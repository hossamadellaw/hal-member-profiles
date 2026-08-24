<?php
/**
 * Integration tests: Ultimate Member + Elementor + Amelia + Account, together.
 *
 * Requires a real, independent WordPress test environment on staging or a clean local
 * WordPress install, with the Ultimate Member, Elementor, and Amelia versions recorded
 * in docs/compatibility-matrix.md (see tests/bootstrap.php, WP_TESTS_DIR). No CI is
 * claimed here.
 *
 * @package HAL\MemberProfiles\Tests\Integration
 */

namespace HAL\MemberProfiles\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UltimateMemberIntegrationTest extends TestCase {

	/**
	 * Card 7.11 acceptance: deleted/Draft Library Template falls back to Native.
	 */
	public function test_profile_adapter_falls_back_when_library_template_is_draft_or_deleted(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.11 acceptance: an exception during Elementor render falls back cleanly.
	 */
	public function test_profile_adapter_falls_back_on_exception(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.11 acceptance: Elementor Pro absent still renders (Widgets only, no Tags).
	 */
	public function test_profile_renders_with_elementor_free_only_no_pro(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor (no Pro) on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.9/7.11 acceptance: Elementor path and Native pipeline never both execute for
	 * the same request.
	 */
	public function test_profile_never_outputs_both_elementor_and_native_pipeline(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; assert output contains exactly one header/nav/body set. Run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.11 acceptance: global $post/$wp_query are restored after rendering a Library
	 * Template mid-request.
	 */
	public function test_profile_adapter_restores_post_and_wp_query_globals(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.12 acceptance: guest never sees Account content via either path.
	 */
	public function test_account_adapter_never_renders_for_guest(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.12 acceptance: disabling Elementor never leaves an empty Account page.
	 */
	public function test_account_adapter_falls_back_to_native_when_elementor_disabled(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging (Elementor deactivated); run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.12 acceptance: deleted Account Library Template falls back cleanly.
	 */
	public function test_account_adapter_falls_back_when_library_template_deleted(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM + Elementor on staging; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.13 acceptance: a tampered selected_services payload is rejected server-side.
	 */
	public function test_amelia_filter_rejects_service_id_outside_allowlist(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging; simulate um_user_pre_updating_profile_array with an out-of-allowlist service ID. Run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.13 acceptance: an employee with no configured services yields no selection.
	 */
	public function test_amelia_unconfigured_employee_has_no_allowed_services(): void {
		$this->markTestIncomplete( 'Requires WordPress on staging; use tests/Fixtures/fixtures.php catalog shape. Run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.13 acceptance: Amelia absent — Amelia integration never loads or errors.
	 */
	public function test_amelia_absent_bootstrap_skips_amelia_cleanly(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM on staging (Amelia not installed); assert Bootstrap::instance()->get_amelia() is null. Run under WP_TESTS_DIR.' );
	}
}
