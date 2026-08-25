<?php
/**
 * Keeps the site's existing Amelia service-selection policy — never rebuilds Amelia itself.
 *
 * Migrated from the site's existing um-amelia-integration.php (employee ID, legacy
 * normalization, selected_services, and the allowlist only), reviewed against every
 * consumer first. hal_amelia_api_request() and its admin-only loopback API are
 * intentionally not carried forward into this release.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Amelia {

	const EMPLOYEE_ID_META_KEY       = 'hal_amelia_employee_id';
	const EMPLOYEE_SERVICES_META_KEY = 'hal_amelia_service_ids';

	/**
	 * Administrator-maintained, manually-managed service catalog:
	 * service_id => 'Name' or service_id => array( 'name' => 'Name', 'legacy_names' => array( ... ) ).
	 * Empty by default — a limited, deliberate allowlist, never a copy or sync of Amelia's
	 * own data.
	 *
	 * @var array
	 */
	const SERVICE_CATALOG = array();

	private Settings $settings;

	/**
	 * Test-only catalog override (mirrors CompatibilityGate's pattern). Production always
	 * passes null and uses the frozen SERVICE_CATALOG constant.
	 *
	 * @var array|null
	 */
	private ?array $catalog_override;

	public function __construct( Settings $settings, ?array $catalog_override = null ) {
		$this->settings         = $settings;
		$this->catalog_override = $catalog_override;

		add_filter( 'um_user_pre_updating_profile_array', array( $this, 'filter_profile_services_before_save' ), 20, 3 );
	}

	/**
	 * An employee's Amelia externalId, mirrored locally by an administrator — never inferred
	 * from an email address or any other guess.
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public function get_employee_id( int $user_id ): ?int {
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
	 * The local service catalog, cleaned to id => name pairs only.
	 *
	 * @return array<int,string>
	 */
	public function get_service_catalog(): array {
		$source = null !== $this->catalog_override ? $this->catalog_override : self::SERVICE_CATALOG;

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
	 * Registered on um_user_pre_updating_profile_array in the constructor — this is the
	 * single, server-side gate for this value.
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
