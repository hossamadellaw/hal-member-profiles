<?php
/**
 * Optional enhancements that never block Profile or Account rendering.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\Dependencies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OptionalIntegrations {

	private Dependencies $dependencies;

	public function __construct( Dependencies $dependencies ) {
		$this->dependencies = $dependencies;
	}

	/**
	 * Product IDs owned by a profile, under a single, explicit, filterable ownership
	 * contract. WordPress's own post_author is only the default fallback here, never an
	 * assumed universal rule — sites using a different ownership model (e.g. a vendor
	 * meta key) must supply the hal_member_profiles_owned_product_ids filter instead.
	 * Returns an empty array whenever WooCommerce is absent.
	 *
	 * @param int $target_user_id Profile owner.
	 * @param int $limit          Maximum number of product IDs to return.
	 * @return int[]
	 */
	public function get_owned_product_ids( int $target_user_id, int $limit = 12 ): array {
		if ( $target_user_id <= 0 || ! $this->dependencies->has_woocommerce() ) {
			return array();
		}

		/**
		 * Filters the WooCommerce product-ownership contract this bridge uses.
		 *
		 * Return an array of product post IDs owned by $target_user_id to fully replace
		 * the default post_author lookup below. Leave the passed value (null) untouched
		 * to keep the default.
		 *
		 * @param int[]|null $product_ids    Pre-resolved IDs, or null to use the default.
		 * @param int        $target_user_id Profile owner.
		 * @param int        $limit          Maximum number of product IDs requested.
		 */
		$product_ids = apply_filters( 'hal_member_profiles_owned_product_ids', null, $target_user_id, $limit );

		if ( is_array( $product_ids ) ) {
			return array_map( 'absint', $product_ids );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'author'         => $target_user_id,
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Translates an object ID via WPML's own public wpml_object_id filter, exactly once.
	 * Returns the original ID unchanged whenever WPML is absent, so this never needs to be
	 * (and must never be) applied a second time by any other file in this plugin.
	 *
	 * @param int    $object_id Original object ID.
	 * @param string $type      WPML element type, e.g. 'post_product', 'post_page'.
	 * @return int
	 */
	public function translate_object_id( int $object_id, string $type ): int {
		if ( $object_id <= 0 || ! $this->dependencies->has_wpml() ) {
			return $object_id;
		}

		$translated_id = apply_filters( 'wpml_object_id', $object_id, $type, true );

		return is_numeric( $translated_id ) ? (int) $translated_id : $object_id;
	}

	/**
	 * Reads one specific, named ACF field — never a generic raw-meta fallback. No field
	 * name is currently required anywhere in this plugin, so this intentionally returns
	 * null until a specific, documented field name is actually needed by a future card.
	 *
	 * @param int    $target_user_id Owner the field belongs to.
	 * @param string $field_name     A specific, documented ACF field name.
	 * @return mixed|null
	 */
	public function get_named_acf_field( int $target_user_id, string $field_name ) {
		unset( $target_user_id, $field_name );

		return null;
	}
}
