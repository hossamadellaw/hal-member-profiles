<?php
/**
 * Unit tests for the Runtime Compatibility Evidence contract (card S-08): the closed
 * exact-schema/enums/unknown-key/size-bound validator, PII-sentinel redaction through
 * the whitelist normalizer, write/network counters, and fail-closed generation when any
 * dependency is missing.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\RuntimeEvidenceReporter;
use PHPUnit\Framework\TestCase;

final class RuntimeEvidenceReporterTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'     => $GLOBALS['wp_stubs']['options'] ?? array(),
			'user_meta'   => $GLOBALS['wp_stubs']['user_meta'] ?? null,
			'transients'  => $GLOBALS['wp_stubs_extra']['transients'] ?? array(),
			'http_calls'  => $GLOBALS['wp_stubs']['http_calls'] ?? array(),
			'is_admin'    => hal_wp_stub_extra( 'is_admin' ),
			'can_manage'  => $GLOBALS['wp_stubs']['can_manage'] ?? false,
		);

		$GLOBALS['wp_stubs']['options']    = $this->backed_up['options'];
		$GLOBALS['wp_stubs']['http_calls'] = $this->backed_up['http_calls'];
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_reset_http();

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
		if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
			// Production always defines this in the main plugin file before boot.
			define( 'HAL_MEMBER_PROFILES_VERSION', '1.2.1' );
		}
		if ( ! function_exists( 'UM' ) ) {
			eval( 'function UM() { return (object) array(); }' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']          = $this->backed_up['options'];
		$GLOBALS['wp_stubs']['user_meta']        = $this->backed_up['user_meta'];
		$GLOBALS['wp_stubs_extra']['transients'] = $this->backed_up['transients'];
		$GLOBALS['wp_stubs']['http_calls']       = $this->backed_up['http_calls'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		$GLOBALS['wp_stubs']['can_manage']       = $this->backed_up['can_manage'];
	}

	/** Synthetic ci_fixture facts exercising the enum space (invented values only). */
	private function fixture_facts(): array {
		return array(
			'source'            => RuntimeEvidenceReporter::SOURCE_FIXTURE,
			'plugin_version'    => HAL_MEMBER_PROFILES_VERSION,
			'environment'       => array(
				'wordpress'       => '6.5',
				'php'             => '8.2',
				'ultimate_member' => '2.8.0',
				'elementor'       => null,
				'elementor_pro'   => null,
				'amelia'          => null,
				'theme'           => null,
			),
			'account_selectors' => array(
				'count' => 0,
				'types' => array(),
			),
			'amelia_reader'     => array(
				'plugin_detected' => true,
				'sync_mode'       => 'discover_only',
				'snapshot_ok'     => true,
				'services'        => 3,
				'employees'       => 2,
				'custom_fields'   => 1,
				'gate_passes'     => false,
			),
			'amelia_writer'     => array(
				'class_present'     => true,
				'route_present'     => true,
				'gate_passes'       => false,
				'sync_mode'         => 'discover_only',
				'mode_allows_write' => false,
				'secret_stored'     => false,
				'secret_decodable'  => null,
				'desired_count'     => 2,
				'ledger_count'      => 1,
				'dry_plan'          => array( 'to_create' => 1, 'to_update' => 0, 'unchanged' => 0, 'orphaned' => 0 ),
			),
			'um_integration'    => array(
				'um_detected'             => true,
				'native_fallback_present' => true,
				'gate_profile'            => false,
				'gate_account'            => false,
				'gate_um_schema'          => false,
				'gate_dynamic_tags'       => false,
			),
			'integrations'      => array(
				'woocommerce'     => false,
				'wpml'            => false,
				'acf'             => false,
				'profile_queries' => false,
			),
		);
	}

	public function test_generate_produces_the_exact_closed_schema(): void {
		$report = ( new RuntimeEvidenceReporter( $this->fixture_facts() ) )->generate();

		$this->assertSame(
			array( 'schema_version', 'report_kind', 'source', 'plugin', 'scope', 'environment', 'capabilities', 'declared_integrations', 'summary' ),
			array_keys( $report )
		);
		$this->assertSame( 1, $report['schema_version'] );
		$this->assertSame( 'hal_runtime_compatibility_evidence', $report['report_kind'] );
		$this->assertSame( 'ci_fixture', $report['source'] );
		$this->assertSame( 'hal-member-profiles', $report['plugin']['slug'] );

		// All six mandated capabilities, each with the closed dimension set.
		$this->assertSame(
			array( 'account_selectors', 'account_photo_tab', 'account_dashboard_tab', 'amelia_reader', 'amelia_writer', 'um_integration' ),
			array_keys( $report['capabilities'] )
		);

		foreach ( $report['capabilities'] as $id => $capability ) {
			$keys = array( 'implementation', 'runtime', 'verification', 'reason', 'evidence_level', 'counts' );

			if ( 'amelia_writer' === $id ) {
				$keys[] = 'delete_supported';
			}

			$this->assertSame( $keys, array_keys( $capability ), "capability {$id} keys" );
		}

		// Expected honest states for the fixture: selectors blocked, tabs native fallback,
		// reader pending on the gate, writer blocked with delete_supported=false.
		$this->assertSame( 'blocked', $report['capabilities']['account_selectors']['runtime'] );
		$this->assertSame( 'no_verified_account_source', $report['capabilities']['account_selectors']['reason'] );
		$this->assertSame( 'native_fallback', $report['capabilities']['account_photo_tab']['runtime'] );
		$this->assertSame( 'no_verified_runtime_probe', $report['capabilities']['account_photo_tab']['reason'] );
		$this->assertSame( 'contract_fixture', $report['capabilities']['amelia_reader']['evidence_level'] );
		$this->assertSame( 'blocked', $report['capabilities']['amelia_writer']['runtime'] );
		$this->assertFalse( $report['capabilities']['amelia_writer']['delete_supported'] );

		// No Matrix row may flip: everything stays Pending in fixture mode.
		foreach ( $report['capabilities'] as $capability ) {
			$this->assertSame( 'Pending', $capability['verification'] );
		}

		$this->assertSame( array( 'woocommerce', 'wpml', 'acf', 'profile_queries' ), array_keys( $report['declared_integrations'] ) );
		$this->assertTrue( $report['summary']['report_valid'] );
		$this->assertSame( 6, $report['summary']['capabilities_total'] );
	}

	public function test_validator_rejects_unknown_keys_enums_and_reasons(): void {
		$report = ( new RuntimeEvidenceReporter( $this->fixture_facts() ) )->generate();

		// Unknown ROOT key.
		$inflated = $report;
		$inflated['unexpected'] = 'sentinel-unknown-root';
		try {
			RuntimeEvidenceReporter::validate( $inflated );
			$this->fail( 'unknown root key must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'root_keys_invalid', $e->getMessage() );
		}

		// Unknown enum value.
		$inflated = $report;
		$inflated['capabilities']['amelia_reader']['runtime'] = 'exploded';
		try {
			RuntimeEvidenceReporter::validate( $inflated );
			$this->fail( 'unknown runtime enum must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'capability_amelia_reader_runtime_invalid', $e->getMessage() );
		}

		// Reason outside the allowlist.
		$inflated = $report;
		$inflated['capabilities']['amelia_reader']['reason'] = 'made_up_reason';
		try {
			RuntimeEvidenceReporter::validate( $inflated );
			$this->fail( 'non-allowlisted reason must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'capability_amelia_reader_reason_not_allowlisted', $e->getMessage() );
		}

		// delete_supported must stay strictly false.
		$inflated = $report;
		$inflated['capabilities']['amelia_writer']['delete_supported'] = true;
		try {
			RuntimeEvidenceReporter::validate( $inflated );
			$this->fail( 'delete_supported=true must be rejected' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'capability_amelia_writer_delete_supported_must_be_false', $e->getMessage() );
		}
	}

	public function test_validator_enforces_the_size_bound(): void {
		$report = ( new RuntimeEvidenceReporter( $this->fixture_facts() ) )->generate();

		// A valid report stays far below the ceiling.
		$this->assertLessThan( RuntimeEvidenceReporter::MAX_JSON_BYTES, strlen( (string) json_encode( $report ) ) );

		// The ceiling itself is enforced (test-only override of the bound).
		try {
			RuntimeEvidenceReporter::validate( $report, 10 );
			$this->fail( 'the size ceiling must be enforced' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'report_too_large', $e->getMessage() );
		}
	}

	public function test_planted_pii_sentinels_never_survive_into_the_json(): void {
		$facts = $this->fixture_facts();

		// PII sentinels planted at every level a hostile or sloppy input could carry.
		$facts['notes']                          = 'sentinel-top-level-free-text';
		$facts['user_email']                     = 'sentinel-owner@example.test';
		$facts['amelia_service_ids']             = array( 4242 );
		$facts['environment']['injected_secret'] = 'sentinel-env-secret';
		$facts['account_selectors']['metakeys']  = array( 'sentinel_meta_key' );
		$facts['amelia_reader']['service_names'] = array( 'sentinel-service-name' );
		$facts['amelia_writer']['ledger_rows']   = array( array( 'sentinel_meta_key' => 'sentinel-field-value' ) );

		$json = ( new RuntimeEvidenceReporter( $facts ) )->generate_json();

		foreach ( array( 'sentinel-top-level-free-text', 'sentinel-owner@example.test', '4242', 'sentinel-env-secret', 'sentinel_meta_key', 'sentinel-service-name', 'sentinel-field-value' ) as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json, "sentinel {$sentinel} must be redacted" );
		}
	}

	public function test_runtime_collection_is_pure_no_writes_no_http_and_redacts_stored_pii(): void {
		// Seed stored data that contains PII sentinels: the collector must emit counts
		// only, never the stored strings.
		$GLOBALS['wp_stubs']['options'] = array_merge(
			$GLOBALS['wp_stubs']['options'],
			array(
				\HAL\MemberProfiles\AdminDashboard::DESIRED_OPTION => array(
					array( 'key' => 'sentinel_meta_key', 'title' => 'sentinel-desired-title', 'type' => 'text' ),
				),
				\HAL\MemberProfiles\Integrations\AmeliaFieldsWriter::LEDGER_OPTION => array(
					'sentinel_meta_key' => array( 'amelia_id' => 4242 ),
				),
				\HAL\MemberProfiles\SchemaRegistry::SNAPSHOT_OPTION => array(
					'version' => 1,
					'services' => array( array( 'id' => 11, 'title' => 'sentinel-service-name' ) ),
					'employees' => array( array( 'id' => 22 ) ),
					'custom_fields' => array( array( 'id' => 33, 'title' => 'sentinel-cf-title' ) ),
				),
			)
		);

		$GLOBALS['wp_stubs']['user_meta'] = array(
			9 => array( 'hal_amelia_employee_id' => 4242 ),
		);

		$b        = $this->boot_admin();
		$options_before    = $GLOBALS['wp_stubs']['options'];
		$transients_before = $GLOBALS['wp_stubs_extra']['transients'];
		$http_before       = count( hal_wp_stub_http_calls() );
		$filesystem_before = $GLOBALS['wp_filesystem'] ?? null;

		$facts    = RuntimeEvidenceReporter::collect_runtime_facts( $b );
		$json     = ( new RuntimeEvidenceReporter( $facts ) )->generate_json();

		$this->assertSame( $options_before, $GLOBALS['wp_stubs']['options'], 'generate must not change options' );
		$this->assertSame( $transients_before, $GLOBALS['wp_stubs_extra']['transients'], 'generate must not change transients' );
		$this->assertSame( $http_before, count( hal_wp_stub_http_calls() ), 'generate must not perform HTTP' );
		$this->assertSame( $filesystem_before, $GLOBALS['wp_filesystem'] ?? null, 'generate must not initialize the filesystem' );

		foreach ( array( 'sentinel_meta_key', 'sentinel-desired-title', '4242', 'sentinel-service-name', 'sentinel-cf-title' ) as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json, "stored sentinel {$sentinel} must be redacted" );
		}

		// Counts made it through; contents did not.
		$report = json_decode( $json, true );
		$this->assertSame( 1, $report['capabilities']['amelia_reader']['counts']['services'] );
		$this->assertSame( 1, $report['capabilities']['amelia_writer']['counts']['desired'] );
		$this->assertSame( 1, $report['capabilities']['amelia_writer']['counts']['ledger'] );

		// Runtime evidence carries runtime_observation levels.
		$this->assertSame( 'runtime_observation', $report['capabilities']['amelia_reader']['evidence_level'] );
		$this->assertSame( 'wordpress_runtime', $report['source'] );
		$this->assertSame( 'runtime_observation', $report['scope']['source_kind'] );
	}

	public function test_report_still_generates_fail_closed_when_dependencies_are_missing(): void {
		// Null bootstrap: no gate, no registry, no field schema — everything fails closed.
		$facts = RuntimeEvidenceReporter::collect_runtime_facts( null );

		$this->assertFalse( $facts['amelia_reader']['gate_passes'] );
		$this->assertFalse( $facts['amelia_reader']['snapshot_ok'] );
		$this->assertSame( 0, $facts['account_selectors']['count'] );

		$report = ( new RuntimeEvidenceReporter( $facts ) )->generate();

		$this->assertSame( 'blocked', $report['capabilities']['account_selectors']['runtime'] );
		$this->assertSame( 'no_verified_account_source', $report['capabilities']['account_selectors']['reason'] );
		$this->assertSame( 'not_detected', $report['capabilities']['amelia_reader']['runtime'] );
		$this->assertSame( 'dependency_not_detected', $report['capabilities']['amelia_reader']['reason'] );
		$this->assertSame( 'Pending', $report['capabilities']['amelia_reader']['verification'] );
		$this->assertSame( 0, $report['capabilities']['amelia_reader']['counts']['services'] );

		// A missing Amelia writer class also lands on zeros, never a guess.
		$facts['amelia_writer']['class_present'] = false;
		$report = ( new RuntimeEvidenceReporter( $facts ) )->generate();
		$this->assertSame( 'not_implemented', $report['capabilities']['amelia_writer']['implementation'] );
		$this->assertSame( 0, $report['capabilities']['amelia_writer']['counts']['ledger'] );
	}

	private function boot_admin(): Bootstrap {
		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( \HAL\MemberProfiles\AdminDashboard::class, false ) ) {
			$registered = new \ReflectionProperty( \HAL\MemberProfiles\AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		Bootstrap::init();

		$b = Bootstrap::instance();

		$this->assertInstanceOf( Bootstrap::class, $b );

		return $b;
	}
}
