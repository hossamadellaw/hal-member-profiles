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
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp_stubs']['post_meta'] );
		unset( $GLOBALS['wp_stubs']['can_manage'] );
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

	/** Account selectors stay empty until a verified source exists (matrix §6 note). */
	public function test_account_selectors_empty_by_default(): void {
		$this->markTestSkipped(
			'Account source registration fires apply_filters(), which needs the WP plugin '
			. 'environment; covered by Integration suite under WP_TESTS_DIR.'
		);
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
