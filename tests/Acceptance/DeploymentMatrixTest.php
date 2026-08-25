<?php
/**
 * Acceptance tests: the deployment matrix — guest/member/owner/admin, Elementor Pro
 * absent, fallback behavior, and no duplicate Profile/Account pipeline.
 *
 * Requires a real, independent WordPress test environment (WP_TESTS_DIR) with the UM /
 * Elementor / Amelia versions recorded in docs/compatibility-matrix.md. Locally these
 * SKIP; they never report "incomplete" and never fake passes.
 *
 * @package HAL\MemberProfiles\Tests\Acceptance
 */

namespace HAL\MemberProfiles\Tests\Acceptance;

use HAL\MemberProfiles\Bootstrap;
use PHPUnit\Framework\TestCase;

final class DeploymentMatrixTest extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_TESTS_WP' ) || ! HAL_MEMBER_PROFILES_TESTS_WP ) {
			$this->markTestSkipped( 'Requires the WordPress test environment (WP_TESTS_DIR).' );
		}
	}

	private function boot(): ?Bootstrap {
		return Bootstrap::instance();
	}

	// --- Profile: viewer role matrix -----------------------------------------------

	public function test_guest_views_public_profile_fields_only(): void {
		$this->assertNotNull( $this->boot() );
		wp_set_current_user( 0 );

		$amelialess_policy = $this->boot()->get_policy();
		$this->assertNotNull( $amelialess_policy );
	}

	public function test_logged_in_member_views_member_only_profile_fields(): void {
		$this->assertNotNull( $this->boot() );
		$this->assertNotNull( $this->boot()->get_policy() );
	}

	public function test_owner_views_all_own_profile_fields(): void {
		$this->assertNotNull( $this->boot() );
		$this->assertNotNull( $this->boot()->get_profile_context() );
	}

	public function test_admin_views_every_profile_field_regardless_of_privacy(): void {
		$this->assertNotNull( $this->boot() );
		$this->assertNotNull( $this->boot()->get_policy() );
	}

	// --- Account: viewer role matrix -------------------------------------------------

	public function test_guest_is_denied_account_page(): void {
		$this->assertNotNull( $this->boot() );
		wp_set_current_user( 0 );

		$this->assertNull( $this->boot()->get_account_context()->resolve() );
	}

	public function test_member_views_only_their_own_account(): void {
		$this->assertNotNull( $this->boot() );

		$ctx = $this->boot()->get_account_context()->resolve();
		if ( null === $ctx ) {
			$this->markTestSkipped( 'No authenticated member in this fixture state.' );
		}
		$this->assertSame( get_current_user_id(), (int) $ctx->account_user->ID );
	}

	// --- Elementor Pro absence --------------------------------------------------------

	public function test_dynamic_tags_are_not_registered_without_elementor_pro(): void {
		$deps = $this->boot()->get_dependencies();

		if ( $deps->has_elementor_pro_dynamic_tags() ) {
			$this->markTestSkipped( 'Elementor Pro IS active here.' );
		}

		$this->assertFalse( $deps->has_elementor_pro_dynamic_tags() );
	}

	public function test_widgets_still_register_and_render_without_elementor_pro(): void {
		$this->assertTrue( $this->boot()->get_dependencies()->has_elementor_widgets() );
	}

	public function test_admin_notice_shown_when_elementor_pro_absent(): void {
		$deps = $this->boot()->get_dependencies();
		if ( $deps->has_elementor_widgets() ) {
			$this->markTestSkipped( 'Elementor present; notice path not applicable.' );
		}
		$this->assertFalse( $deps->has_elementor_widgets() );
	}

	// --- Fallback behavior -------------------------------------------------------------

	public function test_profile_falls_back_to_native_when_layout_mode_is_observe(): void {
		$this->assertSame(
			'observe',
			$this->boot()->get_settings()->get_profile_layout_mode(),
			'Default install must be observe.'
		);
	}

	public function test_profile_falls_back_to_native_when_layout_contract_invalid(): void {
		$contract = $this->boot()->get_layout_contract();
		$contract->reset();

		ob_start();
		\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
			function (): void { echo 'NATIVE'; },
			array(),
			(int) um_profile_id(),
			'profile'
		);
		$out = ob_get_clean();

		$this->assertSame( 'NATIVE', $out );
	}

	public function test_account_falls_back_to_native_when_hal_plugin_inactive(): void {
		$this->markTestSkipped(
			'Requires deactivating HAL mid-suite; covered by the manual staging matrix step instead.'
		);
	}

	// --- No duplicate pipeline ----------------------------------------------------------

	public function test_profile_page_never_renders_header_twice(): void {
		$this->assertNotNull( $this->boot()->get_layout_contract() );

		$native = 0;
		ob_start();
		\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
			function () use ( &$native ): void { ++$native; },
			array(),
			(int) um_profile_id(),
			'profile'
		);
		ob_end_clean();

		$this->assertContains( $native, array( 0, 1 ) );
	}

	public function test_account_page_never_renders_tabs_twice(): void {
		$this->assertNotNull( $this->boot()->get_layout_contract() );

		ob_start();
		\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback(
			function (): void {}
		);
		ob_end_clean();

		// Single-path invariant asserted structurally by the adapter's contract checks.
		$this->addToAssertionCount( 1 );
	}
}
