<?php
/**
 * Card C8 link-contract fixture: the owned, machine-consumable Profile Overview link
 * provider across the full visibility matrix — present / absent / not-ready /
 * invalid-route / unauthorized / visitor, plus fail-closed detection signals. Runs
 * without WordPress against tests/Fixtures/wp-stubs.php — the stubs are transport only;
 * every assertion verifies HAL's own C8 contract decisions.
 *
 * This fixture proves the LOCAL provider side of the documented contract only. The
 * external HAL Frontend Dashboard HAS since published its public link-contract filter
 * (hal_frontend_dashboard_profile_links — its renderer requires a valid string label on
 * every link item); the consumer side of that contract is proven in
 * DashboardLinkConsumerTest, where presence and readiness are context-proven inside the
 * contract invocation. The external renderer itself (its own repository) remains the
 * authorized-environment proof for the leader's handover: no external code is included,
 * contacted, or simulated here. Every test below calls the provider DIRECTLY — i.e.
 * OUTSIDE the contract context, where the two owned gate filters decide.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\Bootstrap;
use HAL\MemberProfiles\Dependencies;
use HAL\MemberProfiles\Integrations\OptionalIntegrations;
use HAL\MemberProfiles\Settings;
use PHPUnit\Framework\TestCase;

final class DashboardLinkContractTest extends TestCase {

	private array $backed_up = array();

	protected function setUp(): void {
		$this->backed_up = array(
			'options'    => $GLOBALS['wp_stubs']['options'] ?? array(),
			'hooks'      => $GLOBALS['__hooks'] ?? array(),
			'filters'    => $GLOBALS['__filters'] ?? array(),
			'users'      => $GLOBALS['wp_stubs']['users'] ?? array(),
			'is_admin'   => hal_wp_stub_extra( 'is_admin' ),
			'can_manage' => $GLOBALS['wp_stubs']['can_manage'] ?? false,
		);

		// Frontend request: never an admin render.
		hal_wp_stub_extra_set( 'is_admin', false );

		if ( ! defined( 'UM_VERSION' ) ) {
			define( 'UM_VERSION', '2.8.0' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wp_stubs']['options']    = $this->backed_up['options'];
		$GLOBALS['__hooks']                = $this->backed_up['hooks'];
		$GLOBALS['__filters']              = $this->backed_up['filters'];
		$GLOBALS['wp_stubs']['users']      = $this->backed_up['users'];
		$GLOBALS['wp_stubs']['can_manage'] = $this->backed_up['can_manage'];
		hal_wp_stub_extra_set( 'is_admin', $this->backed_up['is_admin'] );

		// The stub's current-filter stack is balanced by construction; reset it
		// defensively so no test can leak an innermost tag into the next test.
		$GLOBALS['__current_filter'] = array();

		$this->reset_bootstrap();

		foreach ( array(
			'um_roles', 'um_statuses', 'um_status_by_user', 'current_user',
			'private_map', 'is_page', 'profile_id', 'um_target', 'um_profile_url',
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
	 * Boots Bootstrap on a frontend (non-admin) request and returns the live instance,
	 * exactly as any production consumer of the provider would reach it.
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
	 * three-persona mapping — the minimum the owned persona verdict needs. No profile
	 * field, form, or template state: the link contract consumes none of it.
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

	/** C8 present: ready Dashboard + authorized owner + valid route => exactly ONE link, as data. */
	public function test_present_returns_single_link_for_authorized_owner(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->assertNotNull( $provider, 'the provider is reachable through the established loading contract' );

		// Both signals armed: the external Dashboard is present and its contract ready.
		$this->mark_dashboard_ready();

		ob_start();
		$link   = $provider->get_profile_overview_link( 5 );
		$echoed = ob_get_clean();

		// One link as data: nothing is echoed, no form/navigation/link markup is rendered.
		$this->assertSame( '', $echoed, 'the provider renders nothing itself' );
		$this->assertSame( 'https://stub.test/profile/', $link, 'the canonical UM-owned profile route' );
		$this->assertStringNotContainsString( '<a ', (string) $link );
		$this->assertStringNotContainsString( '<form', (string) $link );
		$this->assertStringNotContainsString( '<nav', (string) $link );

		// Stable and canonical: a second call returns the identical single link.
		$this->assertSame( $link, $provider->get_profile_overview_link( 5 ) );
	}

	/** C8 absent: Dashboard feature absent (default fail-closed signals) => null only. */
	public function test_absent_dashboard_hides_link_without_breaking_hal_or_um(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->assertNull( $provider->get_profile_overview_link( 5 ) );

		// Absence only hides the link: UM's own surface and HAL's boot stay intact.
		$this->assertSame( 'https://stub.test/profile/', um_user_profile_url() );
		$this->assertNotNull( $b->get_profile_context() );
		$this->assertNotNull( $b->get_optional_integrations() );
	}

	/** C8 not-ready (provider level, outside the contract context): detected but not ready => null. */
	public function test_present_but_not_ready_hides_link(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_detected();
		// The readiness signal stays at its fail-closed default (false).

		$this->assertNull( $provider->get_profile_overview_link( 5 ) );
	}

	/** C8 invalid-route: the owner profile cannot be resolved => null. */
	public function test_invalid_route_unresolvable_owner_hides_link(): void {
		$this->seed_owner(); // User 5 exists; no user record for 999.
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( 999 ) );
	}

	/** C8 invalid-route: the owner's persona is unavailable (UM status unreachable) => null. */
	public function test_invalid_route_unreachable_um_status_hides_link(): void {
		$this->seed_owner( 5, 'role_lawyer', '' ); // No UM status resolvable for this owner.
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( 5 ) );
	}

	/** C8: a visitor (unauthenticated viewer) never receives a link. */
	public function test_visitor_gets_no_link(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 0;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( 0 ) );
	}

	/** C8: a logged-in but unauthorized (unmapped/unapproved) member never receives a link. */
	public function test_unauthorized_member_gets_no_link(): void {
		$this->seed_owner();
		// Logged-in viewer 7 exists but holds no approved mapped persona.
		$GLOBALS['wp_stubs']['users'][7]          = array( 'roles' => array( 'role_unmapped' ), 'display_name' => 'Other' );
		$GLOBALS['wp_stubs']['um_status_by_user'] = array( 7 => 'awaiting_review' );
		$GLOBALS['wp_stubs']['current_user']      = 7;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( 7 ) );
	}

	/** C8: non-boolean detection signals are untrusted and fail closed (link hidden). */
	public function test_non_boolean_detection_signals_fail_closed(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		foreach ( array( 1, 'yes', 'true', array() ) as $untrusted ) {
			add_filter(
				'hal_member_profiles_frontend_dashboard_detected',
				static function () use ( $untrusted ) {
					return $untrusted;
				}
			);
			add_filter(
				'hal_member_profiles_frontend_dashboard_ready',
				static function () use ( $untrusted ) {
					return $untrusted;
				}
			);

			$this->assertNull( $provider->get_profile_overview_link( 5 ), 'signal ' . var_export( $untrusted, true ) . ' must fail closed' );
		}
	}

	/** C8: a non-positive viewer identity never resolves a link, even with everything armed. */
	public function test_non_positive_viewer_gets_no_link(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( -3 ) );
	}

	/** C8: two personas resolving to the same role+status verdict is ambiguous => null. */
	public function test_ambiguous_persona_mapping_hides_link(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		// The mapping is this test's own input: pointing a second persona at the owner's
		// role + status means the owner's persona verdict is no longer exactly one match.
		$GLOBALS['wp_stubs']['options'][ Settings::OPTION_KEY ]['um_persona_mapping'][ Settings::PERSONA_CONSULTANT ] = array(
			'role'   => 'role_lawyer',
			'status' => 'approved',
		);

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'an ambiguous persona verdict hides the link' );
	}

	/**
	 * C8 context rule, negative edge: the gate opens ONLY inside the Dashboard's OWN
	 * contract-filter invocation (proven in DashboardLinkConsumerTest). Any other running
	 * filter is just an outer tag on the current-filter stack — the two owned gate
	 * filters still decide there (armed ⇒ open, default ⇒ closed).
	 */
	public function test_unrelated_running_filter_is_not_the_contract_context(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		$inside = null;
		$probe  = static function ( $value ) use ( $provider, &$inside ) {
			$inside = $provider->get_profile_overview_link( 5 );

			return $value;
		};

		add_filter( 'hal_member_profiles_unrelated_outer_tag', $probe );
		apply_filters( 'hal_member_profiles_unrelated_outer_tag', 'x' );

		$this->assertSame( 'https://stub.test/profile/', $inside, 'an unrelated running filter is not the contract context: the owned gates still decide (armed ⇒ open)' );

		// Without the bridge-arming, that same unrelated context stays closed.
		remove_all_filters( 'hal_member_profiles_frontend_dashboard_detected' );
		remove_all_filters( 'hal_member_profiles_frontend_dashboard_ready' );

		$inside = null;
		apply_filters( 'hal_member_profiles_unrelated_outer_tag', 'x' );

		$this->assertNull( $inside, 'outside the contract context, an unrelated running filter opens nothing (default ⇒ closed)' );
	}

	/**
	 * C8 gap closure: the URL validator's own edges, probed directly through the provider.
	 * A parse failure, a missing host, a non-http(s) scheme, and control characters or
	 * ANY whitespace — leading, trailing, or embedded — must all fail closed, while valid
	 * permalinks — including an uppercase scheme (schemes are case-insensitive per
	 * RFC 3986) and percent-encoded non-ASCII paths — stay valid. No trim() exists on the
	 * acceptance path: a whitespace-padded value is rejected, never
	 * normalized-and-accepted.
	 */
	public function test_url_validation_edges_fail_closed(): void {
		$provider = new OptionalIntegrations( new Dependencies() );
		$m        = new \ReflectionMethod( $provider, 'is_valid_profile_url' );
		$m->setAccessible( true );

		$valid = array(
			'https://stub.test/profile/',
			'http://stub.test/profile/',
			'HTTPS://STUB.TEST/profile/',
			'https://example.test/%D9%85%D8%B1%D8%AD%D8%A8%D8%A7',
		);

		foreach ( $valid as $url ) {
			$this->assertTrue( $m->invoke( $provider, $url ), 'must stay valid: ' . var_export( $url, true ) );
		}

		$invalid = array(
			'',
			'   ',
			'  https://stub.test/profile/  ',       // Leading/trailing whitespace: rejected, not trimmed.
			"\thttps://stub.test/profile/",         // Tab-padded: rejected.
			'/profile/',                            // Relative: no scheme, no host.
			'//stub.test/profile/',                 // Protocol-relative: no scheme.
			'ftp://stub.test/profile/',             // Non-http(s) scheme.
			'javaScript:alert(1)',                  // Unexpected scheme, any casing.
			'http:foo',                             // Scheme without a host.
			'http://',                              // Unparseable.
			'http:///path',                         // Unparseable: no host.
			'https://stub.test/a b',                // Embedded whitespace.
			'https://stub.test/a' . "\x00" . 'b',   // Control character inside.
			'https://stub.test/a' . "\x0a" . 'b',   // Newline inside.
		);

		foreach ( $invalid as $url ) {
			$this->assertFalse( $m->invoke( $provider, $url ), 'must fail closed: ' . var_export( $url, true ) );
		}
	}

	/**
	 * P2: a whitespace-padded accessor value fails closed to null at provider level —
	 * rejected, never trimmed-and-accepted — while the unpadded value is returned
	 * byte-identical to what the accessor produced.
	 */
	public function test_whitespace_padded_accessor_value_is_rejected_and_valid_value_is_byte_identical(): void {
		$this->seed_owner();
		$GLOBALS['wp_stubs']['current_user'] = 5;

		$b        = $this->boot_frontend();
		$provider = $b->get_optional_integrations();

		$this->mark_dashboard_ready();

		// Outside the contract context, the armed bridge opens the gate; the value comes
		// back byte-identical to the accessor's own.
		$this->assertSame( um_user_profile_url(), $provider->get_profile_overview_link( 5 ), 'byte-identical: the exact original accessor value is returned' );

		$GLOBALS['wp_stubs']['um_profile_url'] = "  https://stub.test/profile/  ";

		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'a whitespace-padded accessor value is rejected, not normalized-and-accepted' );

		$GLOBALS['wp_stubs']['um_profile_url'] = "\thttps://stub.test/profile/";

		$this->assertNull( $provider->get_profile_overview_link( 5 ), 'a tab-padded accessor value is rejected too' );
	}
}
