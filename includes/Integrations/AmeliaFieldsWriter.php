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
 * 4. SchemaRegistry sync mode: mode_allows_write() closes off/discover_only entirely;
 *    creates additionally require mode_allows_create() and updates mode_allows_update()
 *    (managed_additions = create-missing-only, managed_sync = create + update owned);
 * 5. SecretStore key availability (the transport verb re-checks too).
 *
 * Semantics (card C6b / F-08):
 * - Identity = OUR ledger (option below), mapping each stable field key to the Amelia ID
 *   WE created. A ledger entry is ownership proof; without it nothing may be updated.
 * - Plan is computed fresh server-side from (desired set ∪ ledger ∪ current snapshot):
 *   to_create / to_update / unchanged / orphaned / conflicts. Labels are NEVER a
 *   matching criterion.
 * - Payload allowlists (v1.2.0): create sends title + type only (after the shared
 *   normalization discipline); update sends title ONLY — `type` is immutable after
 *   creation. An owned field whose snapshot type differs from the desired type is a
 *   documented `type_drift_conflict`: it never becomes an update, is never
 *   auto-corrected, and its presence refuses the WHOLE apply before any op runs.
 * - Execution is sequential, single-attempt per op, STOP-ON-FIRST-FAILURE, ledger written
 *   immediately after each success (completed ops stay recorded — no rollback is claimed
 *   or attempted). Replaying an already-applied op hash is a no-op (idempotent), though a
 *   network-level retry after an ambiguous timeout can still duplicate server-side —
 *   documented honestly; our side will never re-issue.
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
	 * Computes the action plan WITHOUT executing anything (preview diff). The plan is a
	 * mode-independent diff: it reports what WOULD change; mode policy is enforced by
	 * apply() against the same buckets.
	 *
	 * Desired row shape: array( 'key' => stable_slug, 'title' => string, 'type' => string ).
	 * Rows are normalized here (the same sanitization discipline the dashboard applies
	 * when saving the desired set) so plan, payload, and ledger always carry identical
	 * values.
	 *
	 * Buckets: to_create / to_update (title-only payload) / unchanged / orphaned /
	 * conflicts. A ledger-proven owned field whose SNAPSHOT type differs from the desired
	 * type is a documented `type_drift_conflict`: `type` is immutable after creation, the
	 * drift never becomes an update, and it is never auto-corrected.
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
			'conflicts'  => array(),
		);

		foreach ( $desired as $row ) {
			$key   = (string) ( $row['key'] ?? '' );
			$title = self::normalize_title( (string) ( $row['title'] ?? '' ) );
			$type  = self::normalize_type( (string) ( $row['type'] ?? '' ) );

			if ( '' === $key || ! isset( $ledger[ $key ] ) ) {
				$plan['to_create'][] = array(
					'key'   => $key,
					'title' => $title,
					'type'  => $type,
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

			if ( (string) ( $current[ $amelia_id ]['type'] ?? '' ) !== $type ) {
				// Type drift on an OWNED field: `type` is immutable after creation, so
				// this is a documented conflict — never an update, never auto-corrected.
				$plan['conflicts'][] = array(
					'key'        => $key,
					'amelia_id'  => $amelia_id,
					'reason'     => 'type_drift_conflict',
				);

				continue;
			}

			if ( (string) ( $current[ $amelia_id ]['title'] ?? '' ) === $title ) {
				$plan['unchanged'][] = $key;

				continue;
			}

			// Update payload allowlist (v1.2.0): title ONLY — `type` never travels here.
			$plan['to_update'][] = array(
				'key'       => $key,
				'amelia_id' => $amelia_id,
				'payload'   => array( 'title' => $title ),
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
	 * Mode semantics (owner-approved mode table): `off`/`discover_only` never write;
	 * `managed_additions` is create-missing-only — pending updates are refused per-op
	 * (documented in results, other ops still run); `managed_sync` creates and updates
	 * owned fields. A plan carrying ANY conflict refuses the whole run before a single
	 * op executes: an ownership/type inconsistency closes the write path until a human
	 * resolves it manually in Amelia.
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
			'created'  => array(),
			'updated'  => array(),
			'skipped'  => $plan['unchanged'],
			'orphaned' => $plan['orphaned'],
			'conflicts' => $plan['conflicts'],
			'refused'  => array(),
			'failed'   => array(),
		);

		if ( ! empty( $results['conflicts'] ) ) {
			// Documented conflict (type drift / ownership): nothing applied, nothing
			// mutated — the operator resolves it manually, HAL never auto-corrects.
			return array(
				'ok'      => false,
				'reason'  => 'conflict',
				'applied' => 0,
				'results' => $results,
			);
		}

		$ledger = self::ledger();
		$failed = false;

		foreach ( $plan['to_create'] as $op ) {
			if ( ! SchemaRegistry::mode_allows_create() ) {
				// Defense-in-depth: the write gate above already implies a managed mode.
				$results['refused'][] = array( 'op' => 'create', 'key' => $op['key'], 'reason' => 'create_forbidden_in_mode' );
				continue;
			}

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
			if ( ! SchemaRegistry::mode_allows_update() ) {
				// managed_additions is create-missing-only: the update is refused and
				// documented; unrelated ops still run; nothing is auto-corrected.
				$results['refused'][] = array( 'op' => 'update', 'key' => $op['key'], 'reason' => 'update_forbidden_in_mode' );
				continue;
			}

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

			// Title-only ledger update: `type` is immutable after creation.
			$ledger[ $op['key'] ]['title'] = $op['payload']['title'];

			self::persist_ledger( $ledger );

			$results['updated'][] = array( 'key' => $op['key'] );
		}

		if ( ! empty( $results['failed'] ) ) {
			// Honest partial result: completed ops stay in the ledger, no rollback is
			// claimed or attempted, remaining ops are marked stopped.
			$ok     = false;
			$reason = 'partial_failure';
		} elseif ( ! empty( $results['refused'] ) ) {
			$ok     = false;
			$reason = 'ops_refused_by_mode';
		} else {
			$ok     = true;
			$reason = 'applied';
		}

		return array(
			'ok'      => $ok,
			'reason'  => $reason,
			'applied' => count( $results['created'] ) + count( $results['updated'] ),
			'results' => $results,
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
	 * Normalizes one desired title per the pinned v1.2.0 payload discipline — the same
	 * sanitization the dashboard applies when saving the desired set, so plan, payload,
	 * and ledger always agree.
	 *
	 * @param string $title Raw desired title.
	 * @return string
	 */
	private static function normalize_title( string $title ): string {
		return sanitize_text_field( $title );
	}

	/**
	 * Normalizes one desired type slug (lowercase key form, same as the dashboard's save
	 * path). Travels on CREATE payloads only — `type` is immutable after creation.
	 *
	 * @param string $type Raw desired type.
	 * @return string
	 */
	private static function normalize_type( string $type ): string {
		return sanitize_key( $type );
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
