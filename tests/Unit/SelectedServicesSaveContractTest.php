<?php
/**
 * Card C5b fixture: the professional service-selection SAVE contract — all seven
 * mandated save-state rows, owner vs ordinary-member behavior, single-hook registration,
 * and proof the S-01-closed direct-save body stays byte-identical. Assertions verify
 * HAL's own contract decisions; transport stubs are transport only. No service IDs or
 * values enter any evidence line.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Integrations\Amelia;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class SelectedServicesSaveContractTest extends TestCase {

	private array $backed_up = array();

	/** Employees meta: user 5 = professional mapped to employee 31 with services 11,12. */
	protected function setUp(): void {
		$this->backed_up = array(
			'options'    => $GLOBALS['wp_stubs']['options'] ?? array(),
			'user_meta'  => $GLOBALS['wp_stubs']['user_meta'] ?? array(),
			'users'      => $GLOBALS['wp_stubs']['users'] ?? array(),
			'filters'    => $GLOBALS['__filters'] ?? array(),
			'is_admin'   => hal_wp_stub_extra( 'is_admin' ),
			'can_manage' => $GLOBALS['wp_stubs']['can_manage'] ?? false,
		);

		$GLOBALS['__hooks']               = array();
		$GLOBALS['__filters']             = array();
		$GLOBALS['wp_stubs']['user_meta'] = array();
		hal_wp_stub_extra_set( 'is_admin', false );

		// Professional persona mapping (C1-owned shape) + UM presence.
		$GLOBALS['wp_stubs']['users'][5] = array( 'roles' => array( 'role_lawyer' ), 'display_name' => 'Owner' );
		$GLOBALS['wp_stubs']['users'][9] = array( 'roles' => array( 'role_member' ), 'display_name' => 'Ordinary' );
		$GLOBALS['wp_stubs']['um_roles'] = array(
			'role_lawyer' => 'Lawyer',
			'role_consultant' => 'Consultant',
			'role_member' => 'Member',
		);
		$GLOBALS['wp_stubs']['um_statuses'] = array( 'approved' => 'Approved' );
		$GLOBALS['wp_stubs']['um_status_by_user'] = array( 5 => 'approved', 9 => 'approved' );
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping' => array(
				Settings::PERSONA_LAWYER          => array( 'role' => 'role_lawyer', 'status' => 'approved' ),
				Settings::PERSONA_CONSULTANT      => array( 'role' => 'role_consultant', 'status' => 'approved' ),
				Settings::PERSONA_ORDINARY_MEMBER => array( 'role' => 'role_member', 'status' => 'approved' ),
			),
		);

		// Employee mapping: user 5 -> employee 31 with assigned services 11,12.
		$GLOBALS['wp_stubs']['user_meta'][5][ Amelia::EMPLOYEE_ID_META_KEY ] = 31;
		$GLOBALS['wp_stubs']['user_meta'][5][ Amelia::EMPLOYEE_SERVICES_META_KEY ] = array( 11, 12 );

		if ( ! defined( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' ) ) {
			define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'C5B-TEST-KEY' );
		}

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['amelia_sync_mode'] = Settings::SYNC_MODE_DISCOVER_ONLY;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']   = $this->backed_up['options'];
		$GLOBALS['wp_stubs']['user_meta'] = $this->backed_up['user_meta'];
		$GLOBALS['wp_stubs']['users']     = $this->backed_up['users'];
		$GLOBALS['__filters']             = $this->backed_up['filters'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];

		foreach ( array( 'um_roles', 'um_statuses', 'um_status_by_user' ) as $key ) {
			unset( $GLOBALS['wp_stubs'][ $key ] );
		}
	}

	private function seed_catalog( array $services, int $version = SchemaRegistry::SNAPSHOT_VERSION ): void {
		$GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ] = array(
			'version'    => $version,
			'fetched_at' => 1,
			'source'     => 'amelia_rest_admin_snapshot',
			'services'   => $services,
			'employees'  => array(),
			'counts'     => array( 'services' => count( $services ), 'employees' => 0 ),
		);
	}

	private function amelia( ?array $catalog_override = null ): Amelia {
		return new Amelia( new Settings(), $catalog_override );
	}

	/** Row 1: valid snapshot + submitted values -> normalize, intersect allowlist, merge stored orphaned. */
	public function test_valid_submit_normalizes_intersects_and_merges_orphaned(): void {
		$this->seed_catalog( array(
			array( 'id' => 11, 'title' => 'Contracts' ),
			array( 'id' => 12, 'title' => 'Consultation' ),
		) );

		// Stored: 11 (still eligible) + 99 (orphaned: outside today's allowlist).
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11, 99 );

		$res = $this->amelia()->apply_selected_services_save( 5, array( '12', 55, 'bogus' ) );

		$this->assertTrue( $res['ok'] );
		// Row 1 literal: submitted normalized -> intersect allowlist; stored non-eligible
		// (orphaned 99) survives via the merge. The stored-eligible 11 was NOT submitted,
		// so it does not re-enter the saved set.
		$this->assertSame( array( 12, 99 ), $res['value'], 'submitted+eligible 12 saved; forged 55/bogus dropped; orphaned stored 99 survives' );
	}

	/** Row 2: explicit empty removes still-eligible stored selections; orphaned stored values SURVIVE. */
	public function test_explicit_empty_keeps_orphaned_stored_selections(): void {
		$this->seed_catalog( array(
			array( 'id' => 11, 'title' => 'Contracts' ),
		) );

		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11, 99 );

		$res = $this->amelia()->apply_selected_services_save( 5, array() );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array( 99 ), $res['value'], 'explicit empty removes the eligible 11 but the orphaned 99 stays stored' );

		// And with NO orphaned stored selections, the explicit-empty result is [].
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11 );

		$res = $this->amelia()->apply_selected_services_save( 5, array() );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array(), $res['value'] );
	}

	/** Row 3: field omitted never reaches the contract — the hook passes the array through untouched. */
	public function test_omitted_field_leaves_previous_value_untouched(): void {
		$this->seed_catalog( array( array( 'id' => 11, 'title' => 'Contracts' ) ) );
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11 );

		$to_update = array( 'display_name' => 'New Name' );

		$out = $this->amelia()->hook_profile_services_save( $to_update, 5 );

		$this->assertArrayNotHasKey( 'selected_services', $out, 'omitted field is not introduced by the hook' );
		$this->assertSame( array( 11 ), $this->amelia()->get_selected_service_ids( 5 ), 'stored value untouched' );
	}

	/** Row 4: catalog unavailable keeps the previous stored value with a safe reason, never wipes. */
	public function test_unavailable_catalog_keeps_previous_value(): void {
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11 );

		$res = $this->amelia()->apply_selected_services_save( 5, array( 12 ) );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'catalog_unavailable', $res['reason'] );
		$this->assertSame( array( 11 ), $res['value'], 'previous value survives an unavailable catalog' );
	}

	/** Row 4b: sync mode off is not_configured — same no-wipe protection. */
	public function test_not_configured_keeps_previous_value(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['amelia_sync_mode'] = Settings::SYNC_MODE_OFF;
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11 );

		$res = $this->amelia()->apply_selected_services_save( 5, array( 12 ) );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'catalog_not_configured', $res['reason'] );
		$this->assertSame( array( 11 ), $res['value'] );
	}

	/** Row 5: a non-professional persona is rejected and its stored value (if any) stays untouched. */
	public function test_ordinary_member_rejected_and_unchanged(): void {
		$this->seed_catalog( array( array( 'id' => 11, 'title' => 'Contracts' ) ) );
		$GLOBALS['wp_stubs']['user_meta'][9]['selected_services'] = array( 11 );

		$res = $this->amelia()->apply_selected_services_save( 9, array( 11 ) );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'persona_not_professional', $res['reason'] );
		$this->assertSame( array( 11 ), $res['value'], 'ordinary member cannot force a change through POST' );
	}

	/** Row 6: forged/ineligible values never widen the allowlist. */
	public function test_forged_values_are_dropped_server_side(): void {
		$this->seed_catalog( array(
			array( 'id' => 11, 'title' => 'Contracts' ),
			array( 'id' => 12, 'title' => 'Consultation' ),
		) );

		$res = $this->amelia()->apply_selected_services_save( 5, array( 55, '77', 'not-a-service', 11.5 ) );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array(), $res['value'], 'nothing eligible was submitted, so nothing new is stored' );
	}

	/** Row 7: allowlist shrink + saving a different value keeps the orphaned stored selection. */
	public function test_allowlist_shrink_preserves_orphaned_in_usermeta(): void {
		$this->seed_catalog( array(
			array( 'id' => 11, 'title' => 'Contracts' ),
			array( 'id' => 12, 'title' => 'Consultation' ),
		) );
		$GLOBALS['wp_stubs']['user_meta'][5]['selected_services'] = array( 11, 12 );

		// The allowlist shrinks: service 12 disappears from the catalog (admin-side change).
		$this->seed_catalog( array( array( 'id' => 11, 'title' => 'Contracts' ) ) );

		$res = $this->amelia()->apply_selected_services_save( 5, array( 11 ) );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array( 11, 12 ), $res['value'], 'orphaned 12 stays stored and merged, hidden from display' );

		// Display path hides the orphaned value (get_member_services intersects the allowlist).
		$this->assertSame( array( 11 => 'Contracts' ), $this->amelia()->get_member_services( 5 ) );
	}

	/** Single production hook, registered once, and never from the constructor (S-01 intact). */
	public function test_single_hook_registration_never_via_constructor(): void {
		$GLOBALS['__filters'] = array();

		$amelia = $this->amelia( array( 11 => 'Contracts' ) );
		$this->assertArrayNotHasKey( 'um_user_pre_updating_profile_array', $GLOBALS['__filters'], 'constructor registers nothing' );

		Amelia::register_selected_services_save_hook();
		Amelia::register_selected_services_save_hook(); // Double call must NOT double-register.

		$entries = $GLOBALS['__filters']['um_user_pre_updating_profile_array'] ?? array();
		$total = 0;

		foreach ( $entries as $callbacks ) {
			$total += count( $callbacks );
		}

		$this->assertSame( 1, $total, 'exactly one production hook registration, static-guarded' );
	}

	/** S-01 proof: the closed direct-save body keeps its documented v1.1.5 verdicts. */
	public function test_direct_save_legacy_body_is_unchanged(): void {
		$amelia = $this->amelia( array( 11 => 'Contracts' ) );

		// Unmapped employee (user 9 has no employee mapping) still stores [] when invoked explicitly.
		$this->assertSame(
			array(),
			$amelia->filter_profile_services_before_save( array( 'selected_services' => array( 11 ) ), 9, array() )['selected_services'],
			'S-01 direct-save contract unchanged: unmapped employee still yields []'
		);

		// Empty override catalog still wipes to [] on explicit invocation.
		$empty_catalog_amelia = $this->amelia( array() );
		$this->assertSame(
			array(),
			$empty_catalog_amelia->filter_profile_services_before_save( array( 'selected_services' => array( 11 ) ), 5, array() )['selected_services'],
			'S-01 direct-save contract unchanged: empty catalog still yields []'
		);

		// Source-level guard: the method body contains no reference to the new contract.
		$m = new \ReflectionMethod( Amelia::class, 'filter_profile_services_before_save' );
		$src = implode( '', array_slice(
			file( $m->getFileName() ),
			$m->getStartLine() - 1,
			$m->getEndLine() - $m->getStartLine() + 1
		) );

		$this->assertStringNotContainsString( 'apply_selected_services_save', $src, 'legacy body does not call the new contract' );
		$this->assertStringNotContainsString( 'is_professional_persona', $src, 'legacy body untouched by C5b' );
	}
}
