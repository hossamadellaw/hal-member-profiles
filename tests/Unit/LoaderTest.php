<?php
/**
 * Targeted loader regression test for Elementor plugin-order independence.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase {

	private array $hooks;
	private array $did_actions;
	private bool $can_manage;
	private bool $is_admin;

	protected function setUp(): void {
		$this->hooks       = $GLOBALS['__hooks'] ?? array();
		$this->did_actions = $GLOBALS['__did_actions'] ?? array();
		$this->can_manage  = (bool) $GLOBALS['wp_stubs']['can_manage'];
		$this->is_admin    = (bool) hal_wp_stub_extra( 'is_admin' );

		$GLOBALS['__hooks']                  = array();
		$GLOBALS['__did_actions']            = array();
		$GLOBALS['wp_stubs']['can_manage']   = true;
		hal_wp_stub_extra_set( 'is_admin', false );

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$GLOBALS['__hooks']                = $this->hooks;
		$GLOBALS['__did_actions']          = $this->did_actions;
		$GLOBALS['wp_stubs']['can_manage'] = $this->can_manage;
		hal_wp_stub_extra_set( 'is_admin', $this->is_admin );
	}

	public function test_loader_waits_for_elementor_loaded_and_suppresses_stale_notice(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_URL' ) ) {
			define( 'HAL_MEMBER_PROFILES_URL', 'https://stub.test/wp-content/plugins/hal-member-profiles/' );
		}
		require_once HAL_MEMBER_PROFILES_PLUGIN_DIR . 'hal-member-profiles.php';

		$this->assertNull( Bootstrap::instance() );
		$this->assertSame( 20, $GLOBALS['__hooks']['plugins_loaded'][0]['priority'] );

		$plugins_loaded = array_values(
			array_filter(
				$GLOBALS['__hooks']['plugins_loaded'],
				static fn( $hook ) => 'HAL\\MemberProfiles\\hal_member_profiles_boot' === $hook['callback']
			)
		);
		call_user_func( $plugins_loaded[0]['callback'] );

		$this->assertNull( Bootstrap::instance(), 'HAL must wait for Elementor readiness.' );
		$this->assertArrayHasKey( 'elementor/loaded', $GLOBALS['__hooks'] );

		$GLOBALS['__did_actions']['elementor/loaded'] = 1;
		$elementor_loaded = $GLOBALS['__hooks']['elementor/loaded'][0]['callback'];
		call_user_func( $elementor_loaded );
		$booted = Bootstrap::instance();
		call_user_func( $elementor_loaded );

		$this->assertInstanceOf( Bootstrap::class, $booted );
		$this->assertSame( $booted, Bootstrap::instance(), 'Bootstrap must start only once.' );

		ob_start();
		call_user_func( $GLOBALS['__hooks']['admin_notices'][0]['callback'] );
		$notice = ob_get_clean();

		$this->assertStringNotContainsString( 'Elementor is required and was not detected', $notice );
	}
}
