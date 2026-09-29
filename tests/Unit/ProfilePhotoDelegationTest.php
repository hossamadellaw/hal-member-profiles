<?php
/**
 * Card C4 / F-03 fixture: UM-native photo delegation. Verifies HAL's own contract
 * decisions — owner-only edit action, privacy-gated display through Policy's verified
 * avatar pipeline, UM-absent safe absence, and the hook-registration assertion that this
 * integration registers NO write hook of its own. Stubs are transport only.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\Integrations\UltimateMember;
use HAL\MemberProfiles\ProfileContext;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class ProfilePhotoDelegationTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'        => $GLOBALS['wp_stubs']['options'] ?? array(),
			'hooks'          => $GLOBALS['__hooks'] ?? array(),
			'filters'        => $GLOBALS['__filters'] ?? array(),
			'user_meta'      => $GLOBALS['wp_stubs']['user_meta'] ?? array(),
			'post_meta'      => $GLOBALS['wp_stubs']['post_meta'] ?? array(),
			'users'          => $GLOBALS['wp_stubs']['users'] ?? array(),
			'is_admin'       => hal_wp_stub_extra( 'is_admin' ),
			'can_manage'     => $GLOBALS['wp_stubs']['can_manage'] ?? false,
			'stylesheet'     => hal_wp_stub_extra( 'stylesheet' ),
			'stylesheet_dir' => hal_wp_stub_extra( 'stylesheet_dir' ),
			'template'       => hal_wp_stub_extra( 'template' ),
		);

		$GLOBALS['__hooks']   = array();
		$GLOBALS['__filters'] = array();
		hal_wp_stub_extra_set( 'is_admin', false );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']   = $this->backed_up['options'];
		$GLOBALS['__hooks']               = $this->backed_up['hooks'];
		$GLOBALS['__filters']             = $this->backed_up['filters'];
		$GLOBALS['wp_stubs']['user_meta'] = $this->backed_up['user_meta'];
		$GLOBALS['wp_stubs']['post_meta'] = $this->backed_up['post_meta'];
		$GLOBALS['wp_stubs']['users']     = $this->backed_up['users'];
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );
		hal_wp_stub_extra_set( 'stylesheet', $this->backed_up['stylesheet'] );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->backed_up['stylesheet_dir'] );
		hal_wp_stub_extra_set( 'template', $this->backed_up['template'] );

		$this->reset_bootstrap();

		remove_all_filters( 'um_get_form_fields' );
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

	private function boot_frontend(): Bootstrap {
		$this->reset_bootstrap();
		Bootstrap::init();

		$b = Bootstrap::instance();
		$this->assertInstanceOf( Bootstrap::class, $b );

		return $b;
	}

	/**
	 * Boots with UM present, seeds form 77 with a profile_photo field of the given
	 * privacy, sets the transport viewer, and returns the UltimateMember integration.
	 */
	private function booted_um( int $viewer_id, string $photo_privacy = '1' ): UltimateMember {
		$GLOBALS['wp_stubs']['is_page'] = array( 'user' => true );

		$GLOBALS['wp_stubs']['post_meta'][77]['_um_custom_fields'] = array(
			'profile_photo' => array( 'type' => 'image', 'title' => 'Profile photo', 'public' => $photo_privacy ),
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

		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['current_user'] = $viewer_id;

		return $this->boot_frontend()->get_um_integration();
	}

	/** C4: the owner sees his own photo through Policy's verified avatar verdict. */
	public function test_owner_sees_photo_through_policy_pipeline(): void {
		$um = $this->booted_um( 5 );

		$html = $um->render_profile_photo( 5, 5, 77, 'lawyer' );

		// get_avatar_url stub yields the escaped URL inside Policy's typed image value.
		$this->assertStringContainsString( 'hal-member-profiles-photo', $html );
		$this->assertStringContainsString( 'https://stub.test/avatar/5.png', $html );
	}

	/** C4: a visitor (guest) may see the public photo; display stays privacy-gated. */
	public function test_visitor_public_photo_renders_privacy_permitting(): void {
		$um = $this->booted_um( 0 );

		$html = $um->render_profile_photo( 5, 0, 77, 'lawyer' );

		$this->assertStringContainsString( 'https://stub.test/avatar/5.png', $html );
	}

	/** C4: unresolvable context (missing form) is safe absence — no guessed image. */
	public function test_missing_form_id_is_safe_absence(): void {
		$um = $this->booted_um( 5 );

		$this->assertSame( '', $um->render_profile_photo( 5, 5, 0, 'lawyer' ) );
	}

	/** C4: edit action renders ONLY for the owner — other users and guests get ''. */
	public function test_edit_action_is_owner_only(): void {
		$um = $this->booted_um( 5 );

		// Non-owner viewers never even reach the hook capture.
		$this->assertSame( '', $um->render_profile_photo_edit_action( array(), false ) );

		// The owner path captures UM's own hook. With no extension attached to
		// um_pre_header_editprofile in this transport, the capture is empty — which is
		// exactly UM-absent safe absence (HAL substitutes nothing).
		$this->assertSame( '', $um->render_profile_photo_edit_action( array(), true ) );

		// With a UM-side callback present (as UM itself attaches in a live install),
		// the owner sees UM's own control verbatim — and only the owner.
		\add_action( 'um_pre_header_editprofile', static function (): void {
			echo 'UM-EDIT-CONTROL';
		} );

		$this->assertSame( 'UM-EDIT-CONTROL', $um->render_profile_photo_edit_action( array(), true ) );
		$this->assertSame( '', $um->render_profile_photo_edit_action( array(), false ) );
	}

	/**
	 * C4 hook-registration assertion: the photo delegation registers NO write hook of
	 * its own across the whole bootstrap — no upload, no profile update, no save filter.
	 */
	public function test_no_hal_write_hook_is_registered(): void {
		$this->booted_um( 5 );

		$forbidden = array(
			'profile_update', 'personal_options_update', 'edit_user_profile_update',
			'um_user_before_updating_profile', 'um_user_after_updating_profile',
			'wp_handle_upload', 'add_attachment', 'um_after_user_upload',
		);

		foreach ( $forbidden as $hook ) {
			$this->assertArrayNotHasKey(
				$hook,
				$GLOBALS['__hooks'],
				"card C4 must not register the write hook {$hook}"
			);
		}
	}

	/** C4: UM absent → the whole delegated surface collapses safely to ''. */
	public function test_um_absence_hides_everything_safely(): void {
		$this->reset_bootstrap();
		$GLOBALS['wp_stubs']['is_page'] = array( 'user' => true );

		// No um_is_core_page → ProfileContext resolves nothing → no photo, no edit.
		$this->boot_frontend();

		$um = Bootstrap::instance()->get_um_integration();
		$this->assertInstanceOf( UltimateMember::class, $um );

		$this->assertSame( '', $um->render_profile_photo( 5, 5, 77, 'lawyer' ) );
	}

	/** C4: private profile field keeps gating the photo through Policy, not around it. */
	public function test_private_avatar_privacy_gates_display(): void {
		// The form's profile_photo field is defined private (-1): owner-or-editors only.
		$um = $this->booted_um( 5, '-1' );

		$GLOBALS['wp_stubs']['um_target']    = 5;
		$GLOBALS['wp_stubs']['current_user'] = 9; // Another logged-in user, not the owner.

		$this->assertSame( '', $um->render_profile_photo( 5, 9, 77, 'lawyer' ), 'private photo hidden from another user' );

		// The owner still sees his own photo through the same pipeline.
		$GLOBALS['wp_stubs']['current_user'] = 5;
		$html = $um->render_profile_photo( 5, 5, 77, 'lawyer' );
		$this->assertStringContainsString( 'https://stub.test/avatar/5.png', $html );
	}
}
