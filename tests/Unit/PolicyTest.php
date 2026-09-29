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
		add_filter(
			'um_get_form_fields',
			static function ( $fields, $form_id ) {
				unset( $fields );
				return get_post_meta( (int) $form_id, '_um_custom_fields', true );
			},
			99,
			2
		);
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['user_meta'] );
		unset( $GLOBALS['wp_stubs']['user_meta_reads'] );
		unset( $GLOBALS['wp_stubs']['post_meta'] );
		unset( $GLOBALS['wp_stubs']['users'] );
		unset( $GLOBALS['wp_stubs']['current_user'] );
		unset( $GLOBALS['wp_stubs']['can_manage'] );
		unset( $GLOBALS['wp_stubs']['private_map'] );
		unset( $GLOBALS['wp_stubs']['can_edit_map'] );
		unset( $GLOBALS['wp_stubs']['um_options'] );
		unset( $GLOBALS['wp_stubs']['um_builtin_fields'] );
		unset( $GLOBALS['wp_stubs']['um_target'] );
		remove_all_filters( 'um_get_form_fields' );
		remove_all_filters( 'um_account_page_default_tabs_hook' );
		remove_all_filters( 'um_account_tab_general_fields' );
		remove_all_filters( 'um_account_tab_privacy_fields' );
		remove_all_filters( 'um_can_view_field' );
	}

	public function test_account_field_lookup_enforces_current_tab_scope(): void {
		$GLOBALS['wp_stubs']['um_builtin_fields'] = array(
			'general_note' => array( 'title' => 'General note', 'type' => 'text', 'public' => '1' ),
			'privacy_note' => array( 'title' => 'Privacy note', 'type' => 'text', 'public' => '1' ),
			'locked_note'  => array( 'title' => 'Locked note', 'type' => 'text', 'public' => '1', 'editable' => false ),
		);

		add_filter(
			'um_account_page_default_tabs_hook',
			static fn (): array => array( 100 => array( 'general' => array(), 'privacy' => array() ) )
		);
		add_filter( 'um_account_tab_general_fields', static fn (): string => 'general_note,locked_note' );
		add_filter( 'um_account_tab_privacy_fields', static fn (): string => 'privacy_note' );
		$schema = new FieldSchema();
		apply_filters( 'um_account_page_default_tabs_hook', array( 100 => array( 'general' => array(), 'privacy' => array() ) ) );
		apply_filters( 'um_account_tab_general_fields', 'general_note,locked_note', array() );
		apply_filters( 'um_account_tab_privacy_fields', 'privacy_note', array() );

		$GLOBALS['wp_stubs']['current_user'] = 5;
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['users'][5]     = array( 'ID' => 5 );
		$GLOBALS['wp_stubs']['user_meta'][5]['general_note'] = 'General value';
		$GLOBALS['wp_stubs']['user_meta'][5]['locked_note']  = 'Must not be read';

		$policy   = new Policy( $schema );
		$same_tab = $policy->can_view_account_field( 5, 5, 'general_note', 'general' );

		$this->assertIsArray( $same_tab );
		$this->assertSame( 'General value', $same_tab['value'] );
		$this->assertSame( 1, $GLOBALS['wp_stubs']['user_meta_reads'][5]['general_note'] );
		$this->assertNull( $policy->can_view_account_field( 5, 5, 'locked_note', 'general' ) );
		$this->assertArrayNotHasKey( 'locked_note', $GLOBALS['wp_stubs']['user_meta_reads'][5] );
		$this->assertNull( $policy->can_view_account_field( 5, 5, 'privacy_note', 'general' ) );
		$this->assertNull( $policy->can_view_account_field( 5, 5, 'general_note' ) );
	}

	public function test_profile_field_lookup_rejects_noneditable_before_reading_meta(): void {
		$this->seed_users();
		$this->seed_form_77(
			array(
				'eligible_note' => array( 'type' => 'text', 'public' => '1', 'editable' => true ),
				'locked_note'   => array( 'type' => 'text', 'public' => '1', 'editable' => false ),
			)
		);
		$GLOBALS['wp_stubs']['current_user'] = 5;
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['user_meta'][5]['eligible_note'] = 'Eligible value';
		$GLOBALS['wp_stubs']['user_meta'][5]['locked_note']   = 'Must not be read';

		$eligible = $this->policy->can_view_field( 5, 5, 77, 'eligible_note' );

		$this->assertSame( 'Eligible value', $eligible['value'] ?? null );
		$this->assertSame( 1, $GLOBALS['wp_stubs']['user_meta_reads'][5]['eligible_note'] );
		$this->assertNull( $this->policy->can_view_field( 5, 5, 77, 'locked_note' ) );
		$this->assertArrayNotHasKey( 'locked_note', $GLOBALS['wp_stubs']['user_meta_reads'][5] );
	}

	private function privacy_decision_for( int $viewer_id, array $field ): bool {
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['current_user'] = $viewer_id;
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

		foreach ( array( '99', 'x', '', 3, 4, 5, -7, null, array( '1' ), 1.5 ) as $bad ) {
			$this->assertFalse(
				$this->privacy_decision_for( 7, array( 'public' => $bad ) ),
				'value: ' . var_export( $bad, true )
			);
		}
	}

	public function test_um_field_visibility_filter_remains_authoritative(): void {
		$this->seed_users();
		add_filter( 'um_can_view_field', static fn( $allowed ) => false, 10, 1 );

		$this->assertFalse( $this->privacy_decision_for( 0, array( 'public' => '1' ) ) );
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
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['current_user'] = $viewer;
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
