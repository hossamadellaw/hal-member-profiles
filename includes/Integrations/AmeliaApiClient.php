<?php
/**
 * Administrative client for the Amelia Elite REST surface — development card D-08.
 *
 * Sole responsibility: talking to Amelia's HTTP API through WordPress's own HTTP API,
 * read-only, for later administrative consumers (SchemaRegistry snapshot, dashboard
 * diagnostics). Nothing else may issue these calls.
 *
 * Contract implemented here (per the governing card):
 * - Base URL derives from THIS SITE (home_url()), never from Request/user input; a
 *   wp-config/environment constant HAL_MEMBER_PROFILES_AMELIA_API_BASE may replace the
 *   default path entirely. The bundled default targets Amelia Elite's REST namespace
 *   (/wp-json/amelia/v2); ANY mismatch (different build, plugin absent, route removed)
 *   surfaces as elite_unavailable via status classification and keeps every consumer
 *   fail-closed — a wrong base can never degrade Ultimate Member.
 * - Authentication travels ONLY in the custom `Amelia` header, sourced exclusively from
 *   SecretStore (card D-07) — never from Request data, constants of third parties, or
 *   stored plaintext.
 * - Limits: 10s timeout, 1 MiB response ceiling, single attempt per call. Read-only
 *   resources (/entities, /fields) are the ONLY endpoints this class knows; there are
 *   deliberately no write verbs, hence no non-idempotent-retry hazard can exist. Any
 *   future write requirement must arrive as its own reviewed, idempotency-aware verb.
 * - Status discipline: 200 validates as a JSON object/array; 401 invalid_key, 403
 *   forbidden, 404 elite_unavailable, 429 rate_limited, 5xx upstream_error, other 4xx
 *   http_<code>, transport failures transport_error (loopback-blocked hosts included).
 *   Raw bodies are parsed internally and never echoed, logged, or stored here.
 *
 * Frontend prohibition (acceptance): every network-performing entry point requires an
 * admin context plus manage_options, so Profile/Account rendering can NEVER trigger an
 * API call — structurally, because no frontend hook exists anywhere in this file.
 *
 * Integration note for card D-14: this file registers nothing on include and wires
 * nothing; consumers instantiate it directly. Until wired, the client is unreachable.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\SecretStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AmeliaApiClient {

	public const CONSTANT_SOURCE_BASE = 'HAL_MEMBER_PROFILES_AMELIA_API_BASE';

	private const BASE_PATH_DEFAULT    = '/wp-json/amelia/v2';
	private const REQUEST_TIMEOUT      = 10;
	private const MAX_RESPONSE_BYTES   = 1048576;

	/**
	 * Read-only connectivity probe against /entities. Returns the same verdict shape as
	 * the resource getters, with reason `connected` on success.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function test_connection(): array {
		$result = self::request( 'GET', 'entities' );

		return $result['ok']
			? array( 'ok' => true, 'reason' => 'connected' )
			: $result;
	}

	/**
	 * Fetches Amelia entities (services/employees catalog side). Read-only.
	 *
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	public static function get_entities(): array {
		return self::request( 'GET', 'entities' );
	}

	/**
	 * Fetches Amelia custom fields definitions. Read-only.
	 *
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	public static function get_fields(): array {
		return self::request( 'GET', 'fields' );
	}

	/**
	 * Resolves the effective base URL: site-derived default, optionally replaced whole
	 * by the wp-config/environment constant. Never reads Request data.
	 *
	 * @return string Base URL without trailing slash.
	 */
	public static function base_url(): string {
		if ( defined( self::CONSTANT_SOURCE_BASE ) ) {
			$override = trim( (string) constant( self::CONSTANT_SOURCE_BASE ) );

			if ( '' !== $override ) {
				return self::finish_base( $override );
			}
		}

		return self::finish_base( self::BASE_PATH_DEFAULT );
	}

	/**
	 * Normalizes either an absolute URL or a root-relative path into a trailing-slash-free
	 * absolute URL anchored on this site.
	 *
	 * @param string $base Absolute URL or root-relative path.
	 * @return string
	 */
	private static function finish_base( string $base ): string {
		if ( preg_match( '#^https?://#i', $base ) ) {
			return rtrim( $base, '/' );
		}

		return rtrim( home_url( '/' . ltrim( $base, '/' ) ), '/' );
	}

	/**
	 * Single guarded read path shared by every public verb.
	 *
	 * @param string $resource 'entities' or 'fields' — the complete allowlist.
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	/**
	 * Integration Closure #4: creates one HAL-owned custom field. Transport-level verb
	 * only — policy (gate/mode/ownership/nonce) is enforced by AmeliaFieldsWriter before
	 * this is ever reached, plus the shared may_call/key guards below.
	 *
	 * @param array<string,mixed> $definition Field definition payload (title/type).
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	public static function create_custom_field( array $definition ): array {
		return self::request(
			'POST',
			'fields',
			wp_json_encode( $definition )
		);
	}

	/**
	 * Integration Closure #4: updates ONE previously HAL-created field by its numeric
	 * Amelia ID. No delete verb exists anywhere in this class by design.
	 *
	 * @param int                 $id         Amelia custom-field ID (ledger-proven).
	 * @param array<string,mixed> $definition Field definition payload.
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	public static function update_custom_field( int $id, array $definition ): array {
		return self::request(
			'POST',
			'fields/' . $id,
			wp_json_encode( $definition )
		);
	}

	/**
	 * Single guarded transport path for every verb (read or write).
	 *
	 * @param string      $method   HTTP verb.
	 * @param string      $resource Resource slug under the API base.
	 * @param string|null $body     Optional JSON body.
	 * @return array{ok:bool, reason:string, data?:array<string,mixed>}
	 */
	private static function request( string $method, string $resource, ?string $body = null ): array {
		if ( ! self::may_call() ) {
			return array( 'ok' => false, 'reason' => 'denied' );
		}

		self::ensure_secret_store_loaded();

		$key = SecretStore::get_amelia_api_key();

		if ( ! is_string( $key ) || '' === $key ) {
			return array( 'ok' => false, 'reason' => 'no_key' );
		}

		$url = self::base_url() . '/' . $resource;

		$args = array(
			'method'              => $method,
			'timeout'             => self::REQUEST_TIMEOUT,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers'             => array(
				'Amelia'      => $key,
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'reason' => 'transport_error',
				'data'   => array(
					// Machine code only (e.g. http_request_failed) — messages may embed
					// environment details we do not propagate.
					'transport_code' => method_exists( $response, 'get_error_code' )
						? (string) $response->get_error_code()
						: '',
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status && 201 !== $status ) {
			return array( 'ok' => false, 'reason' => self::classify_status( $status ) );
		}

		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $parsed ) ) {
			return array( 'ok' => false, 'reason' => 'invalid_payload' );
		}

		return array(
			'ok'     => true,
			'reason' => 'fetched',
			'data'   => $parsed,
		);
	}

	/**
	 * Maps a non-200 status onto the fixed machine-slug vocabulary. Unknown statuses stay
	 * machine-named rather than guessed into a friendlier bucket.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 */
	private static function classify_status( int $status ): string {
		if ( 401 === $status ) {
			return 'invalid_key';
		}

		if ( 403 === $status ) {
			return 'forbidden';
		}

		if ( 404 === $status ) {
			return 'elite_unavailable';
		}

		if ( 429 === $status ) {
			return 'rate_limited';
		}

		if ( $status >= 500 && $status < 600 ) {
			return 'upstream_error';
		}

		if ( $status >= 400 ) {
			return 'http_' . $status;
		}

		return 'unexpected_status';
	}

	/**
	 * Admin-context and capability boundary for EVERY network call — the structural
	 * guarantee behind "no API call while rendering Profile/Account".
	 *
	 * @return bool
	 */
	private static function may_call(): bool {
		return is_admin() && current_user_can( 'manage_options' );
	}

	/**
	 * Guarantees SecretStore exists before its class constants/methods are touched,
	 * because this project ships no autoloader.
	 *
	 * @return void
	 */
	private static function ensure_secret_store_loaded(): void {
		if ( ! class_exists( SecretStore::class ) && defined( 'HAL_MEMBER_PROFILES_DIR' ) ) {
			require_once HAL_MEMBER_PROFILES_DIR . 'includes/SecretStore.php';
		}
	}
}
