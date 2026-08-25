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
