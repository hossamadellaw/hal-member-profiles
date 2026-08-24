<?php
/**
 * Registers the "HAL Member Profiles" Elementor category, its Widgets, and — Elementor Pro
 * only — its Dynamic Tags.
 *
 * @package HAL\MemberProfiles\Elementor
 */

namespace HAL\MemberProfiles\Elementor;

use HAL\MemberProfiles\AccountContext;
use HAL\MemberProfiles\Dependencies;
use HAL\MemberProfiles\FieldSchema;
use HAL\MemberProfiles\Integrations\Amelia;
use HAL\MemberProfiles\Integrations\OptionalIntegrations;
use HAL\MemberProfiles\Integrations\UltimateMember;
use HAL\MemberProfiles\LayoutContract;
use HAL\MemberProfiles\Policy;
use HAL\MemberProfiles\ProfileContext;
use HAL\MemberProfiles\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Register {

	const CATEGORY = 'hal-member-profiles';

	private Dependencies $dependencies;
	private Settings $settings;
	private ?ProfileContext $profile_context;
	private ?AccountContext $account_context;
	private ?FieldSchema $field_schema;
	private ?Policy $policy;
	private ?LayoutContract $layout_contract;
	private ?UltimateMember $um_integration;
	private ?Amelia $amelia;
	private ?OptionalIntegrations $optional_integrations;

	/**
	 * Receives the same shared service instances Bootstrap already built. Only $dependencies
	 * is used by this class's own logic (the has_elementor_pro_dynamic_tags() gate); the
	 * rest are accepted to match Bootstrap's established construction contract and stored
	 * for any future registration logic that needs them.
	 */
	public function __construct(
		Dependencies $dependencies,
		Settings $settings,
		?ProfileContext $profile_context,
		?AccountContext $account_context,
		?FieldSchema $field_schema,
		?Policy $policy,
		?LayoutContract $layout_contract,
		?UltimateMember $um_integration,
		?Amelia $amelia,
		?OptionalIntegrations $optional_integrations
	) {
		$this->dependencies          = $dependencies;
		$this->settings               = $settings;
		$this->profile_context        = $profile_context;
		$this->account_context        = $account_context;
		$this->field_schema           = $field_schema;
		$this->policy                 = $policy;
		$this->layout_contract        = $layout_contract;
		$this->um_integration         = $um_integration;
		$this->amelia                 = $amelia;
		$this->optional_integrations  = $optional_integrations;
	}

	/**
	 * Wires every registration hook. Called once from Bootstrap::register_elementor(), which
	 * is itself only hooked to elementor/init when Dependencies::has_elementor_widgets() is
	 * true — so no redundant Elementor-presence check happens here.
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		if ( $this->dependencies->has_elementor_pro_dynamic_tags() ) {
			add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'notify_dynamic_layout_unavailable' ) );
		}

		if ( $this->dependencies->has_elementor_pro_queries() ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/Elementor/ProfileQueries.php';

			ProfileQueries::register();
		}

		add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'enqueue_editor_styles' ) );
	}

	/**
	 * @param \Elementor\Elements_Manager $elements_manager Elementor's elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'HAL Member Profiles', 'hal-member-profiles' ),
				'icon'  => 'eicon-user-circle-o',
			)
		);
	}

	/**
	 * Registers every compatible Widget — works with or without Elementor Pro. A Widget that
	 * itself depends on an optional integration (Amelia/WooCommerce) is only ever listed here
	 * once its own file exists and that dependency is confirmed available.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		foreach ( $this->widget_classes() as $class_name => $relative_path ) {
			require_once HAL_MEMBER_PROFILES_DIR . $relative_path;

			$widgets_manager->register( new $class_name() );
		}
	}

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags_manager Elementor's tags manager.
	 * @return void
	 */
	public function register_dynamic_tags( $dynamic_tags_manager ): void {
		require_once HAL_MEMBER_PROFILES_DIR . 'includes/Elementor/DynamicTags.php';

		DynamicTags::register_all( $dynamic_tags_manager );
	}

	/**
	 * Enqueues the editor-only placeholder/indicator stylesheet (assets/editor.css). Fires
	 * only on elementor/editor/after_enqueue_styles, so this never loads on the live
	 * frontend or on any screen outside the Elementor editor itself.
	 *
	 * @return void
	 */
	public function enqueue_editor_styles(): void {
		wp_enqueue_style(
			'hal-member-profiles-editor',
			HAL_MEMBER_PROFILES_URL . 'assets/editor.css',
			array(),
			HAL_MEMBER_PROFILES_VERSION
		);
	}

	/**
	 * @return void
	 */
	public function notify_dynamic_layout_unavailable(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'HAL Member Profiles: Elementor Pro was not detected, so Full Dynamic Layout (Dynamic Tags) is unavailable. Compatible Widgets and the Ultimate Member native fallback still work.', 'hal-member-profiles' )
		);
	}

	/**
	 * Widget class (fully qualified, leading backslash) => file path map, relative to the
	 * plugin root. AmeliaBooking is only listed once its own gate (has_amelia()) is true —
	 * its file now exists, but it must never be required/registered while Amelia itself is
	 * not detected, per this card's own prohibition on registering a Widget that depends on
	 * an absent optional integration.
	 *
	 * @return array<string,string>
	 */
	private function widget_classes(): array {
		$namespace = '\\HAL\\MemberProfiles\\Elementor\\Widgets\\';

		$widgets = array(
			$namespace . 'NativeHeader'              => 'includes/Elementor/Widgets/NativeHeader.php',
			$namespace . 'ProfileHeaderCompatibility' => 'includes/Elementor/Widgets/ProfileHeaderCompatibility.php',
			$namespace . 'ProfileActions'             => 'includes/Elementor/Widgets/ProfileActions.php',
			$namespace . 'ProfileField'               => 'includes/Elementor/Widgets/ProfileField.php',
			$namespace . 'ProfileNavigation'          => 'includes/Elementor/Widgets/ProfileNavigation.php',
			$namespace . 'ProfileBody'                => 'includes/Elementor/Widgets/ProfileBody.php',
			$namespace . 'AccountField'               => 'includes/Elementor/Widgets/AccountField.php',
			$namespace . 'AccountNavigation'          => 'includes/Elementor/Widgets/AccountNavigation.php',
			$namespace . 'AccountBody'                => 'includes/Elementor/Widgets/AccountBody.php',
		);

		if ( $this->dependencies->has_amelia() ) {
			$widgets[ $namespace . 'AmeliaBooking' ] = 'includes/Elementor/Widgets/AmeliaBooking.php';
		}

		return $widgets;
	}
}
