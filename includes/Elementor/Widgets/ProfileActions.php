<?php
/**
 * Ultimate Member's own authorized profile action buttons (Edit Profile, and any active
 * extension's Follow/Message controls) — never invented URLs or permissions.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Widget_Base;
use HAL\MemberProfiles\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileActions extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_profile_actions';
	}

	public function get_title(): string {
		return __( 'Profile Actions (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-button';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'profile', 'actions', 'edit', 'follow', 'message' );
	}

	/**
	 * Outputs only UM's own authorized profile actions via the shared UltimateMember
	 * integration, and hides itself entirely when UM has no action to offer or does not
	 * permit one for this viewer. No LayoutContract marker — Profile Actions is not part
	 * of the required Profile contract (header + navigation + body only).
	 */
	protected function render(): void {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();
		$um_integration   = $bootstrap->get_um_integration();

		if ( null === $profile_context || null === $um_integration ) {
			return;
		}

		$resolved_context = $profile_context->resolve();

		if ( null === $resolved_context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$output = $um_integration->render_profile_actions();

		if ( '' === trim( $output ) ) {
			return;
		}

		// UM's own already-rendered, already-escaped native output — not regenerated here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of Ultimate Member's own native action buttons.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Profile Actions (UM): no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
