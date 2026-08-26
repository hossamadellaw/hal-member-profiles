<?php
/**
 * The automatic catalog of Dynamic Tags for ordinary Elementor elements — Elementor Pro
 * only. Every tag defers entirely to Policy for access decisions and typed, escaped values.
 *
 * Development card D-10: control options are fed through SchemaRegistry (card D-09) —
 * whose public/account_only buckets derive from FieldSchema's own shared classifier, so
 * the selectable sets remain identical to the Phase-1 contract — and ONE new typed tag
 * joins the fixed roster: the public Amelia catalog item (services/employees), rendered
 * exclusively from the stored administrative snapshot, never a live API call.
 *
 * @package HAL\MemberProfiles\Elementor
 */

namespace HAL\MemberProfiles\Elementor;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\FieldSchema;
use HAL\MemberProfiles\SchemaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for every HAL tag: Bootstrap access, Policy-gated rendering, and
 * FieldSchema-restricted control options. No tag ever accepts a free-text meta key or
 * reads raw user meta directly — everything passes through Policy::can_view_field(),
 * Policy::can_view_account_field(), or Policy::can_view_header_element().
 */
abstract class Abstract_Tag extends Tag {

	const GROUP = 'hal-member-profiles';

	/**
	 * @return Bootstrap|null
	 */
	protected function bootstrap(): ?Bootstrap {
		return Bootstrap::instance();
	}

	/**
	 * Field control options restricted to one selector type, from FieldSchema's own catalog.
	 *
	 * @param string $type    One of FieldSchema's TYPE_* constants.
	 * @param bool   $account Whether to use the Account catalog instead of Profile.
	 * @return array<string,string>
	 */
	protected function field_options_by_type( string $type, bool $account ): array {
		$options = array( '' => __( '— Select a field —', 'hal-member-profiles' ) );

		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
			return $options;
		}

		$field_schema = $bootstrap->get_field_schema();

		if ( null === $field_schema ) {
			return $options;
		}

		// Profile catalog form resolution (F-10): a scoped live/preview context wins;
		// otherwise the ONLY permitted fallback is FieldSchema's declared design-time
		// default, which itself returns 0 outside the manage_options editor preview —
		// so the frontend never populates controls from a guessed Form.
		$profile_form_id = 0;

		$profile_context = $bootstrap->get_profile_context();

		if ( null !== $profile_context ) {
			$scoped = $profile_context->resolve();

			if ( null !== $scoped && ! empty( $scoped->form_id ) ) {
				$profile_form_id = (int) $scoped->form_id;
			}
		}

		if ( $profile_form_id <= 0 ) {
			$profile_form_id = $field_schema->default_profile_form_id();
		}

		// D-10: options come from SchemaRegistry's normalized buckets. For Profile fields
		// the SAME shared classifier FieldSchema always used types each normalized row, so
		// the selectable set is identical to the Phase-1 contract — now registry-governed,
		// with sensitive material structurally excluded by the registry itself.
		$registry = new SchemaRegistry( $field_schema );

		if ( $account ) {
			foreach ( $registry->um_account_schema()['items'] as $item ) {
				if ( SchemaRegistry::VIS_ACCOUNT_ONLY !== $item['visibility'] || $type !== $item['type'] ) {
					continue;
				}

				$options[ $item['identifier'] ] = $item['label'];
			}

			return $options;
		}

		foreach ( $registry->um_profile_schema( $profile_form_id )['items'] as $item ) {
			if ( SchemaRegistry::VIS_PUBLIC !== $item['visibility'] ) {
				continue;
			}

			if ( $type !== $field_schema->classify_metakey( $item['identifier'], array( 'type' => $item['type'] ) ) ) {
				continue;
			}

			$options[ $item['identifier'] ] = $item['label'];
		}

