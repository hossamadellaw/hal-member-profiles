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
	private const SELECTOR_TYPES  = array( 'text', 'url', 'image', 'list' );
	private const SYNC_MODES      = array( 'unavailable', 'off', 'discover_only', 'managed_additions', 'managed_sync' );
	private const SNAPSHOT_STATES = array( 'available', 'unavailable' );

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
		'sync_mode_read_only',
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

		$selector_types = array_fill_keys( self::SELECTOR_TYPES, 0 );

		foreach ( $selectors as $selector ) {
			$type = is_array( $selector ) ? (string) ( $selector['selector_type'] ?? '' ) : '';

			if ( ! in_array( $type, self::SELECTOR_TYPES, true ) ) {
				continue;
			}

			$selector_types[ $type ]++;
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
				'source_present' => function_exists( 'has_filter' ) && false !== has_filter( 'hal_member_profiles_account_field_definitions' ),
				'count'          => count( $selectors ),
				'types'          => $selector_types,
			),
			'amelia_reader' => array(
				'plugin_detected' => $dependencies->has_amelia(),
				'service_present' => null !== $bootstrap && null !== $bootstrap->get_amelia(),
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
				'service_present'   => null !== $bootstrap && $writer_present && is_callable( array( AmeliaFieldsWriter::class, 'build_plan' ) ),
				'route_present'     => null !== $bootstrap
					&& $writer_present
					&& function_exists( 'has_action' )
					&& false !== has_action( 'admin_post_hal_member_profiles_fields_apply', array( AdminDashboard::class, 'handle_amelia_post' ) ),
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
				'full_native_fallback'    => null !== $bootstrap && null !== $bootstrap->get_layout_contract(),
				'profile_gate_passes'     => null !== $gate ? $gate->passes( CompatibilityGate::CAP_PROFILE ) : false,
				'account_gate_passes'     => null !== $gate ? $gate->passes( CompatibilityGate::CAP_ACCOUNT ) : false,
				'um_schema_gate_passes'   => null !== $gate ? $gate->passes( CompatibilityGate::CAP_UM_SCHEMA ) : false,
				'dynamic_tags_gate_passes' => null !== $gate ? $gate->passes( CompatibilityGate::CAP_ELEMENTOR_DYNAMIC_TAGS ) : false,
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
		$selectors_present = $this->facts['account_selectors']['count'] > 0;
		$selectors_runtime = $selectors_present ? ( self::SOURCE_FIXTURE === $source ? 'pending' : 'ready' ) : 'blocked';
		$selectors_reason  = $selectors_present ? ( self::SOURCE_FIXTURE === $source ? 'awaiting_matrix_signoff' : 'gate_not_signed_off' ) : 'no_verified_account_source';

		$capabilities = array(
			'account_selectors' => array(
				'implementation' => 'partial',
				'runtime'        => $selectors_runtime,
				'verification'   => 'Pending',
				'reason'         => $selectors_reason,
				'evidence_level' => $this->evidence_level(),
				'counts'         => array(
					'total'             => $this->facts['account_selectors']['count'],
					'type_distribution' => $this->facts['account_selectors']['types'],
				),
				'source_present' => $this->facts['account_selectors']['source_present'],
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
		$json = json_encode(
			$this->generate(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $json ) {
			throw new \InvalidArgumentException( 'report_json_encoding_failed' );
		}

		return $json;
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

			if ( 'account_selectors' === $id ) {
				$keys[] = 'source_present';
			} elseif ( 'amelia_reader' === $id ) {
				$keys = array_merge( $keys, array( 'plugin_detected', 'service_present', 'sync_mode', 'snapshot_state', 'gate_passes', 'gate_reason' ) );
			} elseif ( 'amelia_writer' === $id ) {
				$keys = array_merge( $keys, array( 'class_present', 'service_present', 'route_present', 'strict_gate_passes', 'strict_gate_reason', 'sync_mode', 'mode_allows_write', 'secret_stored', 'secret_decodable', 'delete_supported' ) );
			} elseif ( 'um_integration' === $id ) {
				$keys = array_merge( $keys, array( 'um_detected', 'full_native_fallback', 'profile_gate_passes', 'account_gate_passes', 'um_schema_gate_passes', 'dynamic_tags_gate_passes' ) );
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

			if ( 'account_selectors' === $id ) {
				self::exact_keys( $capability['counts'], array( 'total', 'type_distribution' ), "capability_{$id}_counts", $fail );
				self::exact_keys( $capability['counts']['type_distribution'], self::SELECTOR_TYPES, "capability_{$id}_type_distribution", $fail );
				self::validate_counts( array( 'total' => $capability['counts']['total'] ) + $capability['counts']['type_distribution'], "capability_{$id}", $fail );

				if ( ! is_bool( $capability['source_present'] ) || array_sum( $capability['counts']['type_distribution'] ) !== $capability['counts']['total'] ) {
					$fail( 'capability_account_selectors_facts_invalid' );
				}
			} else {
				self::validate_counts( $capability['counts'], "capability_{$id}", $fail );
			}

			if ( in_array( $id, array( 'account_photo_tab', 'account_dashboard_tab' ), true ) ) {
				self::exact_keys( $capability['counts'], array(), "capability_{$id}_counts", $fail );
			}

			if ( 'amelia_reader' === $id ) {
				self::exact_keys( $capability['counts'], array( 'services', 'employees', 'custom_fields' ), "capability_{$id}_counts", $fail );

				if ( ! is_bool( $capability['plugin_detected'] ) || ! is_bool( $capability['service_present'] ) || ! in_array( $capability['sync_mode'], self::SYNC_MODES, true ) || ! in_array( $capability['snapshot_state'], self::SNAPSHOT_STATES, true ) || ! is_bool( $capability['gate_passes'] ) || ! in_array( $capability['gate_reason'], self::REASONS, true ) ) {
					$fail( 'capability_amelia_reader_facts_invalid' );
				}
			}

			if ( 'amelia_writer' === $id ) {
				self::exact_keys( $capability['counts'], array( 'desired', 'ledger', 'dry_plan_create', 'dry_plan_update', 'dry_plan_unchanged', 'dry_plan_orphaned' ), "capability_{$id}_counts", $fail );

				foreach ( array( 'class_present', 'service_present', 'route_present', 'strict_gate_passes', 'mode_allows_write', 'secret_stored', 'secret_decodable' ) as $boolean_key ) {
					if ( ! is_bool( $capability[ $boolean_key ] ) ) {
						$fail( "capability_amelia_writer_{$boolean_key}_invalid" );
					}
				}

				if ( ! in_array( $capability['strict_gate_reason'], self::REASONS, true ) || ! in_array( $capability['sync_mode'], self::SYNC_MODES, true ) ) {
					$fail( 'capability_amelia_writer_facts_invalid' );
				}
			}

			if ( 'um_integration' === $id ) {
				self::exact_keys( $capability['counts'], array( 'gates_total', 'gates_passing' ), "capability_{$id}_counts", $fail );

				foreach ( array( 'um_detected', 'full_native_fallback', 'profile_gate_passes', 'account_gate_passes', 'um_schema_gate_passes', 'dynamic_tags_gate_passes' ) as $boolean_key ) {
					if ( ! is_bool( $capability[ $boolean_key ] ) ) {
						$fail( "capability_um_integration_{$boolean_key}_invalid" );
					}
				}

				if ( 4 !== $capability['counts']['gates_total'] || array_sum( array_map( 'intval', array( $capability['profile_gate_passes'], $capability['account_gate_passes'], $capability['um_schema_gate_passes'], $capability['dynamic_tags_gate_passes'] ) ) ) !== $capability['counts']['gates_passing'] ) {
					$fail( 'capability_um_integration_gates_mismatch' );
				}
			}

			if ( 'amelia_writer' === $id && false !== $capability['delete_supported'] ) {
				$fail( 'capability_amelia_writer_delete_supported_must_be_false' );
			}

			$expected_evidence = in_array( $id, array( 'account_photo_tab', 'account_dashboard_tab' ), true )
				? 'not_observed'
				: ( self::SOURCE_FIXTURE === $report['source'] ? 'contract_fixture' : 'runtime_observation' );

			if ( $expected_evidence !== $capability['evidence_level'] ) {
				$fail( "capability_{$id}_source_evidence_mismatch" );
			}

			if ( self::SOURCE_FIXTURE === $report['source'] && 'ready' === $capability['runtime'] ) {
				$fail( "capability_{$id}_fixture_runtime_ready_forbidden" );
			}

			if ( self::SOURCE_FIXTURE === $report['source'] && 'Pass' === $capability['verification'] ) {
				$fail( "capability_{$id}_fixture_verification_pass_forbidden" );
			}
		}

		self::exact_keys( $report['declared_integrations'], self::INTEGRATION_IDS, 'declared_integrations', $fail );

		foreach ( $report['declared_integrations'] as $id => $integration ) {
			self::exact_keys( $integration, array( 'detected', 'verification', 'evidence_level' ), "integration_{$id}", $fail );

			if ( ! is_bool( $integration['detected'] ) || ! in_array( $integration['verification'], self::VERIFICATIONS, true ) || ! in_array( $integration['evidence_level'], self::EVIDENCE_LEVELS, true ) ) {
				$fail( "integration_{$id}_invalid" );
			}

			$expected_evidence = self::SOURCE_FIXTURE === $report['source'] ? 'contract_fixture' : 'runtime_observation';

			if ( $expected_evidence !== $integration['evidence_level'] || ( self::SOURCE_FIXTURE === $report['source'] && 'Pending' !== $integration['verification'] ) ) {
				$fail( "integration_{$id}_source_provenance_invalid" );
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

		$json = json_encode( $report );

		if ( false === $json ) {
			$fail( 'report_json_encoding_failed' );
		}

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
		$selector_types = array_fill_keys( self::SELECTOR_TYPES, 0 );

		foreach ( self::SELECTOR_TYPES as $type ) {
			$selector_types[ $type ] = max( 0, (int) ( $selectors['types'][ $type ] ?? 0 ) );
		}

		$selector_count = max( 0, (int) ( $selectors['count'] ?? 0 ) );

		return array(
			'source'         => $facts['source'],
			'plugin_version' => $version,
			'environment'    => $environment,
			'account_selectors' => array(
				'source_present' => (bool) ( $selectors['source_present'] ?? false ),
				'count'          => $selector_count,
				'types'          => $selector_types,
			),
			'amelia_reader' => array(
				'plugin_detected' => (bool) ( $facts['amelia_reader']['plugin_detected'] ?? false ),
				'service_present' => (bool) ( $facts['amelia_reader']['service_present'] ?? false ),
				'sync_mode'       => self::normalize_sync_mode( $facts['amelia_reader']['sync_mode'] ?? '' ),
				'snapshot_ok'     => (bool) ( $facts['amelia_reader']['snapshot_ok'] ?? false ),
				'services'        => max( 0, (int) ( $facts['amelia_reader']['services'] ?? 0 ) ),
				'employees'       => max( 0, (int) ( $facts['amelia_reader']['employees'] ?? 0 ) ),
				'custom_fields'   => max( 0, (int) ( $facts['amelia_reader']['custom_fields'] ?? 0 ) ),
				'gate_passes'     => (bool) ( $facts['amelia_reader']['gate_passes'] ?? false ),
				'gate_reason'     => self::bounded_reason( $facts['amelia_reader']['gate_reason'] ?? '' ),
			),
			'amelia_writer' => array(
				'class_present'     => (bool) ( $facts['amelia_writer']['class_present'] ?? false ),
				'service_present'   => (bool) ( $facts['amelia_writer']['service_present'] ?? false ),
				'route_present'     => (bool) ( $facts['amelia_writer']['route_present'] ?? false ),
				'gate_passes'       => (bool) ( $facts['amelia_writer']['gate_passes'] ?? false ),
				'gate_reason'       => self::bounded_reason( $facts['amelia_writer']['gate_reason'] ?? '' ),
				'sync_mode'         => self::normalize_sync_mode( $facts['amelia_writer']['sync_mode'] ?? '' ),
				'mode_allows_write' => (bool) ( $facts['amelia_writer']['mode_allows_write'] ?? false ),
				'secret_stored'     => (bool) ( $facts['amelia_writer']['secret_stored'] ?? false ),
				'secret_decodable'  => (bool) ( $facts['amelia_writer']['secret_decodable'] ?? false ),
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
				'full_native_fallback'    => (bool) ( $facts['um_integration']['full_native_fallback'] ?? false ),
				'profile_gate_passes'     => (bool) ( $facts['um_integration']['profile_gate_passes'] ?? false ),
				'account_gate_passes'     => (bool) ( $facts['um_integration']['account_gate_passes'] ?? false ),
				'um_schema_gate_passes'   => (bool) ( $facts['um_integration']['um_schema_gate_passes'] ?? false ),
				'dynamic_tags_gate_passes' => (bool) ( $facts['um_integration']['dynamic_tags_gate_passes'] ?? false ),
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
		$gate_reason = $facts['gate_passes'] ? 'gate_passed' : $this->gate_reason_slug( $facts['gate_reason'] );

		// 'off' is Settings::SYNC_MODE_OFF written literally: generate()/validate() are
		// the pure path used by the ci_fixture script without WordPress, so they must
		// never reference WP-side classes.
		if ( ! $facts['plugin_detected'] || ! $facts['service_present'] ) {
			$runtime = 'not_detected';
			$reason  = 'dependency_not_detected';
		} elseif ( in_array( $facts['sync_mode'], array( 'unavailable', 'off' ), true ) ) {
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
			$reason  = $gate_reason;
		}

		if ( self::SOURCE_FIXTURE === $this->facts['source'] && 'ready' === $runtime ) {
			$runtime = 'pending';
			$reason  = 'awaiting_matrix_signoff';
		}

		return array(
			'implementation' => 'implemented',
			'runtime'        => $runtime,
			'verification'   => self::SOURCE_RUNTIME === $this->facts['source'] && 'ready' === $runtime ? 'Pass' : 'Pending',
			'reason'         => $reason,
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'services'      => $facts['services'],
				'employees'     => $facts['employees'],
				'custom_fields' => $facts['custom_fields'],
			),
			'plugin_detected' => $facts['plugin_detected'],
			'service_present' => $facts['service_present'],
			'sync_mode'       => $facts['sync_mode'],
			'snapshot_state'  => $facts['snapshot_ok'] ? 'available' : 'unavailable',
			'gate_passes'     => $facts['gate_passes'],
			'gate_reason'     => $gate_reason,
		);
	}

	private function amelia_writer_capability(): array {
		$facts = $this->facts['amelia_writer'];

		$strict_gate_reason = $facts['gate_passes'] ? 'gate_passed' : $this->gate_reason_slug( $facts['gate_reason'] );
		$gate_open = $facts['class_present'] && $facts['service_present'] && $facts['route_present'] && $facts['gate_passes'] && $facts['mode_allows_write'];

		if ( ! $facts['class_present'] || ! $facts['service_present'] ) {
			$runtime = 'not_detected';
			$reason  = 'dependency_not_detected';
		} elseif ( ! $facts['route_present'] ) {
			$runtime = 'blocked';
			$reason  = 'missing_components';
		} elseif ( ! $facts['gate_passes'] ) {
			$runtime = 'blocked';
			$reason  = $strict_gate_reason;
		} elseif ( ! $facts['mode_allows_write'] ) {
			$runtime = 'not_configured';
			$reason  = in_array( $facts['sync_mode'], array( 'unavailable', 'off' ), true ) ? 'sync_mode_off' : 'sync_mode_read_only';
		} else {
			$runtime = self::SOURCE_RUNTIME === $this->facts['source'] ? 'ready' : 'pending';
			$reason  = self::SOURCE_RUNTIME === $this->facts['source'] ? 'gate_passed' : 'awaiting_matrix_signoff';
		}

		return array(
			'implementation' => $facts['class_present'] ? 'implemented' : 'not_implemented',
			'runtime'        => $runtime,
			'verification'   => self::SOURCE_RUNTIME === $this->facts['source'] && $gate_open ? 'Pass' : 'Pending',
			'reason'         => $reason,
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'desired'           => $facts['desired_count'],
				'ledger'            => $facts['ledger_count'],
				'dry_plan_create'   => $facts['dry_plan']['to_create'],
				'dry_plan_update'   => $facts['dry_plan']['to_update'],
				'dry_plan_unchanged' => $facts['dry_plan']['unchanged'],
				'dry_plan_orphaned' => $facts['dry_plan']['orphaned'],
			),
			'class_present'       => $facts['class_present'],
			'service_present'     => $facts['service_present'],
			'route_present'       => $facts['route_present'],
			'strict_gate_passes'  => $facts['gate_passes'],
			'strict_gate_reason'  => $strict_gate_reason,
			'sync_mode'           => $facts['sync_mode'],
			'mode_allows_write'   => $facts['mode_allows_write'],
			'secret_stored'       => $facts['secret_stored'],
			'secret_decodable'    => $facts['secret_decodable'],
			// Closed card fact: NO delete verb exists anywhere in the writer — removals
			// are surfaced as orphaned plan rows for manual handling only.
			'delete_supported' => false,
		);
	}

	private function um_integration_capability(): array {
		$facts = $this->facts['um_integration'];

		$gates = array( $facts['profile_gate_passes'], $facts['account_gate_passes'], $facts['um_schema_gate_passes'], $facts['dynamic_tags_gate_passes'] );
		$passing = count( array_filter( $gates ) );

		if ( ! $facts['um_detected'] ) {
			$runtime = 'not_detected';
			$reason  = 'dependency_not_detected';
		} elseif ( 4 === $passing ) {
			$runtime = self::SOURCE_RUNTIME === $this->facts['source'] ? 'ready' : 'pending';
			$reason  = self::SOURCE_RUNTIME === $this->facts['source'] ? 'gate_passed' : 'awaiting_matrix_signoff';
		} elseif ( $facts['full_native_fallback'] ) {
			$runtime = 'native_fallback';
			$reason  = 'gate_not_signed_off';
		} else {
			$runtime = 'blocked';
			$reason  = 'missing_components';
		}

		return array(
			'implementation' => 'implemented',
			'runtime'        => $runtime,
			'verification'   => self::SOURCE_RUNTIME === $this->facts['source'] && 4 === $passing ? 'Pass' : 'Pending',
			'reason'         => $reason,
			'evidence_level' => $this->evidence_level(),
			'counts'         => array(
				'gates_total'   => 4,
				'gates_passing' => $passing,
			),
			'um_detected'              => $facts['um_detected'],
			'full_native_fallback'     => $facts['full_native_fallback'],
			'profile_gate_passes'      => $facts['profile_gate_passes'],
			'account_gate_passes'      => $facts['account_gate_passes'],
			'um_schema_gate_passes'    => $facts['um_schema_gate_passes'],
			'dynamic_tags_gate_passes' => $facts['dynamic_tags_gate_passes'],
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

	private static function normalize_sync_mode( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, self::SYNC_MODES, true ) ? $value : 'unavailable';
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
