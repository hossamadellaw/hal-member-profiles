<?php
/**
 * Unit tests for card F-05: the version-gated Sync surface and the consolidated
 * read-only Readiness card on the Diagnostics page, plus the ManagedTemplates gate
 * mirror helper.
 *
 * Pinned contracts:
 * - On every 1.2.x release the active Sync form (its caption and description
 *   "actively promise disabled writes" per E-14) is replaced by the passive row
 *   "Writes are disabled in this release by the version gate — see Diagnostics."; the
 *   original form returns verbatim once writes_approved_for_version() turns true.
 * - The Readiness card renders all six checks (a) managed files via inspect(), (b) the
 *   gate describe()/passes() verdict per capability, (c) UM member mapping, (d)
 *   per-persona template validity, (e) the CURRENT `_um_profile_template` selection for
 *   EVERY `um_form` post — each WPML-translated copy enumerated as its own row — with
 *   the referenced template file's presence in the ACTIVE CHILD THEME, and (f) the F-11
 *   contract pre-check behind the class_exists degradation guard.
 * - Every read in the card is non-mutating: full snapshot proof (options, meta,
 *   transients, counters, HTTP, child-theme filesystem).
 * - writes_approved_for_version() mirrors the F-01 gate with ONE source of truth.
 * - Round-4: with the validator class absent, the contract row reads
 *   "unavailable (validator not deployed)" — never a false pass.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles;

/**
 * Card F-05 test seam (test-file-only, transparent). The global get_posts() unit stub
 * always returns an empty array and is shared by the whole suite, but the readiness
 * card must prove it enumerates EVERY um_form post (each WPML-translated copy
 * included). When $GLOBALS['wp_stubs_extra']['readiness_get_posts'] is set to an
 * array, this namespace-scoped override returns exactly that value; otherwise it
 * delegates to the global function, so the stub environment, every other test in this
 * process, and real WordPress all behave exactly as before. No production file is
 * touched by this seam.
 *
 * @param array $args Query args (unchanged).
 * @return array
 */
function get_posts( $args ) {
	$configured = $GLOBALS['wp_stubs_extra']['readiness_get_posts'] ?? null;

	if ( null === $configured ) {
		return \get_posts( $args );
	}

	return is_array( $configured ) ? $configured : array();
}

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AdminDashboard;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\ManagedTemplates;
use HAL\MemberProfiles\Settings;
use HAL\MemberProfiles\TemplateContractValidator;
use HAL\MemberProfiles\UmFormTemplateWatcher;
use PHPUnit\Framework\TestCase;

// The whole unit process pins a 1.2.x release (shared with the ManagedTemplates,
// Lifecycle, and RuntimeEvidenceReporter suites) so the F-01 gate stays closed exactly
// like production 1.2.x.
if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
	define( 'HAL_MEMBER_PROFILES_VERSION', '1.2.0' );
}

final class AdminDashboardReadinessTest extends TestCase {

	/** @var string[] Absolute paths of the per-test child-theme temp dirs. */
	private array $theme_dirs = array();

	/** @var array Backup of the stub environment taken in setUp(). */
	private array $stub_backup = array();

