<?php
/**
 * Backward-compatible bridge that delegates Account rendering to Ultimate Member.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountLayoutAdapter {

	/**
	 * Kept for legacy callers only. C2 makes Account an atomic UM-owned surface, so this
	 * adapter never enters Elementor and invokes the original renderer exactly once with
	 * the original arguments.
	 *
	 * @param callable $native_pipeline_callback Renders (echoes) the complete native UM
	 *                                            account pipeline when called with $args.
	 * @param array    $args Original args the UM account template received.
	 * @return void
	 */
	public static function render_or_fallback( callable $native_pipeline_callback, array $args = array() ): void {
		try {
			call_user_func( $native_pipeline_callback, $args );
		} catch ( \Throwable $e ) {
			// A legacy bridge may otherwise catch and invoke the native callback a second
			// time. Contain the failure here so the UM-owned renderer is attempted once.
			return;
		}
	}
}
