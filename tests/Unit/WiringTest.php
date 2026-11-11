<?php
/**
 * Wiring tests (Integration Closure #8): prove the new modules are ACTUALLY consumed by
 * Bootstrap/Dashboard/Lifecycle in the admin boot path — routes registered, hooks armed,
 * shared instances injected — not merely existing as isolated classes.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AdminDashboard;
use HAL\MemberProfiles\ManagedTemplates;
use HAL\MemberProfiles\Bootstrap;
use PHPUnit\Framework\TestCase;
use HAL\MemberProfiles\Settings;

// Card F-01: the write-closure gate compares the RUNNING plugin version, and this suite
// boots without the main plugin file, so the version constant is pinned here for the
// whole test process (file level = before ANY test method runs) — including when THIS
// file is run standalone, where ManagedTemplatesTest.php (which pins the constant for
// the shared full-suite process) never loads. A 1.2.x value keeps every
// writes_disabled_v1_2 assertion in this suite meaningful against the 1.3.0 gate.
// Production always defines this in hal-member-profiles.php before boot; the guarded
// define is a no-op there and matches the convention in AdminDashboardPermissionsTest
// and RuntimeEvidenceReporterTest.
if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.2.0' );
}

final class WiringTest extends TestCase {

	private array $backed_up = array();

	private ?string $theme = null;

	protected function setUp(): void {
		$this->backed_up = array(
			'options'   => $GLOBALS['wp_stubs']['options'],
			'is_admin'  => hal_wp_stub_extra( 'is_admin' ),
			'hooks'     => $GLOBALS['__hooks'] ?? array(),
			'can_manage'=> $GLOBALS['wp_stubs']['can_manage'],
		);

		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$GLOBALS['wp_stubs']['options']    = array(
			\HAL\MemberProfiles\Settings::OPTION_KEY => array(
				'managed_templates_consent' => true,
				'amelia_sync_mode'          => 'discover_only',
			),
		);
		$GLOBALS['__hooks']                = array();

		$this->theme = sys_get_temp_dir() . '/hal_d17_wiring_' . uniqid();
		@mkdir( $this->theme . '/ultimate-member/templates', 0777, true );

		hal_wp_stub_extra_set( 'stylesheet', 'child-wiring' );
		hal_wp_stub_extra_set( 'template', 'parent' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->theme );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
		if ( ! function_exists( 'UM' ) ) {
			eval( 'function UM() { return (object) array(); }' );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->theme . '/*' ) ?: array() as $path ) {
			is_dir( $path ) ? @rmdir( $path ) : @unlink( $path );
		}

		@rmdir( $this->theme . '/ultimate-member/templates' );
		@rmdir( $this->theme . '/ultimate-member' );
		@rmdir( $this->theme );

		$GLOBALS['wp_stubs']['options'] = $this->backed_up['options'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		hal_wp_stub_extra_set( 'stylesheet', '' );
		hal_wp_stub_extra_set( 'stylesheet_dir', '' );
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];
	}

	private function boot_admin(): Bootstrap {
		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( AdminDashboard::class ) ) {
			$registered = new \ReflectionProperty( AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		Bootstrap::init();

		$b = Bootstrap::instance();

		$this->assertInstanceOf( Bootstrap::class, $b );

		return $b;
	}

	public function test_bootstrap_creates_and_shares_the_schema_registry(): void {
		$b = $this->boot_admin();

		$registry = $b->get_schema_registry();

		$this->assertInstanceOf( \HAL\MemberProfiles\SchemaRegistry::class, $registry );

		$prop = new \ReflectionProperty( \HAL\MemberProfiles\SchemaRegistry::class, 'field_schema' );
		$prop->setAccessible( true );

		$this->assertSame(
			spl_object_hash( $b->get_field_schema() ),
			spl_object_hash( $prop->getValue( $registry ) ),
			'registry must consume the SHARED FieldSchema instance, not a private clone'
		);
	}

	public function test_dashboard_registers_all_six_governed_routes(): void {
		$this->boot_admin();

		$expected = array(
			'admin_post_hal_member_profiles_key_save',
			'admin_post_hal_member_profiles_key_revoke',
			'admin_post_hal_member_profiles_conn_test',
			'admin_post_hal_member_profiles_snap_refresh',
			'admin_post_hal_member_profiles_desired_save',
			'admin_post_hal_member_profiles_fields_apply',
		);

		foreach ( $expected as $hook ) {
			$this->assertArrayHasKey(
				$hook,
				$GLOBALS['__hooks'],
				"route {$hook} must be wired by AdminDashboard"
			);
		}
	}

	public function test_lifecycle_is_wired_with_consent_aware_reconciliation(): void {
		LifecycleShim::run_tick();

		// v1.2.1 (card F-06): reconciliation stays consent-aware and fail-closed — no
		// provisioning state is recorded and nothing is ever written; the terminal
		// writes_disabled_v1_2 closure lands on the FINAL idle status exactly once
		// (no pending re-loop), while Bootstrap still owns the managed-templates service.
		$status = get_option( \HAL\MemberProfiles\Lifecycle::PENDING_OPTION, array() );

		$this->assertSame( 'idle', is_array( $status ) ? ( $status['status'] ?? '' ) : '' );
		$this->assertStringContainsString( 'writes_disabled_v1_2', (string) ( is_array( $status ) ? ( $status['reason'] ?? '' ) : '' ) );
		$this->assertFalse( get_option( ManagedTemplates::STATE_OPTION, false ), 'fail-closed provisioning must not record template state' );
		$this->assertNotNull( $this->boot_admin()->get_managed_templates(), 'bootstrap owns the managed-templates service' );
	}

	/** C2: Account remains one UM-owned surface; only the field helper stays registerable. */
	public function test_elementor_registry_excludes_split_account_widgets_and_preserves_legacy_slugs(): void {
		$b        = $this->boot_admin();
		$register = ( new \ReflectionClass( \HAL\MemberProfiles\Elementor\Register::class ) )->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty( \HAL\MemberProfiles\Elementor\Register::class, 'dependencies' );
		$property->setAccessible( true );
		$property->setValue( $register, $b->get_dependencies() );

		$method = new \ReflectionMethod( \HAL\MemberProfiles\Elementor\Register::class, 'widget_classes' );
		$method->setAccessible( true );
		$widgets = $method->invoke( $register );
		$prefix  = '\\HAL\\MemberProfiles\\Elementor\\Widgets\\';

		$this->assertArrayHasKey( $prefix . 'AccountField', $widgets );
		$this->assertArrayNotHasKey( $prefix . 'AccountNavigation', $widgets );
		$this->assertArrayNotHasKey( $prefix . 'AccountBody', $widgets );

		foreach (
			array(
				'AccountNavigation' => 'hal_member_profiles_account_navigation',
				'AccountBody'       => 'hal_member_profiles_account_body',
			) as $class => $slug
		) {
			$fqcn = $prefix . $class;
			$this->assertSame( $slug, ( new $fqcn() )->get_name() );

			$render = new \ReflectionMethod( $fqcn, 'render' );
			$render->setAccessible( true );
			ob_start();
			$render->invoke( new $fqcn() );
			$this->assertSame( '', ob_get_clean(), $class . ' stays inert for saved legacy documents' );
		}
	}

	public function test_legacy_account_adapter_attempts_native_callback_only_once_on_failure(): void {
		$calls = 0;

		\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
			static function ( array $args ) use ( &$calls ): void {
				++$calls;
				throw new \RuntimeException( (string) ( $args['reason'] ?? '' ) );
			},
			array( 'reason' => 'test' )
		);

		$this->assertSame( 1, $calls );
	}

	public function test_legacy_account_adapter_delegates_native_callback_once_with_original_args(): void {
		$calls = array();

		ob_start();
		\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
			static function ( array $args ) use ( &$calls ): void {
				$calls[] = $args;
				echo 'NATIVE-ACCOUNT';
			},
			array( 'mode' => 'account' )
		);
		$output = ob_get_clean();

		$this->assertSame( 'NATIVE-ACCOUNT', $output );
		$this->assertSame( array( array( 'mode' => 'account' ) ), $calls );
	}

	/** Card S-01: the Amelia read integration must NOT auto-wire the F-16 save callback. */
	public function test_amelia_constructor_does_not_auto_register_profile_services_save_filter(): void {
		$amelia = new \HAL\MemberProfiles\Integrations\Amelia( new Settings(), array( 1 => 'Wired Service' ) );

		// The save method itself survives card S-01 unchanged: explicitly callable,
		// still fail-closed (an unmapped employee stores [] per the F-16 contract).
		$this->assertTrue( method_exists( $amelia, 'filter_profile_services_before_save' ) );
		$this->assertSame(
			array(),
			$amelia->filter_profile_services_before_save(
				array( 'selected_services' => array( 1 ) ),
				9,
				array()
			)['selected_services'],
			'F-16 contract unchanged: an unmapped employee still stores [] when invoked explicitly'
		);

		// The constructor no longer registers anything on the UM profile-save hook.
		$ctor = new \ReflectionMethod( \HAL\MemberProfiles\Integrations\Amelia::class, '__construct' );
		$src  = implode(
			'',
			array_slice(
				file( $ctor->getFileName() ),
				$ctor->getStartLine() - 1,
				$ctor->getEndLine() - $ctor->getStartLine() + 1
			)
		);

		$this->assertStringNotContainsString( 'add_filter', $src, 'constructor must not auto-register the save filter' );
		$this->assertStringNotContainsString( 'um_user_pre_updating_profile_array', $src );
	}

	/** Card S-03: one HAL parent menu, Overview + Settings submenus under it, zero add_options_page(). */
	public function test_admin_menu_registers_single_parent_with_overview_and_settings_submenus(): void {
		hal_wp_stub_extra_set( 'menu_pages', array() );
		hal_wp_stub_extra_set( 'submenu_pages', array() );
		hal_wp_stub_extra_set( 'options_pages', array() );
		hal_wp_stub_extra_set(
			'counters',
			array_merge(
				hal_wp_stub_extra( 'counters', array() ),
				array( 'add_menu_page' => 0, 'add_submenu_page' => 0, 'add_options_page' => 0 )
			)
		);

		$this->boot_admin();

		// The admin_menu stubs only RECORD callbacks, so fire them here in priority
		// order — the same order real WordPress would use.
		$hooks      = $GLOBALS['__hooks']['admin_menu'] ?? array();
		$priorities = array();

		usort(
			$hooks,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		foreach ( $hooks as $hook ) {
			$method = is_array( $hook['callback'] ) ? (string) ( $hook['callback'][1] ?? '' ) : '';

			if ( in_array( $method, array( 'add_page', 'add_overview_submenu', 'register_page' ), true ) ) {
				$priorities[ $method ] = $hook['priority'];
			}

			if ( is_callable( $hook['callback'] ) ) {
				call_user_func( $hook['callback'] );
			}
		}

		// Parent registers first; both submenus register at defined later priorities,
		// regardless of Settings being instantiated before AdminDashboard in Bootstrap.
		$this->assertSame(
			array(
				'add_page'             => 5,
				'add_overview_submenu' => 15,
				'register_page'        => 20,
			),
			$priorities
		);

		$menu = hal_wp_stub_extra( 'menu_pages' );

		$this->assertCount( 1, $menu, 'exactly one top-level HAL parent menu' );
		$this->assertSame( 'hal-member-profiles', $menu[0][3] );

		$submenu = hal_wp_stub_extra( 'submenu_pages' );

		$overview = array_values(
			array_filter(
				$submenu,
				static function ( $args ) {
					return 'hal-member-profiles' === ( $args[4] ?? '' );
				}
			)
		);

		$settings = array_values(
			array_filter(
				$submenu,
				static function ( $args ) {
					return 'hal-member-profiles-settings' === ( $args[4] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $overview, 'exactly one Overview submenu carrying the parent slug' );
		$this->assertSame( 'hal-member-profiles', $overview[0][0], 'Overview lives under the HAL parent' );

		$this->assertCount( 1, $settings, 'exactly one Settings submenu' );
		$this->assertSame( 'hal-member-profiles', $settings[0][0], 'Settings lives under the HAL parent' );
		$this->assertSame( 'manage_options', $settings[0][3] );

		$this->assertSame( 0, hal_wp_stub_counter( 'add_options_page' ), 'the standalone options page is gone' );
		$this->assertSame( array(), hal_wp_stub_extra( 'options_pages' ) );
	}

	/** Card S-04: the six HAL admin pages exist exactly once, in the mandated order. */
	public function test_admin_menu_registers_six_hal_pages_exactly_once_in_order(): void {
		hal_wp_stub_extra_set( 'menu_pages', array() );
		hal_wp_stub_extra_set( 'submenu_pages', array() );
		hal_wp_stub_extra_set( 'options_pages', array() );

		$this->boot_admin();

		$hooks = $GLOBALS['__hooks']['admin_menu'] ?? array();

		usort(
			$hooks,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		foreach ( $hooks as $hook ) {
			if ( is_callable( $hook['callback'] ) ) {
				call_user_func( $hook['callback'] );
			}
		}

		$slugs = array_map(
			static function ( $args ) {
				return $args[4] ?? '';
			},
			hal_wp_stub_extra( 'submenu_pages' )
		);

		$this->assertSame(
			array(
				'hal-member-profiles',
				'hal-member-profiles-profiles',
				'hal-member-profiles-account',
				'hal-member-profiles-amelia',
				'hal-member-profiles-diagnostics',
				'hal-member-profiles-settings',
			),
			$slugs,
			'six HAL pages, each once, in the mandated card S-04 order'
		);

		foreach ( hal_wp_stub_extra( 'submenu_pages' ) as $args ) {
			$this->assertSame( 'hal-member-profiles', $args[0], 'every page lives under the HAL parent' );
			$this->assertSame( 'manage_options', $args[3] );
		}

		$this->assertCount( 1, hal_wp_stub_extra( 'menu_pages' ), 'still exactly one parent' );
	}

	/** Card S-04: every HAL page renders read-only state; Settings keeps the only options form. */
	public function test_hal_admin_pages_render_and_settings_keeps_the_only_options_form(): void {
		$this->boot_admin();

		$pages = array(
			'render_page'             => 'Overview / Health',
			'render_profiles_page'    => 'Profile layout mode',
			'render_account_page'     => 'Account layout mode',
			'render_amelia_page'      => 'Amelia plugin',
			'render_diagnostics_page' => 'Compatibility / Diagnostics',
		);

		foreach ( $pages as $method => $marker ) {
			ob_start();
			AdminDashboard::$method();
			$out = ob_get_clean();

			$this->assertStringContainsString( $marker, $out, "{$method} renders its content" );
			$this->assertStringNotContainsString( 'action="options.php"', $out, "{$method} must not embed an options form" );
		}

		// The complete option form remains owned by Settings::render_page() — proven by
		// contract scan since the stub env has no settings_fields()/settings API.
		$ref = new \ReflectionMethod( Settings::class, 'render_page' );
		$src = implode(
			'',
			array_slice(
				file( $ref->getFileName() ),
				$ref->getStartLine() - 1,
				$ref->getEndLine() - $ref->getStartLine() + 1
			)
		);

		$this->assertStringContainsString( 'action="options.php"', $src, 'Settings keeps the full option form' );
		$this->assertStringContainsString( 'settings_fields', $src );
	}

	/** Card S-04: redirect fallbacks return to the owning submenu of each operation. */
	public function test_action_redirect_fallbacks_target_the_owning_submenu(): void {
		$amelia = new \ReflectionMethod( AdminDashboard::class, 'handle_amelia_post' );
		$amelia_src = implode(
			'',
			array_slice(
				file( $amelia->getFileName() ),
				$amelia->getStartLine() - 1,
				$amelia->getEndLine() - $amelia->getStartLine() + 1
			)
		);

		$this->assertStringContainsString(
			"admin.php?page=' . self::AMELIA_PAGE_SLUG",
			$amelia_src,
			'Amelia actions fall back to the Amelia page'
		);

		$repair = new \ReflectionMethod( \HAL\MemberProfiles\Lifecycle::class, 'handle_repair_request' );
		$repair_src = implode(
			'',
			array_slice(
				file( $repair->getFileName() ),
				$repair->getStartLine() - 1,
				$repair->getEndLine() - $repair->getStartLine() + 1
			)
		);

		$this->assertStringContainsString(
			'admin.php?page=hal-member-profiles-diagnostics',
			$repair_src,
			'The Repair command falls back to the Diagnostics page'
		);
	}

	/** Card S-05: guidance + unified states on all six pages, labeled fields, one H1 each, no admin asset. */
	public function test_admin_pages_show_guidance_states_labeled_fields_and_no_new_asset(): void {
		$b = $this->boot_admin();

		$renders = array(
			array(
				'Overview',
				static function (): void { AdminDashboard::render_page(); },
			),
			array(
				'Profiles',
				static function (): void { AdminDashboard::render_profiles_page(); },
			),
			array(
				'Account',
				static function (): void { AdminDashboard::render_account_page(); },
			),
			array(
				'Amelia',
				static function (): void { AdminDashboard::render_amelia_page(); },
			),
			array(
				'Diagnostics',
				static function (): void { AdminDashboard::render_diagnostics_page(); },
			),
			array(
				'Settings',
				static function () use ( $b ): void { $b->get_settings()->render_page(); },
			),
		);

		foreach ( $renders as [$name, $render] ) {
			ob_start();
			$render();
			$out = ob_get_clean();

			$this->assertSame( 1, substr_count( $out, '<h1>' ), "{$name} has exactly one H1" );
			$this->assertStringContainsString( 'Next step', $out, "{$name} carries its next-step guidance" );
		}

		ob_start();
		AdminDashboard::render_profiles_page();
		$profiles = ob_get_clean();
		$this->assertStringContainsString( 'Not configured', $profiles, 'layout mode shows the unified state' );

		ob_start();
		AdminDashboard::render_account_page();
		$account = ob_get_clean();
		$this->assertStringContainsString( 'Not configured', $account );
		$this->assertStringContainsString( 'Native (Ultimate Member)', $account );
		$this->assertStringContainsString( 'Ultimate Member native Account remains active', $account );

		ob_start();
		AdminDashboard::render_diagnostics_page();
		$diagnostics = ob_get_clean();
		$this->assertMatchesRegularExpression( '/Ready|Blocked|Pending|Not configured/', $diagnostics );

		ob_start();
		AdminDashboard::render_amelia_page();
		$amelia = ob_get_clean();
		$this->assertStringContainsString( 'for="hal-amelia-api-key"', $amelia, 'the sensitive key field has a real label' );
		$this->assertStringContainsString( 'id="hal-amelia-api-key"', $amelia );

		ob_start();
		$b->get_settings()->render_page();
		$settings_out = ob_get_clean();

		foreach (
			array(
				'hal-profile-layout-mode',
				'hal-profile-template-id',
				'hal-profile-fixture-id',
				'hal-account-layout-mode',
				'hal-account-template-id',
				'hal-account-fixture-id',
				'hal-amelia-booking-url',
				'hal-amelia-sync-mode',
			) as $field_id
		) {
			$this->assertStringContainsString( 'for="' . $field_id . '"', $settings_out );
			$this->assertStringContainsString( 'id="' . $field_id . '"', $settings_out );
		}

		// Saved-value contract unchanged: the same ten option keys still post.
		foreach (
			array(
				'profile_layout_mode',
				'profile_library_template_id',
				'profile_fixture_user_id',
				'account_layout_mode',
				'account_library_template_id',
				'account_fixture_user_id',
				'amelia_booking_url',
				'amelia_sync_mode',
				'managed_templates_consent',
				'purge_on_uninstall',
			) as $key
		) {
			$this->assertStringContainsString( '[' . $key . ']', $settings_out );
		}

		// Card S-05 asset rule: no admin asset introduced at all — core components suffice.
		$this->assertArrayNotHasKey( 'admin_enqueue_scripts', $GLOBALS['__hooks'] );
		$this->assertFileDoesNotExist( HAL_MEMBER_PROFILES_PLUGIN_DIR . 'assets/admin.css' );
	}
}

class LifecycleShim {

	public static function run_tick(): void {
		\HAL\MemberProfiles\Lifecycle::maybe_reconcile();
	}
}
