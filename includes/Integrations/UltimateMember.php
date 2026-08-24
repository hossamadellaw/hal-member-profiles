<?php
/**
 * The sole renderer of Ultimate Member's own native Profile and Account output — never a
 * reimplementation of it.
 *
 * @package HAL\MemberProfiles\Integrations
 */

namespace HAL\MemberProfiles\Integrations;

use HAL\MemberProfiles\AccountContext;
use HAL\MemberProfiles\ProfileContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltimateMember {

	private ProfileContext $profile_context;
	private AccountContext $account_context;

	/**
	 * Accepted for architectural consistency with Bootstrap's shared-service wiring, and so
	 * Widgets holding this integration can reach the same Context instances via the getters
	 * below. UM's own native hooks read UM's own global state independently of these Context
	 * objects; the calling Widget remains responsible for confirming context validity via
	 * ProfileContext/AccountContext before invoking the render methods here.
	 *
	 * @param ProfileContext $profile_context Shared ProfileContext instance.
	 * @param AccountContext $account_context Shared AccountContext instance.
	 */
	public function __construct( ProfileContext $profile_context, AccountContext $account_context ) {
		$this->profile_context = $profile_context;
		$this->account_context = $account_context;
	}

	/**
	 * @return ProfileContext
	 */
	public function get_profile_context(): ProfileContext {
		return $this->profile_context;
	}

	/**
	 * @return AccountContext
	 */
	public function get_account_context(): AccountContext {
		return $this->account_context;
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_before_header( array $args = array() ): string {
		return $this->capture( 'um_profile_before_header', $args );
	}

	/**
	 * Fires UM's own um_pre_header_editprofile hook — UM's documented action point for the
	 * Edit Profile control and, on sites with those extensions active, Follow/Message controls
	 * from UM's Followers/Messaging extensions attached to the same hook.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_actions( array $args = array() ): string {
		return $this->capture( 'um_pre_header_editprofile', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_header_cover_area( array $args = array() ): string {
		return $this->capture( 'um_profile_header_cover_area', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_header( array $args = array() ): string {
		return $this->capture( 'um_profile_header', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_navbar( array $args = array() ): string {
		return $this->capture( 'um_profile_navbar', $args );
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_menu( array $args = array() ): string {
		return $this->capture( 'um_profile_menu', $args );
	}

	/**
	 * Fires um_profile_content_{$nav} and um_profile_content_{$nav}_{$subnav} for UM's own
	 * currently active tab and subnav, per UM's own template conditions. UM's own registered
	 * callback on um_profile_content_main applies the um_profile_can_view_main filter itself
	 * when $nav is 'main' — firing the hook is what applies that decision; it is never
	 * reimplemented here. Never guesses or overrides which nav/subnav is active.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_content( array $args = array() ): string {
		$nav = $this->active_profile_nav();

		if ( '' === $nav ) {
			return '';
		}

		$output = $this->capture( "um_profile_content_{$nav}", $args );

		$subnav  = get_query_var( 'subnav' ) ? (string) get_query_var( 'subnav' ) : 'default';
		$output .= $this->capture( "um_profile_content_{$nav}_{$subnav}", $args );

		return $output;
	}

	/**
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_footer( array $args = array() ): string {
		return $this->capture( 'um_profile_footer', $args );
	}

	/**
	 * The complete native Profile pipeline, in UM's own documented hook order:
	 * um_profile_before_header, um_profile_header_cover_area, um_profile_header,
	 * um_profile_navbar, um_profile_menu, um_profile_content_{$nav}, um_profile_footer.
	 * This is the single fallback path used when the Elementor Library route is
	 * unavailable or its LayoutContract is invalid.
	 *
	 * @param array $args UM profile template $args, passed through unchanged.
	 * @return string
	 */
	public function render_profile_native_pipeline( array $args = array() ): string {
		return
			$this->render_profile_before_header( $args ) .
			$this->render_profile_header_cover_area( $args ) .
			$this->render_profile_header( $args ) .
			$this->render_profile_navbar( $args ) .
			$this->render_profile_menu( $args ) .
			$this->render_profile_content( $args ) .
			$this->render_profile_footer( $args );
	}

	/**
	 * The Account tab navigation — Ultimate Member's OWN side-navigation markup, reproduced
	 * exactly as UM's current templates/account.php renders it (verified against the
	 * installed UM version's source): the same um-account-side wrapper with its meta block,
	 * the same <ul>/<li>/<a class="um-account-link"> structure, icon/tip/title/arrow spans,
	 * responsive uimob classes, RTL arrow direction, and UM's own real enablement rule: a
	 * tab shows only when it is marked 'custom', or its own 'account_tab_{$id}' option is
	 * enabled, or it is the always-on 'general' tab. A disabled/unconfigured tab is simply
	 * skipped here, never fabricated; no link, icon, order, or permission is invented here.
	 *
	 * @return string
	 */
	public function render_account_tabs(): string {
		if ( ! function_exists( 'UM' ) || ! method_exists( UM()->account(), 'tab_link' ) ) {
			return '';
		}

		$account = UM()->account();
		$tabs    = isset( $account->tabs ) && is_array( $account->tabs ) ? $account->tabs : array();

		if ( empty( $tabs ) ) {
			return '';
		}

		$current_tab = isset( $account->current_tab ) ? (string) $account->current_tab : '';

		ob_start();
		?>
		<div class="um-account-side uimob340-hide uimob500-hide">
			<div class="um-account-meta radius-<?php echo esc_attr( UM()->options()->get( 'profile_photocorner' ) ); ?>">
				<div class="um-account-meta-img uimob800-hide">
					<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo get_avatar( um_user( 'ID' ), 120 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress core get_avatar() output, echoed verbatim by UM's own account.php too. ?></a>
				</div>
				<div class="um-account-meta-img-b uimob800-show<?php echo wp_is_mobile() ? '' : ' um-tip-' . ( is_rtl() ? 'e' : 'w' ); ?>" title="<?php echo esc_attr( um_user( 'display_name' ) ); ?>">
					<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo get_avatar( um_user( 'ID' ), 120 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress core get_avatar() output, echoed verbatim by UM's own account.php too. ?></a>
				</div>
				<div class="um-account-name uimob800-hide">
					<a href="<?php echo esc_url( um_user_profile_url() ); ?>"><?php echo um_user( 'display_name', 'html' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UM's own sanitized 'html' display value, echoed verbatim by UM's own account.php too. ?></a>
					<div class="um-account-profile-link">
						<a href="<?php echo esc_url( um_user_profile_url() ); ?>" class="um-link"><?php esc_html_e( 'View profile', 'ultimate-member' ); ?></a>
					</div>
				</div>
			</div>
			<ul>
				<?php foreach ( $tabs as $id => $info ) : ?>
					<?php if ( ! is_array( $info ) || empty( $info['title'] ) || ! $this->account_tab_enabled( $id, $info ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<li>
						<a data-tab="<?php echo esc_attr( $id ); ?>" href="<?php echo esc_url( (string) $account->tab_link( $id ) ); ?>" class="um-account-link <?php echo ( (string) $id === $current_tab ) ? 'current' : ''; ?>">
							<span class="um-account-icontip uimob800-show<?php echo wp_is_mobile() ? '' : ' um-tip-' . ( is_rtl() ? 'e' : 'w' ); ?>" title="<?php echo esc_attr( (string) $info['title'] ); ?>">
								<i class="<?php echo esc_attr( (string) ( $info['icon'] ?? '' ) ); ?>"></i>
							</span>
							<span class="um-account-icon uimob800-hide"><i class="<?php echo esc_attr( (string) ( $info['icon'] ?? '' ) ); ?>"></i></span>
							<span class="um-account-title uimob800-hide"><?php echo esc_html( (string) $info['title'] ); ?></span>
							<span class="um-account-arrow uimob800-hide"><i class="<?php echo is_rtl() ? 'um-faicon-angle-left' : 'um-faicon-angle-right'; ?>"></i></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Ultimate Member's own real Account tab enablement rule, confirmed directly from UM's
	 * current account.php template: custom tabs, tabs whose own 'account_tab_{$id}' option
	 * is enabled, or the always-on 'general' tab.
	 *
	 * @param string|int $id   Tab ID.
	 * @param array      $info Tab definition.
	 * @return bool
	 */
	private function account_tab_enabled( $id, array $info ): bool {
		return isset( $info['custom'] )
			|| ! empty( UM()->options()->get( 'account_tab_' . $id ) )
			|| 'general' === $id;
	}

	/**
	 * The active Account tab's body/Form — validation, nonces, and conditional logic exactly
	 * as UM renders them, never rebuilt — via UM's own UM()->account()->render_account_tab()
	 * method, confirmed directly from UM's current account.php template. This method, not a
	 * hook, is how UM itself renders each tab's content.
	 *
	 * @return string
	 */
	public function render_account_body(): string {
		if ( ! function_exists( 'UM' ) || ! method_exists( UM()->account(), 'render_account_tab' ) ) {
			return '';
		}

		$account = UM()->account();
		$id      = isset( $account->current_tab ) ? (string) $account->current_tab : '';

		if ( '' === $id || empty( $account->tabs[ $id ] ) || ! is_array( $account->tabs[ $id ] ) ) {
			return '';
		}

		$info                = $account->tabs[ $id ];
		$info['with_header'] = true;

		ob_start();
		$account->render_account_tab( $id, $info, array() );

		return (string) ob_get_clean();
	}

	/**
	 * The complete native Account pipeline: tabs, then the active tab's body/Form. This is
	 * the single fallback path used when the Elementor Library route is unavailable or its
	 * LayoutContract is invalid.
	 *
	 * @return string
	 */
	public function render_account_native_pipeline(): string {
		return $this->render_account_tabs() . $this->render_account_body();
	}

	/**
	 * UM's own currently active Profile nav/tab key, never guessed or overridden.
	 *
	 * @return string
	 */
	private function active_profile_nav(): string {
		if ( function_exists( 'UM' ) && method_exists( UM()->profile(), 'active_tab' ) ) {
			return (string) UM()->profile()->active_tab();
		}

		return '';
	}

	/**
	 * Fires a UM hook inside an output buffer and returns what it produced.
	 *
	 * @param string $hook UM hook name.
	 * @param array  $args Optional args passed through to the hook unchanged.
	 * @return string
	 */
	private function capture( string $hook, array $args = array() ): string {
		ob_start();
		do_action( $hook, $args );

		return (string) ob_get_clean();
	}
}
