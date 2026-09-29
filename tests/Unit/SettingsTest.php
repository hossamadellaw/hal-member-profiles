<?php
/**
 * C1 tests for the runtime Ultimate Member role/status mapping.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Settings;
use HAL\MemberProfiles\CompatibilityGate;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_stubs']['can_manage']  = true;
		$GLOBALS['wp_stubs']['um_roles']    = array(
			'role_lawyer'     => 'R1',
			'role_consultant' => 'R2',
			'role_member'     => 'R3',
			'role_other'      => 'R4',
		);
		$GLOBALS['wp_stubs']['um_statuses'] = array(
			'approved' => 'Approved',
			'pending'  => 'Pending',
		);
		$GLOBALS['wp_stubs']['options']     = array();
		$_POST                              = array();
	}

	private function mapping(): array {
		return array(
			Settings::PERSONA_LAWYER          => array( 'role' => 'role_lawyer', 'status' => 'approved' ),
			Settings::PERSONA_CONSULTANT      => array( 'role' => 'role_consultant', 'status' => 'approved' ),
			Settings::PERSONA_ORDINARY_MEMBER => array( 'role' => 'role_member', 'status' => 'approved' ),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['wp_stubs']['can_manage'] );
		unset( $GLOBALS['wp_stubs']['um_roles'] );
		unset( $GLOBALS['wp_stubs']['um_statuses'] );
		unset( $GLOBALS['wp_stubs']['options'] );
	}

	public function test_mapping_defaults_to_native_when_missing(): void {
		$mapping = ( new Settings() )->get_um_member_mapping();

		$this->assertSame( 'unavailable', $mapping['availability'] );
		$this->assertSame( 'um_mapping_missing', $mapping['reason'] );
		$this->assertSame( array(), $mapping['member_roles'] );
	}

	public function test_stale_mapping_fails_closed_at_read_time(): void {
		$mapping = $this->mapping();
		$mapping[ Settings::PERSONA_LAWYER ]['role'] = 'removed-role';
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping' => $mapping,
		);

		$mapping = ( new Settings() )->get_um_member_mapping();

		$this->assertSame( 'unavailable', $mapping['availability'] );
		$this->assertSame( 'um_mapping_stale', $mapping['reason'] );

		$mapping = $this->mapping();
		$mapping[ Settings::PERSONA_CONSULTANT ]['status'] = 'removed-status';
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['um_persona_mapping'] = $mapping;
		$this->assertSame( 'um_mapping_stale', ( new Settings() )->get_um_member_mapping()['reason'] );
	}

	public function test_missing_mapping_forces_public_layout_back_to_observe(): void {
		$versions = array( 'wp' => '7.1', 'php' => '8.3.33', 'um' => '2.13.0', 'elementor' => '4.2.3' );
		$row      = array( 'signed' => true, 'versions' => $versions );
		$gate     = new CompatibilityGate(
			$versions,
			array(
				CompatibilityGate::CAP_PROFILE => array( $row ),
				CompatibilityGate::CAP_ACCOUNT => array( $row ),
			)
		);
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_layout_mode' => Settings::LAYOUT_MODE_PUBLIC_LAYOUT,
			'account_layout_mode' => Settings::LAYOUT_MODE_PUBLIC_LAYOUT,
		);

		$settings = new Settings( $gate );

		$this->assertSame( Settings::LAYOUT_MODE_OBSERVE, $settings->get_profile_layout_mode() );
		$this->assertSame( Settings::LAYOUT_MODE_OBSERVE, $settings->get_account_layout_mode() );
	}

	public function test_manage_options_is_required_to_change_mapping(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping'   => $this->mapping(),
			'um_member_roles'      => array( 'legacy-role' ),
			'um_approved_statuses' => array( 'legacy-status' ),
		);
		$GLOBALS['wp_stubs']['can_manage'] = false;
		$_POST[ Settings::UM_MAPPING_NONCE_NAME ] = 'nonce-' . Settings::UM_MAPPING_NONCE_ACTION;

		$clean = ( new Settings() )->sanitize(
			array( 'um_persona_mapping' => array() )
		);

		$this->assertSame( $this->mapping(), $clean['um_persona_mapping'] );
		$this->assertSame( array( 'legacy-role' ), $clean['um_member_roles'] );
	}

	public function test_invalid_mapping_nonce_preserves_existing_mapping(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping' => $this->mapping(),
		);
		$_POST[ Settings::UM_MAPPING_NONCE_NAME ] = 'forged';

		$clean = ( new Settings() )->sanitize(
			array( 'um_persona_mapping' => array() )
		);

		$this->assertSame( $this->mapping(), $clean['um_persona_mapping'] );
	}

	public function test_valid_nonce_revalidates_submitted_keys_against_runtime_options(): void {
		$_POST[ Settings::UM_MAPPING_NONCE_NAME ] = 'nonce-' . Settings::UM_MAPPING_NONCE_ACTION;

		$submitted = $this->mapping();
		$submitted[ Settings::PERSONA_CONSULTANT ]['role'] = 'removed-role';
		$clean = ( new Settings() )->sanitize( array( 'um_persona_mapping' => $submitted ) );

		$this->assertArrayHasKey( Settings::PERSONA_LAWYER, $clean['um_persona_mapping'] );
		$this->assertArrayNotHasKey( Settings::PERSONA_CONSULTANT, $clean['um_persona_mapping'] );
		$this->assertSame( array( 'role_lawyer', 'role_member' ), $clean['um_member_roles'] );
	}

	public function test_complete_explicit_mapping_resolves_and_conflicting_roles_do_not(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array( 'um_persona_mapping' => $this->mapping() );
		$resolved = ( new Settings() )->get_um_member_mapping();

		$this->assertSame( 'available', $resolved['availability'] );
		$this->assertSame( array_keys( $this->mapping() ), array_keys( $resolved['personas'] ) );

		$conflict = $this->mapping();
		$conflict[ Settings::PERSONA_CONSULTANT ]['role'] = 'role_lawyer';
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['um_persona_mapping'] = $conflict;

		$this->assertSame( 'um_mapping_conflict', ( new Settings() )->get_um_member_mapping()['reason'] );
	}

	public function test_account_settings_are_retained_but_effective_mode_is_native_observe(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'account_layout_mode'        => Settings::LAYOUT_MODE_PUBLIC_LAYOUT,
			'account_library_template_id' => 321,
			'account_fixture_user_id'      => 654,
		);

		$clean = ( new Settings() )->sanitize(
			array(
				'account_layout_mode'         => Settings::LAYOUT_MODE_OBSERVE,
				'account_library_template_id' => 0,
				'account_fixture_user_id'      => 0,
			)
		);

		$this->assertSame( Settings::LAYOUT_MODE_PUBLIC_LAYOUT, $clean['account_layout_mode'] );
		$this->assertSame( 321, $clean['account_library_template_id'] );
		$this->assertSame( 654, $clean['account_fixture_user_id'] );
		$this->assertSame( Settings::LAYOUT_MODE_OBSERVE, ( new Settings() )->get_account_layout_mode() );
	}
}
