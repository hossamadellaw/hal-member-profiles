<?php
/**
 * CI fixture generator for the Runtime Compatibility Evidence report — card S-08.
 *
 * Runs WITHOUT WordPress: it feeds the SAME generator class synthetic facts in
 * ci_fixture mode, validates the closed contract offline, and writes the artifact to
 * build/ ONLY (a path that is gitignored and structurally outside the release ZIP).
 *
 * THE ARTIFACT IS NOT LIVE: it never observed a real site, carries
 * evidence_level=contract_fixture and production_observation_claimed=false, and is
 * insufficient for any compatibility sign-off or Matrix row change. It proves the
 * report contract's shape, enums, redaction, and size stability — nothing more.
 *
 * Usage: php tests/runtime/generate-report.php
 * Exit: 0 on success, 1 on any contract failure.
 */

use HAL\MemberProfiles\RuntimeEvidenceReporter;

if ( PHP_SAPI === 'cli' ) {
	// No ABSPATH: this script is a build-time tool, never a web-reachable entry point.
	if ( 'cli-server' === php_sapi_name() || isset( $_SERVER['HTTP_HOST'] ) ) {
		fwrite( STDERR, "generate-report.php is CLI-only\n" );
		exit( 1 );
	}
}

$plugin_root = dirname( __DIR__, 2 );

// The collector class carries WordPress' standard direct-access guard; this build tool
// defines ABSPATH itself so the pure reporter loads without booting WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_root . '/' );
}

require $plugin_root . '/includes/RuntimeEvidenceReporter.php';

/**
 * Reads the plugin version from the main file header (read-only file access; the
 * constant is unavailable because WordPress is not loaded here).
 *
 * @return string
 */
function hal_evidence_fixture_plugin_version(): string {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/hal-member-profiles.php' );

	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $source, $m ) ) {
		return $m[1];
	}

	fwrite( STDERR, "plugin version not found in header\n" );
	exit( 1 );
}

// Synthetic facts exercising the contract's enum space. Nothing here was observed on
// any site: every value is an invented constant, and unknown/junk keys are deliberately
// included to prove the generator's whitelist drops them.
$facts = array(
	'source'                     => RuntimeEvidenceReporter::SOURCE_FIXTURE,
	'plugin_version'             => hal_evidence_fixture_plugin_version(),
	// Junk keys that must NEVER appear in the report (redaction proof by construction).
	'operator_notes'             => 'sentinel-fixture-free-text',
	'amelia_service_ids'         => array( 777, 888 ),
	'environment'                => array(
		'wordpress'       => null,
		'php'             => PHP_VERSION,
		'ultimate_member' => null,
		'elementor'       => null,
		'elementor_pro'   => null,
		'amelia'          => null,
		'theme'           => null,
	),
	'account_selectors'          => array(
		'count' => 0,
		'types' => array(),
	),
	'amelia_reader'              => array(
		'plugin_detected' => true,
		'sync_mode'       => 'discover_only',
		'snapshot_ok'     => true,
		'services'        => 3,
		'employees'       => 2,
		'custom_fields'   => 1,
		'gate_passes'     => false,
	),
	'amelia_writer'              => array(
		'class_present'     => true,
		'route_present'     => true,
		'gate_passes'       => false,
		'sync_mode'         => 'discover_only',
		'mode_allows_write' => false,
		'secret_stored'     => false,
		'secret_decodable'  => null,
		'desired_count'     => 2,
		'ledger_count'      => 1,
		'dry_plan'          => array(
			'to_create' => 1,
			'to_update' => 0,
			'unchanged' => 0,
			'orphaned'  => 0,
		),
	),
	'um_integration'             => array(
		'um_detected'             => true,
		'native_fallback_present' => true,
		'gate_profile'            => false,
		'gate_account'            => false,
		'gate_um_schema'          => false,
		'gate_dynamic_tags'       => false,
	),
	'integrations'               => array(
		'woocommerce'     => false,
		'wpml'            => false,
		'acf'             => false,
		'profile_queries' => false,
	),
);

try {
	$reporter = new RuntimeEvidenceReporter( $facts );
	$json     = $reporter->generate_json();

	// Round-trip proof: the emitted JSON must re-validate against the closed contract.
	RuntimeEvidenceReporter::validate( json_decode( $json, true ) );

	$build_dir = $plugin_root . '/build';

	if ( ! is_dir( $build_dir ) && ! mkdir( $build_dir, 0750, true ) ) {
		fwrite( STDERR, "cannot create build directory\n" );
		exit( 1 );
	}

	$path = $build_dir . '/runtime-evidence-ci_fixture.json';
	file_put_contents( $path, $json );

	echo "runtime-evidence-ci_fixture written: {$path}\n";
	echo 'sha256: ' . hash( 'sha256', $json ) . "\n";
	echo "NOT LIVE — contract_fixture evidence only; insufficient for compatibility sign-off\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'fixture report contract failure: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
