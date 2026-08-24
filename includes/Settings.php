<?php
/**
 * Non-sensitive settings that only control whether the bridge is active and its uninstall fate.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	const OPTION_KEY   = 'hal_member_profiles_settings';
	const OPTION_GROUP = 'hal_member_profiles_settings_group';
	const PAGE_SLUG    = 'hal-member-profiles-settings';

	const LAYOUT_MODE_OBSERVE       = 'observe';
	const LAYOUT_MODE_PUBLIC_LAYOUT = 'public_layout';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Adds the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_options_page(
			__( 'HAL Member Profiles', 'hal-member-profiles' ),
			__( 'HAL Member Profiles', 'hal-member-profiles' ),
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HAL Member Profiles', 'hal-member-profiles' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Profile layout mode', 'hal-member-profiles' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_layout_mode]">
								<option value="observe" <?php selected( $settings['profile_layout_mode'], self::LAYOUT_MODE_OBSERVE ); ?>><?php esc_html_e( 'Observe (no visual change)', 'hal-member-profiles' ); ?></option>
								<option value="public_layout" <?php selected( $settings['profile_layout_mode'], self::LAYOUT_MODE_PUBLIC_LAYOUT ); ?>><?php esc_html_e( 'Public layout (Elementor template)', 'hal-member-profiles' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Profile Elementor library template ID', 'hal-member-profiles' ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_library_template_id]" value="<?php echo esc_attr( (string) $settings['profile_library_template_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Account layout mode', 'hal-member-profiles' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_layout_mode]">
								<option value="observe" <?php selected( $settings['account_layout_mode'], self::LAYOUT_MODE_OBSERVE ); ?>><?php esc_html_e( 'Observe (no visual change)', 'hal-member-profiles' ); ?></option>
								<option value="public_layout" <?php selected( $settings['account_layout_mode'], self::LAYOUT_MODE_PUBLIC_LAYOUT ); ?>><?php esc_html_e( 'Public layout (Elementor template)', 'hal-member-profiles' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Account Elementor library template ID', 'hal-member-profiles' ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_library_template_id]" value="<?php echo esc_attr( (string) $settings['account_library_template_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Amelia general booking URL', 'hal-member-profiles' ); ?></th>
						<td><input type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[amelia_booking_url]" value="<?php echo esc_attr( (string) $settings['amelia_booking_url'] ); ?>" placeholder="https://" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Profile preview fixture user ID', 'hal-member-profiles' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[profile_fixture_user_id]" value="<?php echo esc_attr( (string) $settings['profile_fixture_user_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only used to preview this layout inside the Elementor editor, for administrators only — never shown on the live frontend or in a public preview.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Account preview fixture user ID', 'hal-member-profiles' ); ?></th>
						<td>
							<input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[account_fixture_user_id]" value="<?php echo esc_attr( (string) $settings['account_fixture_user_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only used to preview this layout inside the Elementor editor, for administrators only — never shown on the live frontend or in a public preview.', 'hal-member-profiles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge on uninstall', 'hal-member-profiles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[purge_on_uninstall]" value="1" <?php checked( $settings['purge_on_uninstall'] ); ?> />
								<?php esc_html_e( 'Delete these bridge settings on uninstall', 'hal-member-profiles' ); ?>
							</label>
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

		$clean['profile_layout_mode'] = $this->sanitize_layout_mode( $input['profile_layout_mode'] ?? '' );
		$clean['account_layout_mode'] = $this->sanitize_layout_mode( $input['account_layout_mode'] ?? '' );

		$clean['profile_library_template_id'] = $this->sanitize_library_template_id( $input['profile_library_template_id'] ?? 0 );
		$clean['account_library_template_id'] = $this->sanitize_library_template_id( $input['account_library_template_id'] ?? 0 );

		$clean['amelia_booking_url'] = $this->sanitize_booking_url( $input['amelia_booking_url'] ?? '' );

		// manage_options was already confirmed above. This save happens from the normal
		// wp-admin Settings page, which is never itself "inside the Elementor editor" — so
		// is_editor_context_for_manager() must not gate this write path, or a manage_options
		// user could never set these values at all. That check is reserved for the read-time
		// exposure in get_profile_fixture_user_id()/get_account_fixture_user_id() below, where
		// it is still correctly applied.
		$clean['profile_fixture_user_id'] = $this->sanitize_fixture_user_id( $input['profile_fixture_user_id'] ?? 0 );
		$clean['account_fixture_user_id'] = $this->sanitize_fixture_user_id( $input['account_fixture_user_id'] ?? 0 );

		$clean['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		return $clean;
	}

	/**
	 * Active profile layout mode.
	 *
	 * @return string
	 */
	public function get_profile_layout_mode(): string {
		return $this->all()['profile_layout_mode'];
	}

	/**
	 * Active account layout mode.
	 *
	 * @return string
	 */
	public function get_account_layout_mode(): string {
		return $this->all()['account_layout_mode'];
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
			'account_library_template_id' => 0,
			'profile_fixture_user_id'     => 0,
			'account_fixture_user_id'     => 0,
			'amelia_booking_url'          => '',
			'purge_on_uninstall'          => false,
		);
	}

	/**
	 * Restricts a layout mode value to the two modes this bridge supports.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_layout_mode( $value ): string {
		return self::LAYOUT_MODE_PUBLIC_LAYOUT === $value
			? self::LAYOUT_MODE_PUBLIC_LAYOUT
			: self::LAYOUT_MODE_OBSERVE;
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
