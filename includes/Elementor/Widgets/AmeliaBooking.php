<?php
/**
 * Shows a booking CTA inside Elementor within a safe Profile context — never a direct
 * booking API call, never an untrusted parameter.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Widget_Base;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\Integrations\Amelia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AmeliaBooking extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_amelia_booking';
	}

	public function get_title(): string {
		return __( 'Amelia Booking (UM Profile)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-calendar';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'amelia', 'booking', 'appointment', 'profile' );
	}

	/**
	 * Shows a booking CTA for the resolved Profile owner: Amelia's own documented
	 * [ameliabooking employee=... service=...] shortcode, preselected only to the owner's
	 * admin-mapped, allowlisted employee/service ID — or the administrator's general
	 * booking URL when the owner is not a mapped, allowed employee. Never anything at all
	 * without a valid Amelia install, and never an availability assumption: Amelia's own
	 * form remains the final judge.
	 */
	protected function render(): void {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap || ! $bootstrap->get_dependencies()->has_amelia() ) {
			return;
		}

		$amelia = $bootstrap->get_amelia();

		if ( null === $amelia ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();

		if ( null === $profile_context ) {
			return;
		}

		$context = $profile_context->resolve();

		if ( null === $context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$target_user_id = (int) $context->target_user->ID;

		$output = $this->render_employee_shortcode( $amelia, $target_user_id );

		if ( '' === $output ) {
			$output = $this->render_general_booking_link( $amelia );
		}

		if ( '' === trim( $output ) ) {
			return;
		}

		// do_shortcode() (Amelia's own handler) / esc_url()+esc_html() below — not re-escaped here.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Amelia's own documented [ameliabooking employee=... service=...] shortcode
	 * parameters (see wpamelia.com/documentation/elementor-integration and Amelia's own
	 * shortcode reference), preselected only to this profile owner's admin-mapped employee
	 * ID and, when exactly one service is allowed — Amelia's basic [ameliabooking]
	 * shortcode does not accept multiple service IDs at once — that single service ID.
	 * Empty when the owner is not a validated, allowed employee.
	 *
	 * @param Amelia $amelia         Shared Amelia integration.
	 * @param int    $target_user_id Profile owner.
	 * @return string
	 */
	private function render_employee_shortcode( Amelia $amelia, int $target_user_id ): string {
		$employee_id = $amelia->get_employee_id( $target_user_id );

		if ( null === $employee_id ) {
			return '';
		}

		$allowed_service_ids = $amelia->get_allowed_service_ids( $target_user_id );

		if ( empty( $allowed_service_ids ) ) {
			return '';
		}

		$shortcode = '[ameliabooking employee=' . $employee_id;

		if ( 1 === count( $allowed_service_ids ) ) {
			$shortcode .= ' service=' . reset( $allowed_service_ids );
		}

		$shortcode .= ']';

		return do_shortcode( $shortcode );
	}

	/**
	 * The administrator-configured general booking link, as a plain anchor. Never a
	 * preselection, never an availability check — just a link to Amelia's own booking page.
	 *
	 * @param Amelia $amelia Shared Amelia integration.
	 * @return string
	 */
	private function render_general_booking_link( Amelia $amelia ): string {
		$url = $amelia->get_general_booking_url();

		if ( null === $url ) {
			return '';
		}

		return '<div class="hal-member-profiles hal-member-profiles__booking-link"><a href="' . esc_url( $url ) . '" rel="nofollow">' . esc_html__( 'Book an appointment', 'hal-member-profiles' ) . '</a></div>';
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never when Amelia itself is unavailable (that gate stays a silent return, per
	 * card 7.26: this widget must never operate without a valid Amelia install).
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Amelia Booking (UM Profile): no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
