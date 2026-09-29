<?php
/**
 * Card C3 render fixture: the owner-only Profile Overview across the three personas and
 * the viewer matrix (owner / other logged-in user / visitor / unauthenticated), plus
 * safe absence. Runs without WordPress against tests/Fixtures/wp-stubs.php — the stubs
 * are transport only; every assertion verifies HAL's own C3 contract decisions.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\ProfileOverview;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class ProfileOverviewTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'      => $GLOBALS['wp_stubs']['options'] ?? array(),
			'hooks'        => $GLOBALS['__hooks'] ?? array(),
			'user_meta'    => $GLOBALS['wp_stubs']['user_meta'] ?? array(),
			'post_meta'    => $GLOBALS['wp_stubs']['post_meta'] ?? array(),
			'users'        => $GLOBALS['wp_stubs']['users'] ?? array(),
			'is_admin'     => hal_wp_stub_extra( 'is_admin' ),
			'can_manage'   => $GLOBALS['wp_stubs']['can_manage'] ?? false,
			'stylesheet'   => hal_wp_stub_extra( 'stylesheet' ),
			'template'     => hal_wp_stub_extra( 'template' ),
			'stylesheet_dir' => hal_wp_stub_extra( 'stylesheet_dir' ),
		);

		$GLOBALS['__hooks'] = array();
		$GLOBALS['wp_stubs']['user_meta_reads'] = array();

		// Frontend profile render: never an admin request.
		hal_wp_stub_extra_set( 'is_admin', false );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']        = $this->backed_up['options'];
		$GLOBALS['__hooks']                    = $this->backed_up['hooks'];
		$GLOBALS['wp_stubs']['user_meta']      = $this->backed_up['user_meta'];
		$GLOBALS['wp_stubs']['post_meta']      = $this->backed_up['post_meta'];
		$GLOBALS['wp_stubs']['users']          = $this->backed_up['users'];
		$GLOBALS['wp_stubs']['user_meta_reads'] = array();
		$GLOBALS['wp_stubs']['can_manage']     = $this->backed_up['can_manage'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		hal_wp_stub_extra_set( 'stylesheet', $this->backed_up['stylesheet'] );
		hal_wp_stub_extra_set( 'template', $this->backed_up['template'] );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->backed_up['stylesheet_dir'] );

		$this->reset_bootstrap();

		remove_all_filters( 'um_get_form_fields' );
		remove_all_filters( 'um_account_page_default_tabs_hook' );
		remove_all_filters( 'um_can_view_field' );

		foreach ( array(
			'is_page', 'profile_id', 'private_map', 'um_roles', 'um_statuses',
			'um_status_by_user', 'current_user', 'um_target', 'can_edit_map',
			'um_builtin_fields',
		) as $key ) {
			unset( $GLOBALS['wp_stubs'][ $key ] );
		}
	}

	private function reset_bootstrap(): void {
		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		if ( class_exists( \HAL\MemberProfiles\AdminDashboard::class ) ) {
			$registered = new \ReflectionProperty( \HAL\MemberProfiles\AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}
	}

	/**
	 * Boots Bootstrap on a frontend (non-admin) render and returns the live instance.
	 */
	private function boot_frontend(): Bootstrap {
		$this->reset_bootstrap();
		Bootstrap::init();

		$b = Bootstrap::instance();

		$this->assertInstanceOf( Bootstrap::class, $b );

		return $b;
	}

	/**
	 * Seeds a live public profile of one persona, UM's role/status catalog, and the C1
	 * three-persona mapping, plus one public and one private profile field.
	 */
	private function seed_live_profile( string $role, int $owner_id = 5 ): void {
		$GLOBALS['wp_stubs']['is_page']      = array( 'user' => true );
		$GLOBALS['wp_stubs']['profile_id']   = $owner_id;
		$GLOBALS['wp_stubs']['users']        = array(
			$owner_id => array( 'roles' => array( $role ), 'display_name' => 'Owner Name' ),
		);
		$GLOBALS['wp_stubs']['private_map']  = array( $owner_id => false );
		$GLOBALS['wp_stubs']['um_roles']     = array(
			'role_lawyer'     => 'Lawyer',
			'role_consultant' => 'Consultant',
			'role_member'     => 'Member',
		);
		$GLOBALS['wp_stubs']['um_statuses']  = array( 'approved' => 'Approved' );
		$GLOBALS['wp_stubs']['um_status_by_user'] = array( $owner_id => 'approved' );
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping' => array(
				Settings::PERSONA_LAWYER          => array( 'role' => 'role_lawyer', 'status' => 'approved' ),
				Settings::PERSONA_CONSULTANT      => array( 'role' => 'role_consultant', 'status' => 'approved' ),
				Settings::PERSONA_ORDINARY_MEMBER => array( 'role' => 'role_member', 'status' => 'approved' ),
			),
		);

		$GLOBALS['wp_stubs']['post_meta'][11]['_um_custom_fields'] = array(
			'specialty'   => array( 'metakey' => 'specialty', 'type' => 'text', 'label' => 'Specialty', 'public' => '1', 'in_profile' => '1' ),
			'home_phone'  => array( 'metakey' => 'home_phone', 'type' => 'text', 'label' => 'Home phone', 'public' => '-1', 'in_profile' => '1' ),
		);

		add_filter(
			'um_get_form_fields',
			static function ( $fields, $form_id ) {
				unset( $fields );
				return get_post_meta( (int) $form_id, '_um_custom_fields', true );
			},
			99,
			2
		);
	}

	/** C3: the three personas each render an owner Overview with the right persona label. */
	public function test_owner_overview_renders_for_all_three_personas(): void {
		$matrix = array(
			Settings::PERSONA_LAWYER          => array( 'role_lawyer', 'Lawyer' ),
			Settings::PERSONA_CONSULTANT      => array( 'role_consultant', 'Legal consultant' ),
			Settings::PERSONA_ORDINARY_MEMBER => array( 'role_member', 'Member' ),
		);

		foreach ( $matrix as $persona => $row ) {
			list( $role, $label ) = $row;

			$this->seed_live_profile( $role );
			$GLOBALS['wp_stubs']['current_user'] = 5;
			$GLOBALS['wp_stubs']['um_target']    = 5;
			$GLOBALS['wp_stubs']['user_meta'][5] = array(
				'specialty'  => 'Contracts',
				'home_phone' => 'secret',
			);

			$this->boot_frontend();

			$html = ProfileOverview::render( 11, 'profile' );

			$this->assertStringContainsString( 'hal-member-profiles-overview', $html, "overview renders for persona {$persona}" );
			$this->assertStringContainsString( 'Owner Name', $html );
			$this->assertStringContainsString( $label, $html, "persona label for {$persona}" );

			// Field values flow only through Policy's typed pipeline: the public field
			// is shown, the private one never — even for the owner viewing himself.
			$this->assertStringContainsString( 'Specialty', $html );
			$this->assertStringContainsString( 'Contracts', $html );
			$this->assertStringNotContainsString( 'Home phone', $html );
			$this->assertStringNotContainsString( 'secret', $html );

			// Professional services placeholder stays closed for professionals only.
			$services_expected = in_array( $persona, array( Settings::PERSONA_LAWYER, Settings::PERSONA_CONSULTANT ), true );
			$this->assertSame( $services_expected, false !== strpos( $html, 'hal-member-profiles-overview__services--closed' ), "services placeholder presence for {$persona}" );

			// No owner-only overview for another viewer on the SAME seeded profile.
			$this->reset_bootstrap();
			$this->seed_live_profile( $role );

			$GLOBALS['wp_stubs']['users'][7] = array( 'roles' => array( 'role_member' ), 'display_name' => 'Other' );
			$GLOBALS['wp_stubs']['current_user'] = 7;
			$GLOBALS['wp_stubs']['um_target']    = 5;
			$GLOBALS['wp_stubs']['user_meta'][5] = array( 'specialty' => 'Contracts' );

			$this->boot_frontend();

			$this->assertSame( '', ProfileOverview::render( 11, 'profile' ), "other logged-in user gets no overview for persona {$persona}" );

			$this->reset_bootstrap();
			$this->seed_live_profile( $role );
			$GLOBALS['wp_stubs']['current_user'] = 0; // Visitor (guest).
			$GLOBALS['wp_stubs']['um_target']    = 5;

			$this->boot_frontend();

			$this->assertSame( '', ProfileOverview::render( 11, 'profile' ), "visitor gets no overview for persona {$persona}" );
		}
	}

	/** C3: unauthenticated + missing dependency both fall back to silence (safe absence). */
	public function test_safe_absence_renders_nothing(): void {
		// Unauthenticated on the profile page.
		$this->seed_live_profile( 'role_lawyer' );
		$GLOBALS['wp_stubs']['current_user'] = 0;
		$GLOBALS['wp_stubs']['um_target']    = 5;

		$this->boot_frontend();

		$this->assertSame( '', ProfileOverview::render( 11, 'profile' ) );

		// Safe absence: no UM core page means ProfileContext resolves nothing — Overview
		// stays silent rather than guessing a context.
		$this->reset_bootstrap();
		$GLOBALS['wp_stubs']['is_page'] = array();

		$this->boot_frontend();

		$this->assertSame( '', ProfileOverview::render( 11, 'profile' ) );
	}

	/** C3: UM privacy verdict stands — a blocked viewer never receives the overview. */
	public function test_um_privacy_blocks_even_owner_view_when_context_unavailable(): void {
		$this->seed_live_profile( 'role_lawyer' );
		$GLOBALS['wp_stubs']['current_user'] = 5;
		$GLOBALS['wp_stubs']['um_target']    = 5;
		// UM's own decision unavailable (throws) — ProfileContext fails closed.
		$GLOBALS['wp_stubs']['private_map']  = '__UNAVAILABLE__';

		$this->boot_frontend();

		$this->assertSame( '', ProfileOverview::render( 11, 'profile' ) );
	}

	/** C3: the overview never embeds HAL Frontend Dashboard functions or wp-admin links. */
	public function test_overview_contains_no_dashboard_or_admin_surfaces(): void {
		$this->seed_live_profile( 'role_consultant' );
		$GLOBALS['wp_stubs']['current_user'] = 5;
		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['user_meta'][5] = array( 'specialty' => 'Corporate' );

		$this->boot_frontend();

		$html = ProfileOverview::render( 11, 'profile' );

		$this->assertNotSame( '', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
		$this->assertStringNotContainsString( 'hal-frontend-dashboard', $html );
		$this->assertStringNotContainsString( 'booking', strtolower( $html ) );
	}
}
