<?php
/**
 * Uninstall handler for HAL Member Profiles.
 *
 * Deletes ONLY this plugin's own options (hal_member_profiles_settings plus its
 * lifecycle, managed_templates, staging_qa_enabled, and composition_rows options), and
 * only when the settings option's own purge_on_uninstall flag was explicitly enabled by
 * an authorized administrator (default: false, so by default this deletes nothing at
 * all). Never touches Ultimate Member, Amelia, Elementor Library Templates, or any
 * user/Account data.
 *
 * @package HAL\MemberProfiles
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hal_member_profiles_settings = get_option( 'hal_member_profiles_settings' );

if ( ! is_array( $hal_member_profiles_settings ) || empty( $hal_member_profiles_settings['purge_on_uninstall'] ) ) {
	return;
}

delete_option( 'hal_member_profiles_settings' );
delete_option( 'hal_member_profiles_lifecycle' );
delete_option( 'hal_member_profiles_managed_templates' );
delete_option( 'hal_member_profiles_staging_qa_enabled' );
delete_option( 'hal_member_profiles_composition_rows' );
