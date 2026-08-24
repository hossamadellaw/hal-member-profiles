<?php
/**
 * HAL Member Profiles — Account template override (Elementor bridge).
 *
 * A narrow, official Child Theme override of Ultimate Member's own account.php, built
 * from the installed UM version's real template structure (verified directly against
 * Ultimate Member's current account.php source: https://github.com/ultimatemember/ultimatemember/blob/master/templates/account.php).
 * It keeps every form/tab hook and wrapper from the original. It does not replace UM's
 * own account.php on disk — Child Theme template overrides simply take priority over it.
 *
 * In valid Account Context this file calls only
 * \HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback() with a
 * callback that reproduces Ultimate Member's own complete native Account pipeline —
 * hidden fields, the account-meta avatar/name blocks, tab navigation (side list and
 * mobile accordion), and each tab's real content via UM()->account()->render_account_tab()
 * (UM's own current method for this, not a hook). The adapter chooses Library or this
 * native path exactly once; never both. If the HAL plugin is inactive, the native
 * pipeline runs directly instead.
 *
 * No CSS, no "Hero" markup, no queries, no privacy logic, and no Amelia logic live here.
 *
 * @var array  $args    Ultimate Member's own template args.
 * @var int    $form_id Ultimate Member's own active Form ID.
 * @var string $mode    Ultimate Member's own profile mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a given Account tab is actually enabled, per UM's own real rule (confirmed
 * directly from UM's current account.php template): custom tabs, tabs with their own
 * 'account_tab_{$id}' option enabled, or the always-on 'general' tab.
 *
 * @param string $id   Tab ID.
 * @param array  $info Tab definition.
 * @return bool
 */
$hal_um_account_tab_enabled = function ( $id, $info ) {
	return isset( $info['custom'] ) || ! empty( UM()->options()->get( 'account_tab_' . $id ) ) || 'general' === $id;
};

/**
 * Reproduces Ultimate Member's own complete native Account pipeline, unchanged — every
 * hook and tab-rendering call Ultimate Member itself relies on, in the same order, with
 * nothing removed. Used as the fallback when HAL is inactive or declines the Elementor
 * Library path, and as the native path when HAL is active but its LayoutContract,
 * template, or mode checks do not pass.
 *
 * @return void
 */
$um_native_inner_pipeline = function () use ( $args, $hal_um_account_tab_enabled ) {
	do_action( 'um_account_page_hidden_fields', $args );
	$tabs        = isset( UM()->account()->tabs ) && is_array( UM()->account()->tabs ) ? UM()->account()->tabs : array();
	$current_tab = UM()->account()->current_tab;
	?>
	<div class="um-account-meta radius-<?php echo esc_attr( UM()->options()->get( 'profile_photocorner' ) ); ?> uimob340-show uimob500-show">
		<div class="um-account-meta-img">
			<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo get_avatar( um_user( 'ID' ), 120 ); ?></a>
		</div>
		<div class="um-account-name">
			<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo esc_html( um_user( 'display_name' ) ); ?></a>
			<div class="um-account-profile-link">
				<a href="<?php echo esc_url( um_user_profile_url() ); ?>" class="um-link"><?php esc_html_e( 'View profile', 'ultimate-member' ); ?></a>
			</div>
		</div>
	</div>

	<div class="um-account-side uimob340-hide uimob500-hide">
		<div class="um-account-meta radius-<?php echo esc_attr( UM()->options()->get( 'profile_photocorner' ) ); ?>">
			<div class="um-account-meta-img uimob800-hide">
				<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo get_avatar( um_user( 'ID' ), 120 ); ?></a>
			</div>
			<div class="um-account-meta-img-b uimob800-show<?php echo wp_is_mobile() ? '' : ' um-tip-' . ( is_rtl() ? 'e' : 'w' ); ?>" title="<?php echo esc_attr( um_user( 'display_name' ) ); ?>">
				<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo get_avatar( um_user( 'ID' ), 120 ); ?></a>
			</div>
			<div class="um-account-name uimob800-hide">
				<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo um_user( 'display_name', 'html' ); ?></a>
				<div class="um-account-profile-link">
					<a href="<?php echo esc_url( um_user_profile_url() ); ?>" class="um-link"><?php esc_html_e( 'View profile', 'ultimate-member' ); ?></a>
				</div>
			</div>
		</div>
		<ul>
			<?php foreach ( $tabs as $id => $info ) : ?>
				<?php if ( ! is_array( $info ) || empty( $info['title'] ) || ! $hal_um_account_tab_enabled( $id, $info ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<li>
					<a data-tab="<?php echo esc_attr( $id ); ?>" href="<?php echo esc_url( UM()->account()->tab_link( $id ) ); ?>" class="um-account-link <?php echo ( $id === $current_tab ) ? 'current' : ''; ?>">
						<span class="um-account-icontip uimob800-show<?php echo wp_is_mobile() ? '' : ' um-tip-' . ( is_rtl() ? 'e' : 'w' ); ?>" title="<?php echo esc_attr( $info['title'] ); ?>">
							<i class="<?php echo esc_attr( $info['icon'] ?? '' ); ?>"></i>
						</span>
						<span class="um-account-icon uimob800-hide"><i class="<?php echo esc_attr( $info['icon'] ?? '' ); ?>"></i></span>
						<span class="um-account-title uimob800-hide"><?php echo esc_html( $info['title'] ); ?></span>
						<span class="um-account-arrow uimob800-hide"><i class="<?php echo is_rtl() ? 'um-faicon-angle-left' : 'um-faicon-angle-right'; ?>"></i></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="um-account-main" data-current_tab="<?php echo esc_attr( $current_tab ); ?>">
		<?php
		/** This action is documented in includes/core/um-actions-profile.php */
		do_action( 'um_before_form', $args );

		foreach ( $tabs as $id => $info ) {
			if ( ! is_array( $info ) || empty( $info['title'] ) || ! $hal_um_account_tab_enabled( $id, $info ) ) {
				continue;
			}
			?>
			<div class="um-account-nav uimob340-show uimob500-show">
				<a href="javascript:void(0);" data-tab="<?php echo esc_attr( $id ); ?>" class="<?php echo ( $id === $current_tab ) ? 'current' : ''; ?>">
					<?php echo esc_html( $info['title'] ); ?>
					<span class="ico"><i class="<?php echo esc_attr( $info['icon'] ?? '' ); ?>"></i></span>
					<span class="arr"><i class="um-faicon-angle-down"></i></span>
				</a>
			</div>
			<div class="um-account-tab um-account-tab-<?php echo esc_attr( $id ); ?>" data-tab="<?php echo esc_attr( $id ); ?>">
				<?php
				$info['with_header'] = true;
				UM()->account()->render_account_tab( $id, $info, $args );
				?>
			</div>
			<?php
		}
		?>
	</div>

	<div class="um-clear"></div>
	<?php
};
?>
<div class="um <?php echo esc_attr( $this->get_class( $mode ) ); ?> um-<?php echo esc_attr( $form_id ); ?> hal-member-profiles-account-template">
	<div class="um-form">
		<form method="post" action="">

			<?php
			if ( class_exists( '\HAL\MemberProfiles\Integrations\AccountLayoutAdapter' ) ) {
				\HAL\MemberProfiles\Integrations\AccountLayoutAdapter::render_or_fallback( $um_native_inner_pipeline );
			} else {
				call_user_func( $um_native_inner_pipeline );
			}
			?>

		</form>

		<?php
		/** This action is documented in includes/core/um-actions-account.php */
		do_action( 'um_after_account_page_load' );
		?>
	</div>
</div>
