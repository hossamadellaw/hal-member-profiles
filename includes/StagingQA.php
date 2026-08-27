<?php
/**
 * Staging-QA escape hatch (Integration Closure #7) — fail-closed by construction.
 *
 * Purpose: on a DEDICATED staging environment, an authorized operator may enable deeper
 * QA exercise of gated features BEFORE the compatibility matrix has signed Pass rows
 * (the QA that PRODUCES those rows). It must never exist in production.
 *
 * Double-keyed enablement — BOTH must hold, no Request/input override exists anywhere:
 *   1. WP_ENVIRONMENT_TYPE is literally 'staging' (wp-config / server constant);
 *   2. the server-side option flag hal_member_profiles_staging_qa_enabled holds one of
 *      the CANONICAL TRUE PERSISTED SHAPES — bool true (the same-request read right
 *      after update_option()), int 1, or the string "1" (what WordPress's database
 *      round-trip actually returns on any LATER request). Anything else — false, 0,
 *      "0", "", "true", "yes", null, arrays, objects — fails closed. The flag is
 *      toggled only out-of-band (WP-CLI / code), never through a UI or Request.
 *
 * Production safety: on any production box condition #1 is false regardless of flags or
 * request parameters, so every consumer stays on its strict gate path.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StagingQA {

	const FLAG_OPTION = 'hal_member_profiles_staging_qa_enabled';

	/**
	 * @return bool Whether the staging-QA path is fully enabled right now.
	 */
	public static function enabled(): bool {
		if ( ! self::is_staging_environment() ) {
			return false;
		}

		// Single read. WordPress does NOT preserve boolean types across requests:
		// update_option(true) comes back from the database as the string "1". Accept
		// ONLY the canonical true shapes with strict identity comparisons — bool true
		// (fresh in-request value), int 1, string "1" — and fail closed for every other
		// type or spelling. No loose comparison, no Request override, no filter here.
		$flag = get_option( self::FLAG_OPTION, false );

		if ( true === $flag || 1 === $flag || '1' === $flag ) {
			return true;
		}

		return false;
	}

	/**
	 * Environment check honoring WordPress' own resolver first (which reads the
	 * WP_ENVIRONMENT_TYPE constant), falling back to the literal constant.
	 *
	 * @return bool
	 */
	public static function is_staging_environment(): bool {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return 'staging' === wp_get_environment_type();
		}

		return defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE;
	}
}
