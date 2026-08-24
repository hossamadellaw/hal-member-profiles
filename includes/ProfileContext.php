<?php
/**
 * The single source of truth for who a public Ultimate Member profile page belongs to.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileContext {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolves the profile being viewed, or null when there is no valid, permitted context.
	 *
	 * Ultimate Member's own routing already enforces the "Profile Privacy" setting before
	 * its profile template loads; the is_private_profile() check below is an additional,
	 * deliberately conservative safety net here, not the primary access decision.
	 *
	 * @param array  $args    Raw $args the UM profile template received.
	 * @param int    $form_id UM form ID the profile template received.
	 * @param string $mode    UM profile mode the profile template received.
	 * @return object|null
	 */
	public function resolve( array $args = array(), int $form_id = 0, string $mode = '' ): ?object {
		if ( $this->is_elementor_editor() ) {
			return $this->resolve_editor_fixture( $form_id, $mode );
		}

		return $this->resolve_live_profile( $form_id, $mode );
	}

	/**
	 * Whether the current request is rendering inside the Elementor editor canvas.
	 *
	 * @return bool
	 */
	public function is_editor_preview(): bool {
		return $this->is_elementor_editor();
	}

	/**
	 * Resolves the live, public-facing profile context.
	 *
	 * @param int    $form_id UM form ID.
	 * @param string $mode    UM profile mode.
	 * @return object|null
	 */
	private function resolve_live_profile( int $form_id, string $mode ): ?object {
		if ( ! function_exists( 'um_is_core_page' ) || ! um_is_core_page( 'user' ) ) {
			return null;
		}

		if ( ! function_exists( 'um_profile_id' ) ) {
			return null;
		}

		$target_id = (int) um_profile_id();

		if ( $target_id <= 0 ) {
			return null;
		}

		$target_user = get_userdata( $target_id );

		if ( ! $target_user instanceof \WP_User ) {
			return null;
		}

		$visitor_id = get_current_user_id();
		$is_owner   = $visitor_id > 0 && $visitor_id === $target_id;

		if ( $this->is_private_profile( $target_id ) && ! $is_owner && ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		return (object) array(
			'target_user' => $target_user,
			'visitor_id'  => $visitor_id,
			'is_owner'    => $is_owner,
			'is_edit'     => function_exists( 'um_is_on_edit_profile' ) && um_is_on_edit_profile(),
			'is_preview'  => $this->is_um_preview(),
			'form_id'     => $form_id,
			'mode'        => $mode,
		);
	}

	/**
	 * Resolves a safe fixture context, only inside the Elementor editor for a manage_options user.
	 *
	 * @param int    $form_id UM form ID.
	 * @param string $mode    UM profile mode.
	 * @return object|null
	 */
	private function resolve_editor_fixture( int $form_id, string $mode ): ?object {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$fixture_id = $this->settings->get_profile_fixture_user_id();

		if ( null === $fixture_id ) {
			return null;
		}

		$target_user = get_userdata( $fixture_id );

		if ( ! $target_user instanceof \WP_User ) {
			return null;
		}

		return (object) array(
			'target_user' => $target_user,
			'visitor_id'  => get_current_user_id(),
			'is_owner'    => false,
			'is_edit'     => false,
			'is_preview'  => true,
			'form_id'     => $form_id,
			'mode'        => $mode,
		);
	}

	/**
	 * Checks Ultimate Member's own profile privacy flag for a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function is_private_profile( int $user_id ): bool {
		if ( ! function_exists( 'UM' ) || ! method_exists( UM()->user(), 'is_private_profile' ) ) {
			return false;
		}

		return (bool) UM()->user()->is_private_profile( $user_id );
	}

	/**
	 * Whether Ultimate Member itself considers this a preview render.
	 *
	 * @return bool
	 */
	private function is_um_preview(): bool {
		return function_exists( 'UM' ) && isset( UM()->user()->preview ) && UM()->user()->preview;
	}

	/**
	 * Whether the current request is inside the Elementor editor canvas (edit or preview iframe).
	 *
	 * @return bool
	 */
	private function is_elementor_editor(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) || null === \Elementor\Plugin::$instance ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		return ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() )
			|| ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
	}
}
