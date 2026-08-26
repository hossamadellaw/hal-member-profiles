<?php
/**
 * Unit tests for Lifecycle reconciliation semantics (cards D-05/D-17): activation and
 * theme-switch markers, drift-triggered single-write migration (idempotent upgrade),
 * failure containment that keeps the pending marker, and the manual Repair command.
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

	public function test_drift_triggers_single_write_migration_then_idles(): void {
		Lifecycle::maybe_reconcile(); // fresh env -> drift -> provision both assets

		$this->assertSame( 'idle', $this->stored_status()['status'] );

		foreach (
			array(
				'profile-hal-member-profiles.php',
				'account.php',
			) as $asset
		) {
			$this->assertFileExists( $this->theme . '/ultimate-member/templates/' . $asset );
		}

		clearstatcache();

		$mtimes = array(
			filemtime( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' ),
			filemtime( $this->theme . '/ultimate-member/templates/account.php' ),
		);

		// Second admin tick: no drift, no pending -> nothing rewritten (idempotent).
		Lifecycle::maybe_reconcile();

		clearstatcache();

		$this->assertSame(
			$mtimes,
			array(
				filemtime( $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php' ),
				filemtime( $this->theme . '/ultimate-member/templates/account.php' ),
			),
			'migration must be a single write per asset'
		);
		$this->assertSame( 'idle', $this->stored_status()['status'] );
	}

	public function test_provisioning_failure_keeps_pending_with_named_reason(): void {
		hal_wp_stub_extra_set( 'fs_available', false ); // credentials-less environment

		Lifecycle::maybe_reconcile();

		$status = $this->stored_status();

		$this->assertSame( 'pending', $status['status'] );
		$this->assertStringContainsString( 'needs_credentials', (string) $status['reason'] );
		$this->assertFileDoesNotExist( $this->theme . '/ultimate-member/templates/account.php' );
	}

	public function test_manual_repair_forces_reconciliation(): void {
		$res = Lifecycle::repair(); // programmatic path; nonce null by design here

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'repaired', $res['reason'] );
		$this->assertSame( 'idle', $this->stored_status()['status'] );
	}
}
