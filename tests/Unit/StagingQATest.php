<?php
/**
 * Unit tests for the StagingQA escape hatch (Integration Closure #7): double-keyed
 * enablement (staging environment + server-side flag), zero Request override, and the
 * production-never guarantee through CompatibilityGate::effective_passes().
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\StagingQA;
use PHPUnit\Framework\TestCase;

final class StagingQATest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'env_type', 'production' );
		$GLOBALS['wp_stubs']['options'] = array();
	}

	protected function tearDown(): void {
		unset( $_GET['staging_qa'], $_POST['staging_qa'], $_REQUEST['staging_qa'] );
	}

	public function test_production_never_enables_even_with_flag(): void {
		hal_wp_stub_extra_set( 'env_type', 'production' );
		update_option( StagingQA::FLAG_OPTION, true );

		// Deliberate Request-override attempt must be irrelevant.
		$_GET['staging_qa']     = '1';
		$_POST['staging_qa']    = '1';
		$_REQUEST['staging_qa'] = '1';

		$this->assertFalse( StagingQA::enabled() );
	}

	public function test_staging_requires_the_server_side_flag(): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );

		$this->assertFalse( StagingQA::enabled(), 'flag off by default' );

		update_option( StagingQA::FLAG_OPTION, true );

		$this->assertTrue( StagingQA::enabled() );
	}

	public function test_effective_passes_waives_matrix_only_on_enabled_staging(): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );

		$versions = array( 'wp' => '6.5', 'php' => '8.0', 'um' => '2.8.0' );
		$gate     = new CompatibilityGate( $versions, array() ); // no signed rows at all

		update_option( StagingQA::FLAG_OPTION, false );

		$this->assertFalse( $gate->effective_passes( 'profile' ) );

		update_option( StagingQA::FLAG_OPTION, true );

		$this->assertTrue( $gate->effective_passes( 'profile' ), 'QA waiver on staging' );
		$this->assertSame(
			'awaiting_matrix_signoff',
			$gate->describe( 'profile' ),
			'strict verdict/description stays honest even under QA waiver'
		);
	}

	public function test_floor_components_still_required_under_qa(): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );
		update_option( StagingQA::FLAG_OPTION, true );

		$gate = new CompatibilityGate( array( 'wp' => '6.5', 'php' => '8.0' ), array() ); // um missing

		$this->assertFalse( $gate->effective_passes( 'profile' ), 'floor cannot be waived by QA mode' );
	}

	public function test_amelia_writes_ignore_qa_override_by_policy(): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );
		update_option( StagingQA::FLAG_OPTION, true );

		$versions = array( 'wp' => '6.5', 'php' => '8.0', 'amelia' => '7.2' );
		$gate     = new CompatibilityGate( $versions, array() );

		$this->assertTrue( $gate->effective_passes( 'amelia_api_read' ) );
		$this->assertFalse(
			$gate->passes( 'amelia_fields_write' ),
			'strict passes() stays closed for writes regardless of QA mode'
		);
	}
}
