<?php
/**
 * Additional WordPress stubs for development-phase unit tests (card D-17 / Integration
 * Closure). Every function is guarded so real WordPress (or wp-stubs.php) always wins.
 *
 * @package HAL\MemberProfiles\Tests
 */

namespace {

	if ( defined( 'HAL_MEMBER_PROFILES_EXTRA_STUBS' ) ) {
		return;
	}

	define( 'HAL_MEMBER_PROFILES_EXTRA_STUBS', true );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
		define( 'HAL_MEMBER_PROFILES_DIR', dirname( __DIR__, 2 ) . '/' );
	}

	if ( ! defined( 'HAL_MEMBER_PROFILES_FILE' ) ) {
		define( 'HAL_MEMBER_PROFILES_FILE', HAL_MEMBER_PROFILES_DIR . 'hal-member-profiles.php' );
	}

	$GLOBALS['wp_stubs_extra'] = array(
		'salt'         => 'unit-test-salt',
		'is_admin'     => false,
		'http_queue'   => array(),
		'http_calls'   => array(),
		'transients'   => array(),
		'fs_available' => true,
		'fs_fail_put'  => false,
		'fs_corrupt'   => false,
		'menu_pages'      => array(),
		'submenu_pages'   => array(),
		'options_pages'   => array(),
		'counters'        => array(
			'update_option'    => 0,
			'delete_option'    => 0,
			'delete_transient' => 0,
			'add_menu_page'    => 0,
			'add_submenu_page' => 0,
			'add_options_page' => 0,
		),
		'settings_errors' => array(),
	);

	function hal_wp_stub_extra( string $key, $default = null ) {
		return $GLOBALS['wp_stubs_extra'][ $key ] ?? $default;
	}

	function hal_wp_stub_extra_set( string $key, $value ): void {
		$GLOBALS['wp_stubs_extra'][ $key ] = $value;
	}

	function hal_wp_stub_counter( string $name ): int {
		return (int) ( hal_wp_stub_extra( 'counters' )[ $name ] ?? 0 );
	}

	function hal_wp_stub_queue_http( $response ): void {
		$GLOBALS['wp_stubs']['http_queue'][] = $response;
	}

	function hal_wp_stub_http_calls(): array {
		return $GLOBALS['wp_stubs']['http_calls'] ?? array();
	}

	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( $scheme = 'auth' ) {
			return hal_wp_stub_extra( 'salt' );
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return (bool) hal_wp_stub_extra( 'is_admin' );
		}
	} else {
		// Original stub may exist without admin-awareness; wrap via filter-free global.
		$GLOBALS['__orig_is_admin'] = null;
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_array( $value )
				? array_map( __NAMESPACE__ . '\\wp_unslash', $value )
				: stripslashes( (string) $value );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) {
			return json_encode( $value, $flags );
		}
	}

	if ( ! function_exists( 'wp_create_nonce' ) ) {
		function wp_create_nonce( $action ) {
			return 'nonce-' . $action;
		}
	}

	if ( ! function_exists( 'check_admin_referer' ) ) {
		function check_admin_referer( $action, $query_arg = '_wpnonce' ) {
			return true;
		}
	}

	if ( ! function_exists( 'wp_get_referer' ) ) {
		function wp_get_referer() {
			return 'https://stub.test/wp-admin/';
		}
	}

	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $key, $value, $url ) {
			return $url . ( strpos( (string) $url, '?' ) === false ? '?' : '&' ) . $key . '=' . $value;
		}
	}

	if ( ! function_exists( 'wp_safe_redirect' ) ) {
		function wp_safe_redirect( $url, $status = 302 ) {
			hal_wp_stub_extra_set( 'last_redirect', $url );

			return true;
		}
	}

	if ( ! function_exists( 'wp_get_environment_type' ) ) {
		function wp_get_environment_type() {
			return hal_wp_stub_extra( 'env_type', 'production' );
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return 'https://stub.test' . (string) $path;
		}
	}

	if ( ! function_exists( 'get_stylesheet' ) ) {
		function get_stylesheet() {
			return (string) hal_wp_stub_extra( 'stylesheet' );
		}
	}

	if ( ! function_exists( 'get_template' ) ) {
		function get_template() {
			return (string) hal_wp_stub_extra( 'template' );
		}
	}

	if ( ! function_exists( 'get_stylesheet_directory' ) ) {
		function get_stylesheet_directory() {
			return (string) hal_wp_stub_extra( 'stylesheet_dir' );
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $name, $value, $expiry = 0 ): bool {
			hal_wp_stub_extra_set(
				'transients',
				array_merge( hal_wp_stub_extra( 'transients' ), array( $name => $value ) )
			);

			return true;
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $name ) {
			$t = hal_wp_stub_extra( 'transients' );
			return array_key_exists( $name, $t ) ? $t[ $name ] : false;
		}
	}

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $name ): bool {
			hal_wp_stub_extra_set(
				'transients',
				array_filter(
					hal_wp_stub_extra( 'transients' ),
					fn( $k ) => $k !== $name,
					ARRAY_FILTER_USE_KEY
				)
			);

			return true;
		}
	}

	if ( ! function_exists( 'add_settings_error' ) ) {
		function add_settings_error( $setting, $code, $message, $type = 'error' ) {
			hal_wp_stub_extra_set(
				'settings_errors',
				array_merge(
					hal_wp_stub_extra( 'settings_errors' ),
					array( array( $code, $message, $type ) )
				)
			);
		}
	}

	if ( ! function_exists( 'add_menu_page' ) ) {
		function add_menu_page( ...$args ): bool {
			hal_wp_stub_extra_set(
				'menu_pages',
				array_merge( hal_wp_stub_extra( 'menu_pages' ), array( $args ) )
			);
			$GLOBALS['wp_stubs_extra']['counters']['add_menu_page']++;

			return true;
		}
	}

	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( ...$args ): bool {
			hal_wp_stub_extra_set(
				'submenu_pages',
				array_merge( hal_wp_stub_extra( 'submenu_pages' ), array( $args ) )
			);
			$GLOBALS['wp_stubs_extra']['counters']['add_submenu_page']++;

			return true;
		}
	}

	if ( ! function_exists( 'add_options_page' ) ) {
		function add_options_page( ...$args ): bool {
			hal_wp_stub_extra_set(
				'options_pages',
				array_merge( hal_wp_stub_extra( 'options_pages' ), array( $args ) )
			);
			$GLOBALS['wp_stubs_extra']['counters']['add_options_page']++;

			return true;
		}
	}

	if ( ! function_exists( 'register_activation_hook' ) ) {
		function register_activation_hook( ...$ignored ) {}
	}

	if ( ! function_exists( 'register_deactivation_hook' ) ) {
		function register_deactivation_hook( ...$ignored ) {}
	}

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		/**
		 * Contract-level factory: returns a REAL-behaving direct filesystem double rooted
		 * at actual paths, with injectable failure modes for unit tests.
		 */
		function WP_Filesystem(): bool {
			if ( ! hal_wp_stub_extra( 'fs_available' ) ) {
				return false;
			}

			if ( ! isset( $GLOBALS['wp_filesystem'] ) || null === $GLOBALS['wp_filesystem'] ) {
				$GLOBALS['wp_filesystem'] = new \WP_Filesystem_Direct_Stub();
			}

			return true;
		}
	}
}

