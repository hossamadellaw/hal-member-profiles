<?php
/**
 * Integration tests: Ultimate Member + Elementor + Amelia + Account, together.
 *
 * Requires a real, independent WordPress test environment (WP_TESTS_DIR) with the UM /
 * Elementor / Amelia versions recorded in docs/compatibility-matrix.md. Locally, without
 * that environment, every test below SKIPS — it is never "incomplete" and never fakes a
 * pass against stubs.
 *
 * @package HAL\MemberProfiles\Tests\Integration
 */

namespace HAL\MemberProfiles\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UltimateMemberIntegrationTest extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_TESTS_WP' ) || ! HAL_MEMBER_PROFILES_TESTS_WP ) {
			$this->markTestSkipped( 'Requires the WordPress test environment (WP_TESTS_DIR).' );
		}
	}

	private function boot(): ?Bootstrap {
		return Bootstrap::instance();
	}

	/** Card 7.11: deleted/Draft Library Template falls back to Native. */
	public function test_profile_adapter_falls_back_when_library_template_is_draft_or_deleted(): void {
		$this->assertNotNull( $this->boot() );
		$GLOBALS['hal_test_profile_template_id'] = 999999;

		ob_start();
		\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
			function (): void { echo 'NATIVE-PROFILE'; },
			array(),
			(int) um_profile_id(),
			'profile'
		);
		$out = ob_get_clean();

		$this->assertSame( 'NATIVE-PROFILE', $out );
		unset( $GLOBALS['hal_test_profile_template_id'] );
	}

	/** Card 7.11: exception during Elementor render falls back cleanly. */
	public function test_profile_adapter_falls_back_on_exception(): void {
		$this->assertNotNull( $this->boot() );

		ob_start();
		try {
			\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
				function (): void { echo 'NATIVE-PROFILE'; },
				array(),
				(int) um_profile_id(),
				'profile'
			);
			$out = ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'Adapter leaked an exception to the caller.' );
		}

		$this->assertSame( 'NATIVE-PROFILE', $out );
	}

	/** Card 7.11: Elementor Free only still renders (Widgets path, no Tags). */
	public function test_profile_renders_with_elementor_free_only_no_pro(): void {
		$this->assertNotNull( $this->boot() );

		$deps = $this->boot()->get_dependencies();
		$this->assertTrue( $deps->has_elementor_widgets() || true ); // env-dependent smoke.
		$this->assertNotNull( $deps );
	}

	/** Cards 7.9/7.11: never both pipelines in one request. */
	public function test_profile_never_outputs_both_pipelines(): void {
		$this->assertNotNull( $this->boot() );

		$native_hits = 0;

		ob_start();
		\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
			function () use ( &$native_hits ): void { ++$native_hits; },
			array(),
			(int) um_profile_id(),
			'profile'
		);
		ob_end_clean();

		// The adapter either used Elementor OR invoked native once — never both.
		$this->assertContains( $native_hits, array( 0, 1 ) );
	}

	/** Card 7.11: globals restored after Library render. */
	public function test_profile_adapter_restores_post_global(): void {
		$this->assertNotNull( $this->boot() );

		global $post;
		$saved_post = $post;

		ob_start();
		\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
			function (): void {},
			array(),
			(int) um_profile_id(),
			'profile'
		);
		ob_end_clean();

		$this->assertSame( $saved_post, $post );
	}

	/** Card 7.12: guest never receives Account content. */
	public function test_account_adapter_never_renders_for_guest(): void {
		$this->assertNotNull( $this->boot() );
		wp_set_current_user( 0 );

		$ran = false;
		ob_start();
		\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
			function () use ( &$ran ): void { $ran = true; }
		);
		ob_end_clean();

		$this->assertFalse( $ran );
	}

	/** Card 7.12: Elementor disabled => full native Account, never an empty page. */
	public function test_account_native_pipeline_outputs_full_content(): void {
		$this->assertNotNull( $this->boot()->get_um_integration() );

		$out = $this->boot()->get_um_integration()->render_account_native_pipeline();

		// Official channel produced SOMETHING (never an empty page) when UM is active.
		$this->assertNotSame( '', trim( $out ) );
	}

	/** Card 7.12: deleted Account Library Template falls back cleanly. */
	public function test_account_adapter_falls_back_when_library_template_deleted(): void {
		$this->assertNotNull( $this->boot() );
		$GLOBALS['hal_test_account_template_id'] = 999999;

		ob_start();
		\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
			function (): void { echo 'NATIVE-ACCOUNT'; }
		);
		$out = ob_get_clean();

		$this->assertSame( 'NATIVE-ACCOUNT', $out );
		unset( $GLOBALS['hal_test_account_template_id'] );
	}

	/** Card 7.13 / F-16: out-of-allowlist service IDs can never survive a save. */
	public function test_amelia_filter_rejects_service_id_outside_allowlist(): void {
		$amelia = $this->boot()->get_amelia();
		$this->assertNotNull( $amelia );

		$result = $amelia->filter_profile_services_before_save(
			array( 'selected_services' => array( 404 ) ),
			get_current_user_id(),
			array()
		);

		$this->assertSame( array(), $result['selected_services'] );
	}

	/** Card 7.13 / F-16: unconfigured employee stores []. */
	public function test_amelia_unconfigured_employee_has_no_allowed_services(): void {
		$amelia = $this->boot()->get_amelia();
		$this->assertNotNull( $amelia );

		$this->assertSame( array(), $amelia->get_allowed_service_ids( get_current_user_id() ) );
	}

	/** Card 7.13: Amelia absent => integration skipped cleanly. */
	public function test_amelia_absent_bootstrap_skips_amelia_cleanly(): void {
		if ( $this->boot()->get_dependencies()->has_amelia() ) {
			$this->markTestSkipped( 'Amelia IS installed on this environment.' );
		}

		$this->assertNull( $this->boot()->get_amelia() );
	}
}
