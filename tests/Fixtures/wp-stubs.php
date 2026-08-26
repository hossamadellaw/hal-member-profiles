<?php
/**
 * Configurable WordPress function stubs for the UNIT suite only.
 *
 * Loaded by tests/bootstrap.php when WP_TESTS_DIR is absent. These are transport
 * fakes: they let this plugin's own classes execute so the ASSERTIONS verify HAL's
 * logic. They never assert anything themselves, and Integration/Acceptance tests never
 * run against them — those require the real WordPress test environment (WP_TESTS_DIR).
 *
 * Behavior is driven per-test through $GLOBALS['wp_stubs'] maps, so each TestCase
 * controls its own world in setUp() and cleans it in tearDown().
 *
 * @package HAL\MemberProfiles\Tests
 */

namespace {

	if ( defined( 'ABSPATH' ) ) {
		return; // Real WordPress already loaded — never shadow it.
	}

	define( 'ABSPATH', __DIR__ . '/../' );

	$GLOBALS['wp_stubs'] = array(
		'options'      => array(),
		'user_meta'    => array(),
		'post_meta'    => array(),
		'users'        => array(),
		'current_user' => 0,
		'can_manage'   => false,
		'is_page'      => array(),
		'profile_id'   => 0,
		'private_map'  => null,
		'um_options'   => array(),
		'can_edit_map' => array(),
	);

	function hal_wp_stub( string $key, $default = null ) {
		return $GLOBALS['wp_stubs'][ $key ] ?? $default;
	}

	function add_action( ...$ignored ) {}
	function add_filter( ...$ignored ) {}
	function apply_filters( $tag, $value ) { return $value; }
	function do_action( ...$ignored ) {}
	function __( $text, $domain = null ) { return $text; }
	function esc_html__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	function esc_html_e( $t, $d = null ) { echo htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_attr_e( $t, $d = null ) { echo htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_url( $u, $p = array() ) {
		return ( is_string( $u ) && preg_match( '#^https?://#i', $u ) ) ? $u : '';
	}
	function esc_url_raw( $u, $p = array() ) { return esc_url( $u, $p ); }
	function current_user_can( $cap ) {
		return 'manage_options' === $cap
			? (bool) hal_wp_stub( 'can_manage', false )
			: false;
	}
	function wp_is_mobile() { return false; }
	function is_rtl() { return false; }
	function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
	function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
	function absint( $v ) { return abs( (int) $v ); }
	function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
	function wp_upload_dir() {
		return array( 'baseurl' => 'https://stub.test/wp-content/uploads' );
	}
	function get_avatar_url( $id ) { return 'https://stub.test/avatar/' . (int) $id . '.png'; }
	function setup_postdata( $p ) {}
	function wp_verify_nonce( $n, $a ) {
		// Smart-enough stub: the canonical test nonce for any action verifies, everything
		// else fails — matching how production treats forged values.
		return ( 'nonce-' . $a === $n ) ? 1 : false;
	}
	function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }

	function get_option( $name, $default = false ) {
		$options = hal_wp_stub( 'options', array() );
		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}
	function update_option( $name, $value ) {
		$GLOBALS['wp_stubs']['options'][ $name ] = $value;
		return true;
	}
	function get_user_meta( $uid, $key, $single = true ) {
		$meta = hal_wp_stub( 'user_meta', array() );
		return $meta[ $uid ][ $key ] ?? '';
	}
	function get_post_meta( $pid, $key, $single = true ) {
		$meta = hal_wp_stub( 'post_meta', array() );
		return $meta[ $pid ][ $key ] ?? '';
	}
	function get_userdata( $id ) {
		$users = hal_wp_stub( 'users', array() );
		if ( ! isset( $users[ $id ] ) ) {
			return null;
		}
		$u        = new \WP_User();
		$u->ID    = $id;
		$u->roles = $users[ $id ]['roles'] ?? array();
		$u->display_name = $users[ $id ]['display_name'] ?? '';
		return $u;
	}
	function get_current_user_id() { return (int) hal_wp_stub( 'current_user', 0 ); }
	function is_user_logged_in() { return get_current_user_id() > 0; }

	class WP_Post {
		public $ID          = 0;
		public $post_type   = '';
		public $post_status = '';
	}
	class WP_User {
		public $ID           = 0;
		public $roles        = array();
		public $display_name = '';
	}

	function um_is_core_page( $key ) {
		$pages = hal_wp_stub( 'is_page', array() );
		return array_key_exists( $key, $pages ) ? (bool) $pages[ $key ] : false;
	}
	function um_profile_id() { return (int) hal_wp_stub( 'profile_id', 0 ); }
	function um_user_profile_url() { return 'https://stub.test/profile/'; }
	function um_is_on_edit_profile() { return false; }
	function get_posts( $args ) { return array(); }
	function shortcode_exists( $tag ) { return false; }
	function do_shortcode( $s ) { return ''; }
	function um_get_cover_uri( $raw, $args ) { return ''; }

	function UM() {
		return new class {
			public function user() {
				if ( '__UNAVAILABLE__' === hal_wp_stub( 'private_map', null ) ) {
					return new \stdClass(); // UM build whose user object lacks the method.
				}
				return new class {
					public function is_private_profile( $id ) {
						$map = hal_wp_stub( 'private_map', null );
						return null === $map ? false : (bool) ( $map[ $id ] ?? false );
					}
					public $preview = false;
				};
			}
			public function profile() {
				return new class { public function active_tab() { return 'main'; } };
			}
			public function account() {
				return new class { public $current_tab = 'general'; public $tabs = array(); };
			}
			public function options() {
				return new class {
					public function get( $k ) {
						$map = hal_wp_stub( 'um_options', array() );
						return $map[ $k ] ?? '';
					}
				};
			}
			public function roles() {
				return new class {
					public function um_current_user_can( $perm, $id ) {
						$map = hal_wp_stub( 'can_edit_map', array() );
						return (bool) ( $map[ $id ] ?? false );
					}
				};
			}
		};
	}
}

namespace Elementor {
	class Widget_Base {}
	class Plugin {
		public static $instance = null;
		public $editor;
		public $preview;
		public static function instance() { return self::$instance; }
	}
}