		return $options;
	}

	/**
	 * Public Amelia catalog entries (services + employees) from the stored administrative
	 * snapshot ONLY — never a live REST call, per card D-10's render-time prohibition.
	 *
	 * @return array<string,string>
	 */
	protected function amelia_item_options(): array {
		$options = array( '' => __( '— Select an item —', 'hal-member-profiles' ) );

		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
			return $options;
		}

		$registry = new SchemaRegistry( $bootstrap->get_field_schema() );

		foreach ( $registry->amelia_catalog()['items'] ?? array() as $item ) {
			if ( SchemaRegistry::VIS_PUBLIC !== $item['visibility'] ) {
				continue;
			}

			$options[ $item['kind'] . ':' . $item['id'] ] = (string) $item['label'];
		}

		return $options;
	}

	/**
	 * Renders a Profile Form field's value for the resolved Profile Context viewer.
	 *
	 * @param string $expected_type One of FieldSchema's TYPE_* constants.
	 * @return void
	 */
	protected function render_profile_field( string $expected_type ): void {
		$metakey = (string) $this->get_settings( 'hal_member_profiles_metakey' );

		if ( '' === $metakey ) {
			return;
		}

		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();
		$policy           = $bootstrap->get_policy();
		$field_schema     = $bootstrap->get_field_schema();

		if ( null === $profile_context || null === $policy || null === $field_schema ) {
			return;
		}

		$context = $profile_context->resolve();

		if ( null === $context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		// F-10: no default/zero fallback — an unverifiable form id reaches Policy as-is,
		// which fails closed (null => empty output) instead of another form's fields.
		$form_id = (int) $context->form_id;

		$result = $policy->can_view_field( (int) $context->target_user->ID, (int) $context->visitor_id, $form_id, $metakey );

		$this->echo_typed_result( $result, $expected_type );
	}

	/**
	 * Renders an Account Form field's value — the Account owner only, per AccountContext.
	 * The definition comes from FieldSchema's registered, verified Account source via
	 * Policy::can_view_account_field(); with no source registered this renders nothing.
	 *
	 * @param string $expected_type One of FieldSchema's TYPE_* constants.
	 * @return void
	 */
	protected function render_account_field( string $expected_type ): void {
		$metakey = (string) $this->get_settings( 'hal_member_profiles_metakey' );

		if ( '' === $metakey ) {
			return;
		}

		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
			return;
		}

		$account_context = $bootstrap->get_account_context();
		$policy           = $bootstrap->get_policy();

		if ( null === $account_context || null === $policy ) {
			return;
		}

		$context = $account_context->resolve();

		if ( null === $context ) {
			if ( $account_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		$account_user_id = (int) $context->account_user->ID;

		$result = $policy->can_view_account_field( $account_user_id, $account_user_id, $metakey );

		$this->echo_typed_result( $result, $expected_type );
	}

	/**
	 * Renders a core Header element (name/bio/cover/avatar) for the resolved Profile Context.
	 *
	 * @param string $element       One of 'name', 'bio', 'cover', 'avatar'.
	 * @param string $expected_type One of FieldSchema's TYPE_* constants.
	 * @return void
	 */
	protected function render_header_element( string $element, string $expected_type ): void {
		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
			return;
		}

		$profile_context = $bootstrap->get_profile_context();
		$policy           = $bootstrap->get_policy();

		if ( null === $profile_context || null === $policy ) {
			return;
		}

		$context = $profile_context->resolve();

		if ( null === $context ) {
			if ( $profile_context->is_editor_preview() ) {
				echo $this->render_missing_context_placeholder(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return;
		}

		// F-10: pass the scoped form id straight through; zero means Policy denies.
		$form_id = (int) $context->form_id;

		$result = $policy->can_view_header_element( (int) $context->target_user->ID, (int) $context->visitor_id, $element, $form_id );

		$this->echo_typed_result( $result, $expected_type );
	}

	/**
	 * The single escaping contract for every tag: Policy already returned a typed value;
	 * this only picks the right escaping for that type and never prints anything when the
	 * type does not match what this tag/control expects.
	 *
	 * @param array{type:string,value:mixed}|null $result        Policy's decision.
	 * @param string                               $expected_type One of FieldSchema's TYPE_* constants.
	 * @return void
	 */
	protected function echo_typed_result( ?array $result, string $expected_type ): void {
		if ( null === $result || $expected_type !== $result['type'] ) {
			return;
		}

		switch ( $result['type'] ) {
			case FieldSchema::TYPE_URL:
			case FieldSchema::TYPE_IMAGE:
				echo esc_url( (string) $result['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return;

			case FieldSchema::TYPE_TEXT:
			default:
				echo esc_html( (string) $result['value'] );
				return;
		}
	}

	/**
	 * Builds the safe, translatable "no context/fixture" notice shown only inside the
	 * Elementor editor canvas (edit or preview iframe) — never on the live frontend, and
	 * never in place of a real, Policy-approved value. Reuses the concrete tag's own
	 * get_title() so the notice always names the specific tag that is currently empty.
	 *
	 * @return string
	 */
	protected function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			sprintf(
				/* translators: %s: Dynamic Tag title, e.g. "HAL UM Profile Name". */
				esc_html__( '%s: no context. Choose a preview fixture in HAL Member Profiles → Settings to preview this tag here.', 'hal-member-profiles' ),
				esc_html( $this->get_title() )
			)
		);
	}
}

/**
 * HAL UM Profile Name.
 */
final class Profile_Name_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_name';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Name', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	public function render(): void {
		$this->render_header_element( 'name', FieldSchema::TYPE_TEXT );
	}
}

/**
 * HAL UM Profile Bio.
 */
final class Profile_Bio_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_bio';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Bio', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	public function render(): void {
		$this->render_header_element( 'bio', FieldSchema::TYPE_TEXT );
	}
}

