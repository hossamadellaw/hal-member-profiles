<?php
/**
 * Outputs Ultimate Member's real Profile header — the fallback and the conservative design
 * option, never a redesigned reimplementation of it.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Widget_Base;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\LayoutContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NativeHeader extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_native_header';
	}

	public function get_title(): string {
		return __( 'Native Header (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-single-post';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'profile', 'header', 'cover' );
	}

	/**
	 * Outputs UM's own before-header, header cover area, and header hooks — in Profile
	 * Context only — and registers the native_header LayoutContract marker only after
	 * that output is actually non-empty.
	 */
	protected function render(): void {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();
		$um_integration   = $bootstrap->get_um_integration();
		$layout_contract  = $bootstrap->get_layout_contract();

		if ( null === $profile_context || null === $um_integration || null === $layout_contract ) {
			return;
		}

		$resolved_context = $profile_context->resolve();

		if ( null === $resolved_context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$output = $um_integration->render_profile_before_header()
			. $um_integration->render_profile_header_cover_area()
			. $um_integration->render_profile_header();

		if ( '' === trim( $output ) ) {
			return;
		}

		$layout_contract->register( LayoutContract::MARKER_NATIVE_HEADER );

		// UM's own already-rendered, already-escaped native output — not regenerated here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of Ultimate Member's own native header output.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Native Header (UM): no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
