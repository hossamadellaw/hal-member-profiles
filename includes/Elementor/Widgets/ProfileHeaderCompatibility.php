<?php
/**
 * Enables a fully Custom Header built from ordinary Elementor elements, without ever
 * claiming to be Ultimate Member's own default header.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Widget_Base;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\LayoutContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileHeaderCompatibility extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_profile_header_compatibility';
	}

	public function get_title(): string {
		return __( 'Header Compatibility (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-puzzle';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'profile', 'header', 'custom header', 'compatibility' );
	}

	/**
	 * Outputs only the general UM compatibility/action points a specific, tested extension
	 * adapter has explicitly registered for the header area — never UM's own raw native
	 * header HTML (that stays NativeHeader's job, and only one of the two ever registers
	 * per page), and never a guessed reimplementation of an untested extension's internal
	 * hooks or markup. Registers the custom_header LayoutContract marker only after that
	 * output is actually non-empty.
	 */
	protected function render(): void {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();
		$layout_contract  = $bootstrap->get_layout_contract();

		if ( null === $profile_context || null === $layout_contract ) {
			return;
		}

		// F-11 hard gate: without an executive compatibility Pass for THIS exact
		// composition, NO registered extension callback runs at all, no custom_header
		// marker registers, and the LayoutContract/native pipeline takes over with a
		// FULL native header — never a partial one. Extension eligibility itself is
		// additionally governed by docs/compatibility-matrix.md §2 sign-off.
		$compatibility_gate = $bootstrap->get_compatibility_gate();

		if ( null === $compatibility_gate || ! $compatibility_gate->passes( CompatibilityGate::CAP_PROFILE ) ) {
			return;
		}

		$context = $profile_context->resolve();

		if ( null === $context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$output = $this->render_registered_compatibility_points( $context );

		if ( '' === trim( $output ) ) {
			return;
		}

		$layout_contract->register( LayoutContract::MARKER_CUSTOM_HEADER );

		// Registered extension adapters are responsible for escaping their own output.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Captures output only from extension adapters explicitly registered via the
	 * hal_member_profiles_header_compatibility_points filter, AFTER the composition-level
	 * CompatibilityGate Pass in render() — and each extension itself must additionally be
	 * confirmed and tested against docs/compatibility-matrix.md §2 before it is ever
	 * registered. A registered point that throws is discarded on its own — it never breaks
	 * the other registered points or this widget.
	 *
	 * Empty by default in this release: no extension adapter has been registered and
	 * tested yet (and the gate's approved registry is empty until matrix QA), so this
	 * widget currently produces no output here, and LayoutContract correctly falls back
	 * to the Native profile pipeline instead.
	 *
	 * @param object $context Resolved Profile context from ProfileContext::resolve().
	 * @return string
	 */
	private function render_registered_compatibility_points( object $context ): string {
		$points = apply_filters( 'hal_member_profiles_header_compatibility_points', array(), $context );

		if ( empty( $points ) || ! is_array( $points ) ) {
			return '';
		}

		$output = '';

		foreach ( $points as $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			ob_start();

			try {
				call_user_func( $callback, $context );
			} catch ( \Throwable $e ) {
				ob_end_clean();
				continue;
			}

			$output .= (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of a registered, tested extension adapter's own output.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Header Compatibility (UM): no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