/**
 * HAL UM Profile Avatar. Image control data contract (plain URL via render(), echoed and
 * escaped) matches this file's confirmed Elementor Tag API; verify against a real
 * Elementor Pro Image control during QA — a wrong shape here only shows a missing image,
 * never data.
 */
final class Profile_Avatar_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_avatar';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Avatar', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::IMAGE_CATEGORY );
	}

	public function render(): void {
		$this->render_header_element( 'avatar', FieldSchema::TYPE_IMAGE );
	}
}

/**
 * HAL UM Profile Cover.
 */
final class Profile_Cover_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_cover';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Cover', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::IMAGE_CATEGORY );
	}

	public function render(): void {
		$this->render_header_element( 'cover', FieldSchema::TYPE_IMAGE );
	}
}

/**
 * HAL UM Profile URL — via UM's own um_user_profile_url(), never a constructed link.
 */
final class Profile_Url_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_url';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile URL', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::URL_CATEGORY );
	}

	public function render(): void {
		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap ) {
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

		if ( ! function_exists( 'um_user_profile_url' ) ) {
			return;
		}

		$url = (string) um_user_profile_url( (int) $context->target_user->ID );

		if ( '' === $url ) {
			return;
		}

		echo esc_url( $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * HAL UM Profile Field — Text.
 */
final class Profile_Field_Text_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_field_text';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Field — Text', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_TEXT, false ),
			)
		);
	}

	public function render(): void {
		$this->render_profile_field( FieldSchema::TYPE_TEXT );
	}
}

/**
 * HAL UM Profile Field — URL.
 */
final class Profile_Field_Url_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_field_url';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Field — URL', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::URL_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_URL, false ),
			)
		);
	}

	public function render(): void {
		$this->render_profile_field( FieldSchema::TYPE_URL );
	}
}

/**
 * HAL UM Profile Field — Image.
 */
final class Profile_Field_Image_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_profile_field_image';
	}

	public function get_title(): string {
		return __( 'HAL UM Profile Field — Image', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::IMAGE_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_IMAGE, false ),
			)
		);
	}

	public function render(): void {
		$this->render_profile_field( FieldSchema::TYPE_IMAGE );
	}
}

/**
 * HAL UM Account Field — Text. Account Context only (never Profile, never another member).
 */
final class Account_Field_Text_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_account_field_text';
	}

	public function get_title(): string {
		return __( 'HAL UM Account Field — Text', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_TEXT, true ),
			)
		);
	}

	public function render(): void {
		$this->render_account_field( FieldSchema::TYPE_TEXT );
	}
}

/**
 * HAL UM Account Field — URL.
 */
final class Account_Field_Url_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_account_field_url';
	}

	public function get_title(): string {
		return __( 'HAL UM Account Field — URL', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::URL_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_URL, true ),
			)
		);
	}

	public function render(): void {
		$this->render_account_field( FieldSchema::TYPE_URL );
	}
}

