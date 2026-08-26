<?php
/**
 * Plugin Name:       HAL Member Profiles
 * Description:       Elementor design layer for Ultimate Member public profiles and member accounts.
 * Version:           1.1.0-rc.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  ultimate-member, elementor
 * Author:            Hossam Adel Lawyer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hal-member-profiles
 * Domain Path:       /languages
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.1.0-rc.1' );
}
if ( ! defined( 'HAL_MEMBER_PROFILES_FILE' ) ) {
	define( 'HAL_MEMBER_PROFILES_FILE', __FILE__ );
}
// عنوان مستودع GitHub — قابل للتجاوز من wp-config.php عند الحاجة.
if ( ! defined( 'HAL_MEMBER_PROFILES_GITHUB_REPO' ) ) {
	define( 'HAL_MEMBER_PROFILES_GITHUB_REPO', 'https://github.com/hossamadellaw/hal-member-profiles' );
}
define( 'HAL_MEMBER_PROFILES_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAL_MEMBER_PROFILES_URL', plugin_dir_url( __FILE__ ) );

// Integration Closure #1: arm Lifecycle's activation hook during THIS include phase so
// it registers before WordPress fires the activation event (a plugins_loaded-time load is
// always too late for the very first activation). Loading is admin-request-scoped, and
// the activation callback itself only writes one option — no filesystem work happens
// during activation (card D-05 contract, closed here per the Integration Closure order).
if ( is_admin() && file_exists( __DIR__ . '/includes/Lifecycle.php' ) ) {
	require_once __DIR__ . '/includes/Lifecycle.php';
}

require_once HAL_MEMBER_PROFILES_DIR . 'includes/Updater.php';

add_action( 'plugins_loaded', __NAMESPACE__ . '\\hal_member_profiles_boot' );

/**
 * Load Bootstrap once WordPress core plugin lifecycle is ready.
 *
 * @return void
 */
function hal_member_profiles_boot() {
	require_once HAL_MEMBER_PROFILES_DIR . 'includes/Bootstrap.php';

	Bootstrap::init();
}
