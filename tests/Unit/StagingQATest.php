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
use HAL\MemberProfiles\Integrations\AmeliaFieldsWriter;
use HAL\MemberProfiles\StagingQA;
use PHPUnit\Framework\TestCase;

final class StagingQATest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'env_type', 'production' );
		$GLOBALS['wp_stubs']['options'] = array();
	}

	protected function tearDown(): void {
		unset(
			$_GET['staging_qa'],
			$_POST['staging_qa'],
			$_REQUEST['staging_qa'],
			$_GET[ StagingQA::FLAG_OPTION ],
			$_POST[ StagingQA::FLAG_OPTION ],
			$_REQUEST[ StagingQA::FLAG_OPTION ]
		);
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

		// Complete profile-capability FLOOR tuple; the missing-elementor variant is
		// covered by test_floor_components_still_required_under_qa().
		$versions = array( 'wp' => '6.5', 'php' => '8.0', 'um' => '2.8.0', 'elementor' => '3.25.0' );
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
		$this->assertFalse(
			$gate->passes( 'profile' ),
			'strict passes() itself is untouched by the QA waiver'
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

		// End-to-end: even with the QA waiver ACTIVE at gate level and a flag of the
		// DATABASE-persisted shape "1", the write consumer resolves through strict
		// passes() and stays closed — it never rides effective_passes().
		update_option( StagingQA::FLAG_OPTION, '1' );

		$this->assertTrue( StagingQA::enabled(), 'DB-shaped "1" enables staging QA' );
		$this->assertTrue(
			$gate->effective_passes( 'amelia_fields_write' ),
			'waiver is genuinely active at gate level for this capability'
		);
		$this->assertFalse( $gate->passes( 'amelia_fields_write' ) );

		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		$res = AmeliaFieldsWriter::apply(
			array( array( 'key' => 'x', 'title' => 'X', 'type' => 'text' ) ),
			'nonce-' . AmeliaFieldsWriter::NONCE_ACTION,
			$gate
		);

		$this->assertSame(
			'gate_closed',
			$res['reason'],
			'the Amelia write gate never rides the staging QA waiver'
		);
	}

	/**
	 * The ONLY shapes that may enable the flag: bool true (same-request read right
	 * after update_option()), int 1, and string "1" (the shape WordPress's database
	 * round-trip returns on any later request).
	 */
	public static function canonicalTrueShapes(): array {
		return array(
			'in-request bool true' => array( true ),
			'int one'              => array( 1 ),
			'db string one'        => array( '1' ),
		);
	}

	/**
	 * Every non-canonical value must fail closed — including friendly spellings like
	 * "true"/"yes", numeric zero in both shapes, empty values, null, and containers.
	 */
	public static function nonCanonicalFlagShapes(): array {
		return array(
			'false bool'    => array( false ),
			'int zero'      => array( 0 ),
			'string zero'   => array( '0' ),
			'empty string'  => array( '' ),
			'true spelling' => array( 'true' ),
			'yes spelling'  => array( 'yes' ),
			'null'          => array( null ),
			'array'         => array( array( 1 ) ),
			'object'        => array( new \stdClass() ),
		);
	}

	/** @dataProvider canonicalTrueShapes */
	public function test_staging_accepts_canonical_persisted_shapes( $flag ): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );
		update_option( StagingQA::FLAG_OPTION, $flag );

		$this->assertTrue(
			StagingQA::enabled(),
			'canonical true shape must enable: ' . var_export( $flag, true )
		);
	}

	/** @dataProvider nonCanonicalFlagShapes */
	public function test_staging_rejects_every_non_canonical_shape( $flag ): void {
		hal_wp_stub_extra_set( 'env_type', 'staging' );
		update_option( StagingQA::FLAG_OPTION, $flag );

		$this->assertFalse(
			StagingQA::enabled(),
			'non-canonical shape must fail closed: ' . var_export( $flag, true )
		);
	}

	/**
	 * Production stays closed for EVERY enable-shaped flag value, and Request-override
	 * attempts remain structurally irrelevant.
	 *
	 * @dataProvider canonicalTrueShapes
	 */
	public function test_production_stays_closed_for_every_enabled_shape_with_request_overrides( $flag ): void {
		hal_wp_stub_extra_set( 'env_type', 'production' );
		update_option( StagingQA::FLAG_OPTION, $flag );

		$_GET['staging_qa']     = '1';
		$_POST['staging_qa']    = '1';
		$_REQUEST['staging_qa'] = '1';
		$_GET[ StagingQA::FLAG_OPTION ]    = '1';
		$_POST[ StagingQA::FLAG_OPTION ]   = '1';
		$_REQUEST[ StagingQA::FLAG_OPTION ] = '1';

		$this->assertFalse(
			StagingQA::enabled(),
			'production must stay fail-closed for flag: ' . var_export( $flag, true )
		);
	}

	/**
	 * LIVE-WordPress proof of the actual bug being fixed: store int 1 through the real
	 * Option API, force a genuine DATABASE read (drop the option caches), and assert
	 * the persisted "1" string enables staging QA. Cleanup is guaranteed.
	 *
	 * Two honest gates precede it, and the environment is NEVER faked from inside the
	 * test: wp_get_environment_type() applies no filter, so WP_ENVIRONMENT_TYPE=staging
	 * must be configured outside the test (wp-config.php / server), before bootstrap.
	 * Without a real WordPress environment this records itself as PENDING — it is never
	 * simulated against stubs, because the stub preserves native types and would prove
	 * nothing about persistence.
	 */
	public function test_option_persistence_via_real_database_roundtrip(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_TESTS_WP' ) || ! HAL_MEMBER_PROFILES_TESTS_WP ) {
			$this->markTestSkipped(
				'PENDING live WordPress: real Option API/database roundtrip requires WP_TESTS_DIR.'
			);

			return;
		}

		if ( 'staging' !== wp_get_environment_type() ) {
			$this->markTestSkipped(
				'PENDING staging environment: set WP_ENVIRONMENT_TYPE=staging in wp-config.php '
				. '(outside this test, BEFORE bootstrap), then rerun to execute the database roundtrip.'
			);

			return;
		}

		try {
			update_option( StagingQA::FLAG_OPTION, 1 );

			// Force a genuine DATABASE read on a later operation: drop both option cache
			// entries so get_option() cannot serve an in-request typed value.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( StagingQA::FLAG_OPTION, 'options' );

			$raw = get_option( StagingQA::FLAG_OPTION, false );

			$this->assertSame(
				'1',
				$raw,
				'WordPress persists int 1 as the string "1" across a fresh database read'
			);
			$this->assertTrue(
				StagingQA::enabled(),
				'the DB-persisted "1" shape must enable staging QA after this fix'
			);
		} finally {
			delete_option( StagingQA::FLAG_OPTION );
		}

		$this->assertFalse(
			StagingQA::enabled(),
			'after guaranteed cleanup the default is fail-closed again'
		);
	}
}
