<?php
/**
 * Inserts an Elementor Library Template inside the UM Profile template only — never both
 * the Elementor path and the native pipeline at once.
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

final class ProfileLayoutAdapter {

	/**
	 * The single entry point the Child Theme profile template calls. Renders the configured
	 * Elementor Library Template when it is valid, complete, and permitted for this render;
	 * otherwise falls back to $native_pipeline_callback exactly once. Never both.
	 *
	 * @param callable $native_pipeline_callback Renders (echoes) the complete native UM
	 *                                            profile pipeline when called with $args.
	 * @param array    $args    Raw $args the UM profile template received.
	 * @param int      $form_id UM form ID the profile template received.
	 * @param string   $mode    UM profile mode the profile template received.
	 * @return void
	 */
	public static function render_or_fallback( callable $native_pipeline_callback, array $args = array(), int $form_id = 0, string $mode = '' ): void {
		$output = self::render_elementor_library( $args, $form_id, $mode );

		if ( null !== $output ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own rendered, already-escaped output.
			return;
		}

		call_user_func( $native_pipeline_callback, $args );
	}

	/**
	 * Attempts the Elementor Library path. Returns the rendered HTML only when every guard,
	 * the template itself, and the LayoutContract all succeed; otherwise null, so the caller
	 * falls back to the native pipeline instead of a partial page.
	 *
	 * @param array  $args    UM profile template $args.
	 * @param int    $form_id UM form ID.
	 * @param string $mode    UM profile mode.
	 * @return string|null
	 */
	private static function render_elementor_library( array $args, int $form_id, string $mode ): ?string {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return null;
		}

		if ( ! $bootstrap->get_dependencies()->has_elementor_widgets() ) {
			return null;
		}

		// F-13: executive compatibility gate BEFORE anything is rendered or buffered.
		// Without a Pass for this exact composition the Elementor route never starts and
		// the caller falls back to the complete native pipeline.
		$compatibility_gate = $bootstrap->get_compatibility_gate();

		if ( null === $compatibility_gate || ! $compatibility_gate->effective_passes( CompatibilityGate::CAP_PROFILE ) ) {
			return null;
		}

		$settings = $bootstrap->get_settings();

		if ( Settings::LAYOUT_MODE_PUBLIC_LAYOUT !== $settings->get_profile_layout_mode() ) {
			return null;
		}

		$profile_context = $bootstrap->get_profile_context();

		if ( null === $profile_context || null === $profile_context->resolve( $args, $form_id, $mode ) ) {
			return null;
		}

		$template_id = $settings->get_profile_library_template_id();

		if ( null === $template_id ) {
			return null;
		}

		$layout_contract = $bootstrap->get_layout_contract();

		if ( null === $layout_contract ) {
			return null;
		}

		$layout_contract->reset();

		// F-13: open the verified Profile scope so every Widget/Dynamic Tag inside the
		// Elementor content that calls resolve() bare inherits THIS render's verified
		// form/mode — and ALWAYS close it (finally), on success, on failure, and on any
		// exception, so nothing leaks outward and no global state is left mutated.
		try {
			if ( ! $profile_context->enter_scope( $args, $form_id, $mode ) ) {
				return null;
			}

			$output = self::render_template_with_saved_state( $template_id );
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			$profile_context->exit_scope();
		}

		if ( null === $output || '' === trim( $output ) ) {
			return null;
		}

		if ( ! $layout_contract->is_profile_contract_valid() ) {
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
