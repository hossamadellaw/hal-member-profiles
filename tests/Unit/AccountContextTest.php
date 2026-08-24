<?php
/**
 * Unit tests for AccountContext — the single source of truth for the account owner.
 *
 * Requires WordPress + Ultimate Member (um_is_core_page, is_user_logged_in,
 * UM()->account(), etc.), so these are marked incomplete until run inside the real
 * WordPress test environment described in tests/bootstrap.php (WP_TESTS_DIR), with UM
 * active.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccountContextTest extends TestCase {

	/**
	 * Card 7.6 acceptance: guest.
	 */
	public function test_guest_resolves_to_null(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.6 acceptance: regular member.
	 */
	public function test_logged_in_member_resolves_to_their_own_account(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.6 acceptance: admin — still only their own account, never another member's.
	 */
	public function test_admin_resolves_to_their_own_account_only(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.6 acceptance: switching tab.
	 */
	public function test_current_tab_reflects_um_accounts_own_resolved_tab(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.6 prohibition: never accepts a user_id from the request.
	 */
	public function test_resolve_ignores_a_user_id_query_parameter(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; simulate $_GET[\'user_id\'] set to another member and assert it has no effect. Run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.6: fixture never silently defaults to the current site manager's own account.
	 */
	public function test_editor_fixture_unset_does_not_fall_back_to_managers_account(): void {
		$this->markTestIncomplete( 'Requires WordPress + Elementor + UM; run under WP_TESTS_DIR.' );
	}
}
