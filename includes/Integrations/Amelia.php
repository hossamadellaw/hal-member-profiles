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

			$clean[ $service_id ] = trim( (string) $name );
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
	 * outcome ("employee غير مهيأ" case). Otherwise, only submitted IDs inside the
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
			$state = ( new SchemaRegistry() )->amelia_snapshot();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( empty( $state['ok'] ) || ! is_array( $state['snapshot']['services'] ?? null ) ) {
			return array();
		}

		$pairs = array();

		foreach ( $state['snapshot']['services'] as $service ) {
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
			if ( is_numeric( $value ) && absint( $value ) ) {
				$ids[] = absint( $value );
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
