<?php
/**
 * Optional enhancements that never block Profile or Account rendering.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\Dependencies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OptionalIntegrations {

	private Dependencies $dependencies;

	/**
	 * Once-guard for the card C8 external Dashboard link-contract registration: a static
	 * class property (not a function local) so the guard survives Bootstrap
	 * re-instantiation and stays resettable by the unit fixture, exactly like
	 * AdminDashboard::$registered.
	 *
	 * @var bool
	 */
	private static bool $dashboard_link_consumer_registered = false;

	public function __construct( Dependencies $dependencies ) {
		$this->dependencies = $dependencies;
	}

	/**
	 * Product IDs owned by a profile, under a single, explicit, filterable ownership
	 * contract. WordPress's own post_author is only the default fallback here, never an
	 * assumed universal rule — sites using a different ownership model (e.g. a vendor
	 * meta key) must supply the hal_member_profiles_owned_product_ids filter instead.
	 * Returns an empty array whenever WooCommerce is absent.
	 *
	 * @param int $target_user_id Profile owner.
	 * @param int $limit          Maximum number of product IDs to return.
	 * @return int[]
	 */
	public function get_owned_product_ids( int $target_user_id, int $limit = 12 ): array {
		if ( $target_user_id <= 0 || ! $this->dependencies->has_woocommerce() ) {
			return array();
		}

		/**
		 * Filters the WooCommerce product-ownership contract this bridge uses.
		 *
		 * Return an array of product post IDs owned by $target_user_id to fully replace
		 * the default post_author lookup below. Leave the passed value (null) untouched
		 * to keep the default.
		 *
		 * @param int[]|null $product_ids    Pre-resolved IDs, or null to use the default.
		 * @param int        $target_user_id Profile owner.
		 * @param int        $limit          Maximum number of product IDs requested.
		 */
		$product_ids = apply_filters( 'hal_member_profiles_owned_product_ids', null, $target_user_id, $limit );

		if ( is_array( $product_ids ) ) {
			return array_map( 'absint', $product_ids );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'author'         => $target_user_id,
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Translates an object ID via WPML's own public wpml_object_id filter, exactly once.
	 * Returns the original ID unchanged whenever WPML is absent, so this never needs to be
	 * (and must never be) applied a second time by any other file in this plugin.
	 *
	 * @param int    $object_id Original object ID.
	 * @param string $type      WPML element type, e.g. 'post_product', 'post_page'.
	 * @return int
	 */
	public function translate_object_id( int $object_id, string $type ): int {
		if ( $object_id <= 0 || ! $this->dependencies->has_wpml() ) {
			return $object_id;
		}

		$translated_id = apply_filters( 'wpml_object_id', $object_id, $type, true );

		return is_numeric( $translated_id ) ? (int) $translated_id : $object_id;
	}

	/**
	 * Reads one specific, named ACF field — never a generic raw-meta fallback. No field
	 * name is currently required anywhere in this plugin, so this intentionally returns
	 * null until a specific, documented field name is actually needed by a future card.
	 *
	 * @param int    $target_user_id Owner the field belongs to.
	 * @param string $field_name     A specific, documented ACF field name.
	 * @return mixed|null
	 */
	public function get_named_acf_field( int $target_user_id, string $field_name ) {
		unset( $target_user_id, $field_name );

		return null;
	}

	/**
	 * Card C8 — the owned, machine-consumable Profile Overview link contract: the ONE
	 * canonical permalink the external HAL Frontend Dashboard may consume as a single link
	 * through its published contract filter (hal_frontend_dashboard_profile_links). The
	 * link is Ultimate Member's own profile URL for the viewer — the exact route this
	 * plugin's C3 Profile Overview renders inside for the owner — so no second
	 * rewrite/permalink exists and none is invented here; ProfileOverview itself publishes
	 * no route of its own.
	 *
	 * Every gate below fails closed to null, and null only HIDES the link: a visitor, an
	 * unauthorized/unmapped viewer, or an unresolvable route never breaks HAL or UM
	 * rendering, and this provider never renders anything itself — no form, no navigation,
	 * no duplicate link markup. Rendering decisions stay with the consumer, which must
	 * esc_url() the returned data before output. When every gate passes, the EXACT
	 * original um_user_profile_url() value is returned — the same string that was
	 * validated, byte for byte, with no trim or other normalization.
	 *
	 * Context-derived availability with zero global or stored activation state (nothing
	 * cached, no filter armed here, nothing to clean up): the invocation of the Dashboard's
	 * own contract filter IS itself the proof that the consumption point exists and is live
	 * at that moment.
	 *
	 *   - INSIDE that invocation (this provider is called while
	 *     hal_frontend_dashboard_profile_links is the innermost filter tag), presence and
	 *     readiness are proven by the invocation context alone.
	 *   - ONLY a direct call OUTSIDE that context falls through to the two owned,
	 *     exact-boolean filters below, which default to false (absent) and may only be
	 *     switched on by a proven integration bridge — never by guessing at the external
	 *     project:
	 *
	 *   - hal_member_profiles_frontend_dashboard_detected: the external Dashboard is present.
	 *   - hal_member_profiles_frontend_dashboard_ready:    its extension contract is consumable.
	 *
	 * @param int $viewer_id Current viewer — the only user the owner-only Overview (C3) can ever belong to.
	 * @return string|null Canonical Profile Overview URL, or null to hide the link.
	 */
	public function get_profile_overview_link( int $viewer_id ): ?string {
		if ( ! $this->is_frontend_dashboard_link_available() ) {
			return null;
		}

		if ( $viewer_id <= 0 || ! $this->dependencies->has_um() ) {
			return null;
		}

		$owner = get_userdata( $viewer_id );

		if ( ! $owner instanceof \WP_User ) {
			return null;
		}

		if ( null === $this->resolve_owner_persona( $owner ) ) {
			return null;
		}

		if ( ! function_exists( 'um_user_profile_url' ) ) {
			return null;
		}

		$url = (string) um_user_profile_url( $viewer_id );

		return $this->is_valid_profile_url( $url ) ? $url : null;
	}

	/**
	 * Card C8 — registers this plugin's ONE consumer callback on the external HAL Frontend
	 * Dashboard's public link-contract filter (hal_frontend_dashboard_profile_links),
	 * exactly once, with accepted_args = 2 so the callback receives the incoming list AND
	 * the viewer identity the Dashboard passes. Nothing else about the Dashboard is known
	 * or touched here: no Dashboard file is included, no renderer is called, and the
	 * registration does not depend on the Dashboard's install path — the callback itself
	 * fails closed and returns the incoming list unchanged whenever anything the contract
	 * needs is missing (see provide_profile_overview_link()). Mirrors the Amelia
	 * save-hook precedent: registered from the boot wiring, never from a constructor,
	 * guarded against double registration here.
	 *
	 * @return void
	 */
	public static function register_dashboard_link_consumer(): void {
		if ( self::$dashboard_link_consumer_registered ) {
			return;
		}

		add_filter( 'hal_frontend_dashboard_profile_links', array( self::class, 'provide_profile_overview_link' ), 10, 2 );

		self::$dashboard_link_consumer_registered = true;
	}

	/**
	 * Card C8 — the Dashboard link-contract callback: receives the Dashboard's incoming
	 * link list plus the viewer identity it is rendering for, and appends exactly ONE
	 * data element for the C3 Profile Overview route when every gate passes. The gates
	 * run in a fixed, fail-closed order, and every miss returns the incoming list
	 * UNCHANGED — never null, never a rebuilt list that drops foreign items:
	 *
	 *   1. A non-array incoming list is not this plugin's contract to fix: pass through.
	 *   2. An item already carrying id 'member-profiles.overview' (array or object
	 *      shape): never duplicate — this is what makes a double fire a no-op.
	 *   3. Identity: the server-trusted get_current_user_id() must be positive AND equal
	 *      the viewer identity passed by the Dashboard — a visitor, or any identity that
	 *      does not match the one the Dashboard is rendering for, never receives the
	 *      owner-only link.
	 *   4. The loading contract must be up: Bootstrap::instance() and its
	 *      OptionalIntegrations slot both resolve, or there is no link to give.
	 *   5. The provider's own verdict: get_profile_overview_link() re-evaluates identity,
	 *      persona, and URL validity on EVERY call inside THIS invocation — and its
	 *      Dashboard presence/readiness gate is proven by THIS invocation's own context:
	 *      while this callback runs, the Dashboard's contract tag
	 *      (hal_frontend_dashboard_profile_links) is the innermost filter tag on the call
	 *      stack, which is exactly the proof that the consumption point exists and is live.
	 *      No verdict is cached anywhere between calls. Null only hides the link.
	 *   6. One element, as pure data, carrying EXACTLY the published contract keys
	 *      {id, label, url, eligible}: a translated label (the Dashboard's renderer
	 *      requires a valid string label on every link item and rejects items without
	 *      one), the provider's raw URL byte-identical to what UM's accessor produced, and
	 *      eligible as a real boolean true. No 'order' key (the documented default
	 *      applies), no extra fields, no HTML, no esc_url() — the Dashboard owns rendering
	 *      and escaping.
	 *
	 * @param mixed $links     Incoming link list from the Dashboard contract.
	 * @param mixed $viewer_id Viewer identity passed by the Dashboard contract.
	 * @return mixed The incoming list, with at most one appended element.
	 */
	public static function provide_profile_overview_link( $links, $viewer_id ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		foreach ( $links as $existing ) {
			$existing_id = null;

			if ( is_array( $existing ) ) {
				$existing_id = $existing['id'] ?? null;
			} elseif ( is_object( $existing ) && isset( $existing->id ) ) {
				$existing_id = $existing->id;
			}

			if ( 'member-profiles.overview' === $existing_id ) {
				return $links;
			}
		}

		$current_user_id = get_current_user_id();

		if ( $current_user_id <= 0 || $current_user_id !== (int) $viewer_id ) {
			return $links;
		}

		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap || null === $bootstrap->get_optional_integrations() ) {
			return $links;
		}

		$link = $bootstrap->get_optional_integrations()->get_profile_overview_link( (int) $viewer_id );

		if ( null === $link ) {
			return $links;
		}

		$links[] = array(
			'id'       => 'member-profiles.overview',
			'label'    => __( 'Profile overview', 'hal-member-profiles' ),
			'url'      => $link,
			'eligible' => true,
		);

		return $links;
	}

	/**
	 * Whether the external Dashboard link may be published at all.
	 *
	 * Context-derived availability: when this provider runs INSIDE the Dashboard's own
	 * contract-filter invocation (hal_frontend_dashboard_profile_links), the invocation
	 * itself proves presence and readiness — the consumption point is live at that very
	 * moment — so the gate passes on the call context alone. That context check runs
	 * FIRST, before this method applies any filter, because during any nested
	 * apply_filters() the current hook is the innermost tag: the check at the top sees the
	 * Dashboard's tag correctly, and the owned gate filters below are never consulted
	 * inside the contract context.
	 *
	 * Only a direct call OUTSIDE that context falls through to the two owned gate
	 * filters. Both are exact-boolean — any other value (1, 'yes', 'true') is an
	 * untrusted signal and fails closed to false, which only hides the link. A direct
	 * call outside the context with no other true signal stays closed.
	 *
	 * Zero global or stored activation state: nothing is cached, no filter is armed here,
	 * and nothing needs cleaning up afterwards.
	 *
	 * @return bool
	 */
	private function is_frontend_dashboard_link_available(): bool {
		// The contract-filter invocation IS the proof that the consumption point exists
		// and is live at this moment. Checked before any apply_filters() below: during a
		// nested apply_filters() the current hook is the innermost tag.
		if ( 'hal_frontend_dashboard_profile_links' === current_filter() ) {
			return true;
		}

		$detected = apply_filters( 'hal_member_profiles_frontend_dashboard_detected', false );

		if ( true !== $detected ) {
			return false; // Absent (or untrusted signal): hide the link only.
		}

		$ready = apply_filters( 'hal_member_profiles_frontend_dashboard_ready', false );

		return true === $ready; // Present but not ready: hide the link only.
	}

	/**
	 * Re-derives the owner's persona verdict from the same owned Settings catalog
	 * ProfileContext classifies every render with (UM role + UM account status, exactly
	 * one match). ProfileContext remains the single render-time classifier; this probe
	 * only validates that a C3 Overview would actually be available for this owner before
	 * any link is published. Null = unmapped/unavailable — the link hides.
	 *
	 * @param \WP_User $owner Resolved profile owner.
	 * @return string|null
	 */
	private function resolve_owner_persona( \WP_User $owner ): ?string {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return null;
		}

		$mapping = $bootstrap->get_settings()->get_um_member_mapping();

		if ( 'available' !== ( $mapping['availability'] ?? '' ) || ! is_array( $mapping['personas'] ?? null ) ) {
			return null;
		}

		$status = $this->um_account_status( (int) $owner->ID );

		if ( null === $status ) {
			return null;
		}

		$matches = array();

		foreach ( $mapping['personas'] as $persona => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( in_array( (string) ( $row['role'] ?? '' ), $owner->roles, true ) && $status === (string) ( $row['status'] ?? '' ) ) {
				$matches[] = (string) $persona;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * UM's own account-status verdict for one user, guarded exactly like ProfileContext's
	 * probe: a missing or throwing UM API is unavailable and fails closed.
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
	 * Whether a resolved profile URL is a publishable, absolute http(s) permalink. The
	 * original value is checked WITHOUT any normalization: the raw string is rejected
	 * when it is empty, relative, unparseable, hostless, or carries ANY whitespace or
	 * control character — leading and trailing whitespace included — before any parsing,
	 * and nothing is ever trimmed on the acceptance path. A whitespace-padded or
	 * control-character-carrying URL therefore fails closed (null upstream), never
	 * trimmed-and-accepted; when the check passes, the caller returns the exact original
	 * string, byte-identical to what the accessor produced. The raw value is handed to
	 * the Dashboard consumer verbatim, so every check here fails closed.
	 *
	 * @param string $url Raw URL from UM's own profile-URL accessor.
	 * @return bool
	 */
	private function is_valid_profile_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		// Control characters or any whitespace — leading, trailing, or embedded — make the
		// raw value unpublishable as data for another renderer: rejected BEFORE any
		// parsing, with no trim() anywhere on the acceptance path. Percent-encoded (ASCII)
		// and other printable non-ASCII characters stay valid.
		if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) || 1 === preg_match( '/\s/u', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return false; // Seriously malformed: the parse itself failed.
		}

		// Schemes are case-insensitive (RFC 3986): compare lowercased. A scheme without
		// a host is not an absolute permalink (e.g. 'http:foo').
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = $parts['host'] ?? '';

		return ( 'http' === $scheme || 'https' === $scheme ) && is_string( $host ) && '' !== $host;
	}
}
