<?php
/**
 * Creates each module in dependency order and shares the service instances across HAL
 * Member Profiles.
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
	private Settings $settings;
	private ?ProfileContext $profile_context = null;
	private ?AccountContext $account_context = null;
	private ?FieldSchema $field_schema = null;
	private ?Policy $policy = null;
	private ?LayoutContract $layout_contract = null;
	private ?UltimateMember $um_integration = null;
	private ?Amelia $amelia = null;
	private ?OptionalIntegrations $optional_integrations = null;

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
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Dependencies.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Settings.php';

		$this->dependencies = new Dependencies();
		$this->settings     = new Settings();

		if ( ! $this->dependencies->has_um() ) {
			$this->notify_missing_dependency( __( 'Ultimate Member', 'hal-member-profiles' ) );
			return;
		}

		require_once HAL_MEMBER_PROFILES_DIR . 'includes/ProfileContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/AccountContext.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/FieldSchema.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Policy.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/LayoutContract.php';
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/UltimateMember.php';

		$this->profile_context = new ProfileContext( $this->settings );
		$this->account_context = new AccountContext( $this->settings );
		$this->field_schema    = new FieldSchema();
		$this->policy          = new Policy( $this->field_schema );
		$this->layout_contract = new LayoutContract();
		$this->um_integration  = new UltimateMember( $this->profile_context, $this->account_context );

		if ( $this->dependencies->has_amelia() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/Amelia.php';

			$this->amelia = new Amelia( $this->settings );
		}

		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Integrations/OptionalIntegrations.php';

		$this->optional_integrations = new OptionalIntegrations( $this->dependencies );

		if ( $this->dependencies->has_elementor_widgets() ) {
			add_action( 'elementor/init', array( $this, 'register_elementor' ) );
		} else {
			$this->notify_missing_dependency( __( 'Elementor', 'hal-member-profiles' ) );
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
