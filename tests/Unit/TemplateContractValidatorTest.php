<?php
/**
 * Card F-11: TemplateContractValidator unit tests — acceptance "Validator reports #8413
 * as missing HAL widgets TODAY; a correct template passes; no rendering is triggered by
 * validation", proven locally against the documented #8413 shape (publish + builder +
 * general widgets only) and the guarded documents->get() false-return contract (D-08c).
 *
 * Runs without WordPress against tests/Fixtures/wp-stubs.php — the stubs are transport
 * only; every assertion verifies HAL's own validator decisions. Document/Elementor
 * doubles are injected through the constructor resolver or the stub Plugin::$instance;
 * nothing here touches production data and nothing renders.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Elementor\Widgets\NativeHeader;
use HAL\MemberProfiles\Elementor\Widgets\ProfileBody;
use HAL\MemberProfiles\Elementor\Widgets\ProfileHeaderCompatibility;
use HAL\MemberProfiles\Elementor\Widgets\ProfileNavigation;
use HAL\MemberProfiles\TemplateContractValidator;
use PHPUnit\Framework\TestCase;

final class TemplateContractValidatorTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'posts'              => $GLOBALS['wp_stubs']['posts'] ?? array(),
			'post_meta'          => $GLOBALS['wp_stubs']['post_meta'] ?? array(),
			'elementor_instance' => \Elementor\Plugin::$instance ?? null,
		);

		$GLOBALS['wp_stubs']['posts']     = array();
		$GLOBALS['wp_stubs']['post_meta'] = array();
		\Elementor\Plugin::$instance      = null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['posts']     = $this->backed_up['posts'];
		$GLOBALS['wp_stubs']['post_meta'] = $this->backed_up['post_meta'];
		\Elementor\Plugin::$instance      = $this->backed_up['elementor_instance'];
	}

	/** E-06 alignment lock: the validator's required types ARE the four real get_name() slugs. */
	public function test_required_types_match_the_real_widget_get_name_values(): void {
		$this->assertSame( 'hal_member_profiles_native_header', ( new NativeHeader() )->get_name() );
		$this->assertSame( 'hal_member_profiles_profile_header_compatibility', ( new ProfileHeaderCompatibility() )->get_name() );
		$this->assertSame( 'hal_member_profiles_profile_navigation', ( new ProfileNavigation() )->get_name() );
		$this->assertSame( 'hal_member_profiles_profile_body', ( new ProfileBody() )->get_name() );

		$this->assertSame(
			array(
				'hal_member_profiles_native_header',
				'hal_member_profiles_profile_header_compatibility',
				'hal_member_profiles_profile_navigation',
				'hal_member_profiles_profile_body',
			),
			TemplateContractValidator::REQUIRED_WIDGET_TYPES
		);
	}

	/** Acceptance (a): the documented #8413 shape (publish + builder + general widgets only) fails and names every missing type. */
	public function test_template_matching_documented_8413_shape_fails_and_names_all_missing_types(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		$document  = new FakeTemplateDocument( $this->documented_8413_elements() );
		$validator = new TemplateContractValidator( $this->resolver_returning( $document ) );

		$result = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'], 'a general-widgets-only template must fail the contract' );
		$this->assertFalse( $result['fallback_used'], 'document data was available — no fallback' );
		$this->assertSame( TemplateContractValidator::SOURCE_DOCUMENT_ELEMENTS, $result['data_source'] );
		$this->assertSame( TemplateContractValidator::REASON_CONTRACT_FAILED, $result['reason'] );
		$this->assertSame( TemplateContractValidator::REQUIRED_WIDGET_TYPES, $result['missing'], 'all four required types must be named as missing' );
		$this->assertSame( array(), $result['duplicates'] );

		foreach ( TemplateContractValidator::REQUIRED_WIDGET_TYPES as $type ) {
			$this->assertSame( 0, $result['found'][ $type ] );
		}

		$this->assertTrue( $result['document_facts']['post_found'] );
		$this->assertSame( 'elementor_library', $result['document_facts']['post_type'] );
		$this->assertSame( 'publish', $result['document_facts']['status'] );
		$this->assertSame( 'builder', $result['document_facts']['builder_mode'] );
	}

	/** Acceptance (b): a template carrying all four marker types passes. */
	public function test_template_with_all_four_marker_types_passes(): void {
		$this->seed_facts( 5001, 'elementor_library', 'publish', 'builder' );

		$document  = new FakeTemplateDocument( $this->complete_marker_elements() );
		$validator = new TemplateContractValidator( $this->resolver_returning( $document ) );

		$result = $validator->validate_template( 5001 );

		$this->assertTrue( $result['pass'] );
		$this->assertFalse( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::REASON_CONTRACT_SATISFIED, $result['reason'] );
		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( array(), $result['duplicates'] );

		foreach ( TemplateContractValidator::REQUIRED_WIDGET_TYPES as $type ) {
			$this->assertSame( 1, $result['found'][ $type ] );
		}
	}

	/** Acceptance (c): documents->get() returning false falls back to the documented facts — no fatal, no fake pass. */
	public function test_document_false_falls_back_to_documented_facts_fail_closed(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		$validator = new TemplateContractValidator(
			static function ( int $template_id ) {
				return false; // The documented get() false return (D-08c).
			}
		);

		$result = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'], 'unverified counts must never fake a pass' );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::SOURCE_DOCUMENTED_FACTS, $result['data_source'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_DOCUMENT_UNAVAILABLE, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_COUNTS_UNVERIFIED, $result['reason'], 'good documented facts + unavailable widget data = unverified, still fail' );
		$this->assertSame( TemplateContractValidator::REQUIRED_WIDGET_TYPES, $result['missing'] );
		$this->assertTrue( $result['document_facts']['post_found'] );
		$this->assertSame( 'elementor_library', $result['document_facts']['post_type'] );
		$this->assertSame( 'publish', $result['document_facts']['status'] );
		$this->assertSame( 'builder', $result['document_facts']['builder_mode'] );
	}

	/** The default production path degrades cleanly when Elementor's instance is absent. */
	public function test_default_path_degrades_when_elementor_absent(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		\Elementor\Plugin::$instance = null; // Stub environment: no Elementor booted.

		$validator = new TemplateContractValidator();
		$result    = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_ELEMENTOR_UNAVAILABLE, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_COUNTS_UNVERIFIED, $result['reason'] );
		$this->assertSame( TemplateContractValidator::REQUIRED_WIDGET_TYPES, $result['missing'] );
	}

	/** The default documents->get() false return is guarded exactly per D-08c — never fatal, never a pass. */
	public function test_default_documents_get_false_return_is_guarded(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		$manager                    = new FakeElementorDocumentsManager();
		$manager->next_document     = false; // The documented false|Document return shape.
		$plugin                     = new FakeElementorPlugin();
		$plugin->documents          = $manager;
		\Elementor\Plugin::$instance = $plugin;

		$validator = new TemplateContractValidator();
		$result    = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_DOCUMENT_UNAVAILABLE, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_COUNTS_UNVERIFIED, $result['reason'] );
		$this->assertSame( array( array( 'get', 8413 ) ), $manager->calls, 'documents->get() must be the exact touchpoint, called once' );
	}

	/** A resolved document whose elements data is missing, non-array, or throwing degrades to the documented facts. */
	public function test_document_with_unreadable_elements_data_falls_back(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		// Throwing get_elements_data().
		$validator = new TemplateContractValidator( $this->resolver_returning( new ExplodingTemplateDocument() ) );
		$result    = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_ELEMENTS_UNAVAILABLE, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_COUNTS_UNVERIFIED, $result['reason'] );

		// Document object without any get_elements_data() surface at all.
		$validator = new TemplateContractValidator(
			static function ( int $template_id ) {
				return new \stdClass();
			}
		);
		$result    = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_ELEMENTS_UNAVAILABLE, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_COUNTS_UNVERIFIED, $result['reason'] );
	}

	/** The documented-facts fallback ladder reports each fact failure with its own machine slug — always fail-closed. */
	public function test_fallback_ladder_reports_documented_fact_failures(): void {
		$validator = new TemplateContractValidator(
			static function ( int $template_id ) {
				return false;
			}
		);

		// Invalid template id: never even attempted.
		$result = $validator->validate_template( 0 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::FALLBACK_NOT_ATTEMPTED, $result['fallback_reason'] );
		$this->assertSame( TemplateContractValidator::REASON_INVALID_TEMPLATE_ID, $result['reason'] );

		// Post missing entirely.
		$result = $validator->validate_template( 9999 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertFalse( $result['document_facts']['post_found'] );
		$this->assertSame( TemplateContractValidator::REASON_TEMPLATE_NOT_FOUND, $result['reason'] );
		$this->assertSame( TemplateContractValidator::REQUIRED_WIDGET_TYPES, $result['missing'] );

		// Wrong post type (not the Elementor library CPT).
		$this->seed_facts( 8413, 'page', 'publish', 'builder' );
		$result = $validator->validate_template( 8413 );

		$this->assertFalse( $result['pass'] );
		$this->assertSame( TemplateContractValidator::REASON_NOT_ELEMENTOR_LIBRARY, $result['reason'] );

		// Not published.
		$this->seed_facts( 8414, 'elementor_library', 'draft', 'builder' );
		$result = $validator->validate_template( 8414 );

		$this->assertFalse( $result['pass'] );
		$this->assertSame( TemplateContractValidator::REASON_NOT_PUBLISHED, $result['reason'] );

		// Builder meta absent (not builder-built).
		$this->seed_facts( 8415, 'elementor_library', 'publish', null );
		$result = $validator->validate_template( 8415 );

		$this->assertFalse( $result['pass'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertSame( TemplateContractValidator::REASON_NOT_BUILDER_MODE, $result['reason'] );
		$this->assertSame( '', $result['document_facts']['builder_mode'] );
	}

	/** Acceptance (d): counting is recursive through inner sections/columns, depth-capped, and reports exact counts. */
	public function test_counts_recursively_through_nested_inner_structures(): void {
		$elements = array(
			$this->section(
				array(
					$this->column(
						array(
							$this->section(
								array(
									$this->column(
										array( $this->widget( TemplateContractValidator::WIDGET_PROFILE_NAVIGATION ) ),
										true
									),
								),
								true
							),
							$this->widget( TemplateContractValidator::WIDGET_NATIVE_HEADER ),
						)
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array(
							$this->widget( TemplateContractValidator::WIDGET_HEADER_COMPATIBILITY ),
							$this->widget( TemplateContractValidator::WIDGET_PROFILE_BODY ),
							$this->widget( TemplateContractValidator::WIDGET_PROFILE_BODY ),
							$this->widget( 'heading' ), // General widget: never counted.
						)
					),
				)
			),
		);

		$counts = ( new TemplateContractValidator() )->count_marker_widgets( $elements );

		$this->assertSame(
			array(
				TemplateContractValidator::WIDGET_NATIVE_HEADER          => 1,
				TemplateContractValidator::WIDGET_HEADER_COMPATIBILITY   => 1,
				TemplateContractValidator::WIDGET_PROFILE_NAVIGATION     => 1,
				TemplateContractValidator::WIDGET_PROFILE_BODY           => 2,
			),
			$counts
		);

		// Same tree through the full validation: presence contract passes, the duplicate is surfaced informationally.
		$this->seed_facts( 5002, 'elementor_library', 'publish', 'builder' );
		$document  = new FakeTemplateDocument( $elements );
		$validator = new TemplateContractValidator( $this->resolver_returning( $document ) );
		$result    = $validator->validate_template( 5002 );

		$this->assertTrue( $result['pass'], 'presence contract: every required type found at least once' );
		$this->assertSame( array(), $result['missing'] );
		$this->assertSame( array( TemplateContractValidator::WIDGET_PROFILE_BODY ), $result['duplicates'] );

		// Depth cap: a marker far beyond MAX_ELEMENT_DEPTH is ignored, never a fatal or an infinite walk.
		$reachable = ( new TemplateContractValidator() )->count_marker_widgets(
			$this->nest( array( $this->widget( TemplateContractValidator::WIDGET_NATIVE_HEADER ) ), 10 )
		);
		$this->assertSame( 1, $reachable[ TemplateContractValidator::WIDGET_NATIVE_HEADER ] );

		$capped = ( new TemplateContractValidator() )->count_marker_widgets(
			$this->nest( array( $this->widget( TemplateContractValidator::WIDGET_NATIVE_HEADER ) ), 20 )
		);
		$this->assertSame( 0, $capped[ TemplateContractValidator::WIDGET_NATIVE_HEADER ], 'beyond the defensive depth cap the marker is not counted' );
	}

	/** Acceptance (e): validation triggers no rendering — the only document touchpoint is the data read, buffers untouched. */
	public function test_validation_never_triggers_rendering(): void {
		$this->seed_facts( 8413, 'elementor_library', 'publish', 'builder' );

		$document  = new FakeTemplateDocument( $this->complete_marker_elements() );
		$validator = new TemplateContractValidator( $this->resolver_returning( $document ) );

		$buffer_level = ob_get_level();

		$result = $validator->validate_template( 8413 );

		$this->assertSame( $buffer_level, ob_get_level(), 'validation must not open or close any output buffer' );
		$this->assertSame( array( 'get_elements_data' ), $document->calls, 'the only document call is the data read — no render path touched' );
		$this->assertTrue( $result['pass'] );

		// Static guard: the validator source never references a render or buffering verb.
		$source = file_get_contents( ( new \ReflectionClass( TemplateContractValidator::class ) )->getFileName() );

		$this->assertStringNotContainsString( 'get_builder_content_for_display', $source );
		$this->assertStringNotContainsString( '->render', $source );
		$this->assertStringNotContainsString( 'ob_start', $source );
	}

	private function resolver_returning( object $document ): callable {
		return static function ( int $template_id ) use ( $document ) {
			return $document;
		};
	}

	/** Seeds the documented #8413-style facts through the stub post/meta transport. */
	private function seed_facts( int $id, string $post_type, string $status, ?string $builder_mode ): void {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = $status;

		$GLOBALS['wp_stubs']['posts'][ $id ] = $post;

		if ( null !== $builder_mode ) {
			$GLOBALS['wp_stubs']['post_meta'][ $id ][ TemplateContractValidator::META_EDIT_MODE ] = $builder_mode;
		}
	}

	private function section( array $children, bool $inner = false ): array {
		return array(
			'id'       => uniqid( 'sec' ),
			'elType'   => 'section',
			'isInner'  => $inner,
			'settings' => array(),
			'elements' => $children,
		);
	}

	private function column( array $children, bool $inner = false ): array {
		return array(
			'id'       => uniqid( 'col' ),
			'elType'   => 'column',
			'isInner'  => $inner,
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

	/** Wraps $elements in $levels of inner section > inner column pairs (each level = 2 depth steps). */
	private function nest( array $elements, int $levels ): array {
		for ( $i = 0; $i < $levels; $i++ ) {
			$elements = array( $this->section( array( $this->column( $elements, true ) ), true ) );
		}

		return $elements;
	}

	/** The documented live #8413 shape: real Elementor structure, general widgets only, zero HAL markers. */
	private function documented_8413_elements(): array {
		return array(
			$this->section(
				array(
					$this->column(
						array(
							$this->widget( 'heading' ),
							$this->widget( 'image' ),
						)
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array( $this->widget( 'text-editor' ) )
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array(
							$this->widget( 'button' ),
							$this->widget( 'icon-box' ),
						)
					),
				)
			),
		);
	}

	private function complete_marker_elements(): array {
		return array(
			$this->section(
				array(
					$this->column(
						array( $this->widget( TemplateContractValidator::WIDGET_NATIVE_HEADER ) )
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array( $this->widget( TemplateContractValidator::WIDGET_HEADER_COMPATIBILITY ) )
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array( $this->widget( TemplateContractValidator::WIDGET_PROFILE_NAVIGATION ) )
					),
				)
			),
			$this->section(
				array(
					$this->column(
						array( $this->widget( TemplateContractValidator::WIDGET_PROFILE_BODY ) )
					),
				)
			),
		);
	}
}

/**
 * Document stand-in modeling the single documented read (Document::get_elements_data())
 * with a full call log, so tests can prove validation triggers no other document work.
 */
final class FakeTemplateDocument {

	public array $calls = array();

	private array $elements;

	public function __construct( array $elements ) {
		$this->elements = $elements;
	}

	public function get_elements_data(): array {
		$this->calls[] = 'get_elements_data';

		return $this->elements;
	}
}

/** Document stand-in whose only read explodes, modeling unreadable data. */
final class ExplodingTemplateDocument {

	public array $calls = array();

	public function get_elements_data(): array {
		$this->calls[] = 'get_elements_data';

		throw new \RuntimeException( 'elements data unavailable (test double)' );
	}
}

/** Documents-manager stand-in modeling the documented documents->get() false|Document contract. */
final class FakeElementorDocumentsManager {

	public $next_document = null;

	public array $calls = array();

	public function get( $post_id ) {
		$this->calls[] = array( 'get', $post_id );

		return $this->next_document;
	}
}

/** Plugin stand-in exposing a controllable documents manager through the stub base class. */
final class FakeElementorPlugin extends \Elementor\Plugin {

	public $documents = null;
}
