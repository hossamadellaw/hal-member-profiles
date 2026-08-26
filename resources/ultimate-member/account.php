<?php
/**
 * HAL Member Profiles — canonical managed Account template source (thin bridge).
 *
 * This file is HAL's own source of record for the managed Ultimate Member Account
 * template override. It lives inside the plugin so it ships in every release; the copy
 * that Ultimate Member actually loads is provisioned into the active Child Theme from
 * this source by HAL's managed-template system.
 *
 * Unlike a Profile Form template, a theme-level account.php override activates purely by
 * existing, so its native path is built to stay behaviorally invisible: whenever HAL is
 * inactive, in observe mode, or whenever the adapter/gate/template decline the Elementor
 * route, it delegates to Ultimate Member's OWN original account.php — loaded straight
 * from the plugin directory, deliberately bypassing the theme override lookup so the
 * delegation can never recurse back into this file. Every original arg travels with the
 * hand-off. If Ultimate Member's original template cannot be located/read, nothing is
 * echoed: per the governing card, an unverifiable Account public layout is simply not
 * published, and UM's page falls back to its own un-overridden rendering chain.
 *
 * No CSS, no Hero markup, no queries, no privacy logic, and no Amelia logic live here.
 *
 * @var array $args Ultimate Member's own template args (forwarded unchanged).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates to Ultimate Member's ORIGINAL account.php, read directly from the plugin
 * directory. This bypasses the theme override chain on purpose: locating the template
 * "normally" from inside this very override would recurse forever. Reading the plugin's
 * own file verbatim is the exact opposite of re-implementing it.
 *
 * @return void
 */
$hal_delegate_to_original_um_template = function ( array $args = array() ): void {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return;
	}

	$original = rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/ultimate-member/templates/account.php';

	if ( is_readable( $original ) ) {
		// require() inherits THIS function's locals, so Ultimate Member's own template
		// sees the same $args its normal inclusion site would provide.
		require $original;
	}
};

// HAL inactive → behave exactly as if this override did not exist.
if ( ! class_exists( '\HAL\MemberProfiles\Integrations\AccountLayoutAdapter' ) ) {
	$hal_delegate_to_original_um_template( $args );
	return;
}

// HAL active: one narrow attachment point. The adapter decides Elementor-vs-native
// (mode gate, compatibility gate, context, contract) and calls the delegation callback
// at most once, forwarding these original args with it.
try {
	\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
		static function ( array $args ) use ( $hal_delegate_to_original_um_template ): void {
			$hal_delegate_to_original_um_template( $args );
		},
		$args
	);
} catch ( \Throwable $e ) {
	// Any unexpected failure in HAL's own code can never take the member's account
	// page down: fall back to UM's original template, once, with no partial output.
	$hal_delegate_to_original_um_template( $args );
}
