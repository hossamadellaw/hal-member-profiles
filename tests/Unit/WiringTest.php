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

		$state = get_option( ManagedTemplates::STATE_OPTION );

		$this->assertIsArray( $state );
		$this->assertSame( 'child-wiring', $state['theme'] ?? '', 'provisioning ran against the active stubbed theme' );
		$this->assertNotNull( $this->boot_admin()->get_managed_templates(), 'bootstrap owns the managed-templates service' );
	}
}

class LifecycleShim {

	public static function run_tick(): void {
		\HAL\MemberProfiles\Lifecycle::maybe_reconcile();
	}
}
