<?php
/**
 * Feature detection and safe version reporting for HAL Member Profiles.
 *
 * Answers "what is present, and which version is it?" only. Whether a detected
 * composition is approved for a capability is decided exclusively by
 * CompatibilityGate, which consumes current_versions() from here; no layout or
 * compatibility decisions are made inside this class.
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
	 * Current WordPress core version, or null when it cannot be determined.
	 *
	 * @return string|null
	 */
	public function wp_version(): ?string {
		if ( ! function_exists( 'get_bloginfo' ) ) {
			return null;
		}

		return self::normalize_version_value( get_bloginfo( 'version' ) );
	}

	/**
	 * Current PHP version. Always reportable on any supported runtime.
	 *
	 * @return string|null
	 */
	public function php_version(): ?string {
		return self::normalize_version_value( PHP_VERSION );
	}

	/**
	 * Ultimate Member version, or null when UM is absent or reports no version.
	 *
	 * Prefers the documented UM_VERSION constant and falls back to the accessor's
	 * version property, so an unknown internal layout still fails closed instead of fataling.
	 *
	 * @return string|null
	 */
	public function um_version(): ?string {
		if ( ! $this->has_um() ) {
			return null;
		}

		if ( defined( 'UM_VERSION' ) ) {
			return self::normalize_version_value( UM_VERSION );
		}

		$um = UM();

		if ( is_object( $um ) && isset( $um->version ) && is_scalar( $um->version ) ) {
			return self::normalize_version_value( $um->version );
		}

		return null;
	}

	/**
	 * Elementor (free) version, or null when Elementor is absent or reports none.
	 *
	 * @return string|null
	 */
	public function elementor_version(): ?string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
			return null;
		}

		return self::normalize_version_value( ELEMENTOR_VERSION );
	}

	/**
	 * Elementor Pro version, or null when Pro is not fully active.
	 *
	 * Requires the same two signals has_elementor_pro() requires, so a half-loaded Pro
	 * never reports a version that could pass a compatibility tuple.
	 *
	 * @return string|null
	 */
	public function elementor_pro_version(): ?string {
		if ( ! $this->has_elementor_pro() || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return null;
		}

		return self::normalize_version_value( ELEMENTOR_PRO_VERSION );
	}

	/**
	 * Amelia version, or null when Amelia is absent.
	 *
	 * @return string|null
	 */
	public function amelia_version(): ?string {
		if ( ! $this->has_amelia() ) {
			return null;
		}

		return self::normalize_version_value( AMELIA_VERSION );
	}

	/**
	 * Active theme version, or null when themes API is unavailable or unhelpful.
	 *
	 * @return string|null
	 */
	public function active_theme_version(): ?string {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return null;
		}

		$theme = wp_get_theme();

		if ( ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			return null;
		}

		return self::normalize_version_value( $theme->get( 'Version' ) );
	}

	/**
	 * Current environment versions keyed by the exact component slugs
	 * CompatibilityGate tuples use (wp, php, um, elementor, elementor_pro, amelia,
	 * theme). Components whose version cannot be determined are omitted entirely, so a
	 * gate tuple referencing them fails closed rather than guessing.
	 *
	 * @return array<string, string>
	 */
	public function current_versions(): array {
		$versions = array(
			'wp'            => $this->wp_version(),
			'php'           => $this->php_version(),
			'um'            => $this->um_version(),
			'elementor'     => $this->elementor_version(),
			'elementor_pro' => $this->elementor_pro_version(),
			'amelia'        => $this->amelia_version(),
			'theme'         => $this->active_theme_version(),
		);

		$reported = array();

		foreach ( $versions as $key => $value ) {
			if ( null !== $value ) {
				$reported[ $key ] = $value;
			}
		}

		return $reported;
	}

	/**
	 * Normalizes a raw version value: scalars are trimmed, empty results become null,
	 * everything non-scalar becomes null.
	 *
	 * @param mixed $value Raw version value from any source.
	 * @return string|null
	 */
	private static function normalize_version_value( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
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
