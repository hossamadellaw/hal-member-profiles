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

			self::write_status( self::STATUS_PENDING, 'provision_failed_' . $reason );

			return array( 'ok' => false, 'reason' => $reason );
		}

		self::write_status(
			self::STATUS_IDLE,
			$forced ? 'repaired' : 'reconciled'
		);

		return array( 'ok' => true, 'reason' => $forced ? 'repaired' : 'reconciled' );
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

		if ( count( $recorded ) !== count( $manifest ) ) {
			return true;
		}

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
