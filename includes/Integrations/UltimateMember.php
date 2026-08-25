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
	 * F-12 (fail-closed): Ultimate Member exposes NO official public API that renders the
	 * Account tab navigation alone — the side-navigation markup lives inside UM's own
	 * account.php template, not in any documented method. Per remediation card F-12, when
	 * no official API exists for segmenting tabs/body, this bridge must NOT guess or
	 * re-implement UM's internal HTML; the FULL native Account rendering is used instead
	 * (render_account_native_full()), and this legacy entry point therefore always yields
	 * an empty string so its caller falls back to that full pipeline.
	 *
	 * Kept only for backward compatibility with existing callers.
	 *
	 * @return string Always empty.
	 */
	public function render_account_tabs(): string {
		return '';
	}

	/**
	 * The complete native Account rendering through one of UM's own OFFICIAL channels,
	 * tried in order and failing closed to an empty string when none is verifiable:
	 *
	 * 1. um_get_template( 'account.php' )  — UM's official template loader, which renders
	 *    the full account output (side navigation, active tab body/Form, nonces,
	 *    conditional logic, mobile wrappers) exactly as UM's own page does;
	 * 2. the registered [ultimatemember_account] shortcode — UM's own documented
	 *    front-end entry point for the same complete output;
	 * 3. nothing — callers must then let UM's own template chain handle the page natively
	 *    (e.g. by not overriding it at all), never by re-implementing its HTML here.
	 *
	 * No "exact UM" claim is made beyond what these official channels themselves produce;
	 * live DOM/behavior parity is confirmed during compatibility-matrix QA on staging.
	 *
	 * @param array $args Original template args, forwarded to the official channel when it
	 *                    accepts them (the shortcode/template read UM state directly).
	 * @return string
	 */
	public function render_account_native_full( array $args = array() ): string {
		unset( $args ); // Official channels render from UM's own resolved state.

		if ( function_exists( 'um_get_template' ) ) {
			ob_start();

			try {
				um_get_template( 'account.php' );
			} catch ( \Throwable $e ) {
				ob_end_clean();
				return '';
			}

			$output = (string) ob_get_clean();

			if ( '' !== trim( $output ) ) {
				return $output;
			}
		}

		if ( function_exists( 'shortcode_exists' ) && shortcode_exists( 'ultimatemember_account' ) && function_exists( 'do_shortcode' ) ) {
			return (string) do_shortcode( '[ultimatemember_account]' );
		}

		return '';
	}

	/**
	 * The active Account tab's body/Form — validation, nonces, and conditional logic exactly
	 * as UM renders them, never rebuilt — via UM's own UM()->account()->render_account_tab()
	 * method, confirmed directly from UM's current account.php template. This method, not a
	 * hook, is how UM itself renders each tab's content.
	 *
	 * F-12: the ORIGINAL template args are forwarded to UM's own renderer unchanged (the
	 * previous implementation fabricated an empty array(), severing hook contexts).
	 *
	 * @param array $args Original template args, passed through unchanged.
	 * @return string
	 */
	public function render_account_body( array $args = array() ): string {
		if ( ! function_exists( 'UM' ) || ! method_exists( UM()->account(), 'render_account_tab' ) ) {
			return '';
		}

		$account = UM()->account();
		$id      = isset( $account->current_tab ) ? (string) $account->current_tab : '';

		if ( '' === $id || empty( $account->tabs[ $id ] ) || ! is_array( $account->tabs[ $id ] ) ) {
			return '';
		}

		$info                = $account->tabs[ $id ];
		$info['with_header'] = true;

		ob_start();
		$account->render_account_tab( $id, $info, $args );

		return (string) ob_get_clean();
	}

	/**
	 * The complete native Account pipeline. F-12: delegation goes exclusively through
	 * render_account_native_full() — UM's own official full-account channel — because no
	 * official API exists for rendering the tabs segment alone; composing it here from
	 * re-implemented HTML is exactly what this card removes.
	 *
	 * @param array $args Original template args, forwarded unchanged.
	 * @return string
	 */
	public function render_account_native_pipeline( array $args = array() ): string {
		return $this->render_account_native_full( $args );
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