namespace {

	if ( ! class_exists( '\WP_Filesystem_Direct_Stub' ) ) {
		/**
		 * Minimal Direct-filesystem test double implementing exactly the surface
		 * HAL\MemberProfiles\ManagedTemplates consumes.
		 */
		class WP_Filesystem_Direct_Stub {
			public function exists( $path ): bool {
				return file_exists( (string) $path );
			}

			public function get_contents( $path ) {
				$raw = @file_get_contents( (string) $path );

				return false === $raw ? false : $raw;
			}

			public function put_contents( $path, $contents, $mode = false ): bool {
				if ( hal_wp_stub_extra( 'fs_fail_put' ) ) {
					return false;
				}

				$payload = hal_wp_stub_extra( 'fs_corrupt' )
					? 'CORRUPTED-BY-STUB'
					: (string) $contents;

				$dir = dirname( (string) $path );

				if ( ! is_dir( $dir ) ) {
					@mkdir( $dir, 0777, true );
				}

				return false !== @file_put_contents( $path, $payload );
			}

			public function move( $from, $to, $overwrite = false ): bool {
				if ( ! $overwrite && $this->exists( (string) $to ) ) {
					return false;
				}

				return @rename( (string) $from, (string) $to );
			}

			public function delete( $path, $recursive = false, $type = 'f' ): bool {
				return is_dir( (string) $path ) ? @rmdir( (string) $path ) : @unlink( (string) $path );
			}
		}
	}
}
