<?php
/**
 * Non-sensitive settings that only control whether the bridge is active and its uninstall fate.
 *
 * Layout modes are governed by CompatibilityGate: "public_layout" may only be saved or
 * served when the gate passes for the exact installed composition. A missing gate fails
 * closed, so the bridge can never run an untested composition.
 *
 * Development card D-12: this option stays strictly NON-SENSITIVE — modes, template IDs,
 * fixtures, the managed-template consent flag, and the Amelia sync-mode switch. The
 * Amelia API key lives exclusively in SecretStore (card D-07), and the Amelia catalog
 * lives exclusively in SchemaRegistry's separate NON-autoloaded snapshot option (card
 * D-09); neither ever passes through this file. Disabling managed sync here is purely a
 * scheduling/consumer flag (cards D-13/D-14): it deletes no data and no files.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const PERSONA_LAWYER          = 'lawyer';
	public const PERSONA_CONSULTANT      = 'consultant';
	public const PERSONA_ORDINARY_MEMBER = 'ordinary_member';

	const OPTION_KEY   = 'hal_member_profiles_settings';
	const OPTION_GROUP = 'hal_member_profiles_settings_group';
	const PAGE_SLUG    = 'hal-member-profiles-settings';
	const UM_MAPPING_NONCE_ACTION = 'hal_member_profiles_save_um_mapping';
	const UM_MAPPING_NONCE_NAME   = 'hal_member_profiles_um_mapping_nonce';

	const LAYOUT_MODE_OBSERVE       = 'observe';
	const LAYOUT_MODE_PUBLIC_LAYOUT = 'public_layout';

	const SYNC_MODE_OFF               = 'off';
	const SYNC_MODE_DISCOVER_ONLY     = 'discover_only';
	const SYNC_MODE_MANAGED_ADDITIONS = 'managed_additions';
	const SYNC_MODE_MANAGED_SYNC      = 'managed_sync';

	/**
	 * Runtime compatibility gate consulted before public_layout may be saved or served.
	 * Kept nullable because Bootstrap wires it in a dedicated step; null fails closed.
	 *
	 * @var CompatibilityGate|null
	 */
	private ?CompatibilityGate $compatibility_gate;

	/**
	 * @param CompatibilityGate|null $compatibility_gate Injected runtime gate; null (not yet wired) locks public_layout off.
	 */
	public function __construct( ?CompatibilityGate $compatibility_gate = null ) {
		$this->compatibility_gate = $compatibility_gate;
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Registers the settings page as a submenu of the HAL Member Profiles admin menu
	 * (card S-03) at a later admin_menu priority than the parent registration, since
	 * Settings is instantiated before AdminDashboard in Bootstrap.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			AdminDashboard::PAGE_SLUG,
			__( 'HAL Member Profiles', 'hal-member-profiles' ),
			__( 'Settings', 'hal-member-profiles' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the single grouped option via the Settings API.
	 *
	 * WordPress core (options.php) already verifies the settings-page nonce and the
	 * page's manage_options capability before this sanitize callback runs; sanitize()
	 * re-checks capability itself as defense in depth only.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);

		add_settings_section(
			'hal_member_profiles_main',
			__( 'Profile & Account Bridge', 'hal-member-profiles' ),
			'__return_false',
			self::PAGE_SLUG
		);
	}

	/**
	 * Renders the settings page markup.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->all();
		$roles    = $this->get_um_role_options();
		$statuses = $this->get_um_status_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HAL Member Profiles', 'hal-member-profiles' ); ?></h1>
			<p class="description"><?php esc_html_e( 'All editable HAL Member Profiles values live on this single save form.', 'hal-member-profiles' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Next step:', 'hal-member-profiles' ); ?></strong> <?php esc_html_e( 'review the groups below and save — grouping changed the view only, not how values are stored.', 'hal-member-profiles' ); ?></p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<?php wp_nonce_field( self::UM_MAPPING_NONCE_ACTION, self::UM_MAPPING_NONCE_NAME ); ?>

				<h2 class="title"><?php esc_html_e( 'Profile layout', 'hal-member-profiles' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hal-profile-layout-mode"><?php esc_html_e( 'Profile layout mode', 'hal-member-profiles' ); ?></label></th>
						<td>
							<select id="hal-profile-layout-mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_layout_mode]">
								<option value="observe" <?php selected( $settings['profile_layout_mode'], self::LAYOUT_MODE_OBSERVE ); ?>><?php esc_html_e( 'Observe (no visual change)', 'hal-member-profiles' ); ?></option>
								<option value="public_layout" <?php selected( $settings['profile_layout_mode'], self::LAYOUT_MODE_PUBLIC_LAYOUT ); ?>><?php esc_html_e( 'Public layout (Elementor template)', 'hal-member-profiles' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hal-profile-template-id"><?php esc_html_e( 'Profile Elementor library template ID', 'hal-member-profiles' ); ?></label></th>
						<td><input id="hal-profile-template-id" type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_library_template_id]" value="<?php echo esc_attr( (string) $settings['profile_library_template_id'] ); ?>" /></td>
					</tr>
					<?php $persona_template_ids = is_array( $settings['profile_library_template_ids'] ?? null ) ? $settings['profile_library_template_ids'] : array(); ?>
					<?php foreach ( $this->persona_labels() as $persona => $persona_label ) : ?>
						<tr>
							<th scope="row"><label for="hal-profile-template-id-<?php echo esc_attr( $persona ); ?>"><?php echo esc_html( sprintf( /* translators: %s: member type label. */ __( '%s profile template ID', 'hal-member-profiles' ), $persona_label ) ); ?></label></th>
							<td>
								<input id="hal-profile-template-id-<?php echo esc_attr( $persona ); ?>" type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_library_template_ids][<?php echo esc_attr( $persona ); ?>]" value="<?php echo esc_attr( (string) ( $persona_template_ids[ $persona ] ?? 0 ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Existing Elementor library template for this member type public profile only. Empty or unqualified values fall back to the single template ID above; a configured but invalid ID stays on the native Ultimate Member view. HAL never creates, edits, or writes any template or file.', 'hal-member-profiles' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="hal-profile-fixture-id"><?php esc_html_e( 'Profile preview fixture user ID', 'hal-member-profiles' ); ?></label></th>
						<td>
							<input id="hal-profile-fixture-id" type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_fixture_user_id]" value="<?php echo esc_attr( (string) $settings['profile_fixture_user_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only used to preview this layout inside the Elementor editor, for administrators only — never shown on the live frontend or in a public preview.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Account ownership', 'hal-member-profiles' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ultimate Member owns and renders the complete Account page. Legacy HAL Account layout values are retained for backward compatibility but are not active in v1.2.0.', 'hal-member-profiles' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hal-account-layout-mode"><?php esc_html_e( 'Account layout mode', 'hal-member-profiles' ); ?></label></th>
						<td>
							<select id="hal-account-layout-mode" disabled>
								<option selected><?php esc_html_e( 'Native (Ultimate Member)', 'hal-member-profiles' ); ?></option>
							</select>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_layout_mode]" value="<?php echo esc_attr( (string) $settings['account_layout_mode'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hal-account-template-id"><?php esc_html_e( 'Account Elementor library template ID', 'hal-member-profiles' ); ?></label></th>
						<td><input id="hal-account-template-id" type="number" min="0" value="<?php echo esc_attr( (string) $settings['account_library_template_id'] ); ?>" disabled /><input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_library_template_id]" value="<?php echo esc_attr( (string) $settings['account_library_template_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hal-account-fixture-id"><?php esc_html_e( 'Account preview fixture user ID', 'hal-member-profiles' ); ?></label></th>
						<td>
							<input id="hal-account-fixture-id" type="number" min="0" value="<?php echo esc_attr( (string) $settings['account_fixture_user_id'] ); ?>" disabled />
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_fixture_user_id]" value="<?php echo esc_attr( (string) $settings['account_fixture_user_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Retained only so older saved settings are not deleted; HAL does not preview or replace the native Account page.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Ultimate Member membership mapping', 'hal-member-profiles' ); ?></h2>
				<p class="description"><?php esc_html_e( 'HAL reads roles and account approval from Ultimate Member. If this mapping is missing or no longer valid, the native Ultimate Member view remains active.', 'hal-member-profiles' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $this->persona_labels() as $persona => $persona_label ) : ?>
						<?php $persona_mapping = is_array( $settings['um_persona_mapping'][ $persona ] ?? null ) ? $settings['um_persona_mapping'][ $persona ] : array(); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $persona_label ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Role', 'hal-member-profiles' ); ?>
									<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[um_persona_mapping][<?php echo esc_attr( $persona ); ?>][role]">
										<option value=""><?php esc_html_e( '— Select discovered role —', 'hal-member-profiles' ); ?></option>
										<?php foreach ( $roles as $role_key => $role_label ) : ?>
											<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( (string) ( $persona_mapping['role'] ?? '' ), $role_key ); ?>><?php echo esc_html( $role_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label>
									<?php esc_html_e( 'Approved status', 'hal-member-profiles' ); ?>
									<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[um_persona_mapping][<?php echo esc_attr( $persona ); ?>][status]">
										<option value=""><?php esc_html_e( '— Select discovered status —', 'hal-member-profiles' ); ?></option>
										<?php foreach ( $statuses as $status_key => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( (string) ( $persona_mapping['status'] ?? '' ), $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Amelia', 'hal-member-profiles' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hal-amelia-booking-url"><?php esc_html_e( 'Amelia general booking URL', 'hal-member-profiles' ); ?></label></th>
						<td><input id="hal-amelia-booking-url" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[amelia_booking_url]" value="<?php echo esc_attr( (string) $settings['amelia_booking_url'] ); ?>" placeholder="https://" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hal-amelia-sync-mode"><?php esc_html_e( 'Amelia sync mode', 'hal-member-profiles' ); ?></label></th>
						<td>
							<select id="hal-amelia-sync-mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[amelia_sync_mode]">
								<option value="off" <?php selected( $settings['amelia_sync_mode'], self::SYNC_MODE_OFF ); ?>><?php esc_html_e( 'Off — no connection', 'hal-member-profiles' ); ?></option>
								<option value="discover_only" <?php selected( $settings['amelia_sync_mode'], self::SYNC_MODE_DISCOVER_ONLY ); ?>><?php esc_html_e( 'Discover only — read and show diffs, never write', 'hal-member-profiles' ); ?></option>
							<option value="managed_additions" <?php selected( $settings['amelia_sync_mode'], self::SYNC_MODE_MANAGED_ADDITIONS ); ?>><?php esc_html_e( 'Managed additions — create missing HAL-owned fields only, never update', 'hal-member-profiles' ); ?></option>
							<option value="managed_sync" <?php selected( $settings['amelia_sync_mode'], self::SYNC_MODE_MANAGED_SYNC ); ?>><?php esc_html_e( 'Managed sync — create missing and update HAL-owned fields (deletions stay manual)', 'hal-member-profiles' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Turning this off stops future synchronization only — it never deletes catalog data or files.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Managed sync & uninstall', 'hal-member-profiles' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Managed templates consent', 'hal-member-profiles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[managed_templates_consent]" value="1" <?php checked( $settings['managed_templates_consent'] ); ?> />
								<?php esc_html_e( 'Allow HAL to provision and sync its managed templates into the active Child Theme', 'hal-member-profiles' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Revoking this consent stops provisioning/syncing — it never deletes your files or stored data.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge on uninstall', 'hal-member-profiles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[purge_on_uninstall]" value="1" <?php checked( $settings['purge_on_uninstall'] ); ?> />
								<?php esc_html_e( 'Delete these bridge settings on uninstall', 'hal-member-profiles' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Irreversible: once the plugin is uninstalled with this enabled, the deletion cannot be undone.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitizes and validates the whole settings array before it is saved.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public function sanitize( $input ): array {
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			return $this->all();
		}

		$defaults = $this->defaults();
		$clean    = $defaults;
		$current  = $this->all();

		$clean['profile_layout_mode'] = $this->sanitize_layout_mode( $input['profile_layout_mode'] ?? '', CompatibilityGate::CAP_PROFILE );
		$clean['account_layout_mode'] = self::LAYOUT_MODE_PUBLIC_LAYOUT === ( $current['account_layout_mode'] ?? '' )
			? self::LAYOUT_MODE_PUBLIC_LAYOUT
			: self::LAYOUT_MODE_OBSERVE;

		$clean['profile_library_template_id'] = $this->sanitize_library_template_id( $input['profile_library_template_id'] ?? 0 );
		$clean['profile_library_template_ids'] = $this->sanitize_persona_template_ids( $input['profile_library_template_ids'] ?? array() );
		$clean['account_library_template_id'] = absint( $current['account_library_template_id'] ?? 0 );

		$clean['amelia_booking_url'] = $this->sanitize_booking_url( $input['amelia_booking_url'] ?? '' );

		// manage_options was already confirmed above. This save happens from the normal
		// wp-admin Settings page, which is never itself "inside the Elementor editor" — so
		// is_editor_context_for_manager() must not gate this write path, or a manage_options
		// user could never set these values at all. That check is reserved for the read-time
		// exposure in get_profile_fixture_user_id()/get_account_fixture_user_id() below, where
		// it is still correctly applied.
		$clean['profile_fixture_user_id'] = $this->sanitize_fixture_user_id( $input['profile_fixture_user_id'] ?? 0 );
		$clean['account_fixture_user_id'] = absint( $current['account_fixture_user_id'] ?? 0 );

		if ( $this->um_mapping_nonce_is_valid() ) {
			$role_source   = $this->discover_um_roles();
			$status_source = $this->discover_um_statuses();

			if ( $role_source['available'] && $status_source['available'] ) {
				$clean['um_persona_mapping'] = $this->sanitize_persona_mapping(
					$input['um_persona_mapping'] ?? array(),
					$role_source['items'],
					$status_source['items']
				);
				// Retain the C1 draft aggregate keys for backward-compatible readers only.
				$clean['um_member_roles']      = array_values( array_unique( array_column( $clean['um_persona_mapping'], 'role' ) ) );
				$clean['um_approved_statuses'] = array_values( array_unique( array_column( $clean['um_persona_mapping'], 'status' ) ) );
			} else {
				$clean['um_persona_mapping']   = $this->stored_persona_mapping( $current['um_persona_mapping'] ?? array() );
				$clean['um_member_roles']      = $this->stored_mapping_keys( $current['um_member_roles'] ?? array() );
				$clean['um_approved_statuses'] = $this->stored_mapping_keys( $current['um_approved_statuses'] ?? array() );
			}
		} else {
			// The Settings API verifies its own nonce before reaching this callback. This
			// dedicated token protects the C1 mapping if sanitize() is invoked elsewhere.
			$clean['um_member_roles']      = $this->stored_mapping_keys( $current['um_member_roles'] ?? array() );
			$clean['um_approved_statuses'] = $this->stored_mapping_keys( $current['um_approved_statuses'] ?? array() );
			$clean['um_persona_mapping']   = $this->stored_persona_mapping( $current['um_persona_mapping'] ?? array() );
		}

		$clean['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		// D-12: managed-template consent and the Amelia sync switch — both strictly
		// non-sensitive operational flags. Turning sync OFF (or revoking consent) is a
		// consumer-facing scheduling signal only; nothing anywhere deletes data or files.
		$clean['managed_templates_consent'] = ! empty( $input['managed_templates_consent'] );
		$clean['amelia_sync_mode']          = $this->sanitize_amelia_sync_mode( $input['amelia_sync_mode'] ?? '' );

		return $clean;
	}

	/**
	 * Active profile layout mode, re-checked against the compatibility gate at read time
	 * so a stale public_layout value can never serve on an unapproved composition.
	 *
	 * @return string
	 */
	public function get_profile_layout_mode(): string {
		return $this->enforce_um_mapping( $this->enforced_layout_mode( $this->all()['profile_layout_mode'], CompatibilityGate::CAP_PROFILE ) );
	}

	/**
	 * Active Account mode. C2 keeps the stored value for backward compatibility, while
	 * the effective v1.2.0 behavior is always UM Native/observe.
	 *
	 * @return string
	 */
	public function get_account_layout_mode(): string {
		return self::LAYOUT_MODE_OBSERVE;
	}

	/**
	 * Profile Elementor library template ID, re-validated at read time.
	 *
	 * @return int|null
	 */
	public function get_profile_library_template_id(): ?int {
		return $this->valid_template_id_or_null( (int) $this->all()['profile_library_template_id'] );
	}

	/**
	 * The public-profile Elementor library template ID for ONE member type (OD-06 /
	 * card C7), re-validated at read time. Read-only: no template is ever created,
	 * edited, published, or written.
	 *
	 * A configured per-member-type ID that no longer validates — deleted, unpublished,
	 * wrong post type, not Elementor-built — returns null, which callers must treat as
	 * the Native fallback; a valid template is never silently substituted for another.
	 * When the member type has no per-type selection, the legacy single
	 * profile_library_template_id is read backward-compatibly (the key is never deleted
	 * or migrated) with the exact same validation.
	 *
	 * @param string $persona One of this class's PERSONA_* constants.
	 * @return int|null
	 */
	public function get_public_profile_template_id_for_persona( string $persona ): ?int {
		$stored = $this->all();
		$map    = is_array( $stored['profile_library_template_ids'] ?? null )
			? $stored['profile_library_template_ids']
			: array();

		if ( isset( $this->persona_labels()[ $persona ] ) ) {
			$configured = absint( $map[ $persona ] ?? 0 );

			if ( $configured > 0 ) {
				return $this->valid_template_id_or_null( $configured );
			}
		}

		return $this->get_profile_library_template_id();
	}

	/**
	 * Account Elementor library template ID, re-validated at read time.
	 *
	 * @return int|null
	 */
	public function get_account_library_template_id(): ?int {
		return $this->valid_template_id_or_null( (int) $this->all()['account_library_template_id'] );
	}

	/**
	 * Profile fixture user ID, only inside the Elementor editor for a manage_options user.
	 *
	 * @return int|null
	 */
	public function get_profile_fixture_user_id(): ?int {
		if ( ! $this->is_editor_context_for_manager() ) {
			return null;
		}

		return $this->valid_user_id_or_null( (int) $this->all()['profile_fixture_user_id'] );
	}

	/**
	 * Account fixture user ID, only inside the Elementor editor for a manage_options user.
	 *
	 * @return int|null
	 */
	public function get_account_fixture_user_id(): ?int {
		if ( ! $this->is_editor_context_for_manager() ) {
			return null;
		}

		return $this->valid_user_id_or_null( (int) $this->all()['account_fixture_user_id'] );
	}

	/**
	 * The administrator-configured general Amelia booking URL, or null when unset.
	 *
	 * @return string|null
	 */
	public function get_amelia_booking_url(): ?string {
		$url = trim( (string) $this->all()['amelia_booking_url'] );

		return '' === $url ? null : $url;
	}

	/**
	 * Whether HAL options should be deleted on uninstall.
	 *
	 * @return bool
	 */
	public function get_purge_on_uninstall(): bool {
		return (bool) $this->all()['purge_on_uninstall'];
	}

	/**
	 * Explicit operator consent for HAL provisioning/syncing managed templates into the
	 * active Child Theme (cards D-04/D-05 consumers). Purely non-sensitive; revoking it
	 * never deletes data or files anywhere.
	 *
	 * @return bool
	 */
	public function get_managed_templates_consent(): bool {
		return (bool) $this->all()['managed_templates_consent'];
	}

	/**
	 * The Amelia sync switch (governing doc §9.1): off | discover_only |
	 * managed_additions | managed_sync. Disabling it never deletes catalog snapshots,
	 * options, or files — it only stops future synchronization work.
	 *
	 * @return string
	 */
	public function get_amelia_sync_mode(): string {
		return (string) $this->all()['amelia_sync_mode'];
	}

	/** @return array<string,string> Runtime UM role key => label. */
	public function get_um_role_options(): array {
		$source = $this->discover_um_roles();

		return $source['available'] ? $source['items'] : array();
	}

	/** @return array<string,string> Runtime UM status key => label. */
	public function get_um_status_options(): array {
		$source = $this->discover_um_statuses();

		return $source['available'] ? $source['items'] : array();
	}

	/**
	 * Returns the revalidated runtime mapping. No role/status is inferred and no user
	 * membership value is copied into HAL storage.
	 *
	 * @return array{availability:string,reason:string,personas:array<string,array{role:string,status:string}>,member_roles:array<int,string>,approved_statuses:array<int,string>}
	 */
	public function get_um_member_mapping(): array {
		$role_source   = $this->discover_um_roles();
		$status_source = $this->discover_um_statuses();

		if ( ! $role_source['available'] || ! $status_source['available'] ) {
			return $this->unavailable_mapping( 'um_mapping_provider_unavailable' );
		}

		$stored            = $this->all();
		$stored_mapping = $this->stored_persona_mapping( $stored['um_persona_mapping'] ?? array() );
		$valid_mapping  = $this->sanitize_persona_mapping( $stored_mapping, $role_source['items'], $status_source['items'] );

		if ( $stored_mapping !== $valid_mapping ) {
			return $this->unavailable_mapping( 'um_mapping_stale' );
		}

		if ( count( $valid_mapping ) !== count( $this->persona_labels() ) ) {
			return $this->unavailable_mapping( 'um_mapping_missing' );
		}

		$roles = array_column( $valid_mapping, 'role' );

		if ( count( $roles ) !== count( array_unique( $roles ) ) ) {
			return $this->unavailable_mapping( 'um_mapping_conflict' );
		}

		return array(
			'availability'      => 'available',
			'reason'            => 'configured',
			'personas'          => $valid_mapping,
			'member_roles'      => $roles,
			'approved_statuses' => array_values( array_unique( array_column( $valid_mapping, 'status' ) ) ),
		);
	}

	/**
	 * Reads the stored option merged over safe defaults.
	 *
	 * @return array
	 */
	private function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $this->defaults(), $stored );
	}

	/**
	 * Default, safe values for every stored key.
	 *
	 * @return array
	 */
	private function defaults(): array {
		return array(
			'profile_layout_mode'         => self::LAYOUT_MODE_OBSERVE,
			'account_layout_mode'         => self::LAYOUT_MODE_OBSERVE,
			'profile_library_template_id' => 0,
			'profile_library_template_ids' => array(
				self::PERSONA_LAWYER          => 0,
				self::PERSONA_CONSULTANT      => 0,
				self::PERSONA_ORDINARY_MEMBER => 0,
			),
			'account_library_template_id' => 0,
			'profile_fixture_user_id'     => 0,
			'account_fixture_user_id'     => 0,
			'um_member_roles'             => array(),
			'um_approved_statuses'        => array(),
			'um_persona_mapping'           => array(),
			'amelia_booking_url'          => '',
			'purge_on_uninstall'          => false,
			'managed_templates_consent'   => false,
			'amelia_sync_mode'            => self::SYNC_MODE_OFF,
		);
	}

	/** @return array{available:bool,reason:string,items:array<string,string>} */
	private function discover_um_roles(): array {
		if ( ! function_exists( 'um_get_roles' ) ) {
			return array( 'available' => false, 'reason' => 'um_roles_unavailable', 'items' => array() );
		}

		try {
			return $this->normalize_mapping_options( um_get_roles(), 'um_roles_invalid' );
		} catch ( \Throwable $e ) {
			return array( 'available' => false, 'reason' => 'um_roles_unavailable', 'items' => array() );
		}
	}

	/** @return array{available:bool,reason:string,items:array<string,string>} */
	private function discover_um_statuses(): array {
		if ( ! function_exists( 'UM' ) ) {
			return array( 'available' => false, 'reason' => 'um_statuses_unavailable', 'items' => array() );
		}

		try {
			$um     = UM();
			$common = is_object( $um ) && method_exists( $um, 'common' ) ? $um->common() : null;
			$users  = is_object( $common ) && method_exists( $common, 'users' ) ? $common->users() : null;

			if ( ! is_object( $users ) || ! method_exists( $users, 'statuses_list' ) ) {
				return array( 'available' => false, 'reason' => 'um_statuses_unavailable', 'items' => array() );
			}

			return $this->normalize_mapping_options( $users->statuses_list(), 'um_statuses_invalid' );
		} catch ( \Throwable $e ) {
			return array( 'available' => false, 'reason' => 'um_statuses_unavailable', 'items' => array() );
		}
	}

	/** @return array{available:bool,reason:string,items:array<string,string>} */
	private function normalize_mapping_options( $raw, string $invalid_reason ): array {
		if ( ! is_array( $raw ) ) {
			return array( 'available' => false, 'reason' => $invalid_reason, 'items' => array() );
		}

		$items = array();

		foreach ( $raw as $key => $label ) {
			$key = (string) $key;

			if ( '' !== $key && is_scalar( $label ) ) {
				$items[ $key ] = (string) $label;
			}
		}

		return array(
			'available' => true,
			'reason'    => empty( $items ) ? 'valid_empty' : 'discovered',
			'items'     => $items,
		);
	}

	/** @return array<string,string> */
	private function persona_labels(): array {
		return array(
			self::PERSONA_LAWYER          => __( 'Lawyer', 'hal-member-profiles' ),
			self::PERSONA_CONSULTANT      => __( 'Consultant', 'hal-member-profiles' ),
			self::PERSONA_ORDINARY_MEMBER => __( 'Ordinary member', 'hal-member-profiles' ),
		);
	}

	/** @return array<string,array{role:string,status:string}> */
	private function sanitize_persona_mapping( $raw, array $roles, array $statuses ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$mapping = array();

		foreach ( array_keys( $this->persona_labels() ) as $persona ) {
			$row    = is_array( $raw[ $persona ] ?? null ) ? $raw[ $persona ] : array();
			$role   = is_scalar( $row['role'] ?? null ) ? (string) $row['role'] : '';
			$status = is_scalar( $row['status'] ?? null ) ? (string) $row['status'] : '';

			if ( '' !== $role && '' !== $status && array_key_exists( $role, $roles ) && array_key_exists( $status, $statuses ) ) {
				$mapping[ $persona ] = array( 'role' => $role, 'status' => $status );
			}
		}

		return $mapping;
	}

	/** @return array<string,array{role:string,status:string}> */
	private function stored_persona_mapping( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$mapping = array();

		foreach ( array_keys( $this->persona_labels() ) as $persona ) {
			$row = is_array( $raw[ $persona ] ?? null ) ? $raw[ $persona ] : array();

			if ( is_scalar( $row['role'] ?? null ) && is_scalar( $row['status'] ?? null ) ) {
				$mapping[ $persona ] = array(
					'role'   => (string) $row['role'],
					'status' => (string) $row['status'],
				);
			}
		}

		return $mapping;
	}

	/** @return array<int,string> */
	private function sanitize_mapping_keys( $raw, array $options ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$valid = array();

		foreach ( $raw as $key ) {
			$key = is_scalar( $key ) ? (string) $key : '';

			if ( '' !== $key && array_key_exists( $key, $options ) && ! in_array( $key, $valid, true ) ) {
				$valid[] = $key;
			}
		}

		return $valid;
	}

	/** @return array<int,string> */
	private function stored_mapping_keys( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();

		foreach ( $raw as $key ) {
			if ( is_scalar( $key ) && '' !== (string) $key && ! in_array( (string) $key, $keys, true ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	private function um_mapping_nonce_is_valid(): bool {
		if ( ! isset( $_POST[ self::UM_MAPPING_NONCE_NAME ] ) || ! is_scalar( $_POST[ self::UM_MAPPING_NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::UM_MAPPING_NONCE_NAME ] ) );

		return false !== wp_verify_nonce( $nonce, self::UM_MAPPING_NONCE_ACTION );
	}

	/** @return array{availability:string,reason:string,personas:array,member_roles:array,approved_statuses:array} */
	private function unavailable_mapping( string $reason ): array {
		return array(
			'availability'      => 'unavailable',
			'reason'            => $reason,
			'personas'          => array(),
			'member_roles'      => array(),
			'approved_statuses' => array(),
		);
	}

	/**
	 * A missing or stale UM mapping keeps both enhanced layouts on their native/observe
	 * path even if an older stored public_layout value passed the compatibility gate.
	 */
	private function enforce_um_mapping( string $mode ): string {
		if ( self::LAYOUT_MODE_PUBLIC_LAYOUT !== $mode ) {
			return $mode;
		}

		return 'available' === $this->get_um_member_mapping()['availability']
			? $mode
			: self::LAYOUT_MODE_OBSERVE;
	}

	/**
	 * Restricts the Amelia sync value to the four documented modes; anything else —
	 * including garbage, empty, or hostile input — lands on `off`, which is a pure no-op
	 * flag and never triggers cleanup of any kind.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_amelia_sync_mode( $value ): string {
		$allowed = array(
			self::SYNC_MODE_OFF,
			self::SYNC_MODE_DISCOVER_ONLY,
			self::SYNC_MODE_MANAGED_ADDITIONS,
			self::SYNC_MODE_MANAGED_SYNC,
		);

		return in_array( (string) $value, $allowed, true )
			? (string) $value
			: self::SYNC_MODE_OFF;
	}

	/**
	 * Restricts a layout mode value to the two modes this bridge supports, and only lets
	 * "public_layout" through when the compatibility gate passes for this capability.
	 * Every gate failure (or a missing gate) resets the mode to "observe" and records a
	 * specific admin-facing reason; the result is never taken from the request beyond the
	 * submitted value itself, nor from any Markdown source.
	 *
	 * @param mixed  $value      Raw submitted value.
	 * @param string $capability CompatibilityGate::CAP_* constant for this mode.
	 * @return string
	 */
	private function sanitize_layout_mode( $value, string $capability ): string {
		if ( self::LAYOUT_MODE_PUBLIC_LAYOUT !== $value ) {
			return self::LAYOUT_MODE_OBSERVE;
		}

		if ( ! $this->public_layout_allowed( $capability ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'hal_member_profiles_' . $capability . '_layout_gate',
					null === $this->compatibility_gate
						? __( '"Public layout" was not saved: the runtime compatibility gate is not available, so the bridge stays on "Observe" until it is wired and passes.', 'hal-member-profiles' )
						: __( '"Public layout" was not saved: the compatibility gate has not passed for this site\'s current component versions (see docs/compatibility-matrix.md). The mode stayed "Observe".', 'hal-member-profiles' ),
					'warning'
				);
			}

			return self::LAYOUT_MODE_OBSERVE;
		}

		return self::LAYOUT_MODE_PUBLIC_LAYOUT;
	}

	/**
	 * Whether public_layout may run for a capability right now. A missing gate fails closed.
	 *
	 * @param string $capability CompatibilityGate::CAP_* constant.
	 * @return bool
	 */
	private function public_layout_allowed( string $capability ): bool {
		if ( null === $this->compatibility_gate ) {
			return false;
		}

		return $this->compatibility_gate->effective_passes( $capability );
	}

	/**
	 * Coerces a stored mode at read time: public_layout only survives when the gate
	 * currently passes; anything else stays as stored (observe).
	 *
	 * @param string $mode       Stored layout mode.
	 * @param string $capability CompatibilityGate::CAP_* constant.
	 * @return string
	 */
	private function enforced_layout_mode( string $mode, string $capability ): string {
		if ( self::LAYOUT_MODE_PUBLIC_LAYOUT === $mode && ! $this->public_layout_allowed( $capability ) ) {
			return self::LAYOUT_MODE_OBSERVE;
		}

		return $mode;
	}

	/**
	 * Accepts a template ID only if it is currently a valid, published Elementor library template.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	private function sanitize_library_template_id( $value ): int {
		return $this->valid_template_id_or_null( absint( $value ) ) ?? 0;
	}

	/**
	 * Sanitizes the per-member-type public-profile Elementor template IDs (OD-06 /
	 * card C7). Admin-gated by sanitize() itself; every value is absint'd and must be a
	 * currently valid, published Elementor library template, otherwise it stores 0 —
	 * which makes that member type read the legacy single template ID instead. No
	 * template or file is ever created, edited, or written by this save path.
	 *
	 * @param mixed $raw Raw submitted per-persona map.
	 * @return array<string,int>
	 */
	private function sanitize_persona_template_ids( $raw ): array {
		$submitted = is_array( $raw ) ? $raw : array();
		$clean     = array();

		foreach ( array_keys( $this->persona_labels() ) as $persona ) {
			$clean[ $persona ] = $this->valid_template_id_or_null( absint( $submitted[ $persona ] ?? 0 ) ) ?? 0;
		}

		return $clean;
	}

	/**
	 * Accepts a user ID only if the user actually exists.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	private function sanitize_fixture_user_id( $value ): int {
		return $this->valid_user_id_or_null( absint( $value ) ) ?? 0;
	}

	/**
	 * Accepts a booking URL only if it parses with an http/https scheme; empty clears it.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_booking_url( $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );

		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * Confirms a template ID is a published post built with Elementor, not a Page/Revision/Draft.
	 *
	 * @param int $id Template ID.
	 * @return int|null
	 */
	private function valid_template_id_or_null( int $id ): ?int {
		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( 'elementor_library' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( 'builder' !== get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			return null;
		}

		return $id;
	}

	/**
	 * Confirms a user ID belongs to an existing account.
	 *
	 * @param int $id User ID.
	 * @return int|null
	 */
	private function valid_user_id_or_null( int $id ): ?int {
		if ( $id <= 0 ) {
			return null;
		}

		return get_userdata( $id ) ? $id : null;
	}

	/**
	 * Whether the current request is a manage_options user inside the Elementor editor.
	 *
	 * @return bool
	 */
	private function is_editor_context_for_manager(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || null === \Elementor\Plugin::$instance ) {
			return false;
		}

		return (bool) \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
