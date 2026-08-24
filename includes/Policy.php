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
	 * Ordinal privacy levels in the order Ultimate Member's own Field Privacy dropdown
	 * documents them: Everyone, Members, Owner + users who can edit other accounts,
	 * Owner + specific roles, Specific roles only. Verify these against the real installed
	 * UM version's stored 'public' values during compatibility-matrix QA — create one test
	 * field per privacy option and inspect its saved _um_custom_fields postmeta — before
	 * relying on this mapping in production. Any unrecognized value fails closed (denied).
	 */
	private const PRIVACY_EVERYONE      = 1;
	private const PRIVACY_MEMBERS       = 2;
	private const PRIVACY_OWNER_EDITORS = 3;
	private const PRIVACY_OWNER_ROLES   = 4;
	private const PRIVACY_ROLES_ONLY    = 5;

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
	 * Mimics Ultimate Member's own active-Form privacy for Name/Bio/Cover/Avatar first; an
	 * element not explicitly defined in the active Form is treated as visible by default,
	 * since this bridge does not assume an undocumented general option such as "show_avatar"
	 * exists.
	 *
	 * @param int    $target_user_id Profile owner.
	 * @param int    $viewer_id      Current visitor, 0 for guest.
	 * @param string $element        One of 'name', 'bio', 'cover', 'avatar'.
	 * @param int    $form_id        UM Form the profile is using, for privacy lookups.
	 * @return array{type:string,value:mixed}|null
	 */
	public function can_view_header_element( int $target_user_id, int $viewer_id, string $element, int $form_id = 0 ): ?array {
		if ( $target_user_id <= 0 ) {
			return null;
		}

		switch ( $element ) {
			case 'name':
				if ( ! $this->passes_header_field_privacy( $target_user_id, $viewer_id, $form_id, 'first_name' ) ) {
					return null;
				}

				$user = get_userdata( $target_user_id );

				return $user instanceof \WP_User ? $this->format_text( $user->display_name ) : null;

			case 'bio':
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
	 * Evaluates a field's Privacy setting for a specific viewer/target pair. Fails closed:
	 * an unrecognized privacy value is treated as denied, never as visible.
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

		if ( $viewer_id > 0 && $viewer_id === $target_user_id ) {
			return true;
		}

		$level = isset( $field['public'] ) ? (int) $field['public'] : self::PRIVACY_EVERYONE;

		switch ( $level ) {
			case self::PRIVACY_EVERYONE:
				return true;

			case self::PRIVACY_MEMBERS:
				return $viewer_id > 0;

			case self::PRIVACY_OWNER_EDITORS:
				return $this->viewer_can_edit_target( $target_user_id );

			case self::PRIVACY_OWNER_ROLES:
			case self::PRIVACY_ROLES_ONLY:
				return $this->viewer_has_allowed_role( $viewer_id, $field );

			default:
				return false;
		}
	}

	/**
	 * Mimics the active Form's privacy for a predefined Header element; visible by default
	 * when the element has no explicit field definition in the active Form, since this
	 * bridge does not assume an undocumented general option controls it.
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
			return true;
		}

		return $this->passes_privacy( $target_user_id, $viewer_id, $field );
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
