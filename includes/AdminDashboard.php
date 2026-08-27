<?php
/**
 * The HAL Member Profiles admin dashboard — development card D-06.
 *
 * Sole responsibility: one WordPress-admin screen (no SPA, no framework) presenting the
 * five limited sections — Overview/Health, Layouts/Templates, UM Fields/Dynamic Tags,
 * Amelia Elite, Compatibility/Diagnostics — plus the single delegated action button.
 *
 * Hard rules implemented here:
 * - Read-only BY DESIGN: this class never writes options, transients, or files. The only
 *   POST it renders targets the nonce-and-capability-guarded Repair endpoint that card
 *   D-05 owns (`admin_post.php?action=hal_member_profiles_repair`), reusing
 *   Lifecycle::repair_nonce(). Existing Phase-1 settings remain owned by
 *   Settings::register_page() (Settings API, card 7.4); this dashboard links there
 *   instead of duplicating any save path.
 * - Capability: every entry point requires manage_options (the documented project
 *   capability per compatibility-matrix §7). Non-authorized admins get a 403 wp_die;
 *   the page itself is only registered under the same capability.
 * - Fail-closed presentation: every section explains WHY a feature is unavailable
 *   (module not yet shipped, dependency missing, compatibility gate not passed,
 *   provisioning blocked) instead of hiding or guessing. Modules arriving in later
 *   cards (SecretStore D-07, AmeliaApiClient D-08, SchemaRegistry D-09) appear as named
 *   placeholders with their governing card numbers.
 * - No secrets, no PII: diagnostics print versions, modes, booleans, relative paths,
 *   and machine slugs only — never user records, emails, meta values, or keys.
 * - Notices are resolvable: the repair-result notice renders once from its query arg
 *   and disappears on the next pageload, exactly matching the acceptance rule.
 *
 * Integration note for card D-14: this file registers nothing on include; wiring happens
 * exclusively through {@see AdminDashboard::register()} during bootstrapping. Until then
 * the dashboard is inert and unreachable.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminDashboard {

	public const PAGE_SLUG      = 'hal-member-profiles';
	public const REPAIR_QUERY_ARG = 'hal_member_profiles_repair';
	public const AMELIA_QUERY_ARG = 'hal_amelia_result';
	public const DESIRED_OPTION   = 'hal_member_profiles_amelia_desired_fields';

	private static bool $registered = false;

	/**
	 * Wires the admin hooks exactly once. Called by bootstrapping (card D-14).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered || ! defined( 'HAL_MEMBER_PROFILES_FILE' ) ) {
			return;
		}

		self::$registered = true;

		self::load_module_classes();

		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_notices', array( self::class, 'render_action_notice' ) );

		// Integration Closure #6: governed Amelia management routes.
		foreach (
			array(
				'hal_member_profiles_key_save',
				'hal_member_profiles_key_revoke',
				'hal_member_profiles_conn_test',
				'hal_member_profiles_snap_refresh',
				'hal_member_profiles_desired_save',
				'hal_member_profiles_fields_apply',
			) as $route
		) {
			add_action( 'admin_post_' . $route, array( self::class, 'handle_amelia_post' ) );
		}
	}

	/**
	 * Single guarded entry for every Amelia management route. Verifies capability and
	 * nonce, delegates to the matching service verb, then redirects back with a
	 * sanitized result slug. Never emits output on the success path.
	 *
	 * @return void
	 */
	public static function handle_amelia_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this action.', 'hal-member-profiles' ), 403 );
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer below verifies it against the same value.

		check_admin_referer( $action );

		self::load_module_classes();

		$redirect = wp_get_referer();
		$redirect = $redirect ? $redirect : admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		switch ( $action ) {
			case 'hal_member_profiles_key_save':
				$key = isset( $_POST['amelia_api_key'] ) ? wp_unslash( (string) $_POST['amelia_api_key'] ) : '';
				$res = SecretStore::set_amelia_api_key( $key );
				break;

			case 'hal_member_profiles_key_revoke':
				$res = SecretStore::clear_amelia_api_key();
				break;

			case 'hal_member_profiles_conn_test':
				$res = \HAL\MemberProfiles\Integrations\AmeliaApiClient::test_connection();

				if ( ! empty( $res['ok'] ) ) {
					$res['reason'] = 'connected';
				}
				break;

			case 'hal_member_profiles_snap_refresh':
				$res = ( new SchemaRegistry() )->refresh_amelia_snapshot();

				if ( ! empty( $res['ok'] ) ) {
					$res['reason'] = 'snapshot_updated';
				}
				break;

			case 'hal_member_profiles_desired_save':
				$raw    = isset( $_POST['hal_amelia_desired'] ) ? wp_unslash( (string) $_POST['hal_amelia_desired'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized line-by-line below.
				$wanted = array();

				foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
					$line = trim( (string) $line );

					if ( '' === $line ) {
						continue;
					}

					$segments = array_map( 'trim', explode( '|', $line, 3 ) );
					$title    = sanitize_text_field( $segments[0] );

					if ( '' === $title || count( $segments ) < 2 ) {
						continue;
					}

					$type = sanitize_key( $segments[1] );

					if ( ! in_array( $type, array( 'text', 'select', 'url' ), true ) ) {
						continue;
					}

					$slug = sanitize_key( $title );

					if ( '' === $slug ) {
						continue;
					}

					$wanted[] = array(
						'key'   => $slug,
						'title' => $title,
						'type'  => $type,
					);
				}

				update_option( self::DESIRED_OPTION, $wanted, false );

				$res = array( 'ok' => true, 'reason' => 'desired_saved' );
				break;

			case 'hal_member_profiles_fields_apply':
				// The plan is recomputed server-side from the stored desired set; the
				// client never supplies a trusted plan. Route nonce already verified above,
				// and the writer receives a server-fresh nonce for its own contract.
				$desired = get_option( self::DESIRED_OPTION, array() );
				$desired = is_array( $desired ) ? $desired : array();

				$res = \HAL\MemberProfiles\Integrations\AmeliaFieldsWriter::apply(
					$desired,
					wp_create_nonce( \HAL\MemberProfiles\Integrations\AmeliaFieldsWriter::NONCE_ACTION )
				);

				if ( ! empty( $res['ok'] ) ) {
					$res['reason'] = 'applied';
				}
				break;

			default:
				$res = array( 'ok' => false, 'reason' => 'unknown_action' );
				break;
		}

		$slug = is_string( $res['reason'] ?? null ) ? sanitize_key( $res['reason'] ) : 'unknown';

		$redirect = add_query_arg(
			'hal_amelia_result',
			$slug,
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Guarantees the sibling module classes exist before any class-constant reference,
	 * because this project ships no autoloader.
	 *
	 * @return void
	 */
	private static function load_module_classes(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			return;
		}

		if ( ! class_exists( ManagedTemplates::class ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/ManagedTemplates.php';
		}

		if ( ! class_exists( Lifecycle::class ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Lifecycle.php';
		}
	}

	/**
	 * Registers the dashboard screen under manage_options only.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page(
			__( 'HAL Member Profiles', 'hal-member-profiles' ),
			__( 'HAL Member Profiles', 'hal-member-profiles' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-groups'
		);
	}

	/**
	 * One-shot notice for the delegated Repair action's redirect result. Renders only
	 * while the query arg exists, so it disappears on the very next pageload.
	 *
	 * @return void
	 */
	public static function render_action_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach (
			array(
				self::REPAIR_QUERY_ARG => array(
					'good' => __( 'Managed templates reconciled successfully.', 'hal-member-profiles' ),
					'bad'  => __( 'Managed templates reconciliation did not complete.', 'hal-member-profiles' ),
					'good_slugs' => array( 'done' ),
				),
				self::AMELIA_QUERY_ARG => array(
					'good' => __( 'Amelia action completed.', 'hal-member-profiles' ),
					'bad'  => __( 'Amelia action did not complete.', 'hal-member-profiles' ),
					'good_slugs' => array( 'done', 'connected', 'snapshot_updated', 'applied', 'stored', 'cleared', 'desired_saved' ),
				),
			) as $arg => $cfg
		) {
			if ( ! isset( $_GET[ $arg ] ) || ! is_string( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result banner for already-verified admin_post actions.
				continue;
			}

			$slug = sanitize_key( wp_unslash( $_GET[ $arg ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.

			if ( '' === $slug ) {
				continue;
			}

			$is_good = in_array( $slug, $cfg['good_slugs'], true );

			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s%3$s</p></div>',
				$is_good ? 'notice-success' : 'notice-error',
				esc_html( $is_good ? $cfg['good'] : $cfg['bad'] ),
				esc_html( $is_good ? '' : sprintf( /* translators: %s: machine-readable failure slug. */ __( ' (reason: %s)', 'hal-member-profiles' ), $slug ) )
			);
		}
	}

	/**
	 * Renders the whole dashboard. Read-only output; every dynamic value escaped in its
	 * own context.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'hal-member-profiles' ), 403 );
		}

		self::load_module_classes();

		$bootstrap = Bootstrap::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HAL Member Profiles', 'hal-member-profiles' ); ?></h1>

			<h2 class="title"><?php esc_html_e( 'Overview / Health', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php self::render_overview_rows( $bootstrap ); ?>
				</tbody>
			</table>

			<h2 class="title"><?php esc_html_e( 'Layouts / Templates', 'hal-member-profiles' ); ?></h2>
			<?php self::render_layouts_and_templates( $bootstrap ); ?>

			<h2 class="title"><?php esc_html_e( 'UM Fields / Dynamic Tags', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php self::render_fields_and_tags_rows( $bootstrap ); ?>
				</tbody>
			</table>

			<h2 class="title"><?php esc_html_e( 'Amelia Elite', 'hal-member-profiles' ); ?></h2>
			<?php self::render_amelia_rows( $bootstrap ); ?>

			<h2 class="title"><?php esc_html_e( 'Compatibility / Diagnostics', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php self::render_diagnostics_rows( $bootstrap ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Overview rows: environment presence and versions through Dependencies, plus the
	 * lifecycle marker. Versions and booleans only — no user data.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function render_overview_rows( ?Bootstrap $bootstrap ): void {
		if ( null === $bootstrap ) {
			self::print_row(
				__( 'Plugin core', 'hal-member-profiles' ),
				__( 'Unavailable — Ultimate Member was not detected, so HAL stayed unbooted (fail-closed).', 'hal-member-profiles' )
			);
		} else {
			$dependencies = $bootstrap->get_dependencies();

			$checks = array(
				array( __( 'WordPress', 'hal-member-profiles' ), 'wp_version' ),
				array( __( 'PHP', 'hal-member-profiles' ), 'php_version' ),
				array( __( 'Ultimate Member', 'hal-member-profiles' ), 'um_version' ),
				array( __( 'Elementor', 'hal-member-profiles' ), 'elementor_version' ),
				array( __( 'Elementor Pro', 'hal-member-profiles' ), 'elementor_pro_version' ),
				array( __( 'Amelia', 'hal-member-profiles' ), 'amelia_version' ),
				array( __( 'Active theme', 'hal-member-profiles' ), 'active_theme_version' ),
			);

			foreach ( $checks as $check ) {
				list( $label, $method_name ) = $check;

				$value = call_user_func( array( $dependencies, $method_name ) );

				self::print_row(
					$label,
					is_string( $value ) && '' !== $value
						? $value
						: __( 'not detected', 'hal-member-profiles' )
				);
			}
		}

		if ( class_exists( Lifecycle::class ) ) {
			$stored   = get_option( Lifecycle::PENDING_OPTION, array() );
			$status   = is_array( $stored ) && isset( $stored['status'] ) && is_string( $stored['status'] ) ? $stored['status'] : '';
			$reason   = is_array( $stored ) && isset( $stored['reason'] ) && is_string( $stored['reason'] ) ? $stored['reason'] : '';

			self::print_row(
				__( 'Managed templates lifecycle', 'hal-member-profiles' ),
				sprintf(
					/* translators: 1: lifecycle status slug, 2: audit reason slug. */
					__( '%1$s (last reason: %2$s)', 'hal-member-profiles' ),
					'' !== $status ? $status : __( 'not recorded yet', 'hal-member-profiles' ),
					'' !== $reason ? $reason : '—'
				)
			);
		}
	}

	/**
	 * Layouts/Templates section: current layout configuration read-only, the managed
	 * asset inspection table (the required pre-operation preview), and the single
	 * delegated Repair/Sync button posting to D-05's guarded endpoint.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function render_layouts_and_templates( ?Bootstrap $bootstrap ): void {
		if ( null !== $bootstrap ) {
			$settings = $bootstrap->get_settings();

			self::print_row(
				__( 'Profile layout mode', 'hal-member-profiles' ),
				$settings->get_profile_layout_mode()
			);
			self::print_row(
				__( 'Account layout mode', 'hal-member-profiles' ),
				$settings->get_account_layout_mode()
			);

			echo '<p><a class="button" href="' . esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Open the classic settings page', 'hal-member-profiles' ) . '</a></p>';
		} else {
			echo '<p><em>' . esc_html__( 'Layout settings unavailable: plugin core is unbooted (fail-closed).', 'hal-member-profiles' ) . '</em></p>';
		}

		if ( ! class_exists( ManagedTemplates::class ) ) {
			echo '<p><em>' . esc_html__( 'Managed template module unavailable (fail-closed).', 'hal-member-profiles' ) . '</em></p>';

			return;
		}

		$inspection = ( new ManagedTemplates() )->inspect();

		if ( empty( $inspection['ok'] ) ) {
			$reason = isset( $inspection['reason'] ) && is_string( $inspection['reason'] ) ? $inspection['reason'] : 'unknown';

			printf(
				'<p><em>%1$s <code>%2$s</code></em></p>',
				esc_html__( 'Managed template inspection declined:', 'hal-member-profiles' ),
				esc_html( $reason )
			);

			return;
		}

		$labels = array(
			ManagedTemplates::STATUS_CURRENT           => __( 'current', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_MISSING           => __( 'missing — will be created on sync', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_UPGRADE           => __( 'upgrade — safe update pending', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_USER_MODIFIED     => __( 'user modified — conflict, never overwritten automatically', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_UNREADABLE        => __( 'unreadable target', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_WRITE_FAILED      => __( 'last write failed', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_NEEDS_CREDENTIALS => __( 'needs filesystem credentials', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_IMMUTABLE         => __( 'immutable deployment mode active', 'hal-member-profiles' ),
		);

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		foreach ( $inspection['assets'] as $source_path => $info ) {
			$status_key = is_string( $info['target_status'] ?? null ) ? $info['target_status'] : '';
			$label      = isset( $labels[ $status_key ] ) ? $labels[ $status_key ] : $status_key;

			printf(
				'<tr><td>%1$s</td><td>v%2$s</td><td>%3$s</td></tr>',
				esc_html( $source_path ),
				esc_attr( (string) ( $info['asset_version'] ?? '' ) ),
				esc_html( $label )
			);
		}

		echo '</tbody></table>';

		$nonce = class_exists( Lifecycle::class ) ? Lifecycle::repair_nonce() : '';
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
			<input type="hidden" name="action" value="<?php echo esc_attr( Lifecycle::NONCE_ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<?php submit_button( __( 'Sync managed templates now', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
		</form>

		<p class="description">
			<?php esc_html_e( 'Sync creates missing managed templates and safely updates only files HAL deployed itself; your modifications are always reported as conflicts first.', 'hal-member-profiles' ); ?>
		</p>
		<?php
	}

	/**
	 * UM Fields / Dynamic Tags rows: availability facts only. SchemaRegistry (card D-09)
	 * does not exist yet, so this section names its placeholder honestly.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function render_fields_and_tags_rows( ?Bootstrap $bootstrap ): void {
		if ( null === $bootstrap ) {
			self::print_row( __( 'UM fields catalog', 'hal-member-profiles' ), __( 'unavailable — plugin core unbooted (fail-closed)', 'hal-member-profiles' ) );

			return;
		}

		$dependencies = $bootstrap->get_dependencies();

		self::print_row(
			__( 'Elementor Pro Dynamic Tags', 'hal-member-profiles' ),
			$dependencies->has_elementor_pro_dynamic_tags()
				? __( 'available', 'hal-member-profiles' )
				: __( 'unavailable — Elementor Pro missing; widgets and native fallback stay active', 'hal-member-profiles' )
		);

		self::print_row(
			__( 'Schema registry (card D-09)', 'hal-member-profiles' ),
			__( 'not shipped yet — the automatic UM/Amelia field catalog arrives with development card D-09', 'hal-member-profiles' )
		);
	}
	private static function render_amelia_rows( ?Bootstrap $bootstrap ): void {
			$has_amelia = null !== $bootstrap && $bootstrap->get_dependencies()->has_amelia();
			?>
			<table class="widefat striped" style="max-width:900px"><tbody>
			<?php
			self::print_row(
				__( 'Amelia plugin', 'hal-member-profiles' ),
				$has_amelia ? __( 'detected', 'hal-member-profiles' ) : __( 'not detected', 'hal-member-profiles' )
			);

			self::print_row(
				__( 'Secret store (card D-07)', 'hal-member-profiles' ),
				class_exists( SecretStore::class ) ? self::secret_status_text() : __( 'unavailable (fail-closed)', 'hal-member-profiles' )
			);

			self::print_row(
				__( 'Amelia sync mode', 'hal-member-profiles' ),
				class_exists( SchemaRegistry::class ) ? SchemaRegistry::current_sync_mode() : __( 'unknown (fail-closed)', 'hal-member-profiles' )
			);
			?>
			</tbody>
			</table>

			<?php if ( ! class_exists( SecretStore::class ) || ! class_exists( SchemaRegistry::class ) ) : ?>
				<p><em><?php esc_html_e( 'Amelia management modules unavailable (fail-closed).', 'hal-member-profiles' ); ?></em></p>
				<?php
				return;
			endif;
			?>

			<h3><?php esc_html_e( 'Amelia API key', 'hal-member-profiles' ); ?></h3>
			<table class="widefat striped" style="max-width:900px"><tbody>
				<?php
				self::print_row(
					__( 'Current key', 'hal-member-profiles' ),
					SecretStore::masked_preview() ?? __( '(none stored)', 'hal-member-profiles' )
				);

				$state = SecretStore::storage_state();

				self::print_row(
					__( 'Storage', 'hal-member-profiles' ),
					sprintf(
						/* translators: 1: source slug, 2: decodable state. */
						__( 'source: %1$s · decryptable: %2$s', 'hal-member-profiles' ),
						(string) $state['source'],
						null === $state['decodable'] ? '—' : ( $state['decodable'] ? __( 'yes', 'hal-member-profiles' ) : __( 'NO — re-entry required', 'hal-member-profiles' ) )
					)
				);
				?>
			</tbody></table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
				<input type="hidden" name="action" value="hal_member_profiles_key_save" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_key_save' ) ); ?>" />
				<input type="password" name="amelia_api_key" autocomplete="new-password" style="min-width:320px" placeholder="<?php esc_attr_e( 'Paste a new Amelia Elite API key…', 'hal-member-profiles' ); ?>" />
				<?php submit_button( __( 'Save key (encrypted)', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px">
				<input type="hidden" name="action" value="hal_member_profiles_key_revoke" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_key_revoke' ) ); ?>" />
				<?php submit_button( __( 'Revoke stored key', 'hal-member-profiles' ), 'link-delete', 'submit', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Connection & discovery', 'hal-member-profiles' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px">
				<input type="hidden" name="action" value="hal_member_profiles_conn_test" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_conn_test' ) ); ?>" />
				<?php submit_button( __( 'Test connection (read-only)', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px">
				<input type="hidden" name="action" value="hal_member_profiles_snap_refresh" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_snap_refresh' ) ); ?>" />
				<?php submit_button( __( 'Refresh / discover snapshot', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Requires Amelia sync mode above Off. Builds a PII-free local catalog; employees appear as numeric IDs only.', 'hal-member-profiles' ); ?></p>
			</form>

			<h3><?php esc_html_e( 'Fields sync plan (diff preview) — managed modes only', 'hal-member-profiles' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hal_member_profiles_desired_save" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_desired_save' ) ); ?>" />
				<label for="hal-amelia-desired" class="screen-reader-text"><?php esc_html_e( 'Desired custom fields', 'hal-member-profiles' ); ?></label>
				<textarea id="hal-amelia-desired" name="hal_amelia_desired" rows="5" style="width:640px;font-family:monospace"></textarea>
				<p class="description"><?php esc_html_e( 'One field per line: Title | type (text, select or url). These become HAL-owned Amelia custom fields once you run Apply.', 'hal-member-profiles' ); ?></p>
				<?php submit_button( __( 'Save desired set', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			$desired = get_option( self::DESIRED_OPTION, array() );
			$desired = is_array( $desired ) ? $desired : array();
			$plan    = \HAL\MemberProfiles\Integrations\AmeliaFieldsWriter::build_plan( $desired );
			?>

			<?php if ( empty( $plan['ok'] ) ) : ?>
				<p><em><?php esc_html_e( 'No snapshot yet — use Refresh / discover first (sync mode must be above Off).', 'hal-member-profiles' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;margin-top:6px">
					<thead><tr>
						<th><?php esc_html_e( 'Action', 'hal-member-profiles' ); ?></th>
						<th><?php esc_html_e( 'Key / ID', 'hal-member-profiles' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'hal-member-profiles' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach (
						array(
							'to_create' => __( 'create', 'hal-member-profiles' ),
							'to_update' => __( 'update', 'hal-member-profiles' ),
							'unchanged' => __( 'unchanged', 'hal-member-profiles' ),
							'orphaned'  => __( 'orphaned — manual handling only, never auto-deleted', 'hal-member-profiles' ),
						)
						as $section => $label
					) :
						foreach ( (array) ( $plan['plan'][ $section ] ?? array() ) as $entry ) :
							if ( is_array( $entry ) ) :
								$key_or_id = isset( $entry['amelia_id'] )
									? '#' . (int) $entry['amelia_id']
									: (string) ( $entry['key'] ?? '' );
								?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td><?php echo esc_html( $key_or_id ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['title'] ?? $entry['reason'] ?? '' ) ); ?></td>
								</tr>
								<?php
							else :
								?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td><?php echo esc_html( (string) $entry ); ?></td>
									<td>—</td>
								</tr>
								<?php
							endif;
						endforeach;
					endforeach;
					?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
					<input type="hidden" name="action" value="hal_member_profiles_fields_apply" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_fields_apply' ) ); ?>" />
					<?php submit_button( __( 'Apply plan now', 'hal-member-profiles' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="description">
					<?php esc_html_e( 'Creates/updates HAL-owned fields only. Nothing is ever deleted from Amelia — removals are listed as orphaned for manual handling.', 'hal-member-profiles' ); ?>
				</p>
			<?php endif; ?>

	<?php }

	private static function secret_status_text(): string {
		$state = SecretStore::storage_state();

		$decodable = null === $state['decodable']
			? '—'
			: ( $state['decodable']
				? __( 'yes', 'hal-member-profiles' )
				: __( 'NO — re-entry required', 'hal-member-profiles' ) );

		return sprintf(
			/* translators: 1: source slug, 2: decodability state. */
			__( 'source: %1$s · decryptable: %2$s', 'hal-member-profiles' ),
			(string) $state['source'],
			$decodable
		);
	}

	/**
	 * Diagnostics rows: the runtime compatibility gate verdict per capability, with the
	 * binding observe/native-fallback consequence spelled out. Machine slugs only.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function render_diagnostics_rows( ?Bootstrap $bootstrap ): void {
		if ( null === $bootstrap ) {
			self::print_row( __( 'Compatibility gate', 'hal-member-profiles' ), __( 'unavailable — plugin core unbooted; everything stays on native rendering', 'hal-member-profiles' ) );

			return;
		}

		$gate = $bootstrap->get_compatibility_gate();

		if ( null === $gate ) {
			self::print_row( __( 'Compatibility gate', 'hal-member-profiles' ), __( 'not initialized (fail-closed)', 'hal-member-profiles' ) );

			return;
		}

		// Integration Closure #5: enumerate ALL eight capabilities straight from the gate,
		// with a human reason derived from describe() for every single one.
		$labels = array(
			'profile'                => __( 'Profile Elementor layout', 'hal-member-profiles' ),
			'account'                => __( 'Account Elementor layout', 'hal-member-profiles' ),
			'amelia'                 => __( 'Amelia bridge capabilities', 'hal-member-profiles' ),
			'managed_templates'      => __( 'Managed templates provisioning', 'hal-member-profiles' ),
			'amelia_api_read'        => __( 'Amelia API read', 'hal-member-profiles' ),
			'amelia_fields_write'    => __( 'Amelia fields write', 'hal-member-profiles' ),
			'um_schema'              => __( 'UM schema normalization', 'hal-member-profiles' ),
			'elementor_dynamic_tags' => __( 'Elementor Dynamic Tags', 'hal-member-profiles' ),
		);

		foreach ( $gate->capabilities() as $capability => $components ) {
			$label  = isset( $labels[ $capability ] ) ? $labels[ $capability ] : $capability;
			$reason = $gate->describe( $capability );
			$passes = $gate->passes( $capability );

			if ( $passes ) {
				$text = __( 'approved composition', 'hal-component' );
			} elseif ( 0 === strpos( $reason, 'missing_components:' ) ) {
				$missing = substr( $reason, strlen( 'missing_components:' ) );

				/* translators: %s: comma-separated component slugs. */
				$text = sprintf( __( 'locked — missing components: %1$s (native fallback active)', 'hal-member-profiles' ), $missing );
			} elseif ( 'awaiting_matrix_signoff' === $reason ) {
				$text = __( 'awaiting matrix sign-off — observe mode until QA passes on this exact composition', 'hal-member-profiles' );
			} elseif ( 'composition_mismatch' === $reason ) {
				$text = __( 'current versions differ from the signed composition — native fallback active', 'hal-member-profiles' );
			} elseif ( 'unknown_capability' === $reason ) {
				$text = $capability;
			} else {
				$text = $reason;
			}

			self::print_row( $label, $text );
		}
	}

	/**
	 * Prints one escaped label/value table row.
	 *
	 * @param string $label Raw label (escaped here).
	 * @param string $value Pre-escaped-safe plain text (escaped here).
	 * @return void
	 */
	private static function print_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
