<?php
/**
 * Legacy Account navigation widget retained only for saved Elementor data.
 *
 * @package HAL\MemberProfiles\Elementor\Widgets
 */

namespace HAL\MemberProfiles\Elementor\Widgets;

use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountNavigation extends Widget_Base {

	public function get_name(): string {
		return 'hal_member_profiles_account_navigation';
	}

	public function get_title(): string {
		return __( 'Account Navigation (UM)', 'hal-member-profiles' );
	}

	public function get_icon(): string {
		return 'eicon-nav-menu';
	}

	public function get_categories(): array {
		return array( 'hal-member-profiles' );
	}

	public function get_keywords(): array {
		return array( 'ultimate member', 'um', 'account', 'navigation', 'tabs' );
	}

	/**
	 * Legacy slug retained for saved Elementor documents. C2 makes the Account surface an
	 * atomic UM-owned render, so this split widget is deliberately inert even when older
	 * code instantiates it directly.
	 */
	protected function render(): void {
		return;
	}
}
