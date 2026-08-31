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
			define( 'HAL_MEMBER_PROFILES_VERSION', '1.1.5' );
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
			'plugin_version'    => '1.1.5',
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
				'source_present' => true,
				'count'          => 0,
				'types'          => array( 'text' => 0, 'url' => 0, 'image' => 0, 'list' => 0 ),
			),
			'amelia_reader'     => array(
				'plugin_detected' => true,
				'service_present' => true,
				'sync_mode'       => 'discover_only',
				'snapshot_ok'     => true,
				'services'        => 3,
				'employees'       => 2,
				'custom_fields'   => 1,
				'gate_passes'     => false,
				'gate_reason'     => 'awaiting_matrix_signoff:amelia_api_read',
			),
			'amelia_writer'     => array(
				'class_present'     => true,
				'service_present'   => true,
				'route_present'     => true,
				'gate_passes'       => false,
				'gate_reason'       => 'awaiting_matrix_signoff:amelia_fields_write',
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
				'full_native_fallback'    => true,
				'profile_gate_passes'     => false,
				'account_gate_passes'     => false,
				'um_schema_gate_passes'   => false,
				'dynamic_tags_gate_passes' => false,
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

		$base_keys = array( 'implementation', 'runtime', 'verification', 'reason', 'evidence_level', 'counts' );
		$expected_capability_keys = array(
			'account_selectors'    => array_merge( $base_keys, array( 'source_present' ) ),
			'account_photo_tab'    => $base_keys,
			'account_dashboard_tab' => $base_keys,
			'amelia_reader'        => array_merge( $base_keys, array( 'plugin_detected', 'service_present', 'sync_mode', 'snapshot_state', 'gate_passes', 'gate_reason' ) ),
			'amelia_writer'        => array_merge( $base_keys, array( 'class_present', 'service_present', 'route_present', 'strict_gate_passes', 'strict_gate_reason', 'sync_mode', 'mode_allows_write', 'secret_stored', 'secret_decodable', 'delete_supported' ) ),
			'um_integration'       => array_merge( $base_keys, array( 'um_detected', 'full_native_fallback', 'profile_gate_passes', 'account_gate_passes', 'um_schema_gate_passes', 'dynamic_tags_gate_passes' ) ),
		);

		foreach ( $report['capabilities'] as $id => $capability ) {
			$this->assertSame( $expected_capability_keys[ $id ], array_keys( $capability ), "capability {$id} keys" );
		}

		$this->assertSame( array( 'total', 'type_distribution' ), array_keys( $report['capabilities']['account_selectors']['counts'] ) );
		$this->assertSame( array( 'text', 'url', 'image', 'list' ), array_keys( $report['capabilities']['account_selectors']['counts']['type_distribution'] ) );
		$this->assertSame( array( 'services', 'employees', 'custom_fields' ), array_keys( $report['capabilities']['amelia_reader']['counts'] ) );
		$this->assertSame( array( 'desired', 'ledger', 'dry_plan_create', 'dry_plan_update', 'dry_plan_unchanged', 'dry_plan_orphaned' ), array_keys( $report['capabilities']['amelia_writer']['counts'] ) );
		$this->assertSame( array( 'gates_total', 'gates_passing' ), array_keys( $report['capabilities']['um_integration']['counts'] ) );

		// Expected honest states for the fixture: selectors blocked, tabs native fallback,
		// reader pending on the gate, writer blocked with delete_supported=false.
		$this->assertSame( 'blocked', $report['capabilities']['account_selectors']['runtime'] );
		$this->assertSame( 'no_verified_account_source', $report['capabilities']['account_selectors']['reason'] );
		$this->assertSame( 'native_fallback', $report['capabilities']['account_photo_tab']['runtime'] );
		$this->assertSame( 'no_verified_runtime_probe', $report['capabilities']['account_photo_tab']['reason'] );
		$this->assertSame( 'contract_fixture', $report['capabilities']['amelia_reader']['evidence_level'] );
		$this->assertTrue( $report['capabilities']['amelia_reader']['plugin_detected'] );
		$this->assertTrue( $report['capabilities']['amelia_reader']['service_present'] );
		$this->assertSame( 'discover_only', $report['capabilities']['amelia_reader']['sync_mode'] );
		$this->assertSame( 'available', $report['capabilities']['amelia_reader']['snapshot_state'] );
		$this->assertFalse( $report['capabilities']['amelia_reader']['gate_passes'] );
		$this->assertSame( 'awaiting_matrix_signoff', $report['capabilities']['amelia_reader']['gate_reason'] );
		$this->assertSame( 'blocked', $report['capabilities']['amelia_writer']['runtime'] );
		$this->assertTrue( $report['capabilities']['amelia_writer']['class_present'] );
		$this->assertTrue( $report['capabilities']['amelia_writer']['service_present'] );
		$this->assertTrue( $report['capabilities']['amelia_writer']['route_present'] );
		$this->assertFalse( $report['capabilities']['amelia_writer']['strict_gate_passes'] );
		$this->assertFalse( $report['capabilities']['amelia_writer']['secret_decodable'] );
		$this->assertFalse( $report['capabilities']['amelia_writer']['delete_supported'] );
		$this->assertTrue( $report['capabilities']['um_integration']['full_native_fallback'] );
		$this->assertFalse( $report['capabilities']['um_integration']['profile_gate_passes'] );

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

		// Required capability-local field and unknown capability-local key.
		$inflated = $report;
		unset( $inflated['capabilities']['amelia_reader']['service_present'] );
		$this->assert_validation_error( 'capability_amelia_reader_keys_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_writer']['apply_supported'] = false;
		$this->assert_validation_error( 'capability_amelia_writer_keys_invalid', $inflated );

		// Nested schemas, sync/snapshot enums and capability-local reason allowlists are closed.
		$inflated = $report;
		$inflated['capabilities']['account_selectors']['counts']['type_distribution']['sentinel_meta_key'] = 1;
		$this->assert_validation_error( 'capability_account_selectors_type_distribution_keys_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_reader']['sync_mode'] = 'sentinel-sync-mode';
		$this->assert_validation_error( 'capability_amelia_reader_facts_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_reader']['snapshot_state'] = 'stale';
		$this->assert_validation_error( 'capability_amelia_reader_facts_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_writer']['strict_gate_reason'] = 'sentinel-reason';
		$this->assert_validation_error( 'capability_amelia_writer_facts_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_writer']['secret_decodable'] = null;
		$this->assert_validation_error( 'capability_amelia_writer_secret_decodable_invalid', $inflated );

		$inflated = $report;
		$inflated['capabilities']['amelia_reader']['counts']['services'] = 1000001;
		$this->assert_validation_error( 'capability_amelia_reader_counts_value_invalid', $inflated );

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
		$this->assertSame( 65536, RuntimeEvidenceReporter::MAX_JSON_BYTES );
		$this->assertLessThan( RuntimeEvidenceReporter::MAX_JSON_BYTES, strlen( (string) json_encode( $report ) ) );

		// The ceiling itself is enforced (test-only override of the bound).
		try {
			RuntimeEvidenceReporter::validate( $report, 10 );
			$this->fail( 'the size ceiling must be enforced' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'report_too_large', $e->getMessage() );
		}
	}

	public function test_json_encoding_is_non_empty_and_fails_closed_on_invalid_utf8(): void {
		$json = ( new RuntimeEvidenceReporter( $this->fixture_facts() ) )->generate_json();

		$this->assertNotSame( '', $json );
		$this->assertIsArray( json_decode( $json, true ) );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );

		$facts = $this->fixture_facts();
		$facts['environment']['wordpress'] = "\xB1\x31";

		try {
			( new RuntimeEvidenceReporter( $facts ) )->generate_json();
			$this->fail( 'generate_json must fail closed when JSON encoding fails' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'report_json_encoding_failed', $e->getMessage() );
		}

		$report = ( new RuntimeEvidenceReporter( $this->fixture_facts() ) )->generate();
		$report['environment']['wordpress'] = "\xB1\x31";
		$this->assert_validation_error( 'report_json_encoding_failed', $report );
	}

	public function test_planted_pii_sentinels_never_survive_into_the_json(): void {
		$facts = $this->fixture_facts();

		// PII sentinels planted at every level a hostile or sloppy input could carry.
		$facts['notes']                          = 'sentinel-top-level-free-text';
		$facts['user_email']                     = 'sentinel-owner@example.test';
		$facts['amelia_service_ids']             = array( 4242 );
		$facts['environment']['injected_secret'] = 'sentinel-env-secret';
		$facts['account_selectors']['metakeys']  = array( 'sentinel_meta_key' );
		$facts['account_selectors']['types']['sentinel_meta_key'] = 1;
		$facts['amelia_reader']['service_names'] = array( 'sentinel-service-name' );
		$facts['amelia_reader']['sync_mode']     = 'sentinel-sync-mode';
		$facts['amelia_reader']['gate_reason']   = 'sentinel-gate-reason:sentinel_meta_key';
		$facts['amelia_writer']['ledger_rows']   = array( array( 'sentinel_meta_key' => 'sentinel-field-value' ) );

		$json = ( new RuntimeEvidenceReporter( $facts ) )->generate_json();

		foreach ( array( 'sentinel-top-level-free-text', 'sentinel-owner@example.test', '4242', 'sentinel-env-secret', 'sentinel_meta_key', 'sentinel-service-name', 'sentinel-field-value', 'sentinel-sync-mode', 'sentinel-gate-reason' ) as $sentinel ) {
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
		$this->assertFalse( $report['capabilities']['account_selectors']['source_present'], 'missing has_filter API/hook must fail closed' );
		$this->assertFalse( $report['capabilities']['amelia_writer']['route_present'], 'missing has_action API must fail closed' );
		$this->assertIsBool( $report['capabilities']['amelia_writer']['secret_stored'] );
		$this->assertIsBool( $report['capabilities']['amelia_writer']['secret_decodable'] );

		// Runtime evidence carries runtime_observation levels.
		$this->assertSame( 'runtime_observation', $report['capabilities']['amelia_reader']['evidence_level'] );
		$this->assertSame( 'wordpress_runtime', $report['source'] );
		$this->assertSame( 'runtime_observation', $report['scope']['source_kind'] );

		$mutated = $report;
		$mutated['capabilities']['amelia_reader']['evidence_level'] = 'contract_fixture';
		$this->assert_validation_error( 'capability_amelia_reader_source_evidence_mismatch', $mutated );

		$mutated = $report;
		$mutated['declared_integrations']['woocommerce']['evidence_level'] = 'contract_fixture';
		$this->assert_validation_error( 'integration_woocommerce_source_provenance_invalid', $mutated );
	}

	public function test_report_still_generates_fail_closed_when_dependencies_are_missing(): void {
		// Null bootstrap: no gate, no registry, no field schema — everything fails closed.
		$facts = RuntimeEvidenceReporter::collect_runtime_facts( null );

		$this->assertFalse( $facts['amelia_reader']['gate_passes'] );
		$this->assertFalse( $facts['amelia_reader']['snapshot_ok'] );
		$this->assertSame( 0, $facts['account_selectors']['count'] );
		$this->assertFalse( $facts['account_selectors']['source_present'] );

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

	public function test_ci_fixture_can_never_claim_ready_or_pass_from_gate_facts(): void {
		$facts = $this->fixture_facts();
		$facts['amelia_reader']['gate_passes'] = true;
		$facts['amelia_writer']['gate_passes'] = true;
		$facts['amelia_writer']['sync_mode'] = 'managed_sync';
		$facts['amelia_writer']['mode_allows_write'] = true;
		$facts['um_integration']['profile_gate_passes'] = true;
		$facts['um_integration']['account_gate_passes'] = true;
		$facts['um_integration']['um_schema_gate_passes'] = true;
		$facts['um_integration']['dynamic_tags_gate_passes'] = true;

		$report = ( new RuntimeEvidenceReporter( $facts ) )->generate();

		foreach ( array( 'amelia_reader', 'amelia_writer', 'um_integration' ) as $capability_id ) {
			$this->assertSame( 'pending', $report['capabilities'][ $capability_id ]['runtime'] );
			$this->assertSame( 'Pending', $report['capabilities'][ $capability_id ]['verification'] );
			$this->assertSame( 'awaiting_matrix_signoff', $report['capabilities'][ $capability_id ]['reason'] );
		}

		$mutated = $report;
		$mutated['capabilities']['amelia_reader']['runtime'] = 'ready';
		$mutated['summary']['runtime']['pending']--;
		$mutated['summary']['runtime']['ready']++;
		$this->assert_validation_error( 'capability_amelia_reader_fixture_runtime_ready_forbidden', $mutated );

		$mutated = $report;
		$mutated['capabilities']['amelia_reader']['verification'] = 'Pass';
		$mutated['summary']['verification']['Pending']--;
		$mutated['summary']['verification']['Pass']++;
		$this->assert_validation_error( 'capability_amelia_reader_fixture_verification_pass_forbidden', $mutated );

		$mutated = $report;
		$mutated['declared_integrations']['woocommerce']['verification'] = 'Pass';
		$this->assert_validation_error( 'integration_woocommerce_source_provenance_invalid', $mutated );
	}

	public function test_ci_fixture_account_selectors_with_counts_remain_pending(): void {
		$facts = $this->fixture_facts();
		$facts['account_selectors']['count'] = 2;
		$facts['account_selectors']['types']['text'] = 1;
		$facts['account_selectors']['types']['list'] = 1;

		$capability = ( new RuntimeEvidenceReporter( $facts ) )->generate()['capabilities']['account_selectors'];

		$this->assertSame( 2, $capability['counts']['total'] );
		$this->assertSame( 'pending', $capability['runtime'] );
		$this->assertSame( 'Pending', $capability['verification'] );
		$this->assertSame( 'awaiting_matrix_signoff', $capability['reason'] );
	}

	private function assert_validation_error( string $expected, array $report ): void {
		try {
			RuntimeEvidenceReporter::validate( $report );
			$this->fail( "validator must reject {$expected}" );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( $expected, $e->getMessage() );
		}
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
