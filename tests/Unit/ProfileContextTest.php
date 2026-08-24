<?php
/**
 * Unit tests for ProfileContext — the single source of truth for the public profile owner.
 *
 * Requires WordPress + Ultimate Member (um_is_core_page, um_profile_id, UM()->user(),
 * etc.), so these are marked incomplete until run inside the real WordPress test
 * environment described in tests/bootstrap.php (WP_TESTS_DIR), with UM active.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProfileContextTest extends TestCase {

	/**
	 * Card 7.5 acceptance: visitor viewing another member.
	 */
	public function test_guest_can_resolve_a_public_members_profile(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 acceptance: owner.
	 */
	public function test_owner_viewing_own_profile_sets_is_owner_true(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 acceptance: guest.
	 */
	public function test_guest_visitor_id_is_zero(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 acceptance: editor without a fixture returns null, not a fallback user.
	 */
	public function test_elementor_editor_without_fixture_returns_null(): void {
		$this->markTestIncomplete( 'Requires WordPress + Elementor + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 acceptance: invalid link (no resolvable profile) returns null.
	 */
	public function test_invalid_profile_link_returns_null(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 prohibition: never falls back to the current user outside a real profile
	 * page (the documented um_profile_id() quirk this class guards against).
	 */
	public function test_never_falls_back_to_current_user_on_a_non_profile_page(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM, on a page that is not um_is_core_page("user"); run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.5 prohibition: never mutates the global um_user object.
	 */
	public function test_resolve_never_calls_um_fetch_user(): void {
		$this->markTestIncomplete( 'Requires WordPress + UM; assert global um_user is unchanged before/after resolve(). Run under WP_TESTS_DIR.' );
	}

	/**
	 * Editor fixture path: only a manage_options user with a Settings-configured fixture
	 * ID sees a fixture; never shown on frontend/general preview.
	 */
	public function test_editor_fixture_requires_manage_options(): void {
		$this->markTestIncomplete( 'Requires WordPress + Elementor + UM; run under WP_TESTS_DIR.' );
	}
}
