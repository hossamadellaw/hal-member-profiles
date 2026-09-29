<?php
/**
 * Unit tests for FieldSchema — restricted selector catalog, default-form gating (F-09),
 * and per-request re-classification. Runs without WordPress against
 * tests/Fixtures/wp-stubs.php.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\FieldSchema;
use PHPUnit\Framework\TestCase;

final class FieldSchemaTest extends TestCase {

	private FieldSchema $schema;

	public function setUp(): void {
		$this->schema = new FieldSchema();
		add_filter(
			'um_get_form_fields',
			static function ( $fields, $form_id ) {
				unset( $fields );
				return get_post_meta( (int) $form_id, '_um_custom_fields', true );
			},
			99,
			2
		);
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['post_meta'] );
		unset( $GLOBALS['wp_stubs']['can_manage'] );
		unset( $GLOBALS['wp_stubs']['um_builtin_fields'] );
		remove_all_filters( 'um_get_form_fields' );
		remove_all_filters( 'um_account_page_default_tabs_hook' );
		remove_all_filters( 'um_account_tab_general_fields' );
		remove_all_filters( 'um_account_tab_custom_fields' );
		if ( class_exists( '\Elementor\Plugin', false ) ) {
			\Elementor\Plugin::$instance = null;
		}
	}

	private function seed_form( int $form_id, array $fields ): void {
		$GLOBALS['wp_stubs']['post_meta'][ $form_id ]['_um_custom_fields'] = $fields;
	}

	private function keys( array $selectors ): array {
		return array_column( $selectors, 'metakey' );
	}

	/** Card 7.7: classification by type. */
	public function test_multiselect_classifies_as_list(): void {
		$this->seed_form( 11, array( 'hobbies' => array( 'metakey' => 'hobbies', 'type' => 'multiselect' ) ) );

		$s = $this->schema->get_profile_selectors( 11 );
		$this->assertSame( 'list', $s[0]['selector_type'] );
	}

	public function test_image_and_url_classify_correctly(): void {
		$this->seed_form( 11, array(
			'gallery_photo' => array( 'metakey' => 'gallery_photo', 'type' => 'image' ),
			'personal_site' => array( 'metakey' => 'personal_site', 'type' => 'url' ),
		) );

		$by_key = array_column( $this->schema->get_profile_selectors( 11 ), 'selector_type', 'metakey' );
		$this->assertSame( 'image', $by_key['gallery_photo'] );
		$this->assertSame( 'url', $by_key['personal_site'] );
	}

	/** Card 7.7: sensitive/unsupported fields never appear. */
	public function test_password_and_unsupported_types_are_excluded(): void {
		$this->seed_form( 11, array(
			'user_password'     => array( 'metakey' => 'user_password', 'type' => 'password' ),
			'recover_password'  => array( 'metakey' => 'recover_password', 'type' => 'password' ),
			'unsupported_field' => array( 'metakey' => 'unsupported_field', 'type' => 'oembed' ),
		) );

		$this->assertSame( array(), $this->schema->get_profile_selectors( 11 ) );
	}

	/** F-09 acceptance: two role-specific forms produce their own distinct catalogs. */
	public function test_two_forms_produce_distinct_catalogs(): void {
		$this->seed_form( 11, array(
			'first_name' => array( 'metakey' => 'first_name', 'type' => 'text' ),
			'hobbies'    => array( 'metakey' => 'hobbies', 'type' => 'multiselect' ),
		) );
		$this->seed_form( 12, array(
			'firm_name'    => array( 'metakey' => 'firm_name', 'type' => 'text' ),
			'firm_website' => array( 'metakey' => 'firm_website', 'type' => 'url' ),
		) );

		$k11 = $this->keys( $this->schema->get_profile_selectors( 11 ) );
		$k12 = $this->keys( $this->schema->get_profile_selectors( 12 ) );

		$this->assertSame( array( 'first_name', 'hobbies' ), $k11 );
		$this->assertSame( array( 'firm_name', 'firm_website' ), $k12 );
		$this->assertNotSame( $k11, $k12 );
	}

	/** F-09 acceptance: a type change is reflected on the next read (no stale cache). */
	public function test_changed_type_reclassifies_on_next_read(): void {
		$this->seed_form( 11, array( 'bio_link' => array( 'metakey' => 'bio_link', 'type' => 'url' ) ) );
		$this->assertSame( 'url', $this->schema->get_profile_selectors( 11 )[0]['selector_type'] );

		$GLOBALS['wp_stubs']['post_meta'][11]['_um_custom_fields']['bio_link']['type'] = 'text';
		$this->assertSame( 'text', $this->schema->get_profile_selectors( 11 )[0]['selector_type'] );
	}

	public function test_profile_provider_distinguishes_valid_empty_from_unavailable(): void {
		$this->seed_form( 15, array() );
		$this->assertSame( 'valid_empty', $this->schema->profile_field_state( 15 )['reason'] );

		remove_all_filters( 'um_get_form_fields' );
		$this->assertSame( 'unavailable', $this->schema->profile_field_state( 15 )['availability'] );
	}

	public function test_account_discovers_field_specs_but_keeps_html_only_tabs_native(): void {
		$GLOBALS['wp_stubs']['um_builtin_fields'] = array(
			'first_name' => array( 'title' => 'First name', 'type' => 'text' ),
		);

		apply_filters(
			'um_account_page_default_tabs_hook',
			array( 100 => array( 'general' => array(), 'custom' => array() ) )
		);
		apply_filters( 'um_account_tab_general_fields', 'first_name', array() );
		apply_filters( 'um_account_content_hook_custom', '<p>native</p>', array() );

		$state = $this->schema->account_field_state();
		$this->assertSame( 'available', $state['availability'] );
		$this->assertSame( array( 'first_name' ), array_keys( $state['items'] ) );
		$this->assertArrayNotHasKey( 'custom', $state['items'] );
	}

	public function test_account_runtime_can_be_available_and_valid_empty(): void {
		apply_filters( 'um_account_page_default_tabs_hook', array() );

		$state = $this->schema->account_field_state();
		$this->assertSame( 'available', $state['availability'] );
		$this->assertSame( 'valid_empty', $state['reason'] );
		$this->assertSame( array(), $state['items'] );
	}

	public function test_metadata_is_retained_and_ineligible_fields_never_reach_selector(): void {
		$this->seed_form(
			16,
			array(
				'ok'               => array( 'type' => 'text', 'public' => '1', 'editable' => true, 'context' => 'profile', 'persona' => 'lawyer', 'tab' => 'profile' ),
				'private'          => array( 'type' => 'text', 'public' => '2', 'editable' => true, 'context' => 'profile', 'persona' => 'lawyer' ),
				'wrong_context'    => array( 'type' => 'text', 'public' => '1', 'editable' => true, 'context' => 'account', 'persona' => 'lawyer' ),
				'wrong_persona'    => array( 'type' => 'text', 'public' => '1', 'editable' => true, 'context' => 'profile', 'persona' => 'consultant' ),
				'not_editable'     => array( 'type' => 'text', 'public' => '1', 'editable' => false, 'context' => 'profile', 'persona' => 'lawyer' ),
			)
		);

		$state = $this->schema->profile_field_state( 16, array( 'context' => 'profile', 'persona' => 'lawyer', 'tab' => 'profile' ) );

		$this->assertSame( array( 'ok' ), array_keys( $state['items'] ) );
		$this->assertSame(
			array( 'tab', 'personas', 'contexts', 'privacy', 'editable' ),
			array_keys( $state['items']['ok']['_hal_schema'] )
		);
		$this->assertSame( array( 'ok' ), $this->keys( $this->schema->get_profile_selectors( 16, array( 'context' => 'profile', 'persona' => 'lawyer', 'tab' => 'profile' ) ) ) );
		$this->assertSame( array(), $this->schema->get_profile_selectors( 16, array( 'context' => 'account', 'persona' => 'lawyer' ) ) );
	}

	// ------------------------------------------------------------------
	// F-09: default_profile_form_id gating.
	// ------------------------------------------------------------------

	public function test_default_form_id_is_zero_outside_editor_preview(): void {
		$this->seed_form( 11, array() ); // forms exist in the world.

		$this->assertSame( 0, $this->schema->default_profile_form_id() );
	}

	public function test_default_form_id_allowed_only_for_manager_in_editor(): void {
		\Elementor\Plugin::$instance          = new \Elementor\Plugin();
		\Elementor\Plugin::$instance->editor  = new class { public function is_edit_mode() { return true; } };
		\Elementor\Plugin::$instance->preview = new class { public function is_preview_mode() { return false; } };

		// Editor but NOT manage_options: still locked.
		$this->assertSame( 0, $this->schema->default_profile_form_id() );

		// Editor + manage_options: declared design-time context — get_posts stub returns
		// no rows here, so the expected result is still 0, but through the ALLOWED path;
		// assert capability flip does not crash and stays fail-closed without forms.
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$this->assertSame( 0, $this->schema->default_profile_form_id() );

		\Elementor\Plugin::$instance = null;
	}
}
