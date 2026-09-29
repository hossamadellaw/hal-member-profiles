<?php
/**
 * The logged-in Profile Overview (card C3 / F-04): the profile owner's own summary space.
 *
 * Contract (GOV_TARGET C3): renders for the resolved profile OWNER only — never for
 * visitors, other users, or unauthenticated requests. It consumes the C1-owned sources
 * (ProfileContext persona resolution, FieldSchema catalog, Policy typed values) and
 * never reads user meta directly or second-guesses UM privacy. Photo is a safe slot
 * (link only, no write, no delegation — C4 owns the actual integration). Services stay
 * a closed placeholder section until C5/C5b. No Elementor template selection/render here
 * (that is C7's public-profile surface) and no HAL Frontend Dashboard function.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileOverview {

	/**
	 * Builds the owner-only Overview HTML. Returns '' whenever the current viewer is not
	 * the resolved profile owner, the context is unverifiable, or any required component
	 * is absent (safe absence — the caller falls back to UM's native profile silently).
	 *
	 * The template-side caller owns the verified $form_id/$mode the UM profile template
	 * received; passing them explicitly here feeds the same verified form through
	 * ProfileContext::resolve() and Policy — never a guessed form.
	 *
	 * @param int    $form_id UM form ID the profile template received.
	 * @param string $mode    UM profile mode the template received.
	 * @return string Escaped HTML, '' when not permitted or unavailable.
	 */
	public static function render( int $form_id = 0, string $mode = '' ): string {
		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return '';
		}

		$profile_context = $bootstrap->get_profile_context();
		$field_schema    = $bootstrap->get_field_schema();
		$policy          = $bootstrap->get_policy();

		if ( null === $profile_context || null === $field_schema || null === $policy ) {
			return '';
		}

		$context = $profile_context->resolve( array(), $form_id, $mode );

		if ( null === $context || ! ( $context->is_owner ?? false ) ) {
			return '';
		}

		$persona  = (string) ( $context->persona ?? '' );
		$form_id  = (int) ( $context->form_id ?? 0 );
		$owner_id = (int) $context->target_user->ID;

		$out  = '<section class="hal-member-profiles-overview" aria-label="' . esc_attr( __( 'Profile Overview', 'hal-member-profiles' ) ) . '">';
		$out .= '<h2 class="hal-member-profiles-overview__title">' . esc_html__( 'Profile Overview', 'hal-member-profiles' ) . '</h2>';

		$out .= self::render_summary( $context, $persona );
		$out .= self::render_photo_slot( $persona );
		$out .= self::render_persona_fields( $field_schema, $policy, $owner_id, $form_id, $persona );
		$out .= self::render_privacy_note();
		$out .= self::render_services_placeholder( $persona );

		$out .= '</section>';

		return $out;
	}

	/**
	 * Owner summary line: display name + persona label. Display name comes from the
	 * WP_User object UM itself resolved; persona is the C1 mapping verdict.
	 *
	 * @param object $context Resolved profile context (owner).
	 * @param string $persona Resolved persona.
	 * @return string
	 */
	private static function render_summary( object $context, string $persona ): string {
		$name = (string) ( $context->target_user->display_name ?? '' );

		$out  = '<div class="hal-member-profiles-overview__summary">';
		$out .= '<p class="hal-member-profiles-overview__name">' . esc_html( $name ) . '</p>';
		$out .= '<p class="hal-member-profiles-overview__persona">' . esc_html( self::persona_label( $persona ) ) . '</p>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Photo slot (C3): a safe link to the owner's profile only — no write, no upload,
	 * no delegation. C4 owns the actual UM-native photo integration.
	 *
	 * @param string $persona Resolved persona.
	 * @return string
	 */
	private static function render_photo_slot( string $persona ): string {
		unset( $persona ); // Reserved for C4 persona-conditional presentation; not a decision input today.

		$url = function_exists( 'um_user_profile_url' ) ? (string) um_user_profile_url() : '';

		$out  = '<div class="hal-member-profiles-overview__photo">';
		$out .= '<a class="hal-member-profiles-overview__photo-link" href="' . esc_url( $url ) . '">' . esc_html__( 'View photo on profile', 'hal-member-profiles' ) . '</a>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Persona-typed field values, exclusively through Policy's typed pipeline — the same
	 * privacy verdicts as the public profile. Fields come from FieldSchema's catalog for
	 * the resolved form + persona; private fields never reach the owner's Overview either,
	 * because Policy stays the single privacy owner (UM contract).
	 *
	 * @param FieldSchema $field_schema FieldSchema instance.
	 * @param Policy      $policy       Policy instance.
	 * @param int         $owner_id     Profile owner (viewer) ID.
	 * @param int         $form_id      UM Form ID from context.
	 * @param string      $persona      Resolved persona.
	 * @return string
	 */
	private static function render_persona_fields( FieldSchema $field_schema, Policy $policy, int $owner_id, int $form_id, string $persona ): string {
		$scope     = array(
			'context' => 'profile',
			'persona' => $persona,
		);
		$selectors = $field_schema->get_profile_selectors( $form_id, $scope );

		if ( array() === $selectors ) {
			return '';
		}

		$out = '<dl class="hal-member-profiles-overview__fields">';

		foreach ( $selectors as $selector ) {
			$metakey = (string) ( $selector['metakey'] ?? '' );

			if ( '' === $metakey ) {
				continue;
			}

			$result = $policy->can_view_field( $owner_id, $owner_id, $form_id, $metakey, $persona );

			if ( null === $result || '' === trim( (string) $result['value'] ) ) {
				continue;
			}

			$out .= '<div class="hal-member-profiles-overview__field">';
			$out .= '<dt class="hal-member-profiles-overview__field-label">' . esc_html( (string) $selector['label'] ) . '</dt>';
			$out .= '<dd class="hal-member-profiles-overview__field-value">' . self::format_value( $result ) . '</dd>';
			$out .= '</div>';
		}

		$out .= '</dl>';

		return $out;
	}

	/**
	 * Formats one Policy-typed value for the Overview. Policy already classified and
	 * escaped-ready typed values; this applies the same semantic per-type markup as the
	 * public profile widgets (list items for list types, text otherwise).
	 *
	 * @param array{type:string,value:mixed} $result Policy typed value.
	 * @return string
	 */
	private static function format_value( array $result ): string {
		if ( FieldSchema::TYPE_LIST === $result['type'] ) {
			$items = '';

			foreach ( (array) $result['value'] as $item ) {
				$items .= '<li class="hal-member-profiles-overview__field-item">' . esc_html( (string) $item ) . '</li>';
			}

			return '<ul class="hal-member-profiles-overview__field-list">' . $items . '</ul>';
		}

		if ( FieldSchema::TYPE_URL === $result['type'] ) {
			return '<a class="hal-member-profiles-overview__field-link" href="' . esc_url( (string) $result['value'] ) . '">' . esc_html( (string) $result['value'] ) . '</a>';
		}

		return esc_html( (string) $result['value'] );
	}

	/**
	 * Privacy note pointing the owner to UM's own privacy controls — the Overview itself
	 * owns no privacy state of its own (UM remains the single privacy owner).
	 *
	 * @return string
	 */
	private static function render_privacy_note(): string {
		return '<p class="hal-member-profiles-overview__privacy">' . esc_html__( 'Profile privacy is managed by Ultimate Member settings.', 'hal-member-profiles' ) . '</p>';
	}

	/**
	 * Closed services section: a titled, inert placeholder shown to qualified professional
	 * personas only, never rendered as an interactive control. C5/C5b own the actual
	 * service selection and save contract; no Amelia call happens here.
	 *
	 * @param string $persona Resolved persona.
	 * @return string
	 */
	private static function render_services_placeholder( string $persona ): string {
		if ( Settings::PERSONA_LAWYER !== $persona && Settings::PERSONA_CONSULTANT !== $persona ) {
			return '';
		}

		return '<div class="hal-member-profiles-overview__services hal-member-profiles-overview__services--closed">'
			. '<h3 class="hal-member-profiles-overview__services-title">' . esc_html__( 'Public services', 'hal-member-profiles' ) . '</h3>'
			. '<p class="hal-member-profiles-overview__services-note">' . esc_html__( 'Service selection will be available here.', 'hal-member-profiles' ) . '</p>'
			. '</div>';
	}

	/**
	 * Translatable persona labels matching the C1 three-persona mapping.
	 *
	 * @param string $persona Persona key.
	 * @return string
	 */
	private static function persona_label( string $persona ): string {
		switch ( $persona ) {
			case Settings::PERSONA_LAWYER:
				return __( 'Lawyer', 'hal-member-profiles' );
			case Settings::PERSONA_CONSULTANT:
				return __( 'Legal consultant', 'hal-member-profiles' );
			case Settings::PERSONA_ORDINARY_MEMBER:
				return __( 'Member', 'hal-member-profiles' );
			default:
				return '';
		}
	}
}
