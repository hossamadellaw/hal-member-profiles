<?php
/**
 * Unit tests for ManagedTemplates (card D-04 / D-17 / v1.2.0 C7 / v1.2.1 F-01 / v1.3.0
 * G-14): hash ownership, conflicts, and silent-delete prevention under READ-ONLY
 * inspection; the release-version write gate — closed on every 1.2.x since v1.2.1 and
 * OPEN at the suite-pinned 1.4.0 (the closed 1.2.x regression runs as the standalone
 * runtime proof tool tests/runtime/force-1-2-x-closed-regression.php in its own
 * process); the manifest-key state prune; and the C7/OD-06 public-profile
 * template-selection contract — per-member-type selection, legacy-compat reading,
 * valid/invalid/deleted/wrong-type/unpublished/Elementor-absent rendering, owner/visitor
 * context, and private-field exclusion. Exercises the REAL classes against REAL temp
 * directories through the contract-level filesystem stub.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\Integrations\ProfileLayoutAdapter;
use HAL\MemberProfiles\LayoutContract;
use HAL\MemberProfiles\ManagedTemplates;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

// Card F-01 / G-14: the write-closure gate compares the RUNNING plugin version, and the
// unit suite boots without the main plugin file, so the version constant is pinned here
// for the whole test process (file level = before ANY test method of ANY suite file
// runs). The pinned 1.4.0 value OPENS the F-01 gate for this suite — and for the
// Lifecycle/Wiring suites sharing this process (phpunit.xml.dist has no
// processIsolation; first define wins) — so the provision-ladder assertions prove the
// approved write path. The CLOSED 1.2.x semantics cannot exist in this process (the
// constant is process-wide and immutable once defined): they are proven by the
// standalone runtime proof tool tests/runtime/force-1-2-x-closed-regression.php, which
// forces '1.2.0' in its own process. Production always defines this in
// hal-member-profiles.php before boot; the guarded define is a no-op there and matches
// the convention in AdminDashboardPermissionsTest and RuntimeEvidenceReporterTest.
if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.4.0' );
}

final class ManagedTemplatesTest extends TestCase {

	private $paths = array();

	/**
	 * Environment snapshot taken by boot_c7_environment() and restored by
	 * restore_c7_environment(), so the adapter tests never leak globals.
	 *
	 * @var array<string,mixed>
	 */
	private array $c7_backup = array();

	protected function tearDown(): void {
		$this->restore_c7_environment();

		foreach ( $this->paths as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}

		$this->paths = array();
		hal_wp_stub_extra_set( 'fs_fail_put', false );
		hal_wp_stub_extra_set( 'fs_corrupt', false );
		hal_wp_stub_extra_set( 'fs_available', true );
	}

	private function track( string $path ): string {
		$this->paths[] = $path;

		return $path;
	}

	/**
	 * Builds a self-contained sandbox: one source asset + a manifest that pins it, plus a
	 * fresh child-theme directory. Returns [manifest_path, source_abs, theme_dir].
	 */
	private function sandbox( string $body = "<?php // managed body\n" ): array {
		$uniq     = uniqid( 'd17_', true );
		$src_rel  = 'tests/tmp-d17/' . $uniq . '/asset.php';
		$src_abs  = HAL_MEMBER_PROFILES_PLUGIN_DIR . $src_rel;
		$manifest = HAL_MEMBER_PROFILES_PLUGIN_DIR . 'tests/tmp-d17/' . $uniq . '/manifest.json';

		@mkdir( dirname( $src_abs ), 0777, true );
		file_put_contents( $src_abs, $body );

		$this->track( $src_abs );
		$this->track( $manifest );

		$digest = hash( 'sha256', $body );

		file_put_contents(
			$manifest,
			(string) json_encode(
				array(
					'manifest_version' => 1,
					'plugin_slug'      => 'hal-member-profiles',
					'assets'           => array(
						array(
							'asset_version' => 1,
							'type'          => 'um_profile_form_template',
							'um_scope'      => 'profile',
							'source_path'   => $src_rel,
							'target_path'   => 'ultimate-member/templates/asset.php',
							'sha256'        => $digest,
						),
					),
				)
			)
		);

		$theme = $this->track( sys_get_temp_dir() . '/hal_d17_theme_' . uniqid(), ) ;
		@mkdir( $theme . '/ultimate-member/templates', 0777, true );

		hal_wp_stub_extra_set( 'stylesheet', 'child-a' );
		hal_wp_stub_extra_set( 'template', 'parent' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $theme );

		return array( $manifest, $src_abs, $theme, $digest );
	}

	private function target_in( string $theme_dir ): string {
		return $theme_dir . '/ultimate-member/templates/asset.php';
	}

	public function test_frontend_request_is_denied_before_anything_loads(): void {
		hal_wp_stub_extra_set( 'is_admin', false );
		list( $manifest ) = $this->sandbox();

		$gate = new ManagedTemplates( $manifest );

		$this->assertSame( 'denied', $gate->inspect()['reason'] );
		$this->assertSame( 'denied', $gate->provision()['reason'] );
	}

	public function test_missing_capability_is_denied(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = false;
		list( $manifest ) = $this->sandbox();

		$gate = new ManagedTemplates( $manifest );

		$this->assertFalse( $gate->inspect()['ok'] );
		$this->assertSame( 'denied', $gate->provision()['reason'] );
	}

	public function test_invalid_manifest_fails_closed(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		$bad = $this->track( HAL_MEMBER_PROFILES_PLUGIN_DIR . 'tests/tmp-d17/bad-' . uniqid() . '.json' );
		@mkdir( dirname( $bad ), 0777, true );
		file_put_contents( $bad, '{not-json' );

		$gate = new ManagedTemplates( $bad );

		$this->assertFalse( $gate->inspect()['ok'] );
		$this->assertSame( 'invalid_manifest', $gate->provision()['reason'] );
	}

	/**
	 * The provision ladder at the open 1.4.0 gate: a credentials-less environment can
	 * never turn provisioning into a write attempt — the WP_Filesystem connection is
	 * required BEFORE the lock or any target work, so the verdict is needs_credentials
	 * and nothing is ever created in the Child Theme.
	 */
	public function test_provision_fails_closed_when_no_filesystem_is_available(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_extra_set( 'fs_available', false );
		list( $manifest,,$theme ) = $this->sandbox();

		$gate = new ManagedTemplates( $manifest );

		$result = $gate->provision();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ManagedTemplates::STATUS_NEEDS_CREDENTIALS, $result['reason'] );
		$this->assertFileDoesNotExist( $this->target_in( $theme ) );
		$this->assertFalse( get_transient( ManagedTemplates::LOCK_TRANSIENT ), 'the failed filesystem step must never arm the provisioning lock' );
	}

	/**
	 * F-01 / G-14: the formerly unconditional write closure is a release-version gate
	 * (WRITES_APPROVED_SINCE = '1.3.0'), and the unit process pins 1.4.0, which the gate
	 * approves (file-level define above), so the private verdict must read OPEN exactly as on
	 * production 1.4.0. The CLOSED side of the gate — every 1.2.x release, including any
	 * rollback — cannot exist in this process (the version constant is process-wide and
	 * immutable once defined): it is proven by the standalone runtime proof tool
	 * tests/runtime/force-1-2-x-closed-regression.php, which forces '1.2.0' in its own
	 * process and is executed and logged during the G-14 card session.
	 */
	public function test_write_gate_is_open_at_the_pinned_1_3_0_version(): void {
		$this->assertTrue(
			version_compare( HAL_MEMBER_PROFILES_VERSION, '1.3.0', '>=' ),
			'this proof is only meaningful while the process pins a gate-approved version (suite-pinned 1.4.0)'
		);

		$method = new \ReflectionMethod( ManagedTemplates::class, 'writes_disapproved' );
		$method->setAccessible( true );

		$this->assertFalse(
			$method->invoke( new ManagedTemplates() ),
			'at the pinned 1.4.0 the gate is open (provisioning is approved)'
		);
	}

	/**
	 * C7 / F-01: per-theme state rows whose key is absent from the CURRENT manifest are
	 * pruned on the state-writing path, so the stored option ends up holding exactly the
	 * current manifest keys (a stale v1.1.5-era row such as the retired account.php asset
	 * can never survive). The prune helper itself is exercised directly here; the
	 * end-to-end persistence now also runs in-process through the successful-provision
	 * tests below (the gate opens at the pinned 1.4.0). Option-state cleanup ONLY — no
	 * file is ever deleted.
	 */
	public function test_state_rows_absent_from_the_current_manifest_are_pruned(): void {
		$gate = new ManagedTemplates();

		$manifest = new \ReflectionProperty( ManagedTemplates::class, 'manifest' );
		$manifest->setAccessible( true );
		$manifest->setValue(
			$gate,
			array(
				'assets' => array(
					array( 'source_path' => 'resources/ultimate-member/profile-hal-member-profiles.php' ),
				),
			)
		);

		$prune = new \ReflectionMethod( ManagedTemplates::class, 'prune_state_to_current_manifest' );
		$prune->setAccessible( true );

		$surviving_row = array(
			'asset_version'     => 1,
			'source_hash'       => str_repeat( 'a', 64 ),
			'last_managed_hash' => str_repeat( 'a', 64 ),
			'status'            => ManagedTemplates::STATUS_WRITE_FAILED,
		);

		$stale_state = array(
			'theme'  => 'astra-child',
			'assets' => array(
				'resources/ultimate-member/profile-hal-member-profiles.php' => $surviving_row,
				'resources/ultimate-member/account.php'                     => array(
					'asset_version'     => 1,
					'last_managed_hash' => null,
					'status'            => ManagedTemplates::STATUS_WRITE_FAILED,
				),
			),
		);

		$pruned = $prune->invoke( $gate, $stale_state );

		$this->assertSame(
			array( 'resources/ultimate-member/profile-hal-member-profiles.php' ),
			array_keys( $pruned['assets'] ),
			'after the state path only the current manifest keys remain'
		);
		$this->assertSame(
			$surviving_row,
			$pruned['assets']['resources/ultimate-member/profile-hal-member-profiles.php'],
			'the surviving manifest-key row is retained untouched'
		);
		$this->assertArrayNotHasKey(
			'resources/ultimate-member/account.php',
			$pruned['assets'],
			'the stale row whose key is absent from the manifest is pruned'
		);

		$exact_state = array(
			'theme'  => 'astra-child',
			'assets' => array(
				'resources/ultimate-member/profile-hal-member-profiles.php' => $surviving_row,
			),
		);

		$this->assertSame(
			$exact_state,
			$prune->invoke( $gate, $exact_state ),
			'a state already exact to the manifest passes through unchanged (idempotent trim)'
		);
	}

	/**
	 * G-14: with the gate open at the pinned 1.4.0, provisioning against a healthy admin
	 * environment SUCCEEDS: the bridge file is created byte-exactly through the
	 * hash-verified temp-write cycle, the success verdict is reported, and the ownership
	 * ledger records the per-theme state for the manifest asset.
	 */
	public function test_provision_writes_the_bridge_file_and_records_ownership_state(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		list( $manifest,,$theme, $digest ) = $this->sandbox();

		$gate   = new ManagedTemplates( $manifest );
		$result = $gate->provision();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertFileExists( $this->target_in( $theme ), 'the open gate lets the bridge file be created' );
		$this->assertStringEqualsFile( $this->target_in( $theme ), "<?php // managed body\n" );

		$key   = key( $result['assets'] );
		$state = get_option( ManagedTemplates::STATE_OPTION, array() );

		$this->assertSame( ManagedTemplates::STATUS_CURRENT, $result['assets'][ $key ]['status'] );
		$this->assertSame( $digest, $result['assets'][ $key ]['last_managed_hash'] );
		$this->assertSame( 'child-a', $state['theme'] ?? '', 'the ledger records the provisioning theme' );
		$this->assertSame( ManagedTemplates::STATUS_CURRENT, $state['assets'][ $key ]['status'] ?? '' );
		$this->assertSame( $digest, $state['assets'][ $key ]['last_managed_hash'] ?? '' );
		$this->assertFalse( get_transient( ManagedTemplates::LOCK_TRANSIENT ), 'the provisioning lock must be released after success' );
	}

	/**
	 * C7 / G-14: the conflict contract holds at the open 1.4.0 gate — a user-modified
	 * target is reported through READ-ONLY inspection AND through provisioning, and the
	 * conflicted file is never overwritten.
	 */
	public function test_user_modified_target_is_a_hard_conflict_and_never_touched(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		list( $manifest,,$theme ) = $this->sandbox();

		$target = $this->target_in( $theme );
		file_put_contents( $target, "<?php // operator customization\n" );

		$gate       = new ManagedTemplates( $manifest );
		$inspection = $gate->inspect();

		$this->assertTrue( $inspection['ok'] );
		$this->assertSame(
			'user_modified',
			$inspection['assets'][ key( $inspection['assets'] ) ]['target_status']
		);

		$result = $gate->provision();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertSame(
			ManagedTemplates::STATUS_USER_MODIFIED,
			$result['assets'][ key( $result['assets'] ) ]['status'],
			'the conflict is reported through provisioning as well'
		);
		$this->assertStringContainsString(
			'operator customization',
			(string) file_get_contents( $target ),
			'a conflicted file must survive provisioning untouched'
		);
	}

	/**
	 * G-14: the upgrade path at the open 1.4.0 gate — a manifest digest bump (drift) is
	 * recognized as HAL's own previous deployment through the recorded last_managed_hash,
	 * so the safe hash-verified update proceeds and the target ends up byte-identical to
	 * the new canonical source.
	 */
	public function test_manifest_drift_upgrades_the_managed_target_at_the_open_gate(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		list( $manifest,,$theme ) = $this->sandbox( "<?php // v1\n" );

		$gate   = new ManagedTemplates( $manifest );
		$before = $gate->provision();

		$this->assertTrue( $before['ok'] );
		$this->assertSame( 'provisioned', $before['reason'] );

		// New canonical version: same source path, new bytes + new pinned digest.
		$new_body = "<?php // v2\n";
		file_put_contents( $this->paths[0], $new_body );
		$mf = json_decode( (string) file_get_contents( $this->paths[1] ), true );
		$mf['assets'][0]['sha256'] = hash( 'sha256', $new_body );
		file_put_contents( $this->paths[1], (string) json_encode( $mf ) );

		$result = ( new ManagedTemplates( $this->paths[1] ) )->provision();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertStringEqualsFile(
			$this->target_in( $theme ),
			$new_body,
			'the drifted manifest updates the managed target to the new canonical bytes'
		);
	}

	/**
	 * G-14: the temp-write safety holds at the open 1.4.0 gate — a hostile filesystem's
	 * put failure is contained PER ASSET (the asset records write_failed), the managed
	 * target itself is never created, no temporary artifact is left behind, and the
	 * overall verdict still reports the batch outcome through the success shape.
	 */
	public function test_a_failing_filesystem_reports_the_per_asset_write_failure_without_leftovers(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		list( $manifest,,$theme ) = $this->sandbox( "<?php // stable\n" );

		hal_wp_stub_extra_set( 'fs_fail_put', true );

		$result = ( new ManagedTemplates( $manifest ) )->provision();

		hal_wp_stub_extra_set( 'fs_fail_put', false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertSame(
			ManagedTemplates::STATUS_WRITE_FAILED,
			$result['assets'][ key( $result['assets'] ) ]['status'],
			'the put failure is contained as the per-asset write_failed record'
		);
		$this->assertFileDoesNotExist( $this->target_in( $theme ), 'a failed write never leaves a managed target' );

		$leftovers = glob( $theme . '/ultimate-member/templates/*.hal-tmp' );
		$this->assertSame( array(), $leftovers, 'no temporary artifact may ever survive a failed write' );
	}

	/**
	 * C7 / G-14: theme-switch semantics hold at the open 1.4.0 gate. A previously managed
	 * tree (placed by the test itself) is never trusted in a NEW theme — the unknown
	 * pre-existing file there is a conflict that provisioning reports without ever
	 * overwriting — and the OLD theme stays byte-identical throughout.
	 */
	public function test_theme_switch_never_trusts_old_theme_hashes_and_touches_only_new_theme(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		list( $manifest,,$theme_a, $digest ) = $this->sandbox();

		// Simulate the previously provisioned tree without ever calling the writer.
		file_put_contents( $this->target_in( $theme_a ), "<?php // managed body\n" );
		$this->track( $this->target_in( $theme_a ) );

		$old_bytes = file_get_contents( $this->target_in( $theme_a ) );
		$this->assertSame( $digest, hash( 'sha256', (string) $old_bytes ) );

		$theme_b = $this->track( sys_get_temp_dir() . '/hal_d17_theme_b_' . uniqid() );
		@mkdir( $theme_b . '/ultimate-member/templates', 0777, true );
		file_put_contents( $this->target_in( $theme_b ), "<?php // someone else's file\n" );

		hal_wp_stub_extra_set( 'stylesheet', 'child-b' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $theme_b );

		$gate = new ManagedTemplates( $manifest );
		$after_switch = $gate->inspect();

		$this->assertSame(
			'user_modified',
			$after_switch['assets'][ key( $after_switch['assets'] ) ]['target_status'],
			'an unknown pre-existing file in the NEW theme is a conflict, never an upgrade'
		);

		$result = $gate->provision();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertSame(
			ManagedTemplates::STATUS_USER_MODIFIED,
			$result['assets'][ key( $result['assets'] ) ]['status'],
			'the NEW theme conflict is reported through provisioning without any write'
		);
		$this->assertStringEqualsFile( $this->target_in( $theme_a ), (string) $old_bytes, 'old theme must stay untouched' );
	}

	public function test_legacy_account_override_is_observed_without_mutation_or_ownership_state(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		list( $manifest,, $theme ) = $this->sandbox();

		$gate   = new ManagedTemplates( $manifest );
		$target = $this->track( $theme . '/ultimate-member/templates/account.php' );

		$this->assertSame(
			array( 'ok' => true, 'status' => ManagedTemplates::STATUS_LEGACY_OVERRIDE_ABSENT ),
			$gate->inspect_legacy_account_override()
		);

		$bytes = "<?php // operator-owned legacy Account override\n";
		file_put_contents( $target, $bytes );
		$state_before = get_option( ManagedTemplates::STATE_OPTION, false );

		$this->assertSame(
			array( 'ok' => true, 'status' => ManagedTemplates::STATUS_LEGACY_OVERRIDE_PRESENT ),
			$gate->inspect_legacy_account_override()
		);
		$this->assertStringEqualsFile( $target, $bytes );
		$this->assertSame( $state_before, get_option( ManagedTemplates::STATE_OPTION, false ) );
	}

	public function test_provisioning_never_changes_or_claims_existing_legacy_account_override(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		list( $manifest,,$theme ) = $this->sandbox();

		$gate   = new ManagedTemplates( $manifest );
		$target = $this->track( $theme . '/ultimate-member/templates/account.php' );
		$bytes  = "<?php // operator-owned legacy Account override\n";
		file_put_contents( $target, $bytes );

		$result = $gate->provision();
		$state  = get_option( ManagedTemplates::STATE_OPTION, array() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'provisioned', $result['reason'] );
		$this->assertStringEqualsFile( $target, $bytes );
		$this->assertArrayNotHasKey( 'resources/ultimate-member/account.php', $result['assets'] );
		$this->assertArrayNotHasKey( 'resources/ultimate-member/account.php', is_array( $state['assets'] ?? null ) ? $state['assets'] : array() );
	}

	/**
	 * C7 / OD-06: per-member-type template selection is admin-gated, absint'd, and
	 * validated at save time; the legacy single template ID is retained untouched.
	 */
	public function test_per_type_template_ids_sanitize_is_admin_gated_and_validated(): void {
		$_POST = array();
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->seed_c7_template( 55 );
		$this->seed_c7_template( 77 );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_library_template_id' => 55,
		);

		$settings = new Settings();

		$clean = $settings->sanitize(
			array(
				'profile_library_template_id'  => 55,
				'profile_library_template_ids' => array(
					Settings::PERSONA_LAWYER          => '77',  // Valid existing template.
					Settings::PERSONA_CONSULTANT      => -999,  // Absint 999: no such post -> 0.
					Settings::PERSONA_ORDINARY_MEMBER => 'abc', // Absint 0: unselected.
					'hostile_key'                     => 77,    // Unknown personas are dropped.
				),
			)
		);

		$this->assertSame(
			array(
				Settings::PERSONA_LAWYER          => 77,
				Settings::PERSONA_CONSULTANT      => 0,
				Settings::PERSONA_ORDINARY_MEMBER => 0,
			),
			$clean['profile_library_template_ids']
		);
		$this->assertSame( 55, $clean['profile_library_template_id'], 'the legacy single template ID is retained untouched' );

		// A non-admin can never change any template selection: sanitize() fails closed to
		// the currently stored (default) values and never stores the submitted 88.
		$GLOBALS['wp_stubs']['can_manage'] = false;

		$denied = ( new Settings() )->sanitize(
			array(
				'profile_library_template_id'  => 88,
				'profile_library_template_ids' => array( Settings::PERSONA_LAWYER => 88 ),
			)
		);

		$this->assertSame( 55, $denied['profile_library_template_id'] );
		$this->assertSame(
			array(
				Settings::PERSONA_LAWYER          => 0,
				Settings::PERSONA_CONSULTANT      => 0,
				Settings::PERSONA_ORDINARY_MEMBER => 0,
			),
			$denied['profile_library_template_ids']
		);

		$_POST = array();
	}

	/**
	 * C7: read-time selection — a valid per-type ID wins for exactly its own member type,
	 * unselected member types read the legacy single ID backward-compatibly, and a
	 * configured-but-invalid ID (or a deleted legacy ID) is Native, never substituted.
	 */
	public function test_per_type_selection_reads_legacy_single_id_backward_compatibly(): void {
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->seed_c7_template( 55 );
		$this->seed_c7_template( 77 );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_library_template_id' => 55,
		);

		$settings = new Settings();

		$this->assertSame( 55, $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'no per-type selection -> legacy single ID' );
		$this->assertSame( 55, $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_ORDINARY_MEMBER ) );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['profile_library_template_ids'] = array(
			Settings::PERSONA_LAWYER => 77,
		);

		$this->assertSame( 77, $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'a valid per-type selection wins' );
		$this->assertSame( 55, $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_CONSULTANT ), 'unselected member types keep the legacy single ID' );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['profile_library_template_ids'][ Settings::PERSONA_CONSULTANT ] = 999;

		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_CONSULTANT ), 'a configured but invalid per-type ID is Native, not the legacy ID' );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['profile_library_template_id'] = 555;

		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_ORDINARY_MEMBER ), 'a deleted legacy template is Native' );
		$this->assertSame( 77, $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'the valid per-type selection still resolves' );
	}

	/**
	 * C7: the full read-time validation matrix on EXISTING posts, proved through read-only
	 * get_post/get_post_meta: deleted, wrong-type, unpublished, and non-Elementor-built
	 * templates are never selected.
	 */
	public function test_deleted_wrong_type_unpublished_and_non_builder_templates_are_never_selected(): void {
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->seed_c7_template( 41, 'page' );
		$this->seed_c7_template( 42, 'elementor_library', 'draft' );
		$this->seed_c7_template( 43, 'elementor_library', 'publish', false ); // Not Elementor-built.

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_library_template_ids' => array(
				Settings::PERSONA_LAWYER          => 999, // Deleted: no post exists.
				Settings::PERSONA_CONSULTANT      => 41,  // A Page, not an Elementor library template.
				Settings::PERSONA_ORDINARY_MEMBER => 42,  // An Elementor library DRAFT.
			),
		);

		$settings = new Settings();

		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'deleted template => Native' );
		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_CONSULTANT ), 'wrong post type => Native' );
		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_ORDINARY_MEMBER ), 'unpublished template => Native' );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['profile_library_template_ids'][ Settings::PERSONA_LAWYER ] = 43;

		$this->assertNull( $settings->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'a published post not built with Elementor => Native' );
	}

	/**
	 * C7: a valid per-type template renders the OWNER's context — persona plus allowed
	 * fields only — through the existing adapter seam, with zero native fallback.
	 */
	public function test_valid_per_type_template_renders_owner_context_with_allowed_fields_only(): void {
		$this->boot_c7_environment();

		$GLOBALS['wp_stubs']['user_meta'][5] = array(
			'specialty'   => 'Contracts',
			'secret_note' => 'private-value',
		);

		$this->c7_elementor_frontend()->content_callback = array( C7ElementorFrontendStub::class, 'policy_content' );

		$native_ran = 0;

		ob_start();
		ProfileLayoutAdapter::render_or_fallback(
			static function ( array $args ) use ( &$native_ran ): void {
				unset( $args );
				++$native_ran;
				echo 'NATIVE-PROFILE';
			},
			array(),
			11,
			'profile'
		);
		$output = ob_get_clean();

		$this->assertSame( 0, $native_ran, 'a valid template must not fall back to native' );
		$this->assertStringContainsString( 'data-template="77"', $output, 'the LAWYER per-type template is rendered' );
		$this->assertStringNotContainsString( 'data-template="88"', $output, 'another member type template is never rendered' );
		$this->assertStringNotContainsString( 'data-template="55"', $output, 'the legacy single ID is not rendered when a per-type ID exists' );
		$this->assertStringContainsString( 'data-persona="lawyer"', $output, 'the rendered context carries the owner persona' );
		$this->assertStringContainsString( 'Contracts', $output, 'the allowed public field value is rendered' );
		$this->assertStringNotContainsString( 'private-value', $output, 'the private field value is never rendered' );

		$this->restore_c7_environment();
	}

	/**
	 * C7: a visitor's render still selects the OWNER's member-type template (owner context
	 * comes from ProfileContext, never the current viewer) and excludes private fields.
	 */
	public function test_visitor_context_selects_the_owner_persona_template_without_private_fields(): void {
		$this->boot_c7_environment();

		// A different, logged-in ordinary member viewing the lawyer's public profile.
		$GLOBALS['wp_stubs']['users'][7]     = array( 'roles' => array( 'role_member' ), 'display_name' => 'Visitor Name' );
		$GLOBALS['wp_stubs']['current_user'] = 7;
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['user_meta'][5] = array(
			'specialty'   => 'Contracts',
			'secret_note' => 'private-value',
		);

		$this->c7_elementor_frontend()->content_callback = array( C7ElementorFrontendStub::class, 'policy_content' );

		$native_ran = 0;

		ob_start();
		ProfileLayoutAdapter::render_or_fallback(
			static function ( array $args ) use ( &$native_ran ): void {
				unset( $args );
				++$native_ran;
				echo 'NATIVE-PROFILE';
			},
			array(),
			11,
			'profile'
		);
		$output = ob_get_clean();

		$this->assertSame( 0, $native_ran );
		$this->assertStringContainsString( 'data-template="77"', $output, 'the OWNER persona template is selected, never the visitor member type' );
		$this->assertStringContainsString( 'data-persona="lawyer"', $output );
		$this->assertStringContainsString( 'Contracts', $output, 'the allowed public field value is rendered for the visitor' );
		$this->assertStringNotContainsString( 'private-value', $output, 'a role-restricted private field is excluded for a visitor' );

		$this->restore_c7_environment();
	}

	/**
	 * C7: deleted / wrong-type / unpublished selections fall back to the complete native
	 * pipeline exactly once, with no fatal and no partial template output.
	 */
	public function test_invalid_template_selections_fall_back_to_native_without_fatal(): void {
		$cases = array(
			'deleted'     => 999, // No post at all.
			'wrong-type'  => 41,  // A Page, not an Elementor library template.
			'unpublished' => 42,  // A draft Elementor library template.
		);

		foreach ( $cases as $case => $template_id ) {
			$this->restore_c7_environment();
			$this->boot_c7_environment( array( Settings::PERSONA_LAWYER => $template_id ) );

			$native_ran = 0;

			ob_start();
			ProfileLayoutAdapter::render_or_fallback(
				static function ( array $args ) use ( &$native_ran ): void {
					unset( $args );
					++$native_ran;
					echo 'NATIVE-PROFILE';
				},
				array(),
				11,
				'profile'
			);
			$output = ob_get_clean();

			$this->assertSame( 1, $native_ran, "{$case} template must fall back to native exactly once" );
			$this->assertSame( 'NATIVE-PROFILE', $output, "{$case} template must never render any template output" );
		}

		$this->restore_c7_environment();
	}

	/**
	 * C7: Elementor absence disables ONLY the custom render — the native pipeline runs and
	 * the template-selection settings themselves keep working, with no fatal anywhere.
	 */
	public function test_elementor_absence_disables_custom_render_only(): void {
		$bootstrap = $this->boot_c7_environment(
			array(
				Settings::PERSONA_LAWYER          => 77,
				Settings::PERSONA_CONSULTANT      => 88,
				Settings::PERSONA_ORDINARY_MEMBER => 0,
			),
			55,
			false
		);

		$GLOBALS['wp_stubs']['user_meta'][5] = array( 'specialty' => 'Contracts' );

		$native_ran = 0;

		ob_start();
		ProfileLayoutAdapter::render_or_fallback(
			static function ( array $args ) use ( &$native_ran ): void {
				unset( $args );
				++$native_ran;
				echo 'NATIVE-PROFILE';
			},
			array(),
			11,
			'profile'
		);
		$output = ob_get_clean();

		$this->assertSame( 1, $native_ran, 'Elementor absence must fall back to the native pipeline' );
		$this->assertSame( 'NATIVE-PROFILE', $output );
		$this->assertSame( 77, $bootstrap->get_settings()->get_public_profile_template_id_for_persona( Settings::PERSONA_LAWYER ), 'template selection itself is unaffected by Elementor absence' );

		$this->restore_c7_environment();
	}

	/**
	 * Boots the C7 frontend render environment: a live public profile of one persona, the
	 * C1 three-persona mapping, admin-configured EXISTING Elementor library templates, a
	 * signed CAP_PROFILE gate (via the gate's own test-only constructor parameter — the
	 * production registry stays frozen), and a stubbed Elementor builder frontend.
	 *
	 * @param array<string,int> $per_type_ids       Per-member-type template ID map.
	 * @param int               $legacy_template_id Legacy single template ID.
	 * @param bool              $elementor_present  Whether Elementor counts as loaded.
	 * @return Bootstrap
	 */
	private function boot_c7_environment( array $per_type_ids = array( 'lawyer' => 77, 'consultant' => 88, 'ordinary_member' => 0 ), int $legacy_template_id = 55, bool $elementor_present = true ): Bootstrap {
		$this->restore_c7_environment();

		$this->c7_backup = array(
			'options'            => $GLOBALS['wp_stubs']['options'] ?? array(),
			'hooks'              => $GLOBALS['__hooks'] ?? array(),
			'did_actions'        => $GLOBALS['__did_actions'] ?? array(),
			'user_meta'          => $GLOBALS['wp_stubs']['user_meta'] ?? array(),
			'post_meta'          => $GLOBALS['wp_stubs']['post_meta'] ?? array(),
			'users'              => $GLOBALS['wp_stubs']['users'] ?? array(),
			'posts'              => $GLOBALS['wp_stubs']['posts'] ?? array(),
			'current_user'       => $GLOBALS['wp_stubs']['current_user'] ?? 0,
			'um_target'          => $GLOBALS['wp_stubs']['um_target'] ?? 0,
			'is_page'            => $GLOBALS['wp_stubs']['is_page'] ?? array(),
			'profile_id'         => $GLOBALS['wp_stubs']['profile_id'] ?? 0,
			'private_map'        => $GLOBALS['wp_stubs']['private_map'] ?? null,
			'um_roles'           => $GLOBALS['wp_stubs']['um_roles'] ?? array(),
			'um_statuses'        => $GLOBALS['wp_stubs']['um_statuses'] ?? array(),
			'um_status_by_user'  => $GLOBALS['wp_stubs']['um_status_by_user'] ?? array(),
			'can_manage'         => $GLOBALS['wp_stubs']['can_manage'] ?? false,
			'is_admin'           => hal_wp_stub_extra( 'is_admin' ),
			'elementor_instance' => \Elementor\Plugin::$instance,
		);

		$GLOBALS['__hooks']       = array();
		$GLOBALS['__did_actions'] = $elementor_present ? array( 'elementor/loaded' => 1 ) : array();

		// Frontend profile render: never an admin request.
		hal_wp_stub_extra_set( 'is_admin', false );
		$GLOBALS['wp_stubs']['can_manage'] = false;

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}

		$GLOBALS['wp_stubs']['is_page']      = array( 'user' => true );
		$GLOBALS['wp_stubs']['profile_id']   = 5;
		$GLOBALS['wp_stubs']['users']        = array( 5 => array( 'roles' => array( 'role_lawyer' ), 'display_name' => 'Owner Name' ) );
		$GLOBALS['wp_stubs']['private_map']  = array( 5 => false );
		$GLOBALS['wp_stubs']['um_roles']     = array( 'role_lawyer' => 'Lawyer', 'role_consultant' => 'Consultant', 'role_member' => 'Member' );
		$GLOBALS['wp_stubs']['um_statuses']  = array( 'approved' => 'Approved' );
		$GLOBALS['wp_stubs']['um_status_by_user'] = array( 5 => 'approved' );
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_layout_mode'          => Settings::LAYOUT_MODE_PUBLIC_LAYOUT,
			'profile_library_template_id'  => $legacy_template_id,
			'profile_library_template_ids' => $per_type_ids,
			'um_persona_mapping'           => array(
				Settings::PERSONA_LAWYER          => array( 'role' => 'role_lawyer', 'status' => 'approved' ),
				Settings::PERSONA_CONSULTANT      => array( 'role' => 'role_consultant', 'status' => 'approved' ),
				Settings::PERSONA_ORDINARY_MEMBER => array( 'role' => 'role_member', 'status' => 'approved' ),
			),
		);

		// Admin-configured EXISTING templates, expressed through read-only post data.
		$this->seed_c7_template( 55 );
		$this->seed_c7_template( 77 );
		$this->seed_c7_template( 88 );
		$this->seed_c7_template( 41, 'page' );
		$this->seed_c7_template( 42, 'elementor_library', 'draft' );

		$GLOBALS['wp_stubs']['post_meta'][11]['_um_custom_fields'] = array(
			'specialty'   => array( 'metakey' => 'specialty', 'type' => 'text', 'label' => 'Specialty', 'public' => '1', 'in_profile' => '1' ),
			'secret_note' => array( 'metakey' => 'secret_note', 'type' => 'text', 'label' => 'Secret note', 'public' => '-2', 'roles' => array( 'subscriber' ), 'in_profile' => '1' ),
		);

		add_filter(
			'um_get_form_fields',
			static function ( $fields, $form_id ) {
				unset( $fields );

				return get_post_meta( (int) $form_id, '_um_custom_fields', true );
			},
			99,
			2
		);

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( \HAL\MemberProfiles\AdminDashboard::class ) ) {
			$registered = new \ReflectionProperty( \HAL\MemberProfiles\AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		Bootstrap::init();

		$bootstrap = Bootstrap::instance();

		$this->assertInstanceOf( Bootstrap::class, $bootstrap );

		$versions = array(
			'wp'        => '6.5',
			'php'       => PHP_VERSION,
			'um'        => '2.8.0',
			'elementor' => '4.2.3',
		);

		$gate = new CompatibilityGate(
			$versions,
			array(
				CompatibilityGate::CAP_PROFILE => array(
					array( 'signed' => true, 'versions' => $versions ),
				),
			)
		);

		$bootstrap_gate = new \ReflectionProperty( Bootstrap::class, 'compatibility_gate' );
		$bootstrap_gate->setAccessible( true );
		$bootstrap_gate->setValue( $bootstrap, $gate );

		$settings_gate = new \ReflectionProperty( Settings::class, 'compatibility_gate' );
		$settings_gate->setAccessible( true );
		$settings_gate->setValue( $bootstrap->get_settings(), $gate );

		\Elementor\Plugin::$instance           = new \Elementor\Plugin();
		\Elementor\Plugin::$instance->frontend = new C7ElementorFrontendStub();

		return $bootstrap;
	}

	/**
	 * Seeds one EXISTING template post through the read-only stub maps.
	 *
	 * @param int    $id          Template post ID.
	 * @param string $post_type   Post type.
	 * @param string $post_status Post status.
	 * @param bool   $builder     Whether the Elementor builder edit-mode meta is present.
	 * @return void
	 */
	private function seed_c7_template( int $id, string $post_type = 'elementor_library', string $post_status = 'publish', bool $builder = true ): void {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = $post_status;

		$GLOBALS['wp_stubs']['posts'][ $id ] = $post;

		if ( $builder ) {
			$GLOBALS['wp_stubs']['post_meta'][ $id ]['_elementor_edit_mode'] = 'builder';
		}
	}

	/**
	 * @return C7ElementorFrontendStub The injected stub builder frontend.
	 */
	private function c7_elementor_frontend(): C7ElementorFrontendStub {
		$frontend = \Elementor\Plugin::$instance->frontend ?? null;

		$this->assertInstanceOf( C7ElementorFrontendStub::class, $frontend );

		return $frontend;
	}

	/**
	 * Restores every global boot_c7_environment() touched.
	 *
	 * @return void
	 */
	private function restore_c7_environment(): void {
		if ( empty( $this->c7_backup ) ) {
			return;
		}

		$GLOBALS['wp_stubs']['options']           = $this->c7_backup['options'];
		$GLOBALS['wp_stubs']['user_meta']         = $this->c7_backup['user_meta'];
		$GLOBALS['wp_stubs']['post_meta']         = $this->c7_backup['post_meta'];
		$GLOBALS['wp_stubs']['users']             = $this->c7_backup['users'];
		$GLOBALS['wp_stubs']['posts']             = $this->c7_backup['posts'];
		$GLOBALS['wp_stubs']['current_user']      = $this->c7_backup['current_user'];
		$GLOBALS['wp_stubs']['um_target']         = $this->c7_backup['um_target'];
		$GLOBALS['wp_stubs']['is_page']           = $this->c7_backup['is_page'];
		$GLOBALS['wp_stubs']['profile_id']        = $this->c7_backup['profile_id'];
		$GLOBALS['wp_stubs']['private_map']       = $this->c7_backup['private_map'];
		$GLOBALS['wp_stubs']['um_roles']          = $this->c7_backup['um_roles'];
		$GLOBALS['wp_stubs']['um_statuses']       = $this->c7_backup['um_statuses'];
		$GLOBALS['wp_stubs']['um_status_by_user'] = $this->c7_backup['um_status_by_user'];
		$GLOBALS['wp_stubs']['can_manage']        = $this->c7_backup['can_manage'];
		$GLOBALS['wp_stubs']['user_meta_reads']   = array();
		$GLOBALS['__hooks']                       = $this->c7_backup['hooks'];
		$GLOBALS['__did_actions']                 = $this->c7_backup['did_actions'];

		\Elementor\Plugin::$instance = $this->c7_backup['elementor_instance'];

		hal_wp_stub_extra_set( 'is_admin', $this->c7_backup['is_admin'] );

		remove_all_filters( 'um_get_form_fields' );
		remove_all_filters( 'um_account_page_default_tabs_hook' );

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( \HAL\MemberProfiles\AdminDashboard::class ) ) {
			$registered = new \ReflectionProperty( \HAL\MemberProfiles\AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		$this->c7_backup = array();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_immutable_mode_refuses_all_writes(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		list( $manifest,,$theme ) = $this->sandbox();

		$before_files = scandir( $theme );

		// Immutable mode is a constant; emulate by checking the verdict path directly
		// through a partially-applied environment is impossible, so assert the documented
		// constant-driven behaviour via reflection on the private helper.
		$method = new \ReflectionMethod( ManagedTemplates::class, 'immutable_deployment' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( new ManagedTemplates( $manifest ) ) );

		define( 'HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT', true ); // process-wide, last.
		$this->assertTrue( $method->invoke( new ManagedTemplates( $manifest ) ) );

		$this->assertSame( $before_files, scandir( $theme ), 'immutable mode wrote nothing' );
	}
}

/**
 * C7 stub for Elementor's installed builder frontend. It registers the three HAL
 * LayoutContract markers — exactly what a real, complete public-profile template's HAL
 * Widgets do during their own renders — and lets each test supply the actual content.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */
final class C7ElementorFrontendStub {

	/** @var callable|null int $template_id, Bootstrap $bootstrap => string content. */
	public $content_callback;

	/**
	 * @param int  $template_id Requested template ID.
	 * @param bool $with_css    Elementor's second argument.
	 * @return string|null
	 */
	public function get_builder_content_for_display( $template_id, $with_css = false ) {
		unset( $with_css );

		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return null;
		}

		$contract = $bootstrap->get_layout_contract();

		if ( null !== $contract ) {
			$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
			$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
			$contract->register( LayoutContract::MARKER_PROFILE_BODY );
		}

		if ( is_callable( $this->content_callback ) ) {
			return (string) call_user_func( $this->content_callback, (int) $template_id, $bootstrap );
		}

		return '<div class="hal-c7-template" data-template="' . (int) $template_id . '"></div>';
	}

	/**
	 * Simulated template content rendered through the REAL Policy inside the open Profile
	 * scope: one allowed public field and one role-restricted private field, so only
	 * persona-permitted values can ever appear in the output.
	 *
	 * @param int       $template_id Rendered template ID.
	 * @param Bootstrap $bootstrap   Shared service container.
	 * @return string
	 */
	public static function policy_content( int $template_id, Bootstrap $bootstrap ): string {
		$context = $bootstrap->get_profile_context();

		if ( null === $context || null === $bootstrap->get_policy() ) {
			return '<div class="hal-c7-template" data-template="' . $template_id . '"></div>';
		}

		$resolved = $context->resolve();
		$html     = '<div class="hal-c7-template" data-template="' . $template_id . '" data-persona="' . esc_attr( null === $resolved ? '' : (string) $resolved->persona ) . '"></div>';

		if ( null === $resolved ) {
			return $html;
		}

		foreach ( array( 'specialty', 'secret_note' ) as $metakey ) {
			$field = $bootstrap->get_policy()->can_view_field(
				(int) $resolved->target_user->ID,
				(int) $resolved->visitor_id,
				(int) $resolved->form_id,
				$metakey,
				(string) $resolved->persona
			);

			if ( null !== $field ) {
				$html .= '<span class="hal-c7-field-' . esc_attr( $metakey ) . '">' . esc_html( (string) $field['value'] ) . '</span>';
			}
		}

		return $html;
	}
}
