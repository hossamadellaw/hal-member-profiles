<?php
/**
 * Flexible display for fields an ordinary Heading/Image/Button can't handle well —
 * especially lists/chips and formats needing their own markup.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\FieldSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileField extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_profile_field';
	}

	public function get_title(): string {
		return __( 'Profile Field', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-post-list';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'profile', 'field', 'list', 'chips' );
	}

	/**
	 * A single SELECT control strictly populated from FieldSchema's own catalog — never a
	 * free-text meta key control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'hal_member_profiles_content_section',
			array(
				'label' => __( 'Field', 'hal-member-profiles' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'hal_member_profiles_metakey',
			array(
				'label'   => __( 'Profile Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->get_field_options(),
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Design-time selector options. Uses FieldSchema's own best-effort default Profile Form
	 * (design-time only; render() below re-resolves the real Form for the live viewer).
	 *
	 * @return array<string,string>
	 */
	private function get_field_options(): array {
		$options = array( '' => __( '— Select a field —', 'hal-member-profiles' ) );

		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			return $options;
		}

		$field_schema = $bootstrap->get_field_schema();

		if ( null === $field_schema ) {
			return $options;
		}

		$form_id = $field_schema->default_profile_form_id();

		foreach ( $field_schema->get_profile_selectors( $form_id ) as $selector ) {
			$options[ $selector['metakey'] ] = $selector['label'];
		}

		return $options;
	}

	/**
	 * Renders the selected field's typed value only, per Policy's decision; hides itself
	 * completely when no field is selected, or the field is unset, private, unsupported,
	 * or empty for this viewer.
	 */
	protected function render(): void {
		$metakey = (string) $this->get_settings_for_display( 'hal_member_profiles_metakey' );

		if ( '' === $metakey ) {
			return;
		}

		$bootstrap = Bootstrap::instance();

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

		$form_id = ! empty( $context->form_id ) ? (int) $context->form_id : $field_schema->default_profile_form_id();

		$result = $policy->can_view_field( (int) $context->target_user->ID, (int) $context->visitor_id, $form_id, $metakey );

		if ( null === $result ) {
			return;
		}

		$label = $this->field_label( $field_schema, $form_id, $metakey );

		// Policy already returned typed, escaped-ready values; format() below applies the
		// final semantic markup and escaping per type.
		echo $this->format_field( $result, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Looks up the field's designer-facing label from FieldSchema's catalog, for accessible
	 * markup (e.g. image alt text). Empty when not found.
	 *
	 * @param FieldSchema $field_schema FieldSchema instance.
	 * @param int         $form_id      UM Form ID.
	 * @param string      $metakey      Field meta key.
	 * @return string
	 */
	private function field_label( FieldSchema $field_schema, int $form_id, string $metakey ): string {
		foreach ( $field_schema->get_profile_selectors( $form_id ) as $selector ) {
			if ( $selector['metakey'] === $metakey ) {
				return $selector['label'];
			}
		}

		return '';
	}

	/**
	 * Builds semantic, escaped, accessible markup under the hal-member-profiles wrapper.
	 *
	 * @param array  $result Typed value from Policy: array{type:string,value:mixed}.
	 * @param string $label  Field label, for accessible markup such as image alt text.
	 * @return string
	 */
	private function format_field( array $result, string $label ): string {
		switch ( $result['type'] ) {
			case FieldSchema::TYPE_LIST:
				$items = '';

				foreach ( (array) $result['value'] as $item ) {
					$items .= '<li class="hal-member-profiles__field-item">' . esc_html( (string) $item ) . '</li>';
				}

				return '<ul class="hal-member-profiles hal-member-profiles__field hal-member-profiles__field--list">' . $items . '</ul>';

			case FieldSchema::TYPE_URL:
				$url = (string) $result['value'];

				return '<div class="hal-member-profiles hal-member-profiles__field hal-member-profiles__field--url"><a href="' . esc_url( $url ) . '" rel="nofollow">' . esc_html( $url ) . '</a></div>';

			case FieldSchema::TYPE_IMAGE:
				return '<div class="hal-member-profiles hal-member-profiles__field hal-member-profiles__field--image"><img src="' . esc_url( (string) $result['value'] ) . '" alt="' . esc_attr( $label ) . '" loading="lazy" /></div>';

			case FieldSchema::TYPE_TEXT:
			default:
				return '<div class="hal-member-profiles hal-member-profiles__field hal-member-profiles__field--text">' . esc_html( (string) $result['value'] ) . '</div>';
		}
	}

	/**
	 * Builds the safe, translatable "no profile context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of a selected field's own Policy-approved value.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Profile Field: no profile context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
