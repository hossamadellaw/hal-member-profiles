<?php
/**
 * The automatic catalog of fields a designer may select — never a generic user-meta reader.
 *
 * Contract (remediation card F-09): on the live frontend the ACTUAL UM-resolved form ID
 * is mandatory everywhere; this file hands out its best-effort default form only inside
 * a declared, manage_options-gated Elementor editor/preview context. Sensitive and
 * unsupported fields stay excluded from every catalog regardless of caller.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldSchema {

	public const SOURCE_AVAILABLE   = 'available';
	public const SOURCE_UNAVAILABLE = 'unavailable';

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

	/** @var array<string,bool> */
	private array $observed_account_tabs = array();

	private bool $account_tabs_observed = false;

	/** @var array<string,array<string,array>> */
	private array $account_fields_by_tab = array();

	/** @var array<string,bool> */
	private array $account_tab_observers = array();

	public function __construct() {
		// Observe UM's completed runtime tab definition. Field specs are observed later,
		// when UM applies each documented tab filter with its real version-specific value.
		add_filter( 'um_account_page_default_tabs_hook', array( $this, 'observe_account_tabs' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Builds the selector catalog for the Profile Form UM itself resolved for this render.
	 *
	 * @param int $form_id UM Form ID, as resolved and passed through by UM.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */

	public function get_profile_selectors( int $form_id, array $scope = array() ): array {
		$state = $this->profile_field_state( $form_id, $scope );

		return self::SOURCE_AVAILABLE === $state['availability']
			? $this->selectors_from_definitions( $state['items'] )
			: array();
	}

	/**
	 * Discovers a Profile Form through UM's public field hook.
	 *
	 * An array result, including an empty array, is a valid available provider response.
	 * Missing context/provider or a non-array response is explicitly unavailable.
	 *
	 * @param int $form_id Verified active UM Profile Form ID.
	 * @return array{availability:string,reason:string,items:array<string,array>}
	 */
	public function profile_field_state( int $form_id, array $scope = array() ): array {
		if ( $form_id <= 0 ) {
			return $this->unavailable( 'profile_form_unavailable' );
		}

		if ( ! function_exists( 'has_filter' ) || false === has_filter( 'um_get_form_fields' ) ) {
			return $this->unavailable( 'profile_provider_unavailable' );
		}

		try {
			$fields = apply_filters( 'um_get_form_fields', array(), $form_id );
		} catch ( \Throwable $e ) {
			return $this->unavailable( 'profile_provider_unavailable' );
		}

		if ( ! is_array( $fields ) ) {
			return $this->unavailable( 'profile_provider_invalid' );
		}

		$definitions = $this->normalize_definitions( $fields, 'profile', 'profile' );

		return $this->available( $this->filter_definitions( $definitions, $scope, 'profile' ) );
	}

	/**
	 * Looks up one definition only inside the verified runtime Profile source.
	 *
	 * @param int    $form_id Verified active UM Profile Form ID.
	 * @param string $metakey Field key.
	 * @return array|null
	 */
	public function get_profile_field_definition( int $form_id, string $metakey, string $persona = '' ): ?array {
		$state = $this->profile_field_state(
			$form_id,
			array( 'persona' => $persona, 'include_private' => true )
		);

		return self::SOURCE_AVAILABLE === $state['availability']
			&& isset( $state['items'][ $metakey ] )
			&& is_array( $state['items'][ $metakey ] )
			? $state['items'][ $metakey ]
			: null;
	}

	/**
	 * Looks up one fixed core Header field, including non-editable definitions needed only
	 * to enforce that Header element's UM privacy at render time.
	 *
	 * @param int    $form_id Verified active UM Profile Form ID.
	 * @param string $metakey Fixed core Header field key.
	 * @param string $persona Resolved HAL persona.
	 * @return array|null
	 */
	public function get_profile_header_field_definition( int $form_id, string $metakey, string $persona = '' ): ?array {
		if ( ! in_array( $metakey, array( 'first_name', 'last_name', 'description', 'profile_photo', 'cover_photo' ), true ) ) {
			return null;
		}

		$state = $this->profile_field_state(
			$form_id,
			array( 'persona' => $persona, 'include_private' => true, 'include_noneditable' => true )
		);

		return self::SOURCE_AVAILABLE === $state['availability']
			&& isset( $state['items'][ $metakey ] )
			&& is_array( $state['items'][ $metakey ] )
			? $state['items'][ $metakey ]
			: null;
	}

	/**
	 * Account field selectors, from a registered, verified Account field source only.
	 *
	 * Definitions are collected only when UM invokes its documented runtime tab/field
	 * filters. Empty before discovery; no selector is invented and custom HTML tabs stay
	 * inside the native Account body.
	 *
	 * @param int $form_id Unused: the Account source carries its own definitions.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */
	public function get_account_selectors( int $form_id = 0, array $scope = array() ): array {
		unset( $form_id );

		$state = $this->account_field_state( $scope );

		return self::SOURCE_AVAILABLE === $state['availability']
			? $this->selectors_from_definitions( $state['items'] )
			: array();
	}

	/**
	 * Returns Account definitions observed from UM's real tab field filters.
	 * Tabs whose only public contract is rendered HTML never enter this collection and
	 * therefore stay on UM's native path.
	 *
	 * @return array{availability:string,reason:string,items:array<string,array>}
	 */
	public function account_field_state( array $scope = array() ): array {
		if ( ! $this->account_tabs_observed ) {
			return $this->unavailable( 'account_tabs_unavailable' );
		}

		$fields = array();

		foreach ( $this->account_fields_by_tab as $tab_id => $definitions ) {
			foreach ( $definitions as $metakey => $definition ) {
				$definition['account_tab'] = $tab_id;
				$fields[ $metakey ]          = $definition;
			}
		}

		return $this->available( $this->filter_definitions( $fields, $scope, 'account' ) );
	}

	/**
	 * One field's raw definition from the registered Account source, or null when no
	 * verified source defines it. This is the definition lookup Policy uses at render time,
	 * so an Account field can never bypass its own schema entry.
	 *
	 * @param string $metakey Field meta key.
	 * @param array  $scope   Account render scope. Accepts tab or current_tab.
	 * @return array|null
	 */
	public function get_account_field_definition( string $metakey, array $scope = array() ): ?array {
		$tab = sanitize_key( (string) ( $scope['tab'] ?? ( $scope['current_tab'] ?? '' ) ) );

		if ( '' === $tab ) {
			return null;
		}

		$scope['context']             = 'account';
		$scope['tab']                 = $tab;
		$scope['include_private'] = true;

		$state  = $this->account_field_state( $scope );
		$fields = $state['items'];

		return self::SOURCE_AVAILABLE === $state['availability']
			&& isset( $fields[ $metakey ] ) && is_array( $fields[ $metakey ] )
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
	 * Observes UM's runtime tab map and registers observers for its documented per-tab
	 * field hooks. A custom HTML-only tab never fires such a hook and remains native.
	 *
	 * @param mixed $tabs Runtime UM tab map.
	 * @return mixed Unchanged runtime value.
	 */
	public function observe_account_tabs( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$this->account_tabs_observed = true;

		foreach ( $tabs as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( array_keys( $group ) as $raw_tab_id ) {
				$tab_id = sanitize_key( (string) $raw_tab_id );

				if ( '' === $tab_id ) {
					continue;
				}

				$this->observed_account_tabs[ $tab_id ] = true;

				if ( isset( $this->account_tab_observers[ $tab_id ] ) ) {
					continue;
				}

				$this->account_tab_observers[ $tab_id ] = true;
				add_filter(
					'um_account_tab_' . $tab_id . '_fields',
					function ( $spec, $shortcode_args = array() ) use ( $tab_id ) {
						return $this->observe_account_field_spec( $tab_id, $spec, $shortcode_args );
					},
					PHP_INT_MAX,
					2
				);
			}
		}

		return $tabs;
	}

	/**
	 * Captures a definition-only Account field spec without rendering or reading a user.
	 *
	 * @param string $tab_id         Discovered UM tab ID.
	 * @param mixed  $spec           UM's version-specific comma-separated field spec.
	 * @param mixed  $shortcode_args Unused UM shortcode context.
	 * @return mixed Unchanged spec.
	 */
	private function observe_account_field_spec( string $tab_id, $spec, $shortcode_args ) {
		unset( $shortcode_args );

		if ( ! is_string( $spec ) || ! function_exists( 'UM' ) ) {
			return $spec;
		}

		try {
			$um      = UM();
			$builtin = is_object( $um ) && method_exists( $um, 'builtin' ) ? $um->builtin() : null;
		} catch ( \Throwable $e ) {
			return $spec;
		}

		if ( ! is_object( $builtin ) || ! method_exists( $builtin, 'get_specific_fields' ) ) {
			return $spec;
		}

		try {
			$definitions = $builtin->get_specific_fields( $spec );
		} catch ( \Throwable $e ) {
			return $spec;
		}

		if ( is_array( $definitions ) ) {
			$this->account_fields_by_tab[ $tab_id ] = $this->normalize_definitions( $definitions, 'account', $tab_id );
		}

		return $spec;
	}

	/**
	 * A best-effort default Profile Form ID, gated to the declared design-time context:
	 * inside the Elementor editor/preview AND only for a manage_options user. Everywhere
	 * else — above all every live frontend render — this returns 0, so no caller can
	 * silently substitute a role-specific Form's catalog with another form's fields
	 * (remediation card F-09). Render-time callers must pass the form ID UM itself
	 * resolved; 0 means "no verified form" and yields an empty catalog.
	 *
	 * @return int
	 */
	public function default_profile_form_id(): int {
		if ( ! $this->is_editor_preview_for_manager() ) {
			return 0;
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

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
	 * Whether the current request is a manage_options user inside the Elementor editor
	 * canvas or its preview iframe — the one declared place where the default Profile
	 * Form may feed design-time selector population.
	 *
	 * @return bool
	 */
	private function is_editor_preview_for_manager(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || null === \Elementor\Plugin::$instance ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		return ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() )
			|| ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
	}

	/**
	 * Reads a Form's field definitions and classifies each into a selector catalog entry.
	 *
	 * @param int $form_id UM Form ID.
	 * @return array<int,array{metakey:string,label:string,selector_type:string}>
	 */
	private function selectors_from_definitions( array $fields ): array {
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

	/** @return array<string,array> */
	private function normalize_definitions( array $fields, string $source_context, string $source_tab ): array {
		$normalized = array();

		foreach ( $fields as $metakey => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = is_string( $metakey ) ? $metakey : (string) ( $field['metakey'] ?? '' );

			if ( '' !== $key ) {
				$field['_hal_schema'] = array(
					'tab'         => sanitize_key( (string) ( $field['tab'] ?? $source_tab ) ),
					'personas'    => $this->normalize_string_list( $field['personas'] ?? ( $field['persona'] ?? array() ) ),
					'contexts'    => $this->normalize_string_list( $field['contexts'] ?? ( $field['context'] ?? $source_context ) ),
					'privacy'     => array_key_exists( 'public', $field ) ? (string) $field['public'] : '1',
					'editable'    => empty( $field['edit_forbidden'] )
						&& empty( $field['disabled'] )
						&& ( ! array_key_exists( 'editable', $field ) || (bool) $field['editable'] ),
				);
				$normalized[ $key ] = $field;
			}
		}

		return $normalized;
	}

	/** @return array<string,array> */
	private function filter_definitions( array $fields, array $scope, string $expected_context ): array {
		$request_context     = sanitize_key( (string) ( $scope['context'] ?? $expected_context ) );
		$persona            = sanitize_key( (string) ( $scope['persona'] ?? '' ) );
		$tab                = sanitize_key( (string) ( $scope['tab'] ?? '' ) );
		$include_private    = ! empty( $scope['include_private'] );
		$include_noneditable = ! empty( $scope['include_noneditable'] );
		$eligible           = array();

		if ( $request_context !== $expected_context ) {
			return $eligible;
		}

		foreach ( $fields as $metakey => $field ) {
			$meta = is_array( $field['_hal_schema'] ?? null ) ? $field['_hal_schema'] : array();

			if ( ! in_array( $request_context, (array) ( $meta['contexts'] ?? array() ), true ) ) {
				continue;
			}

			if ( '' !== $tab && '' !== (string) ( $meta['tab'] ?? '' ) && $tab !== $meta['tab'] ) {
				continue;
			}

			$personas = (array) ( $meta['personas'] ?? array() );

			if ( ! empty( $personas ) && ( '' === $persona || ! in_array( $persona, $personas, true ) ) ) {
				continue;
			}

			if ( ! $include_private && '1' !== (string) ( $meta['privacy'] ?? '' ) ) {
				continue;
			}

			if ( ! $include_noneditable && empty( $meta['editable'] ) ) {
				continue;
			}

			$eligible[ $metakey ] = $field;
		}

		return $eligible;
	}

	/** @return array<int,string> */
	private function normalize_string_list( $raw ): array {
		$values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$list   = array();

		foreach ( $values as $value ) {
			$value = sanitize_key( trim( (string) $value ) );

			if ( '' !== $value && ! in_array( $value, $list, true ) ) {
				$list[] = $value;
			}
		}

		return $list;
	}

	/** @return array{availability:string,reason:string,items:array<string,array>} */
	private function available( array $items ): array {
		return array(
			'availability' => self::SOURCE_AVAILABLE,
			'reason'       => empty( $items ) ? 'valid_empty' : 'discovered',
			'items'        => $items,
		);
	}

	/** @return array{availability:string,reason:string,items:array<string,array>} */
	private function unavailable( string $reason ): array {
		return array(
			'availability' => self::SOURCE_UNAVAILABLE,
			'reason'       => $reason,
			'items'        => array(),
		);
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
