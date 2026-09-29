<?php
/**
 * Read-only runtime compatibility evidence collector — release card S-08.
 *
 * Sole responsibility: produce one bounded, machine-readable JSON report describing
 * HAL's own loaded services and local state, with machine reasons, as the internal
 * baseline for the next goal. It NEVER refreshes, calls out, writes, or decides
 * compatibility: no self-registered hooks, no HTTP, no apply verbs, no option/transient/
 * file writes anywhere in this class. Compatibility verdicts stay owned by
 * CompatibilityGate; this reporter only OBSERVES their current values.
 *
 * Load contract: required lazily via require_once inside
 * AdminDashboard::render_diagnostics_rows() — the single consumption point. It is
 * deliberately NOT part of load_module_classes() and never loads on frontend or other
 * admin requests. Display happens only on the Diagnostics page, already guarded by
 * manage_options, inside a readonly esc_textarea'd <textarea>. The external Production
 * Verifier never receives this report, tokens, or endpoints for it.
 *
 * Evidence sources are strictly separated:
 * - wordpress_runtime: facts collected from the booted services (evidence_level
 *   runtime_observation or not_observed).
 * - ci_fixture: the SAME generator fed synthetic facts (evidence_level
 *   contract_fixture) — proves the contract offline; it is never live, never a
 *   production observation, and never sufficient for a Matrix sign-off.
 *
 * Forbidden data never enter the report: user/member IDs, Amelia employee/service/field
 * IDs, metakeys, labels, names, emails, field values or contents, secrets, headers,
 * bodies, cookies, nonces, URLs, query strings, filesystem/server paths, stack traces,
 * external callback names, or hook dumps. Only booleans and bounded counts are read.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

use HAL\MemberProfiles\Integrations\AmeliaFieldsWriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RuntimeEvidenceReporter {

	const SCHEMA_VERSION = 1;
	const REPORT_KIND    = 'hal_runtime_compatibility_evidence';
	const SOURCE_RUNTIME = 'wordpress_runtime';
	const SOURCE_FIXTURE = 'ci_fixture';
	const PLUGIN_SLUG    = 'hal-member-profiles';
	const MAX_JSON_BYTES = 65536;

	private const CAPABILITY_IDS = array(
		'account_selectors',
		'account_photo_tab',
		'account_dashboard_tab',
		'amelia_reader',
		'amelia_writer',
		'um_integration',
	);

	private const INTEGRATION_IDS = array( 'woocommerce', 'wpml', 'acf', 'profile_queries' );

	private const IMPLEMENTATIONS = array( 'implemented', 'partial', 'not_implemented' );
	private const RUNTIMES        = array( 'ready', 'blocked', 'pending', 'native_fallback', 'not_configured', 'not_detected' );
	private const VERIFICATIONS   = array( 'Pass', 'Pending', 'Fail', 'Blocked' );
	private const EVIDENCE_LEVELS = array( 'runtime_observation', 'contract_fixture', 'not_observed' );

	private const REASONS = array(
		'gate_passed',
		'gate_not_signed_off',
		'awaiting_matrix_signoff',
		'composition_mismatch',
		'missing_components',
		'no_verified_account_source',
		'no_verified_runtime_probe',
		'not_implemented',
		'dependency_not_detected',
		'sync_mode_off',
		'no_snapshot',
	);

	private const ENVIRONMENT_KEYS   = array( 'wordpress', 'php', 'ultimate_member', 'elementor', 'elementor_pro', 'amelia', 'theme' );
	private const MAX_ENV_LENGTH     = 64;
	private const MAX_COUNTS_ENTRIES = 32;
	private const MAX_COUNT_VALUE    = 1000000;

	/**
	 * Whitelisted, already-validated facts. Unknown input keys are dropped by
	 * normalize_facts(), which is the redaction mechanism: planted PII sentinels can
	 * never survive into the report.
	 *
	 * @var array<string,mixed>
	 */
	private array $facts;

	/**
	 * @param array<string,mixed> $facts Facts from collect_runtime_facts() or a ci_fixture
	 *                                   script. Unknown keys are silently dropped.
	 */
	public function __construct( array $facts ) {
		$this->facts = self::normalize_facts( $facts );
	}

	/**
	 * Collects runtime facts from the booted services. Pure reads only: options and
	 * getters, no refresh verbs, no API calls, no writes of any kind. Every missing
	 * dependency yields a fail-closed value (false/null/0), never a guess.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance, or null when the core never booted.
	 * @return array<string,mixed>
	 */
	public static function collect_runtime_facts( ?Bootstrap $bootstrap ): array {
		$dependencies = null !== $bootstrap ? $bootstrap->get_dependencies() : new Dependencies();
		$gate         = null !== $bootstrap ? $bootstrap->get_compatibility_gate() : null;
		$field_schema = null !== $bootstrap ? $bootstrap->get_field_schema() : null;
		$registry     = null !== $bootstrap ? $bootstrap->get_schema_registry() : null;
		$settings     = null !== $bootstrap ? $bootstrap->get_settings() : null;

		$selectors = null !== $field_schema ? $field_schema->get_account_selectors() : array();

		$selector_types = array();

		foreach ( $selectors as $selector ) {
			$type = is_array( $selector ) ? (string) ( $selector['selector_type'] ?? '' ) : '';

			if ( '' === $type ) {
				continue;
			}

			$selector_types[ $type ] = ( $selector_types[ $type ] ?? 0 ) + 1;
		}

		$sync_mode = null !== $settings ? (string) SchemaRegistry::current_sync_mode() : '';

		$snapshot_ok        = false;
		$services_count     = 0;
		$employees_count    = 0;
		$custom_fields_count = 0;

		if ( null !== $registry ) {
			try {
				$state = $registry->amelia_snapshot();
			} catch ( \Throwable $e ) {
				$state = array( 'ok' => false );
			}

			$snapshot_ok = ! empty( $state['ok'] ) && is_array( $state['snapshot'] ?? null );

			if ( $snapshot_ok ) {
				$services_count      = count( (array) ( $state['snapshot']['services'] ?? array() ) );
				$employees_count     = count( (array) ( $state['snapshot']['employees'] ?? array() ) );
				$custom_fields_count = count( (array) ( $state['snapshot']['custom_fields'] ?? array() ) );
			}
		}

		$writer_present = class_exists( AmeliaFieldsWriter::class );

		$desired_count = 0;
		$ledger_count  = 0;
		$dry_plan      = array( 'to_create' => 0, 'to_update' => 0, 'unchanged' => 0, 'orphaned' => 0 );

		if ( $writer_present && class_exists( AdminDashboard::class ) ) {
			$desired = get_option( AdminDashboard::DESIRED_OPTION, array() );
			$desired = is_array( $desired ) ? $desired : array();
			$desired_count = count( $desired );

			$ledger = get_option( AmeliaFieldsWriter::LEDGER_OPTION, array() );
			$ledger_count = is_array( $ledger ) ? count( $ledger ) : 0;

			// Dry-plan means a LOCAL computation from the stored snapshot only — never a
			// write, never apply(), never an API call. Any failure counts as zeros.
			if ( $snapshot_ok && class_exists( SchemaRegistry::class ) ) {
				try {
					$plan = AmeliaFieldsWriter::build_plan( $desired );

					if ( ! empty( $plan['ok'] ) && is_array( $plan['plan'] ?? null ) ) {
						foreach ( $dry_plan as $section => $ignored ) {
							$dry_plan[ $section ] = count( (array) ( $plan['plan'][ $section ] ?? array() ) );
						}
					}
				} catch ( \Throwable $e ) {
					$dry_plan = array( 'to_create' => 0, 'to_update' => 0, 'unchanged' => 0, 'orphaned' => 0 );
				}
			}
		}

		$secret_state = array( 'stored' => false, 'decodable' => null );

		if ( class_exists( SecretStore::class ) ) {
			try {
				$state = SecretStore::storage_state();

				$secret_state = array(
					'stored'    => (bool) ( $state['stored'] ?? false ),
					'decodable' => isset( $state['decodable'] ) && is_bool( $state['decodable'] ) ? $state['decodable'] : null,
				);
			} catch ( \Throwable $e ) {
				$secret_state = array( 'stored' => false, 'decodable' => null );
			}
		}

		return array(
			'source'         => self::SOURCE_RUNTIME,
			'plugin_version' => defined( 'HAL_MEMBER_PROFILES_VERSION' ) ? (string) HAL_MEMBER_PROFILES_VERSION : '',
			'environment'    => array(
				'wordpress'       => $dependencies->wp_version(),
				'php'             => $dependencies->php_version(),
				'ultimate_member' => $dependencies->um_version(),
				'elementor'       => $dependencies->elementor_version(),
				'elementor_pro'   => $dependencies->elementor_pro_version(),
				'amelia'          => $dependencies->amelia_version(),
				'theme'           => $dependencies->active_theme_version(),
			),
			'account_selectors' => array(
				'count' => count( $selectors ),
				'types' => $selector_types,
			),
			'amelia_reader' => array(
				'plugin_detected' => $dependencies->has_amelia(),
				'sync_mode'       => $sync_mode,
				'snapshot_ok'     => $snapshot_ok,
				'services'        => $services_count,
				'employees'       => $employees_count,
				'custom_fields'   => $custom_fields_count,
				'gate_passes'     => null !== $gate ? $gate->passes( CompatibilityGate::CAP_AMELIA_API_READ ) : false,
				'gate_reason'     => null !== $gate ? $gate->describe( CompatibilityGate::CAP_AMELIA_API_READ ) : '',
			),
			'amelia_writer' => array(
				'class_present'     => $writer_present,
				'route_present'     => $writer_present && class_exists( AdminDashboard::class ),
				'gate_passes'       => null !== $gate ? $gate->passes( CompatibilityGate::CAP_AMELIA_FIELDS_WRITE ) : false,
				'gate_reason'       => null !== $gate ? $gate->describe( CompatibilityGate::CAP_AMELIA_FIELDS_WRITE ) : '',
				'sync_mode'         => $sync_mode,
				'mode_allows_write' => in_array( $sync_mode, array( Settings::SYNC_MODE_MANAGED_ADDITIONS, Settings::SYNC_MODE_MANAGED_SYNC ), true ),
				'secret_stored'     => $secret_state['stored'],
				'secret_decodable'  => $secret_state['decodable'],
				'desired_count'     => $desired_count,
				'ledger_count'      => $ledger_count,
				'dry_plan'          => $dry_plan,
			),
			'um_integration' => array(
				'um_detected'             => $dependencies->has_um(),
				'native_fallback_present' => class_exists( LayoutContract::class ),
				'gate_profile'            => null !== $gate ? $gate->passes( CompatibilityGate::CAP_PROFILE ) : false,
				'gate_account'            => null !== $gate ? $gate->passes( CompatibilityGate::CAP_ACCOUNT ) : false,
				'gate_um_schema'          => null !== $gate ? $gate->passes( CompatibilityGate::CAP_UM_SCHEMA ) : false,
				'gate_dynamic_tags'       => null !== $gate ? $gate->passes( CompatibilityGate::CAP_ELEMENTOR_DYNAMIC_TAGS ) : false,
			),
			'integrations' => array(
				'woocommerce'     => $dependencies->has_woocommerce(),
				'wpml'            => $dependencies->has_wpml(),
				'acf'             => class_exists( '\ACF' ),
				'profile_queries' => $dependencies->has_elementor_pro_queries(),
			),
		);
	}

	/**
	 * Builds the closed report and validates it before returning. Any validator failure
	 * throws — the report can never exist in an invalid shape.
	 *
	 * @return array<string,mixed>
	 */
	public function generate(): array {
		$source = $this->facts['source'];

		$capabilities = array(
			'account_selectors' => array(
				'implementation' => 'partial',
				'runtime'        => $this->facts['account_selectors']['count'] > 0 ? 'ready' : 'blocked',
				'verification'   => 'Pending',
				'reason'         => $this->facts['account_selectors']['count'] > 0 ? 'gate_not_signed_off' : 'no_verified_account_source',
				'evidence_level' => $this->evidence_level(),
				'counts'         => array(
					'selectors'      => $this->facts['account_selectors']['count'],
					'selector_types' => count( $this->facts['account_selectors']['types'] ),
				),
			),
			'account_photo_tab' => array(
				'implementation' => 'not_implemented',
				'runtime'        => 'native_fallback',
				'verification'   => 'Pending',
				'reason'         => 'no_verified_runtime_probe',
				'evidence_level' => 'not_observed',
				'counts'         => array(),
			),
			'account_dashboard_tab' => array(
				'implementation' => 'not_implemented',
				'runtime'        => 'native_fallback',
				'verification'   => 'Pending',
				'reason'         => 'no_verified_runtime_probe',
				'evidence_level' => 'not_observed',
				'counts'         => array(),
			),
			'amelia_reader' => $this->amelia_reader_capability(),
			'amelia_writer' => $this->amelia_writer_capability(),
			'um_integration' => $this->um_integration_capability(),
		);

		$report = array(
			'schema_version' => self::SCHEMA_VERSION,
			'report_kind'    => self::REPORT_KIND,
			'source'         => $source,
			'plugin'         => array(
				'slug'    => self::PLUGIN_SLUG,
				'version' => $this->facts['plugin_version'],
			),
			'scope'          => array(
				'network_calls'                  => false,
				'writes'                         => false,
				'pii_free'                       => true,
				'source_kind'                    => self::SOURCE_RUNTIME === $source ? 'runtime_observation' : 'contract_fixture',
				'production_observation_claimed' => false,
			),
			'environment'    => $this->facts['environment'],
			'capabilities'   => $capabilities,
			'declared_integrations' => array(
				'woocommerce'     => $this->integration_entry( $this->facts['integrations']['woocommerce'] ),
				'wpml'            => $this->integration_entry( $this->facts['integrations']['wpml'] ),
				'acf'             => $this->integration_entry( $this->facts['integrations']['acf'] ),
				'profile_queries' => $this->integration_entry( $this->facts['integrations']['profile_queries'] ),
			),
			'summary'        => $this->build_summary( $capabilities ),
		);

		self::validate( $report );

		return $report;
	}

	/**
	 * Builds and validates the report, then returns its canonical JSON form.
	 *
	 * @return string
	 */
	public function generate_json(): string {
		return (string) json_encode(
			$this->generate(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * The closed validator: exact keys, closed enums, allowlisted reasons, bounded
	 * counts, and the 64 KiB size ceiling. Throws InvalidArgumentException on any
	 * violation — including the size ceiling (overridable only through $max_bytes for
	 * tests; production always uses MAX_JSON_BYTES).
	 *
	 * @param array<string,mixed> $report    Candidate report.
	 * @param int|null            $max_bytes Test-only ceiling override.
	 * @return void
	 */
	public static function validate( array $report, ?int $max_bytes = null ): void {
		$fail = static function ( string $message ): void {
			throw new \InvalidArgumentException( $message );
		};

		self::exact_keys(
			$report,
			array( 'schema_version', 'report_kind', 'source', 'plugin', 'scope', 'environment', 'capabilities', 'declared_integrations', 'summary' ),
			'root',
			$fail
		);

		if ( self::SCHEMA_VERSION !== $report['schema_version'] ) {
			$fail( 'schema_version_invalid' );
		}

		if ( self::REPORT_KIND !== $report['report_kind'] ) {
			$fail( 'report_kind_invalid' );
		}

		if ( ! in_array( $report['source'], array( self::SOURCE_RUNTIME, self::SOURCE_FIXTURE ), true ) ) {
			$fail( 'source_invalid' );
		}

		self::exact_keys( $report['plugin'], array( 'slug', 'version' ), 'plugin', $fail );

		if ( self::PLUGIN_SLUG !== $report['plugin']['slug'] || ! self::bounded_string( $report['plugin']['version'], 32 ) ) {
			$fail( 'plugin_identity_invalid' );
		}

		$scope = $report['scope'];

		self::exact_keys( $scope, array( 'network_calls', 'writes', 'pii_free', 'source_kind', 'production_observation_claimed' ), 'scope', $fail );

		if ( false !== $scope['network_calls'] || false !== $scope['writes'] || true !== $scope['pii_free'] || false !== $scope['production_observation_claimed'] ) {
			$fail( 'scope_invalid' );
		}

		$expected_source_kind = self::SOURCE_RUNTIME === $report['source'] ? 'runtime_observation' : 'contract_fixture';

		if ( $expected_source_kind !== $scope['source_kind'] ) {
			$fail( 'scope_source_kind_mismatch' );
		}

		self::exact_keys( $report['environment'], self::ENVIRONMENT_KEYS, 'environment', $fail );

		foreach ( $report['environment'] as $component => $version ) {
			if ( null !== $version && ! self::bounded_string( $version, self::MAX_ENV_LENGTH ) ) {
				$fail( "environment_{$component}_invalid" );
			}
		}

		self::exact_keys( $report['capabilities'], self::CAPABILITY_IDS, 'capabilities', $fail );

		foreach ( $report['capabilities'] as $id => $capability ) {
			$keys = array( 'implementation', 'runtime', 'verification', 'reason', 'evidence_level', 'counts' );

			// The writer carries one extra closed fact demanded by the card: no delete
			// verb exists anywhere, expressed as delete_supported === false.
			if ( 'amelia_writer' === $id ) {
				$keys[] = 'delete_supported';
			}

			self::exact_keys( $capability, $keys, "capability_{$id}", $fail );

			if ( ! in_array( $capability['implementation'], self::IMPLEMENTATIONS, true ) ) {
				$fail( "capability_{$id}_implementation_invalid" );
			}

			if ( ! in_array( $capability['runtime'], self::RUNTIMES, true ) ) {
				$fail( "capability_{$id}_runtime_invalid" );
			}

			if ( ! in_array( $capability['verification'], self::VERIFICATIONS, true ) ) {
				$fail( "capability_{$id}_verification_invalid" );
			}

			if ( ! in_array( $capability['reason'], self::REASONS, true ) ) {
				$fail( "capability_{$id}_reason_not_allowlisted" );
			}

			if ( ! in_array( $capability['evidence_level'], self::EVIDENCE_LEVELS, true ) ) {
				$fail( "capability_{$id}_evidence_level_invalid" );
			}

			self::validate_counts( $capability['counts'], "capability_{$id}", $fail );

			if ( 'amelia_writer' === $id && false !== $capability['delete_supported'] ) {
				$fail( 'capability_amelia_writer_delete_supported_must_be_false' );
			}
		}

		self::exact_keys( $report['declared_integrations'], self::INTEGRATION_IDS, 'declared_integrations', $fail );

		foreach ( $report['declared_integrations'] as $id => $integration ) {
			self::exact_keys( $integration, array( 'detected', 'verification', 'evidence_level' ), "integration_{$id}", $fail );

			if ( ! is_bool( $integration['detected'] ) || ! in_array( $integration['verification'], self::VERIFICATIONS, true ) || ! in_array( $integration['evidence_level'], self::EVIDENCE_LEVELS, true ) ) {
				$fail( "integration_{$id}_invalid" );
			}
		}

		$summary = $report['summary'];

		self::exact_keys( $summary, array( 'capabilities_total', 'implementation', 'runtime', 'verification', 'report_valid' ), 'summary', $fail );

		if ( count( self::CAPABILITY_IDS ) !== $summary['capabilities_total'] || true !== $summary['report_valid'] ) {
			$fail( 'summary_invalid' );
		}

		foreach ( array( 'implementation' => self::IMPLEMENTATIONS, 'runtime' => self::RUNTIMES, 'verification' => self::VERIFICATIONS ) as $dimension => $allowed ) {
			self::exact_keys( $summary[ $dimension ], $allowed, "summary_{$dimension}", $fail );

			foreach ( $summary[ $dimension ] as $state => $count ) {
				if ( ! is_int( $count ) || $count < 0 ) {
					$fail( "summary_{$dimension}_{$state}_invalid" );
				}
			}
		}

		if ( $summary['implementation'] !== self::count_states( $report['capabilities'], 'implementation' ) ) {
			$fail( 'summary_implementation_mismatch' );
		}

		if ( $summary['runtime'] !== self::count_states( $report['capabilities'], 'runtime' ) ) {
			$fail( 'summary_runtime_mismatch' );
		}

		if ( $summary['verification'] !== self::count_states( $report['capabilities'], 'verification' ) ) {
			$fail( 'summary_verification_mismatch' );
		}

		$json = (string) json_encode( $report );

		if ( strlen( $json ) > ( $max_bytes ?? self::MAX_JSON_BYTES ) ) {
			$fail( 'report_too_large' );
		}
	}

	/**
	 * Whitelist normalization: rebuilds every level key-by-key so planted sentinels in
	 * unknown keys can never survive. Throws on a malformed source/plugin_version —
	 * our own callers control these.
	 *
	 * @param array<string,mixed> $facts Raw facts.
	 * @return array<string,mixed>
	 */
	private static function normalize_facts( array $facts ): array {
		if ( ! isset( $facts['source'] ) || ! in_array( $facts['source'], array( self::SOURCE_RUNTIME, self::SOURCE_FIXTURE ), true ) ) {
			throw new \InvalidArgumentException( 'facts_source_invalid' );
		}

		$version = isset( $facts['plugin_version'] ) ? trim( (string) $facts['plugin_version'] ) : '';

		if ( '' === $version || strlen( $version ) > 32 ) {
			throw new \InvalidArgumentException( 'facts_plugin_version_invalid' );
		}

		$environment = array();

		foreach ( self::ENVIRONMENT_KEYS as $key ) {
			$value = $facts['environment'][ $key ] ?? null;

			$environment[ $key ] = null !== $value ? (string) $value : null;
		}

		$selectors = $facts['account_selectors'] ?? array();

		return array(
			'source'         => $facts['source'],
			'plugin_version' => $version,
			'environment'    => $environment,
			'account_selectors' => array(
				'count' => max( 0, (int) ( $selectors['count'] ?? 0 ) ),
				'types' => is_array( $selectors['types'] ?? null ) ? $selectors['types'] : array(),
			),
			'amelia_reader' => array(
				'plugin_detected' => (bool) ( $facts['amelia_reader']['plugin_detected'] ?? false ),
				'sync_mode'       => isset( $facts['amelia_reader']['sync_mode'] ) ? (string) $facts['amelia_reader']['sync_mode'] : '',
				'snapshot_ok'     => (bool) ( $facts['amelia_reader']['snapshot_ok'] ?? false ),
				'services'        => max( 0, (int) ( $facts['amelia_reader']['services'] ?? 0 ) ),
				'employees'       => max( 0, (int) ( $facts['amelia_reader']['employees'] ?? 0 ) ),
				'custom_fields'   => max( 0, (int) ( $facts['amelia_reader']['custom_fields'] ?? 0 ) ),
				'gate_passes'     => (bool) ( $facts['amelia_reader']['gate_passes'] ?? false ),
				'gate_reason'     => self::bounded_reason( $facts['amelia_reader']['gate_reason'] ?? '' ),
			),
			'amelia_writer' => array(
				'class_present'     => (bool) ( $facts['amelia_writer']['class_present'] ?? false ),
				'route_present'     => (bool) ( $facts['amelia_writer']['route_present'] ?? false ),
				'gate_passes'       => (bool) ( $facts['amelia_writer']['gate_passes'] ?? false ),
				'gate_reason'       => self::bounded_reason( $facts['amelia_writer']['gate_reason'] ?? '' ),
				'sync_mode'         => isset( $facts['amelia_writer']['sync_mode'] ) ? (string) $facts['amelia_writer']['sync_mode'] : '',
				'mode_allows_write' => (bool) ( $facts['amelia_writer']['mode_allows_write'] ?? false ),
				'secret_stored'     => (bool) ( $facts['amelia_writer']['secret_stored'] ?? false ),
				'secret_decodable'  => isset( $facts['amelia_writer']['secret_decodable'] ) && is_bool( $facts['amelia_writer']['secret_decodable'] ) ? $facts['amelia_writer']['secret_decodable'] : null,
				'desired_count'     => max( 0, (int) ( $facts['amelia_writer']['desired_count'] ?? 0 ) ),
				'ledger_count'      => max( 0, (int) ( $facts['amelia_writer']['ledger_count'] ?? 0 ) ),
				'dry_plan'          => array(
					'to_create' => max( 0, (int) ( $facts['amelia_writer']['dry_plan']['to_create'] ?? 0 ) ),
					'to_update' => max( 0, (int) ( $facts['amelia_writer']['dry_plan']['to_update'] ?? 0 ) ),
					'unchanged' => max( 0, (int) ( $facts['amelia_writer']['dry_plan']['unchanged'] ?? 0 ) ),
					'orphaned'  => max( 0, (int) ( $facts['amelia_writer']['dry_plan']['orphaned'] ?? 0 ) ),
				),
			),
			'um_integration' => array(
				'um_detected'             => (bool) ( $facts['um_integration']['um_detected'] ?? false ),
				'native_fallback_present' => (bool) ( $facts['um_integration']['native_fallback_present'] ?? false ),
				'gate_profile'            => (bool) ( $facts['um_integration']['gate_profile'] ?? false ),
				'gate_account'            => (bool) ( $facts['um_integration']['gate_account'] ?? false ),
				'gate_um_schema'          => (bool) ( $facts['um_integration']['gate_um_schema'] ?? false ),
				'gate_dynamic_tags'       => (bool) ( $facts['um_integration']['gate_dynamic_tags'] ?? false ),
			),
			'integrations' => array(
				'woocommerce'     => (bool) ( $facts['integrations']['woocommerce'] ?? false ),
				'wpml'            => (bool) ( $facts['integrations']['wpml'] ?? false ),
				'acf'             => (bool) ( $facts['integrations']['acf'] ?? false ),
				'profile_queries' => (bool) ( $facts['integrations']['profile_queries'] ?? false ),
			),
		);
	}

	private function amelia_reader_capability(): array {
		$facts = $this->facts['amelia_reader'];

		// 'off' is Settings::SYNC_MODE_OFF written literally: generate()/validate() are
		// the pure path used by the ci_fixture script without WordPress, so they must
		// never reference WP-side classes.
		if ( ! $facts['plugin_detected'] ) {
			$runtime = 'not_detected';
			$reason  = 'dependency_not_detected';
		} elseif ( '' === $facts['sync_mode'] || 'off' === $facts['sync_mode'] ) {
			$runtime = 'not_configured';
			$reason  = 'sync_mode_off';
		} elseif ( ! $facts['snapshot_ok'] ) {
			$runtime = 'blocked';
			$reason  = 'no_snapshot';
		} elseif ( $facts['gate_passes'] ) {
			$runtime = 'ready';
			$reason  = 'gate_passed';
		} else {
			$runtime = 'pending';
			$reason  = $this->gate_reason_slug( $facts['gate_reason'] );
		}

		return array(
			'implementation' => 'implemented',
			'runtime'        => $runtime,
			'verification'   => $facts['gate_passes'] ? 'Pass' : 'Pending',
			'reason'         => $reason,
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'services'      => $facts['services'],
				'employees'     => $facts['employees'],
				'custom_fields' => $facts['custom_fields'],
			),
		);
	}

	private function amelia_writer_capability(): array {
		$facts = $this->facts['amelia_writer'];

		$gate_open = $facts['gate_passes'] && $facts['mode_allows_write'];

		return array(
			'implementation' => $facts['class_present'] ? 'implemented' : 'not_implemented',
			'runtime'        => $gate_open ? 'ready' : 'blocked',
			'verification'   => $facts['gate_passes'] ? 'Pass' : 'Pending',
			'reason'         => $facts['gate_passes'] ? 'gate_passed' : $this->gate_reason_slug( $facts['gate_reason'] ),
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'desired'           => $facts['desired_count'],
				'ledger'            => $facts['ledger_count'],
				'dry_plan_create'   => $facts['dry_plan']['to_create'],
				'dry_plan_update'   => $facts['dry_plan']['to_update'],
				'dry_plan_unchanged' => $facts['dry_plan']['unchanged'],
				'dry_plan_orphaned' => $facts['dry_plan']['orphaned'],
			),
			// Closed card fact: NO delete verb exists anywhere in the writer — removals
			// are surfaced as orphaned plan rows for manual handling only.
			'delete_supported' => false,
		);
	}

	private function um_integration_capability(): array {
		$facts = $this->facts['um_integration'];

		$gates = array( $facts['gate_profile'], $facts['gate_account'], $facts['gate_um_schema'], $facts['gate_dynamic_tags'] );
		$passing = count( array_filter( $gates ) );

		if ( ! $facts['um_detected'] ) {
			$runtime = 'not_detected';
			$reason  = 'dependency_not_detected';
		} elseif ( 4 === $passing ) {
			$runtime = 'ready';
			$reason  = 'gate_passed';
		} else {
			$runtime = 'native_fallback';
			$reason  = 'gate_not_signed_off';
		}

		return array(
			'implementation' => 'implemented',
			'runtime'        => $runtime,
			'verification'   => 4 === $passing ? 'Pass' : 'Pending',
			'reason'         => $reason,
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'gates_total'   => 4,
				'gates_passing' => $passing,
			),
		);
	}

	private function integration_entry( bool $detected ): array {
		return array(
			'detected'       => $detected,
			'verification'   => 'Pending',
			'evidence_level' => $this->evidence_level(),
		);
	}

	private function evidence_level(): string {
		return self::SOURCE_RUNTIME === $this->facts['source'] ? 'runtime_observation' : 'contract_fixture';
	}

	/**
	 * Maps a gate describe() string onto the closed reason allowlist. The describe()
	 * value itself (which may embed component slugs) never enters the report.
	 *
	 * @param string $describe_value Raw gate describe() value.
	 * @return string
	 */
	private function gate_reason_slug( string $describe_value ): string {
		if ( '' === $describe_value ) {
			return 'gate_not_signed_off';
		}

		$prefix = strtolower( (string) strtok( $describe_value, ':' ) );

		if ( in_array( $prefix, array( 'awaiting_matrix_signoff', 'composition_mismatch', 'missing_components' ), true ) ) {
			return $prefix;
		}

		return 'gate_not_signed_off';
	}

	private function build_summary( array $capabilities ): array {
		$summary = array(
			'capabilities_total' => count( self::CAPABILITY_IDS ),
			'implementation'     => array(),
			'runtime'            => array(),
			'verification'       => array(),
			'report_valid'       => true,
		);

		foreach ( array( 'implementation' => self::IMPLEMENTATIONS, 'runtime' => self::RUNTIMES, 'verification' => self::VERIFICATIONS ) as $dimension => $states ) {
			$counts = array_fill_keys( $states, 0 );

			foreach ( $capabilities as $capability ) {
				$counts[ $capability[ $dimension ] ]++;
			}

			$summary[ $dimension ] = $counts;
		}

		return $summary;
	}

	/**
	 * Recomputes one dimension's state counts straight from the capabilities, for the
	 * summary consistency check.
	 *
	 * @param array<string,array<string,mixed>> $capabilities Capability map.
	 * @param string                            $dimension    One of implementation|runtime|verification.
	 * @return array<string,int>
	 */
	private static function count_states( array $capabilities, string $dimension ): array {
		$states = array(
			'implementation' => self::IMPLEMENTATIONS,
			'runtime'        => self::RUNTIMES,
			'verification'   => self::VERIFICATIONS,
		)[ $dimension ];

		$counts = array_fill_keys( $states, 0 );

		foreach ( $capabilities as $capability ) {
			$counts[ $capability[ $dimension ] ]++;
		}

		return $counts;
	}

	private static function validate_counts( $counts, string $context, \Closure $fail ): void {
		if ( ! is_array( $counts ) ) {
			$fail( "{$context}_counts_invalid" );
		}

		if ( count( $counts ) > self::MAX_COUNTS_ENTRIES ) {
			$fail( "{$context}_counts_too_many_entries" );
		}

		foreach ( $counts as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 64 ) {
				$fail( "{$context}_counts_key_invalid" );
			}

			if ( ! is_int( $value ) || $value < 0 || $value > self::MAX_COUNT_VALUE ) {
				$fail( "{$context}_counts_value_invalid" );
			}
		}
	}

	private static function bounded_string( $value, int $max ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $max;
	}

	/**
	 * Bounds a raw gate describe() value: the machine reason itself never enters the
	 * report (only its mapped slug does); bounding merely keeps hostile input small.
	 *
	 * @param mixed $value Raw describe() value.
	 * @return string
	 */
	private static function bounded_reason( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return substr( $value, 0, 200 );
	}

	private static function exact_keys( $array, array $expected, string $context, \Closure $fail ): void {
		if ( ! is_array( $array ) ) {
			$fail( "{$context}_not_array" );
		}

		$actual = array_keys( $array );
		sort( $actual );

		$expected_sorted = $expected;
		sort( $expected_sorted );

		if ( $actual !== $expected_sorted ) {
			$fail( "{$context}_keys_invalid" );
		}
	}
}
