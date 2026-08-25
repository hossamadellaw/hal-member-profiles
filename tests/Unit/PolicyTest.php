<?php
/**
 * Unit tests for Policy — access/privacy gates, typed values, and escaping.
 *
 * Runs WITHOUT WordPress against tests/Fixtures/wp-stubs.php: the stubs are transport
 * only; every assertion below verifies HAL's own documented decisions (official UM
 * privacy semantics per remediation F-07 and includes/Integrations/Amelia.php contract).
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\FieldSchema;
use HAL\MemberProfiles\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {

	private Policy $policy;

	public function setUp(): void {
		$this->policy = new Policy( new FieldSchema() );
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['user_meta'] );
		unset( $GLOBALS['wp_stubs']['post_meta'] );
		unset( $GLOBALS['wp_stubs']['users'] );
		unset( $GLOBALS['wp_stubs']['current_user'] );
		unset( $GLOBALS['wp_stubs']['can_manage'] );
		unset( $GLOBALS['wp_stubs']['private_map'] );
		unset( $GLOBALS['wp_stubs']['can_edit_map'] );
		unset( $GLOBALS['wp_stubs']['um_options'] );
	}

	private function privacy_decision_for( int $viewer_id, array $field ): bool {
		$m = new \ReflectionMethod( $this->policy, 'passes_privacy' );
		$m->setAccessible( true );

		return (bool) $m->invoke( $this->policy, 5, $viewer_id, $field );
	}

	private function seed_users(): void {
		$GLOBALS['wp_stubs']['users'] = array(
			5 => array( 'roles' => array( 'owner_role' ), 'display_name' => 'Profile Owner' ),
			6 => array( 'roles' => array( 'plain_member' ), 'display_name' => 'Other Member' ),
			7 => array( 'roles' => array( 'subscriber' ), 'display_name' => 'Sub Member' ),
		);
	}

	/**
	 * Card 7.8 acceptance: public/member/owner/admin decisions for each level.
	 */
	public function test_everyone_level_visible_to_guest_and_member(): void {
		$this->seed_users();
		$field = array( 'public' => '1' );

		$this->assertTrue( $this->privacy_decision_for( 0, $field ) );
		$this->assertTrue( $this->privacy_decision_for( 6, $field ) );
	}

	public function test_members_level_hidden_from_guest_visible_to_member(): void {
		$this->seed_users();
		$field = array( 'public' => '2' );

		$this->assertFalse( $this->privacy_decision_for( 0, $field ) );
		$this->assertTrue( $this->privacy_decision_for( 6, $field ) );
	}

	public function test_owner_editors_level_denies_stranger_allows_editor_and_owner(): void {
		$this->seed_users();
		$field = array( 'public' => '-1' );

		$this->assertFalse( $this->privacy_decision_for( 0, $field ) );
		$this->assertFalse( $this->privacy_decision_for( 6, $field ) );

		$GLOBALS['wp_stubs']['can_edit_map'] = array( 5 => true );
		$this->assertTrue( $this->privacy_decision_for( 6, $field ) );

		$GLOBALS['wp_stubs']['can_edit_map'] = array();
		$this->assertTrue( $this->privacy_decision_for( 5, $field ) );
	}

	public function test_roles_only_level_has_no_owner_exception(): void {
		$this->seed_users();
		// Official '-2': role intersection ONLY — an owner without the role is denied too.
		$field = array( 'public' => '-2', 'roles' => array( 'subscriber' ) );

		$this->assertFalse( $this->privacy_decision_for( 0, $field ) );
		$this->assertFalse( $this->privacy_decision_for( 6, $field ) );
		$this->assertTrue( $this->privacy_decision_for( 7, $field ) );
		$this->assertFalse( $this->privacy_decision_for( 5, $field ) ); // owner lacks role.
	}

	public function test_owner_roles_level_allows_owner_or_matching_role(): void {
		$this->seed_users();
		$field = array( 'public' => '-3', 'roles' => array( 'subscriber' ) );

		$this->assertTrue( $this->privacy_decision_for( 5, $field ) );   // owner bypass.
		$this->assertFalse( $this->privacy_decision_for( 6, $field ) );
		$this->assertTrue( $this->privacy_decision_for( 7, $field ) );
		$this->assertFalse( $this->privacy_decision_for( 0, $field ) );
	}

	public function test_admin_sees_every_level_including_unknown(): void {
		$this->seed_users();
		$GLOBALS['wp_stubs']['can_manage'] = true;

		foreach ( array( '1', '2', '-1', '-2', '-3', '99', '' ) as $level ) {
			$field = '99' === $level || '' === $level
				? array( 'public' => $level )
				: array( 'public' => $level, 'roles' => array() );
			$this->assertTrue( $this->privacy_decision_for( 0, $field ), "admin + {$level}" );
		}

		$GLOBALS['wp_stubs']['can_manage'] = false;
	}

	/**
	 * Card 7.8 / F-07: unknown values deny; legacy positives 3/4/5 are unknown now.
	 */
	public function test_unrecognized_privacy_values_fail_closed(): void {
		$this->seed_users();

		foreach ( array( '99', 'x', '', 3, 4, 5, -7, null, array( '1' ), true, 1.5 ) as $bad ) {
			$this->assertFalse(
				$this->privacy_decision_for( 7, array( 'public' => $bad ) ),
				'value: ' . var_export( $bad, true )
			);
		}
	}

	public function test_missing_public_key_defaults_to_everyone(): void {
		$this->seed_users();
		$this->assertTrue( $this->privacy_decision_for( 0, array( 'type' => 'text' ) ) );
	}

	/**
	 * Card 7.8 acceptance: malicious URL is rejected by the URL formatter.
	 */
	public function test_url_format_rejects_non_http_schemes(): void {
		$m = new \ReflectionMethod( $this->policy, 'format_url' );
		$m->setAccessible( true );

		$this->assertNull( $m->invoke( $this->policy, 'javascript:alert(1)' ) );
		$this->assertNull( $m->invoke( $this->policy, 'data:text/html,x' ) );

		$ok = $m->invoke( $this->policy, 'https://example.test/page' );
		$this->assertSame( 'https://example.test/page', $ok['value'] ?? null );
	}

	// ------------------------------------------------------------------
	// Header element rules (F-07 fail-closed contract).
	// ------------------------------------------------------------------

	private function seed_form_77( array $fields ): void {
		$GLOBALS['wp_stubs']['post_meta'][77]['_um_custom_fields'] = $fields;
	}

	private function header( string $element, int $viewer ): ?array {
		return $this->policy->can_view_header_element( 5, $viewer, $element, 77 );
	}

	public function test_header_requires_a_positive_form_id_even_for_admin(): void {
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$GLOBALS['wp_stubs']['users']      = array( 5 => array( 'roles' => array(), 'display_name' => 'O' ) );

		$this->assertNull( $this->policy->can_view_header_element( 5, 7, 'name', 0 ) );
		$this->assertNull( $this->policy->can_view_header_element( 5, 7, 'bio', -1 ) );

		$GLOBALS['wp_stubs']['can_manage'] = false;
	}

	public function test_header_element_without_form_definition_fails_closed(): void {
		// Old behavior allowed undefined elements; F-07 inverts this to fail-closed.
		$this->seed_form_77( array() );

		$this->assertNull( $this->header( 'name', 6 ) );
		$this->assertNull( $this->header( 'avatar', 6 ) );
		$this->assertNull( $this->header( 'cover', 6 ) );
	}

	public function test_bio_blocked_by_general_settings_then_field_privacy(): void {
		$GLOBALS['wp_stubs']['um_options'] = array( 'profile_show_bio' => false );
		$this->seed_form_77( array( 'description' => array( 'metakey' => 'description', 'type' => 'textarea', 'public' => '1' ) ) );
		$GLOBALS['wp_stubs']['user_meta'][5]['description'] = 'Bio text';

		// General settings OFF: blocked even though the field is defined and public.
		$this->assertNull( $this->header( 'bio', 6 ) );

		// General settings ON + public field: visible to guest.
		$GLOBALS['wp_stubs']['um_options']['profile_show_bio'] = true;
		$allowed = $this->header( 'bio', 0 );
		$this->assertSame( 'Bio text', $allowed['value'] ?? null );

		// ON but '-1' field: stranger denied, editor allowed.
		$GLOBALS['wp_stubs']['can_edit_map']                              = array();
		$GLOBALS['wp_stubs']['post_meta'][77]['_um_custom_fields']['description']['public'] = '-1';
		$this->assertNull( $this->header( 'bio', 6 ) );
		$GLOBALS['wp_stubs']['can_edit_map'] = array( 5 => true );
		$this->assertSame( 'Bio text', $this->header( 'bio', 6 )['value'] ?? null );
	}

	public function test_name_requires_at_least_one_defined_name_field_and_passing_privacy(): void {
		$this->seed_users();
		$this->seed_form_77( array() );
		$this->assertNull( $this->header( 'name', 6 ) ); // none defined.

		$this->seed_form_77( array( 'last_name' => array( 'metakey' => 'last_name', 'type' => 'text', 'public' => '2' ) ) );
		$this->assertNull( $this->header( 'name', 0 ) );   // guest vs members-only name.
		$this->assertSame( 'Profile Owner', $this->header( 'name', 6 )['value'] ?? null );
	}

	public function test_avatar_and_cover_fail_closed_without_their_form_fields(): void {
		$this->seed_form_77( array() );
		$this->assertNull( $this->header( 'avatar', 6 ) );
		$this->assertNull( $this->header( 'cover', 6 ) );
	}
}
