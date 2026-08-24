<?php
/**
 * Ultimate Member's real Account navigation tabs.
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

final class AccountNavigation extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_account_navigation';
	}

	public function get_title(): string {
		return __( 'Account Navigation (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-nav-menu';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'account', 'navigation', 'tabs' );
	}

	/**
	 * Outputs UM's own Account tab navigation exactly as UM resolves it — the same tabs
	 * and the same links for every active extension, in UM's own stored order, with no
	 * independent fixed ordering imposed here. A tab UM itself does not include in its
	 * resolved tabs (disabled/hidden) is simply never iterated, never fabricated. Registers
	 * the account_navigation LayoutContract marker only after that output is actually
	 * non-empty.
	 */
	protected function render(): void {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return;
		}

		$account_context = $bootstrap->get_account_context();
		$um_integration   = $bootstrap->get_um_integration();
		$layout_contract  = $bootstrap->get_layout_contract();

		if ( null === $account_context || null === $um_integration || null === $layout_contract ) {
			return;
		}

		$resolved_context = $account_context->resolve();

		if ( null === $resolved_context ) {
			if ( $account_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$output = $um_integration->render_account_tabs();

		if ( '' === trim( $output ) ) {
			return;
		}

		$layout_contract->register( LayoutContract::MARKER_ACCOUNT_NAVIGATION );

		// UM's own already-rendered, already-escaped native output — not regenerated here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the safe, translatable "no account context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of Ultimate Member's own native account tab navigation.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Account Navigation (UM): no account context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
