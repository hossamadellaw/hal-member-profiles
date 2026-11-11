<?php
/**
 * Keeps the site's existing Amelia service-selection policy — never rebuilds Amelia itself.
 *
 * Migrated from the site's existing um-amelia-integration.php (employee ID, legacy
 * normalization, selected_services, and the allowlist only), reviewed against every
 * consumer first. hal_amelia_api_request() and its admin-only loopback API are
 * intentionally not carried forward into this release.
 *
 * Development card D-11: the LIVE service catalog now comes from SchemaRegistry's
 * administrative PII-free snapshot (card D-09) instead of the frozen empty constant;
 * SERVICE_CATALOG remains solely as the legacy-name migration table for pre-ID stored
 * values. Employee identity resolves through the documented externalId filter FIRST,
 * then the governed local mirror metakey — email/name inference stays forbidden. The
 * F-16 stored-value contract below is frozen: same hook, same three wipe cases, same
 * allowlist intersection. Amelia remains the sole owner of availability and booking;
 * this class never checks availability, books anything, or touches bookings.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Amelia {

	const EMPLOYEE_ID_META_KEY       = 'hal_amelia_employee_id';
	const EMPLOYEE_SERVICES_META_KEY = 'hal_amelia_service_ids';

	/**
	 * LEGACY-MIGRATION TABLE ONLY (since development card D-11): pre-ID service name
	 * aliases for normalizing very old stored values. It is NO LONGER the live catalog
	 * source — the live catalog comes from SchemaRegistry's administrative snapshot.
	 * Stays empty unless the owner records historical aliases deliberately.
	 *
	 * @var array
	 */
	const SERVICE_CATALOG = array();

	private Settings $settings;

	/**
	 * Test-only catalog override (mirrors CompatibilityGate's pattern). Production always
	 * passes null and consumes SchemaRegistry's stored snapshot.
	 *
	 * @var array|null
	 */
	private ?array $catalog_override;

	public function __construct( Settings $settings, ?array $catalog_override = null ) {
		$this->settings         = $settings;
		$this->catalog_override = $catalog_override;
	}

	/**
	 * An employee's Amelia identity. Resolution order (card D-11): a DOCUMENTED
	 * externalId supplied through the
	 * `hal_member_profiles_amelia_employee_external_id` filter wins — intended exclusively
	 * for a verified, matrix-recorded adapter — and only then the governed local mirror
	 * metakey is consulted. Email, username, display-name, and ordering are NEVER consulted:
	 * any unusable filter value fails toward the local mirror, never toward a guess.
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public function get_employee_id( int $user_id ): ?int {
		$external = apply_filters( 'hal_member_profiles_amelia_employee_external_id', null, $user_id );

		if ( is_int( $external ) && $external > 0 ) {
			return $external;
		}

		if ( is_string( $external ) && 1 === preg_match( '/^\d{1,20}$/', trim( $external ) ) ) {
			$external_id = absint( trim( $external ) );

			if ( $external_id > 0 ) {
				return $external_id;
			}
		}

		$employee_id = absint( get_user_meta( $user_id, self::EMPLOYEE_ID_META_KEY, true ) );

		return $employee_id > 0 ? $employee_id : null;
	}

	/**
	 * The employee's own allowed service IDs, as maintained locally by an administrator.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public function get_employee_service_ids( int $user_id ): array {
		return $this->normalize_positive_ids( get_user_meta( $user_id, self::EMPLOYEE_SERVICES_META_KEY, true ) );
	}

	/**
	 * The live service catalog, cleaned to id => name pairs only. Since card D-11 the
	 * production source is SchemaRegistry's stored PII-free snapshot (public services);
	 * the constructor-level override remains the deterministic test seam. Entries without
	 * a usable title drop out — an unnamed service can never enter the allowlist math.
	 *
	 * @return array<int,string>
	 */
	public function get_service_catalog(): array {
		$source = null !== $this->catalog_override
			? $this->catalog_override
			: $this->snapshot_catalog();

		$clean = array();

		foreach ( $source as $service_id => $service ) {
			$service_id = absint( $service_id );

			if ( $service_id <= 0 ) {
				continue;
			}

			$name = is_array( $service ) ? ( $service['name'] ?? '' ) : $service;

			if ( ! is_scalar( $name ) || '' === trim( (string) $name ) ) {
				continue;
			}

			$name = trim( (string) $name );

			// Card C5: a display label longer than WordPress's own term-name ceiling is
			// not a justified display value — it drops out rather than surfacing.
			if ( mb_strlen( $name ) > 191 ) {
				continue;
			}

			$clean[ $service_id ] = $name;
		}

		return $clean;
	}

	/**
	 * Service IDs this user is actually allowed to select: in the local catalog, and within
	 * their own employee's assigned services when they are a mapped employee. Never any ID
	 * outside both of these limits.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public function get_allowed_service_ids( int $user_id ): array {
		$catalog_ids = array_keys( $this->get_service_catalog() );

		if ( empty( $catalog_ids ) || null === $this->get_employee_id( $user_id ) ) {
			return array();
		}

		return array_values( array_intersect( $catalog_ids, $this->get_employee_service_ids( $user_id ) ) );
	}

	/**
	 * The user's currently stored selected_services, normalized to positive integer IDs
	 * (accepting either numeric IDs or legacy names already present in older data).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public function get_selected_service_ids( int $user_id ): array {
		return $this->normalize_service_values( get_user_meta( $user_id, 'selected_services', true ) );
	}

	/**
	 * The user's selected services actually within their allowlist, as id => name pairs.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string>
	 */
	public function get_member_services( int $user_id ): array {
		$catalog  = $this->get_service_catalog();
		$allowed  = $this->get_allowed_service_ids( $user_id );
		$selected = array_intersect( $this->get_selected_service_ids( $user_id ), $allowed );

		$services = array();

		foreach ( $selected as $service_id ) {
			if ( isset( $catalog[ $service_id ] ) ) {
				$services[ $service_id ] = $catalog[ $service_id ];
			}
		}

		return $services;
	}

	/**
	 * Card C5b: the professional's explicit, safe service-selection save contract.
	 * INDEPENDENT of the S-01-closed direct-save body above (which stays byte-identical)
	 * and of AmeliaFieldsWriter. Implements the mandated save-state table:
	 *
	 *   submitted values     -> normalize, then intersect with the CURRENT allowlist, then
	 *                           MERGE with stored orphaned selections (kept in usermeta,
	 *                           hidden from display, never deleted here).
	 *   explicit empty       -> remove still-eligible stored selections only; stored
	 *                           orphaned selections SURVIVE; result is [] only when the
	 *                           owner had no orphaned selections at all.
	 *   field omitted        -> the caller never reaches this method (hook checks presence).
	 *   catalog unavailable  -> keep the previous stored value; return a safe reason,
	 *                           never a wipe (unavailable must not erase choices).
	 *   non-professional     -> reject the input, leave the stored value untouched.
	 *   forged/ineligible    -> dropped server-side; the allowlist never widens from POST.
	 *
	 * Ownership/capability: the production path runs inside UM's own profile-save
	 * lifecycle for the profile owner (the single registered hook), so the user being
	 * saved is the user whose form UM is processing; the ordinary member fails the
	 * professional-persona gate below and saves nothing.
	 *
	 * @param int   $user_id Profile owner whose UM form is saving.
	 * @param mixed $posted  Raw posted selected_services value (array or scalar).
	 * @return array{ok:bool, reason:string, value:array<int,int>} Safe machine-readable result; no service IDs or values in evidence.
	 */
	public function apply_selected_services_save( int $user_id, $posted ): array {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'invalid_user', 'value' => array() );
		}

		// Professional-persona gate (C1-owned mapping; ordinary members save nothing).
		if ( ! $this->is_professional_persona( $user_id ) ) {
			return array( 'ok' => false, 'reason' => 'persona_not_professional', 'value' => $this->get_selected_service_ids( $user_id ) );
		}

		// Catalog availability gate: unavailable is NOT an empty allowlist — the stored
		// value must survive with a safe reason, never be wiped.
		$registry_state = $this->catalog_state();

		if ( 'valid_current' !== $registry_state['state'] && 'valid_empty' !== $registry_state['state'] ) {
			return array(
				'ok'     => false,
				'reason' => 'catalog_' . $registry_state['state'],
				'value'  => $this->get_selected_service_ids( $user_id ),
			);
		}

		$catalog   = $this->get_service_catalog();
		$stored    = $this->get_selected_service_ids( $user_id );
		$eligible  = $this->get_allowed_service_ids( $user_id );
		$submitted = $this->normalize_service_values( $posted );

		// Stored selections outside today's allowlist are orphaned: kept in usermeta,
		// hidden from display, merged back on every save; only an explicit owner/admin
		// action with a future owner-approved deletion policy may remove them.
		$orphaned = array_values( array_diff( $stored, $eligible ) );

		$merged = array_values( array_unique( array_merge(
			array_values( array_intersect( $submitted, $eligible ) ),
			$orphaned
		) ) );

		sort( $merged );

		return array( 'ok' => true, 'reason' => 'saved', 'value' => $merged );
	}

	/**
	 * Card C5b: the SINGLE production save hook. Presence-checked (an omitted field never
	 * reaches the contract method), registered exactly once by Bootstrap — never by the
	 * constructor (S-01 stays intact).
	 *
	 * @param mixed $to_update Values UM is about to save.
	 * @param mixed $user_id  Profile owner.
	 * @return mixed
	 */
	public function hook_profile_services_save( $to_update, $user_id ) {
		if ( ! is_array( $to_update ) || ! array_key_exists( 'selected_services', $to_update ) ) {
			return $to_update; // Field omitted: previous value stays untouched.
		}

		$result = $this->apply_selected_services_save( absint( $user_id ), $to_update['selected_services'] );

		if ( $result['ok'] ) {
			$to_update['selected_services'] = $result['value'];
		} else {
			// Rejected input or unavailable catalog: keep the stored value, never wipe.
			$to_update['selected_services'] = $result['value'];
		}

		return $to_update;
	}

	/**
	 * Card C5b: one-time production registration of the single save hook. Called from
	 * Bootstrap's wiring phase only; double registration is structurally impossible via
	 * the static guard.
	 *
	 * @return void
	 */
	public static function register_selected_services_save_hook(): void {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		add_filter( 'um_user_pre_updating_profile_array', array( self::class, 'route_profile_services_save' ), 10, 2 );
		$registered = true;
	}

	/**
	 * Static router for the registered hook; resolves the shared Amelia instance through
	 * Bootstrap so the hook uses the same catalog/settings wiring as the rest of the
	 * plugin. Falls through untouched when Bootstrap is unavailable (fail-safe no-op).
	 *
	 * @param mixed $to_update Values UM is about to save.
	 * @param mixed $user_id   Profile owner.
	 * @return mixed
	 */
	public static function route_profile_services_save( $to_update, $user_id ) {
		$bootstrap = \HAL\MemberProfiles\Bootstrap::instance();

		if ( null === $bootstrap || null === $bootstrap->get_amelia() ) {
			return $to_update;
		}

		return $bootstrap->get_amelia()->hook_profile_services_save( $to_update, $user_id );
	}

	/**
	 * Card C5b: whether this user resolves to one of the two professional personas via
	 * the C1-owned Settings mapping (role + account status). Unresolvable mapping is
	 * fail-closed: not a professional.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function is_professional_persona( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$mapping = $this->settings->get_um_member_mapping();

		if ( 'available' !== ( $mapping['availability'] ?? '' ) || ! is_array( $mapping['personas'] ?? null ) ) {
			return false;
		}

		$status = $this->um_account_status( $user_id );

		if ( null === $status ) {
			return false;
		}

		foreach ( $mapping['personas'] as $persona => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( \HAL\MemberProfiles\Settings::PERSONA_LAWYER === $persona || \HAL\MemberProfiles\Settings::PERSONA_CONSULTANT === $persona ) {
				if ( in_array( (string) ( $row['role'] ?? '' ), $user->roles, true ) && $status === (string) ( $row['status'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Card C5b: raw account status through UM's own common()->users()->get_status(),
	 * fail-closed to null when UM is unavailable (never a guess).
	 *
	 * @param int $user_id User ID.
	 * @return string|null
	 */
	private function um_account_status( int $user_id ): ?string {
		if ( ! function_exists( 'UM' ) ) {
			return null;
		}

		try {
			$um     = UM();
			$common = is_object( $um ) && method_exists( $um, 'common' ) ? $um->common() : null;
			$users  = is_object( $common ) && method_exists( $common, 'users' ) ? $common->users() : null;

			if ( ! is_object( $users ) || ! method_exists( $users, 'get_status' ) ) {
				return null;
			}

			$status = $users->get_status( $user_id );

			return is_scalar( $status ) && '' !== (string) $status ? (string) $status : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Card C5b: the C5 taxonomy state, resolved through SchemaRegistry without this class
	 * duplicating snapshot reading.
	 *
	 * @return array{state:string, reason:string, services:array<int,array<string,mixed>>}
	 */
	private function catalog_state(): array {
		if ( ! $this->ensure_registry_loaded() ) {
			return array( 'state' => 'unavailable', 'reason' => 'registry_missing', 'services' => array() );
		}

		try {
			return ( new SchemaRegistry() )->amelia_catalog_state();
		} catch ( \Throwable $e ) {
			return array( 'state' => 'unavailable', 'reason' => 'registry_error', 'services' => array() );
		}
	}

	/**
	 * UM's own choices callback for the selected_services field: only this profile's
	 * allowed, cataloged services. Wiring this method as the field's actual options source
	 * is a UM Form admin configuration step, done during setup/QA on staging, not a PHP task.
	 *
	 * @return array<string,string>
	 */
	public function services_choices_callback(): array {
		$user_id = function_exists( 'um_profile_id' ) ? absint( um_profile_id() ) : 0;

		if ( $user_id <= 0 ) {
			return array();
		}

		$catalog = $this->get_service_catalog();
		$choices = array();

		foreach ( $this->get_allowed_service_ids( $user_id ) as $service_id ) {
			if ( isset( $catalog[ $service_id ] ) ) {
				$choices[ (string) $service_id ] = $catalog[ $service_id ];
			}
		}

		return $choices;
	}

	/**
	 * Server-side enforcement for selected_services posted by the UM Profile form.
	 * The constructor does not register this callback automatically; consumers must wire
	 * it explicitly to the documented um_user_pre_updating_profile_array hook.
	 *
	 * CONTRACT (remediation card F-16 + docs/compatibility-matrix.md §4): the value that
	 * UM finally STORES must be an EMPTY array in exactly these three cases, because the
	 * key is written back with [] through this documented save hook — dropping the key
	 * with unset() would leave any previously stored services stale in usermeta:
	 *
	 *   1. the administrative catalog is empty;
	 *   2. the profile owner is NOT a mapped employee (empty allowlist);
	 *   3. a mapped employee has an empty allowlist (no assigned service IDs).
	 *
	 * In every case the wipe is silent by design and staging QA asserts the stored
	 * outcome ("employee not primed" case). Otherwise, only submitted IDs inside the
	 * member's allowlist survive; anything else is dropped. Fail-closed throughout: an
	 * invalid/unmapped employee or an empty catalog can never widen what is saved.
	 *
	 * @param mixed $to_update Values UM is about to save.
	 * @param mixed $user_id   Profile owner.
	 * @param mixed $form_data Raw submitted form data.
	 * @return mixed
	 */
	public function filter_profile_services_before_save( $to_update, $user_id, $form_data ) {
		unset( $form_data );

		if ( ! is_array( $to_update ) || ! array_key_exists( 'selected_services', $to_update ) ) {
			return $to_update;
		}

		$user_id = absint( $user_id );
		$catalog = $this->get_service_catalog();

		if ( empty( $catalog ) ) {
			// Contract case 1: store an explicit empty array so stale usermeta is wiped.
			$to_update['selected_services'] = array();

			return $to_update;
		}

		if ( null === $this->get_employee_id( $user_id ) ) {
			// Contract case 2: not a mapped employee => nothing may be stored.
			$to_update['selected_services'] = array();

			return $to_update;
		}

		if ( empty( $this->get_employee_service_ids( $user_id ) ) ) {
			// Contract case 3: mapped employee with an empty allowlist => wiped to [].
			$to_update['selected_services'] = array();

			return $to_update;
		}

		$submitted = $this->normalize_service_values( $to_update['selected_services'] );
		$allowed   = $this->get_allowed_service_ids( $user_id );

		$to_update['selected_services'] = array_values( array_intersect( $submitted, $allowed ) );

		return $to_update;
	}

	/**
	 * Live catalog rows from SchemaRegistry's stored administrative snapshot (card D-09),
	 * shaped id => title for the shared cleaner above. Read-only consumption: this class
	 * NEVER refreshes the snapshot — that verb belongs to authorized admin flows. Any
	 * absence/corruption yields an empty catalog, which by the F-16 contract wipes
	 * selected_services on save (fail-closed), never widens it.
	 *
	 * @return array<int,string>
	 */
	private function snapshot_catalog(): array {
		if ( ! $this->ensure_registry_loaded() ) {
			return array();
		}

		try {
			// Card C5: consume the five-state taxonomy instead of a bare ok/not-ok.
			$state = ( new SchemaRegistry() )->amelia_catalog_state();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $state['services'] ?? null ) ) {
			return array();
		}

		// valid_current/valid_empty serve the stored catalog; stale_previous_valid keeps
		// serving the LAST valid snapshot (a later failure never erases it — C5 invariant);
		// unavailable is NOT a valid empty list, so its services array arrives empty and
		// nothing surfaces; not_configured was already resolved by the taxonomy itself.
		$pairs = array();

		foreach ( $state['services'] as $service ) {
			$id = isset( $service['id'] ) ? absint( $service['id'] ) : 0;

			if ( $id <= 0 || ! isset( $service['title'] ) || ! is_scalar( $service['title'] ) ) {
				continue;
			}

			$title = trim( (string) $service['title'] );

			if ( '' === $title ) {
				continue;
			}

			$pairs[ $id ] = $title;
		}

		return $pairs;
	}

	/**
	 * Guarantees the registry chain exists before instantiation (no autoloader ships).
	 *
	 * @return bool
	 */
	private function ensure_registry_loaded(): bool {
		if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			return false;
		}

		if ( ! class_exists( \HAL\MemberProfiles\FieldSchema::class ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/FieldSchema.php';
		}

		if ( ! class_exists( SchemaRegistry::class ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/SchemaRegistry.php';
		}

		return class_exists( SchemaRegistry::class );
	}

	/**
	 * The administrator-configured general booking URL from Settings, or null when unset.
	 * Amelia's own booking form remains the final authority on availability and acceptance;
	 * this is only a link, never a preselection or availability check.
	 *
	 * @return string|null
	 */
	public function get_general_booking_url(): ?string {
		return $this->settings->get_amelia_booking_url();
	}

	/**
	 * Maps legacy (pre-ID) service name strings to their current service ID, from each
	 * catalog entry's own 'legacy_names' list. A name matching more than one service ID
	 * inconsistently is dropped entirely rather than guessed.
	 *
	 * @return array<string,int>
	 */
	private function get_legacy_service_map(): array {
		$legacy = array();

		foreach ( self::SERVICE_CATALOG as $service_id => $service ) {
			if ( ! is_array( $service ) || empty( $service['legacy_names'] ) || ! is_array( $service['legacy_names'] ) ) {
				continue;
			}

			foreach ( $service['legacy_names'] as $legacy_name ) {
				if ( ! is_scalar( $legacy_name ) ) {
					continue;
				}

				$legacy_name = trim( (string) $legacy_name );

				if ( '' === $legacy_name ) {
					continue;
				}

				if ( isset( $legacy[ $legacy_name ] ) && $legacy[ $legacy_name ] !== absint( $service_id ) ) {
					$legacy[ $legacy_name ] = 0;
				} else {
					$legacy[ $legacy_name ] = absint( $service_id );
				}
			}
		}

		return array_filter( $legacy );
	}

	/**
	 * Normalizes stored service values to positive integer IDs: numeric values are accepted
	 * as-is; non-numeric legacy name strings are resolved only through the migrated
	 * get_legacy_service_map(), never guessed.
	 *
	 * @param mixed $values Raw meta value.
	 * @return int[]
	 */
	private function normalize_service_values( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$legacy = $this->get_legacy_service_map();
		$ids    = array();

		foreach ( $values as $value ) {
			// Card C5b: only strict integer strings enter the ID set — a forged
			// fractional value like "11.5" drops out entirely instead of being
			// floor-rounded into a real service ID by absint().
			if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', trim( $value ) ) ) ) {
				$int = absint( $value );

				if ( $int > 0 ) {
					$ids[] = $int;
				}

				continue;
			}

			if ( is_scalar( $value ) ) {
				$value = trim( (string) $value );

				if ( isset( $legacy[ $value ] ) ) {
					$ids[] = $legacy[ $value ];
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * @param mixed $values Raw meta value.
	 * @return int[]
	 */
	private function normalize_positive_ids( $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$values = array_map( 'absint', $values );

		return array_values( array_unique( array_filter( $values ) ) );
	}
}
