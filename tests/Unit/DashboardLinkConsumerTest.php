<?php
/**
 * Card C8 consumer contract: the ONE callback this plugin registers on the external HAL
 * Frontend Dashboard's public link-contract filter (hal_frontend_dashboard_profile_links),
 * registered exactly once with accepted_args = 2. Every test here exercises the real
 * registered callback through the real filter registry: identity matching and mismatch,
 * no duplication (double fire, double registration), preservation of foreign and
 * malformed items, fail-closed dependency gates, and no leftover state after the call.
 *
 * Readiness under the adopted context-derived design: the invocation of the Dashboard's
 * own contract filter IS the proof that the consumption point exists and is live at that
 * moment, so inside this context the provider needs NO manual arming of the two owned
 * gate filters (hal_member_profiles_frontend_dashboard_detected/ready) at all — the old
 * "not-ready ⇒ hidden at consumer level" case no longer exists as such. Not-ready remains
 * a PROVIDER-level concept OUTSIDE this context, where the two filters decide (default
 * false ⇒ closed; a proven integration bridge arming them ⇒ open).
 *
 * The appended item carries exactly the published Dashboard contract keys
 * {id, label, url, eligible}: the renderer requires a valid string label on every link
 * item, and the delivered URL is byte-identical to UM's own accessor value — the
 * provider trims and normalizes nothing (a whitespace-padded accessor value fails
 * closed instead).
 *
 * This fixture proves the LOCAL consumer side of the documented contract only. The real
 * HAL Frontend Dashboard renderer (its own repository) remains the authorized-environment
 * proof for the leader's handover: no external code is included, contacted, or simulated
 * here beyond the documented filter invocation pattern — the incoming list plus the
 * viewer identity, passed as exactly two arguments.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\Integrations\OptionalIntegrations;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class DashboardLinkConsumerTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'      => $GLOBALS['wp_stubs']['options'] ?? array(),
			'filters'      => $GLOBALS['__filters'] ?? array(),
			'users'        => $GLOBALS['wp_stubs']['users'] ?? array(),
			'current_user' => $GLOBALS['wp_stubs']['current_user'] ?? 0,
		);

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']      = $this->backed_up['options'];
		$GLOBALS['__filters']                = $this->backed_up['filters'];
		$GLOBALS['wp_stubs']['users']        = $this->backed_up['users'];
		$GLOBALS['wp_stubs']['current_user'] = $this->backed_up['current_user'];

		// The stub's current-filter stack is balanced by construction; reset it
		// defensively so no test can leak an innermost tag into the next test.
		$GLOBALS['__current_filter'] = array();

		$this->reset_bootstrap();
		$this->reset_consumer_guard();

		foreach ( array( 'um_roles', 'um_statuses', 'um_status_by_user', 'current_user', 'um_profile_url' ) as $key ) {
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
	 * Resets the consumer's registration guard (a static class property, so the fixture
	 * can reset it exactly like AdminDashboard::$registered) and clears any previous
	 * registration of the contract filter, so every registration test starts from a
	 * verifiable zero state.
	 */
	private function reset_consumer_guard(): void {
		$guard = new \ReflectionProperty( OptionalIntegrations::class, 'dashboard_link_consumer_registered' );
		$guard->setAccessible( true );
		$guard->setValue( null, false );

		unset( $GLOBALS['__filters']['hal_frontend_dashboard_profile_links'] );
	}

	/**
	 * Registers the consumer from a provable zero state: guard reset, registry key
	 * cleared, then ONE registration call — the same registrar the production boot
	 * wiring calls.
	 */
	private function register_fresh(): void {
		$this->reset_consumer_guard();

		OptionalIntegrations::register_dashboard_link_consumer();
	}

	/**
	 * Boots Bootstrap on a frontend (non-admin) request and returns the live instance.
	 * The production wiring inside boot() is what registers the consumer here — the
	 * tests below exercise the filter through that real registration path.
	 */
	private function boot_frontend(): Bootstrap {
		$this->reset_bootstrap();

		Bootstrap::init();

		$b = Bootstrap::instance();

		$this->assertInstanceOf( Bootstrap::class, $b );

		return $b;
	}

	/**
	 * Seeds one approved member plus UM's own role/status catalog and the C1
	 * three-persona mapping — the minimum the owned persona verdict needs.
	 */
	private function seed_owner( int $owner_id = 5, string $role = 'role_lawyer', string $status = 'approved' ): void {
		$GLOBALS['wp_stubs']['users']       = array(
			$owner_id => array( 'roles' => array( $role ), 'display_name' => 'Owner Name' ),
		);
		$GLOBALS['wp_stubs']['um_roles']    = array(
			'role_lawyer'     => 'Lawyer',
			'role_consultant' => 'Consultant',
			'role_member'     => 'Member',
		);
		$GLOBALS['wp_stubs']['um_statuses'] = array( 'approved' => 'Approved' );

		if ( '' !== $status ) {
			$GLOBALS['wp_stubs']['um_status_by_user'] = array( $owner_id => $status );
		}

		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ] = array(
			'um_persona_mapping' => array(
				Settings::PERSONA_LAWYER          => array( 'role' => 'role_lawyer', 'status' => 'approved' ),
				Settings::PERSONA_CONSULTANT      => array( 'role' => 'role_consultant', 'status' => 'approved' ),
				Settings::PERSONA_ORDINARY_MEMBER => array( 'role' => 'role_member', 'status' => 'approved' ),
			),
		);
	}

	/** Arms only the presence signal: the external Dashboard is detected. */
	private function mark_dashboard_detected(): void {
		add_filter(
			'hal_member_profiles_frontend_dashboard_detected',
			static function () {
				return true;
			}
		);
	}

	/** Arms both signals: the detected Dashboard's extension contract is ready. */
	private function mark_dashboard_ready(): void {
		$this->mark_dashboard_detected();
		add_filter(
			'hal_member_profiles_frontend_dashboard_ready',
			static function () {
				return true;
			}
		);
	}

	/**
	 * A representative foreign list exactly as a renderer would pass it: well-formed
	 * items with their own ids, used as the untouched baseline in the tests below.
	 *
	 * @return array
	 */
	private function foreign_list(): array {
		return array(
			array( 'id' => 'dashboard.home', 'title' => 'Dashboard', 'url' => 'https://stub.test/dash/', 'eligible' => true ),
			array( 'id' => 'dashboard.billing', 'title' => 'Billing', 'url' => 'https://stub.test/billing/' ),
		);
	}

	/**
	 * The registration contract: exactly ONE callback, with accepted_args = 2 (the list
	 * AND the viewer identity), and a double registration that never stacks.
	 */
	public function test_registration_is_exactly_once_with_two_arguments(): void {
		$this->register_fresh();

		OptionalIntegrations::register_dashboard_link_consumer(); // Double call must NOT double-register.

		$entries   = $GLOBALS['__filters']['hal_frontend_dashboard_profile_links'] ?? array();
		$total     = 0;
		$accepted  = array();
		$callbacks = array();

		foreach ( $entries as $callbacks_by_priority ) {
			foreach ( $callbacks_by_priority as $entry ) {
				$total++;
				$accepted[]  = $entry['accepted_args'];
				$callbacks[] = $entry['callback'];
			}
		}

		$this->assertSame( 1, $total, 'exactly one registration, once-guarded' );
		$this->assertSame( array( 2 ), $accepted, 'the contract passes the list AND the viewer identity' );
		$this->assertSame( array( array( OptionalIntegrations::class, 'provide_profile_overview_link' ) ), $callbacks );
	}

	/**
	 * The contract invocation itself proves presence and readiness — no manual arming of
	 * the two gate filters anywhere — and the appended item carries exactly the published
	 * contract keys {id, label, url, eligible}: foreign items untouched and in place, no
	 * 'order' key, no legacy 'title' key, no HTML, raw URL only.
	 */
	public function test_contract_invocation_appends_one_data_element_for_matching_identity(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend(); // Production wiring registers the consumer itself. NO gate arming.

		$base = $this->foreign_list();

		$out = apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );

		$this->assertCount( 3, $out, 'exactly one element appended' );
		$this->assertSame( $base[0], $out[0], 'foreign item untouched and in place' );
		$this->assertSame( $base[1], $out[1], 'foreign item untouched and in place' );
		$this->assertSame(
			array(
				'id'       => 'member-profiles.overview',
				'label'    => 'Profile overview',
				'url'      => 'https://stub.test/profile/',
				'eligible' => true,
			),
			$out[2],
			'exactly the published contract keys: translated label, raw URL, real boolean eligible'
		);
		$this->assertSame( true, $out[2]['eligible'], 'eligible is a real boolean true, not a truthy value' );
		$this->assertArrayNotHasKey( 'order', $out[2], 'no order key: the documented default applies' );
		$this->assertArrayNotHasKey( 'title', $out[2], 'the contract key is label, not the legacy title' );
		$this->assertStringNotContainsString( '<a ', (string) $out[2]['label'], 'no HTML: the Dashboard owns rendering' );
		$this->assertStringNotContainsString( '<a ', (string) $out[2]['url'], 'raw URL only: no markup, no esc_url() here' );
	}

	/**
	 * Identity gates remain in force INSIDE the contract context: a mismatched identity,
	 * a visitor, and a zero/null contract identity never append.
	 */
	public function test_identity_mismatch_and_visitor_leave_list_unchanged(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base = $this->foreign_list();

		// The Dashboard is rendering for identity 7 while the server-trusted user is 5.
		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 7 ), 'identity mismatch leaves the list unchanged' );

		// A visitor: server-trusted user 0.
		$GLOBALS['wp_stubs']['current_user'] = 0;
		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 ), 'a visitor never receives the owner-only link' );

		// Degenerate identities passed by the contract itself.
		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 0 ) );
		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, null ) );
	}

	/** A second fire over the already-augmented list adds nothing: no duplication. */
	public function test_double_invocation_does_not_duplicate(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base = $this->foreign_list();

		$first  = apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );
		$second = apply_filters( 'hal_frontend_dashboard_profile_links', $first, 5 );

		$this->assertCount( 3, $first );
		$this->assertSame( $first, $second, 'the second fire is a no-op' );
	}

	/** Foreign and malformed items are preserved untouched; non-lists pass through as-is. */
	public function test_foreign_and_malformed_items_are_preserved(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base = array(
			array( 'id' => 'dashboard.home', 'title' => 'Dashboard', 'url' => 'https://stub.test/dash/' ),
			array( 'no-id' => 'shapeless' ),                       // No id key at all.
			array( 'id' => null, 'title' => 'null id' ),           // Null id.
			'plain-string-item',                                   // Not an array.
			77,                                                    // Not an array.
			array( 'id' => 'dashboard.billing' ),                  // Missing every other key.
		);

		$out = apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );

		$this->assertCount( 7, $out, 'nothing dropped, nothing rebuilt' );
		$this->assertSame( $base, array_slice( $out, 0, 6, true ), 'every foreign item, however shaped, stays untouched and in place' );
		$this->assertSame( 'member-profiles.overview', $out[6]['id'] );

		// An existing overview id in an unexpected object shape is still not duplicated.
		$object_item       = new \stdClass();
		$object_item->id   = 'member-profiles.overview';
		$object_list       = array( $object_item );

		$this->assertSame( $object_list, apply_filters( 'hal_frontend_dashboard_profile_links', $object_list, 5 ), 'an object-shaped overview id is never duplicated' );

		// Non-array incoming values are not this plugin's contract to fix: pass through.
		$this->assertSame( 'not-a-list', apply_filters( 'hal_frontend_dashboard_profile_links', 'not-a-list', 5 ) );
		$this->assertNull( apply_filters( 'hal_frontend_dashboard_profile_links', null, 5 ) );
	}

	/** No Bootstrap: the registered callback fails closed and returns the list unchanged. */
	public function test_missing_bootstrap_leaves_list_unchanged(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->reset_bootstrap(); // The loading contract is NOT up: nothing was booted.
		$this->register_fresh();

		$this->assertNull( Bootstrap::instance(), 'precondition: no Bootstrap instance' );

		$base = $this->foreign_list();

		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 ) );
	}

	/** A booted Bootstrap whose provider slot is null fails closed before any verdict. */
	public function test_missing_provider_leaves_list_unchanged(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b = $this->boot_frontend();

		$provider = new \ReflectionProperty( Bootstrap::class, 'optional_integrations' );
		$provider->setAccessible( true );
		$provider->setValue( $b, null );

		$base = $this->foreign_list();

		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 ), 'a missing provider slot leaves the list unchanged' );
	}

	/** Identity satisfied but the provider's own verdict is null: unchanged. */
	public function test_provider_null_route_leaves_list_unchanged(): void {
		$this->seed_owner();

		// Logged-in identity matches the passed viewer, but holds no approved mapped persona.
		$GLOBALS['wp_stubs']['users'][7]          = array( 'roles' => array( 'role_unmapped' ), 'display_name' => 'Other' );
		$GLOBALS['wp_stubs']['um_status_by_user'] = array( 7 => 'awaiting_review' );
		$GLOBALS['wp_stubs']['current_user']      = 7;

		$this->boot_frontend();

		$base = $this->foreign_list();

		$this->assertSame( $base, apply_filters( 'hal_frontend_dashboard_profile_links', $base, 7 ), 'a null provider verdict only hides the link' );
	}

	/**
	 * P1-2: through the REAL filter path, an eligible user receives the item with NO
	 * manual arming of detected/ready anywhere in this test — the invocation of the
	 * Dashboard's contract filter is itself the presence+readiness proof.
	 */
	public function test_contract_context_proves_availability_without_any_arming(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base = $this->foreign_list();

		$out = apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );

		$this->assertCount( 3, $out, 'the contract context alone proves presence and readiness' );
		$this->assertSame( 'member-profiles.overview', $out[2]['id'] );
		$this->assertSame( 'Profile overview', $out[2]['label'] );

		// Proof that nothing was armed: both owned gate hooks carry zero callbacks.
		$this->assertFalse( has_filter( 'hal_member_profiles_frontend_dashboard_detected' ), 'no arming of the detected gate' );
		$this->assertFalse( has_filter( 'hal_member_profiles_frontend_dashboard_ready' ), 'no arming of the ready gate' );
	}

	/**
	 * P1-2: a direct provider call outside the contract context, with no arming, stays
	 * closed — the context rule opens nothing for calls the Dashboard did not make.
	 */
	public function test_direct_provider_call_outside_the_contract_context_stays_closed(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->assertNotNull( $provider, 'precondition: the provider slot resolves' );

		// No contract context on the call stack and no armed gate filter: fail closed.
		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'outside the context and unarmed, the gate stays closed' );
	}

	/**
	 * P1-2 zero-state proof: after the consumer invocation completes, a subsequent direct
	 * provider call is STILL null, no callback exists on either owned gate hook, and the
	 * stub's current-filter stack is empty — zero activation state outlives the context.
	 */
	public function test_zero_state_after_the_contract_invocation(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$out = apply_filters( 'hal_frontend_dashboard_profile_links', $this->foreign_list(), 5 );

		$this->assertCount( 3, $out, 'precondition: the contract invocation delivered the link' );

		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'no verdict outlives the contract context' );
		$this->assertFalse( has_filter( 'hal_member_profiles_frontend_dashboard_detected' ), 'no callback exists on the detected gate hook' );
		$this->assertFalse( has_filter( 'hal_member_profiles_frontend_dashboard_ready' ), 'no callback exists on the ready gate hook' );
		$this->assertSame( array(), $GLOBALS['__current_filter'], 'the current-filter stack is empty: no context left open' );
	}

	/**
	 * The readiness gates are evaluated inside each invocation, never cached — and since
	 * the adopted context-derived design, readiness INSIDE the Dashboard's contract
	 * invocation is inherent (context-proven): the two owned gate filters are not
	 * consulted there at all, so even an explicitly detected-but-NOT-ready arming changes
	 * nothing (under the previous design this hid the link — that consumer-level case no
	 * longer exists as such). Not-ready remains a PROVIDER-level concept OUTSIDE the
	 * context: default filters false ⇒ null; a manual bridge-arming ⇒ link.
	 */
	public function test_readiness_is_context_proven_inside_the_contract_invocation(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base = $this->foreign_list();

		// Inside the contract context the invocation itself is the proof: even a
		// detected-but-NOT-ready arming is not consulted.
		$this->mark_dashboard_detected();

		$out = apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );

		$this->assertCount( 3, $out, 'inside the contract context, readiness is inherent: the invocation proves it' );
		$this->assertSame( 'member-profiles.overview', $out[2]['id'] );

		// PROVIDER level, OUTSIDE the context: the in-context verdict was not cached —
		// an unready dashboard stays closed on a direct call.
		$provider = Bootstrap::instance()->get_optional_integrations();

		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'outside the context, not-ready stays closed: nothing was cached' );

		// A proven integration bridge arming BOTH signals opens the same direct call.
		$this->mark_dashboard_ready();

		$this->assertSame( 'https://stub.test/profile/', $provider->get_profile_overview_link( 5 ), 'outside the context, manual bridge-arming opens the gate' );
	}

	/** Nothing survives the call: no option, no filter arming, no transient readiness state. */
	public function test_no_leftover_state_after_the_invocation(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$base              = $this->foreign_list();
		$options_before    = $GLOBALS['wp_stubs']['options'];
		$filters_before    = $GLOBALS['__filters'];
		$transients_before = hal_wp_stub_extra( 'transients' );

		apply_filters( 'hal_frontend_dashboard_profile_links', $base, 5 );

		$this->assertSame( $options_before, $GLOBALS['wp_stubs']['options'], 'the consumer writes no option' );
		$this->assertSame( $filters_before, $GLOBALS['__filters'], 'the consumer arms no filter and clears none' );
		$this->assertSame( $transients_before, hal_wp_stub_extra( 'transients' ), 'the consumer stores no transient readiness state' );
	}

	/**
	 * P2: the URL delivered through the contract path is byte-identical to UM's own
	 * accessor value — no trim, no normalization, no transformation — and a
	 * whitespace-padded accessor value fails closed (the list stays unchanged), never
	 * normalized-and-accepted.
	 */
	public function test_delivered_url_is_byte_identical_to_the_accessor_value(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$this->boot_frontend();

		$accessor_value = um_user_profile_url();
		$out            = apply_filters( 'hal_frontend_dashboard_profile_links', $this->foreign_list(), 5 );

		$this->assertCount( 3, $out, 'precondition: the valid accessor value delivers the link' );
		$this->assertSame( $accessor_value, $out[2]['url'], 'byte-identical delivery: no transformation between accessor and contract' );

		// A whitespace-padded accessor value is rejected fail-closed: no link, no
		// trimmed-and-accepted variant.
		$GLOBALS['wp_stubs']['um_profile_url'] = "  https://stub.test/profile/  ";

		$this->assertSame(
			$this->foreign_list(),
			apply_filters( 'hal_frontend_dashboard_profile_links', $this->foreign_list(), 5 ),
			'a whitespace-padded accessor value fails closed on the contract path'
		);
	}
}
