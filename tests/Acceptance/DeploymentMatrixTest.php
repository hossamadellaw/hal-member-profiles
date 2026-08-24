<?php
/**
 * Acceptance tests: the deployment matrix — guest/member/owner/admin, Elementor Pro
 * absent, fallback behavior, and no duplicate Profile/Account pipeline.
 *
 * Requires a real, independent WordPress test environment on staging or a clean local
 * WordPress install, with the Ultimate Member, Elementor, and Amelia versions recorded
 * in docs/compatibility-matrix.md (see tests/bootstrap.php, WP_TESTS_DIR). No CI is
 * claimed here; wiring an actual CI workflow is a separate, later decision.
 *
 * @package HAL\MemberProfiles\Tests\Acceptance
 */

namespace HAL\MemberProfiles\Tests\Acceptance;

use PHPUnit\Framework\TestCase;

final class DeploymentMatrixTest extends TestCase {

	// --- Profile: viewer role matrix -----------------------------------------------

	public function test_guest_views_public_profile_fields_only(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	public function test_logged_in_member_views_member_only_profile_fields(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	public function test_owner_views_all_own_profile_fields(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	public function test_admin_views_every_profile_field_regardless_of_privacy(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	// --- Account: viewer role matrix -------------------------------------------------

	public function test_guest_is_denied_account_page(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging; run under WP_TESTS_DIR.' );
	}

	public function test_member_views_only_their_own_account(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging; run under WP_TESTS_DIR.' );
	}

	// --- Elementor Pro absence --------------------------------------------------------

	public function test_dynamic_tags_are_not_registered_without_elementor_pro(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor (no Pro) on staging; run under WP_TESTS_DIR.' );
	}

	public function test_widgets_still_register_and_render_without_elementor_pro(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor (no Pro) on staging; run under WP_TESTS_DIR.' );
	}

	public function test_admin_notice_shown_when_elementor_pro_absent(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor (no Pro) on staging, viewed as a manage_options user; run under WP_TESTS_DIR.' );
	}

	// --- Fallback behavior -------------------------------------------------------------

	public function test_profile_falls_back_to_native_when_layout_mode_is_observe(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging (Settings default: observe); run under WP_TESTS_DIR.' );
	}

	public function test_profile_falls_back_to_native_when_layout_contract_invalid(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging (Library Template missing a required Widget); run under WP_TESTS_DIR.' );
	}

	public function test_account_falls_back_to_native_when_hal_plugin_inactive(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging (HAL Member Profiles deactivated); run under WP_TESTS_DIR.' );
	}

	// --- No duplicate pipeline ----------------------------------------------------------

	public function test_profile_page_never_renders_header_twice(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; assert exactly one header block in output. Run under WP_TESTS_DIR.' );
	}

	public function test_account_page_never_renders_tabs_twice(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; assert exactly one nav block in output. Run under WP_TESTS_DIR.' );
	}
}
