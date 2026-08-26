<?php
/**
 * GitHub Releases update checking via YahnisElsts\PluginUpdateChecker (~5.7.0).
 *
 * The library namespace is version-pinned by composer.json's exact constraint
 * ("~5.7.0" ⇔ "v5p7"); changing one requires changing the other together.
 *
 * @package HAL\MemberProfiles
 */

declare( strict_types=1 );

namespace HAL\MemberProfiles;

use YahnisElsts\PluginUpdateChecker\v5p7\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api as VcsApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {

	/**
	 * Matches ONLY this plugin's STABLE release assets, as named by
	 * .github/workflows/release.yml ("hal-member-profiles-X.Y.Z.zip"). Pre-release
	 * candidates ("hal-member-profiles-X.Y.Z-rc.N.zip") deliberately do NOT match, so an
	 * accidentally published RC can never be selected by the checker. Without a matching
	 * asset the update check fails closed via REQUIRE_RELEASE_ASSETS below — it must
	 * never fall back to a source archive that lacks vendor/.
	 *
	 * @var string
	 */
	private const ASSET_REGEX = '/^hal-member-profiles-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i';

	public static function init(): void {
		if ( ! defined( 'HAL_MEMBER_PROFILES_GITHUB_REPO' ) || '' === HAL_MEMBER_PROFILES_GITHUB_REPO ) {
			return;
		}

		$library = plugin_dir_path( HAL_MEMBER_PROFILES_FILE ) . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

		if ( ! is_readable( $library ) ) {
			return;
		}

		// إن سبقتنا إضافة أخرى إلى تحميل نفس الـnamespace فتجاوز التحميل واستخدم نسختها؛
		// ملفات المكتبة محمية داخليًا بـclass_exists(false) فالتكرار آمن أصلًا.
		if ( ! class_exists( PucFactory::class, false ) ) {
			require_once $library;
		}

		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		try {
			PucFactory::buildUpdateChecker(
				HAL_MEMBER_PROFILES_GITHUB_REPO,
				HAL_MEMBER_PROFILES_FILE,
				'hal-member-profiles'
			)->getVcsApi()->enableReleaseAssets(
				self::ASSET_REGEX,
				VcsApi::REQUIRE_RELEASE_ASSETS
			);
		} catch ( \Throwable ) {
			// لا تسمح لعطل منظومة التحديث بإسقاط عمل الإضافة أبدًا.
		}
	}
}

add_action( 'plugins_loaded', array( Updater::class, 'init' ), 20 );
