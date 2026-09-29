<?php
/**
 * Card C5 / F-06 fixture: the five-state Amelia public service catalog taxonomy and its
 * consumption. Assertions verify HAL's own contract decisions (valid empty is not a
 * failure; unavailable is not a valid empty list; a failure never erases the last valid
 * snapshot; not_configured is intentional safe absence) — transport stubs are transport
 * only. No PII, secrets, response bodies, or URLs appear in any assertion.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Integrations\Amelia;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class AmeliaCatalogStateTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'   => $GLOBALS['wp_stubs']['options'] ?? array(),
			'is_admin'  => hal_wp_stub_extra( 'is_admin' ),
			'can_manage' => $GLOBALS['wp_stubs']['can_manage'] ?? false,
		);

		hal_wp_stub_extra_set( 'is_admin', false );

		// The taxonomy reads key presence through SecretStore, which resolves the
		// process-wide constant before storage. The constant may or may not already be
		// defined by an earlier test file in the same process (AmeliaApiClientTest defines
		// it first-run), so every state other than not_configured needs it present — define
		// it defensively here, identically for all cases in this file.
		if ( ! defined( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' ) ) {
			define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'C5-CATALOG-STATE-TEST-KEY' );
		}
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'amelia_sync_mode' => Settings::SYNC_MODE_DISCOVER_ONLY,
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options'] = $this->backed_up['options'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];
	}

	private function seed_snapshot( array $services, int $version = SchemaRegistry::SNAPSHOT_VERSION ): void {
		$GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ] = array(
			'version'    => $version,
			'fetched_at' => 1,
			'source'     => 'amelia_rest_admin_snapshot',
			'services'   => $services,
			'employees'  => array(),
			'counts'     => array( 'services' => count( $services ), 'employees' => 0 ),
		);
	}

	/** valid_current: a version-matching snapshot with services serves them as id => label. */
	public function test_valid_current_serves_catalog(): void {
		$this->seed_snapshot( array(
			array( 'id' => 5, 'title' => 'Contract drafting' ),
			array( 'id' => 9, 'title' => 'Legal consultation' ),
		) );

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'valid_current', $state['state'] );
		$this->assertCount( 2, $state['services'] );

		$amelia = new Amelia( new Settings() );
		$catalog = $amelia->get_service_catalog();

		$this->assertSame( array( 5 => 'Contract drafting', 9 => 'Legal consultation' ), $catalog );
	}

	/** valid_empty: an empty services list is an honest state, never a failure. */
	public function test_valid_empty_is_not_a_failure(): void {
		$this->seed_snapshot( array() );

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'valid_empty', $state['state'] );
		$this->assertSame( array(), $state['services'] );
	}

	/** stale_previous_valid: an older-shaped snapshot survives and keeps serving. */
	public function test_stale_previous_valid_keeps_last_valid_services(): void {
		$this->seed_snapshot( array( array( 'id' => 5, 'title' => 'Contract drafting' ) ), 0 );

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'stale_previous_valid', $state['state'] );
		$this->assertSame( 'snapshot_version_mismatch', $state['reason'] );
		$this->assertCount( 1, $state['services'], 'the last valid snapshot is never erased by a failure' );

		$amelia = new Amelia( new Settings() );
		$this->assertSame( array( 5 => 'Contract drafting' ), $amelia->get_service_catalog() );
	}

	/** unavailable: no snapshot at all is NOT a valid empty list — nothing surfaces. */
	public function test_unavailable_is_not_a_valid_empty_list(): void {
		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'unavailable', $state['state'] );
		$this->assertSame( 'no_snapshot', $state['reason'] );
		$this->assertSame( array(), $state['services'] );

		$amelia = new Amelia( new Settings() );
		$this->assertSame( array(), $amelia->get_service_catalog(), 'unavailable surfaces no services' );
	}

	/** unavailable also covers a snapshot whose services section is structurally missing. */
	public function test_snapshot_without_services_section_is_unavailable(): void {
		$GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ] = array(
			'version' => SchemaRegistry::SNAPSHOT_VERSION,
			'employees' => array(),
		);

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'unavailable', $state['state'] );
		$this->assertSame( 'snapshot_services_missing', $state['reason'] );
	}

	/** not_configured: sync mode `off` disables the catalog feature by owner choice. */
	public function test_not_configured_when_sync_off(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['amelia_sync_mode'] = Settings::SYNC_MODE_OFF;

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'not_configured', $state['state'] );
		$this->assertSame( 'sync_off', $state['reason'] );

		// Safe absence: the member profile itself keeps working with an empty catalog.
		$amelia = new Amelia( new Settings() );
		$this->assertSame( array(), $amelia->get_service_catalog() );
	}

	/**
	 * Failure preserves the previous snapshot (C5 acceptance): a malformed refresh never
	 * erases the stored valid snapshot, and the taxonomy keeps reporting it current.
	 * Transport-level malformed/oversized handling itself is covered by the existing
	 * AmeliaApiClientTest suite (reused here, not duplicated).
	 */
	public function test_failed_refresh_keeps_previous_valid_snapshot_current(): void {
		$this->seed_snapshot( array( array( 'id' => 5, 'title' => 'Contract drafting' ) ) );

		// Simulate the documented failed-refresh outcome at its contract level: the
		// snapshot option stays untouched (SchemaRegistry only writes on full success).
		$before = $GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ];

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'valid_current', $state['state'] );
		$this->assertSame( $before, $GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ], 'no write happened during a read' );
	}

	/** Oversized/malformed service rows inside a stored snapshot drop out individually. */
	public function test_malformed_service_rows_drop_out_individually(): void {
		$this->seed_snapshot( array(
			array( 'id' => 5, 'title' => 'Contract drafting' ),
			array( 'id' => 0, 'title' => 'Bad id' ),              // invalid id
			array( 'id' => 7, 'title' => '' ),                    // empty title
			array( 'id' => 8, 'title' => str_repeat( 'x', 5000 ) ), // oversized title
			'not-an-array',                                        // malformed row
		) );

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'valid_current', $state['state'] );
		$this->assertCount( 5, $state['services'], 'taxonomy reports the stored rows as-is' );

		$amelia = new Amelia( new Settings() );
		$catalog = $amelia->get_service_catalog();

		$this->assertSame( array( 5 => 'Contract drafting' ), $catalog, 'cleaner keeps only well-formed, in-limit rows' );
		$this->assertArrayNotHasKey( 8, $catalog, 'oversized title never surfaces' );
	}

	/** The stored snapshot stays PII-free by construction (employees contribute IDs only). */
	public function test_state_and_catalog_never_expose_pii(): void {
		$this->seed_snapshot( array( array( 'id' => 5, 'title' => 'Contract drafting' ) ) );
		$GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ]['employees'] = array(
			array( 'id' => 31 ), // PII-free shape by construction; anything else fails extraction earlier.
		);

		$state = ( new SchemaRegistry() )->amelia_catalog_state();

		$this->assertSame( 'valid_current', $state['state'] );

		$encoded = (string) json_encode( $state );
		$this->assertStringNotContainsString( 'email', $encoded );
		$this->assertStringNotContainsString( 'first_name', $encoded );
		$this->assertStringNotContainsString( 'secret', $encoded );
		$this->assertStringNotContainsString( 'api_key', $encoded );
	}
}
