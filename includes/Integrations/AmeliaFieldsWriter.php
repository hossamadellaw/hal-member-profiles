<?php
/**
 * The OFFICIAL, fully-governed consumer for writing HAL-owned custom fields into Amelia
 * (Integration Closure #4 / development card D-13 capability CAP_AMELIA_FIELDS_WRITE).
 *
 * Governance stack enforced on EVERY apply, in order:
 * 1. is_admin() + manage_options;
 * 2. caller-supplied nonce verified against hal_member_profiles_fields_apply;
 * 3. CompatibilityGate::passes( CAP_AMELIA_FIELDS_WRITE ) — fail-closed, no QA bypass on
 *    writes;
 * 4. SchemaRegistry sync mode ∈ {managed_additions, managed_sync} (never off/discover);
 * 5. SecretStore key availability (the transport verb re-checks too).
 *
 * Semantics:
 * - Identity = OUR ledger (option below), mapping each stable field key to the Amelia ID
 *   WE created. A ledger entry is ownership proof; without it nothing may be updated.
 * - Plan is computed fresh server-side from (desired set ∪ ledger ∪ current snapshot):
 *   to_create / to_update / unchanged / orphaned. Labels are NEVER a matching criterion.
 * - Execution is sequential, single-attempt per op, STOP-ON-FIRST-FAILURE, ledger written
 *   immediately after each success. Replaying an already-applied op hash is a no-op
 *   (idempotent), though a network-level retry after an ambiguous timeout can still
 *   duplicate server-side — documented honestly; our side will never re-issue.
 * - There is NO delete verb in this class or its transport. Desired-set removals become
 *   `orphaned` entries reported for MANUAL handling in Amelia, forever.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\SchemaRegistry;
use HAL\MemberProfiles\CompatibilityGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AmeliaFieldsWriter {

	public const LEDGER_OPTION   = 'hal_member_profiles_amelia_field_ledger';
	public const NONCE_ACTION    = 'hal_member_profiles_fields_apply';

	/**
	 * Computes the action plan WITHOUT executing anything (preview diff).
	 *
	 * Desired row shape: array( 'key' => stable_slug, 'title' => string, 'type' => string ).
	 *
	 * @param array<int,array{key:string,title:string,type:string}> $desired Explicitly configured desired set.
	 * @return array{ok:bool, reason:string, plan?:array<string,mixed>}
	 */
	public static function build_plan( array $desired ): array {
		$snapshot_state = ( new SchemaRegistry() )->amelia_snapshot();

		if ( ! $snapshot_state['ok'] ) {
			return array(
				'ok'     => false,
				'reason' => $snapshot_state['reason'],
			);
		}

		$current = array();

		foreach ( (array) ( $snapshot_state['snapshot']['custom_fields'] ?? array() ) as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			if ( $id > 0 ) {
				$current[ $id ] = array(
					'title' => (string) ( $row['title'] ?? '' ),
					'type'  => (string) ( $row['type'] ?? '' ),
				);
			}
		}

		$ledger = self::ledger();
		$plan   = array(
			'to_create'  => array(),
			'to_update'  => array(),
			'unchanged'  => array(),
			'orphaned'   => array(),
		);

		foreach ( $desired as $row ) {
			$key = (string) ( $row['key'] ?? '' );

			if ( '' === $key || ! isset( $ledger[ $key ] ) ) {
				$plan['to_create'][] = array(
					'key'   => $key,
					'title' => (string) ( $row['title'] ?? '' ),
					'type'  => (string) ( $row['type'] ?? '' ),
				);

				continue;
			}

			$entry      = $ledger[ $key ];
			$amelia_id  = isset( $entry['amelia_id'] ) ? (int) $entry['amelia_id'] : 0;

			if ( $amelia_id <= 0 || ! isset( $current[ $amelia_id ] ) ) {
				// We OWN this key but Amelia no longer reports the field: manual territory,
				// never auto-recreated (would risk duplicates).
				$plan['orphaned'][] = array(
					'key'        => $key,
					'amelia_id'  => $amelia_id,
					'reason'     => 'missing_from_snapshot',
				);

				continue;
			}

			$desired_payload = array(
				'title' => (string) ( $row['title'] ?? '' ),
				'type'  => (string) ( $row['type'] ?? '' ),
			);

			if (
				(string) ( $current[ $amelia_id ]['title'] ?? '' ) === $desired_payload['title']
				&& (string) ( $current[ $amelia_id ]['type'] ?? '' ) === $desired_payload['type']
			) {
				$plan['unchanged'][] = $key;

				continue;
			}

			$plan['to_update'][] = array(
				'key'       => $key,
				'amelia_id' => $amelia_id,
				'payload'   => $desired_payload,
			);
		}

		$desired_keys = array_column( $desired, 'key' );

		foreach ( $ledger as $key => $entry ) {
			if ( ! in_array( (string) $key, $desired_keys, true ) ) {
				$plan['orphaned'][] = array(
					'key'       => (string) $key,
					'amelia_id' => (int) ( $entry['amelia_id'] ?? 0 ),
					'reason'    => 'removed_from_desired_set',
				);
			}
		}

		return array(
			'ok'   => true,
			'plan' => $plan,
		);
	}

	/**
	 * Applies the freshly-computed plan under the full governance stack.
	 *
	 * @param array<int,array{key:string,title:string,type:string}> $desired Explicit desired set (re-planned server-side; client plans are never trusted).
	 * @param string                                                $nonce    Nonce from the calling form/handler.
	 * @return array{ok:bool, reason:string, applied?:int, results?:array<string,mixed>}
	 */
	public static function apply( array $desired, string $nonce, ?CompatibilityGate $gate = null ): array {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return array( 'ok' => false, 'reason' => 'invalid_nonce' );
		}

		if ( ! self::gate_allows( $gate ) ) {
			return array( 'ok' => false, 'reason' => 'gate_closed' );
		}

		if ( ! SchemaRegistry::mode_allows_write() ) {
			return array( 'ok' => false, 'reason' => 'sync_mode_read_only' );
		}

		$planned = self::build_plan( $desired );

		if ( ! $planned['ok'] ) {
			return array( 'ok' => false, 'reason' => 'no_snapshot' );
		}

		$plan    = $planned['plan'];
		$results = array(
			'created' => array(),
			'updated' => array(),
			'skipped' => $plan['unchanged'],
			'orphaned' => $plan['orphaned'],
			'failed'  => array(),
		);

		$ledger = self::ledger();
		$failed = false;

		foreach ( $plan['to_create'] as $op ) {
			if ( $failed ) {
				$results['failed'][] = array( 'op' => 'create', 'key' => $op['key'], 'reason' => 'stopped_after_previous_failure' );
				continue;
			}

			$res = AmeliaApiClient::create_custom_field(
				array(
					'title' => $op['title'],
					'type'  => $op['type'],
				)
			);

			$new_id = isset( $res['data']['id'] ) ? absint( $res['data']['id'] ) : 0;

			if ( ! $res['ok'] || $new_id <= 0 ) {
				$failed              = true;
				$results['failed'][] = array( 'op' => 'create', 'key' => $op['key'], 'reason' => (string) $res['reason'] );

				continue;
			}

			$ledger[ $op['key'] ] = array(
				'amelia_id'  => $new_id,
				'title'      => $op['title'],
				'type'       => $op['type'],
				'created_at' => time(),
			);

			self::persist_ledger( $ledger );

			$results['created'][] = array( 'key' => $op['key'], 'amelia_id' => $new_id );
		}

		foreach ( $plan['to_update'] as $op ) {
			if ( $failed ) {
				$results['failed'][] = array( 'op' => 'update', 'key' => $op['key'], 'reason' => 'stopped_after_previous_failure' );
				continue;
			}

			$res = AmeliaApiClient::update_custom_field(
				(int) $op['amelia_id'],
				$op['payload']
			);

			if ( ! $res['ok'] ) {
				$failed              = true;
				$results['failed'][] = array( 'op' => 'update', 'key' => $op['key'], 'reason' => (string) $res['reason'] );

				continue;
			}

			$ledger[ $op['key'] ]['title'] = $op['payload']['title'];
			$ledger[ $op['key'] ]['type']  = $op['payload']['type'];

			self::persist_ledger( $ledger );

			$results['updated'][] = array( 'key' => $op['key'] );
		}

		$ok = empty( $results['failed'] );

		return array_merge(
			array( 'ok' => $ok, 'applied' => count( $results['created'] ) + count( $results['updated'] ) ),
			$ok ? array( 'reason' => 'applied' ) : array( 'reason' => 'partial_failure' ),
			array( 'results' => $results )
		);
	}

	/**
	 * @return array<string,array<string,mixed>> Stored ownership ledger.
	 */
	private static function ledger(): array {
		$stored = get_option( self::LEDGER_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $ledger Full replacement ledger.
	 * @return void
	 */
	private static function persist_ledger( array $ledger ): void {
		update_option( self::LEDGER_OPTION, $ledger, false );
	}

	/**
	 * Strict write gate: CAP_AMELIA_FIELDS_WRITE must pass. The staging-QA override is
	 * deliberately NOT honored for Amelia-side writes.
	 *
	 * @param CompatibilityGate|null $gate Test seam; production resolves the shared instance.
	 * @return bool
	 */
	private static function gate_allows( ?CompatibilityGate $gate = null ): bool {
		if ( null === $gate ) {
			$bootstrap = Bootstrap::instance();

			if ( null === $bootstrap ) {
				return false;
			}

			$gate = $bootstrap->get_compatibility_gate();
		}

		return null !== $gate && $gate->passes(
			CompatibilityGate::CAP_AMELIA_FIELDS_WRITE
		);
	}
}
