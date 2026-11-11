<?php
/**
 * Unit tests for Lifecycle reconciliation semantics (cards D-05/D-17 / v1.2.0 C7 /
 * v1.2.1 F-06): activation and theme-switch markers, consent-aware reconciliation that
 * stays FAIL-CLOSED under the v1.2.0 write closure (zero Child Theme writes, named
 * reason), failure containment, the manual Repair command, and the F-06 terminal-failure
 * classification — version-terminal (writes_disabled_v1_2) and immutable provision
 * failures close into the final idle status exactly once (machine reason + audit
 * timestamps preserved), while retryable causes (needs_credentials, busy) legitimately
 * keep the pending marker.
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

	/**
	 * Seeds the exact live marker shape measured in production (evidence L-03):
	 * {"status":"pending","reason":"provision_failed_writes_disabled_v1_2","since":...}.
	 *
	 * @param int $since Original pending `since` timestamp to preserve through the closure.
	 * @return void
	 */
	private function seed_terminal_pending( int $since ): void {
		update_option(
			Lifecycle::PENDING_OPTION,
			array(
				'status' => 'pending',
				'reason' => 'provision_failed_writes_disabled_v1_2',
				'since'  => $since,
			),
			false
		);
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
	 * v1.2.1 (card F-06): drift still schedules reconciliation and provisioning still
	 * fails closed with zero Child Theme writes — but the terminal closure reason now
	 * converts the marker to the final idle status ONCE instead of re-looping as
	 * pending on every admin visit.
	 */
	public function test_drift_reconciliation_fails_closed_and_closes_terminal_pending_once(): void {
		Lifecycle::maybe_reconcile(); // fresh env -> drift -> v1.2.x disapproves writes

		$status = $this->stored_status();

		$this->assertSame( 'idle', $status['status'], 'the terminal closure must land on the final status, not pending' );
		$this->assertSame( 'provision_failed_writes_disabled_v1_2', $status['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );

		// Second admin tick: still fail-closed, still zero writes, and the closed marker
		// is never rewritten (drift is still present here, like production, L-04).
		$closed = get_option( Lifecycle::PENDING_OPTION, array() );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $closed, get_option( Lifecycle::PENDING_OPTION, array() ) );
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

	/**
	 * Card F-06 (a): the terminal live-shaped marker (pending + writes_disabled_v1_2,
	 * L-03) with consent enabled converts in ONE admin visit to the final idle status
	 * while the machine reason and the original pending `since` are preserved verbatim.
	 */
	public function test_terminal_pending_converts_once_to_final_status_keeping_reason(): void {
		$this->seed_terminal_pending( 1794410931 ); // L-03 live value: 2026-11-11 15:28:51Z.

		Lifecycle::maybe_reconcile();

		$status = $this->stored_status();

		$this->assertSame( 'idle', $status['status'], 'one admin visit must convert the terminal pending to the final status' );
		$this->assertSame( 'provision_failed_writes_disabled_v1_2', $status['reason'], 'the machine reason must be preserved verbatim' );
		$this->assertSame( 1794410931, $status['since'], 'the original pending since must be preserved' );
		$this->assertGreaterThan( 0, $status['until'] ?? 0, 'the closure must stamp until' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}

	/**
	 * Card F-06 (a2): stability — after the one-time conversion, a second reconcile call
	 * (with drift present, matching production's stale state rows, L-04) must leave the
	 * option identical: no re-loop, no rewrite, no since advance.
	 */
	public function test_second_reconcile_visit_does_not_rewrite_terminal_closure(): void {
		$this->seed_terminal_pending( 1794410931 );

		Lifecycle::maybe_reconcile();
		$closed = get_option( Lifecycle::PENDING_OPTION, array() );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $closed, get_option( Lifecycle::PENDING_OPTION, array() ), 'the second admin visit must not rewrite the closed terminal marker' );
		$this->assertSame( 'idle', $this->stored_status()['status'] );
		$this->assertSame( 1794410931, $this->stored_status()['since'], 'since must never advance once closed' );
	}

	/**
	 * Card F-06 (b): retryable causes legitimately warrant pending — they map to the
	 * pending status and keep the pre-F-06 behavior unchanged. (The end-to-end retryable
	 * path is additionally proven by the F-06 runtime simulation under tests/runtime/,
	 * because the pinned 1.2.x write gate makes needs_credentials/busy unreachable
	 * through a real provision() call inside this suite.)
	 */
	public function test_retryable_failure_reasons_remain_pending(): void {
		$method = new \ReflectionMethod( Lifecycle::class, 'failure_status_for_reason' );
		$method->setAccessible( true );

		$this->assertSame( 'pending', $method->invoke( null, 'needs_credentials' ), 'needs_credentials must stay pending' );
		$this->assertSame( 'pending', $method->invoke( null, 'busy' ), 'busy must stay pending' );
		$this->assertSame( 'idle', $method->invoke( null, 'writes_disabled_v1_2' ), 'the version-terminal reason must close into idle' );
		$this->assertSame( 'idle', $method->invoke( null, 'immutable' ), 'the immutable reason must close into idle' );
	}

	/**
	 * Card F-06 (c): consent gating unchanged — without consent the silent tick must not
	 * convert the terminal marker at all; the pending state stays byte-identical.
	 */
	public function test_no_consent_never_converts_terminal_pending(): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['managed_templates_consent'] = false;

		$seeded = array(
			'status' => 'pending',
			'reason' => 'provision_failed_writes_disabled_v1_2',
			'since'  => 1794410931,
		);
		update_option( Lifecycle::PENDING_OPTION, $seeded, false );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $seeded, get_option( Lifecycle::PENDING_OPTION, array() ), 'no consent -> no conversion at all' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
	}

	public function test_manual_repair_reports_writes_disabled_fail_closed(): void {
		// v1.2.1 (card F-06): the manual Repair reports the named closure reason and the
		// terminal classification also closes the marker — no pending re-loop from the
		// repair path either.
		$res = Lifecycle::repair(); // programmatic path; nonce null by design here

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'writes_disabled_v1_2', $res['reason'] );
		$this->assertSame( 'idle', $this->stored_status()['status'], 'the terminal repair failure must close the marker, not re-mark pending' );
		$this->assertStringContainsString( 'writes_disabled_v1_2', (string) $this->stored_status()['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}
}
