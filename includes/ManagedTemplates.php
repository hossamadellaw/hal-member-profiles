<?php
/**
 * Inspects, provisions, and syncs the managed Ultimate Member template assets into the
 * active Child Theme through the official WP_Filesystem API — development card D-04.
 *
 * Sole responsibility: filesystem custody of the two canonical assets declared in
 * resources/ultimate-member/manifest.json (card D-03). Everything else — when
 * reconciliation runs (card D-05), who drives it from the UI (card D-06), and how this
 * module is wired into bootstrapping (card D-14) — belongs to later cards; this class
 * registers no hooks and loads no other module.
 *
 * Hard rules implemented here:
 * - Only the ACTIVE CHILD THEME is ever touched (get_stylesheet_directory()). When no
 *   Child Theme is active (stylesheet === template), the verdict is no_child_theme and
 *   nothing is ever written — the Parent Theme directory is unreachable by construction.
 *   Target paths come exclusively from the bundled manifest; no request/user-supplied
 *   path parameter exists on this class.
 * - A user-modified target (its content hash matching neither the manifest digest nor
 *   HAL's own last-deployed digest for the SAME theme) is a hard conflict: reported as
 *   user_modified, never overwritten, never deleted.
 * - Writes go to a temporary sibling file first, are hash-verified byte-for-byte against
 *   the manifest digest, and only then replace the managed target. The only deletion ever
 *   performed is this class's OWN failed temporary artifact — managed templates themselves
 *   are never silently overwritten or deleted.
 * - Every mutating call requires an admin context plus the manage_options capability;
 *   nonce enforcement belongs to the admin endpoints that invoke this class (cards
 *   D-05/D-06 own their request surfaces).
 * - No filesystem work ever happens on a frontend request: non-admin contexts receive a
 *   fail-closed denial before any WP_Filesystem call is attempted.
 * - Multisite: options are per-site and the active theme resolves per site, so every
 *   site in a network manages its own Child Theme copy independently; switching themes
 *   re-baselines the state instead of trusting digests recorded under another theme.
 *
 * Deployment modes:
 * - Direct: WP_Filesystem reports the 'direct' method — provisioning proceeds normally.
 * - Guided credentials: any other method (FTP/SSH) yields needs_credentials so the admin
 *   UI (card D-06) can drive request_filesystem_credentials(); this class never prompts
 *   and never stores passwords.
 * - Immutable/CI: defining HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT as true turns every
 *   write into a reported no-op (immutable) — for pipelines whose deployer owns file
 *   placement while HAL verifies only.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ManagedTemplates {

	public const STATE_OPTION    = 'hal_member_profiles_managed_templates';
	public const LOCK_TRANSIENT  = 'hal_member_profiles_managed_templates_lock';

	public const STATUS_CURRENT           = 'current';
	public const STATUS_MISSING           = 'missing';
	public const STATUS_UPGRADE           = 'upgrade';
	public const STATUS_USER_MODIFIED     = 'user_modified';
	public const STATUS_UNREADABLE        = 'unreadable';
	public const STATUS_WRITE_FAILED      = 'write_failed';
	public const STATUS_IMMUTABLE         = 'immutable';
	public const STATUS_NEEDS_CREDENTIALS = 'needs_credentials';

	private string $manifest_path;

	/**
	 * @var array<string, mixed>|null Validated manifest contents; null until loaded, and
	 *                               remains null when the manifest is absent or invalid.
	 */
	private ?array $manifest = null;

	/**
	 * @var object|null The initialized WP_Filesystem instance; null until connected.
	 */
	private ?object $fs = null;

	/**
	 * @param string|null $manifest_path Absolute path override for tests; production code
	 *                                   must rely on the bundled manifest location.
	 */
	public function __construct( ?string $manifest_path = null ) {
		if ( null === $manifest_path ) {
			$manifest_path = defined( 'HAL_MEMBER_PROFILES_DIR' )
				? HAL_MEMBER_PROFILES_DIR . 'resources/ultimate-member/manifest.json'
				: '';
		}

		$this->manifest_path = $manifest_path;
	}

	/**
	 * Read-only inspection of every managed asset against the active Child Theme.
	 * Performs filesystem READS only and changes nothing.
	 *
	 * @return array{ok:bool, reason:string, theme:string, assets:array<string, array<string, mixed>>}
	 */
	public function inspect(): array {
		if ( ! $this->may_operate() ) {
			return $this->verdict( false, 'denied' );
		}

		if ( null === $this->load_manifest() ) {
			return $this->verdict( false, 'invalid_manifest' );
		}

		$child_theme_problem = $this->active_child_theme_guard();
		if ( null !== $child_theme_problem ) {
			return $this->verdict( false, $child_theme_problem );
		}

		if ( ! $this->connect_filesystem() || ! $this->fs_ready_for_reads() ) {
			return $this->verdict( false, self::STATUS_NEEDS_CREDENTIALS );
		}

		$assets = array();

		foreach ( $this->manifest['assets'] as $asset ) {
			list( $status, ) = $this->decide( $asset );

			$assets[ $asset['source_path'] ] = array(
				'asset_version' => $asset['asset_version'],
				'source_hash'   => $asset['sha256'],
				'target_status' => $status,
				'source_ok'     => $this->source_matches_manifest( $asset ),
			);
		}

		return $this->verdict( true, 'inspected', $assets );
	}

	/**
	 * Reconciles every managed asset into the active Child Theme, honoring the conflict
	 * rules above. Idempotent: calling again on an up-to-date tree changes nothing.
	 *
	 * @return array{ok:bool, reason:string, theme:string, assets:array<string, array<string, mixed>>}
	 */
	public function provision(): array {
		if ( ! $this->may_operate() ) {
			return $this->verdict( false, 'denied' );
		}

		if ( null === $this->load_manifest() ) {
			return $this->verdict( false, 'invalid_manifest' );
		}

		if ( $this->immutable_deployment() ) {
			return $this->verdict( false, self::STATUS_IMMUTABLE );
		}

		$child_theme_problem = $this->active_child_theme_guard();
		if ( null !== $child_theme_problem ) {
			return $this->verdict( false, $child_theme_problem );
		}

		if ( ! $this->connect_filesystem() || ! $this->fs_ready_for_reads() ) {
			return $this->verdict( false, self::STATUS_NEEDS_CREDENTIALS );
		}

		// One bad source byte invalidates the whole batch: verify every source against the
		// manifest BEFORE touching any target, so a tampered/mismatched manifest or asset
		// prevents provisioning entirely (fail-closed).
		foreach ( $this->manifest['assets'] as $asset ) {
			if ( ! $this->source_matches_manifest( $asset ) ) {
				return $this->verdict( false, 'source_hash_mismatch' );
			}
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return $this->verdict( false, 'busy' );
		}

		set_transient( self::LOCK_TRANSIENT, 1, 30 );

		try {
			$state = $this->read_state();
			$theme = get_stylesheet();

			if ( ! is_array( $state['assets'] ?? null ) ) {
				$state['assets'] = array();
			}

			$results  = array();
			$mutated  = false;

			foreach ( $this->manifest['assets'] as $asset ) {
				$key        = $asset['source_path'];
				$entry      = $state['assets'][ $key ] ?? array();
				$record     = array(
					'asset_version'     => $asset['asset_version'],
					'source_hash'       => $asset['sha256'],
					'last_managed_hash' => $entry['last_managed_hash'] ?? null,
					'status'            => $entry['status'] ?? null,
				);

				list( $status, $target_abs, $actual_hash ) = $this->decide( $asset );

				switch ( $status ) {
					case self::STATUS_MISSING:
					case self::STATUS_UPGRADE:
						$content = $this->read_source( $asset );
						$written = is_string( $content )
							&& $this->write_verified( $target_abs, $content, $asset['sha256'] );

						if ( $written ) {
							$mutated                        = true;
							$status                         = self::STATUS_CURRENT;
							$record['last_managed_hash']    = $asset['sha256'];
							$actual_hash                    = $asset['sha256'];
						} else {
							$status = self::STATUS_WRITE_FAILED;
						}
						break;

					case self::STATUS_CURRENT:
						$record['last_managed_hash'] = $asset['sha256'];
						break;
				}

				$record['status'] = $status;
				$state['assets'][ $key ] = $record;

				$results[ $key ] = array(
					'asset_version'     => $record['asset_version'],
					'source_hash'       => $record['source_hash'],
					'last_managed_hash' => $record['last_managed_hash'],
					'status'            => $status,
				);
			}

			$state['theme'] = $theme;

			if ( $mutated || $this->state_stale( $state ) ) {
				update_option( self::STATE_OPTION, $state, false );
			}

			return array(
				'ok'     => true,
				'reason' => 'provisioned',
				'theme'  => $theme,
				'assets' => $results,
			);
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Admin-context and capability gate shared by every entry point. This is the class's
	 * own fail-closed boundary; request nonces remain the caller endpoint's duty.
	 *
	 * @return bool
	 */
	private function may_operate(): bool {
		return is_admin() && current_user_can( 'manage_options' );
	}

	/**
	 * Rejects operation whenever the active theme is not a Child Theme, because
	 * get_stylesheet_directory() would then resolve to the Parent Theme directory.
	 *
	 * @return string|null Problem slug, or null when an active Child Theme is confirmed.
	 */
	private function active_child_theme_guard(): ?string {
		return get_stylesheet() === get_template() ? 'no_child_theme' : null;
	}

	/**
	 * Loads and strictly validates the bundled manifest once per instance. An absent,
	 * unparseable, incomplete, or path-unsafe manifest keeps returning null forever,
	 * which makes every operation fail closed.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load_manifest(): ?array {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		if ( '' === $this->manifest_path ) {
			return null;
		}

		$raw = file_get_contents( $this->manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- Reading a fixed plugin-owned path; WP_Filesystem is reserved for Child Theme targets.

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$parsed = json_decode( $raw, true );

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		foreach ( array( 'manifest_version', 'plugin_slug', 'assets' ) as $required_top ) {
			if ( ! isset( $parsed[ $required_top ] ) ) {
				return null;
			}
		}

		if ( 'hal-member-profiles' !== $parsed['plugin_slug'] || ! is_array( $parsed['assets'] ) || count( $parsed['assets'] ) < 1 ) {
			return null;
		}

		$seen = array();

		foreach ( $parsed['assets'] as $asset ) {
			foreach ( array( 'asset_version', 'type', 'um_scope', 'source_path', 'target_path', 'sha256' ) as $required ) {
				if ( ! isset( $asset[ $required ] ) ) {
					return null;
				}
			}

			if ( ! $this->safe_relative_path( $asset['source_path'] ) || ! $this->safe_relative_path( $asset['target_path'] ) ) {
				return null;
			}

			if ( isset( $seen[ $asset['source_path'] ] ) || ! preg_match( '/^[0-9a-f]{64}$/', (string) $asset['sha256'] ) ) {
				return null;
			}

			$seen[ $asset['source_path'] ] = true;
		}

		$this->manifest = $parsed;

		return $this->manifest;
	}

	/**
	 * Forward-slash, rooted-at-nothing, traversal-free relative path check applied to
	 * every manifest path before it is ever joined to a real directory.
	 *
	 * @param mixed $path Candidate path.
	 * @return bool
	 */
	private function safe_relative_path( $path ): bool {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		if ( str_contains( $path, '\\' ) || str_contains( $path, ':' ) || str_starts_with( $path, '/' ) ) {
			return false;
		}

		$segments = explode( '/', $path );

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '..' === $segment || '.' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Initializes the official WP_Filesystem abstraction. Returns true only when a usable
	 * filesystem instance exists afterwards; a credentials-dependent environment yields
	 * false so callers report needs_credentials instead of prompting here.
	 *
	 * @return bool
	 */
	private function connect_filesystem(): bool {
		if ( null !== $this->fs ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return false;
		}

		if ( ! WP_Filesystem() ) {
			return false;
		}

		$candidate = $GLOBALS['wp_filesystem'] ?? null;

		if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'get_contents' ) ) {
			return false;
		}

		$this->fs = $candidate;

		return true;
	}

	/**
	 * @return bool Whether a filesystem instance is connected and readable.
	 */
	private function fs_ready_for_reads(): bool {
		return null !== $this->fs;
	}

	/**
	 * Decides what the given asset's TARGET currently is inside the active Child Theme.
	 * Digests recorded under a different theme are never trusted (theme switch rule).
	 *
	 * @param array<string, mixed> $asset Validated manifest asset row.
	 * @return array{0:string, 1:string, 2:?string} Status, absolute target path, actual target hash (when readable).
	 */
	private function decide( array $asset ): array {
		$target_abs = $this->target_absolute_path( $asset['target_path'] );

		if ( ! $this->fs->exists( $target_abs ) ) {
			return array( self::STATUS_MISSING, $target_abs, null );
		}

		$raw = $this->fs->get_contents( $target_abs );

		if ( ! is_string( $raw ) ) {
			return array( self::STATUS_UNREADABLE, $target_abs, null );
		}

		$actual = hash( 'sha256', $raw );

		if ( hash_equals( $asset['sha256'], $actual ) ) {
			return array( self::STATUS_CURRENT, $target_abs, $actual );
		}

		$state = $this->read_state();

		if (
			isset( $state['theme'], $state['assets'][ $asset['source_path'] ]['last_managed_hash'] )
			&& $state['theme'] === get_stylesheet()
			&& hash_equals( (string) $state['assets'][ $asset['source_path'] ]['last_managed_hash'], $actual )
		) {
			return array( self::STATUS_UPGRADE, $target_abs, $actual );
		}

		return array( self::STATUS_USER_MODIFIED, $target_abs, $actual );
	}

	/**
	 * Reads the plugin-owned source file from the PLUGIN directory (never the theme) and
	 * confirms it still hashes to the manifest digest.
	 *
	 * @param array<string, mixed> $asset Validated manifest asset row.
	 * @return string|null Raw bytes, or null when unreadable/mismatched.
	 */
	private function read_source( array $asset ): ?string {
		$source_abs = $this->source_absolute_path( $asset['source_path'] );

		if ( '' === $source_abs ) {
			return null;
		}

		$raw = file_get_contents( $source_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- Fixed plugin-owned path; WP_Filesystem is reserved for Child Theme targets.

		if ( ! is_string( $raw ) || ! hash_equals( $asset['sha256'], hash( 'sha256', $raw ) ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $asset Validated manifest asset row.
	 * @return bool Whether the on-disk source still matches the manifest digest.
	 */
	private function source_matches_manifest( array $asset ): bool {
		return null !== $this->read_source( $asset );
	}

	/**
	 * Joins a validated relative manifest path onto the ACTIVE THEME directory. This is
	 * the ONLY place an absolute TARGET location is ever produced, and the base is never
	 * configurable at runtime.
	 *
	 * @param string $relative Validated relative path.
	 * @return string
	 */
	private function target_absolute_path( string $relative ): string {
		$base = rtrim( (string) get_stylesheet_directory(), '/\\' );

		return $base . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Joins a validated relative manifest path onto the PLUGIN directory. Sources live
	 * inside the plugin itself and are therefore resolved independently of any theme.
	 *
	 * @param string $relative Validated relative path.
	 * @return string Absolute path, or an empty string when the plugin dir is unknown.
	 */
	private function source_absolute_path( string $relative ): string {
		if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) || '' === (string) HAL_MEMBER_PROFILES_DIR ) {
			return '';
		}

		$base = rtrim( (string) HAL_MEMBER_PROFILES_DIR, '/\\' );

		return $base . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Temporary-file write: put → re-read → hash-verify → move over the target. On any
	 * verification failure the temporary artifact (and only it) is removed, leaving the
	 * previous target untouched.
	 *
	 * @param string $target_abs    Absolute managed target path.
	 * @param string $content       Verified source bytes.
	 * @param string $expected_hash Manifest SHA-256 digest of the bytes.
	 * @return bool
	 */
	private function write_verified( string $target_abs, string $content, string $expected_hash ): bool {
		$temp = $target_abs . '.hal-tmp';
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false;

		if ( ! $this->fs->put_contents( $temp, $content, $mode ) ) {
			$this->remove_own_temp_artifact( $temp );

			return false;
		}

		$roundtrip = $this->fs->get_contents( $temp );

		if ( ! is_string( $roundtrip ) || ! hash_equals( $expected_hash, hash( 'sha256', $roundtrip ) ) ) {
			$this->remove_own_temp_artifact( $temp );

			return false;
		}

		$moved = $this->fs->move( $temp, $target_abs, true );

		if ( ! $moved ) {
			$this->remove_own_temp_artifact( $temp );
		}

		return (bool) $moved;
	}

	/**
	 * Removes THIS class's own temporary artifact after a failed write cycle. Managed
	 * templates are never deleted by this method — the path is the `.hal-tmp` sibling the
	 * class itself just created.
	 *
	 * @param string $temp Absolute path of the temporary artifact.
	 * @return void
	 */
	private function remove_own_temp_artifact( string $temp ): void {
		if ( strlen( $temp ) > 7 && '.hal-tmp' === substr( $temp, -8 ) && $this->fs->exists( $temp ) ) {
			$this->fs->delete( $temp, false, 'f' );
		}
	}

	/**
	 * @return array<string, mixed> Stored per-theme state; never autoloaded.
	 */
	private function read_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state Candidate next state.
	 * @return bool Whether the stored option differs and must be persisted.
	 */
	private function state_stale( array $state ): bool {
		return get_option( self::STATE_OPTION ) !== $state;
	}

	/**
	 * @param bool  $ok    Overall outcome.
	 * @param string $reason Machine-readable reason slug.
	 * @param array<string, array<string, mixed>> $assets Per-asset results.
	 * @return array{ok:bool, reason:string, theme:string, assets:array<string, array<string, mixed>>}
	 */
	private function verdict( bool $ok, string $reason, array $assets = array() ): array {
		return array(
			'ok'     => $ok,
			'reason' => $reason,
			'theme'  => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
			'assets' => $assets,
		);
	}

	/**
	 * @return bool Whether immutable/CI deployment mode is enabled.
	 */
	private function immutable_deployment(): bool {
		return defined( 'HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT' ) && true === HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT;
	}
}
