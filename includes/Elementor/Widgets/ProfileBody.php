<?php
/**
 * The actual Profile tab content — including new UM fields and extensions — never a
 * private field listing that works around privacy.
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

final class ProfileBody extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_profile_body';
	}

	public function get_title(): string {
		return __( 'Profile Body (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-post-content';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'profile', 'body', 'tab', 'content' );
	}

	/**
	 * Outputs UM's own active tab/subtab content exactly as UM's own template conditions
	 * produce it — including UM's own um_profile_can_view_main decision for the main tab,
	 * which UM's own registered callback applies automatically the moment that hook fires
	 * (never reimplemented here), and any tab UM or an active extension adds. Registers the
	 * profile_body LayoutContract marker whenever this actually ran inside valid Profile
	 * Context, even when the active tab's real content is legitimately empty — that is a
	 * documented empty state, not an incomplete Layout.
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

		$output = $um_integration->render_profile_content();

		// Valid Profile Context and a real render attempt happened; register even if this
		// particular tab's legitimate content turns out to be empty.
		$layout_contract->register( LayoutContract::MARKER_PROFILE_BODY );

		if ( '' === trim( $output ) ) {
			return;
		}

		// UM's own already-rendered, already-escaped native output — not regenerated here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of Ultimate Member's own active tab content.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Profile Body (UM): no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
