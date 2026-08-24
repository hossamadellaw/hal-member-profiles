<?php
/**
 * The single source of truth for the Ultimate Member account belonging to the current user.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountContext {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolves the account being viewed, or null when there is no valid, permitted context.
	 *
	 * @return object|null
	 */
	public function resolve(): ?object {
		if ( $this->is_elementor_editor() ) {
			return $this->resolve_editor_fixture();
		}

		return $this->resolve_live_account();
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
	 * Resolves the live account context for the currently authenticated member only.
	 *
	 * Ultimate Member's own Account class already blocks guests via its
	 * template_redirect restriction before this template renders; is_user_logged_in()
	 * below is an additional, deliberately conservative safety net, not the primary gate.
	 *
	 * @return object|null
	 */
	private function resolve_live_account(): ?object {
		if ( ! function_exists( 'um_is_core_page' ) || ! um_is_core_page( 'account' ) ) {
			return null;
		}

		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		$account_user = get_userdata( $user_id );

		if ( ! $account_user instanceof \WP_User ) {
			return null;
		}

		return (object) array(
			'account_user'      => $account_user,
			'current_tab'       => $this->current_tab(),
			'is_editor_preview' => false,
		);
	}

	/**
	 * Resolves a safe fixture account, only inside the Elementor editor for a manage_options user.
	 *
	 * Never falls back to the current site manager's own account when no fixture is set.
	 *
	 * @return object|null
	 */
	private function resolve_editor_fixture(): ?object {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$fixture_id = $this->settings->get_account_fixture_user_id();

		if ( null === $fixture_id ) {
			return null;
		}

		$account_user = get_userdata( $fixture_id );

		if ( ! $account_user instanceof \WP_User ) {
			return null;
		}

		return (object) array(
			'account_user'      => $account_user,
			'current_tab'       => $this->current_tab(),
			'is_editor_preview' => true,
		);
	}

	/**
	 * Reads the active Account tab Ultimate Member has already resolved.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		if ( function_exists( 'UM' ) && isset( UM()->account()->current_tab ) ) {
			return (string) UM()->account()->current_tab;
		}

		return 'general';
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
