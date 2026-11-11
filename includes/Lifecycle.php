<?php
/**
 * Decides WHEN the managed templates need reconciliation — development card D-05.
 *
 * Sole responsibility: lifecycle scheduling. Every filesystem decision belongs to
 * {@see ManagedTemplates} (card D-04); rendering a Repair button belongs to the admin
 * dashboard (card D-06); instantiating/registering this module belongs to bootstrapping
 * (card D-14). This file performs no filesystem work of its own beyond reading the
 * bundled manifest JSON (a fixed plugin-owned path) as a drift heuristic.
 *
 * Triggers implemented (per the governing card):
 * - Plugin activation records a `pending` marker ONLY — an option write. No
 *   WP_Filesystem connection, no credential prompt, and no provisioning attempt ever
 *   happens during activation.
 * - admin_init compares the bundled manifest (asset versions + digests) against the
 *   last-provisioned state and reconciles once when they drift; identical state is a
 *   no-op, so updates never cause duplicate writes.
 * - after_switch_theme re-marks `pending`; the previously active theme is never touched,
 *   because provisioning always targets the NEW active Child Theme directory only.
 * - A manual Repair command (nonce-guarded admin_post endpoint plus a callable method)
 *   forces one reconciliation for authorized administrators.
 *
 * Deliberately NOT used: `upgrader_process_complete` is not hooked at all — the
 * admin_init drift comparison detects updates of every kind without relying on any
 * single upgrade event.
 *
 * Frontend safety: every hook this file registers is admin-scoped, and reconciliation
 * failures are caught, recorded, and never propagated — a provisioning failure can never
 * degrade a frontend request.
 *
 * Integration note for card D-14: the activation hook is registered at THIS FILE'S TOP
 * LEVEL because WordPress fires the activation event while including the plugin main
 * file, i.e., before any plugins_loaded-time registration could exist. Arming it for the
 * very first activation therefore requires Lifecycle.php to be loaded during the main
 * file's include phase; until D-14 makes that wiring decision, the admin_init drift
 * comparison remains the authoritative safety net and reconciles the very first admin
 * visit even when the activation marker was missed.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {

	public const PENDING_OPTION = 'hal_member_profiles_lifecycle';
	public const NONCE_ACTION   = 'hal_member_profiles_repair';

	private const STATUS_PENDING = 'pending';
	private const STATUS_IDLE    = 'idle';

	/**
	 * Card F-06: provision failure reasons that can never succeed by retrying within the
	 * running release — the release-version write gate (writes_disabled_v1_2, card F-01)
	 * and the immutable/CI deployment mode (immutable). Re-looping them as `pending`
	 * rewrote the marker on EVERY admin visit (live-proven, evidence L-03), so they close
	 * into the final {@see STATUS_IDLE} status instead, with the machine reason and the
	 * audit timestamps preserved.
	 *
	 * @var string[]
	 */
	private const TERMINAL_FAILURE_REASONS = array( 'writes_disabled_v1_2', 'immutable' );

	/**
	 * Card F-06: provision failure reasons that legitimately warrant a retry on a later
	 * admin visit — credentials-dependent filesystems and the in-flight provisioning
	 * lock. These keep the `pending` marker exactly as before this card.
	 *
	 * @var string[]
	 */
	private const RETRYABLE_FAILURE_REASONS = array( 'needs_credentials', 'busy' );

	/**
	 * Wires the runtime hooks exactly once. Called by bootstrapping (card D-14).
	 *
	 * @return void
	 */
	public static function register(): void {
		static $done = false;

		if ( $done || ! defined( 'HAL_MEMBER_PROFILES_FILE' ) ) {
			return;
		}

		$done = true;

		add_action( 'admin_init', array( self::class, 'maybe_reconcile' ), 20 );
		add_action( 'after_switch_theme', array( self::class, 'mark_pending_on_theme_switch' ) );
		add_action( 'admin_post_' . self::NONCE_ACTION, array( self::class, 'handle_repair_request' ) );
	}

	/**
	 * Activation handler: records the pending marker only. Runs at most once per
	 * activation and never touches the filesystem.
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		self::write_status( self::STATUS_PENDING, 'activated' );
	}

	/**
	 * Theme-switch handler: re-marks pending so the next admin visit reconciles against
	 * the newly active Child Theme. The old theme's files are intentionally untouched.
	 *
	 * @return void
	 */
	public static function mark_pending_on_theme_switch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::write_status( self::STATUS_PENDING, 'theme_switched' );
	}

	/**
	 * admin_init worker: reconciles once when the pending marker exists or the manifest
	 * drifted away from the recorded provisioning state. Every other admin visit is a
	 * cheap no-op, which is what keeps migrations idempotent across repeated loads.
	 *
	 * @return void
	 */
	public static function maybe_reconcile(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Integration Closure #2: no consent -> no reconciliation writes at all. The skip
		// is silent by design (nothing to diagnose beyond the Settings flag itself), and
		// revoking consent mid-flight simply freezes further runs without any cleanup.
		if ( ! self::managed_templates_consent() ) {
			return;
		}

		$status = self::read_status();

		if ( self::STATUS_PENDING !== $status['status'] && ! self::manifest_drift_detected() ) {
			return;
		}

		self::reconcile( $status['status'] );
	}

	/**
	 * Manual Repair command. Callable programmatically by the dashboard (card D-06) and
	 * reachable as a nonce-guarded admin_post endpoint. Forces exactly one reconciliation
	 * regardless of drift state.
	 *
	 * @param string|null $nonce Nonce for programmatic callers; the HTTP handler supplies
	 *                           its own.
	 * @return array{ok:bool, reason:string}
	 */
	public static function repair( ?string $nonce = null ): array {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		// Integration Closure #2: explicit Repair is also consent-gated, but unlike the
		// silent tick it REPORTS the block so an administrator can diagnose it.
		if ( ! self::managed_templates_consent() ) {
			return array( 'ok' => false, 'reason' => 'consent_missing' );
		}

		if (
			null !== $nonce
			&& ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
		) {
			return array( 'ok' => false, 'reason' => 'invalid_nonce' );
		}

		return self::reconcile( self::STATUS_PENDING, true );
	}

	/**
	 * Nonce for the dashboard's Repair button (card D-06 consumes this).
	 *
	 * @return string
	 */
	public static function repair_nonce(): string {
		return (string) wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * admin_post handler for the manual Repair command. Verifies capability and nonce
	 * server-side, redirects back with a sanitized result slug, and never emits output.
	 *
	 * @return void
	 */
	public static function handle_repair_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this command.', 'hal-member-profiles' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$result = self::reconcile( self::STATUS_PENDING, true );

		$redirect = wp_get_referer();
		// Card S-04: the Repair command lives on the Diagnostics page, so the fallback
		// return lands there when no referer exists.
		$redirect = $redirect ? $redirect : admin_url( 'admin.php?page=hal-member-profiles-diagnostics' );
		$redirect = add_query_arg(
			'hal_member_profiles_repair',
			$result['ok'] ? 'done' : sanitize_key( (string) $result['reason'] ),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Runs one reconciliation through ManagedTemplates and records the outcome. Any thrown
	 * failure — including provisioning problems — is contained here so neither admin nor
	 * frontend pages can break because reconciliation broke.
	 *
	 * v1.2.1 (card F-06): provision failure reasons are classified — version-terminal
	 * (writes_disabled_v1_2) and immutable failures close into the final idle status
	 * exactly once, with the machine reason and the audit timestamps preserved, while
	 * retryable reasons (needs_credentials, busy) keep the pending marker unchanged.
	 *
	 * @param string $from_status Status that led here (audit trail in the stored option).
	 * @param bool   $forced      True for the manual Repair command.
	 * @return array{ok:bool, reason:string}
	 */
	private static function reconcile( string $from_status, bool $forced = false ): array {
		try {
			if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
				self::write_status( self::STATUS_PENDING, 'blocked_missing_plugin_dir_' . $from_status );

				return array( 'ok' => false, 'reason' => 'missing_plugin_dir' );
			}

			require_once HAL_MEMBER_PROFILES_DIR . 'includes/ManagedTemplates.php';

			$result = ( new ManagedTemplates() )->provision();
		} catch ( \Throwable $e ) {
			self::write_status( self::STATUS_PENDING, 'exception_' . $from_status );

			return array( 'ok' => false, 'reason' => 'exception' );
		}

		if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
			$reason = isset( $result['reason'] ) && is_string( $result['reason'] )
				? sanitize_key( $result['reason'] )
				: 'unknown';

			// Card F-06: terminal reasons (writes_disabled_v1_2, immutable) close into the
			// final idle status exactly once — never re-looped as pending on every admin
			// visit. Retryable reasons (needs_credentials, busy) and every unclassified
			// containment reason keep the pending marker unchanged.
			if ( self::STATUS_IDLE === self::failure_status_for_reason( $reason ) ) {
				self::write_final_failure_status( 'provision_failed_' . $reason );
			} else {
				self::write_status( self::STATUS_PENDING, 'provision_failed_' . $reason );
			}

			return array( 'ok' => false, 'reason' => $reason );
		}

		self::write_status(
			self::STATUS_IDLE,
			$forced ? 'repaired' : 'reconciled'
		);

		return array( 'ok' => true, 'reason' => $forced ? 'repaired' : 'reconciled' );
	}

	/**
	 * Card F-06: classifies a provision failure reason into the lifecycle status it must
	 * produce. Version-terminal (writes_disabled_v1_2) and immutable reasons can never
	 * succeed by retrying within the running release, so they map to the final idle
	 * status instead of re-looping as pending on every admin visit. Retryable reasons
	 * (needs_credentials, busy) legitimately warrant pending. Every unclassified reason
	 * (denied, invalid_manifest, no_child_theme, source_hash_mismatch, unknown, and the
	 * exception/containment paths) keeps the pre-F-06 pending behavior — this card
	 * classifies only the terminal and the retryable sets.
	 *
	 * @param string $reason Sanitized machine reason from ManagedTemplates::provision().
	 * @return string One of the STATUS_* constants.
	 */
	private static function failure_status_for_reason( string $reason ): string {
		if ( in_array( $reason, self::TERMINAL_FAILURE_REASONS, true ) ) {
			return self::STATUS_IDLE;
		}

		if ( in_array( $reason, self::RETRYABLE_FAILURE_REASONS, true ) ) {
			// Retryable: credentials-dependent filesystem or in-flight lock — a later
			// admin visit legitimately retries, so the pending marker stays.
			return self::STATUS_PENDING;
		}

		// Unclassified reasons keep the pre-F-06 pending behavior (conservative default).
		return self::STATUS_PENDING;
	}

	/**
	 * Card F-06: closes a terminal provision failure into the final idle status — exactly
	 * once. The machine reason is preserved verbatim as the audit trail, the original
	 * `since` (when the failing loop began) is preserved, and `until` records the closure
	 * time. A marker already closed with the SAME machine reason is never rewritten, so
	 * subsequent admin visits leave the option untouched — including drift-driven
	 * reconcile runs (production's stale v1.1.5-era state rows keep drift true, L-04).
	 *
	 * @param string $reason Machine audit slug, e.g. provision_failed_writes_disabled_v1_2.
	 * @return void
	 */
	private static function write_final_failure_status( string $reason ): void {
		$stored = get_option( self::PENDING_OPTION, array() );

		if (
			is_array( $stored )
			&& self::STATUS_IDLE === ( $stored['status'] ?? null )
			&& ( $stored['reason'] ?? null ) === $reason
		) {
			return; // Already closed for this terminal reason — stability, no rewrite.
		}

		update_option(
			self::PENDING_OPTION,
			array(
				'status' => self::STATUS_IDLE,
				'reason' => sanitize_key( $reason ),
				'since'  => is_array( $stored ) && isset( $stored['since'] ) ? (int) $stored['since'] : time(),
				'until'  => time(),
			),
			false
		);
	}

	/**
	 * Drift heuristic: compares the bundled manifest against the last-provisioned state.
	 * Any doubt fails toward "no drift" here because provisioning re-validates everything
	 * authoritatively; this check only decides whether an extra reconciliation run is
	 * worth scheduling.
	 *
	 * @return bool
	 */
	private static function manifest_drift_detected(): bool {
		$manifest = self::light_manifest();

		if ( null === $manifest ) {
			return false;
		}

		if ( ! class_exists( ManagedTemplates::class ) && defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/ManagedTemplates.php';
		}

		if ( ! class_exists( ManagedTemplates::class ) ) {
			return false;
		}

		$state = get_option( ManagedTemplates::STATE_OPTION, null );

		if ( ! is_array( $state ) || empty( $state['theme'] ) ) {
			return true;
		}

		if ( (string) $state['theme'] !== (string) get_stylesheet() ) {
			return true;
		}

		$recorded = is_array( $state['assets'] ?? null ) ? $state['assets'] : array();

		foreach ( $manifest as $source_path => $asset ) {
			if ( ! isset( $recorded[ $source_path ]['asset_version'], $recorded[ $source_path ]['source_hash'] ) ) {
				return true;
			}

			if (
				(string) $recorded[ $source_path ]['asset_version'] !== (string) $asset['asset_version']
				|| (string) $recorded[ $source_path ]['source_hash'] !== $asset['sha256']
			) {
				return true;
			}
		}

		// Retired manifest rows are deliberately ignored and retained in the historical
		// ownership ledger. In particular, removing the legacy Account override from future
		// provisioning must not delete its state or schedule reconciliation forever.

		return false;
	}

	/**
	 * Minimal manifest read for drift detection only. Returns a map keyed by source_path;
	 * null when anything about the file is doubtful. Authoritative validation stays
	 * inside ManagedTemplates.
	 *
	 * @return array<string, array{asset_version:int|string, sha256:string}>|null
	 */
	private static function light_manifest(): ?array {
		if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			return null;
		}

		$path = rtrim( (string) HAL_MEMBER_PROFILES_DIR, '/\\' ) . '/resources/ultimate-member/manifest.json';
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- Fixed plugin-owned path used as a drift hint only.

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$parsed = json_decode( $raw, true );

		if (
			! is_array( $parsed )
			|| ( $parsed['plugin_slug'] ?? '' ) !== 'hal-member-profiles'
			|| ! is_array( $parsed['assets'] ?? null )
		) {
			return null;
		}

		$map = array();

		foreach ( $parsed['assets'] as $asset ) {
			if (
				! is_array( $asset )
				|| ! isset( $asset['source_path'], $asset['asset_version'], $asset['sha256'] )
				|| ! is_string( $asset['source_path'] )
				|| ! preg_match( '/^[0-9a-f]{64}$/', (string) $asset['sha256'] )
			) {
				return null;
			}

			$map[ $asset['source_path'] ] = array(
				'asset_version' => $asset['asset_version'],
				'sha256'        => (string) $asset['sha256'],
			);
		}

		return count( $map ) > 0 ? $map : null;
	}

	/**
	 * Integration Closure #2: whether the operator granted managed-template consent in
	 * Settings. Fail-closed: unreadable option/class means NO consent.
	 *
	 * @return bool
	 */
	private static function managed_templates_consent(): bool {
		if ( ! class_exists( \HAL\MemberProfiles\Settings::class ) ) {
			return false;
		}

		$stored = get_option( \HAL\MemberProfiles\Settings::OPTION_KEY, array() );

		return is_array( $stored ) && ! empty( $stored['managed_templates_consent'] );
	}

	/**
	 * Reads the lifecycle marker option.
	 *
	 * @return array{status:string, reason:string, since:int}
	 */
	private static function read_status(): array {
		$stored = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'status' => is_string( $stored['status'] ?? null ) ? $stored['status'] : '',
			'reason' => is_string( $stored['reason'] ?? null ) ? $stored['reason'] : '',
			'since'  => isset( $stored['since'] ) ? (int) $stored['since'] : 0,
		);
	}

	/**
	 * Persists the lifecycle marker. Never autoloaded: this option changes rarely and is
	 * only consulted in admin contexts.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param string $reason Machine-readable audit slug.
	 * @return void
	 */
	private static function write_status( string $status, string $reason ): void {
		update_option(
			self::PENDING_OPTION,
			array(
				'status' => $status,
				'reason' => sanitize_key( $reason ),
				'since'  => time(),
			),
			false
		);
	}
}

// Armed from file include time (see the integration note in the file header): activation
// fires while the main plugin file is being included, before plugins_loaded-time
// registrations can exist. Writes an option only — no filesystem work, no credentials.
if ( defined( 'HAL_MEMBER_PROFILES_FILE' ) ) {
	register_activation_hook( HAL_MEMBER_PROFILES_FILE, array( Lifecycle::class, 'on_activation' ) );
}
