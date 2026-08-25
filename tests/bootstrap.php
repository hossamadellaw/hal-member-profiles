<?php
/**
 * PHPUnit bootstrap for HAL Member Profiles.
 *
 * Unit suite: exercises this plugin's own classes directly, without WordPress, against
 * tests/Fixtures/wp-stubs.php (configurable transport stubs — assertions always verify
 * HAL's own logic, never the stubs).
 *
 * Integration/Acceptance suites: require a real, independent WordPress test environment
 * on staging or a clean local WordPress install, with the Ultimate Member, Elementor,
 * and Amelia versions recorded in docs/compatibility-matrix.md. Point WP_TESTS_DIR at
 * the WordPress PHPUnit test library before running these suites:
 * https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/
 * Without that environment they SKIP cleanly — no placeholder/incomplete markers anywhere.
 *
 * No CI is claimed or configured by this file. Wiring an actual CI workflow is a
 * separate, later decision requiring its own repository/workflow setup.
 */

define( 'HAL_MEMBER_PROFILES_TESTS_DIR', __DIR__ );
define( 'HAL_MEMBER_PROFILES_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $wp_tests_dir && file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	require_once $wp_tests_dir . '/includes/functions.php';

	/**
	 * Loads this plugin inside the real WordPress test environment, once WordPress
	 * itself, Ultimate Member, Elementor, and (when installed) Amelia are already active
	 * per docs/compatibility-matrix.md.
	 *
	 * @return void
	 */
	function hal_member_profiles_tests_load_plugin() {
		require HAL_MEMBER_PROFILES_PLUGIN_DIR . 'hal-member-profiles.php';
	}

	define( 'HAL_MEMBER_PROFILES_TESTS_WP', true );
	tests_add_filter( 'muplugins_loaded', 'hal_member_profiles_tests_load_plugin' );

	require $wp_tests_dir . '/includes/bootstrap.php';
} else {
	define( 'HAL_MEMBER_PROFILES_TESTS_WP', false );
	require_once __DIR__ . '/Fixtures/wp-stubs.php';

	fwrite( STDERR, "WP_TESTS_DIR not set or WordPress test library not found: running the Unit suite only, against tests/Fixtures/wp-stubs.php. Integration/Acceptance suites will be skipped.\n" );
}

/**
 * A minimal autoloader for HAL\MemberProfiles\* classes, mapping the namespace directly
 * onto includes/ the same way this plugin's own files require each other — not a
 * Composer/external dependency, just this project's own existing folder convention made
 * loadable by class name for tests.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'HAL\\MemberProfiles\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$path     = HAL_MEMBER_PROFILES_PLUGIN_DIR . 'includes/' . $relative . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

require_once __DIR__ . '/Fixtures/fixtures.php';
