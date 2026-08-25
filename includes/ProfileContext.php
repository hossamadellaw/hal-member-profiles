<?php
/**
 * The single source of truth for who a public Ultimate Member profile page belongs to.
 *
 * Also owns the per-request Profile scope (card remediation F-08): the template-side
 * caller opens a scope with the verified $args/form_id/mode triple, so every later
 * Widget/Dynamic Tag calling resolve() with no arguments sees that SAME verified form
 * instead of falling back to guesses. Scopes nest (each exit restores the previous),
 * hold no target identity supplied by callers, and die with the per-request instance —
 * nothing persists between requests and no global state is touched.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileContext {

	private Settings $settings;

	/**
	 * Open scope stack, innermost last. Each entry: array{args:array, form_id:int, mode:string}.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	private array $scope_stack = array();

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolves the profile being viewed, or null when there is no valid, permitted context.
	 *
	 * Explicit arguments win; otherwise an open scope supplies the template-verified
	 * form/mode so bare resolve() calls from Widgets/Tags stay on the SAME Form. With
	 * neither, legacy behavior applies (zeros — which downstream code must treat as
	 * unverifiable).
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
		$effective = $this->effective_request( $args, $form_id, $mode );

		if ( $this->is_elementor_editor() ) {
			return $this->resolve_editor_fixture( $effective['form_id'], $effective['mode'] );
		}

		return $this->resolve_live_profile( $effective['form_id'], $effective['mode'] );
	}

	/**
	 * Opens a verified Profile scope for this request. Only callers that genuinely hold
	 * the template's values (the layout adapter invoked from the UM template) may call
	 * this; scopes nest, and the caller MUST exit inside a finally so the previous scope
	 * is always restored even when rendering throws.
	 *
	 * The target user identity is deliberately NOT accepted here: it is derived fresh,
	 * through the fully guarded pipeline, on every resolve() — a caller-supplied target
	 * would be a profile-identity forgery vector. Nothing is stored before validation:
	 * a non-positive form_id pushes no scope and returns false.
	 *
	 * @param array  $args    Raw template args to carry through the scope.
	 * @param int    $form_id Verified active UM Profile form ID (> 0 required).
	 * @param string $mode    Active UM profile mode.
	 * @return bool Whether a scope was opened.
	 */
	public function enter_scope( array $args, int $form_id, string $mode ): bool {
		if ( $form_id <= 0 ) {
			return false;
		}

		$this->scope_stack[] = array(
			'args'    => $args,
			'form_id' => $form_id,
			'mode'    => $this->sanitize_mode( $mode ),
		);

		return true;
	}

	/**
	 * Closes the innermost scope, restoring the previous one automatically. Callers wrap
	 * their render in try/finally and call this from finally, so nested renders can never
	 * leak their scope outward on failure.
	 *
	 * @return bool Whether a scope was actually closed.
	 */
	public function exit_scope(): bool {
		if ( empty( $this->scope_stack ) ) {
			return false;
		}

		array_pop( $this->scope_stack );

		return true;
	}

	/**
	 * How many scopes are currently open (0 = none). Diagnostic aid for adapters/tests.
	 *
	 * @return int
	 */
	public function scope_depth(): int {
		return count( $this->scope_stack );
	}

	/**
	 * Merges explicit arguments over the innermost open scope: explicit wins per field,
	 * scope fills the gaps of bare resolve() calls from Tags/Widgets.
	 *
	 * @param array  $args    Explicit args (may be empty).
	 * @param int    $form_id Explicit form ID (0 = not supplied).
	 * @param string $mode    Explicit mode ('' = not supplied).
	 * @return array{args:array,form_id:int,mode:string}
	 */
	private function effective_request( array $args, int $form_id, string $mode ): array {
		$scope = ! empty( $this->scope_stack )
			? $this->scope_stack[ count( $this->scope_stack ) - 1 ]
			: null;

		return array(
			'args'    => ! empty( $args ) ? $args : ( is_array( $scope['args'] ?? null ) ? $scope['args'] : array() ),
			'form_id' => $form_id > 0 ? $form_id : (int) ( $scope['form_id'] ?? 0 ),
			'mode'    => '' !== $mode ? $this->sanitize_mode( $mode ) : (string) ( $scope['mode'] ?? '' ),
		);
	}

	/**
	 * Restricts a mode value to lowercase alphanumerics/dash/underscore.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private function sanitize_mode( string $mode ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( $mode ) ) ) ?? '';
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

		// Fail closed (remediation F-08 #1): when UM's own privacy verdict is
		// UNAVAILABLE we cannot verify access at all, so no target is exposed — the old
		// code assumed "not private" on a missing API, which failed open.
		$private = $this->is_private_profile( $target_id );

		if ( null === $private ) {
			return null;
		}

		if ( $private && ! $is_owner && ! current_user_can( 'manage_options' ) ) {
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
	 * Tri-state on purpose: null means "UM's verdict is unavailable", which callers must
	 * treat as UNVERIFIABLE (fail closed) — never as "not private".
	 *
	 * @param int $user_id User ID.
	 * @return bool|null True/false per UM; null when the API cannot be consulted.
	 */
	private function is_private_profile( int $user_id ): ?bool {
		if ( ! function_exists( 'UM' ) ) {
			return null;
		}

		$user = UM()->user();

		if ( ! is_object( $user ) || ! method_exists( $user, 'is_private_profile' ) ) {
			return null;
		}

		return (bool) $user->is_private_profile( $user_id );
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