/**
 * HAL UM Account Field — Image.
 */
final class Account_Field_Image_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_account_field_image';
	}

	public function get_title(): string {
		return __( 'HAL UM Account Field — Image', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::IMAGE_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->field_options_by_type( FieldSchema::TYPE_IMAGE, true ),
			)
		);
	}

	public function render(): void {
		$this->render_account_field( FieldSchema::TYPE_IMAGE );
	}
}

/**
 * HAL UM Booking URL — the administrator-configured general Amelia booking URL from
 * Settings, via Amelia::get_general_booking_url(). Only a link, never a preselection or
 * availability check; Amelia's own booking form remains the final authority, per card
 * 7.13. Renders nothing when Amelia is not detected or no general booking URL has been
 * configured yet in Settings — never a guessed or constructed URL.
 */
final class Booking_Url_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_booking_url';
	}

	public function get_title(): string {
		return __( 'HAL UM Booking URL', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::URL_CATEGORY );
	}

	public function render(): void {
		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap || null === $bootstrap->get_amelia() ) {
			return;
		}

		$url = $bootstrap->get_amelia()->get_general_booking_url();

		if ( null === $url ) {
			return;
		}

		echo esc_url( $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * HAL Amelia Catalog Item — ONE typed tag for the whole public catalog (card D-10:
 * explicitly no class-per-field). Options and output come exclusively from
 * SchemaRegistry's stored PII-free snapshot; a source item deleted since the last sync
 * simply renders nothing. Requires the stored snapshot AND a detected Amelia plugin —
 * otherwise silent, never a guessed value.
 */
final class Amelia_Catalog_Tag extends Abstract_Tag {

	public function get_name(): string {
		return 'hal_member_profiles_amelia_catalog';
	}

	public function get_title(): string {
		return __( 'HAL Amelia Catalog Item', 'hal-member-profiles' );
	}

	public function get_group(): array {
		return array( self::GROUP );
	}

	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'hal_member_profiles_amelia_item',
			array(
				'label'   => __( 'Catalog item', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->amelia_item_options(),
			)
		);
	}

	public function render(): void {
		$selected = (string) $this->get_settings( 'hal_member_profiles_amelia_item' );

		if ( '' === $selected ) {
			return;
		}

		$parts = explode( ':', $selected );

		if ( 2 !== count( $parts ) ) {
			return;
		}

		list( $kind, $raw_id ) = $parts;
		$item_id = (int) $raw_id;

		if ( ! in_array( $kind, array( 'service', 'employee' ), true ) || $item_id <= 0 ) {
			return;
		}

		$bootstrap = $this->bootstrap();

		if ( null === $bootstrap || null === $bootstrap->get_amelia() ) {
			return;
		}

		$registry = new SchemaRegistry( $bootstrap->get_field_schema() );

		foreach ( $registry->amelia_catalog()['items'] ?? array() as $item ) {
			if (
				$kind === $item['kind']
				&& $item_id === (int) $item['id']
				&& SchemaRegistry::VIS_PUBLIC === $item['visibility']
			) {
				echo esc_html( (string) $item['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied directly above.
			}
		}
	}
}

/**
 * Coordinates registration of every HAL tag above. Register.php (card 7.15) calls
 * register_all() from the elementor/dynamic_tags/register hook, Elementor Pro only.
 */
final class DynamicTags {

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags_manager Elementor's tags manager.
	 * @return void
	 */
	public static function register_all( $dynamic_tags_manager ): void {
		$tags = array(
			Profile_Name_Tag::class,
			Profile_Bio_Tag::class,
			Profile_Avatar_Tag::class,
			Profile_Cover_Tag::class,
			Profile_Url_Tag::class,
			Profile_Field_Text_Tag::class,
			Profile_Field_Url_Tag::class,
			Profile_Field_Image_Tag::class,
			Account_Field_Text_Tag::class,
			Account_Field_Url_Tag::class,
			Account_Field_Image_Tag::class,
			Booking_Url_Tag::class,
			Amelia_Catalog_Tag::class,
		);

		foreach ( $tags as $tag_class ) {
			$dynamic_tags_manager->register( new $tag_class() );
		}
	}
}
