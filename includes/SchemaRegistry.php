<?php
/**
 * Normalizes UM and Amelia schemas into one catalog WITHOUT owning any source data —
 * development card D-09.
 *
 * Sole responsibility: shape conversion + classification + fixed mapping metadata.
 * Source data stays where it belongs: UM fields come from the ACTUAL Form ID through
 * {@see FieldSchema} (the Phase-1 single reader of `_um_custom_fields`, remediation card
 * F-09), Amelia entities come exclusively from the administrative REST snapshot taken
 * through {@see \HAL\MemberProfiles\Integrations\AmeliaApiClient} (card D-08), and the
 * member↔employee linkage stays governed by the existing matrix-controlled meta keys
 * consumed by `includes/Integrations/Amelia.php` (compatibility-matrix §4.0).
 *
 * Classification contract (four exclusive buckets):
 * - public          : Profile Form fields FieldSchema itself approved (its own sensitive/
 *                     unsupported exclusions already applied), plus Amelia services and
 *                     employees (business catalog data).
 * - account_only    : Account fields exposed ONLY through FieldSchema's registered,
 *                     matrix-gated Account adapter (empty until such an adapter exists).
 * - sensitive       : credentials/identity/system material. A deliberately duplicated
 *                     denylist lives HERE as defense-in-depth over FieldSchema: it can
 *                     only ever REMOVE items from exposure, never grant any.
 * - unsupported     : everything else, explicitly named instead of guessed. Missing
 *                     mappings are NEVER inferred into existence.
 *
 * Snapshot discipline (acceptance): the stored Amelia snapshot is PII-FREE BY
 * CONSTRUCTION — employees contribute their numeric IDs only, never names or emails.
 * Extraction is conservative: if a payload section exists but does not match the
 * recognized shape, the ENTIRE refresh fails closed with unrecognized_payload and the
 * previous snapshot stays untouched; nothing partial is ever written.
 *
 * Cache rules: UM normalization caches per request keyed by Form ID; the Amelia snapshot
 * persists in a NON-autoloaded option and changes only through the guarded refresh verb.
 *
 * Integration note for card D-14: this file registers no hooks on include; consumers
 * instantiate/invoke it during bootstrapping. Until wired it is unreachable.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

use HAL\MemberProfiles\Integrations\AmeliaApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaRegistry {

	public const SNAPSHOT_OPTION = 'hal_member_profiles_amelia_snapshot';
	public const SNAPSHOT_VERSION = 1;

	public const VIS_PUBLIC        = 'public';
	public const VIS_ACCOUNT_ONLY  = 'account_only';
	public const VIS_SENSITIVE     = 'sensitive';
	public const VIS_UNSUPPORTED   = 'unsupported';

	private const SENSITIVE_METAKEYS = array(
		'user_password', 'password', 'confirm_password', 'user_email', 'email',
		'username', 'user_login', 'role', 'account_status', 'status',
		'secret_key', 'ID', 'token', 'api_key',
	);

	private const SENSITIVE_TYPE_HINTS = array(
		'password', 'confirm_password', 'hidden', 'email',
	);

	private FieldSchema $field_schema;

	public function __construct( ?FieldSchema $field_schema = null ) {
		$this->field_schema = $field_schema ?? new FieldSchema();
	}

	/**
	 * Integration Closure #3: the effective Amelia sync switch, read from Settings and
	 * locked to the four documented modes. Anything unreadable/garbage fails closed to
	 * `off` (no API calls, no sync work).
	 *
	 * @return string One of Settings::SYNC_MODE_* values.
	 */
	public static function current_sync_mode(): string {
		if ( ! class_exists( \HAL\MemberProfiles\Settings::class ) ) {
			return \HAL\MemberProfiles\Settings::SYNC_MODE_OFF;
		}

		$stored  = get_option( \HAL\MemberProfiles\Settings::OPTION_KEY, array() );
		$mode    = is_array( $stored ) ? (string) ( $stored['amelia_sync_mode'] ?? '' ) : '';
		$allowed = array(
			\HAL\MemberProfiles\Settings::SYNC_MODE_OFF,
			\HAL\MemberProfiles\Settings::SYNC_MODE_DISCOVER_ONLY,
			\HAL\MemberProfiles\Settings::SYNC_MODE_MANAGED_ADDITIONS,
			\HAL\MemberProfiles\Settings::SYNC_MODE_MANAGED_SYNC,
		);

		return in_array( $mode, $allowed, true ) ? $mode : \HAL\MemberProfiles\Settings::SYNC_MODE_OFF;
	}

	/**
	 * Whether READ-ONLY Amelia REST traffic (connection test, entities/fields discovery)
	 * is permitted right now. Everything except `off` allows discovery.
	 *
	 * @return bool
	 */
	public static function mode_allows_read(): bool {
		return self::SYNC_MODE_OFF_CONST() !== self::current_sync_mode();
	}

	/**
	 * Whether AMELIA-SIDE WRITE operations are permitted right now: only the two managed
	 * modes qualify; `off` and `discover_only` never do (§9.1).
	 *
	 * @return bool
	 */
	public static function mode_allows_write(): bool {
		return in_array(
			self::current_sync_mode(),
			array(
				\HAL\MemberProfiles\Settings::SYNC_MODE_MANAGED_ADDITIONS,
				\HAL\MemberProfiles\Settings::SYNC_MODE_MANAGED_SYNC,
			),
			true
		);
	}

	/**
	 * @return string The literal Off mode slug without requiring a Settings instance.
	 */
	private static function SYNC_MODE_OFF_CONST(): string {
		return 'off';
	}


	/**
	 * Fixed, machine-readable mapping contract linking the two systems by IDs — labels
	 * are NEVER a matching criterion. Values live in the governed stores referenced
	 * below; this registry only declares the shape, direction, and types.
	 *
	 * @return array<string,mixed>
	 */
	public function mapping_contract(): array {
		return array(
			'version'            => 1,
			'member_to_employee' => array(
				'direction'      => 'um_user -> amelia_employee',
				'carrier'        => 'usermeta',
				// Canonical owner: includes/Integrations/Amelia.php (matrix §4.0).
				'key'            => 'hal_amelia_employee_id',
				'value_type'     => 'positive_int',
				'source_of_truth' => 'Amelia admin records; never email/name/ordering',
			),
			'member_services'    => array(
				'direction'      => 'um_user -> amelia_service_ids allowlist',
				'carrier'        => 'usermeta',
				'key'            => 'hal_amelia_service_ids',
				'value_type'     => 'array<positive_int>',
				'source_of_truth' => 'intersected with the synced service catalog at save time',
			),
			'service_catalog'    => array(
				'direction'      => 'amelia_rest -> hal snapshot',
				'carrier'        => 'option:' . self::SNAPSHOT_OPTION,
				'value_type'     => 'array<service>',
				'source_of_truth' => 'administrative REST snapshot (this class)',
			),
		);
	}

	/**
	 * Normalizes ONE Profile Form's fields, identified by the ACTUAL Form ID. Per-request
	 * cached; identical inputs return identical output without touching post meta again.
	 *
	 * @param int $form_id UM Form ID resolved upstream (0 yields an empty public set).
	 * @return array{ok:bool, reason:string, items:array<int,array<string,string>>}
	 */
	public function um_profile_schema( int $form_id ): array {
		static $cache = array();

		$cache_key = (string) $form_id;

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( $form_id <= 0 ) {
			return $cache[ $cache_key ] = array(
				'ok'     => true,
				'reason' => 'no_form_context',
				'items'  => array(),
			);
		}

		$raw = get_post_meta( $form_id, '_um_custom_fields', true );

		if ( ! is_array( $raw ) ) {
			return $cache[ $cache_key ] = array(
				'ok'     => false,
				'reason' => 'um_form_unreadable',
				'items'  => array(),
			);
		}

		$items = array();

		foreach ( $raw as $metakey => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$metakey = is_string( $metakey ) ? $metakey : (string) ( $field['metakey'] ?? '' );
			$label   = (string) ( $field['title'] ?? ( $field['label'] ?? $metakey ) );

			$items[] = array(
				'identifier' => $metakey,
				'label'      => $label,
				'type'       => isset( $field['type'] ) ? (string) $field['type'] : '',
				'visibility' => $this->classify_um_field( $metakey, $field ),
			);
		}

		return $cache[ $cache_key ] = array(
			'ok'     => true,
			'reason' => 'normalized',
			'items'  => $items,
		);
	}

	/**
	 * Normalizes Account-side fields. Empty unless a matrix-verified adapter registered
	 * definitions with FieldSchema — absence is reported, never improvised.
	 *
	 * @return array{ok:bool, reason:string, items:array<int,array<string,string>>}
	 */
	public function um_account_schema(): array {
		$selectors = $this->field_schema->get_account_selectors();

		$items = array();

		foreach ( $selectors as $selector ) {
			$items[] = array(
				'identifier' => (string) ( $selector['metakey'] ?? '' ),
				'label'      => (string) ( $selector['label'] ?? '' ),
				'type'       => (string) ( $selector['selector_type'] ?? '' ),
				'visibility' => self::VIS_ACCOUNT_ONLY,
			);
		}

		return array(
			'ok'     => true,
			'reason' => empty( $items ) ? 'no_verified_account_source' : 'normalized',
			'items'  => $items,
		);
	}

	/**
	 * Refreshes the administrative Amelia snapshot through the guarded REST client:
	 * /entities + /fields, extracted into a PII-free index. Admin capability required;
	 * failures keep the previous snapshot byte-for-byte intact.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public function refresh_amelia_snapshot(): array {
		if ( ! $this->may_write() ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		// Integration Closure #3: discovery traffic requires anything above `off`.
		if ( ! self::mode_allows_read() ) {
			return array( 'ok' => false, 'reason' => 'sync_off' );
		}

		self::ensure_client_loaded();

		$entities = AmeliaApiClient::get_entities();

		if ( empty( $entities['ok'] ) ) {
			$reason = is_string( $entities['reason'] ?? null ) ? $entities['reason'] : 'unknown';

			return array( 'ok' => false, 'reason' => 'entities_' . $reason );
		}

		$fields = AmeliaApiClient::get_fields();

		if ( empty( $fields['ok'] ) ) {
			$reason = is_string( $fields['reason'] ?? null ) ? $fields['reason'] : 'unknown';

			return array( 'ok' => false, 'reason' => 'fields_' . $reason );
		}

		$index = $this->extract_pii_free_index(
			is_array( $entities['data'] ?? null ) ? $entities['data'] : array(),
			is_array( $fields['data'] ?? null ) ? $fields['data'] : array()
		);

		if ( null === $index ) {
			return array( 'ok' => false, 'reason' => 'unrecognized_payload' );
		}

		$snapshot = array_merge(
			$index,
			array(
				'version'    => self::SNAPSHOT_VERSION,
				'fetched_at' => time(),
				'source'     => 'amelia_rest_admin_snapshot',
			)
		);

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		return array( 'ok' => true, 'reason' => 'snapshot_updated' );
	}

	/**
	 * Reads the stored PII-free snapshot, or reports its absence/state honestly.
	 *
	 * @return array{ok:bool, reason:string, snapshot:?array<string,mixed>}
	 */
	public function amelia_snapshot(): array {
		$stored = get_option( self::SNAPSHOT_OPTION, null );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array( 'ok' => false, 'reason' => 'no_snapshot', 'snapshot' => null );
		}

		if ( self::SNAPSHOT_VERSION !== ( $stored['version'] ?? null ) ) {
			return array( 'ok' => false, 'reason' => 'snapshot_version_mismatch', 'snapshot' => null );
		}

		return array( 'ok' => true, 'reason' => 'available', 'snapshot' => $stored );
	}

	/**
	 * Normalized Amelia catalog view derived ONLY from the stored snapshot. Custom fields
	 * default to `unsupported`: exposure for them requires an explicit future mapping
	 * decision, never an inference.
	 *
	 * @return array{ok:bool, reason:string, items:array<int,array<string,mixed>>}
	 */
	public function amelia_catalog(): array {
		$state = $this->amelia_snapshot();

		if ( ! $state['ok'] ) {
			return array(
				'ok'     => false,
				'reason' => $state['reason'],
				'items'  => array(),
			);
		}

		$snapshot = $state['snapshot'];
		$items    = array();

		foreach ( (array) ( $snapshot['services'] ?? array() ) as $service ) {
			$items[] = array(
				'kind'       => 'service',
				'id'         => (int) ( $service['id'] ?? 0 ),
				'label'      => (string) ( $service['title'] ?? '' ),
				'visibility' => self::VIS_PUBLIC,
			);
		}

		foreach ( (array) ( $snapshot['employees'] ?? array() ) as $employee ) {
			$items[] = array(
				'kind'       => 'employee',
				'id'         => (int) ( $employee['id'] ?? 0 ),
				'label'      => sprintf( /* translators: %d: Amelia employee numeric ID. */ __( 'Employee #%d', 'hal-member-profiles' ), (int) ( $employee['id'] ?? 0 ) ),
				'visibility' => self::VIS_PUBLIC,
			);
		}

		foreach ( (array) ( $snapshot['custom_fields'] ?? array() ) as $custom_field ) {
			$items[] = array(
				'kind'       => 'custom_field',
				'id'         => (int) ( $custom_field['id'] ?? 0 ),
				'label'      => (string) ( $custom_field['title'] ?? '' ),
				'type'       => (string) ( $custom_field['type'] ?? '' ),
				'visibility' => self::VIS_UNSUPPORTED,
			);
		}

		return array( 'ok' => true, 'reason' => 'catalog_ready', 'items' => $items );
	}

	/**
	 * Four-bucket classification for one UM field definition. Order matters: the local
	 * denylist wins OVER FieldSchema approval (defense-in-depth), then the shared
	 * classifier decides public-vs-unsupported; nothing is ever guessed upward.
	 *
	 * @param string $metakey Field meta key.
	 * @param array  $field   Raw UM definition.
	 * @return string One of the VIS_* constants.
	 */
	private function classify_um_field( string $metakey, array $field ): string {
		$type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';

		if ( $this->looks_sensitive( $metakey, $type ) ) {
			return self::VIS_SENSITIVE;
		}

		if ( '' === $metakey ) {
			return self::VIS_UNSUPPORTED;
		}

		return null !== $this->field_schema->classify_metakey( $metakey, $field )
			? self::VIS_PUBLIC
			: self::VIS_UNSUPPORTED;
	}

	/**
	 * Deliberate local denylist (metakey + declared-type hints). Duplication over
	 * FieldSchema is intentional and one-directional: it can only downgrade exposure.
	 *
	 * @param string $metakey Field meta key.
	 * @param string $type    Declared UM type.
	 * @return bool
	 */
	private function looks_sensitive( string $metakey, string $type ): bool {
		$haystack_key = strtolower( $metakey );

		if ( in_array( $haystack_key, self::SENSITIVE_METAKEYS, true ) ) {
			return true;
		}

		foreach ( array( 'pass', 'email', 'secret', 'token', 'api_key' ) as $fragment ) {
			if ( false !== strpos( $haystack_key, $fragment ) ) {
				return true;
			}
		}

		return in_array( $type, self::SENSITIVE_TYPE_HINTS, true );
	}

	/**
	 * Conservative extractor: builds the PII-free index ONLY when every present section
	 * matches the recognized shape. Employees reduce to bare numeric IDs; titles survive
	 * for services/custom fields (business labels, not personal data). Any structural
	 * surprise fails the WHOLE extraction so no half-understood payload is ever stored.
	 *
	 * Recognized container shapes, tried in order: top-level key, then `data.<key>`.
	 *
	 * @param array<string,mixed> $entities_payload Decoded /entities body.
	 * @param array<string,mixed> $fields_payload   Decoded /fields body.
	 * @return array<string,mixed>|null Index, or null when unrecognized.
	 */
	private function extract_pii_free_index( array $entities_payload, array $fields_payload ): ?array {
		$services = $this->extract_section( $entities_payload, 'services', true );
		$employees = $this->extract_section( $entities_payload, 'employees', false );

		if ( null === $services || null === $employees ) {
			return null;
		}

		$custom_fields = $this->extract_section( $fields_payload, 'custom_fields', true );

		if ( null === $custom_fields ) {
			return null;
		}

		return array(
			'services'      => $services,
			'employees'     => $employees,
			'custom_fields' => $custom_fields,
			'counts'        => array(
				'services'      => count( $services ),
				'employees'     => count( $employees ),
				'custom_fields' => count( $custom_fields ),
			),
		);
	}

	/**
	 * Pulls one named section from either container position and validates every item.
	 * A section that is GENUINELY ABSENT yields an empty list; a section that is PRESENT
	 * but structurally wrong (scalar, non-list, bad items) fails the WHOLE extraction.
	 *
	 * @param array<string,mixed> $payload       Decoded body.
	 * @param string              $name          Section name.
	 * @param bool                $keep_title    Whether the item carries a business title.
	 * @return array<int,array<string,mixed>>|null Null when the section exists but is malformed.
	 */
	private function extract_section( array $payload, string $name, bool $keep_title ): ?array {
		$candidates = array();

		if ( array_key_exists( $name, $payload ) ) {
			$candidates[] = $payload[ $name ];
		}

		if ( array_key_exists( 'data', $payload ) ) {
			if ( ! is_array( $payload['data'] ) ) {
				// An unparseable envelope cannot be trusted for any section.
				return null;
			}

			if ( array_key_exists( $name, $payload['data'] ) ) {
				$candidates[] = $payload['data'][ $name ];
			}
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		$section = $candidates[0];

		if ( ! is_array( $section ) ) {
			return null;
		}

		$rows = array();

		foreach ( $section as $item ) {
			if ( ! is_array( $item ) ) {
				return null;
			}

			$id = isset( $item['id'] ) ? (int) $item['id'] : 0;

			if ( $id <= 0 ) {
				return null;
			}

			$row = array( 'id' => $id );

			if ( $keep_title ) {
				$row['title'] = isset( $item['title'] ) && is_string( $item['title'] )
					? $item['title']
					: '';
			}

			if ( 'custom_fields' === $name ) {
				$row['type'] = isset( $item['type'] ) && is_string( $item['type'] )
					? $item['type']
					: '';
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Admin-context and capability boundary shared by mutating verbs; request nonces
	 * belong to the calling endpoint.
	 *
	 * @return bool
	 */
	private function may_write(): bool {
		return is_admin() && current_user_can( 'manage_options' );
	}

	/**
	 * Guarantees the REST client class exists before use (no autoloader ships).
	 *
	 * @return void
	 */
	private static function ensure_client_loaded(): void {
		if ( ! class_exists( AmeliaApiClient::class ) && defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/AmeliaApiClient.php';
		}
	}
}
