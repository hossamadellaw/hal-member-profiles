<?php
/**
 * Unit tests for Policy — access/privacy gates, typed values, and escaping.
 *
 * Requires WordPress (get_post, get_user_meta, current_user_can, esc_url, etc.), so these
 * are marked incomplete until run inside the real WordPress test environment described in
 * tests/bootstrap.php (WP_TESTS_DIR), with Ultimate Member active. No hand-written
 * WordPress function stubs are used here — an incorrect stub would produce a false pass on
 * exactly the privacy-critical logic this class exists to gate.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {

	/**
	 * Card 7.8 acceptance: public/member/owner/admin decisions for each privacy level.
	 */
	public function test_can_view_field_everyone_visible_to_guest(): void {
		$this->markTestIncomplete( 'Requires WordPress + a real UM Form fixture; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_members_only_hidden_from_guest(): void {
		$this->markTestIncomplete( 'Requires WordPress + a real UM Form fixture; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_owner_editors_visible_to_um_edit_capable_user(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM()->roles()->um_current_user_can(); run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_specific_roles_checks_intersection(): void {
		$this->markTestIncomplete( 'Requires WordPress + a real user with a matching/non-matching role; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_owner_always_sees_own_field(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_admin_sees_every_privacy_level(): void {
		$this->markTestIncomplete( 'Requires WordPress + a manage_options user; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_field_unrecognized_privacy_value_fails_closed(): void {
		$this->markTestIncomplete( 'Requires WordPress; verify against docs/compatibility-matrix.md §5 once the real "public" values are confirmed.' );
	}

	public function test_can_view_field_returns_null_for_password_role_status_hidden_email(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.8 acceptance: malicious URL is rejected/escaped.
	 */
	public function test_can_view_field_rejects_javascript_scheme_url(): void {
		$this->markTestIncomplete( 'Requires WordPress (esc_url/wp_parse_url); run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.8 acceptance: an existing private field is actually rejected, not skipped
	 * silently because of a lookup miss.
	 */
	public function test_can_view_field_existing_private_field_is_denied_not_missing(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.8 acceptance: Name/Bio/Cover mimic the active Form's conditions, then general
	 * settings — never an undocumented option like show_avatar.
	 */
	public function test_can_view_header_element_name_bio_cover_follow_form_privacy_when_defined(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	public function test_can_view_header_element_visible_by_default_when_not_defined_in_form(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}
}
