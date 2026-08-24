<?php
/**
 * HAL Member Profiles — alternative Profile template (Elementor bridge).
 *
 * An OPTIONAL alternative to Ultimate Member's own profile.php, built from the installed
 * UM version's real template structure (matching this site's existing
 * ultimate-member/templates/profile-hossam-legal.php wrappers, outer hooks, and
 * edit/preview behavior exactly). It does not replace, modify, or delete the original
 * profile.php or profile-hossam-legal.php — both remain untouched and selectable.
 *
 * Activation: select "HAL Member Profiles" as the template for the relevant Ultimate
 * Member Profile Form(s) from Ultimate Member > Forms, manually, after staging QA — this
 * file does not select itself automatically.
 *
 * No CSS, no "Hero" markup, no queries, no privacy logic, and no Amelia logic live here —
 * all of that belongs to the plugin's own PHP classes. In Public Profile this file never
 * calls um_profile_header_cover_area, um_profile_header, um_profile_navbar, um_profile_menu,
 * or um_profile_content_* directly; it calls only
 * \HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback() with a
 * callback that reproduces Ultimate Member's own complete native pipeline. If the HAL
 * plugin is inactive, that native pipeline runs directly instead.
 *
 * @var array  $args    Ultimate Member's own template args.
 * @var int    $form_id Ultimate Member's own active Form ID.
 * @var string $mode    Ultimate Member's own profile mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$description_key = UM()->profile()->get_show_bio_key( $args );

/**
 * Reproduces Ultimate Member's own complete native Profile pipeline, unchanged — every
 * hook Ultimate Member itself and its active extensions rely on, in the same order, inside
 * the same conditions, with nothing removed. Used as the fallback when HAL is inactive or
 * declines the Elementor Library path, and as the native path when HAL is active but its
 * LayoutContract, template, or mode checks do not pass.
 *
 * @return void
 */
$um_native_inner_pipeline = function () use ( $args ) {
	do_action( 'um_profile_before_header', $args );
	do_action( 'um_profile_header_cover_area', $args );
	do_action( 'um_profile_header', $args );
	?>
	<div class="um-profile-navbar <?php echo esc_attr( apply_filters( 'um_profile_navbar_classes', '' ) ); ?>">
		<?php do_action( 'um_profile_navbar', $args ); ?>
		<div class="um-clear"></div>
	</div>
	<?php
	do_action( 'um_profile_menu', $args );

	if ( um_is_on_edit_profile() || UM()->user()->preview ) {
		$nav    = 'main';
		$subnav = UM()->profile()->active_subnav();
		$subnav = ! empty( $subnav ) ? $subnav : 'default';
		?>
		<div class="um-profile-body <?php echo esc_attr( $nav . ' ' . $nav . '-' . $subnav ); ?>">
			<?php
			do_action( "um_profile_content_{$nav}", $args );
			do_action( "um_profile_content_{$nav}_{$subnav}", $args );
			?>
			<div class="clear"></div>
		</div>
		<?php
	} else {
		$menu_enabled = UM()->options()->get( 'profile_menu' );
		$profile_tabs = UM()->profile()->tabs_active();
		$nav          = UM()->profile()->active_tab();
		$subnav       = UM()->profile()->active_subnav();
		$subnav       = ! empty( $subnav ) ? $subnav : 'default';

		if ( $menu_enabled || ! empty( $profile_tabs[ $nav ]['hidden'] ) ) {
			?>
			<div class="um-profile-body <?php echo esc_attr( $nav . ' ' . $nav . '-' . $subnav ); ?>">
				<?php
				do_action( "um_profile_content_{$nav}", $args );
				do_action( "um_profile_content_{$nav}_{$subnav}", $args );
				?>
				<div class="clear"></div>
			</div>
			<?php
		}
	}

	do_action( 'um_profile_footer', $args );
};
?>
<div class="um <?php echo esc_attr( $this->get_class( $mode ) ); ?> um-<?php echo esc_attr( $form_id ); ?> um-role-<?php echo esc_attr( um_user( 'role' ) ); ?> hal-member-profiles-template">
	<div class="um-form" data-mode="<?php echo esc_attr( $mode ); ?>" data-form_id="<?php echo esc_attr( $form_id ); ?>">

		<?php if ( um_is_on_edit_profile() ) : ?>
			<form method="post" action="" data-description_key="<?php echo esc_attr( $description_key ); ?>">
		<?php endif; ?>

		<?php
		if ( class_exists( '\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter' ) ) {
			\HAL\MemberProfiles\Integrations\ProfileLayoutAdapter::render_or_fallback(
				$um_native_inner_pipeline,
				is_array( $args ) ? $args : array(),
				(int) $form_id,
				(string) $mode
			);
		} else {
			call_user_func( $um_native_inner_pipeline );
		}
		?>

		<?php if ( um_is_on_edit_profile() && ! UM()->user()->preview ) : ?>
			</form>
		<?php endif; ?>

	</div>
</div>
