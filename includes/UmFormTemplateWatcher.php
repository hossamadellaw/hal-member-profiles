<?php
/**
 * Watcher for Ultimate Member profile-form template switches — card F-10.
 *
 * Sole responsibility: when the `_um_profile_template` meta of a `um_form` post is
 * UPDATED (the early-switch trap: selecting the HAL bridge template in Ultimate Member
 * > Forms before readiness silently changes the LIVE profile rendering — and replaces
 * the form's previous design, not "UM native"), evaluate HAL readiness from READ-ONLY
 * APIs only and, when readiness is incomplete, render ONE persistent, dismissible
 * ADMIN WARNING notice. WARNING ONLY (rule 0.5): this class never blocks, never
 * touches Ultimate Member data, and keeps zero persistent state anywhere — its only
 * state is four in-memory, request-scoped properties. Nothing is ever stored for the
 * dismissal: the notice carries the core client-side hide button (`is-dismissible`),
 * so hiding it writes nothing by construction, and "persistent" means every new
 * qualifying template switch (or a manual re-check) raises the warning again with no
 * server-side dismissal memory. The notice fires ONLY on the watched update path (or
 * a manual re-check) — never on every page load.
 *
 * Readiness definition (card F-10, all four elements required):
 *   1. FILE     — ManagedTemplates::inspect() (card E-13: read-only, functional on
 *                 1.2.x) reports every managed asset CURRENT in the active Child
 *                 Theme, i.e. the bridge file the switch would activate is actually
 *                 provisioned and hash-intact.
 *   2. GATE     — CompatibilityGate::passes( CAP_PROFILE ): the same executive gate
 *                 ProfileLayoutAdapter consults before ever rendering the Elementor
 *                 route. On 1.2.x this is always closed (describe() reads
 *                 awaiting_matrix_signoff), so every live switch warns today.
 *   3. MAPPING  — Settings::get_um_member_mapping() reports availability "available"
 *                 (um_persona_mapping complete and valid against live UM roles/statuses).
 *   4. CONTRACT — F-11 pre-check: the TemplateContractValidator class exists AND its
 *                 validate_template() PASSES for the configured profile library
 *                 template. ROUND-4: without this element the watcher would declare
 *                 false readiness on the live library template #8413 ("Profile —
 *                 Member (HAL)", published, general widgets only) and re-create the
 *                 very trap it was built to catch. Fail-closed: a missing validator
 *                 class, an unvalidated template selection, or an unverifiable
 *                 (fallback) verdict is NEVER ready.
 *
 * Any thrown/unusable result from a dependency degrades that element to NOT ready —
 * the watcher can only over-warn, never under-warn, and never invent availability.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UmFormTemplateWatcher {

	/** The UM form meta key whose update marks a profile template switch (E-07). */
	const WATCHED_META_KEY = '_um_profile_template';

	/** The only post type this watcher reacts to (UM Forms). */
	const WATCHED_POST_TYPE = 'um_form';

	/**
	 * The executive capability whose verdict governs the profile Elementor route — the
	 * route a profile-form template switch actually affects. Deliberately the STRICT
	 * passes() (no staging waiver): readiness for a live rendering change is judged by
	 * the production truth.
	 */
	const WATCHED_CAPABILITY = CompatibilityGate::CAP_PROFILE;

	/**
	 * The shared executive gate (Bootstrap-owned single instance).
	 *
	 * @var CompatibilityGate
	 */
	private $compatibility_gate;

	/**
	 * The shared settings service (Bootstrap-owned single instance).
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Optional managed-templates service; null uses a fresh instance exactly like the
	 * Diagnostics inspection block does. Injection exists for test isolation only.
	 *
	 * @var ManagedTemplates|null
	 */
	private $managed_templates;

	/**
	 * Optional validator factory (test seam): callable(): ?TemplateContractValidator.
	 * Null uses the official path — a class-existence guard followed by instantiation;
	 * a factory returning null models the deploy-degraded state where the F-11 class
	 * is absent, which must read as NOT ready (fail-closed, never a fake pass).
	 *
	 * @var callable|null
	 */
	private $contract_validator_factory;

	/**
	 * Whether a qualifying switch (or re-check) is currently unanswered — the ONLY
	 * notice state, in memory, never stored anywhere.
	 *
	 * @var bool
	 */
	private $notice_pending = false;

	/**
	 * The um_form post ID of the last qualifying switch (0 when none).
	 *
	 * @var int
	 */
	private $watched_form_id = 0;

	/**
	 * The new template value of the last qualifying switch ('' when none).
	 *
	 * @var string
	 */
	private $watched_template_value = '';

	/**
	 * The last readiness evaluation (see evaluate_readiness()); in-memory only.
	 *
	 * @var array
	 */
	private $last_evaluation = array();

	/**
	 * Constructor injection (F-11 pattern) so every dependency is a known, shared or
	 * purpose-built instance — no service locator, no hidden construction.
	 *
	 * @param CompatibilityGate     $compatibility_gate         Shared executive gate.
	 * @param Settings              $settings                   Shared settings service.
	 * @param ManagedTemplates|null $managed_templates          Optional managed-templates service.
	 * @param callable|null         $contract_validator_factory Optional validator factory (test seam).
	 */
	public function __construct(
		CompatibilityGate $compatibility_gate,
		Settings $settings,
		?ManagedTemplates $managed_templates = null,
		?callable $contract_validator_factory = null
	) {
		$this->compatibility_gate         = $compatibility_gate;
		$this->settings                   = $settings;
		$this->managed_templates          = $managed_templates;
		$this->contract_validator_factory = $contract_validator_factory;
	}

	/**
	 * The official `updated_post_meta` handler — official 4-argument signature (D-05:
	 * meta_id, object_id, meta_key, meta_value). The meta-key check and the post-type
	 * check run FIRST: anything else (any other key, any other post type, missing post)
	 * returns before touching a single service. A qualifying switch evaluates readiness
	 * once and stores the outcome in memory only.
	 *
	 * @param int|string $meta_id    Meta row ID (unused; official signature position 1).
	 * @param int|string $object_id  Post ID the meta belongs to.
	 * @param mixed      $meta_key   Meta key being updated.
	 * @param mixed      $meta_value New meta value.
	 * @return void
	 */
	public function on_updated_post_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( ! is_string( $meta_key ) || self::WATCHED_META_KEY !== $meta_key ) {
			return;
		}

		$form_id = (int) $object_id;

		if ( $form_id <= 0 ) {
			return;
		}

		try {
			$post = get_post( $form_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || self::WATCHED_POST_TYPE !== (string) $post->post_type ) {
			return;
		}

		$this->watched_form_id        = $form_id;
		$this->watched_template_value = is_scalar( $meta_value ) ? (string) $meta_value : '';

		$this->recheck();
	}

	/**
	 * Manual re-check path (the only notice trigger besides the watched hook): evaluates
	 * readiness against the CURRENT environment and answers the warning accordingly.
	 * Complete readiness clears the pending flag — the acceptance "notice disappears
	 * once readiness completes" holds because nothing was ever stored to re-show it.
	 *
	 * @return array The fresh evaluation (see evaluate_readiness()).
	 */
	public function recheck(): array {
		$this->last_evaluation = $this->evaluate_readiness();
		$this->notice_pending  = ! (bool) $this->last_evaluation['ready'];

		return $this->last_evaluation;
	}

	/**
	 * The `admin_notices` renderer. Renders ONLY when a qualifying switch (or re-check)
	 * left the warning pending and the viewer manages the options — one warning div,
	 * marked dismissible for the CORE CLIENT-SIDE hide (no stored state, no endpoint,
	 * no nonce round-trip: hiding writes nothing anywhere). Any other request renders
	 * nothing at all.
	 *
	 * @return void
	 */
	public function render_admin_notice(): void {
		if ( ! $this->notice_pending || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failed = $this->failing_element_labels( $this->last_evaluation );

		if ( $this->watched_form_id > 0 ) {
			$context = sprintf(
				/* translators: 1: form post ID, 2: selected template value, 3: failed readiness element list. */
				esc_html__( 'Form #%1$s now points to the template "%2$s". Incomplete readiness: %3$s.', 'hal-member-profiles' ),
				esc_html( (string) $this->watched_form_id ),
				esc_html( $this->watched_template_value ),
				esc_html( implode( ', ', $failed ) )
			);
		} else {
			$context = sprintf(
				/* translators: %s: failed readiness element list. */
				esc_html__( 'Incomplete readiness: %s.', 'hal-member-profiles' ),
				esc_html( implode( ', ', $failed ) )
			);
		}

		$warning_only = esc_html__(
			'This is a warning only: nothing was blocked and no Ultimate Member data was modified.',
			'hal-member-profiles'
		);

		$rollback = esc_html__(
			'Rollback: this switch replaced the form\'s previous design (not "UM native") — re-select the previous template under Ultimate Member > Forms to restore it.',
			'hal-member-profiles'
		);

		$cache_rule = esc_html__(
			'Cache rule (mandatory): after any provision or template switch, run Purge All (LiteSpeed + any CDN), or cached pages may keep serving the previous design.',
			'hal-member-profiles'
		);

		echo '<div class="notice notice-warning is-dismissible">'
			. '<p><strong>' . esc_html__(
				'HAL Member Profiles: the Ultimate Member profile form template was changed while HAL readiness is incomplete.',
				'hal-member-profiles'
			) . '</strong></p>'
			. '<p>' . $context . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part built above from escaped literals and escaped dynamic values.
			. '<p>' . $warning_only . '</p>'
			. '<p>' . $rollback . '</p>'
			. '<p>' . $cache_rule . '</p>'
			. '</div>';
	}

	/**
	 * Full readiness evaluation across the four card elements. READ-ONLY: every call
	 * below is an inspection/gate/settings/validator read; nothing is stored, written,
	 * or provisioned by this method.
	 *
	 * @return array{
	 *   ready:bool,
	 *   file:array{ready:bool,reason:string},
	 *   gate:array{ready:bool,reason:string},
	 *   mapping:array{ready:bool,reason:string},
	 *   contract:array{ready:bool,reason:string}
	 * }
	 */
	public function evaluate_readiness(): array {
		$file     = $this->evaluate_file_element();
		$gate     = $this->evaluate_gate_element();
		$mapping  = $this->evaluate_mapping_element();
		$contract = $this->evaluate_contract_element();

		return array(
			'ready'    => $file['ready'] && $gate['ready'] && $mapping['ready'] && $contract['ready'],
			'file'     => $file,
			'gate'     => $gate,
			'mapping'  => $mapping,
			'contract' => $contract,
		);
	}

	/**
	 * Convenience verdict: all four readiness elements hold.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return (bool) $this->evaluate_readiness()['ready'];
	}

	/**
	 * Whether a qualifying switch (or re-check) currently leaves the warning pending.
	 * In-memory read for tests and future readiness surfaces.
	 *
	 * @return bool
	 */
	public function is_notice_pending(): bool {
		return $this->notice_pending;
	}

	/**
	 * The last evaluation performed by the hook or recheck() ([] before any).
	 *
	 * @return array
	 */
	public function get_last_evaluation(): array {
		return $this->last_evaluation;
	}

	/**
	 * FILE element: every managed asset is CURRENT in the active Child Theme per the
	 * read-only inspection (E-13). Any declined/failed/empty inspection or any asset
	 * that is not current (missing, upgrade, user-modified, ...) means the file the
	 * switch would activate is not reliably provisioned — NOT ready.
	 *
	 * @return array{ready:bool,reason:string}
	 */
	private function evaluate_file_element(): array {
		if ( ! class_exists( ManagedTemplates::class ) ) {
			return array( 'ready' => false, 'reason' => 'module_unavailable' );
		}

		$managed = null !== $this->managed_templates ? $this->managed_templates : new ManagedTemplates();

		try {
			$inspection = $managed->inspect();
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'reason' => 'inspect_failed' );
		}

		if ( ! is_array( $inspection ) || empty( $inspection['ok'] ) ) {
			return array(
				'ready'  => false,
				'reason' => (string) ( is_array( $inspection ) ? ( $inspection['reason'] ?? 'inspect_failed' ) : 'inspect_failed' ),
			);
		}

		$assets = $inspection['assets'] ?? null;

		if ( ! is_array( $assets ) || array() === $assets ) {
			return array( 'ready' => false, 'reason' => 'no_managed_assets' );
		}

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['target_status'] ?? '' ) !== ManagedTemplates::STATUS_CURRENT ) {
				return array( 'ready' => false, 'reason' => 'target_not_current' );
			}
		}

		return array( 'ready' => true, 'reason' => 'provisioned_current' );
	}

	/**
	 * GATE element: the strict executive verdict for the profile capability, plus its
	 * machine-stable describe() slug (awaiting_matrix_signoff on 1.2.x — the gate is
	 * simply closed there).
	 *
	 * @return array{ready:bool,reason:string}
	 */
	private function evaluate_gate_element(): array {
		try {
			$ready  = $this->compatibility_gate->passes( self::WATCHED_CAPABILITY );
			$reason = $this->compatibility_gate->describe( self::WATCHED_CAPABILITY );
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'reason' => 'gate_unavailable' );
		}

		return array( 'ready' => (bool) $ready, 'reason' => (string) $reason );
	}

	/**
	 * MAPPING element: the revalidated runtime UM member mapping (um_persona_mapping
	 * against live UM roles/statuses) must be fully available.
	 *
	 * @return array{ready:bool,reason:string}
	 */
	private function evaluate_mapping_element(): array {
		try {
			$mapping = $this->settings->get_um_member_mapping();
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'reason' => 'mapping_unavailable' );
		}

		$ready = is_array( $mapping ) && 'available' === ( $mapping['availability'] ?? '' );

		return array(
			'ready'  => $ready,
			'reason' => $ready ? 'configured' : (string) ( is_array( $mapping ) ? ( $mapping['reason'] ?? 'unavailable' ) : 'unavailable' ),
		);
	}

	/**
	 * CONTRACT element (ROUND-4, fail-closed): the F-11 validator class must exist AND
	 * pass for the configured profile library template. No configured template, a
	 * missing validator class, or any non-pass/unverifiable verdict means NOT ready —
	 * this element is exactly what stops the false readiness on the live #8413 shape.
	 *
	 * @return array{ready:bool,reason:string}
	 */
	private function evaluate_contract_element(): array {
		try {
			$template_id = $this->settings->get_profile_library_template_id();
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'reason' => 'settings_unavailable' );
		}

		if ( ! is_int( $template_id ) || $template_id <= 0 ) {
			return array( 'ready' => false, 'reason' => 'no_validated_profile_template' );
		}

		$validator = $this->resolve_contract_validator();

		if ( null === $validator ) {
			return array( 'ready' => false, 'reason' => 'validator_unavailable' );
		}

		try {
			$result = $validator->validate_template( $template_id );
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'reason' => 'validation_failed' );
		}

		$ready = is_array( $result ) && true === ( $result['pass'] ?? false );

		return array(
			'ready'  => $ready,
			'reason' => (string) ( is_array( $result ) ? ( $result['reason'] ?? 'invalid_result' ) : 'invalid_result' ),
		);
	}

	/**
	 * Resolves the F-11 validator: the injected factory when present (test seam), else
	 * the official path — class-existence guard, then instantiation. A class absent from
	 * a degraded deploy resolves to null and the contract element reads NOT ready
	 * (fail-closed), exactly like the F-05 degradation guard upstream.
	 *
	 * @return TemplateContractValidator|null
	 */
	private function resolve_contract_validator(): ?TemplateContractValidator {
		if ( null !== $this->contract_validator_factory ) {
			try {
				$validator = call_user_func( $this->contract_validator_factory );
			} catch ( \Throwable $e ) {
				return null;
			}

			return $validator instanceof TemplateContractValidator ? $validator : null;
		}

		return class_exists( TemplateContractValidator::class ) ? new TemplateContractValidator() : null;
	}

	/**
	 * Human-readable labels for the elements that failed the last evaluation.
	 *
	 * @param array $evaluation Evaluation from evaluate_readiness().
	 * @return string[]
	 */
	private function failing_element_labels( array $evaluation ): array {
		$labels = array(
			'file'     => __( 'managed template file', 'hal-member-profiles' ),
			'gate'     => __( 'compatibility gate', 'hal-member-profiles' ),
			'mapping'  => __( 'UM member mapping', 'hal-member-profiles' ),
			'contract' => __( 'template contract', 'hal-member-profiles' ),
		);

		$failed = array();

		foreach ( $labels as $key => $label ) {
			if ( empty( $evaluation[ $key ]['ready'] ) ) {
				$failed[] = $label . ' (' . (string) ( $evaluation[ $key ]['reason'] ?? 'unknown' ) . ')';
			}
		}

		return array() === $failed ? array( __( 'unresolved evaluation', 'hal-member-profiles' ) ) : $failed;
	}
}
