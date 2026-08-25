<?php
/**
 * Unit tests for ProfileContext — the profile owner source of truth and the request
 * scope (remediation F-08). Runs without WordPress against tests/Fixtures/wp-stubs.php.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\ProfileContext;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class ProfileContextTest extends TestCase {

	private ProfileContext $context;

	public function setUp(): void {
		$this->context = new ProfileContext( new Settings() );
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['user_meta'] );
		unset( $GLOBALS['wp_stubs']['users'] );
		unset( $GLOBALS['wp_stubs']['current_user'] );
		unset( $GLOBALS['wp_stubs']['can_manage'] );
		unset( $GLOBALS['wp_stubs']['private_map'] );
		unset( $GLOBALS['wp_stubs']['is_page'] );
		unset( $GLOBALS['wp_stubs']['profile_id'] );
		unset( $GLOBALS['wp_stubs']['options'] );
	}

	private function seed_live_profile( bool $private = false ): void {
		$GLOBALS['wp_stubs']['is_page']      = array( 'user' => true );
		$GLOBALS['wp_stubs']['profile_id']   = 5;
		$GLOBALS['wp_stubs']['users']        = array( 5 => array( 'roles' => array(), 'display_name' => 'Owner' ) );
		$GLOBALS['wp_stubs']['private_map']  = array( 5 => $private );
	}

	/** Card 7.5: guest on a public member's profile resolves that member, not himself. */
	public function test_guest_resolves_public_member_profile_with_zero_visitor(): void {
		$this->seed_live_profile( false );

		$ctx = $this->context->resolve();

		$this->assertNotNull( $ctx );
		$this->assertSame( 5, (int) $ctx->target_user->ID );
		$this->assertSame( 0, (int) $ctx->visitor_id );
		$this->assertFalse( $ctx->is_owner );
	}

	public function test_owner_viewing_own_profile_sets_is_owner_true(): void {
		$this->seed_live_profile( true ); // even a PRIVATE profile.
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$ctx = $this->context->resolve();

		$this->assertNotNull( $ctx );
		$this->assertTrue( $ctx->is_owner );
	}

	/**
	 * F-08 #1 fail-closed: privacy verdict unavailable => null target, never "public".
	 */
	public function test_unavailable_privacy_verdict_returns_null_not_public(): void {
		$this->seed_live_profile( false );
		$GLOBALS['wp_stubs']['private_map'] = '__UNAVAILABLE__';

		$this->assertNull( $this->context->resolve() );
	}

	public function test_private_profile_denies_stranger_allows_admin(): void {
		$this->seed_live_profile( true );

		$this->assertNull( $this->context->resolve() );

		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->assertNotNull( $this->context->resolve() );
	}

	/** Card 7.5: non-profile page never falls back to current user. */
	public function test_non_profile_page_never_falls_back_to_current_user(): void {
		$GLOBALS['wp_stubs']['is_page']      = array( 'user' => false );
		$GLOBALS['wp_stubs']['current_user'] = 9;
		$GLOBALS['wp_stubs']['users'][9]     = array( 'roles' => array(), 'display_name' => 'Manager' );

		$this->assertNull( $this->context->resolve() );
	}

	/** F-08 scope: bare resolve() inherits the verified form id; nested restores. */
	public function test_scope_keeps_verified_form_id_for_bare_resolve_and_restores(): void {
		$this->seed_live_profile( false );

		$this->assertTrue( $this->context->enter_scope( array(), 77, 'profile' ) );
		$this->assertSame( 77, $this->context->resolve()->form_id );

		$this->assertTrue( $this->context->enter_scope( array(), 99, 'edit' ) );
		$this->assertSame( 99, $this->context->resolve()->form_id );

		$this->assertTrue( $this->context->exit_scope() );
		$this->assertSame( 77, $this->context->resolve()->form_id );

		$this->assertTrue( $this->context->exit_scope() );
		$this->assertSame( 0, $this->context->resolve()->form_id );
		$this->assertFalse( $this->context->exit_scope() ); // draining is safe.
	}

	/** F-08: editor fixture requires manage_options; unset fixture never defaults to manager. */
	public function test_editor_fixture_requires_manage_options_and_real_fixture(): void {
		\Elementor\Plugin::$instance           = new \Elementor\Plugin();
		\Elementor\Plugin::$instance->editor   = new class { public function is_edit_mode() { return true; } };
		\Elementor\Plugin::$instance->preview  = new class { public function is_preview_mode() { return false; } };

		// Not an admin: no fixture.
		$this->assertNull( $this->context->resolve( array(), 77, '' ) );

		// Admin but no fixture configured: still null — never the manager's own account.
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->assertNull( $this->context->resolve( array(), 77, '' ) );

		// Admin + configured fixture user: fixture context with the configured ID.
		$GLOBALS['wp_stubs']['users'][42]    = array( 'roles' => array(), 'display_name' => 'Fixture' );
		$GLOBALS['wp_stubs']['options']      = array(
			Settings::OPTION_KEY => array( 'profile_fixture_user_id' => 42 ),
		);

		$ctx = $this->context->resolve( array(), 77, '' );
		$this->assertNotNull( $ctx );
		$this->assertSame( 42, (int) $ctx->target_user->ID );

		\Elementor\Plugin::$instance = null;
	}
}
