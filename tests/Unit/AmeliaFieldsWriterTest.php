<?php
/**
 * Unit tests for AmeliaFieldsWriter (Integration Closure #4): preview diff, ownership
 * ledger, idempotent replay, stop-on-failure, governance denials, and the absolute
 * no-delete guarantee.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\SecretStore;
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

		// The apply() path traverses AmeliaApiClient's transport, which refuses to move
		// without a resolvable key; provision a synthetic one for this suite only.
		SecretStore::set_amelia_api_key( 'unit-test-amelia-key-0123456789abcdef' );

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

	public function test_stop_on_first_failure_keeps_prior_ledger_consistent(): void {
		$desired = array(
			array( 'key' => 'tone', 'title' => 'Tone v9', 'type' => 'text' ),  // update op
			array( 'key' => 'brand', 'title' => 'Brand', 'type' => 'select' ), // create op
		);

		// Creates run before updates, so the FIRST op (create) consumes the queued
		// upstream failure and everything after it must be marked stopped.
		$this->queue_http( $this->http( 500 ) );

		$before = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$res = AmeliaFieldsWriter::apply( $desired, $this->nonce(), $this->gate );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'partial_failure', $res['reason'], 'top-level reason stays coarse' );
		$this->assertSame(
			'upstream_error',
			$res['results']['failed'][0]['reason'],
			'the transport verdict surfaces in the first failed row'
		);

		$after = $GLOBALS['wp_stubs']['options'][ AmeliaFieldsWriter::LEDGER_OPTION ];

		$this->assertSame( $before, $after, 'failed batch must not mutate ownership ledger' );
		$this->assertNotEmpty( $res['results']['failed'] );
	}

	/** Mandatory case name: silent-delete prevention is structural. */
	public function test_no_delete_capability_exists_anywhere(): void {
		$methods = get_class_methods( AmeliaFieldsWriter::class );

		foreach ( $methods as $m ) {
			$this->assertFalse(
				(bool) preg_match( '/delete|remove|destroy/i', $m ),
				"writer must not expose destructive verbs: {$m}"
			);
		}
	}

	private function http( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body );
	}
}
