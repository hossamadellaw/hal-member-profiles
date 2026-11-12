<?php
/**
 * Creates each module in dependency order and shares the service instances across HAL
 * Member Profiles.
 *
 * Development card D-14 module order: Dependencies/Gate → Settings/SecretStore →
 * Context/Policy (+FieldSchema/LayoutContract) → SchemaRegistry → UM/Amelia →
 * ManagedTemplates/Lifecycle (admin-only) → Elementor → Dashboard (admin-only). No
 * circular coupling: every arrow points at an ALREADY-created instance, the admin-only
 * block never runs on frontend requests (so Admin/Filesystem/API paths stay unloaded
 * there), and a missing Amelia merely leaves its slot null instead of blocking UM.
 * Lifecycle's activation-hook arming limitation (see its file header) remains documented:
 * closing it fully needs a one-line early require in the plugin MAIN file, which stays an
 * explicit owner decision outside this two-file card.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

use HAL\MemberProfiles\Elementor\Register;
use HAL\MemberProfiles\Integrations\Amelia;
use HAL\MemberProfiles\Integrations\OptionalIntegrations;
use HAL\MemberProfiles\Integrations\UltimateMember;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bootstrap {

	private static ?self $instance = null;

	private Dependencies $dependencies;
	private CompatibilityGate $compatibility_gate;
	private Settings $settings;
	private ?ProfileContext $profile_context = null;
	private ?AccountContext $account_context = null;
	private ?FieldSchema $field_schema = null;
	private ?Policy $policy = null;
	private ?LayoutContract $layout_contract = null;
	private ?SchemaRegistry $schema_registry = null;
	private ?UltimateMember $um_integration = null;
	private ?Amelia $amelia = null;
	private ?OptionalIntegrations $optional_integrations = null;
	private ?ManagedTemplates $managed_templates = null;
	private ?UmFormTemplateWatcher $um_form_template_watcher = null;

	private function __construct() {}

	/**
	 * Boots the plugin once, in module creation order.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->boot();
	}

	/**
	 * The single booted Bootstrap instance, so Widgets/Register can reach the same shared
	 * service instances Bootstrap itself built (Elementor constructs each Widget itself and
	 * cannot pass constructor-injected dependencies, so this is the one shared access point).
	 * Returns null before init() has run or when Ultimate Member was not detected.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Registers a late, self-correcting notice while HAL waits for Elementor's loader.
	 *
	 * @return void
	 */
	public static function watch_for_missing_elementor(): void {
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Dependencies.php';

		$dependencies = new Dependencies();

		self::notify_missing_dependency(
			__( 'Elementor', 'hal-member-profiles' ),
			array( $dependencies, 'has_elementor_widgets' )
		);
	}

	/**
	 * @return Dependencies
	 */
	public function get_dependencies(): Dependencies {
		return $this->dependencies;
	}

	/**
	 * The one runtime compatibility gate instance, created after Dependencies (it reads
	 * the environment versions from there) and shared with every service that needs a
	 * compatibility decision, so no service forms its own divergent verdict.
	 *
	 * @return CompatibilityGate
	 */
	public function get_compatibility_gate(): CompatibilityGate {
		return $this->compatibility_gate;
	}

	/**
	 * @return Settings
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * @return ProfileContext|null
	 */
	public function get_profile_context(): ?ProfileContext {
		return $this->profile_context;
	}

	/**
	 * @return AccountContext|null
	 */
	public function get_account_context(): ?AccountContext {
		return $this->account_context;
	}

	/**
	 * @return FieldSchema|null
	 */
	public function get_field_schema(): ?FieldSchema {
		return $this->field_schema;
	}

	/**
	 * @return Policy|null
	 */
	public function get_policy(): ?Policy {
		return $this->policy;
	}

	/**
	 * @return LayoutContract|null
	 */
	public function get_layout_contract(): ?LayoutContract {
		return $this->layout_contract;
	}

	/**
	 * D-14: the shared schema normalizer (card D-09), created once after Policy.
	 *
	 * @return SchemaRegistry|null
	 */
	public function get_schema_registry(): ?SchemaRegistry {
		return $this->schema_registry;
	}

	/**
	 * D-14: the admin-only managed-template service (card D-04); null on frontend and
	 * whenever boot stopped before the admin block.
	 *
	 * @return ManagedTemplates|null
	 */
	public function get_managed_templates(): ?ManagedTemplates {
		return $this->managed_templates;
	}

	/**
	 * Card F-10: the admin-only um_form template watcher; null on frontend and whenever
	 * boot stopped before the admin block.
	 *
	 * @return UmFormTemplateWatcher|null
	 */
	public function get_um_form_template_watcher(): ?UmFormTemplateWatcher {
		return $this->um_form_template_watcher;
	}

	/**
	 * @return UltimateMember|null
	 */
	public function get_um_integration(): ?UltimateMember {
		return $this->um_integration;
	}

	/**
	 * @return Amelia|null
	 */
	public function get_amelia(): ?Amelia {
		return $this->amelia;
	}

	/**
	 * @return OptionalIntegrations|null
	 */
	public function get_optional_integrations(): ?OptionalIntegrations {
		return $this->optional_integrations;
	}

	/**
	 * Creates each module in dependency order and wires shared service instances.
	 *
	 * @return void
	 */
	private function boot(): void {
		// Card F-14: the official textdomain load, hooked on init from boot(). boot()
		// itself runs at plugins_loaded (hal-member-profiles.php), which precedes init
		// (audit round-2 correction), so the load must be deferred to init here.
		// Registered on every boot path — including the missing-dependency early return
		// below — so translations resolve for the notice strings too.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// 1) Dependencies/Gate → 2) Settings/SecretStore — card D-14 order start.
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Dependencies.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/CompatibilityGate.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Settings.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/SecretStore.php';

		$this->dependencies = new Dependencies();

		// The gate is created after Dependencies (it consumes current_versions()) and
		// before Settings/Adapters, then the same instance is injected everywhere a
		// compatibility verdict is needed. Pure in-memory work only: no filesystem,
		// no external API calls happen here.
		$this->compatibility_gate = new CompatibilityGate( $this->dependencies->current_versions() );

		$this->settings = new Settings( $this->compatibility_gate );

		// SecretStore is a stateless static module (card D-07): loading it costs nothing
		// and performs no I/O; consumers reach it statically wherever needed.

		if ( ! $this->dependencies->has_um() ) {
			self::notify_missing_dependency( __( 'Ultimate Member', 'hal-member-profiles' ) );
			return;
		}

		// 3) Context/Policy (+ FieldSchema/LayoutContract).
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/ProfileContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/AccountContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/FieldSchema.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Policy.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/LayoutContract.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/ProfileOverview.php';

		// Card F-11: the read-only template contract validator — a pure reader with no
		// hooks and no side effects at load time, always loaded like LayoutContract so
		// the F-05 readiness card (and F-10) reach it via class_exists(); nothing else
		// is wired here.
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/TemplateContractValidator.php';

		$this->profile_context = new ProfileContext( $this->settings );
		$this->account_context = new AccountContext( $this->settings );
		$this->field_schema    = new FieldSchema();
		$this->policy          = new Policy( $this->field_schema );
		$this->layout_contract = new LayoutContract();

		// 4) SchemaRegistry (card D-09) — after Context/Policy, before UM/Amelia per card
		// D-14; it consumes only the already-created FieldSchema instance.
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/SchemaRegistry.php';

		$this->schema_registry = new SchemaRegistry( $this->field_schema );

		// 5) UM/Amelia.
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/UltimateMember.php';

		$this->um_integration = new UltimateMember( $this->profile_context, $this->account_context );

		// A missing Amelia leaves its slot null without blocking UM in any way
		// (acceptance: "Amelia absent does not block UM").
		if ( $this->dependencies->has_amelia() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/Amelia.php';

			$this->amelia = new Amelia( $this->settings );

			// Card C5b: the ONE production save hook for selected_services, registered
			// here (never in the constructor — S-01 stays intact), guarded against double
			// registration inside the registrar itself.
			Amelia::register_selected_services_save_hook();
		}

		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/OptionalIntegrations.php';

		$this->optional_integrations = new OptionalIntegrations( $this->dependencies );

		// Card C8: the ONE production consumer registration on the external Dashboard's
		// public link-contract filter, wired at boot exactly like the Amelia save hook
		// above; the guard against double registration lives inside the registrar itself.
		OptionalIntegrations::register_dashboard_link_consumer();

		// 6) ManagedTemplates/UmFormTemplateWatcher (card F-10)/Lifecycle — ADMIN-ONLY: on
		// frontend requests this entire block (and its filesystem-capable service) never
		// even loads (acceptance: "Frontend does not load unneeded Admin/Filesystem/API
		// paths").
		if ( is_admin() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/ManagedTemplates.php';

			$this->managed_templates = new ManagedTemplates();

			// Card F-10: the um_form template watcher — ADMIN-ONLY, constructor-injected
			// (F-11 pattern) with the SHARED gate/settings/managed-templates instances.
			// Bootstrap arms exactly two hooks (the official 4-arg updated_post_meta
			// signature and admin_notices); the watcher itself reads only and keeps zero
			// persistent state (warning-only, rule 0.5).
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/UmFormTemplateWatcher.php';

			$this->um_form_template_watcher = new UmFormTemplateWatcher(
				$this->compatibility_gate,
				$this->settings,
				$this->managed_templates
			);

			add_action( 'updated_post_meta', array( $this->um_form_template_watcher, 'on_updated_post_meta' ), 10, 4 );
			add_action( 'admin_notices', array( $this->um_form_template_watcher, 'render_admin_notice' ) );

			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Lifecycle.php';

			Lifecycle::register();
		}

		// 7) Elementor.
		if ( $this->dependencies->has_elementor_widgets() ) {
			add_action( 'elementor/init', array( $this, 'register_elementor' ) );
		} else {
			self::notify_missing_dependency(
				__( 'Elementor', 'hal-member-profiles' ),
				array( $this->dependencies, 'has_elementor_widgets' )
			);
		}

		// 8) Dashboard — ADMIN-ONLY, last per card D-14; it explains every module's state
		// through CompatibilityGate::describe()/capabilities().
		if ( is_admin() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/AdminDashboard.php';

			AdminDashboard::register();
		}
	}

	/**
	 * Registers the Elementor category, widgets, and dynamic tags after elementor/init.
	 *
	 * @return void
	 */
	public function register_elementor(): void {
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Elementor/Register.php';

		( new Register(
			$this->dependencies,
			$this->settings,
			$this->profile_context,
			$this->account_context,
			$this->field_schema,
			$this->policy,
			$this->layout_contract,
			$this->um_integration,
			$this->amelia,
			$this->optional_integrations
		) )->run();
	}

	/**
	 * Card F-14: loads the plugin translations from the plugin-local languages/
	 * directory on the init hook (registered from boot()).
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'hal-member-profiles',
			false,
			dirname( plugin_basename( HAL_MEMBER_PROFILES_FILE ) ) . '/languages'
		);
	}

	/**
	 * Shows a manage_options-only admin notice when an essential dependency is missing.
	 *
	 * @param string        $plugin_name Human-readable name of the missing plugin.
	 * @param callable|null $is_available Optional live dependency check before rendering.
	 * @return void
	 */
	private static function notify_missing_dependency( string $plugin_name, ?callable $is_available = null ): void {
		add_action(
			'admin_notices',
			static function () use ( $plugin_name, $is_available ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( null !== $is_available && call_user_func( $is_available ) ) {
					return;
				}

				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: missing plugin name. */
							__( 'HAL Member Profiles is inactive: %s is required and was not detected.', 'hal-member-profiles' ),
							$plugin_name
						)
					)
				);
			}
		);
	}
}