	protected function setUp(): void {
		$this->stub_backup = array(
			'wp_stubs'       => $GLOBALS['wp_stubs'],
			'wp_stubs_extra' => $GLOBALS['wp_stubs_extra'],
			'hooks'          => $GLOBALS['__hooks'] ?? array(),
		);

		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_extra_set(
			'counters',
			array(
				'update_option'    => 0,
				'delete_option'    => 0,
				'delete_transient' => 0,
				'add_menu_page'    => 0,
				'add_submenu_page' => 0,
				'add_options_page' => 0,
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->theme_dirs as $dir ) {
			$this->remove_dir_recursive( $dir );
		}

		$this->theme_dirs = array();

		$GLOBALS['wp_stubs']       = $this->stub_backup['wp_stubs'];
		$GLOBALS['wp_stubs_extra'] = $this->stub_backup['wp_stubs_extra'];
		$GLOBALS['__hooks']        = $this->stub_backup['hooks'];

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	// ------------------------------------------------------------------
	// (iv) The helper mirrors the F-01 gate with a single source of truth.
	// ------------------------------------------------------------------

	public function test_writes_approved_for_version_mirrors_the_f01_gate(): void {
		$this->assertTrue(
			version_compare( HAL_MEMBER_PROFILES_VERSION, '1.3.0', '<' ),
			'this proof is only meaningful while the unit process pins a pre-1.3.0 version'
		);

		$managed  = new ManagedTemplates();
		$approved = $managed->writes_approved_for_version();

		$gate = new \ReflectionMethod( ManagedTemplates::class, 'writes_disapproved' );
		$gate->setAccessible( true );

		$this->assertSame( ! $gate->invoke( $managed ), $approved, 'the public helper must be the exact negation of the F-01 gate read' );
		$this->assertFalse( $approved, 'on 1.2.x the F-01 gate disapproves writes and the mirror must report exactly that' );

		// Single source of truth: the helper body must DELEGATE to writes_disapproved()
		// — no second version comparison, no second constant read that could drift from
		// the verdict provision() actually enforces.
		$body   = $this->method_source( HAL_MEMBER_PROFILES_PLUGIN_DIR . 'includes/ManagedTemplates.php', ManagedTemplates::class, 'writes_approved_for_version' );
		$this->assertStringNotContainsString( 'version_compare', $body, 'no second version comparison may exist in the helper' );
		$this->assertStringNotContainsString( 'WRITES_APPROVED_SINCE', $body, 'no second constant read may exist in the helper' );
		$this->assertStringContainsString( 'writes_disapproved', $body, 'the helper must delegate to the F-01 gate read' );
	}

	// ------------------------------------------------------------------
	// (i) On 1.2.x no active Sync form — the passive row instead.
	// ------------------------------------------------------------------

	public function test_on_1_2_x_the_active_sync_form_is_replaced_by_the_passive_row(): void {
		hal_wp_stub_extra_set( 'stylesheet', 'hal-child' );
		hal_wp_stub_extra_set( 'template', 'hal-parent' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->make_theme_dir( array() ) );

		$out = $this->invoke_static( 'render_managed_templates_block' );

		$this->assertStringContainsString(
			'Writes are disabled in this release by the version gate — see Diagnostics.',
			$out,
			'the exact passive info row required by card F-05 must render'
		);
		$this->assertStringNotContainsString( 'Sync managed templates now', $out, 'the false-promise caption must never render on 1.2.x' );
		$this->assertStringNotContainsString( 'Sync creates missing managed templates', $out, 'the false-promise description must never render on 1.2.x' );
		$this->assertStringNotContainsString( '<form', $out, 'no active Sync form may render on 1.2.x' );
	}

	/**
	 * The approved-state branch: the existing form — extraction verified verbatim
	 * against the pre-F-05 markup (caption, description, D-05 endpoint and nonce) —
	 * returns exactly when writes_approved_for_version() turns true (from 1.3.0).
	 */
	public function test_the_original_sync_form_returns_verbatim_for_the_approved_state(): void {
		$out = $this->invoke_static( 'render_sync_form' );

		$this->assertStringContainsString( '<form method="post"', $out );
		$this->assertStringContainsString( 'admin-post.php', $out, 'the delegated D-05 endpoint' );
		$this->assertStringContainsString( 'hal_member_profiles_repair', $out, 'the guarded Lifecycle action' );
		$this->assertStringContainsString( 'Sync managed templates now', $out, 'the original caption' );
		$this->assertStringContainsString(
			'Sync creates missing managed templates and safely updates only files HAL deployed itself; your modifications are always reported as conflicts first.',
			$out,
			'the original description, verbatim'
		);
	}

	// ------------------------------------------------------------------
	// (ii) The card renders all six checks.
	// ------------------------------------------------------------------

	public function test_readiness_card_renders_all_six_checks(): void {
		$out = $this->invoke_static( 'render_readiness_card', null );

		// (a) managed files via inspect(); (b) gate per capability; (c) UM mapping;
		// (d) per-persona validity; (e) UM Forms selection; (f) contract pre-check.
		$this->assertStringContainsString( 'Managed files', $out );
		$this->assertStringContainsString( 'Compatibility gate', $out );
		$this->assertStringContainsString( 'UM member mapping', $out );
		$this->assertStringContainsString( 'Persona templates', $out );
		$this->assertStringContainsString( 'UM Forms (um_form)', $out );
		$this->assertStringContainsString( 'Template contract (pre-check)', $out );
	}

	/**
	 * Check (e) enumeration semantics: every um_form post is its own row — the WPML
	 * case, where a translated form copy is a separate um_form post carrying its own
	 * `_um_profile_template` — and each non-empty selection reports whether the
	 * referenced template file exists in the ACTIVE CHILD THEME (real file_exists
	 * against a real temp directory; no stub asserts anything).
	 */
	public function test_readiness_card_enumerates_every_um_form_copy_including_wpml_translations(): void {
		hal_wp_stub_extra_set( 'stylesheet', 'hal-child' );
		hal_wp_stub_extra_set( 'template', 'hal-parent' );
		$theme = $this->make_theme_dir(
			array(
				'ultimate-member/templates/hal_profile_bridge.php' => "<?php // managed bridge fixture\n",
			)
		);
		hal_wp_stub_extra_set( 'stylesheet_dir', $theme );

		hal_wp_stub_extra_set(
			'readiness_get_posts',
			array(
				(object) array(
					'ID'          => 101,
					'post_type'   => 'um_form',
					'post_title'  => 'Default Profile',
					'post_status' => 'publish',
				),
				(object) array(
					'ID'          => 102,
					'post_type'   => 'um_form',
					'post_title'  => 'Default Profile — AR (WPML copy)',
					'post_status' => 'publish',
				),
			)
		);

		$GLOBALS['wp_stubs']['post_meta'][101][ UmFormTemplateWatcher::WATCHED_META_KEY ] = 'hal_profile_bridge';
		$GLOBALS['wp_stubs']['post_meta'][102][ UmFormTemplateWatcher::WATCHED_META_KEY ] = 'legacy_profile_9';

		$out = $this->invoke_static( 'render_readiness_card', null );

		$this->assertStringContainsString( 'UM form #101 — Default Profile', $out, 'the first form row' );
		$this->assertStringContainsString( 'template file present (ultimate-member/templates/hal_profile_bridge.php)', $out, 'presence is verified against the real child theme' );
		$this->assertStringContainsString( 'UM form #102 — Default Profile — AR (WPML copy)', $out, 'the WPML-translated copy is its own enumerated row' );
		$this->assertStringContainsString( 'template file NOT found in the active child theme', $out, 'a missing target file must read blocked, never guessed' );
		$this->assertStringNotContainsString( 'none found', $out, 'enumerated forms replace the empty fallback row' );
	}

	// ------------------------------------------------------------------
	// (iii) Every read in the card is non-mutating — snapshot proof.
	// ------------------------------------------------------------------

	public function test_readiness_card_rendering_is_a_pure_read_full_snapshot_proof(): void {
		hal_wp_stub_extra_set( 'stylesheet', 'hal-child' );
		hal_wp_stub_extra_set( 'template', 'hal-parent' );
		$theme = $this->make_theme_dir(
			array(
				'ultimate-member/templates/hal_profile_bridge.php' => "<?php // managed bridge fixture\n",
			)
		);
		hal_wp_stub_extra_set( 'stylesheet_dir', $theme );

		hal_wp_stub_extra_set(
			'readiness_get_posts',
			array(
				(object) array(
					'ID'          => 101,
					'post_type'   => 'um_form',
					'post_title'  => 'Default Profile',
					'post_status' => 'publish',
				),
			)
		);
		$GLOBALS['wp_stubs']['post_meta'][101][ UmFormTemplateWatcher::WATCHED_META_KEY ] = 'hal_profile_bridge';
		$GLOBALS['wp_stubs']['um_options'] = array( 'um_profile_form_id' => 101 );

		$this->boot_grant_path();

		// Snapshot AFTER boot: this test owns the card's render contract only, not the
		// boot's own lifecycle behavior.
		$snapshot = array(
			'wp_stubs'       => $GLOBALS['wp_stubs'],
			'transients'     => $GLOBALS['wp_stubs_extra']['transients'],
			'counters'       => $GLOBALS['wp_stubs_extra']['counters'],
			'http_calls'     => hal_wp_stub_http_calls(),
			'theme_listing'  => $this->dir_listing( $theme ),
		);

		$this->invoke_static( 'render_readiness_card', Bootstrap::instance() );

		$this->assertSame( $snapshot['wp_stubs'], $GLOBALS['wp_stubs'], 'the card must not mutate options, meta, or any stub state' );
		$this->assertSame( $snapshot['transients'], $GLOBALS['wp_stubs_extra']['transients'], 'no transient writes' );
		$this->assertSame( 0, $GLOBALS['wp_stubs_extra']['counters']['update_option'], 'no option writes' );
		$this->assertSame( 0, $GLOBALS['wp_stubs_extra']['counters']['delete_option'], 'no option deletions' );
		$this->assertSame( 0, $GLOBALS['wp_stubs_extra']['counters']['delete_transient'], 'no transient deletions' );
		$this->assertSame( $snapshot['http_calls'], hal_wp_stub_http_calls(), 'no HTTP' );
		$this->assertSame( $snapshot['theme_listing'], $this->dir_listing( $theme ), 'the child theme filesystem must be untouched by the card' );
		$this->assertSame( 0, hal_wp_stub_counter( 'update_option' ) + hal_wp_stub_counter( 'delete_option' ) + hal_wp_stub_counter( 'delete_transient' ), 'write counters stay at zero' );
	}

	// ------------------------------------------------------------------
	// (v) Round-4: the contract row degrades to "unavailable", never a fake pass.
	// ------------------------------------------------------------------

	public function test_contract_row_degrades_to_unavailable_when_the_validator_is_absent(): void {
		$method = new \ReflectionMethod( AdminDashboard::class, 'readiness_contract_verdict' );
		$method->setAccessible( true );

		// The settings verdict is checked first (fail-closed without the plugin core) —
		// the ROUND-4 degradation fires when the core is booted but the F-11 class is not
		// deployed, which is exactly the partial-deploy state the guard exists for.
		$without_core = $method->invoke( null, null, null );

		$this->assertSame( 'blocked', $without_core['state'] );
		$this->assertSame( 'unavailable — plugin core unbooted (fail-closed)', $without_core['detail'] );

		$degraded = $method->invoke( null, new Settings(), null );

		$this->assertSame( 'blocked', $degraded['state'], 'a missing validator can never read as ready' );
		$this->assertSame( 'unavailable (validator not deployed)', $degraded['detail'], 'the exact ROUND-4 degradation wording' );

		// With the validator deployed and nothing configured, the row stays honest on the
		// fail-closed ladder — never a default pass.
		$not_configured = $method->invoke( null, new Settings(), new TemplateContractValidator() );

		$this->assertSame( 'not_configured', $not_configured['state'] );
		$this->assertSame( 'no profile library template selected', $not_configured['detail'] );
	}

	/**
	 * Wiring proof: the Diagnostics page itself carries the read-only Readiness card and,
	 * on 1.2.x, no active Sync form anywhere on the page — with the same snapshot
	 * discipline as the card-only purity test above.
	 */
	public function test_diagnostics_page_carries_the_readiness_card_and_no_active_sync_on_1_2_x(): void {
		hal_wp_stub_extra_set( 'stylesheet', 'hal-child' );
		hal_wp_stub_extra_set( 'template', 'hal-parent' );
		$theme = $this->make_theme_dir(
			array(
				'ultimate-member/templates/hal_profile_bridge.php' => "<?php // managed bridge fixture\n",
			)
		);
		hal_wp_stub_extra_set( 'stylesheet_dir', $theme );

		hal_wp_stub_extra_set(
			'readiness_get_posts',
			array(
				(object) array(
					'ID'          => 101,
					'post_type'   => 'um_form',
					'post_title'  => 'Default Profile',
					'post_status' => 'publish',
				),
			)
		);
		$GLOBALS['wp_stubs']['post_meta'][101][ UmFormTemplateWatcher::WATCHED_META_KEY ] = 'hal_profile_bridge';

		$this->boot_grant_path();

		$snapshot = array(
			'wp_stubs'   => $GLOBALS['wp_stubs'],
			'transients' => $GLOBALS['wp_stubs_extra']['transients'],
		);

		$buffer_level = ob_get_level();

		try {
			ob_start();
			AdminDashboard::render_diagnostics_page();
			$out = ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		$this->assertStringContainsString( 'Readiness (read-only)', $out, 'the F-05 card section must be wired into the page' );
		$this->assertStringContainsString( 'Managed file — resources/ultimate-member/profile-hal-member-profiles.php', $out, 'the managed-file check renders one row per manifest asset' );
		$this->assertStringContainsString( 'UM form #101 — Default Profile', $out, 'check (e) renders through its per-form rows when forms exist' );
		$this->assertStringContainsString( 'Template contract (pre-check)', $out );
		$this->assertStringContainsString( 'UM form #101 — Default Profile', $out, 'the seeded um_form copy is enumerated on the live page' );
		$this->assertStringContainsString(
			'Writes are disabled in this release by the version gate — see Diagnostics.',
			$out,
			'the passive version-gate row replaces the Sync form on the page'
		);
		$this->assertStringNotContainsString( 'Sync managed templates now', $out, 'no active Sync button on 1.2.x' );
		$this->assertStringNotContainsString( '<form', $out, 'no active form of any kind on the 1.2.x Diagnostics page' );

		$this->assertSame( $snapshot['wp_stubs'], $GLOBALS['wp_stubs'], 'rendering the page must not mutate stub state' );
		$this->assertSame( $snapshot['transients'], $GLOBALS['wp_stubs_extra']['transients'], 'rendering the page must not write transients' );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Boots the plugin exactly like the Diagnostics grant-path test in
	 * AdminDashboardPermissionsTest (the shared, already-proven pattern), leaving the
	 * instance reachable for the render under test.
	 *
	 * @return void
	 */
	private function boot_grant_path(): void {
		$GLOBALS['__hooks'] = array();
		hal_wp_stub_extra_set( 'is_admin', true );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}

		if ( ! defined( 'HAL_MEMBER_PROFILES_VERSION' ) ) {
			define( 'HAL_MEMBER_PROFILES_VERSION', '1.2.0' );
		}

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( AdminDashboard::class, false ) ) {
			$registered = new \ReflectionProperty( AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		Bootstrap::init();

		$this->assertInstanceOf( Bootstrap::class, Bootstrap::instance() );
	}

	/**
	 * Invokes a private static AdminDashboard method and returns everything it printed.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments passed through to the method.
	 * @return string
	 */
	private function invoke_static( string $method, ...$args ): string {
		$ref = new \ReflectionMethod( AdminDashboard::class, $method );
		$ref->setAccessible( true );

		ob_start();

		try {
			$ref->invoke( null, ...$args );

			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();

			throw $error;
		}
	}

	/**
	 * Creates a fresh temp child-theme directory containing the given relative files and
	 * registers it for teardown. Real filesystem, never inside the repository.
	 *
	 * @param array<string, string> $files Relative path => contents.
	 * @return string Absolute directory path.
	 */
	private function make_theme_dir( array $files ): string {
		$base = sys_get_temp_dir() . '/hal-f05-' . uniqid( '', true );

		if ( ! is_dir( $base ) ) {
			@mkdir( $base, 0777, true );
		}

		$this->theme_dirs[] = $base;

		foreach ( $files as $relative => $contents ) {
			$path = $base . '/' . $relative;
			$dir  = dirname( $path );

			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0777, true );
			}

			file_put_contents( $path, $contents );
		}

		return $base;
	}

	/**
	 * Recursive listing of a directory: relative path => sha1 of contents (files only),
	 * so any added, deleted, or rewritten file breaks the purity snapshot.
	 *
	 * @param string $dir Absolute directory path.
	 * @return array<string, string>
	 */
	private function dir_listing( string $dir ): array {
		$listing = array();

		if ( ! is_dir( $dir ) ) {
			return $listing;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$relative             = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( $dir ) + 1 ) );
			$listing[ $relative ] = sha1( (string) file_get_contents( (string) $file->getPathname() ) );
		}

		ksort( $listing );

		return $listing;
	}

	/**
	 * Removes a directory tree created by make_theme_dir().
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function remove_dir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( (string) $item->getPathname() ) : @unlink( (string) $item->getPathname() );
		}

		@rmdir( $dir );
	}

	/**
	 * The exact source lines of one method, for structural single-source-of-truth pins.
	 *
	 * @param string $file   Absolute file path.
	 * @param string $class  Class name the reflection is taken from.
	 * @param string $method Method name.
	 * @return string
	 */
	private function method_source( string $file, string $class, string $method ): string {
		$ref      = new \ReflectionMethod( $class, $method );
		$lines    = explode( "\n", (string) file_get_contents( $file ) );
		$slice    = array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 );

		return implode( "\n", $slice );
	}
}
