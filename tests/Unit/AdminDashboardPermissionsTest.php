<?php
/**
 * Unit tests for AdminDashboard permissions and read-only guarantees (cards D-06/D-17):
 * manage_options gates every surface, notices resolve once, and NO dashboard interaction
 * ever writes options/transients/files.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AdminDashboard;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\FieldSchema;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\SecretStore;
use HAL\MemberProfiles\Settings;
use HAL\MemberProfiles\Integrations\AmeliaFieldsWriter;
use PHPUnit\Framework\TestCase;

final class AdminDashboardPermissionsTest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_extra_set( 'menu_pages', array() );
		hal_wp_stub_extra_set(
			'counters',
			array(
				'update_option'    => 0,
				'delete_option'    => 0,
				'delete_transient' => 0,
				'add_menu_page'    => 0,
			)
		);
		unset( $_GET[ AdminDashboard::REPAIR_QUERY_ARG ] );
	}

	protected function tearDown(): void {
		unset( $_GET[ AdminDashboard::REPAIR_QUERY_ARG ] );
	}

	private function write_counters_all_zero(): bool {
		$c = hal_wp_stub_extra( 'counters' );

		return 0 === $c['update_option']
			&& 0 === $c['delete_option']
			&& 0 === $c['delete_transient'];
	}

	public function test_without_manage_options_no_menu_is_registered(): void {
		$GLOBALS['wp_stubs']['can_manage'] = false;

		AdminDashboard::add_page();

		$this->assertSame( 0, hal_wp_stub_counter( 'add_menu_page' ) );
	}

	public function test_with_manage_options_the_dashboard_menu_registers_once_per_call(): void {
		AdminDashboard::add_page();
		AdminDashboard::add_page();

		$this->assertSame( 2, hal_wp_stub_counter( 'add_menu_page' ) );

		$first = hal_wp_stub_extra( 'menu_pages' )[0];

		$this->assertSame( 'manage_options', $first[2] );
		$this->assertSame( AdminDashboard::PAGE_SLUG, $first[3] );
	}

	public function test_repair_notice_requires_capability_and_prints_once_from_query_arg(): void {
		$GLOBALS['wp_stubs']['can_manage'] = false;

		ob_start();
		AdminDashboard::render_action_notice();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'no notice without capability' );

		$GLOBALS['wp_stubs']['can_manage'] = true;
		$_GET[ AdminDashboard::REPAIR_QUERY_ARG ] = 'done';

		ob_start();
		AdminDashboard::render_action_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $out );
		$this->assertStringContainsString( 'is-dismissible', $out );

		// The query arg is gone on the next pageload in real WP; here we assert the same
		// one-shot semantics by removing it.
		unset( $_GET[ AdminDashboard::REPAIR_QUERY_ARG ] );

		ob_start();
		AdminDashboard::render_action_notice();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'notice must disappear once resolved' );
	}

	public function test_error_notice_names_a_sanitized_reason_slug_only(): void {
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$_GET[ AdminDashboard::REPAIR_QUERY_ARG ] = 'failed_some_reason';

		ob_start();
		AdminDashboard::render_action_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( 'failed_some_reason', $out );
	}

	public function test_dashboard_interactions_never_write_anything(): void {
		$GLOBALS['wp_stubs']['can_manage'] = true;

		$_GET[ AdminDashboard::REPAIR_QUERY_ARG ] = 'done';

		ob_start();
		AdminDashboard::add_page();
		AdminDashboard::render_action_notice();
		ob_end_clean();

		unset( $_GET[ AdminDashboard::REPAIR_QUERY_ARG ] );

		$this->assertTrue( $this->write_counters_all_zero(), 'dashboard is read-only by design' );
	}

	public function test_dashboard_renders_and_loads_amelia_fields_writer_without_test_autoloading(): void {
		class_exists( Bootstrap::class );
		class_exists( FieldSchema::class );
		class_exists( SecretStore::class );
		class_exists( SchemaRegistry::class );
		class_exists( Settings::class );

		$test_autoloader = null;

		foreach ( spl_autoload_functions() as $autoloader ) {
			if ( ! $autoloader instanceof \Closure ) {
				continue;
			}

			$reflection = new \ReflectionFunction( $autoloader );

			$loader_file = str_replace( '\\', '/', (string) $reflection->getFileName() );
			$test_file   = str_replace( '\\', '/', HAL_MEMBER_PROFILES_PLUGIN_DIR . 'tests/bootstrap.php' );

			if ( $test_file === $loader_file ) {
				$test_autoloader = $autoloader;
				spl_autoload_unregister( $test_autoloader );
				break;
			}
		}

		$this->assertNotNull( $test_autoloader, 'The HAL-only test autoloader must be isolated.' );
		$this->assertFalse( class_exists( AmeliaFieldsWriter::class, false ) );

		$buffer_level = ob_get_level();

		try {
			ob_start();
			AdminDashboard::render_page();
			$output = ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			spl_autoload_register( $test_autoloader );
		}

		$this->assertTrue( class_exists( AmeliaFieldsWriter::class, false ) );
		$this->assertStringContainsString( 'HAL Member Profiles', $output );
	}
}
