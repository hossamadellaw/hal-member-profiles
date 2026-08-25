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
	 * They are stored as strings in _um_custom_fields, so they are compared as strings
	 * here after normalization. Anything else — including legacy positive integers such
	 * as 3/4/5 from older assumptions — normalizes to null and FAILS CLOSED (denied);
	 * UM's own default filter would let unrecognized values through, and this bridge is
	 * deliberately stricter.
	 *
	 * These mappings mirror upstream master, NOT necessarily the installed site version.
	 * Before any public_layout release, compatibility-matrix QA must create one test
	 * field per privacy option on staging, inspect the saved _um_custom_fields values,
	 * and record the installed UM version — updating this adapter if the site diverges.
	 */
	private const PRIVACY_EVERYONE      = '1';
	private const PRIVACY_MEMBERS       = '2';
	private const PRIVACY_OWNER_EDITORS = '-1';
	private const PRIVACY_ROLES_ONLY    = '-2';
	private const PRIVACY_OWNER_ROLES   = '-3';

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
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_field( int $target_user_id, int $viewer_id, int $form_id, string $metakey ): ?array {
		if ( $target_user_id <= 0 || '' === $metakey ) {
			return null;
		}

		$field = $this->find_field( $form_id, $metakey );

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
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_account_field( int $target_user_id, int $viewer_id, string $metakey ): ?array {
		if ( $target_user_id <= 0 || '' === $metakey ) {
			return null;
		}

		$field = $this->field_schema->get_account_field_definition( $metakey );

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
	public function can_view_header_element( int $target_user_id, int $viewer_id, string $element, int $form_id = 0 ): ?array {
		if ( $target_user_id <= 0 || $form_id <= 0 ) {
			return null;
		}

		switch ( $element ) {
			case 'name':
				if ( ! $this->header_name_fields_pass( $target_user_id, $viewer_id, $form_id ) ) {
					return null;
				}

				$user = get_userdata( $target_user_id );

				return $user instanceof \WP_User ? $this->format_text( $user->display_name ) : null;

			case 'bio':
				if ( ! $this->bio_allowed_by_general_settings() ) {
					return null;
				}

				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'description' ) ) {
					return null;
				}

				return $this->format_text( get_user_meta( $target_user_id, 'description', true ) );

			case 'avatar':
				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'profile_photo' ) ) {
					return null;
				}

				$url = get_avatar_url( $target_user_id );

				return $url ? array( 'type' => FieldSchema::TYPE_IMAGE, 'value' => esc_url( (string) $url, array( 'http', 'https' ) ) ) : null;

			case 'cover':
				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'cover_photo' ) ) {
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
	 * Finds a raw field definition by metakey inside a Form's stored fields.
	 *
	 * @param int    $form_id UM Form ID.
	 * @param string $metakey Field meta key.
	 * @return array|null
	 */
	private function find_field( int $form_id, string $metakey ): ?array {
		if ( $form_id <= 0 ) {
			return null;
		}

		$fields = get_post_meta( $form_id, '_um_custom_fields', true );

		if ( ! is_array( $fields ) ) {
			return null;
		}

		if ( isset( $fields[ $metakey ] ) && is_array( $fields[ $metakey ] ) ) {
			return $fields[ $metakey ];
		}

		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['metakey'] ) && $field['metakey'] === $metakey ) {
				return $field;
			}
		}

		return null;
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
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$level = array_key_exists( 'public', $field )
			? $this->normalize_privacy_level( $field['public'] )
			: self::PRIVACY_EVERYONE;

		if ( null === $level ) {
			return false;
		}

		switch ( $level ) {
			case self::PRIVACY_EVERYONE:
				return true;

			case self::PRIVACY_MEMBERS:
				return $viewer_id > 0;

			case self::PRIVACY_OWNER_EDITORS:
				return $this->viewer_is_owner( $viewer_id, $target_user_id )
					|| $this->viewer_can_edit_target( $target_user_id );

			case self::PRIVACY_ROLES_ONLY:
				return $this->viewer_has_allowed_role( $viewer_id, $field );

			case self::PRIVACY_OWNER_ROLES:
				return $this->viewer_is_owner( $viewer_id, $target_user_id )
					|| $this->viewer_has_allowed_role( $viewer_id, $field );

			default:
				return false;
		}
	}

	/**
	 * Normalizes a raw stored privacy value to an official level constant. Only the
	 * storage types UM actually writes — strings and integers — are considered; booleans,
	 * floats, arrays, and everything else return null (fail closed), so e.g. a boolean
	 * true can never masquerade as "Everyone" through string coercion.
	 *
	 * @param mixed $raw Raw 'public' value from a field definition.
	 * @return string|null
	 */
	private function normalize_privacy_level( $raw ): ?string {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return null;
		}

		$value = trim( (string) $raw );

		if ( in_array(
			$value,
			array(
				self::PRIVACY_EVERYONE,
				self::PRIVACY_MEMBERS,
				self::PRIVACY_OWNER_EDITORS,
				self::PRIVACY_ROLES_ONLY,
				self::PRIVACY_OWNER_ROLES,
			),
			true
		) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Whether the viewer is the profile/account owner themselves.
	 *
	 * @param int $viewer_id      Current visitor, 0 for guest.
	 * @param int $target_user_id Profile/account owner.
	 * @return bool
	 */
	private function viewer_is_owner( int $viewer_id, int $target_user_id ): bool {
		return $viewer_id > 0 && $viewer_id === $target_user_id;
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
	private function passes_header_field_privacy( int $target_user_id, int $viewer_id, int $form_id, string $metakey ): bool {
		$field = $this->find_field( $form_id, $metakey );

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
	private function header_name_fields_pass( int $target_user_id, int $viewer_id, int $form_id ): bool {
		$saw_definition = false;

		foreach ( array( 'first_name', 'last_name' ) as $metakey ) {
			$field = $this->find_field( $form_id, $metakey );

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
	 * Whether the viewer has UM's own "can edit other member accounts" permission for the target.
	 *
	 * @param int $target_user_id Target user ID.
	 * @return bool
	 */
	private function viewer_can_edit_target( int $target_user_id ): bool {
		if ( ! function_exists( 'UM' ) || ! method_exists( UM()->roles(), 'um_current_user_can' ) ) {
			return false;
		}

		return (bool) UM()->roles()->um_current_user_can( 'edit', $target_user_id );
	}

	/**
	 * Whether the viewer holds one of the field's explicitly allowed roles.
	 *
	 * @param int   $viewer_id Current visitor, 0 for guest.
	 * @param array $field     Raw UM field definition.
	 * @return bool
	 */
	private function viewer_has_allowed_role( int $viewer_id, array $field ): bool {
		if ( $viewer_id <= 0 || empty( $field['roles'] ) || ! is_array( $field['roles'] ) ) {
			return false;
		}

		$user = get_userdata( $viewer_id );

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return count( array_intersect( $user->roles, $field['roles'] ) ) > 0;
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
