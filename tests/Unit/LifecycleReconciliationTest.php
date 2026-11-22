<?php
/**
 * Unit tests for Lifecycle reconciliation semantics (cards D-05/D-17 / v1.2.0 C7 /
 * v1.2.1 F-06 / v1.3.0 G-14): activation and theme-switch markers, consent-aware
 * reconciliation — at the suite-pinned 1.4.0 the F-01 write gate is OPEN, so a
 * reconciliation run provisions the bundled bridge asset into the sandbox child theme
 * and records the success status (the CLOSED 1.2.x semantics are proven by the
 * standalone runtime proof tool tests/runtime/force-1-2-x-closed-regression.php) —
 * failure containment, the manual Repair command, and the F-06 terminal-failure
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

// Card F-01 / G-14: the write-closure gate compares the RUNNING plugin version, and
// this suite boots without the main plugin file, so the version constant is pinned here
// for the whole test process (file level = before ANY test method runs) — including
// when THIS file is run standalone, where ManagedTemplatesTest.php (which pins the
// constant for the shared full-suite process) never loads. The pinned 1.4.0 value
// OPENS the F-01 gate so the reconciliation assertions prove the approved write path;
// the CLOSED 1.2.x semantics are proven by the standalone runtime proof tool
// tests/runtime/force-1-2-x-closed-regression.php in its own process. Production
// always defines this in hal-member-profiles.php before boot; the guarded define is a
// no-op there and matches the convention in AdminDashboardPermissionsTest and
// RuntimeEvidenceReporterTest.
if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.4.0' );
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
	 * @param int $since Original pending `since` timestamp (the L-03 live value).
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
	 * G-14: drift still schedules reconciliation and — at the pinned 1.4.0, where the
	 * F-01 gate is open — provisioning SUCCEEDS: the bundled bridge asset is written
	 * into the sandbox child theme, the marker lands on the final idle status with the
	 * success reason, and the second admin tick (drift now cleared by the recorded
	 * provisioning state) never rewrites the marker or the ledger.
	 */
	public function test_drift_reconciliation_provisions_successfully_and_records_the_status_once(): void {
		Lifecycle::maybe_reconcile(); // fresh env -> drift -> the open gate provisions

		$status = $this->stored_status();

		$this->assertSame( 'idle', $status['status'], 'the reconciliation must land on the final status, not pending' );
		$this->assertSame( 'reconciled', $status['reason'] );
		$this->assertFileExists( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php', 'the bridge asset is provisioned into the sandbox theme' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php', 'the retired asset is never provisioned' );

		// Second admin tick: the recorded provisioning state clears the drift, so the
		// success marker and the ownership ledger are never rewritten.
		$recorded = get_option( Lifecycle::PENDING_OPTION, array() );
		$ledger   = get_option( ManagedTemplates::STATE_OPTION, array() );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $recorded, get_option( Lifecycle::PENDING_OPTION, array() ) );
		$this->assertSame( $ledger, get_option( ManagedTemplates::STATE_OPTION, array() ) );
		$this->assertFileExists( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' );
	}

	/**
	 * G-14: the F-01 prune meets the retired row under the OPEN gate — a reconcile that
	 * provisions the bundled asset also trims the seeded v1.1.5-era account.php row from
	 * the ownership ledger (option-state cleanup ONLY: no file is ever deleted, and the
	 * retired file was never written). The v1.2.x-era closed-gate retention behavior —
	 * the seeded row surviving a fail-closed tick untouched — is proven by the standalone
	 * runtime proof tool's proof (e).
	 */
	public function test_retired_account_ledger_row_is_pruned_by_the_open_gate_reconcile(): void {
		// v1.2.0 (card C7): the historical ledger is seeded directly, in the exact shape
		// the v1.1.5-era writer produced.
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

		$ledger = get_option( ManagedTemplates::STATE_OPTION, array() );
		$assets = is_array( $ledger['assets'] ?? null ) ? $ledger['assets'] : array();

		$this->assertArrayNotHasKey(
			'resources/ultimate-member/account.php',
			$assets,
			'the F-01 prune removes the retired row on the state-writing path'
		);
		$this->assertSame(
			ManagedTemplates::STATUS_CURRENT,
			$assets['resources/ultimate-member/profile-hal-member-profiles.php']['status'] ?? '',
			'the reconcile provisioned the bundled asset into the ledger'
		);
		$this->assertFileDoesNotExist(
			$this->theme . '/ultimate-member/templates/account.php',
			'the prune is option-state cleanup ONLY — the retired file is never written or deleted'
		);
		$this->assertSame( 'reconciled', (string) $this->stored_status()['reason'] );
	}

	/**
	 * G-14: the live-shaped terminal marker (pending + provision_failed_writes_disabled_
	 * v1_2, L-03) with consent enabled converts in ONE admin visit through the SUCCESS
	 * path at the pinned 1.4.0 — the final idle status records the reconciled reason and
	 * a fresh since, never a pending re-loop. The closed-gate conversion semantics (the
	 * machine reason and the original pending `since` preserved verbatim, until stamped)
	 * are proven by the standalone runtime proof tool's proof (e).
	 */
	public function test_terminal_pending_converts_once_to_final_status_via_a_successful_reconcile(): void {
		$this->seed_terminal_pending( 1794410931 ); // L-03 live value: 2026-11-11 15:28:51Z.

		Lifecycle::maybe_reconcile();

		$status = $this->stored_status();

		$this->assertSame( 'idle', $status['status'], 'one admin visit must convert the terminal pending to the final status' );
		$this->assertSame( 'reconciled', $status['reason'], 'the success path records the reconciled reason, never a pending re-loop' );
		$this->assertGreaterThan(
			1794410931,
			(int) ( $status['since'] ?? 0 ),
			'the success path stamps a fresh since — the seeded audit timestamp is not preserved on success'
		);
		$this->assertFileExists( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php', 'the successful reconcile provisions the bridge asset' );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}

	/**
	 * G-14: stability — after the one-time SUCCESS conversion, a second reconcile call
	 * (drift now cleared by the recorded provisioning state) must leave the option
	 * identical: no re-loop, no rewrite. The success path stamped a fresh since on the
	 * first visit; the closed-gate no-since-advance semantics are proven by the
	 * standalone runtime proof tool's proof (e).
	 */
	public function test_second_reconcile_visit_does_not_rewrite_the_success_marker(): void {
		$this->seed_terminal_pending( 1794410931 );

		Lifecycle::maybe_reconcile();
		$converted = get_option( Lifecycle::PENDING_OPTION, array() );

		Lifecycle::maybe_reconcile();

		$this->assertSame( $converted, get_option( Lifecycle::PENDING_OPTION, array() ), 'the second admin visit must not rewrite the success marker' );
		$this->assertSame( 'idle', $this->stored_status()['status'], 'the success marker stays on the final idle status' );
	}

	/**
	 * Card F-06 (b): retryable causes legitimately warrant pending — they map to the
	 * pending status and keep the pre-F-06 behavior unchanged. (Inside this suite the
	 * retryable classification is proven directly against the classifier, since no test
	 * drives needs_credentials/busy through a real provision() call; the end-to-end
	 * retryable path is additionally proven by the F-06 runtime simulation under
	 * tests/runtime/.)
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

	public function test_manual_repair_reports_the_success_path_status(): void {
		// G-14: at the pinned 1.4.0 the manual Repair SUCCEEDS — the reconciliation
		// provisions the bundled asset and the marker records the success-path status
		// (final idle with the repaired reason), never a pending re-loop.
		$res = Lifecycle::repair(); // programmatic path; nonce null by design here

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'repaired', $res['reason'] );
		$this->assertSame( 'idle', $this->stored_status()['status'], 'the successful repair must close the marker on the final status, not re-mark pending' );
		$this->assertSame( 'repaired', (string) $this->stored_status()['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}
}
