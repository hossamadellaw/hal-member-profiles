<?php
/**
 * Non-sensitive test fixtures for HAL Member Profiles. Every value here is synthetic —
 * no real user data, no real site content, no real API keys or secrets.
 */

/**
 * A synthetic Ultimate Member custom-field definition array, shaped like a real
 * _um_custom_fields entry, for FieldSchema/Policy tests. No real site field names.
 *
 * @return array
 */
function hal_member_profiles_fixture_um_fields(): array {
	return array(
		'favorite_color' => array(
			'type'   => 'text',
			'title'  => 'Favorite Color',
			'public' => '1', // Everyone.
		),
		'personal_site'  => array(
			'type'   => 'url',
			'title'  => 'Personal Site',
			'public' => '2', // Members.
		),
		'gallery_photo'  => array(
			'type'   => 'image',
			'title'  => 'Gallery Photo',
			'public' => '1', // Everyone.
		),
		'hobbies'        => array(
			'type'   => 'multiselect',
			'title'  => 'Hobbies',
			'public' => '-2', // Specific roles only (official string).
			'roles'  => array( 'subscriber' ),
		),
		'user_password'  => array(
			'type'   => 'password',
			'title'  => 'Password',
			'public' => '1',
		),
		'secret_note'    => array(
			'type'   => 'text',
			'title'  => 'Secret Note',
			'public' => '-3', // Owner + specific roles (official string).
			'roles'  => array( 'editor' ),
		),
		'unsupported_field' => array(
			'type'   => 'oembed',
			'title'  => 'Unsupported Field',
			'public' => '1',
		),
	);
}

/**
 * A synthetic Amelia service catalog shape, for Amelia integration tests. Fake IDs and
 * names only — never a real site's actual employee/service mapping.
 *
 * @return array
 */
function hal_member_profiles_fixture_amelia_catalog(): array {
	return array(
		101 => array(
			'name'         => 'Sample Consultation',
			'legacy_names' => array( 'consultation' ),
		),
		102 => array(
			'name' => 'Sample Follow-up',
		),
	);
}

/**
 * HTTP transport interception helpers for unit tests (card D-17). Loaded unconditionally
 * by the bootstrap so they're always available to any test file.
 */
$GLOBALS['wp_stubs']['http_queue'] = array();
$GLOBALS['wp_stubs']['http_calls'] = array();

if ( ! function_exists( 'hal_wp_stub_queue_http' ) ) {
	function hal_wp_stub_queue_http( $response ): void {
		$GLOBALS['wp_stubs']['http_queue'][] = $response;
	}
}

if ( ! function_exists( 'hal_wp_stub_http_calls' ) ) {
	function hal_wp_stub_http_calls(): array {
		return $GLOBALS['wp_stubs']['http_calls'] ?? array();
	}
}

if ( ! function_exists( 'hal_wp_stub_reset_http' ) ) {
	function hal_wp_stub_reset_http(): void {
		$GLOBALS['wp_stubs']['http_queue'] = array();
		$GLOBALS['wp_stubs']['http_calls'] = array();
	}
}

/**
 * WordPress HTTP API + error stubs for development-phase unit tests (card D-17).
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		return array_shift( $GLOBALS['wp_stubs']['http_queue'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): string {
		return is_array( $response ) ? (string) ( $response['response']['code'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
