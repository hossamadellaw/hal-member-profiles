<?php
/**
 * Unit tests for AdminDashboard permissions and read-only guarantees (cards D-06/D-17):
 * manage_options gates every surface, notices resolve once, and NO dashboard interaction
 * ever writes options/transients/files. Card S-08 adds the runtime evidence display
 * contract: the JSON never reaches a non-privileged user, is escaped/read-only, and the
 * reporter class never loads on frontend requests.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AdminDashboard;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\FieldSchema;
use HAL\MemberProfiles\RuntimeEvidenceReporter;
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

	/**
	 * Card S-08 (deny path): without manage_options the Diagnostics page must refuse
	 * through its 403 branch before emitting anything — the evidence JSON can never
	 * reach a non-privileged user. The stub environment deliberately leaves wp_die
	 * undefined, so the refusal surfaces as an engine \Error; in real WordPress this is
	 * wp_die( ..., 403 ). Either way: refusal first, zero output.
	 *
	 * Declared BEFORE the capability render test below: PHPUnit runs tests in
	 * declaration order, and this test must observe the reporter class still unloaded.
	 */
	public function test_diagnostics_evidence_json_is_never_rendered_without_manage_options(): void {
		$GLOBALS['wp_stubs']['can_manage'] = false;

		$buffer_level = ob_get_level();
		$output       = '';
		$refused      = false;

		try {
			ob_start();
			AdminDashboard::render_diagnostics_page();
			ob_get_clean();
		} catch ( \Throwable $guard ) {
			$output  = (string) ob_get_clean();
			$refused = true;

			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( $refused, 'the manage_options guard must refuse before rendering' );
		$this->assertSame( '', $output, 'no page content may leak to a non-privileged user' );
		$this->assertFalse(
			class_exists( RuntimeEvidenceReporter::class, false ),
			'the reporter must not even load when the page is refused'
		);
	}

	/**
	 * Card S-08 (frontend isolation): the frontend boot must never load the reporter —
	 * its single consumption point is the Diagnostics page inside the admin shell —
	 * and must not register that shell (admin_menu). A structural source check pins
	 * the single-consumption-point contract independently of process state.
	 */
	public function test_runtime_evidence_reporter_never_loads_on_the_frontend(): void {
		if ( class_exists( RuntimeEvidenceReporter::class, false ) ) {
			self::markTestSkipped( 'reporter class already loaded by an earlier test in this process' );
		}

		$hooks       = $GLOBALS['__hooks'] ?? array();
		$did_actions = $GLOBALS['__did_actions'] ?? array();
		$is_admin    = hal_wp_stub_extra( 'is_admin' );

		$GLOBALS['__hooks']       = array();
		$GLOBALS['__did_actions'] = array( 'elementor/loaded' => 1 );
		hal_wp_stub_extra_set( 'is_admin', false );

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		try {
			Bootstrap::init();

			$this->assertFalse(
				class_exists( RuntimeEvidenceReporter::class, false ),
				'the evidence reporter must not load on frontend requests'
			);

			// The admin shell that owns the Diagnostics page — the reporter's single
			// consumption point — is never registered on frontend requests. Settings
			// may hook admin_menu at boot by design (card S-03); that hook is inert on
			// frontend because WordPress never fires it there.
			$registered = new \ReflectionProperty( AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$this->assertFalse( $registered->getValue(), 'the admin shell must not register on frontend requests' );
		} finally {
			$instance->setValue( null, null );
			$GLOBALS['__hooks']       = $hooks;
			$GLOBALS['__did_actions'] = $did_actions;
			hal_wp_stub_extra_set( 'is_admin', $is_admin );
		}

		// Structural backstop (order-independent): every reporter reference inside
		// AdminDashboard lives inside render_diagnostics_rows() — never in
		// load_module_classes() or anywhere else — and Bootstrap never names it.
		$dashboard_src = (string) file_get_contents( HAL_MEMBER_PROFILES_PLUGIN_DIR . 'includes/AdminDashboard.php' );
		$method        = new \ReflectionMethod( AdminDashboard::class, 'render_diagnostics_rows' );
		$start         = $method->getStartLine();
		$end           = $method->getEndLine();

		$references = 0;

		foreach ( preg_grep( '/RuntimeEvidenceReporter/', explode( "\n", $dashboard_src ) ) as $line_no => $line ) {
			$references++;
			$this->assertGreaterThanOrEqual( $start, $line_no + 1, "reporter reference outside render_diagnostics_rows() at line " . ( $line_no + 1 ) );
			$this->assertLessThanOrEqual( $end, $line_no + 1, "reporter reference outside render_diagnostics_rows() at line " . ( $line_no + 1 ) );
		}

		$this->assertGreaterThanOrEqual( 1, $references, 'the lazy consumption point must exist' );

		$this->assertStringNotContainsString(
			'RuntimeEvidenceReporter',
			(string) file_get_contents( HAL_MEMBER_PROFILES_PLUGIN_DIR . 'includes/Bootstrap.php' ),
			'Bootstrap must never reference the evidence reporter'
		);
	}

	/**
	 * Card S-08 (grant path): with manage_options the Diagnostics page shows the
	 * evidence JSON escaped inside a readonly textarea — the machine kind string may
	 * only appear in its &quot;-escaped form — and rendering performs zero writes and
	 * zero HTTP.
	 */
	public function test_diagnostics_evidence_json_renders_escaped_and_readonly_for_manage_options(): void {
		$backed_up = array(
			'options' => $GLOBALS['wp_stubs']['options'],
			'hooks'   => $GLOBALS['__hooks'] ?? array(),
			'is_admin'=> hal_wp_stub_extra( 'is_admin' ),
		);

		$GLOBALS['__hooks'] = array();
		hal_wp_stub_extra_set( 'is_admin', true );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
		if ( ! function_exists( 'UM' ) ) {
			eval( 'function UM() { return (object) array(); }' );
		}
		if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
			// Production always defines this in the main plugin file before boot.
			define( 'HAL_MEMBER_PROFILES_VERSION', '1.1.5' );
		}

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( AdminDashboard::class, false ) ) {
			$registered = new \ReflectionProperty( AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		try {
			Bootstrap::init();
			$this->assertInstanceOf( Bootstrap::class, Bootstrap::instance() );

			// Snapshot AFTER boot: this test owns the render contract only, not the
			// boot's own lifecycle behavior.
			$options_before    = $GLOBALS['wp_stubs']['options'];
			$transients_before = $GLOBALS['wp_stubs_extra']['transients'] ?? array();
			hal_wp_stub_reset_http();

			$buffer_level = ob_get_level();

			try {
				ob_start();
				AdminDashboard::render_diagnostics_page();
				$out = ob_get_clean();
			} finally {
				while ( ob_get_level() > $buffer_level ) {
					ob_end_clean();
				}
			}

			$this->assertStringContainsString(
				'<label for="hal-runtime-evidence-json"',
				$out,
				'the evidence textarea must have an explicit accessible label'
			);
			$this->assertSame(
				1,
				preg_match( '/<textarea\b[^>]*\bid="hal-runtime-evidence-json"[^>]*>/', $out, $evidence_textarea ),
				'the evidence JSON must render in its stable textarea'
			);
			$this->assertMatchesRegularExpression(
				'/\sreadonly(?:\s|=|>)/',
				$evidence_textarea[0],
				'the evidence JSON must be read-only'
			);
			$this->assertStringContainsString( 'aria-label=', $evidence_textarea[0], 'the textarea must carry an accessible label' );
			$this->assertStringContainsString(
				'&quot;hal_runtime_compatibility_evidence&quot;',
				$out,
				'the evidence JSON must reach the page escaped'
			);
			$this->assertStringNotContainsString(
				'"hal_runtime_compatibility_evidence"',
				$out,
				'raw unescaped JSON must never reach the page'
			);

			$c = hal_wp_stub_extra( 'counters' );

			$this->assertSame( 0, $c['update_option'], 'rendering evidence must not write options' );
			$this->assertSame( 0, $c['delete_option'], 'rendering evidence must not delete options' );
			$this->assertSame( 0, $c['delete_transient'], 'rendering evidence must not delete transients' );
			$this->assertSame( $options_before, $GLOBALS['wp_stubs']['options'], 'rendering evidence must not change options' );
			$this->assertSame( $transients_before, $GLOBALS['wp_stubs_extra']['transients'] ?? array(), 'rendering evidence must not change transients' );
			$this->assertSame( 0, count( hal_wp_stub_http_calls() ), 'rendering evidence must not perform HTTP' );
		} finally {
			$instance->setValue( null, null );
			$GLOBALS['wp_stubs']['options'] = $backed_up['options'];
			$GLOBALS['__hooks']             = $backed_up['hooks'];
			hal_wp_stub_extra_set( 'is_admin', $backed_up['is_admin'] );
		}
	}
}
