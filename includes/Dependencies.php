<?php
/**
 * The single compatibility and feature-detection gate for HAL Member Profiles.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dependencies {

	/**
	 * Whether Ultimate Member is active and its main accessor is available.
	 *
	 * @return bool
	 */
	public function has_um(): bool {
		return function_exists( 'UM' );
	}

	/**
	 * Whether Elementor (free) is active with the base widget class our widgets extend.
	 *
	 * @return bool
	 */
	public function has_elementor_widgets(): bool {
		return class_exists( '\Elementor\Plugin' ) && class_exists( '\Elementor\Widget_Base' );
	}

	/**
	 * Whether Elementor Pro's Dynamic Tags module is available for normal-element Tags.
	 *
	 * @return bool
	 */
	public function has_elementor_pro_dynamic_tags(): bool {
		return $this->has_elementor_pro() && class_exists( '\ElementorPro\Modules\DynamicTags\Module' );
	}

	/**
	 * Whether Elementor Pro's Query Control module is available for Loop/Grid widgets.
	 *
	 * @return bool
	 */
	public function has_elementor_pro_queries(): bool {
		return $this->has_elementor_pro() && class_exists( '\ElementorPro\Modules\QueryControl\Module' );
	}

	/**
	 * Whether Amelia is active.
	 *
	 * Confirm the exact class signature against the installed Amelia build in
	 * docs/compatibility-matrix.md before relying on this in production.
	 *
	 * @return bool
	 */
	public function has_amelia(): bool {
		return defined( 'AMELIA_VERSION' );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public function has_woocommerce(): bool {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * Whether WPML is active.
	 *
	 * @return bool
	 */
	public function has_wpml(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * Whether Elementor Pro itself is active, checked before trusting a specific Pro module.
	 *
	 * @return bool
	 */
	private function has_elementor_pro(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) && class_exists( '\ElementorPro\Plugin' );
	}
}
