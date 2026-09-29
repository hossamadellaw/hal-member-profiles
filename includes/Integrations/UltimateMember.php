<?php
/**
 * The sole renderer of Ultimate Member's own native Profile and Account output — never a
 * reimplementation of it.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\AccountContext;
use HAL\MemberProfiles\ProfileContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltimateMember {

	private ProfileContext $profile_context;
	private AccountContext $account_context;

	/**
	 * Accepted for architectural consistency with Bootstrap's shared-service wiring, and so
	 * Widgets holding this integration can reach the same Context instances via the getters
	 * below. UM's own native hooks read UM's own global state independently of these Context
	 * objects; the calling Widget remains responsible for confirming context validity via
	 * ProfileContext/AccountContext before invoking the render methods here.
	 *
	 * @param ProfileContext $profile_context Shared ProfileContext instance.
	 * @param AccountContext $account_context Shared AccountContext instance.
	 */
	public function __construct( ProfileContext $profile_context, AccountContext $account_context ) {
		$this->profile_context = $profile_context;
		$this->account_context = $account_context;
	}

	/**
	 * @return ProfileContext
	 */
	public function get_profile_context(): ProfileContext {
		return $this->profile_context;
	}

	/**
	 * @return AccountContext
	 */
	public function get_account_context(): AccountContext {
		return $this->account_context;
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_before_header( array $args = array() ): string {
		return $this->capture( 'um_profile_before_header', $args );
	}

	/**
	 * Fires UM's own um_pre_header_editprofile hook — UM's documented action point for the
	 * Edit Profile control and, on sites with those extensions active, Follow/Message controls
	 * from UM's Followers/Messaging extensions attached to the same hook.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_actions( array $args = array() ): string {
		return $this->capture( 'um_pre_header_editprofile', $args );
	}

	/**
	 * Card C4 / F-03: the UM-native profile photo, delegated. UM owns read/write/permission
	 * for the photo itself; HAL only renders. The privacy verdict and the avatar URL come
	 * exclusively from Policy's verified avatar pipeline (C1) — a private photo or an
	 * unresolvable context returns '' (safe absence), never a guessed fallback image, and
	 * never a bypass of UM's decision. No HAL write hook or parallel store exists here.
	 *
	 * @param int    $target_user_id Profile owner whose photo is being viewed.
	 * @param int    $viewer_id      Current viewer (0 = guest).
	 * @param int    $form_id        UM form ID the profile template received.
	 * @param string $persona        Resolved HAL persona.
	 * @return string Escaped <img> markup, or '' when not permitted/unavailable.
	 */
	public function render_profile_photo( int $target_user_id, int $viewer_id, int $form_id, string $persona = '' ): string {
		if ( $target_user_id <= 0 || $form_id <= 0 ) {
			return '';
		}

		$bootstrap = \HAL\MemberProfiles\Bootstrap::instance();
		$policy    = null !== $bootstrap ? $bootstrap->get_policy() : null;

		if ( null === $policy ) {
			return '';
		}

		$result = $policy->can_view_header_element( $target_user_id, $viewer_id, 'avatar', $form_id, $persona );

		if ( null === $result || \HAL\MemberProfiles\FieldSchema::TYPE_IMAGE !== $result['type'] || '' === (string) $result['value'] ) {
			return '';
		}

		return sprintf(
			'<img class="hal-member-profiles-photo" src="%s" alt="%s" />',
			esc_url( (string) $result['value'], array( 'http', 'https' ) ),
			esc_attr( __( 'Profile photo', 'hal-member-profiles' ) )
		);
	}

	/**
	 * Card C4 / F-03: the photo edit action, owned by UM. Renders only for the profile
	 * OWNER: the edit control itself is UM's own um_pre_header_editprofile output (the
	 * same hook ProfileActions captures), so the permission decision and the markup stay
	 * UM's. Visitors, other users, and unauthenticated requests get '' — and with UM
	 * absent the captured hook simply produces nothing (safe absence, no HAL substitute).
	 *
	 * @param array  $args UM profile template $args, passed through unchanged.
	 * @param bool   $is_owner Whether the current viewer is the resolved profile owner.
	 * @return string UM's own captured edit control for the owner, '' otherwise.
	 */
	public function render_profile_photo_edit_action( array $args = array(), bool $is_owner = false ): string {
		if ( ! $is_owner ) {
			return '';
		}

		return $this->capture( 'um_pre_header_editprofile', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_header_cover_area( array $args = array() ): string {
		return $this->capture( 'um_profile_header_cover_area', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_header( array $args = array() ): string {
		return $this->capture( 'um_profile_header', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_navbar( array $args = array() ): string {
		return $this->capture( 'um_profile_navbar', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_menu( array $args = array() ): string {
		return $this->capture( 'um_profile_menu', $args );
	}

	/**
	 * Fires um_profile_content_{$nav} and um_profile_content_{$nav}_{$subnav} for UM's own
	 * currently active tab and subnav, per UM's own template conditions. UM's own registered
	 * callback on um_profile_content_main applies the um_profile_can_view_main filter itself
	 * when $nav is 'main' — firing the hook is what applies that decision; it is never
	 * reimplemented here. Never guesses or overrides which nav/subnav is active.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_content( array $args = array() ): string {
		$nav = $this->active_profile_nav();

		if ( '' === $nav ) {
			return '';
		}

		$output = $this->capture( "um_profile_content_{$nav}", $args );

		$subnav  = get_query_var( 'subnav' ) ? (string) get_query_var( 'subnav' ) : 'default';
		$output .= $this->capture( "um_profile_content_{$nav}_{$subnav}", $args );

		return $output;
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_footer( array $args = array() ): string {
		return $this->capture( 'um_profile_footer', $args );
	}

	/**
	 * The complete native Profile pipeline, in UM's own documented hook order:
	 * um_profile_before_header, um_profile_header_cover_area, um_profile_header,
	 * um_profile_navbar, um_profile_menu, um_profile_content_{$nav}, um_profile_footer.
	 * This is the single fallback path used when the Elementor Library route is
	 * unavailable or its LayoutContract is invalid.
	 *
	 * F-12: the original template $args are REQUIRED here — the canonical fallback must
	 * never run hook-less on invented empty arguments.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_native_pipeline( array $args ): string {
		return
			$this->render_profile_before_header( $args ) .
			$this->render_profile_header_cover_area( $args ) .
			$this->render_profile_header( $args ) .
			$this->render_profile_navbar( $args ) .
			$this->render_profile_menu( $args ) .
			$this->render_profile_content( $args ) .
			$this->render_profile_footer( $args );
	}

	/**
	 * Legacy split renderer retained for backward compatibility. C2 assigns the complete
	 * Account surface to Ultimate Member's original public entry point, so HAL never
	 * renders a navigation segment.
	 *
	 * Kept only for backward compatibility with existing callers.
	 *
	 * @return string Always empty.
	 */
	public function render_account_tabs(): string {
		return '';
	}

	/**
	 * Legacy full renderer retained as an inert compatibility method. Calling UM's raw
	 * template loader or Account shortcode from inside an override/Widget can re-enter the
	 * same override and duplicate forms, hooks, IDs, and nonces. The original UM entry point
	 * is now the only renderer.
	 *
	 * @param array $args Legacy arguments retained for signature compatibility; unused.
	 * @return string
	 */
	public function render_account_native_full( array $args = array() ): string {
		unset( $args );
		return '';
	}

	/**
	 * Legacy split body renderer retained as an inert compatibility method. A body without
	 * UM's navigation and enclosing form is not a valid Account surface.
	 *
	 * @param array $args Original template args, passed through unchanged.
	 * @return string
	 */
	public function render_account_body( array $args = array() ): string {
		unset( $args );
		return '';
	}

	/**
	 * Legacy pipeline retained as an inert compatibility method. Native Account rendering
	 * happens only through UM's original public page/shortcode lifecycle, outside HAL.
	 *
	 * @param array $args Original template args, forwarded unchanged.
	 * @return string
	 */
	public function render_account_native_pipeline( array $args = array() ): string {
		unset( $args );
		return '';
	}

	/**
	 * UM's own currently active Profile nav/tab key, never guessed or overridden.
	 *
	 * @return string
	 */
	private function active_profile_nav(): string {
		if ( function_exists( 'UM' ) && method_exists( UM()->profile(), 'active_tab' ) ) {
			return (string) UM()->profile()->active_tab();
		}

		return '';
	}

	/**
	 * Fires a UM hook inside an output buffer and returns what it produced.
	 *
	 * @param string $hook UM hook name.
	 * @param array  $args Optional args passed through to the hook unchanged.
	 * @return string
	 */
	private function capture( string $hook, array $args = array() ): string {
		ob_start();
		do_action( $hook, $args );

		return (string) ob_get_clean();
	}
}
