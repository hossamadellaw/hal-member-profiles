<?php
/**
 * The HAL Member Profiles admin dashboard — development card D-06.
 *
 * Sole responsibility: the HAL Member Profiles admin menu (no SPA, no framework) with its
 * six governed pages — Overview, Profiles, Account, Amelia, Diagnostics, Settings (card
 * S-04) — plus the delegated Amelia action endpoints and the lifecycle Repair button.
 *
 * Hard rules implemented here:
 * - Read-only DISPLAY, delegated ACTIONS: the six pages only ever render state (versions,
 *   modes, gate verdicts, manifest rows) — no page render writes anything. The delegated
 *   write paths are separate, capability- and nonce-guarded admin_post endpoints: the
 *   lifecycle Repair/Sync endpoint (card D-05, `admin_post.php?action=hal_member_profiles_repair`,
 *   reusing Lifecycle::repair_nonce()) and the six governed Amelia routes in
 *   handle_amelia_post() (key save/revoke, connection test, snapshot refresh, desired-set
 *   save, fields apply) — each performing only its own governed verbs. Editable settings
 *   remain owned by Settings::register_page() (Settings API, card 7.4) on the Settings
 *   page; this dashboard links there instead of duplicating any save path.
 * - Capability: every entry point requires manage_options (the documented project
 *   capability per compatibility-matrix §7). Non-authorized admins get a 403 wp_die;
 *   the page itself is only registered under the same capability.
 * - Fail-closed presentation: every section explains WHY a feature is unavailable
 *   (dependency missing, compatibility gate not passed, provisioning blocked) instead
 *   of hiding or guessing. The shipped modules (SecretStore, AmeliaApiClient,
 *   SchemaRegistry) render their real state here.
 * - No secrets, no PII: diagnostics print versions, modes, booleans, relative paths,
 *   and machine slugs only — never user records, emails, meta values, or keys.
 * - Notices are resolvable: the action-result notices render once from their query arg
 *   and disappear on the next pageload, exactly matching the acceptance rule.
 *
 * v1.2.1 (card F-05): while the F-01 version gate keeps writes disapproved, the active
 * Sync form and its caption/description are replaced by a passive informational row
 * (E-14: an active false promise is never shown), and the Diagnostics page gains the
 * consolidated READ-ONLY Readiness card — managed-file inspection (E-13), the gate
 * describe() verdict per capability, UM mapping availability, per-persona template
 * validity, the current UM Forms selection across every um_form post (including every
 * WPML-translated copy) with template-file presence in the active child theme, and the
 * F-11 contract pre-check behind a class_exists degradation guard. Zero writes.
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

	// Card S-04: the four section submenus, in the mandated order after Overview and
	// before the Settings submenu (Settings::PAGE_SLUG).
	public const PROFILES_PAGE_SLUG    = 'hal-member-profiles-profiles';
	public const ACCOUNT_PAGE_SLUG     = 'hal-member-profiles-account';
	public const AMELIA_PAGE_SLUG      = 'hal-member-profiles-amelia';
	public const DIAGNOSTICS_PAGE_SLUG = 'hal-member-profiles-diagnostics';

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

		add_action( 'admin_menu', array( self::class, 'add_page' ), 5 );
		add_action( 'admin_menu', array( self::class, 'add_overview_submenu' ), 15 );
		add_action( 'admin_menu', array( self::class, 'add_section_submenus' ), 15 );
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
		$redirect = $redirect ? $redirect : admin_url( 'admin.php?page=' . self::AMELIA_PAGE_SLUG );

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

		if ( ! class_exists( \HAL\MemberProfiles\Integrations\AmeliaApiClient::class, false ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/AmeliaApiClient.php';
		}

		if ( ! class_exists( \HAL\MemberProfiles\Integrations\AmeliaFieldsWriter::class, false ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/AmeliaFieldsWriter.php';
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
	 * Registers the explicit Overview submenu carrying the parent slug (card S-03).
	 * Runs at a later admin_menu priority than add_page(), so the parent always exists
	 * first regardless of module creation order.
	 *
	 * @return void
	 */
	public static function add_overview_submenu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Overview', 'hal-member-profiles' ),
			__( 'Overview', 'hal-member-profiles' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Registers the four section submenus (card S-04) — Profiles, Account, Amelia,
	 * Diagnostics, in the mandated order. Runs at the same later admin_menu priority as
	 * the Overview submenu, right after it, so the parent always exists first and the
	 * Settings submenu (priority 20, registered by Settings) appends last.
	 *
	 * @return void
	 */
	public static function add_section_submenus(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Profiles', 'hal-member-profiles' ),
			__( 'Profiles', 'hal-member-profiles' ),
			'manage_options',
			self::PROFILES_PAGE_SLUG,
			array( self::class, 'render_profiles_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Account', 'hal-member-profiles' ),
			__( 'Account', 'hal-member-profiles' ),
			'manage_options',
			self::ACCOUNT_PAGE_SLUG,
			array( self::class, 'render_account_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Amelia', 'hal-member-profiles' ),
			__( 'Amelia', 'hal-member-profiles' ),
			'manage_options',
			self::AMELIA_PAGE_SLUG,
			array( self::class, 'render_amelia_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Diagnostics', 'hal-member-profiles' ),
			__( 'Diagnostics', 'hal-member-profiles' ),
			'manage_options',
			self::DIAGNOSTICS_PAGE_SLUG,
			array( self::class, 'render_diagnostics_page' )
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
	 * Renders the Overview page (card S-04): system state, dependencies, and actionable
	 * alerts only — brief by design; the sections moved to their own pages.
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
			<p class="description"><?php esc_html_e( 'Brief system state and dependency versions for HAL Member Profiles.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'open Profiles or Account to review layout state, or Settings to edit values.', 'hal-member-profiles' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Overview / Health', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php self::render_overview_rows( $bootstrap ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Profiles page (card S-04): read-only profile layout/template state plus the
	 * profile-side UM fields/Dynamic Tags status, with guidance toward Settings.
	 *
	 * @return void
	 */
	public static function render_profiles_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'hal-member-profiles' ), 403 );
		}

		self::load_module_classes();

		$bootstrap = Bootstrap::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Profiles', 'hal-member-profiles' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Read-only profile layout state — the editable values live on the Settings page.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'open the Settings page to configure the profile template.', 'hal-member-profiles' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Profile layout / template', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php
					if ( null !== $bootstrap ) {
						$settings = $bootstrap->get_settings();

						$profile_mode = $settings->get_profile_layout_mode();

						self::print_state_row(
							__( 'Profile layout mode', 'hal-member-profiles' ),
							'public_layout' === $profile_mode ? 'ready' : 'not_configured',
							$profile_mode
						);

						$template_id = $settings->get_profile_library_template_id();

						self::print_row(
							__( 'Profile Elementor library template', 'hal-member-profiles' ),
							null === $template_id
								? self::state_label( 'not_configured' )
								: '#' . (string) $template_id
						);
					} else {
						self::print_row(
							__( 'Profile layout state', 'hal-member-profiles' ),
							__( 'unavailable — plugin core is unbooted (fail-closed).', 'hal-member-profiles' )
						);
					}
					?>
				</tbody>
			</table>

			<h2 class="title"><?php esc_html_e( 'UM Fields / Dynamic Tags', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php self::render_fields_and_tags_rows( $bootstrap ); ?>
				</tbody>
			</table>

			<?php self::render_settings_link( $bootstrap ); ?>
		</div>
		<?php
	}

	/**
	 * Account page (card S-04/C2): read-only native ownership and legacy-state
	 * observations, plus the Account selectors/Tags state.
	 *
	 * @return void
	 */
	public static function render_account_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'hal-member-profiles' ), 403 );
		}

		self::load_module_classes();

		$bootstrap = Bootstrap::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account', 'hal-member-profiles' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ultimate Member owns and renders the complete Account page, including navigation, active state, forms, nonces, and extension tabs.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'review the legacy override observation below; HAL never changes or deletes that file.', 'hal-member-profiles' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Account layout / template', 'hal-member-profiles' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<?php
					if ( null !== $bootstrap ) {
						$settings     = $bootstrap->get_settings();
						$field_schema = $bootstrap->get_field_schema();

						self::print_state_row(
							__( 'Account layout mode', 'hal-member-profiles' ),
							'ready',
							__( 'Native (Ultimate Member)', 'hal-member-profiles' )
						);

						$template_id = $settings->get_account_library_template_id();

						self::print_row(
							__( 'Legacy Account Elementor template (retained, inactive)', 'hal-member-profiles' ),
							null === $template_id
								? self::state_label( 'not_configured' )
								: '#' . (string) $template_id
						);

						if ( class_exists( ManagedTemplates::class ) ) {
							self::print_row(
								__( 'Legacy theme Account override', 'hal-member-profiles' ),
								self::legacy_account_override_text( ( new ManagedTemplates() )->inspect_legacy_account_override() )
							);
						}

						$selectors = null !== $field_schema ? $field_schema->get_account_selectors() : array();

						self::print_state_row(
							__( 'Account field selectors', 'hal-member-profiles' ),
							empty( $selectors ) ? 'not_configured' : 'ready',
							empty( $selectors )
								? __( 'fields stay inside the native Account body until a verified field source exists', 'hal-member-profiles' )
								/* translators: %s: number of registered selectors. */
								: sprintf( __( '%s registered from the verified source', 'hal-member-profiles' ), (string) count( $selectors ) )
						);
					} else {
						self::print_row(
							__( 'Account layout state', 'hal-member-profiles' ),
							__( 'unavailable — plugin core is unbooted (fail-closed).', 'hal-member-profiles' )
						);
					}
					?>
				</tbody>
			</table>

			<?php self::render_settings_link( $bootstrap ); ?>
		</div>
		<?php
	}

	/**
	 * Amelia page (card S-04): the existing render_amelia_rows() content and its
	 * delegated operations, moved here unchanged.
	 *
	 * @return void
	 */
	public static function render_amelia_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'hal-member-profiles' ), 403 );
		}

		self::load_module_classes();

		$bootstrap = Bootstrap::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Amelia', 'hal-member-profiles' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Connection state and delegated Amelia operations — availability and booking always stay inside Amelia itself.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'paste a key only when you intend to connect; every action shows its result banner once.', 'hal-member-profiles' ); ?></p>
			<?php self::render_amelia_rows( $bootstrap ); ?>
		</div>
		<?php
	}

	/**
	 * Diagnostics page (card S-04): compatibility gate verdicts, the managed templates
	 * manifest/lifecycle state with the version-gated Repair/Sync surface (card F-05:
	 * the active form is replaced by a passive row while the F-01 gate disapproves
	 * writes), and the consolidated read-only Readiness card (card F-05).
	 *
	 * @return void
	 */
	public static function render_diagnostics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'hal-member-profiles' ), 403 );
		}

		self::load_module_classes();

		$bootstrap = Bootstrap::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Diagnostics', 'hal-member-profiles' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Compatibility verdicts and the managed-template manifest/lifecycle state.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'run Sync only after reviewing the manifest table below.', 'hal-member-profiles' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Compatibility / Diagnostics', 'hal-member-profiles' ); ?></h2>
			<?php self::render_diagnostics_rows( $bootstrap ); ?>

			<h2 class="title"><?php esc_html_e( 'Managed templates / lifecycle', 'hal-member-profiles' ); ?></h2>
			<?php self::render_managed_templates_block(); ?>

			<h2 class="title"><?php esc_html_e( 'Readiness (read-only)', 'hal-member-profiles' ); ?></h2>
			<?php self::render_readiness_card( $bootstrap ); ?>
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
	 * Settings-page link used by the read-only pages to guide operators to the editable
	 * values (card S-04).
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function render_settings_link( ?Bootstrap $bootstrap ): void {
		if ( null === $bootstrap ) {
			return;
		}

		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Open the settings page', 'hal-member-profiles' ) . '</a></p>';
	}

	/**
	 * Managed asset inspection table (the required pre-operation preview) and the single
	 * delegated Repair/Sync button posting to D-05's guarded endpoint — moved to the
	 * Diagnostics page by card S-04, content unchanged.
	 *
	 * @return void
	 */
	private static function render_managed_templates_block(): void {
		if ( ! class_exists( ManagedTemplates::class ) ) {
			echo '<p><em>' . esc_html__( 'Managed template module unavailable (fail-closed).', 'hal-member-profiles' ) . '</em></p>';

			return;
		}

		$managed    = new ManagedTemplates();
		$inspection = $managed->inspect();
		$legacy     = $managed->inspect_legacy_account_override();

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		self::print_row(
			__( 'Legacy theme Account override (observe only)', 'hal-member-profiles' ),
			self::legacy_account_override_text( $legacy )
		);
		echo '</tbody></table>';

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

		// Card F-05 (E-14): while the F-01 version gate keeps writes disapproved, the
		// active Sync form and its caption/description are an ACTIVE false promise —
		// they are replaced by a passive informational row. The original form returns
		// verbatim once writes_approved_for_version() turns true (from 1.3.0).
		if ( ! $managed->writes_approved_for_version() ) {
			printf(
				'<p class="description"><em>%1$s</em></p>',
				esc_html__( 'Writes are disabled in this release by the version gate — see Diagnostics.', 'hal-member-profiles' )
			);

			return;
		}

		self::render_sync_form();
	}

	/**
	 * The single delegated Repair/Sync form (D-05 endpoint) with its caption/description —
	 * extracted verbatim by card F-05 so the version-gate branch above can swap it for a
	 * passive row while keeping this exact markup for the approved (1.3.0+) state.
	 *
	 * @return void
	 */
	private static function render_sync_form(): void {
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
	 * Card F-05: the consolidated READ-ONLY Readiness card on the Diagnostics page — six
	 * checks, each a pure read (zero writes, zero state):
	 *  (a) managed-file status via ManagedTemplates::inspect() (E-13: read-only and
	 *      functional on 1.2.x);
	 *  (b) the executive gate verdict plus machine-stable describe() reason for EVERY
	 *      capability;
	 *  (c) UM member mapping availability plus its machine reason;
	 *  (d) per-persona public-profile template validity (the effective, revalidated
	 *      selection the renderer would actually use);
	 *  (e) the CURRENT UM Forms selection — `_um_profile_template` for EVERY `um_form`
	 *      post (each WPML-translated copy is its own um_form post and is enumerated
	 *      individually) plus whether the referenced template file exists in the ACTIVE
	 *      CHILD THEME;
	 *  (f) the F-11 contract pre-check, degrading behind a class_exists guard to
	 *      "unavailable (validator not deployed)" when the validator class is absent —
	 *      never to a false pass.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null (rows degrade fail-closed).
	 * @return void
	 */
	private static function render_readiness_card( ?Bootstrap $bootstrap ): void {
		?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php
			// (a) managed files.
			self::readiness_managed_files_rows();

			// (b) gate describe() per capability.
			self::readiness_gate_rows( $bootstrap );

			// (c) + (d) UM mapping availability and per-persona template validity.
			self::readiness_mapping_and_persona_rows( $bootstrap );

			// (e) UM Forms selection + template file presence.
			self::readiness_um_forms_rows();

			// (f) F-11 contract pre-check (ROUND-4 degradation behind class_exists).
			self::readiness_contract_rows( $bootstrap );
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Readiness check (a): managed-file status per manifest asset through the read-only
	 * inspection (E-13). Degrades to an explicit fail-closed row when the inspection is
	 * declined, fails, or reports no assets. Never writes.
	 *
	 * @return void
	 */
	private static function readiness_managed_files_rows(): void {
		if ( ! class_exists( ManagedTemplates::class ) ) {
			self::print_state_row( __( 'Managed files', 'hal-member-profiles' ), 'blocked', 'unavailable (fail-closed)' );

			return;
		}

		try {
			$inspection = ( new ManagedTemplates() )->inspect();
		} catch ( \Throwable $e ) {
			self::print_state_row( __( 'Managed files', 'hal-member-profiles' ), 'blocked', 'inspect_failed' );

			return;
		}

		if ( ! is_array( $inspection ) || empty( $inspection['ok'] ) ) {
			$reason = is_array( $inspection ) && is_string( $inspection['reason'] ?? null ) ? $inspection['reason'] : 'unknown';

			self::print_state_row( __( 'Managed files', 'hal-member-profiles' ), 'blocked', 'inspection declined — ' . $reason );

			return;
		}

		$assets = $inspection['assets'] ?? array();

		if ( ! is_array( $assets ) || array() === $assets ) {
			self::print_state_row( __( 'Managed files', 'hal-member-profiles' ), 'blocked', 'no managed assets declared' );

			return;
		}

		foreach ( $assets as $source_path => $info ) {
			$status = is_array( $info ) && is_string( $info['target_status'] ?? null ) ? $info['target_status'] : '';

			self::print_state_row(
				sprintf(
					/* translators: %s: managed asset source path. */
					__( 'Managed file — %s', 'hal-member-profiles' ),
					(string) $source_path
				),
				ManagedTemplates::STATUS_CURRENT === $status ? 'ready' : 'blocked',
				'' !== $status ? $status : 'unknown'
			);
		}
	}

	/**
	 * Readiness check (b): the gate verdict and machine-stable describe() reason for
	 * EVERY capability, straight from the shared gate instance. Never writes.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function readiness_gate_rows( ?Bootstrap $bootstrap ): void {
		if ( null === $bootstrap ) {
			self::print_state_row( __( 'Compatibility gate', 'hal-member-profiles' ), 'blocked', 'unavailable — plugin core unbooted (fail-closed)' );

			return;
		}

		try {
			$gate = $bootstrap->get_compatibility_gate();
		} catch ( \Throwable $e ) {
			$gate = null;
		}

		if ( null === $gate ) {
			self::print_state_row( __( 'Compatibility gate', 'hal-member-profiles' ), 'blocked', 'not initialized (fail-closed)' );

			return;
		}

		try {
			$capabilities = $gate->capabilities();
		} catch ( \Throwable $e ) {
			$capabilities = array();
		}

		foreach ( array_keys( $capabilities ) as $capability ) {
			try {
				$reason = $gate->describe( (string) $capability );
				$passes = $gate->passes( (string) $capability );
			} catch ( \Throwable $e ) {
				$reason = 'gate_unavailable';
				$passes = false;
			}

			self::print_state_row(
				sprintf(
					/* translators: %s: capability slug. */
					__( 'Compatibility gate — %s', 'hal-member-profiles' ),
					(string) $capability
				),
				$passes ? 'ready' : 'blocked',
				(string) $reason
			);
		}
	}

	/**
	 * Readiness checks (c) + (d): UM member mapping availability plus reason, then the
	 * effective per-persona template validity. Every read is a revalidating Settings
	 * read; nothing is ever written.
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function readiness_mapping_and_persona_rows( ?Bootstrap $bootstrap ): void {
		$settings = null;

		if ( null !== $bootstrap ) {
			try {
				$settings = $bootstrap->get_settings();
			} catch ( \Throwable $e ) {
				$settings = null;
			}
		}

		if ( null === $settings ) {
			self::print_state_row( __( 'UM member mapping', 'hal-member-profiles' ), 'blocked', 'unavailable — plugin core unbooted (fail-closed)' );
			self::print_state_row( __( 'Persona templates', 'hal-member-profiles' ), 'blocked', 'unavailable — plugin core unbooted (fail-closed)' );

			return;
		}

		try {
			$mapping = $settings->get_um_member_mapping();
		} catch ( \Throwable $e ) {
			$mapping = array();
		}

		$available = is_array( $mapping ) && 'available' === ( $mapping['availability'] ?? '' );
		$reason    = is_array( $mapping ) && is_string( $mapping['reason'] ?? null ) ? $mapping['reason'] : 'unavailable';

		self::print_state_row(
			__( 'UM member mapping', 'hal-member-profiles' ),
			$available ? 'ready' : 'blocked',
			(string) $reason
		);

		foreach ( array( Settings::PERSONA_LAWYER, Settings::PERSONA_CONSULTANT, Settings::PERSONA_ORDINARY_MEMBER ) as $persona ) {
			try {
				$template_id = $settings->get_public_profile_template_id_for_persona( $persona );
			} catch ( \Throwable $e ) {
				$template_id = null;
			}

			self::print_state_row(
				sprintf(
					/* translators: %s: member type slug. */
					__( 'Persona template — %s', 'hal-member-profiles' ),
					$persona
				),
				null !== $template_id ? 'ready' : 'not_configured',
				null !== $template_id
					? sprintf(
						/* translators: %d: Elementor library template ID. */
						__( 'valid selection — template #%d', 'hal-member-profiles' ),
						$template_id
					)
					: __( 'no valid template selected — the native Ultimate Member profile renders', 'hal-member-profiles' )
			);
		}
	}

	/**
	 * Readiness check (e): the CURRENT UM Forms selection. Every `um_form` post is
	 * enumerated (post_status 'any' — a WPML-translated copy is its own um_form post and
	 * carries its OWN `_um_profile_template`, so per-post rows are the only complete
	 * view), and for each non-empty selection the card reports whether the referenced
	 * template file exists in the ACTIVE CHILD THEME. Meta and stat reads only — the
	 * form, its meta, and the theme are never modified.
	 *
	 * @return void
	 */
	private static function readiness_um_forms_rows(): void {
		$label = __( 'UM Forms (um_form)', 'hal-member-profiles' );

		if ( ! function_exists( 'get_posts' ) ) {
			self::print_state_row( $label, 'blocked', 'unavailable — post queries are unavailable (fail-closed)' );

			return;
		}

		try {
			$found = get_posts(
				array(
					'post_type'      => UmFormTemplateWatcher::WATCHED_POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
		} catch ( \Throwable $e ) {
			$found = null;
		}

		if ( ! is_array( $found ) ) {
			self::print_state_row( $label, 'blocked', 'unavailable — the post query failed (fail-closed)' );

			return;
		}

		$rows = 0;

		foreach ( $found as $form ) {
			$post = null;

			if ( is_numeric( $form ) ) {
				try {
					$post = get_post( (int) $form );
				} catch ( \Throwable $e ) {
					$post = null;
				}
			} elseif ( is_object( $form ) && isset( $form->ID ) ) {
				$post = $form;
			}

			if ( ! is_object( $post ) || (int) $post->ID <= 0 ) {
				continue;
			}

			if ( isset( $post->post_type ) && UmFormTemplateWatcher::WATCHED_POST_TYPE !== (string) $post->post_type ) {
				continue;
			}

			$form_id = (int) $post->ID;
			$title   = isset( $post->post_title ) && is_scalar( $post->post_title ) ? trim( (string) $post->post_title ) : '';

			$row_label = '' !== $title
				? sprintf(
					/* translators: 1: form post ID, 2: form title. */
					__( 'UM form #%1$d — %2$s', 'hal-member-profiles' ),
					$form_id,
					$title
				)
				: sprintf(
					/* translators: %d: form post ID. */
					__( 'UM form #%d', 'hal-member-profiles' ),
					$form_id
				);

			try {
				$selected = get_post_meta( $form_id, UmFormTemplateWatcher::WATCHED_META_KEY, true );
			} catch ( \Throwable $e ) {
				$selected = '';
			}

			$selected = is_scalar( $selected ) ? trim( (string) $selected ) : '';

			if ( '' === $selected ) {
				self::print_state_row(
					$row_label,
					'not_configured',
					__( 'no template selected — the Ultimate Member default profile renders', 'hal-member-profiles' )
				);

				$rows++;

				continue;
			}

			$found_relative = self::locate_um_template_file( $selected );

			if ( '' !== $found_relative ) {
				self::print_state_row(
					$row_label,
					'ready',
					sprintf(
						/* translators: 1: selected template slug, 2: relative template file path. */
						__( 'selected: "%1$s" — template file present (%2$s)', 'hal-member-profiles' ),
						$selected,
						$found_relative
					)
				);
			} else {
				self::print_state_row(
					$row_label,
					'blocked',
					sprintf(
						/* translators: %s: selected template slug. */
						__( 'selected: "%1$s" — template file NOT found in the active child theme', 'hal-member-profiles' ),
						$selected
					)
				);
			}

			$rows++;
		}

		if ( 0 === $rows ) {
			self::print_state_row( $label, 'not_configured', __( 'none found', 'hal-member-profiles' ) );
		}
	}

	/**
	 * Locates a UM profile template file, named by its `_um_profile_template` value,
	 * inside the ACTIVE CHILD THEME only — both Ultimate Member's theme conventions:
	 * the modern `ultimate-member/templates/` location (also the managed manifest's
	 * target base) and the legacy theme-root location. Pure existence stats; the value
	 * comes from post meta, so anything outside a plain file slug fails closed to
	 * "not found". Never writes.
	 *
	 * @param string $template Template slug from the form meta.
	 * @return string The found relative path, or '' when absent in the child theme.
	 */
	private static function locate_um_template_file( string $template ): string {
		if ( '' === $template || preg_match( '/[^A-Za-z0-9_\-]/', $template ) ) {
			return '';
		}

		$base = function_exists( 'get_stylesheet_directory' )
			? rtrim( (string) get_stylesheet_directory(), '/\\' )
			: '';

		if ( '' === $base ) {
			return '';
		}

		foreach ( array( 'ultimate-member/templates/' . $template . '.php', 'ultimate-member/' . $template . '.php' ) as $relative ) {
			if ( file_exists( $base . '/' . $relative ) ) {
				return $relative;
			}
		}

		return '';
	}

	/**
	 * Readiness check (f): the F-11 contract pre-check row. The validator is resolved
	 * behind a class_exists guard; a degraded deploy without the class renders the
	 * ROUND-4 degradation row — never a pass (see readiness_contract_verdict()).
	 *
	 * @param Bootstrap|null $bootstrap Booted instance or null.
	 * @return void
	 */
	private static function readiness_contract_rows( ?Bootstrap $bootstrap ): void {
		$settings = null;

		if ( null !== $bootstrap ) {
			try {
				$settings = $bootstrap->get_settings();
			} catch ( \Throwable $e ) {
				$settings = null;
			}
		}

		$validator = class_exists( TemplateContractValidator::class ) ? new TemplateContractValidator() : null;

		$verdict = self::readiness_contract_verdict( $settings, $validator );

		self::print_state_row(
			__( 'Template contract (pre-check)', 'hal-member-profiles' ),
			$verdict['state'],
			$verdict['detail']
		);
	}

	/**
	 * The pure F-11 contract verdict for the readiness card, kept free of rendering so
	 * the ROUND-4 degradation branch is unit-testable: a missing validator (null) reads
	 * as "unavailable (validator not deployed)" — never as a pass. With the validator
	 * deployed, the verdict mirrors the fail-closed validator contract: only a confirmed
	 * pass reads ready; unverified/fallback verdicts read blocked.
	 *
	 * @param Settings|null                  $settings  Shared settings service, or null when unbooted.
	 * @param TemplateContractValidator|null $validator Resolved validator, or null when the F-11 class is absent.
	 * @return array{state:string, detail:string}
	 */
	private static function readiness_contract_verdict( ?Settings $settings, ?TemplateContractValidator $validator ): array {
		if ( null === $settings ) {
			return array(
				'state'  => 'blocked',
				'detail' => 'unavailable — plugin core unbooted (fail-closed)',
			);
		}

		if ( null === $validator ) {
			return array(
				'state'  => 'blocked',
				'detail' => 'unavailable (validator not deployed)',
			);
		}

		try {
			$template_id = $settings->get_profile_library_template_id();
		} catch ( \Throwable $e ) {
			return array(
				'state'  => 'blocked',
				'detail' => 'settings_unavailable',
			);
		}

		if ( ! is_int( $template_id ) || $template_id <= 0 ) {
			return array(
				'state'  => 'not_configured',
				'detail' => 'no profile library template selected',
			);
		}

		try {
			$result = $validator->validate_template( $template_id );
		} catch ( \Throwable $e ) {
			return array(
				'state'  => 'blocked',
				'detail' => 'validation_failed',
			);
		}

		$reason = is_array( $result ) && is_string( $result['reason'] ?? null ) ? $result['reason'] : 'invalid_result';
		$detail = sprintf(
			/* translators: 1: validator verdict slug, 2: template post ID. */
			__( '%1$s — template #%2$d', 'hal-member-profiles' ),
			$reason,
			(int) $template_id
		);

		return array(
			'state'  => is_array( $result ) && true === ( $result['pass'] ?? false ) ? 'ready' : 'blocked',
			'detail' => $detail,
		);
	}

	/**
	 * Converts the fixed, read-only legacy Account override verdict into operator text.
	 * No path, hash, contents, or user data are exposed.
	 *
	 * @param array{ok?:bool,status?:string} $observation Legacy override observation.
	 * @return string
	 */
	private static function legacy_account_override_text( array $observation ): string {
		$status = is_string( $observation['status'] ?? null ) ? $observation['status'] : 'unknown';
		$labels = array(
			ManagedTemplates::STATUS_LEGACY_OVERRIDE_PRESENT    => __( 'present — retained and never managed by HAL', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_LEGACY_OVERRIDE_ABSENT     => __( 'absent — Ultimate Member native Account remains active', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_LEGACY_OVERRIDE_UNREADABLE => __( 'present but unreadable — retained and never managed by HAL', 'hal-member-profiles' ),
			ManagedTemplates::STATUS_NEEDS_CREDENTIALS          => __( 'unavailable — filesystem access is not ready', 'hal-member-profiles' ),
			'denied'                                            => __( 'unavailable — inspection was denied', 'hal-member-profiles' ),
		);

		return $labels[ $status ] ?? __( 'unavailable — unknown observation state', 'hal-member-profiles' );
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

			if ( class_exists( SchemaRegistry::class ) ) {
				// Card C6a: the dashboard reports the mode's PROVEN capabilities from the
				// server-side gates — the UI never claims an ability the mode does not have.
				self::print_row(
					__( 'Field-definition capabilities (card C6a)', 'hal-member-profiles' ),
					sprintf(
						/* translators: 1: read verdict, 2: diff verdict, 3: create verdict, 4: update verdict. */
						__( 'read: %1$s · diff: %2$s · create missing: %3$s · update: %4$s (delete: never)', 'hal-member-profiles' ),
						SchemaRegistry::mode_allows_read() ? __( 'yes', 'hal-member-profiles' ) : __( 'no', 'hal-member-profiles' ),
						SchemaRegistry::mode_allows_read() ? __( 'yes', 'hal-member-profiles' ) : __( 'no', 'hal-member-profiles' ),
						SchemaRegistry::mode_allows_create() ? __( 'yes', 'hal-member-profiles' ) : __( 'no', 'hal-member-profiles' ),
						SchemaRegistry::mode_allows_update() ? __( 'yes', 'hal-member-profiles' ) : __( 'no', 'hal-member-profiles' )
					)
				);
			}
			?>
			</tbody>
			</table>

			<?php if ( ! class_exists( SecretStore::class ) || ! class_exists( SchemaRegistry::class ) ) : ?>
				<p><em><?php esc_html_e( 'Amelia management modules unavailable (fail-closed).', 'hal-member-profiles' ); ?></em></p>
				<?php
				return;
			endif;
			?>

			<h2 class="title"><?php esc_html_e( 'Amelia API key', 'hal-member-profiles' ); ?></h2>
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
				<label for="hal-amelia-api-key" class="description"><?php esc_html_e( 'Amelia Elite API key — sensitive, stored encrypted:', 'hal-member-profiles' ); ?></label><br />
				<input id="hal-amelia-api-key" type="password" name="amelia_api_key" autocomplete="new-password" style="min-width:320px" placeholder="<?php esc_attr_e( 'Paste a new Amelia Elite API key…', 'hal-member-profiles' ); ?>" />
				<?php submit_button( __( 'Save key (encrypted)', 'hal-member-profiles' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px">
				<input type="hidden" name="action" value="hal_member_profiles_key_revoke" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'hal_member_profiles_key_revoke' ) ); ?>" />
				<?php submit_button( __( 'Revoke stored key', 'hal-member-profiles' ), 'link-delete', 'submit', false ); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Connection & discovery', 'hal-member-profiles' ); ?></h2>
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

			<h2 class="title"><?php esc_html_e( 'Fields sync plan (diff preview) — managed modes only', 'hal-member-profiles' ); ?></h2>
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
							'conflicts' => __( 'conflict — manual resolution required, never auto-corrected', 'hal-member-profiles' ),
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
		?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php
			if ( null === $bootstrap ) {
				self::print_row( __( 'Compatibility gate', 'hal-member-profiles' ), __( 'unavailable — plugin core unbooted; everything stays on native rendering', 'hal-member-profiles' ) );
			} else {
				$gate = $bootstrap->get_compatibility_gate();

				if ( null === $gate ) {
					self::print_row( __( 'Compatibility gate', 'hal-member-profiles' ), __( 'not initialized (fail-closed)', 'hal-member-profiles' ) );
				} else {
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

			// Card S-05: every verdict opens with one unified, translated state word;
			// the machine reason text behind it stays unchanged.
			$state = null;
			$text  = '';

			if ( $passes ) {
				$state = 'ready';
				$text  = __( 'approved composition', 'hal-member-profiles' );
			} elseif ( 0 === strpos( $reason, 'missing_components:' ) ) {
				$missing = substr( $reason, strlen( 'missing_components:' ) );
				$state   = 'blocked';

				/* translators: %s: comma-separated component slugs. */
				$text = sprintf( __( 'locked — missing components: %1$s (native fallback active)', 'hal-member-profiles' ), $missing );
			} elseif ( 'awaiting_matrix_signoff' === $reason ) {
				$state = 'pending';
				$text  = __( 'awaiting matrix sign-off — observe mode until QA passes on this exact composition', 'hal-member-profiles' );
			} elseif ( 'composition_mismatch' === $reason ) {
				$state = 'blocked';
				$text  = __( 'current versions differ from the signed composition — native fallback active', 'hal-member-profiles' );
			} elseif ( 'unknown_capability' === $reason ) {
				$text = $capability;
			} else {
				$text = $reason;
			}

			self::print_state_row( $label, (string) $state, $text );
				}
			}
			?>
			</tbody>
		</table>
		<?php
		// Card S-08: the evidence reporter is loaded lazily at its ONLY consumption
		// point — never in load_module_classes(), never on frontend, never on other
		// admin requests. The JSON is read-only, PII-free, escaped, and only ever
		// displayed here behind the page's manage_options guard. Any failure in the
		// collector degrades to an explicit fail-closed note — the page never fatals.
		if ( null !== $bootstrap ) {
			$evidence = null;

			try {
				if ( ! class_exists( RuntimeEvidenceReporter::class ) ) {
					require_once HAL_MEMBER_PROFILES_DIR . 'includes/RuntimeEvidenceReporter.php';
				}

				$facts    = RuntimeEvidenceReporter::collect_runtime_facts( $bootstrap );
				$evidence = ( new RuntimeEvidenceReporter( $facts ) )->generate_json();
			} catch ( \Throwable $e ) {
				$evidence = null;
			}
			?>
			<h3 class="title"><?php esc_html_e( 'Runtime compatibility evidence (JSON)', 'hal-member-profiles' ); ?></h3>
			<?php if ( null === $evidence ) : ?>
				<p><em><?php esc_html_e( 'Evidence report unavailable (fail-closed) — no JSON is shown.', 'hal-member-profiles' ); ?></em></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( "Read-only JSON of this site's own HAL state — the external Production Verifier never reads it, and it never changes any compatibility verdict.", 'hal-member-profiles' ); ?></p>
				<textarea readonly rows="14" aria-label="<?php esc_attr_e( 'Runtime compatibility evidence JSON', 'hal-member-profiles' ); ?>" style="max-width:900px;width:100%;font-family:monospace"><?php echo esc_textarea( $evidence ); ?></textarea>
			<?php endif; ?>
			<?php
		}
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

	/**
	 * Unified translated state words (card S-05). Display-only: the machine values and
	 * the decisions behind them never change.
	 *
	 * @param string $state Machine state slug: ready|blocked|pending|not_configured.
	 * @return string
	 */
	private static function state_label( string $state ): string {
		$labels = array(
			'ready'          => __( 'Ready', 'hal-member-profiles' ),
			'blocked'        => __( 'Blocked', 'hal-member-profiles' ),
			'pending'        => __( 'Pending', 'hal-member-profiles' ),
			'not_configured' => __( 'Not configured', 'hal-member-profiles' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * One row whose value opens with the unified state word followed by the unchanged
	 * machine detail — states never rely on color alone (card S-05).
	 *
	 * @param string $label  Raw row label.
	 * @param string $state  Machine state slug accepted by state_label().
	 * @param string $detail Unchanged machine detail (may be empty).
	 * @return void
	 */
	private static function print_state_row( string $label, string $state, string $detail = '' ): void {
		$state_word = '' !== $state ? self::state_label( $state ) : '';

		if ( '' === $state_word ) {
			self::print_row( $label, $detail );

			return;
		}

		$value = '' === $detail
			? $state_word
			: $state_word . ' — ' . $detail;

		self::print_row( $label, $value );
	}
}
