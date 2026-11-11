<?php
/**
 * Unit tests for Lifecycle reconciliation semantics (cards D-05/D-17 / v1.2.0 C7):
 * activation and theme-switch markers, consent-aware reconciliation that stays FAIL-CLOSED
 * under the v1.2.0 write closure (zero Child Theme writes, named reason, pending marker
 * kept for diagnosability), failure containment, and the manual Repair command.
 *
 * Runs against the REAL bundled manifest/resources with a synthetic child-theme target
 * directory, through the contract filesystem stub — no repository files are modified.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Lifecycle;
use HAL\MemberProfiles\ManagedTemplates;
use PHPUnit\Framework\TestCase;
use HAL\MemberProfiles\Settings;

// Card F-01: the write-closure gate compares the RUNNING plugin version, and this suite
// boots without the main plugin file, so the version constant is pinned here for the
// whole test process (file level = before ANY test method runs) — including when THIS
// file is run standalone, where ManagedTemplatesTest.php (which pins the constant for
// the shared full-suite process) never loads. A 1.2.x value keeps every
// writes_disabled_v1_2 assertion in this suite meaningful against the 1.3.0 gate.
// Production always defines this in hal-member-profiles.php before boot; the guarded
// define is a no-op there and matches the convention in AdminDashboardPermissionsTest
// and RuntimeEvidenceReporterTest.
if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.2.0' );
}

final class LifecycleReconciliationTest extends TestCase {

	private string $theme;

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_extra_set( 'fs_available', true );
		hal_wp_stub_extra_set( 'fs_fail_put', false );

		$this->theme = sys_get_temp_dir() . '/hal_d17_lifecycle_' . uniqid();
		@mkdir( $this->theme . '/ultimate-member/templates', 0777, true );

		hal_wp_stub_extra_set( 'stylesheet', 'child-lc' );
		hal_wp_stub_extra_set( 'template', 'parent' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->theme );

		$GLOBALS['wp_stubs']['options']   = array(
			\HAL\MemberProfiles\Settings::OPTION_KEY => array(
				'managed_templates_consent' => true,
			),
		);
		$GLOBALS['wp_stubs']['post_meta'] = array();
	}

	public function test_no_consent_blocks_repair_and_reconciliation(): void {
		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['managed_templates_consent'] = false;

		$res = Lifecycle::repair();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'consent_missing', $res['reason'] );

		Lifecycle::maybe_reconcile();

		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
		$this->assertSame(
			false,
			get_option( Lifecycle::PENDING_OPTION, false ),
			'silent tick must not even record state when consent is absent'
		);
	}

	protected function tearDown(): void {
		foreach ( glob( $this->theme . '/ultimate-member/templates/*' ) ?: array() as $file ) {
			@unlink( $file );
		}

		@rmdir( $this->theme . '/ultimate-member/templates' );
		@rmdir( $this->theme . '/ultimate-member' );
		@rmdir( $this->theme );
	}

	private function stored_status(): array {
		$stored = get_option( Lifecycle::PENDING_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	public function test_activation_records_pending_only(): void {
		Lifecycle::on_activation();

		$status = $this->stored_status();

		$this->assertSame( 'pending', $status['status'] );
		$this->assertSame( 'activated', $status['reason'] );
	}

	public function test_theme_switch_marks_pending_and_keeps_old_theme_alone(): void {
		Lifecycle::mark_pending_on_theme_switch();

		$all = $GLOBALS['wp_stubs']['options'];

		if ( ! isset( $all[ Lifecycle::PENDING_OPTION ] ) ) {
			$this->fail(
				'PENDING_OPTION not written. All option keys: ' . implode( ', ', array_keys( $all ) )
			);
		}

		$raw = $all[ Lifecycle::PENDING_OPTION ];

		$this->assertSame( 'pending', $raw['status'] );
		$this->assertSame( 'theme_switched', $raw['reason'] );
	}

	/**
	 * v1.2.0 (card C7): reconciliation is consent-aware but the write path is closed —
	 * drift still schedules reconciliation, provisioning fails closed with the named
	 * reason, the pending marker stays for diagnosability, and no Child Theme file is
	 * ever written (idempotent across repeated ticks).
	 */
	public function test_drift_reconciliation_stays_fail_closed_without_child_theme_writes(): void {
		Lifecycle::maybe_reconcile(); // fresh env -> drift -> but v1.2.0 disapproves writes

		$status = $this->stored_status();

		$this->assertSame( 'pending', $status['status'] );
		$this->assertStringContainsString( 'writes_disabled_v1_2', (string) $status['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );

		// Second admin tick: still fail-closed, still zero writes.
		Lifecycle::maybe_reconcile();

		$this->assertSame( 'pending', $this->stored_status()['status'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
	}

	public function test_retired_account_ledger_row_is_retained_without_reconciliation(): void {
		// v1.2.0 (card C7): the historical ledger is seeded directly — the writer can no
		// longer be run to produce it, and no retired row is ever deleted or rewritten.
		$state = array(
			'theme'  => 'child-lc',
			'assets' => array(
				'resources/ultimate-member/account.php' => array(
					'asset_version'     => 1,
					'source_hash'       => str_repeat( 'a', 64 ),
					'last_managed_hash' => str_repeat( 'a', 64 ),
					'status'            => ManagedTemplates::STATUS_CURRENT,
				),
			),
		);
		update_option( ManagedTemplates::STATE_OPTION, $state );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $state, get_option( ManagedTemplates::STATE_OPTION ), 'the historical ledger row must be retained untouched' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
		$this->assertStringContainsString( 'writes_disabled_v1_2', (string) $this->stored_status()['reason'] );
	}

	public function test_provisioning_failure_keeps_pending_with_named_reason(): void {
		// v1.2.0 (card C7): the write closure fires before any filesystem work, so the
		// containment path records the named closure reason and keeps the pending marker.
		Lifecycle::maybe_reconcile();

		$status = $this->stored_status();

		$this->assertSame( 'pending', $status['status'] );
		$this->assertStringContainsString( 'writes_disabled_v1_2', (string) $status['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}

	public function test_manual_repair_reports_writes_disabled_fail_closed(): void {
		$res = Lifecycle::repair(); // programmatic path; nonce null by design here

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'writes_disabled_v1_2', $res['reason'] );
		$this->assertSame( 'pending', $this->stored_status()['status'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}
}
