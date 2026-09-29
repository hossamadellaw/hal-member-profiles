<?php
/**
 * Separate access/privacy gates, typed values, and the escaping contract for every output.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Policy {

	/**
	 * Field privacy values exactly as Ultimate Member's official source documents them
	 * (ultimatemember/includes/core/um-actions-form.php, um_submit_form_errors_hook_()):
	 *
	 *   '1'  Everyone
	 *   '2'  Members (logged-in visitors)
	 *   '-1' Only the profile owner and users who can edit that account
	 *   '-2' Only visitors holding one of the field's specific roles (NO owner exception)
	 *   '-3' The profile owner OR visitors holding one of the field's specific roles
	 *
	 * The installed UM runtime remains authoritative: um_can_view_field() evaluates these
	 * definitions and its public filter may further restrict them. HAL keeps no parallel
	 * privacy mapping.
	 */
	private FieldSchema $field_schema;

	public function __construct( FieldSchema $field_schema ) {
		$this->field_schema = $field_schema;
	}

	/**
	 * Decides whether the viewer may see a Form field's value, and returns it typed and escaped.
	 *
	 * @param int    $target_user_id Profile/account owner.
	 * @param int    $viewer_id      Current visitor, 0 for guest.
	 * @param int    $form_id        UM Form the field belongs to.
	 * @param string $metakey        Field meta key.
	 * @param string $persona        Resolved HAL persona.
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_field( int $target_user_id, int $viewer_id, int $form_id, string $metakey, string $persona = '' ): ?array {
		if ( $target_user_id <= 0 || '' === $metakey ) {
			return null;
		}

		$field = $this->find_field( $form_id, $metakey, $persona );

		if ( null === $field ) {
			return null;
		}

		return $this->evaluate_field( $target_user_id, $viewer_id, $metakey, $field );
	}

	/**
	 * Decides whether the Account owner may see one of their own Account fields, and returns
	 * it typed and escaped. The field definition comes exclusively from FieldSchema's
	 * registered, verified Account source (docs/compatibility-matrix.md §6) — never from a
	 * guessed form ID, so with no registered source this always returns null (fail-closed).
	 *
	 * @param int    $target_user_id Account owner (always the current viewer, per AccountContext).
	 * @param int    $viewer_id      Current visitor; equals $target_user_id on live renders.
	 * @param string $metakey        Field meta key.
	 * @param string $current_tab    Server-resolved current Account tab.
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_account_field( int $target_user_id, int $viewer_id, string $metakey, string $current_tab = '' ): ?array {
		if ( $target_user_id <= 0 || '' === $metakey ) {
			return null;
		}

		$current_tab = sanitize_key( $current_tab );
		if ( '' === $current_tab ) {
			return null;
		}

		$field = $this->field_schema->get_account_field_definition(
			$metakey,
			array(
				'context'     => 'account',
				'current_tab' => $current_tab,
			)
		);

		if ( null === $field ) {
			return null;
		}

		return $this->evaluate_field( $target_user_id, $viewer_id, $metakey, $field );
	}

	/**
	 * The shared evaluation pipeline for both Profile and Account fields: classify via
	 * FieldSchema's single shared catalog, evaluate privacy, then read and format typed.
	 *
	 * @param int    $target_user_id Owner.
	 * @param int    $viewer_id      Viewer.
	 * @param string $metakey        Field meta key.
	 * @param array  $field          Raw UM field definition.
	 * @return array{type:string,value:mixed}|null
	 */
	private function evaluate_field( int $target_user_id, int $viewer_id, string $metakey, array $field ): ?array {
		$type = $this->field_schema->classify_metakey( $metakey, $field );

		if ( null === $type ) {
			return null;
		}

		if ( ! $this->passes_privacy( $target_user_id, $viewer_id, $field ) ) {
			return null;
		}

		$raw = get_user_meta( $target_user_id, $metakey, true );

		return $this->format( $raw, $type, $target_user_id );
	}

	/**
	 * Decides whether the viewer may see a core Header element, and returns it typed and escaped.
	 *
	 * Fail-closed rules (execution-plan card 7.8 + remediation F-07): a missing or invalid
	 * Form ID, or a Header element whose governing field is not explicitly defined in the
	 * active Form, can never be verified against UM's own decisions here, so the element
	 * returns null (hidden / native fallback) instead of being allowed by assumption.
	 *
	 * Bio additionally honors Ultimate Member's documented global option
	 * profile_show_bio (source-verified in um-actions-form.php); per-form custom bio
	 * settings and any undocumented options are NOT assumed.
	 *
	 * @param int    $target_user_id Profile owner.
	 * @param int    $viewer_id      Current visitor, 0 for guest.
	 * @param string $element        One of 'name', 'bio', 'cover', 'avatar'.
	 * @param int    $form_id        UM Form the profile is using, for privacy lookups.
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_header_element( int $target_user_id, int $viewer_id, string $element, int $form_id = 0, string $persona = '' ): ?array {
		if ( $target_user_id <= 0 || $form_id <= 0 ) {
			return null;
		}

		switch ( $element ) {
			case 'name':
				if ( ! $this->header_name_fields_pass( $target_user_id, $viewer_id, $form_id, $persona ) ) {
					return null;
				}

				$user = get_userdata( $target_user_id );

				return $user instanceof \WP_User ? $this->format_text( $user->display_name ) : null;

			case 'bio':
				if ( ! $this->bio_allowed_by_general_settings() ) {
					return null;
				}

				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'description', $persona ) ) {
					return null;
				}

				return $this->format_text( get_user_meta( $target_user_id, 'description', true ) );

			case 'avatar':
				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'profile_photo', $persona ) ) {
					return null;
				}

				$url = get_avatar_url( $target_user_id );

				return $url ? array( 'type' => FieldSchema::TYPE_IMAGE, 'value' => esc_url( (string) $url, array( 'http', 'https' ) ) ) : null;

			case 'cover':
				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'cover_photo', $persona ) ) {
					return null;
				}

				if ( ! function_exists( 'um_get_cover_uri' ) ) {
					return null;
				}

				$raw = get_user_meta( $target_user_id, 'cover_photo', true );
				$url = um_get_cover_uri( $raw, array() );

				return $url ? array( 'type' => FieldSchema::TYPE_IMAGE, 'value' => esc_url( (string) $url, array( 'http', 'https' ) ) ) : null;

			default:
				return null;
		}
	}

	/**
	 * Finds a raw field definition inside UM's verified runtime Form provider.
	 *
	 * @param int    $form_id UM Form ID.
	 * @param string $metakey Field meta key.
	 * @return array|null
	 */
	private function find_field( int $form_id, string $metakey, string $persona = '' ): ?array {
		return $this->field_schema->get_profile_field_definition( $form_id, $metakey, $persona );
	}

	/**
	 * Evaluates a field's Privacy setting for a specific viewer/target pair, mirroring
	 * Ultimate Member's own um_submit_form_errors_hook_() switch decision-for-decision:
	 *
	 *   '1'  Everyone → visible
	 *   '2'  Members → logged-in only
	 *   '-1' Owner OR a viewer who can edit the target account (UM: um_current_user_can('edit'))
	 *   '-2' Specific roles only — UM applies NO owner exception here, and neither do we
	 *   '-3' Owner OR one of the field's specific roles
	 *
	 * Deliberate HAL-side differences, all documented and conservative in direction:
	 * - manage_options administrators always see the value (UM's submit-path switch has
	 *   no such branch; execution-plan card 7.8 requires admin coverage).
	 * - An unrecognized 'public' value is DENIED, while UM's default filter branch would
	 *   keep the field viewable. This bridge never guesses.
	 * - An explicitly present but non-scalar 'public' key (e.g. null) is DENIED, while
	 *   UM's isset()-style guard would fall through to Everyone.
	 * A missing 'public' key means the field has no explicit privacy restriction, which
	 * UM treats as Everyone; that is preserved here.
	 *
	 * @param int   $target_user_id Profile/account owner.
	 * @param int   $viewer_id      Current visitor, 0 for guest.
	 * @param array $field          Raw UM field definition.
	 * @return bool
	 */
	private function passes_privacy( int $target_user_id, int $viewer_id, array $field ): bool {
		if ( ! function_exists( 'um_can_view_field' ) || ! function_exists( 'um_user' ) ) {
			return false;
		}

		// UM evaluates against its current requested user and authenticated visitor. Refuse
		// caller-supplied identities that do not match that already-resolved runtime state.
		if ( (int) um_user( 'ID' ) !== $target_user_id || get_current_user_id() !== $viewer_id ) {
			return false;
		}

		try {
			return (bool) um_can_view_field( $field );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Evaluates a predefined Header element's governing field against the active Form.
	 * A field that is not explicitly defined in the Form FAILS CLOSED (false): absence
	 * of a definition is never treated as permission to show the element.
	 *
	 * @param int    $target_user_id Profile owner.
	 * @param int    $viewer_id      Current visitor, 0 for guest.
	 * @param int    $form_id        UM Form ID.
	 * @param string $metakey        Predefined field meta key to check, if defined.
	 * @return bool
	 */
	private function passes_header_field_privacy( int $target_user_id, int $viewer_id, int $form_id, string $metakey, string $persona = '' ): bool {
		$field = $this->field_schema->get_profile_header_field_definition( $form_id, $metakey, $persona );

		if ( null === $field ) {
			return false;
		}

		return $this->passes_privacy( $target_user_id, $viewer_id, $field );
	}

	/**
	 * The display name is composed from first/last name in UM; every one of those fields
	 * that the active Form explicitly defines must pass privacy for this viewer, and at
	 * least one of them must actually be defined — otherwise the element is unverifiable
	 * and fails closed.
	 *
	 * @param int $target_user_id Profile owner.
	 * @param int $viewer_id      Current visitor, 0 for guest.
	 * @param int $form_id        UM Form ID.
	 * @return bool
	 */
	private function header_name_fields_pass( int $target_user_id, int $viewer_id, int $form_id, string $persona = '' ): bool {
		$saw_definition = false;

		foreach ( array( 'first_name', 'last_name' ) as $metakey ) {
			$field = $this->field_schema->get_profile_header_field_definition( $form_id, $metakey, $persona );

			if ( null === $field ) {
				continue;
			}

			$saw_definition = true;

			if ( ! $this->passes_privacy( $target_user_id, $viewer_id, $field ) ) {
				return false;
			}
		}

		return $saw_definition;
	}

	/**
	 * Whether Ultimate Member's documented global "show bio" option allows the bio block
	 * at all (um-actions-form.php reads UM()->options()->get('profile_show_bio') when the
	 * form does not override bio settings). Fails closed when UM or its options API is
	 * unavailable. Per-form custom bio overrides are intentionally NOT guessed here;
	 * they need compatibility-matrix verification before public_layout.
	 *
	 * @return bool
	 */
	private function bio_allowed_by_general_settings(): bool {
		if ( ! function_exists( 'UM' ) ) {
			return false;
		}

		$options = UM()->options();

		if ( ! is_object( $options ) || ! method_exists( $options, 'get' ) ) {
			return false;
		}

		return (bool) $options->get( 'profile_show_bio' );
	}

	/**
	 * Formats a raw stored value into a typed, escaped value for the given selector type.
	 *
	 * @param mixed  $raw            Raw stored value.
	 * @param string $type           One of FieldSchema's TYPE_* constants.
	 * @param int    $target_user_id Owner, needed to resolve relative image paths.
	 * @return array{type:string,value:mixed}|null
	 */
	private function format( $raw, string $type, int $target_user_id = 0 ): ?array {
		switch ( $type ) {
			case FieldSchema::TYPE_TEXT:
				return $this->format_text( $raw );

			case FieldSchema::TYPE_URL:
				return $this->format_url( $raw );

			case FieldSchema::TYPE_IMAGE:
				return $this->format_image( $raw, $target_user_id );

			case FieldSchema::TYPE_LIST:
				return $this->format_list( $raw );

			default:
				return null;
		}
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return array{type:string,value:string}|null
	 */
	private function format_text( $raw ): ?array {
		$value = trim( wp_strip_all_tags( (string) $raw ) );

		return '' === $value ? null : array(
			'type'  => FieldSchema::TYPE_TEXT,
			'value' => $value,
		);
	}

	/**
	 * Validates the scheme before esc_url(), so only http/https ever reach output.
	 *
	 * @param mixed $raw Raw value.
	 * @return array{type:string,value:string}|null
	 */
	private function format_url( $raw ): ?array {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return null;
		}

		$scheme = wp_parse_url( $raw, PHP_URL_SCHEME );

		if ( null !== $scheme && ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$url = esc_url( $raw, array( 'http', 'https' ) );

		return '' === $url ? null : array(
			'type'  => FieldSchema::TYPE_URL,
			'value' => $url,
		);
	}

	/**
	 * Resolves a stored image value to a URL. UM's typical per-user upload path
	 * (wp-content/uploads/ultimatemember/{user_id}/{filename}) is used for values that
	 * are not already absolute URLs; verify this against the real installed UM version
	 * during compatibility-matrix QA, since a wrong path only breaks the image, never
	 * exposes anything, so this is deliberately the safe direction to be wrong in.
	 *
	 * @param mixed $raw            Raw value.
	 * @param int   $target_user_id Owner, needed to resolve relative paths.
	 * @return array{type:string,value:string}|null
	 */
	private function format_image( $raw, int $target_user_id ): ?array {
		$raw = is_array( $raw ) ? reset( $raw ) : $raw;
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return null;
		}

		if ( 0 === strpos( $raw, 'http://' ) || 0 === strpos( $raw, 'https://' ) ) {
			$url = $raw;
		} elseif ( $target_user_id > 0 ) {
			$upload_dir = wp_upload_dir();
			$url        = trailingslashit( $upload_dir['baseurl'] ) . 'ultimatemember/' . $target_user_id . '/' . ltrim( $raw, '/' );
		} else {
			return null;
		}

		$url = esc_url( $url, array( 'http', 'https' ) );

		return '' === $url ? null : array(
			'type'  => FieldSchema::TYPE_IMAGE,
			'value' => $url,
		);
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return array{type:string,value:string[]}|null
	 */
	private function format_list( $raw ): ?array {
		$items = is_array( $raw ) ? $raw : array( $raw );
		$clean = array();

		foreach ( $items as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return empty( $clean ) ? null : array(
			'type'  => FieldSchema::TYPE_LIST,
			'value' => $clean,
		);
	}
}
