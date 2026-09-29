<?php
/**
 * Unit tests for SchemaRegistry (cards D-09/D-17): four-bucket classification with the
 * defense-in-depth denylist, schema diffs across forms, PII-free snapshot pipeline with
 * whole-batch fail-closed, orphan/custom-field defaults, mapping contract pinning, and
 * Dynamic-Tags privacy guarantees at the selector level.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class SchemaRegistryTest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_reset_http();
		$GLOBALS['wp_stubs']['options']   = array(
			\HAL\MemberProfiles\Settings::OPTION_KEY => array(
				'amelia_sync_mode' => 'discover_only',
			),
		);
		$GLOBALS['wp_stubs']['post_meta'] = array();
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

	protected function tearDown(): void {
		remove_all_filters( 'um_get_form_fields' );
		remove_all_filters( 'um_account_page_default_tabs_hook' );
	}

	public function test_sync_off_refuses_discovery_before_any_http_call(): void {
		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'off';

		$res = $this->registry()->refresh_amelia_snapshot();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'sync_off', $res['reason'] );
		$this->assertCount( 0, hal_wp_stub_http_calls() );
	}

	private function registry(): SchemaRegistry {
		return new SchemaRegistry();
	}

	private function visibility_map( int $form_id ): array {
		$schema = $this->registry()->um_profile_schema( $form_id );

		return array_column( $schema['items'], 'visibility', 'identifier' );
	}

	public function test_mapping_contract_pins_the_governed_metakeys(): void {
		$contract = $this->registry()->mapping_contract();

		$this->assertSame( 'hal_amelia_employee_id', $contract['member_to_employee']['key'] );
		$this->assertSame( 'hal_amelia_service_ids', $contract['member_services']['key'] );
	}

	public function test_four_bucket_classification_with_denylist_wins(): void {
		$GLOBALS['wp_stubs']['post_meta'][42]['_um_custom_fields'] = array(
			'nickname'     => array( 'title' => 'Nickname', 'type' => 'text' ),
			'website'      => array( 'title' => 'Site', 'type' => 'url' ),
			'hobbies'      => array( 'title' => 'Hobbies', 'type' => 'multiselect' ),
			'secret_token' => array( 'title' => 'Token', 'type' => 'text' ), // denylist beats classifier
			'mypassword'   => array( 'title' => 'PW', 'type' => 'text' ),
			'hidden_note'  => array( 'title' => 'Note', 'type' => 'hidden' ),
			'odd_field'    => array( 'title' => 'Odd', 'type' => 'hologram' ), // unknown -> unsupported
		);

		$map = $this->visibility_map( 42 );

		$this->assertSame( 'public', $map['nickname'] );
		$this->assertSame( 'public', $map['website'] );
		$this->assertSame( 'public', $map['hobbies'] );
		$this->assertSame( 'sensitive', $map['secret_token'] );
		$this->assertSame( 'sensitive', $map['mypassword'] );
		$this->assertSame( 'sensitive', $map['hidden_note'] );
		$this->assertSame( 'unsupported', $map['odd_field'] );
	}

	/** Mandatory case name: Dynamic Tags privacy at the selector/catalog level. */
	public function test_dt_privacy_sensitive_identifiers_never_reach_public_items(): void {
		$GLOBALS['wp_stubs']['post_meta'][43]['_um_custom_fields'] = array(
			'bio'          => array( 'title' => 'Bio', 'type' => 'textarea' ),
			'user_email_x' => array( 'title' => 'Mail', 'type' => 'text' ),
			'api_key_x'    => array( 'title' => 'Key', 'type' => 'text' ),
		);

		$schema = $this->registry()->um_profile_schema( 43 );
		$public = array_column(
			array_filter( $schema['items'], fn( $i ) => 'public' === $i['visibility'] ),
			'identifier'
		);

		$this->assertSame( array( 'bio' ), $public, 'only non-sensitive fields may become selectors' );
	}

	public function test_schema_diff_between_two_forms_and_per_request_cache_isolation(): void {
		$GLOBALS['wp_stubs']['post_meta'][10]['_um_custom_fields'] = array(
			'alpha' => array( 'title' => 'Alpha', 'type' => 'text' ),
		);
		$GLOBALS['wp_stubs']['post_meta'][20]['_um_custom_fields'] = array(
			'beta'  => array( 'title' => 'Beta', 'type' => 'url' ),
			'gamma' => array( 'title' => 'Gamma', 'type' => 'image' ),
		);

		$first  = $this->registry()->um_profile_schema( 10 );
		$second = $this->registry()->um_profile_schema( 20 );

		$this->assertNotSame(
			array_column( $first['items'], 'identifier' ),
			array_column( $second['items'], 'identifier' ),
			'different forms must produce different catalogs'
		);
		$this->assertSame( array( 'alpha' ), array_column( $first['items'], 'identifier' ) );
	}

	public function test_account_side_reports_absence_without_inventing_selectors(): void {
		$res = $this->registry()->um_account_schema();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'account_tabs_unavailable', $res['reason'] );
		$this->assertSame( array(), $res['items'] );
	}

	public function test_snapshot_refresh_builds_pii_free_index_and_catalog_defaults(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY' ) ) {
			define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'D17-SNAP-KEY' );
		}

		hal_wp_stub_queue_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"services":[{"id":5,"title":"Cut"},{"id":9,"title":"Style"}],"employees":[{"id":31,"first_name":"Jane","email":"jane@private.test"}]}',
			)
		);
		hal_wp_stub_queue_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"custom_fields":[{"id":77,"title":"Tone","type":"text"}]}',
			)
		);

		$res = $this->registry()->refresh_amelia_snapshot();

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'snapshot_updated', $res['reason'] );

		$raw = (string) json_encode( $GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ] );

		$this->assertFalse( strpos( $raw, 'Jane' ) );
		$this->assertFalse( strpos( $raw, 'jane@private.test' ) );
		$this->assertStringContainsString( '"id":31', $raw );

		$catalog = $this->registry()->amelia_catalog();
		$kinds   = array();

		foreach ( $catalog['items'] as $item ) {
			$kinds[] = $item['kind'] . ':' . $item['visibility'];
		}

		$this->assertContains( 'service:public', $kinds );
		$this->assertContains( 'employee:public', $kinds );
		$this->assertContains( 'custom_field:unsupported', $kinds, 'unmapped custom fields stay orphaned/unsupported' );
	}

	public function test_unrecognized_payload_fails_whole_batch_and_keeps_previous_snapshot(): void {
		// Seed a known-good snapshot first.
		hal_wp_stub_queue_http(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"services":[{"id":5,"title":"Cut"}],"employees":[]}' )
		);
		hal_wp_stub_queue_http(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"custom_fields":[]}' )
		);

		$this->assertTrue( $this->registry()->refresh_amelia_snapshot()['ok'] );

		$before = md5( (string) json_encode( $GLOBALS['__opts'] ?? array() ) . (string) json_encode( $GLOBALS['wp_stubs']['options'] ) );

		hal_wp_stub_queue_http(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"employees":{"not":"a-list"}}' )
		);
		hal_wp_stub_queue_http(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"custom_fields":[]}' )
		);

		$res = $this->registry()->refresh_amelia_snapshot();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'unrecognized_payload', $res['reason'] );

		$after = md5( (string) json_encode( $GLOBALS['__opts'] ?? array() ) . (string) json_encode( $GLOBALS['wp_stubs']['options'] ) );

		$this->assertSame( $before, $after, 'failed sync must leave the previous snapshot byte-intact' );
	}

	/**
	 * Card C6a / F-07: the owner-approved mode table, applied EXCLUSIVELY to HAL-owned
	 * Amelia field definitions. Each mode is asserted across every gate — the two new
	 * semantically distinct pair members (create/update) plus the legacy read/write
	 * views — so `managed_additions` can never silently gain `managed_sync` powers.
	 */
	public function test_c6a_mode_table_gates_field_definition_operations(): void {
		$table = array(
			'off'               => array( 'read' => false, 'diff' => false, 'create' => false, 'update' => false ),
			'discover_only'     => array( 'read' => true, 'diff' => true, 'create' => false, 'update' => false ),
			'managed_additions' => array( 'read' => true, 'diff' => true, 'create' => true, 'update' => false ),
			'managed_sync'      => array( 'read' => true, 'diff' => true, 'create' => true, 'update' => true ),
		);

		foreach ( $table as $mode => $expected ) {
			$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = $mode;

			$this->assertSame( $expected['read'], SchemaRegistry::mode_allows_read(), "read gate for {$mode}" );
			$this->assertSame( $expected['create'], SchemaRegistry::mode_allows_create(), "create gate for {$mode}" );
			$this->assertSame( $expected['update'], SchemaRegistry::mode_allows_update(), "update gate for {$mode}" );

			// The legacy "any write" view follows create (both managed modes) and never
			// widens beyond it; discovery-only modes stay read-only through every gate.
			$this->assertSame( $expected['create'], SchemaRegistry::mode_allows_write(), "legacy any-write gate for {$mode}" );
			$this->assertSame( $expected['diff'], SchemaRegistry::mode_allows_read(), "diff availability rides the read gate for {$mode}" );
		}
	}

	/**
	 * Card C6a: an UNRECOGNIZED mode value fail-closes every gate — it must never
	 * fall through into any capability, including the legacy any-write view.
	 */
	public function test_c6a_unknown_mode_fails_closed_on_every_gate(): void {
		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'managed_everything';

		$this->assertSame( 'off', SchemaRegistry::current_sync_mode(), 'unknown mode normalizes to off' );
		$this->assertFalse( SchemaRegistry::mode_allows_read() );
		$this->assertFalse( SchemaRegistry::mode_allows_create() );
		$this->assertFalse( SchemaRegistry::mode_allows_update() );
		$this->assertFalse( SchemaRegistry::mode_allows_write() );
	}
}
