<?php
/**
 * Inserts an Elementor Library Template inside the UM Account template only — never both
 * the Elementor path and the native pipeline at once, and never an empty Account page.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountLayoutAdapter {

	/**
	 * The single entry point the Child Theme account template calls. Renders the configured
	 * Elementor Library Template when it is valid, complete, and permitted for this render;
	 * otherwise falls back to $native_pipeline_callback exactly once — inside the correct
	 * wrappers, with the original template args forwarded. Never both paths at once.
	 *
	 * F-14: the native callback receives the ORIGINAL $args (never stripped or replaced),
	 * so UM's own full Account pipeline keeps its complete hook/save context.
	 *
	 * @param callable $native_pipeline_callback Renders (echoes) the complete native UM
	 *                                            account pipeline when called with $args.
	 * @param array    $args Original args the UM account template received.
	 * @return void
	 */
	public static function render_or_fallback( callable $native_pipeline_callback, array $args = array() ): void {
		$output = self::render_elementor_library();

		if ( null !== $output ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own rendered, already-escaped output.
			return;
		}

		call_user_func( $native_pipeline_callback, $args );
	}

	/**
	 * Attempts the Elementor Library path using AccountContext only — never ProfileContext.
	 * Returns the rendered HTML only when every guard, the template itself, and the
	 * LayoutContract all succeed; otherwise null, so the caller falls back to the native
	 * Account pipeline instead of an empty page.
	 *
	 * @return string|null
	 */
	private static function render_elementor_library(): ?string {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return null;
		}

		if ( ! $bootstrap->get_dependencies()->has_elementor_widgets() ) {
			return null;
		}

		// F-14 executive gate: without a compatibility Pass for THIS exact composition
		// (account capability), the Elementor route never starts — the caller falls back
		// to UM's own full Account pipeline inside its original wrappers.
		$compatibility_gate = $bootstrap->get_compatibility_gate();

		if ( null === $compatibility_gate || ! $compatibility_gate->effective_passes( CompatibilityGate::CAP_ACCOUNT ) ) {
			return null;
		}

		$settings = $bootstrap->get_settings();

		if ( Settings::LAYOUT_MODE_PUBLIC_LAYOUT !== $settings->get_account_layout_mode() ) {
			return null;
		}

		$account_context = $bootstrap->get_account_context();

		if ( null === $account_context || null === $account_context->resolve() ) {
			return null;
		}

		$template_id = $settings->get_account_library_template_id();

		if ( null === $template_id ) {
			return null;
		}

		$layout_contract = $bootstrap->get_layout_contract();

		if ( null === $layout_contract ) {
			return null;
		}

		$layout_contract->reset();

		$output = self::render_template_with_saved_state( $template_id );

		if ( null === $output || '' === trim( $output ) ) {
			return null;
		}

		if ( ! $layout_contract->is_account_contract_valid() ) {
			return null;
		}

		return $output;
	}

	/**
	 * Renders one Elementor Library Template through Elementor's own installed
	 * get_builder_content_for_display() lifecycle, saving and restoring $post/$wp_query
	 * around the call (Elementor's own recursion guard reads get_the_ID(), which depends on
	 * this global state), and never letting an exception escape to the caller.
	 *
	 * @param int $template_id Published, Elementor-built template ID.
	 * @return string|null
	 */
	private static function render_template_with_saved_state( int $template_id ): ?string {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		global $post, $wp_query;

		$saved_post     = $post;
		$saved_wp_query = $wp_query;
		$content         = null;

		try {
			$content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id, true );
		} catch ( \Throwable $e ) {
			$content = null;
		}

		$post     = $saved_post;
		$wp_query = $saved_wp_query;

		if ( $post instanceof \WP_Post ) {
			setup_postdata( $post );
		}

		return is_string( $content ) ? $content : null;
	}
}
