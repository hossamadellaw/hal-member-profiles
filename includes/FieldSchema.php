<?php
/**
 * The automatic catalog of fields a designer may select — never a generic user-meta reader.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldSchema {

	const TYPE_TEXT  = 'text';
	const TYPE_URL   = 'url';
	const TYPE_IMAGE = 'image';
	const TYPE_LIST  = 'list';

	/**
	 * UM field 'type' values this bridge knows how to classify, mapped to a selector type.
	 *
	 * Verify against the site's real installed UM version and forms during
	 * compatibility-matrix testing; any type not listed here stays unsupported
	 * rather than being guessed, per this file's own acceptance rule.
	 *
	 * @var array<string,string>
	 */
	private const KNOWN_TYPES = array(
		'text'         => self::TYPE_TEXT,
		'textarea'     => self::TYPE_TEXT,
		'number'       => self::TYPE_TEXT,
		'date'         => self::TYPE_TEXT,
		'time'         => self::TYPE_TEXT,
		'phone_number' => self::TYPE_TEXT,
		'url'          => self::TYPE_URL,
		'image'        => self::TYPE_IMAGE,
		'select'       => self::TYPE_LIST,
		'multiselect'  => self::TYPE_LIST,
		'checkbox'     => self::TYPE_LIST,
		'radio'        => self::TYPE_LIST,
	);

	/**
	 * UM field 'type' values that must never reach a selector regardless of metakey.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_TYPES = array( 'password', 'confirm_password', 'hidden', 'rating', 'file', 'email' );

	/**
	 * UM metakeys that must never reach a selector regardless of their declared type.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_METAKEYS = array( 'user_password', 'user_email', 'username', 'user_login', 'role', 'account_status', 'status', 'secret_key', 'ID' );

	/**
	 * Builds the selector catalog for the Profile Form UM itself resolved for this render.
	 *
	 * @param int $form_id UM Form ID, as resolved and passed through by UM.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */
	/**
	 * Per-request cache of the registered Account field definitions (never persisted to DB).
	 *
	 * @var array<string,array>|null
	 */
	private ?array $account_field_definitions_cache = null;

	public function get_profile_selectors( int $form_id ): array {
		return $this->build_selectors( $form_id );
	}

	/**
	 * Account field selectors, from a registered, verified Account field source only.
	 *
	 * The sole source is the `hal_member_profiles_account_field_definitions` filter: a
	 * dedicated adapter may return an associative array of raw UM field definitions
	 * (metakey => definition) for one active, documented Account tab/form. Per this file's
	 * own acceptance rule and docs/compatibility-matrix.md §6, such an adapter may be
	 * registered ONLY after that tab's real field API has been confirmed against the
	 * installed UM version and recorded there as Pass. Empty by default: no selector is
	 * created without a verified source, and the field stays inside the native
	 * AccountBody instead.
	 *
	 * @param int $form_id Unused: the Account source carries its own definitions.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */
	public function get_account_selectors( int $form_id = 0 ): array {
		unset( $form_id );

		$selectors = array();

		foreach ( $this->account_field_definitions() as $metakey => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$metakey       = (string) $metakey;
			$selector_type = $this->classify( $metakey, $field );

			if ( null === $selector_type ) {
				continue;
			}

			$selectors[] = array(
				'metakey'       => $metakey,
				'label'         => (string) ( $field['title'] ?? ( $field['label'] ?? $metakey ) ),
				'selector_type' => $selector_type,
			);
		}

		return $selectors;
	}

	/**
	 * One field's raw definition from the registered Account source, or null when no
	 * verified source defines it. This is the definition lookup Policy uses at render time,
	 * so an Account field can never bypass its own schema entry.
	 *
	 * @param string $metakey Field meta key.
	 * @return array|null
	 */
	public function get_account_field_definition( string $metakey ): ?array {
		$fields = $this->account_field_definitions();

		return isset( $fields[ $metakey ] ) && is_array( $fields[ $metakey ] )
			? $fields[ $metakey ]
			: null;
	}

	/**
	 * Classifies one field definition into a selector type (or null). The single shared
	 * classifier for Profile and Account paths alike; Policy delegates here so both files
	 * can never drift apart.
	 *
	 * @param string $metakey Field meta key.
	 * @param array  $field   Raw UM field definition.
	 * @return string|null
	 */
	public function classify_metakey( string $metakey, array $field ): ?string {
		return $this->classify( $metakey, $field );
	}

	/**
	 * Reads the registered Account field definitions once per request.
	 *
	 * @return array<string,array>
	 */
	private function account_field_definitions(): array {
		if ( null !== $this->account_field_definitions_cache ) {
			return $this->account_field_definitions_cache;
		}

		$fields = apply_filters( 'hal_member_profiles_account_field_definitions', array() );

		$this->account_field_definitions_cache = is_array( $fields ) ? $fields : array();

		return $this->account_field_definitions_cache;
	}

	/**
	 * A best-effort default Profile Form ID for design-time selector population only
	 * (e.g. inside the Elementor editor, before any live UM profile render exists).
	 * Render-time callers should always pass the form ID UM itself resolved instead.
	 *
	 * @return int
	 */
	public function default_profile_form_id(): int {
		$forms = get_posts(
			array(
				'post_type'      => 'um_form',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_um_mode',
						'value' => 'profile',
					),
				),
			)
		);

		return ! empty( $forms ) ? (int) $forms[0] : 0;
	}

	/**
	 * Reads a Form's field definitions and classifies each into a selector catalog entry.
	 *
	 * @param int $form_id UM Form ID.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */
	private function build_selectors( int $form_id ): array {
		if ( $form_id <= 0 ) {
			return array();
		}

		$fields = get_post_meta( $form_id, '_um_custom_fields', true );

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$selectors = array();

		foreach ( $fields as $metakey => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$metakey       = is_string( $metakey ) ? $metakey : (string) ( $field['metakey'] ?? '' );
			$selector_type = $this->classify( $metakey, $field );

			if ( null === $selector_type ) {
				continue;
			}

			$selectors[] = array(
				'metakey'       => $metakey,
				'label'         => (string) ( $field['title'] ?? ( $field['label'] ?? $metakey ) ),
				'selector_type' => $selector_type,
			);
		}

		return $selectors;
	}

	/**
	 * Classifies a single field, or returns null when it must be excluded or is unsupported.
	 *
	 * @param string $metakey Field meta key.
	 * @param array  $field   Raw UM field definition.
	 * @return string|null
	 */
	private function classify( string $metakey, array $field ): ?string {
		if ( '' === $metakey || in_array( $metakey, self::EXCLUDED_METAKEYS, true ) ) {
			return null;
		}

		if ( false !== strpos( $metakey, 'email' ) || false !== strpos( $metakey, 'password' ) ) {
			return null;
		}

		$type = isset( $field['type'] ) ? (string) $field['type'] : '';

		if ( '' === $type || in_array( $type, self::EXCLUDED_TYPES, true ) ) {
			return null;
		}

		return self::KNOWN_TYPES[ $type ] ?? null;
	}
}
