<?php
/**
 * Flexible display for an Account field when ordinary Dynamic Tags aren't enough —
 * for the Account owner only.
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

final class AccountField extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_account_field';
	}

	public function get_title(): string {
		return __( 'Account Field', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'account', 'field' );
	}

	/**
	 * A single SELECT control strictly populated from FieldSchema's own Account catalog —
	 * never a free-text meta key control.
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
				'label'   => __( 'Account Field', 'hal-member-profiles' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->get_field_options(),
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * FieldSchema::get_account_selectors() is currently always empty (no verified Account
	 * field source yet), so this control has nothing to offer until that is confirmed — a
	 * field with no qualified schema stays inside the native AccountBody instead, exactly
	 * as this card requires.
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

		foreach ( $field_schema->get_account_selectors() as $selector ) {
			$options[ $selector['metakey'] ] = $selector['label'];
		}

		return $options;
	}

	/**
	 * Renders the selected field's typed value only, for the Account owner alone, per
	 * Policy's decision; hides itself completely when no field is selected, Account
	 * Context is invalid, or the field is private/unsupported/empty. AccountContext already
	 * guarantees the resolved account belongs only to the current viewer (or an authorized
	 * editor fixture), so the same user ID is used as both target and viewer here.
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

		$field_schema = $bootstrap->get_field_schema();

		if ( null === $field_schema ) {
			return;
		}

		$result = $policy->can_view_account_field( $account_user_id, $account_user_id, $metakey );

		if ( null === $result ) {
			return;
		}

		$label = $this->field_label( $field_schema, $metakey );

		// Policy already returned typed, escaped-ready values; format() below applies the
		// final semantic markup and escaping per type.
		echo $this->format_field( $result, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Looks up the field's designer-facing label from FieldSchema's Account catalog, for
	 * accessible markup (e.g. image alt text). Empty when not found.
	 *
	 * @param FieldSchema $field_schema FieldSchema instance.
	 * @param string      $metakey      Field meta key.
	 * @return string
	 */
	private function field_label( FieldSchema $field_schema, string $metakey ): string {
		foreach ( $field_schema->get_account_selectors() as $selector ) {
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
	 * Builds the safe, translatable "no account context/fixture" notice shown only inside
	 * the Elementor editor canvas (edit or preview iframe) — never on the live frontend,
	 * and never in place of a selected field's own Policy-approved value.
	 *
	 * @return string
	 */
	private function render_missing_context_placeholder(): string {
		return sprintf(
			'<div class="hal-member-profiles-placeholder"><span class="hal-member-profiles-placeholder__text">%s</span></div>',
			esc_html__( 'Account Field: no account context. Choose a preview fixture in HAL Member Profiles → Settings to preview this widget here.', 'hal-member-profiles' )
		);
	}
}
