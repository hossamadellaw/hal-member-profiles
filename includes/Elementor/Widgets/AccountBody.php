<?php
/**
 * The actual Account tab content — including validation and nonces — for the Account
 * owner only, never a manually rebuilt Form.
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

final class AccountBody extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_account_body';
	}

	public function get_title(): string {
		return __( 'Account Body (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'account', 'body', 'form', 'tab' );
	}

	/**
	 * Outputs UM's own active Account tab body/Form exactly as UM renders it — including
	 * validation, nonces, and conditional logic, never rebuilt — for the Account owner
	 * only (AccountContext never resolves another member's account). Registers the
	 * account_body LayoutContract marker whenever this actually ran inside valid Account
	 * Context, even when the active tab's real content is legitimately empty — that is a
	 * documented empty state, not an incomplete Layout.
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

		$output = $um_integration->render_account_body();

		// Valid Account Context and a real render attempt happened; register even if this
		// particular tab's legitimate content turns out to be empty.
		$layout_contract->register( LayoutContract::MARKER_ACCOUNT_BODY );

		if ( '' === trim( $output ) ) {
			return;
		}

		// UM's own already-rendered, already-escaped native output (its own validation,
		// nonces, and conditional logic included) — not regenerated here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the safe, translatable "no account context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of Ultimate Member's own active tab Form output.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Account Body (UM): no account context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
