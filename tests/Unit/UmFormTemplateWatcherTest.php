<?php
/**
 * Card F-10: UmFormTemplateWatcher unit tests — acceptance "Notice appears on a template
 * change while readiness is incomplete; zero writes to UM data; notice disappears once
 * readiness completes", plus the ROUND-4 contract requirement: without the F-11 contract
 * element the watcher would declare false readiness on the live template #8413 shape, so
 * the suite re-creates that exact trap (file + gate + mapping all ready, contract element
 * missing or failing on the documented #8413 general-widgets shape) and proves the
 * verdict stays NOT ready with the warning shown.
 *
 * Runs without WordPress against tests/Fixtures/wp-stubs.php — the stubs are transport
 * only; every assertion verifies HAL's own watcher decisions. Readiness is driven through
 * the REAL read-only surfaces (ManagedTemplates::inspect() over a real temp Child Theme
 * directory and the shipped manifest, CompatibilityGate, Settings over stub options, the
 * real TemplateContractValidator with an injected document double). The zero-writes
 * proofs are twofold: (1) a runtime snapshot — the stub environment defines NO post-meta
 * or user-meta write functions at all, so any write attempt would fatal the test process,
 * and the option/transient stores are snapshot-compared byte-for-byte; (2) a source scan
 * pinning that the watcher file never gains a write call or a write endpoint.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\AdminDashboard;
use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\CompatibilityGate;
use HAL\MemberProfiles\ManagedTemplates;
use HAL\MemberProfiles\Settings;
use HAL\MemberProfiles\TemplateContractValidator;
use HAL\MemberProfiles\UmFormTemplateWatcher;
use PHPUnit\Framework\TestCase;

final class UmFormTemplateWatcherTest extends TestCase {

	/** @var array<string,mixed> Global snapshot restored by tearDown(). */
	private array $backed_up = array();

	/** @var string|null Temporary active Child Theme directory. */
	private ?string $theme = null;

	/** @var string[] Temporary files created by a test (removed in tearDown). */
	private array $paths = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'stubs'       => $GLOBALS['wp_stubs'],
			'stubs_extra' => $GLOBALS['wp_stubs_extra'],
			'hooks'       => $GLOBALS['__hooks'] ?? array(),
		);

		$GLOBALS['wp_stubs'] = array_merge(
			$this->backed_up['stubs'],
			array(
				'options'         => array(),
				'post_meta'       => array(),
				'posts'           => array(),
				'user_meta'       => array(),
				'user_meta_reads' => array(),
				'um_roles'        => array(),
				'um_statuses'     => array(),
			)
		);
		$GLOBALS['__hooks'] = array();

		hal_wp_stub_extra_set( 'is_admin', true );
		$GLOBALS['wp_stubs']['can_manage'] = true;

		$this->theme = sys_get_temp_dir() . '/hal_f10_' . uniqid();
		@mkdir( $this->theme . '/ultimate-member/templates', 0777, true );

		hal_wp_stub_extra_set( 'stylesheet', 'child-f10' );
		hal_wp_stub_extra_set( 'template', 'parent' );
		hal_wp_stub_extra_set( 'stylesheet_dir', $this->theme );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}

		@rmdir( $this->theme . '/ultimate-member/templates' );
		@rmdir( $this->theme . '/ultimate-member' );
		@rmdir( $this->theme );

		hal_wp_stub_extra_set( 'stylesheet', '' );
		hal_wp_stub_extra_set( 'template', '' );
		hal_wp_stub_extra_set( 'stylesheet_dir', '' );

		$GLOBALS['wp_stubs']       = $this->backed_up['stubs'];
		$GLOBALS['wp_stubs_extra'] = $this->backed_up['stubs_extra'];
		$GLOBALS['__hooks']        = $this->backed_up['hooks'];
	}

	/** Acceptance (a): a qualifying template switch while readiness is incomplete shows the warning. */
	public function test_template_switch_with_incomplete_readiness_shows_the_warning(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );

		$watcher = new UmFormTemplateWatcher(
			$this->production_gate(),
			new Settings( $this->production_gate() ),
			new ManagedTemplates()
		);

		$this->assertSame( '', $this->render_notice( $watcher ), 'nothing renders before any qualifying event' );

		$watcher->on_updated_post_meta( 101, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$evaluation = $watcher->get_last_evaluation();

		$this->assertFalse( $evaluation['ready'] );
		$this->assertFalse( $evaluation['file']['ready'], 'empty Child Theme: bridge file not provisioned' );
		$this->assertSame( 'target_not_current', $evaluation['file']['reason'] );
		$this->assertFalse( $evaluation['gate']['ready'], '1.2.x production registry is empty — gate closed' );
		$this->assertSame( 'awaiting_matrix_signoff', $evaluation['gate']['reason'] );
		$this->assertFalse( $evaluation['mapping']['ready'], 'no um_persona_mapping configured' );
		$this->assertFalse( $evaluation['contract']['ready'], 'no validated profile library template' );

		$this->assertTrue( $watcher->is_notice_pending() );

		$out = $this->render_notice( $watcher );

		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( 'is-dismissible', $out );
		$this->assertStringContainsString( '#3621', $out, 'the form ID is surfaced for rollback' );
		$this->assertStringContainsString( 'profile-hal-member-profiles', $out, 'the new template value is surfaced' );
		$this->assertStringContainsString( 'awaiting_matrix_signoff', $out, 'the failing gate reason is surfaced' );
		$this->assertStringContainsString( 'Purge All (LiteSpeed', $out, 'ROUND-4 cache rule embedded in the notice' );
		$this->assertStringContainsString( 'Ultimate Member &gt; Forms', $out, 'rollback guidance' );
		$this->assertStringContainsString( 'This is a warning only', $out );
	}

	/** Acceptance (b): the switch path and the notice perform ZERO writes to UM/plugin data. */
	public function test_switch_and_notice_perform_zero_writes_to_um_data(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array( 'profile_library_template_id' => 0 );

		$watcher = new UmFormTemplateWatcher(
			$this->production_gate(),
			new Settings( $this->production_gate() ),
			new ManagedTemplates()
		);

		$before_stubs = serialize( $GLOBALS['wp_stubs'] );
		$before_extra = serialize( $GLOBALS['wp_stubs_extra'] );

		$watcher->on_updated_post_meta( 101, 3621, '_um_profile_template', 'profile-hal-member-profiles' );
		$this->render_notice( $watcher );

		$this->assertSame(
			$before_stubs,
			serialize( $GLOBALS['wp_stubs'] ),
			'no tracked state may change: options, post meta, user meta (not even stub read counters)'
		);
		$this->assertSame(
			$before_extra,
			serialize( $GLOBALS['wp_stubs_extra'] ),
			'no transients or extra stub state may change'
		);

		$this->assertSame(
			array( 'profile_library_template_id' => 0 ),
			get_option( Settings::OPTION_KEY, array() ),
			'the HAL settings option is untouched'
		);
		$this->assertFalse(
			get_option( ManagedTemplates::STATE_OPTION, false ),
			'the managed-template provisioning state is untouched'
		);

		// Source scan pin: the watcher file must never gain a write call or a write
		// endpoint. (The stub environment defines no post-meta/user-meta write functions
		// at all, so a runtime call would have fataled this test already.)
		$src = (string) file_get_contents( (string) ( new \ReflectionClass( UmFormTemplateWatcher::class ) )->getFileName() );

		$forbidden = array(
			'update_post_meta(',
			'update_metadata(',
			'add_post_meta(',
			'delete_post_meta(',
			'delete_metadata(',
			'update_option(',
			'delete_option(',
			'update_user_meta(',
			'update_user_metadata(',
			'delete_user_meta(',
			'delete_user_metadata(',
			'set_transient(',
			'delete_transient(',
			'wp_update_post(',
			'wp_insert_post(',
			'wp_delete_post(',
			'wp_trash_post(',
			'register_rest_route(',
			'add_settings_error(',
			'wp_ajax',
			'admin_post',
		);

		foreach ( $forbidden as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'the watcher must never gain a write path: ' . $token );
		}
	}

	/** Acceptance (c): once readiness completes, the pending warning is gone and a ready switch never warns. */
	public function test_notice_disappears_once_readiness_completes(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );

		$gate     = $this->approved_gate();
		$settings = new Settings( $gate );
		$watcher  = new UmFormTemplateWatcher( $gate, $settings, new ManagedTemplates(), $this->passing_validator_factory() );

		// Start incomplete (file missing, mapping empty, no validated library template).
		$watcher->on_updated_post_meta( 101, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$this->assertTrue( $watcher->is_notice_pending(), 'incomplete readiness must warn' );

		// Complete readiness through the very same read-only surfaces: provisioned file,
		// complete mapping, validated library template. The gate was approved all along.
		$this->provision_bridge_file_into_theme();
		$this->configure_complete_mapping_and_template( 5001 );
		$this->seed_library_template_facts( 5001 );

		$evaluation = $watcher->recheck();

		$this->assertTrue( $evaluation['ready'] );
		$this->assertTrue( $evaluation['file']['ready'] );
		$this->assertSame( 'provisioned_current', $evaluation['file']['reason'] );
		$this->assertTrue( $evaluation['gate']['ready'] );
		$this->assertSame( 'approved_composition', $evaluation['gate']['reason'] );
		$this->assertTrue( $evaluation['mapping']['ready'] );
		$this->assertSame( 'configured', $evaluation['mapping']['reason'] );
		$this->assertTrue( $evaluation['contract']['ready'] );
		$this->assertSame( 'contract_satisfied', $evaluation['contract']['reason'] );

		$this->assertTrue( $watcher->is_ready() );
		$this->assertFalse( $watcher->is_notice_pending(), 'notice disappears once readiness completes' );
		$this->assertSame( '', $this->render_notice( $watcher ) );

		// A fresh qualifying switch while fully ready never warns either:
		$watcher->on_updated_post_meta( 102, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$this->assertFalse( $watcher->is_notice_pending() );
		$this->assertSame( '', $this->render_notice( $watcher ) );
	}

	/** Acceptance (d): other meta keys, other post types, and missing posts never even evaluate. */
	public function test_other_meta_keys_and_post_types_do_not_trigger_anything(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );
		$this->seed_post( 77, 'page', 'publish' );

		$watcher = new UmFormTemplateWatcher(
			$this->production_gate(),
			new Settings( $this->production_gate() ),
			new ManagedTemplates()
		);

		$watcher->on_updated_post_meta( 201, 3621, '_um_profile_css', 'anything' );

		$this->assertSame( array(), $watcher->get_last_evaluation(), 'other meta keys must not even evaluate' );
		$this->assertFalse( $watcher->is_notice_pending() );
		$this->assertSame( '', $this->render_notice( $watcher ) );

		$watcher->on_updated_post_meta( 202, 77, '_um_profile_template', 'profile-hal-member-profiles' );

		$this->assertSame( array(), $watcher->get_last_evaluation(), 'other post types must not even evaluate' );
		$this->assertFalse( $watcher->is_notice_pending() );
		$this->assertSame( '', $this->render_notice( $watcher ) );

		$watcher->on_updated_post_meta( 203, 0, '_um_profile_template', 'profile-hal-member-profiles' );

		$this->assertSame( array(), $watcher->get_last_evaluation(), 'a missing post must not evaluate' );
		$this->assertFalse( $watcher->is_notice_pending() );
		$this->assertSame( '', $this->render_notice( $watcher ) );
	}

	/** ROUND-4 trap, seam variant: a degraded deploy without the F-11 validator class stays NOT ready on the otherwise-complete #8413 environment. */
	public function test_contract_validator_absence_keeps_the_8413_shape_not_ready(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );
		$this->seed_library_template_facts( 8413 );
		$this->configure_complete_mapping_and_template( 8413 );
		$this->provision_bridge_file_into_theme();

		$gate     = $this->approved_gate();
		$settings = new Settings( $gate );

		// The test seam models class_exists( TemplateContractValidator ) === false: the
		// factory resolves to null exactly like the official path would on a degraded deploy.
		$watcher = new UmFormTemplateWatcher(
			$gate,
			$settings,
			new ManagedTemplates(),
			static function () {
				return null;
			}
		);

		$watcher->on_updated_post_meta( 301, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$evaluation = $watcher->get_last_evaluation();

		// File + gate + mapping all READY — this is exactly the false-readiness shape the
		// contract element exists to prevent (live #8413: published, builder, general widgets).
		$this->assertTrue( $evaluation['file']['ready'] );
		$this->assertTrue( $evaluation['gate']['ready'] );
		$this->assertTrue( $evaluation['mapping']['ready'] );

		// ...but the missing contract element keeps the whole verdict NOT ready (fail-closed).
		$this->assertFalse( $evaluation['contract']['ready'] );
		$this->assertSame( 'validator_unavailable', $evaluation['contract']['reason'] );
		$this->assertFalse( $evaluation['ready'], 'false readiness on the #8413 shape must stay impossible' );

		$this->assertTrue( $watcher->is_notice_pending() );
		$this->assertStringContainsString( 'notice-warning', $this->render_notice( $watcher ) );
	}

	/** ROUND-4 trap, data variant: the documented #8413 general-widgets template fails the contract and stays NOT ready. */
	public function test_documented_8413_general_widgets_template_fails_the_contract(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );
		$this->seed_library_template_facts( 8413 );
		$this->configure_complete_mapping_and_template( 8413 );
		$this->provision_bridge_file_into_theme();

		$gate     = $this->approved_gate();
		$settings = new Settings( $gate );

		$document  = new FakeWatcherTemplateDocument( $this->documented_8413_elements() );
		$validator = new TemplateContractValidator(
			static function ( int $template_id ) use ( $document ) {
				return $document;
			}
		);

		$watcher = new UmFormTemplateWatcher(
			$gate,
			$settings,
			new ManagedTemplates(),
			static function () use ( $validator ) {
				return $validator;
			}
		);

		$watcher->on_updated_post_meta( 302, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$evaluation = $watcher->get_last_evaluation();

		$this->assertTrue( $evaluation['file']['ready'] );
		$this->assertTrue( $evaluation['gate']['ready'] );
		$this->assertTrue( $evaluation['mapping']['ready'] );
		$this->assertFalse( $evaluation['contract']['ready'] );
		$this->assertSame( 'contract_failed', $evaluation['contract']['reason'] );
		$this->assertFalse( $evaluation['ready'], 'the #8413 shape must stay NOT ready' );

		$this->assertTrue( $watcher->is_notice_pending() );
		$this->assertStringContainsString( 'contract (contract_failed)', $this->render_notice( $watcher ) );
	}

	/** Acceptance (f): the dismissal is the core client-side hide and writes nothing anywhere. */
	public function test_dismissal_is_client_side_and_writes_nothing(): void {
		$this->seed_post( 3621, 'um_form', 'publish' );

		$watcher = new UmFormTemplateWatcher(
			$this->production_gate(),
			new Settings( $this->production_gate() ),
			new ManagedTemplates()
		);

		$watcher->on_updated_post_meta( 401, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$before_stubs = serialize( $GLOBALS['wp_stubs'] );
		$before_extra = serialize( $GLOBALS['wp_stubs_extra'] );

		$out = $this->render_notice( $watcher );

		$this->assertStringContainsString( 'is-dismissible', $out, 'the dismiss button is the CORE client-side hide' );
		$this->assertStringNotContainsString( '<form', $out, 'the notice must not embed any form' );
		$this->assertStringNotContainsString( 'admin-post.php', $out, 'no write endpoint anywhere' );
		$this->assertStringNotContainsString( '_wpnonce', $out, 'no nonce round-trip anywhere' );

		$this->assertSame( $before_stubs, serialize( $GLOBALS['wp_stubs'] ), 'rendering the dismissible notice writes nothing' );
		$this->assertSame( $before_extra, serialize( $GLOBALS['wp_stubs_extra'] ), 'rendering the dismissible notice writes nothing' );
	}

	/** Wiring: Bootstrap arms exactly the two hooks on the shared watcher instance. */
	public function test_bootstrap_wires_the_watcher_with_the_shared_instances(): void {
		$GLOBALS['wp_stubs']['options'] = array(
			Settings::OPTION_KEY => array(
				'managed_templates_consent' => true,
				'amelia_sync_mode'          => 'discover_only',
			),
		);

		if ( class_exists( AdminDashboard::class ) ) {
			$registered = new \ReflectionProperty( AdminDashboard::class, 'registered' );
			$registered->setAccessible( true );
			$registered->setValue( null, false );
		}

		$instance = new \ReflectionProperty( Bootstrap::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		Bootstrap::init();

		$b       = Bootstrap::instance();
		$watcher = $b->get_um_form_template_watcher();

		$this->assertInstanceOf( UmFormTemplateWatcher::class, $watcher );

		$updated = array();

		foreach ( $GLOBALS['__hooks']['updated_post_meta'] ?? array() as $entry ) {
			if ( is_array( $entry['callback'] ) && $entry['callback'][0] === $watcher && 'on_updated_post_meta' === $entry['callback'][1] ) {
				$updated = $entry;
			}
		}

		$this->assertNotSame( array(), $updated, 'updated_post_meta must be armed by Bootstrap' );
		$this->assertSame( 4, $updated['accepted_args'], 'official 4-argument signature (D-05)' );

		$armed = false;

		foreach ( $GLOBALS['__hooks']['admin_notices'] ?? array() as $entry ) {
			if ( is_array( $entry['callback'] ) && $entry['callback'][0] === $watcher && 'render_admin_notice' === $entry['callback'][1] ) {
				$armed = true;
			}
		}

		$this->assertTrue( $armed, 'admin_notices must be armed by Bootstrap' );

		$ref = new \ReflectionClass( UmFormTemplateWatcher::class );

		foreach (
			array(
				'compatibility_gate' => 'get_compatibility_gate',
				'settings'           => 'get_settings',
				'managed_templates'  => 'get_managed_templates',
			) as $prop => $getter
		) {
			$p      = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$shared = $b->{$getter}();

			$this->assertNotNull( $shared, "Bootstrap must own the shared {$prop}" );
			$this->assertSame(
				spl_object_hash( $shared ),
				spl_object_hash( $p->getValue( $watcher ) ),
				"the watcher must consume the SHARED {$prop} instance, not a private clone"
			);
		}

		// End-to-end hook path through the stub dispatcher (readiness is incomplete in the
		// stub env, so the switch must leave the warning pending and rendered):
		$this->seed_post( 3621, 'um_form', 'publish' );

		do_action( 'updated_post_meta', 501, 3621, '_um_profile_template', 'profile-hal-member-profiles' );

		$this->assertTrue( $watcher->is_notice_pending(), 'the armed hook must answer a qualifying switch' );

		ob_start();
		$watcher->render_admin_notice();

		$this->assertStringContainsString( 'notice-warning', (string) ob_get_clean() );
	}

	/**
	 * Seeds one post through the stub transport.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @return \WP_Post
	 */
	private function seed_post( int $id, string $post_type, string $status ): \WP_Post {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = $status;

		$GLOBALS['wp_stubs']['posts'][ $id ] = $post;

		return $post;
	}

	/**
	 * Copies the REAL managed bridge source into the temporary Child Theme target so the
	 * shipped manifest inspection reports the asset CURRENT (hash-verified by inspect()).
	 *
	 * @return string The copied target path.
	 */
	private function provision_bridge_file_into_theme(): string {
		$source = HAL_MEMBER_PROFILES_PLUGIN_DIR . 'resources/ultimate-member/profile-hal-member-profiles.php';
		$target = $this->theme . '/ultimate-member/templates/profile-hal-member-profiles.php';

		$this->assertTrue( (bool) copy( $source, $target ), 'test fixture: copying the bridge source must succeed' );
		$this->assertTrue( hash_file( 'sha256', $target ) === '4505034e70093d3d8922e7ddb7f846ead6645ad54cfe863a1aeb687dce5633d0', 'test fixture: copy must match the shipped manifest digest' );

		$this->paths[] = $target;

		return $target;
	}

	/**
	 * The production-shaped gate: versions present, no signed rows — exactly the 1.2.x
	 * state where describe() reads awaiting_matrix_signoff.
	 *
	 * @return CompatibilityGate
	 */
	private function production_gate(): CompatibilityGate {
		return new CompatibilityGate(
			array(
				'wp'        => '6.5.5',
				'php'       => '8.2.0',
				'um'        => '2.8.0',
				'elementor' => '3.21.0',
			)
		);
	}

	/**
	 * A gate with one signed row matching the environment — the approved-composition state.
	 *
	 * @return CompatibilityGate
	 */
	private function approved_gate(): CompatibilityGate {
		$versions = array(
			'wp'        => '6.5.5',
			'php'       => '8.2.0',
			'um'        => '2.8.0',
			'elementor' => '3.21.0',
		);

		return new CompatibilityGate(
			$versions,
			array(
				CompatibilityGate::CAP_PROFILE => array(
					array(
						'matrix_row' => 'unit-fixture-row-1',
						'signed'     => true,
						'versions'   => $versions,
					),
				),
			)
		);
	}

	/**
	 * A validator factory returning a validator that passes for ANY template id (document
	 * double carries all four marker types).
	 *
	 * @return callable
	 */
	private function passing_validator_factory(): callable {
		$document  = new FakeWatcherTemplateDocument( $this->complete_marker_elements() );
		$validator = new TemplateContractValidator(
			static function ( int $template_id ) use ( $document ) {
				return $document;
			}
		);

		return static function () use ( $validator ) {
			return $validator;
		};
	}

	/**
	 * Completes the mapping (all three personas, valid against the stub UM providers) and
	 * points the profile library template at the given id.
	 *
	 * @param int $template_id Library template post id to configure.
	 * @return void
	 */
	private function configure_complete_mapping_and_template( int $template_id ): void {
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'profile_library_template_id' => $template_id,
			'um_persona_mapping'          => array(
				'lawyer'          => array( 'role' => 'um_lawyer', 'status' => 'approved' ),
				'consultant'      => array( 'role' => 'um_consultant', 'status' => 'approved' ),
				'ordinary_member' => array( 'role' => 'um_member', 'status' => 'approved' ),
			),
		);

		$GLOBALS['wp_stubs']['um_roles'] = array(
			'um_lawyer'     => 'Lawyer',
			'um_consultant' => 'Consultant',
			'um_member'     => 'Member',
		);

		$GLOBALS['wp_stubs']['um_statuses'] = array(
			'approved' => 'Approved',
			'awaiting' => 'Awaiting review',
		);
	}

	/**
	 * Seeds the documented library-template facts (D-08a/D-08b) for one id.
	 *
	 * @param int $template_id Library template post id.
	 * @return void
	 */
	private function seed_library_template_facts( int $template_id ): void {
		$this->seed_post( $template_id, 'elementor_library', 'publish' );

		$GLOBALS['wp_stubs']['post_meta'][ $template_id ][ TemplateContractValidator::META_EDIT_MODE ] = 'builder';
	}

	/**
	 * Renders the watcher's admin notice into a string.
	 *
	 * @param UmFormTemplateWatcher $watcher Watcher under test.
	 * @return string
	 */
	private function render_notice( UmFormTemplateWatcher $watcher ): string {
		ob_start();
		$watcher->render_admin_notice();

		return (string) ob_get_clean();
	}

	private function section( array $children ): array {
		return array(
			'id'       => uniqid( 'sec' ),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(),
			'elements' => $children,
		);
	}

	private function column( array $children ): array {
		return array(
			'id'       => uniqid( 'col' ),
			'elType'   => 'column',
			'isInner'  => false,
			'settings' => array(),
			'elements' => $children,
		);
	}

	private function widget( string $widget_type ): array {
		return array(
			'id'         => uniqid( 'wgt' ),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => array(),
			'elements'   => array(),
		);
	}

	/** All four required marker types, one occurrence each. */
	private function complete_marker_elements(): array {
		return array(
			$this->section( array( $this->column( array( $this->widget( TemplateContractValidator::WIDGET_NATIVE_HEADER ) ) ) ) ),
			$this->section( array( $this->column( array( $this->widget( TemplateContractValidator::WIDGET_HEADER_COMPATIBILITY ) ) ) ) ),
			$this->section( array( $this->column( array( $this->widget( TemplateContractValidator::WIDGET_PROFILE_NAVIGATION ) ) ) ) ),
			$this->section( array( $this->column( array( $this->widget( TemplateContractValidator::WIDGET_PROFILE_BODY ) ) ) ) ),
		);
	}

	/** The documented live #8413 shape: real Elementor structure, general widgets only, zero HAL markers. */
	private function documented_8413_elements(): array {
		return array(
			$this->section( array( $this->column( array( $this->widget( 'heading' ), $this->widget( 'image' ) ) ) ) ),
			$this->section( array( $this->column( array( $this->widget( 'text-editor' ) ) ) ) ),
			$this->section( array( $this->column( array( $this->widget( 'button' ), $this->widget( 'icon-box' ) ) ) ) ),
		);
	}
}

/**
 * Document stand-in modeling the single documented read (Document::get_elements_data()).
 * Named separately from TemplateContractValidatorTest's double so both files can coexist
 * in one full-suite process.
 */
final class FakeWatcherTemplateDocument {

	/** @var array */
	private array $elements;

	public function __construct( array $elements ) {
		$this->elements = $elements;
	}

	public function get_elements_data(): array {
		return $this->elements;
	}
}
