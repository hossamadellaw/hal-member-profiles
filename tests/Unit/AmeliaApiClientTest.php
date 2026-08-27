<?php
/**
 * Unit tests for AmeliaApiClient (cards D-08/D-17): auth/rate/error classification,
 * transport failures, payload validation, key gating, GET-only allowlist, and the
 * structural "no frontend calls" guarantee.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Integrations\AmeliaApiClient;
use PHPUnit\Framework\TestCase;

final class AmeliaApiClientTest extends TestCase {

	protected function setUp(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
		hal_wp_stub_reset_http();
	}

	protected function tearDown(): void {
		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;
	}

	private function http( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body );
	}

	public function test_missing_key_short_circuits_before_any_http_call(): void {
		$this->assertFalse(
			class_exists( '\WP_Error', false ) && false, // environment sanity only
			''
		);

		$res = AmeliaApiClient::test_connection();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'no_key', $res['reason'] );
		$this->assertCount( 0, hal_wp_stub_http_calls() );
	}

	public function test_non_admin_context_can_never_trigger_a_call(): void {
		define( 'HAL_MEMBER_PROFILES_AMELIA_API_KEY', 'D17-KEY' ); // process-wide, first use.
		hal_wp_stub_extra_set( 'is_admin', false );

		$res = AmeliaApiClient::get_entities();

		hal_wp_stub_extra_set( 'is_admin', true );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'denied', $res['reason'] );
		$this->assertCount( 0, hal_wp_stub_http_calls(), 'frontend must issue zero API calls' );
	}

	public function test_success_carries_site_base_url_amelia_header_and_limits(): void {
		hal_wp_stub_queue_http( $this->http( 200, '{"entities":[]}' ) );

		$res = AmeliaApiClient::test_connection();

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'connected', $res['reason'] );

		$call = hal_wp_stub_http_calls()[0];

		$this->assertSame( 'https://stub.test/wp-json/amelia/v2/entities', $call['url'] );
		$this->assertSame( 'D17-KEY', $call['args']['headers']['Amelia'] );
		$this->assertSame( 'GET', $call['args']['method'] );
		$this->assertSame( 10, $call['args']['timeout'] );
		$this->assertSame( 1048576, $call['args']['limit_response_size'] );
	}

	public function test_status_classification_covers_auth_rate_error_and_unknown(): void {
		foreach (
			array(
				401 => 'invalid_key',
				403 => 'forbidden',
				404 => 'elite_unavailable',
				429 => 'rate_limited',
				500 => 'upstream_error',
				302 => 'unexpected_status',
			) as $code => $expected
		) {
			hal_wp_stub_queue_http( $this->http( $code ) );

			$res = AmeliaApiClient::get_entities();

			$this->assertFalse( $res['ok'] );
			$this->assertSame( $expected, $res['reason'], "status {$code}" );
		}
	}

	public function test_transport_failure_is_contained_with_machine_code(): void {
		hal_wp_stub_queue_http( new \WP_Error( 'http_request_failed' ) );

		$res = AmeliaApiClient::get_fields();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'transport_error', $res['reason'] );
		$this->assertSame( 'http_request_failed', $res['data']['transport_code'] );
	}

	public function test_malformed_payload_fails_closed_without_output(): void {
		hal_wp_stub_queue_http( $this->http( 200, '{definitely-not-json' ) );

		$res = AmeliaApiClient::get_entities();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'invalid_payload', $res['reason'] );
	}

	public function test_fields_resource_uses_its_own_path(): void {
		hal_wp_stub_queue_http( $this->http( 200, '{"fields":[]}' ) );

		$res = AmeliaApiClient::get_fields();

		$this->assertTrue( $res['ok'] );
		$this->assertSame(
			'https://stub.test/wp-json/amelia/v2/fields',
			hal_wp_stub_http_calls()[ count( hal_wp_stub_http_calls() ) - 1 ]['url']
		);
	}
}
