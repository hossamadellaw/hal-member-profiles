<?php
/**
 * Unit tests for FieldSchema — the automatic, restricted field selector catalog.
 *
 * Requires WordPress (get_post_meta, get_posts), so these are marked incomplete until
 * run inside the real WordPress test environment described in tests/bootstrap.php
 * (WP_TESTS_DIR). tests/Fixtures/fixtures.php provides a synthetic field set
 * (hal_member_profiles_fixture_um_fields()) to seed a real UM Form's _um_custom_fields
 * postmeta with in that environment — never real site field data.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FieldSchemaTest extends TestCase {

	/**
	 * Card 7.7 acceptance: adding/removing/changing a field type is reflected.
	 */
	public function test_selectors_reflect_added_field(): void {
		$this->markTestIncomplete( 'Requires WordPress + a real Form post seeded with fixtures.php; run under WP_TESTS_DIR.' );
	}

	public function test_selectors_reflect_removed_field(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	public function test_selectors_reflect_changed_field_type(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.7 acceptance: list/image/url classify correctly.
	 */
	public function test_multiselect_field_classifies_as_list(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR. Fixture: hobbies.' );
	}

	public function test_image_field_classifies_as_image(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR. Fixture: gallery_photo.' );
	}

	public function test_url_field_classifies_as_url(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR. Fixture: personal_site.' );
	}

	/**
	 * Card 7.7 acceptance: sensitive fields are rejected outright.
	 */
	public function test_password_field_never_appears_in_selectors(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR. Fixture: user_password.' );
	}

	public function test_unsupported_type_is_excluded_not_guessed(): void {
		$this->markTestIncomplete( 'Requires WordPress; run under WP_TESTS_DIR. Fixture: unsupported_field (oembed).' );
	}

	/**
	 * Card 7.7 acceptance: Forms differing by role produce different catalogs.
	 */
	public function test_different_forms_produce_different_selector_catalogs(): void {
		$this->markTestIncomplete( 'Requires WordPress + two distinct Form posts; run under WP_TESTS_DIR.' );
	}

	/**
	 * Card 7.7: Account selectors stay empty until a verified source is confirmed
	 * (docs/compatibility-matrix.md §6).
	 */
	public function test_account_selectors_are_empty_by_default(): void {
		$field_schema = new \HAL\MemberProfiles\FieldSchema();

		$this->assertSame( array(), $field_schema->get_account_selectors() );
	}
}
