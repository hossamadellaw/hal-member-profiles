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
		'um_roles'     => array(),
		'um_statuses'  => array(),
		'um_target'    => 0,
		'um_account_tab' => 'general',
		'um_status_by_user' => array(),
	);
	$GLOBALS['__did_actions'] = array();
	$GLOBALS['__filters']     = array();
	// WordPress-parity current-filter stack: pushed/popped by apply_filters(), read by
	// current_filter(). Always an array so every test starts from a balanced stack.
	$GLOBALS['__current_filter'] = array();

	function hal_wp_stub( string $key, $default = null ) {
		return $GLOBALS['wp_stubs'][ $key ] ?? $default;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__hooks'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
	function has_filter( $hook, $callback = false ) {
		if ( empty( $GLOBALS['__filters'][ $hook ] ) ) {
			return false;
		}
		if ( false === $callback ) {
			return true;
		}
		foreach ( $GLOBALS['__filters'][ $hook ] as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $entry['callback'] === $callback ) {
					return $priority;
				}
			}
		}
		return false;
	}
	function remove_all_filters( $hook ) {
		unset( $GLOBALS['__filters'][ $hook ] );
		return true;
	}
	function apply_filters( $tag, $value, ...$args ) {
		// Faithful WordPress parity: the tag is pushed for the WHOLE duration of
		// apply_filters() — attached callbacks see it via current_filter() — and popped
		// at the end, regardless of whether any callback is attached.
		$stack                       = $GLOBALS['__current_filter'] ?? array();
		$stack[]                     = $tag;
		$GLOBALS['__current_filter'] = $stack;

		if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
			$stack = $GLOBALS['__current_filter'];
			array_pop( $stack );
			$GLOBALS['__current_filter'] = $stack;

			return $value;
		}

		ksort( $GLOBALS['__filters'][ $tag ] );

		foreach ( $GLOBALS['__filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$accepted = max( 1, (int) $entry['accepted_args'] );
				$value    = call_user_func_array(
					$entry['callback'],
					array_slice( array_merge( array( $value ), $args ), 0, $accepted )
				);
			}
		}

		$stack = $GLOBALS['__current_filter'];
		array_pop( $stack );
		$GLOBALS['__current_filter'] = $stack;

		return $value;
	}

	if ( ! function_exists( 'current_filter' ) ) {
		/**
		 * Innermost tag of the current-filter stack (WordPress parity), or an empty
		 * string when no filter is running.
		 *
		 * @return string
		 */
		function current_filter() {
			$stack     = $GLOBALS['__current_filter'] ?? array();
			$innermost = end( $stack );

			return is_string( $innermost ) ? $innermost : '';
		}
	}
	function do_action( $hook, ...$args ) {
		$GLOBALS['__did_actions'][ $hook ] = (int) ( $GLOBALS['__did_actions'][ $hook ] ?? 0 ) + 1;

		if ( empty( $GLOBALS['__hooks'][ $hook ] ) ) {
			return;
		}

		foreach ( $GLOBALS['__hooks'][ $hook ] as $entry ) {
			if ( ! is_callable( $entry['callback'] ) ) {
				continue;
			}
			call_user_func_array(
				$entry['callback'],
				array_slice( $args, 0, max( 1, (int) $entry['accepted_args'] ) )
			);
		}
	}
	function did_action( $hook ) { return (int) ( $GLOBALS['__did_actions'][ $hook ] ?? 0 ); }
	function __( $text, $domain = null ) { return $text; }
	function esc_html__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	function esc_html_e( $t, $d = null ) { echo htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_attr_e( $t, $d = null ) { echo htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_url( $u, $p = array() ) {
		return ( is_string( $u ) && preg_match( '#^https?://#i', $u ) ) ? $u : '';
	}
	function esc_url_raw( $u, $p = array() ) { return esc_url( $u, $p ); }
	function admin_url( $path = '' ) { return 'https://stub.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) {
		echo '<button type="submit" class="button button-' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>';
	}
	function settings_fields( $group ) {}
	function settings_errors() {}
	function wp_nonce_field( $action, $name = '_wpnonce' ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'nonce-' . $action ) . '" />';
	}
	function selected( $helper, $current = true ) {
		return (string) $helper === (string) $current ? " selected='selected'" : '';
	}
	function checked( $helper, $current = true ) {
		return (string) $helper === (string) $current ? " checked='checked'" : '';
	}
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
		$GLOBALS['wp_stubs']['user_meta_reads'][ $uid ][ $key ] = (int) ( $GLOBALS['wp_stubs']['user_meta_reads'][ $uid ][ $key ] ?? 0 ) + 1;
		$meta = hal_wp_stub( 'user_meta', array() );
		return $meta[ $uid ][ $key ] ?? '';
	}
	function get_post_meta( $pid, $key, $single = true ) {
		$meta = hal_wp_stub( 'post_meta', array() );
		return $meta[ $pid ][ $key ] ?? '';
	}
	function get_post( $id ) {
		$posts = hal_wp_stub( 'posts', array() );
		return $posts[ $id ] ?? null;
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
	function um_user( $key ) { return 'ID' === $key ? (int) hal_wp_stub( 'um_target', 0 ) : ''; }
	function um_get_roles() {
		$roles = hal_wp_stub( 'um_roles', array() );
		if ( '__UNAVAILABLE__' === $roles ) {
			throw new \RuntimeException( 'UM roles unavailable' );
		}
		return $roles;
	}
	function um_can_view_profile( $user_id ) {
		$map = hal_wp_stub( 'private_map', null );
		if ( '__UNAVAILABLE__' === $map ) {
			throw new \RuntimeException( 'UM profile decision unavailable' );
		}
		if ( ! (bool) ( $map[ $user_id ] ?? false ) ) {
			return true;
		}
		return get_current_user_id() === (int) $user_id
			|| current_user_can( 'manage_options' )
			|| (bool) ( hal_wp_stub( 'can_edit_map', array() )[ $user_id ] ?? false );
	}
	function um_can_view_field( $field ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$target = (int) um_user( 'ID' );
		$viewer = get_current_user_id();
		$level  = array_key_exists( 'public', $field ) ? $field['public'] : '1';
		$owner  = $viewer > 0 && $viewer === $target;
		$edit   = (bool) ( hal_wp_stub( 'can_edit_map', array() )[ $target ] ?? false );
		$roles  = get_userdata( $viewer );
		$match  = $roles instanceof \WP_User && ! empty( $field['roles'] ) && is_array( $field['roles'] )
			? (bool) array_intersect( $roles->roles, $field['roles'] )
			: false;
		switch ( $level ) {
			case '1': $can_view = true; break;
			case '2': $can_view = $viewer > 0; break;
			case '-1': $can_view = $owner || $edit; break;
			case '-2': $can_view = $match; break;
			case '-3': $can_view = $owner || $match; break;
			default: $can_view = false;
		}
		return apply_filters( 'um_can_view_field', $can_view, $field );
	}
	function um_user_profile_url() {
		// Disclosed fidelity extension (behavior-preserving): an optional per-test override
		// key lets a test model any value UM's own accessor would return — including the
		// invalid ones the C8 URL validator must reject. The default is byte-identical to
		// the previous constant return, so every pre-existing consumer sees no change.
		return (string) hal_wp_stub( 'um_profile_url', 'https://stub.test/profile/' );
	}
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
				$tab = hal_wp_stub( 'um_account_tab', 'general' );
				if ( '__UNAVAILABLE__' === $tab ) {
					return new \stdClass();
				}
				return new class( $tab ) {
					public $current_tab;
					public $tabs = array();
					public function __construct( $tab ) { $this->current_tab = $tab; }
				};
			}
			public function builtin() {
				return new class {
					public function get_specific_fields( $spec ) {
						$all = hal_wp_stub( 'um_builtin_fields', array() );
						$out = array();
						foreach ( array_filter( array_map( 'trim', explode( ',', (string) $spec ) ) ) as $key ) {
							if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
								$out[ $key ] = $all[ $key ];
							}
						}
						return $out;
					}
				};
			}
			public function common() {
				return new class {
					public function users() {
						return new class {
							public function statuses_list() {
								$statuses = hal_wp_stub( 'um_statuses', array() );
								if ( '__UNAVAILABLE__' === $statuses ) {
									throw new \RuntimeException( 'UM statuses unavailable' );
								}
								return $statuses;
							}
							public function get_status( $user_id ) {
								$statuses = hal_wp_stub( 'um_status_by_user', array() );
								return $statuses[ $user_id ] ?? '';
							}
						};
					}
				};
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
		/** Stubbed builder frontend; tests may inject get_builder_content_for_display(). */
		public $frontend;
		public static function instance() { return self::$instance; }
	}
}
