<?php
/**
 * Unit tests for AmeliaFieldsWriter (card C6b / Integration Closure #4): preview diff,
 * ownership ledger, payload allowlists, type-drift conflicts, mode-split enforcement,
 * idempotent replay, honest partial failure, governance denials, and the absolute
 * no-delete guarantee.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\SecretStore;
use HAL\MemberProfiles\Integrations\AmeliaApiClient;
use HAL\MemberProfiles\Integrations\AmeliaFieldsWriter;
use PHPUnit\Framework\TestCase;

final class AmeliaFieldsWriterTest extends TestCase {

	private ?CompatibilityGate $gate = null;

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		$GLOBALS['wp_stubs']['http_queue'] = array();
		$GLOBALS['wp_stubs']['http_calls'] = array();

		$GLOBALS['wp_stubs']['options'] = array(
			\HAL\MemberProfiles\Settings::OPTION_KEY => array(
				'amelia_sync_mode' => 'managed_sync',
			),
			SchemaRegistry::SNAPSHOT_OPTION          => array(
				'version'    => 1,
				'fetched_at' => time(),
				'services'   => array(),
				'employees'  => array(),
				'custom_fields' => array(
					array( 'id' => 55, 'title' => 'Existing Tone', 'type' => 'text' ),
				),
				'counts'        => array( 'services' => 0, 'employees' => 0, 'custom_fields' => 1 ),
			),
			AmeliaFieldsWriter::LEDGER_OPTION        => array(
				'tone'   => array(
					'amelia_id'  => 55,
					'title'      => 'Existing Tone',
					'type'       => 'text',
					'created_at' => 100,
				),
				'legacy' => array(
					'amelia_id'  => 77,
					'title'      => 'Legacy Field',
					'type'       => 'text',
					'created_at' => 90,
				),
				'ghost'  => array(
					'amelia_id'  => 88,
					'title'      => 'Ghost Field',
					'type'       => 'text',
					'created_at' => 80,
				),
			),
		);

		// Card C6b environment parity: this local PHP may lack the sodium extension, so
		// SecretStore::set_amelia_api_key() would refuse with crypto_unavailable and the
		// transport would resolve no_key. SecretStore resolves the process-wide constant
		// FIRST, so this guarded define keeps the suite environment-independent without
		// touching production code (same pattern the D-17 client suite uses).
		if ( ! defined( SecretStore::CONSTANT_SOURCE ) ) {
			define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'unit-test-amelia-key-0123456789abcdef' );
		}

		// Approved gate for the write capability on this exact environment.
		$this->gate = new CompatibilityGate(
			array( 'wp' => '6.5', 'php' => '8.0', 'amelia' => '7.2' ),
			array(
				'amelia_fields_write' => array(
					array(
						'signed'     => true,
						'matrix_row' => 'QA-WRITE',
						'versions'   => array( 'wp' => '6.5', 'php' => '8.0', 'amelia' => '7.2' ),
					),
				),
			)
		);
	}

	private function nonce(): string {
		return 'nonce-' . AmeliaFieldsWriter::NONCE_ACTION;
	}

	/**
	 * fixtures.php's wp_remote_request() consumes THIS base-stub queue; the same-named
	 * extra-stubs helper writes to a different global and never reaches transport.
	 */
	private function queue_http( $response ): void {
		$GLOBALS['wp_stubs']['http_queue'][] = $response;
	}

	public function test_preview_diff_marks_create_update_unchanged_and_orphaned(): void {
		$desired = array(
			array( 'key' => 'tone', 'title' => 'Existing Tone v2', 'type' => 'text' ), // update
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ),         // create
			// ledger keys legacy/ghost absent from desired -> orphaned (removed_from_desired_set)
		);

		$plan = AmeliaFieldsWriter::build_plan( $desired )['plan'];

		$this->assertCount( 1, $plan['to_create'] );
		$this->assertSame( 'brand', $plan['to_create'][0]['key'] );

		$this->assertCount( 1, $plan['to_update'] );
		$this->assertSame( 55, $plan['to_update'][0]['amelia_id'] );

		$this->assertSame( array(), $plan['conflicts'], 'a matching type is never a conflict' );

		$this->assertArrayNotHasKey( 'orphans', $plan, 'the misspelled key must never reappear' );

		$orphan_keys = array_column( $plan['orphaned'], 'key' );

		$this->assertEqualsCanonicalizing( array( 'legacy', 'ghost' ), $orphan_keys );
	}

	public function test_both_orphan_branches_report_under_single_canonical_key(): void {
		// ghost: ledger-owned AND desired, but its Amelia id vanished from the snapshot ->
		// missing_from_snapshot. legacy: owned but absent from desired ->
		// removed_from_desired_set. Both land in plan['orphaned']; nothing is deleted or
		// auto-recreated.
		$desired = array(
			array( 'key' => 'ghost', 'title' => 'Ghost', 'type' => 'text' ),
		);

		$plan = AmeliaFieldsWriter::build_plan( $desired )['plan'];

		$this->assertArrayNotHasKey( 'orphans', $plan );

		$by_key = array_column( $plan['orphaned'], null, 'key' );

		$this->assertSame( 'missing_from_snapshot', $by_key['ghost']['reason'] );
		$this->assertSame( 88, $by_key['ghost']['amelia_id'] );
		$this->assertSame( 'removed_from_desired_set', $by_key['legacy']['reason'] );
		$this->assertSame( 'removed_from_desired_set', $by_key['tone']['reason'] );
	}

	public function test_unowned_id_update_is_never_attempted(): void {
		// Desired references an amelia_id we never created (not in ledger) -> treated as create.
		$desired = array(
			array( 'key' => 'foreign', 'title' => 'Foreign', 'type' => 'url' ),
		);

		$this->queue_http(
			array( 'response' => array( 'code' => 201 ), 'body' => '{"id":900}' )
		);

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 900, $res['results']['created'][0]['amelia_id'] );

		$ledger = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];
		$this->assertArrayHasKey( 'foreign', $ledger, 'ownership recorded for the NEW id only' );
	}

	/** Card C6b: payload allowlists are exact — create {title,type}, update {title}. */
	public function test_create_and_update_send_exactly_the_pinned_allowlist(): void {
		$desired = array(
			array( 'key' => 'brand', 'title' => '  Brand  ', 'type' => 'SELECT' ), // create, normalized
			array( 'key' => 'tone', 'title' => 'Tone v2', 'type' => 'text' ),      // owned, title-only drift
		);

		$this->queue_http( array( 'response' => array( 'code' => 201 ), 'body' => '{"id":900}' ) );
		$this->queue_http( $this->http( 200, '{"ok":true}' ) );

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'applied', $res['reason'] );
		$this->assertSame( 2, $res['applied'] );

		$calls = hal_wp_stub_http_calls();
		$this->assertCount( 2, $calls );

		// Create: POST fields — EXACTLY title + type after normalization, nothing else.
		$this->assertSame( 'https://stub.test/wp-json/amelia/v2/fields', $calls[0]['url'] );
		$this->assertSame( 'POST', $calls[0]['args']['method'] );
		$this->assertSame(
			array( 'title' => 'Brand', 'type' => 'select' ),
			json_decode( (string) $calls[0]['args']['body'], true ),
			'create body is exactly the {title,type} allowlist, normalized'
		);

		// Update: POST fields/55 — EXACTLY title, never type (immutable after creation).
		$this->assertSame( 'https://stub.test/wp-json/amelia/v2/fields/55', $calls[1]['url'] );
		$this->assertSame( 'POST', $calls[1]['args']['method'] );
		$this->assertSame(
			array( 'title' => 'Tone v2' ),
			json_decode( (string) $calls[1]['args']['body'], true ),
			'update body is exactly {title} — no type property may travel'
		);

		$ledger = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$this->assertSame( 'Tone v2', $ledger['tone']['title'], 'ledger follows the applied title' );
		$this->assertSame( 'text', $ledger['tone']['type'], 'ledger type is immutable after creation' );
		$this->assertSame( 'select', $ledger['brand']['type'] );
	}

	/** Card C6b: type drift on an owned field is a documented conflict and blocks apply. */
	public function test_type_drift_is_a_documented_conflict_and_blocks_the_whole_apply(): void {
		$desired = array(
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ), // unrelated create
			array( 'key' => 'tone', 'title' => 'Tone v2', 'type' => 'select' ), // owned + type drift
		);

		$plan = AmeliaFieldsWriter::build_plan( $desired )['plan'];

		$this->assertCount( 1, $plan['conflicts'] );
		$this->assertSame( 'tone', $plan['conflicts'][0]['key'] );
		$this->assertSame( 55, $plan['conflicts'][0]['amelia_id'] );
		$this->assertSame( 'type_drift_conflict', $plan['conflicts'][0]['reason'] );
		$this->assertCount(
			0,
			$plan['to_update'],
			'a type-drifted row never becomes an update, even when its title also differs'
		);

		$before = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'conflict', $res['reason'] );
		$this->assertSame( 0, $res['applied'] );
		$this->assertSame( 'type_drift_conflict', $res['results']['conflicts'][0]['reason'] );
		$this->assertCount( 0, $res['results']['created'] );
		$this->assertCount( 0, $res['results']['updated'] );
		$this->assertCount( 0, $res['results']['failed'] );
		$this->assertCount(
			0,
			hal_wp_stub_http_calls(),
			'a conflict refuses the WHOLE apply — zero transport calls of any kind'
		);
		$this->assertSame(
			$before,
			$GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ],
			'a conflicted apply never mutates the ownership ledger'
		);
	}

	/** Card C6b mode table: managed_additions creates missing fields but refuses updates. */
	public function test_managed_additions_creates_but_refuses_updates(): void {
		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'managed_additions';

		$desired = array(
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ), // create — allowed
			array( 'key' => 'tone', 'title' => 'Tone v2', 'type' => 'text' ),  // update — refused
		);

		$this->queue_http( array( 'response' => array( 'code' => 201 ), 'body' => '{"id":900}' ) );

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'ops_refused_by_mode', $res['reason'] );
		$this->assertSame( 1, $res['applied'], 'the create still applied' );

		$this->assertSame( 'brand', $res['results']['created'][0]['key'] );
		$this->assertSame( 'update', $res['results']['refused'][0]['op'] );
		$this->assertSame( 'tone', $res['results']['refused'][0]['key'] );
		$this->assertSame( 'update_forbidden_in_mode', $res['results']['refused'][0]['reason'] );
		$this->assertCount( 0, $res['results']['updated'] );

		$this->assertCount( 1, hal_wp_stub_http_calls(), 'only the create reached transport' );

		$ledger = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$this->assertSame( 900, $ledger['brand']['amelia_id'] );
		$this->assertSame( 'Existing Tone', $ledger['tone']['title'], 'the refused update never mutated anything' );

		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'managed_sync';
	}

	/** Card C6b acceptance: off/discover_only produce NO write of any kind. */
	public function test_off_and_discover_only_never_write_anything(): void {
		$desired = array(
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ),
		);

		foreach ( array( 'off', 'discover_only' ) as $mode ) {
			$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = $mode;

			$before = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

			$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

			$this->assertSame(
				array( 'ok' => false, 'reason' => 'sync_mode_read_only' ),
				$res,
				"mode {$mode} refuses with nothing else attached"
			);
			$this->assertCount( 0, hal_wp_stub_http_calls(), "mode {$mode} issues zero transport calls" );
			$this->assertSame( $before, $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ], "mode {$mode} never touches the ledger" );
		}

		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'managed_sync';
	}

	public function test_idempotent_replay_skips_already_applied_operations(): void {
		$desired = array(
			array( 'key' => 'tone', 'title' => 'Existing Tone', 'type' => 'text' ), // unchanged vs snapshot
		);

		$this->queue_http(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' )
		);

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $res['ok'] );
		$this->assertContains( 'tone', $res['results']['skipped'] );
		$this->assertCount( 0, hal_wp_stub_http_calls(), 'unchanged ops issue zero transport calls' );
		$this->assertEqualsCanonicalizing(
			array( 'legacy', 'ghost' ),
			array_column( $res['results']['orphaned'], 'key' ),
			'apply() maps plan orphans into results under the canonical key'
		);
	}

	/** Card C6b: re-running apply after success is ledger-driven idempotent — no duplicates. */
	public function test_rerun_after_successful_apply_does_not_duplicate_creates(): void {
		$desired = array(
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ),
		);

		$this->queue_http( array( 'response' => array( 'code' => 201 ), 'body' => '{"id":900}' ) );

		$first = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $first['ok'] );
		$this->assertSame( 900, $first['results']['created'][0]['amelia_id'] );
		$this->assertCount( 1, hal_wp_stub_http_calls() );

		hal_wp_stub_reset_http();

		// Simulate a later snapshot refresh that now reports the created field.
		$GLOBALS['wp_stubs']['options'][ SchemaRegistry::SNAPSHOT_OPTION ]['custom_fields'][] = array(
			'id'    => 900,
			'title' => 'Brand',
			'type'  => 'select',
		);

		$plan = AmeliaFieldsWriter::build_plan( $desired )['plan'];

		$this->assertCount( 0, $plan['to_create'], 'a ledger-proven key is never proposed for creation again' );
		$this->assertContains( 'brand', $plan['unchanged'] );

		$second = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $second['ok'] );
		$this->assertSame( 'applied', $second['reason'] );
		$this->assertSame( 0, $second['applied'] );
		$this->assertCount( 0, $second['results']['created'] );
		$this->assertCount( 0, hal_wp_stub_http_calls(), 'the re-run issues zero transport calls' );
		$this->assertSame(
			900,
			$GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ]['brand']['amelia_id']
		);
	}

	/** Card C6b: an owned key missing from the snapshot is orphaned — never recreated. */
	public function test_owned_field_missing_from_snapshot_is_orphaned_never_recreated(): void {
		$desired = array(
			array( 'key' => 'ghost', 'title' => 'Ghost', 'type' => 'text' ), // ledger id 88, absent from snapshot
		);

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, $res['applied'] );
		$this->assertCount( 0, $res['results']['created'] );
		$this->assertCount( 0, hal_wp_stub_http_calls(), 'a missing owned field is never re-created' );

		$orphaned = array_column( $res['results']['orphaned'], null, 'key' );

		$this->assertSame( 'missing_from_snapshot', $orphaned['ghost']['reason'] );

		$ledger = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$this->assertArrayHasKey( 'ghost', $ledger, 'the ownership entry is kept, never deleted' );
		$this->assertSame( 88, $ledger['ghost']['amelia_id'] );
	}

	/** Card C6b: stop-on-first-failure keeps the completed part honestly, no rollback claim. */
	public function test_stop_on_first_failure_keeps_completed_work_and_marks_the_rest(): void {
		$desired = array(
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ), // create op
			array( 'key' => 'carla', 'title' => 'Carla', 'type' => 'text' ),   // create op
			array( 'key' => 'tone', 'title' => 'Tone v9', 'type' => 'text' ),  // update op
		);

		// Creates run before updates: brand succeeds, carla hits the upstream failure,
		// and the tone update must be marked stopped — with no transport call at all.
		$this->queue_http( array( 'response' => array( 'code' => 201 ), 'body' => '{"id":900}' ) );
		$this->queue_http( $this->http( 500 ) );

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'partial_failure', $res['reason'], 'top-level reason stays coarse' );
		$this->assertSame( 1, $res['applied'], 'only the completed create counts as applied' );

		$this->assertSame( 'brand', $res['results']['created'][0]['key'] );
		$this->assertSame( 'carla', $res['results']['failed'][0]['key'] );
		$this->assertSame(
			'upstream_error',
			$res['results']['failed'][0]['reason'],
			'the transport verdict surfaces in the first failed row'
		);
		$this->assertSame( 'tone', $res['results']['failed'][1]['key'] );
		$this->assertSame( 'stopped_after_previous_failure', $res['results']['failed'][1]['reason'] );

		$this->assertCount( 2, hal_wp_stub_http_calls(), 'stopped ops issue zero transport calls' );

		$ledger = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$this->assertArrayHasKey( 'brand', $ledger, 'the completed create stays in the ledger — no rollback is claimed' );
		$this->assertSame( 900, $ledger['brand']['amelia_id'] );
		$this->assertArrayNotHasKey( 'carla', $ledger, 'the failed create recorded no ownership' );
		$this->assertSame( 'Existing Tone', $ledger['tone']['title'], 'the stopped update never mutated the ledger' );
	}

	public function test_governance_denials_matrix(): void {
		$desired = array( array( 'key' => 'x', 'title' => 'X', 'type' => 'text' ) );

		$GLOBALS['wp_stubs']['can_manage'] = false;
		$this->assertSame( 'denied', AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate )['reason'] );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		$this->assertSame(
			'invalid_nonce',
			AmeliaFieldsWriter::apply( $desired, 'wrong-nonce', $this->gate )['reason']
		);

		// Gate closed: fresh gate with empty registry.
		$closed = new CompatibilityGate( array( 'wp' => '6.5', 'php' => '8.0', 'amelia' => '7.2' ), array() );

		$this->assertSame(
			'gate_closed',
			AmeliaFieldsWriter::apply( $desired, $this->nonce(), $closed )['reason']
		);

		// Read-only sync modes refuse writes.
		foreach ( array( 'off', 'discover_only' ) as $mode ) {
			$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = $mode;

			$this->assertSame(
				'sync_mode_read_only',
				AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate )['reason'],
				"mode {$mode}"
			);
		}

		$GLOBALS['wp_stubs']['options'][ \HAL\MemberProfiles\Settings::OPTION_KEY ]['amelia_sync_mode'] = 'managed_sync';
	}

	/** Mandatory case name: silent-delete prevention is structural in Writer AND client. */
	public function test_no_delete_capability_exists_anywhere(): void {
		foreach ( array( AmeliaFieldsWriter::class, AmeliaApiClient::class ) as $class ) {
			$methods = get_class_methods( $class );

			foreach ( $methods as $m ) {
				$this->assertFalse(
					(bool) preg_match( '/delete|remove|destroy/i', $m ),
					"{$class} must not expose destructive verbs: {$m}"
				);
			}
		}
	}

	private function http( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body );
	}
}
