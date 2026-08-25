<?php
/**
 * Unit tests for AccountContext — the account owner source of truth (card 7.6).
 * Runs without WordPress against tests/Fixtures/wp-stubs.php.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AccountContext;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class AccountContextTest extends TestCase {

	private AccountContext $context;

	public function setUp(): void {
		$this->context = new AccountContext( new Settings() );
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['is_page'] );
		unset( $GLOBALS['wp_stubs']['logged_in'] );
		unset( $GLOBALS['wp_stubs']['current_user'] );
		unset( $GLOBALS['wp_stubs']['users'] );
		unset( $_GET['user_id'] );
	}

	private function seed_member_account(): void {
		$GLOBALS['wp_stubs']['is_page']  = array( 'account' => true );
		$GLOBALS['wp_stubs']['users']    = array( 7 => array( 'roles' => array( 'subscriber' ), 'display_name' => 'Member' ) );
		$GLOBALS['viewer_backup']        = null;
	}

	/** Card 7.6: guest resolves to null. */
	public function test_guest_resolves_to_null(): void {
		$this->seed_member_account();
		$GLOBALS['wp_stubs']['current_user'] = 0;

		$this->assertNull( $this->context->resolve() );
	}

	/** Card 7.6: a member resolves to THEIR OWN account object only. */
	public function test_logged_in_member_resolves_to_their_own_account(): void {
		$this->seed_member_account();
		$GLOBALS['wp_stubs']['current_user'] = 7;

		$ctx = $this->context->resolve();

		$this->assertNotNull( $ctx );
		$this->assertSame( 7, (int) $ctx->account_user->ID );
	}

	/** Card 7.6: an admin still only ever gets their own account, never another member's. */
	public function test_admin_resolves_to_their_own_account_only(): void {
		$this->seed_member_account();
		$GLOBALS['wp_stubs']['users'][1] = array( 'roles' => array( 'administrator' ), 'display_name' => 'Admin' );
		$GLOBALS['wp_stubs']['current_user'] = 1;
		$_GET['user_id'] = 7; // forgery attempt: must be ignored entirely.

		$ctx = $this->context->resolve();

		$this->assertNotNull( $ctx );
		$this->assertSame( 1, (int) $ctx->account_user->ID );
	}

	/** Card 7.6: the active tab comes from UM's own resolved state. */
	public function test_current_tab_reflects_um_accounts_own_resolved_tab(): void {
		$this->seed_member_account();
		$GLOBALS['wp_stubs']['current_user'] = 7;

		$this->assertSame( 'general', $this->context->resolve()->current_tab );
	}

	/** Card 7.6: outside the account page there is no context at all. */
	public function test_non_account_page_resolves_to_null_even_logged_in(): void {
		$GLOBALS['um_page_is_account_backup'] = true;
		$GLOBALS['wp_stubs']['is_page']       = array( 'account' => false );
		$GLOBALS['wp_stubs']['current_user']  = 7;

		$this->assertNull( $this->context->resolve() );
	}
}
