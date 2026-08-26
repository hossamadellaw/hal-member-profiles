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
			$this->notify_missing_dependency( __( 'Ultimate Member', 'hal-member-profiles' ) );
			return;
		}

		// 3) Context/Policy (+ FieldSchema/LayoutContract).
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/ProfileContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/AccountContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/FieldSchema.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Policy.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/LayoutContract.php';

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
		// (acceptance: "Amelia غائبة لا تمنع UM").
		if ( $this->dependencies->has_amelia() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/Amelia.php';

			$this->amelia = new Amelia( $this->settings );
		}

		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/OptionalIntegrations.php';

		$this->optional_integrations = new OptionalIntegrations( $this->dependencies );

		// 6) ManagedTemplates/Lifecycle — ADMIN-ONLY: on frontend requests this entire
		// block (and its filesystem-capable service) never even loads (acceptance:
		// "Frontend لا يحمل Admin/Filesystem/API paths غير اللازمة").
		if ( is_admin() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/ManagedTemplates.php';

			$this->managed_templates = new ManagedTemplates();

			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Lifecycle.php';

			Lifecycle::register();
		}

		// 7) Elementor.
		if ( $this->dependencies->has_elementor_widgets() ) {
			add_action( 'elementor/init', array( $this, 'register_elementor' ) );
		} else {
			$this->notify_missing_dependency( __( 'Elementor', 'hal-member-profiles' ) );
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
	 * Shows a manage_options-only admin notice when an essential dependency is missing.
	 *
	 * @param string $plugin_name Human-readable name of the missing plugin.
	 * @return void
	 */
	private function notify_missing_dependency( string $plugin_name ): void {
		add_action(
			'admin_notices',
			static function () use ( $plugin_name ) {
				if ( ! current_user_can( 'manage_options' ) ) {
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
