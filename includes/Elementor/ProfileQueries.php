<?php
/**
 * Deferred Elementor Pro Loop/Grid feature: the Profile owner's own posts/courses only.
 *
 * @package HAL\MemberProfiles\Elementor
 */

namespace HAL\MemberProfiles\Elementor;

use HAL\MemberProfiles\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProfileQueries {

	const QUERY_ID_POSTS   = 'hal_member_profiles_posts';
	const QUERY_ID_COURSES = 'hal_member_profiles_courses';

	/**
	 * Wires every registered Query ID via Elementor's own documented custom query filter
	 * (elementor/query/{$query_id}). A site builder must still set the matching "Query ID"
	 * string in a Loop Grid/Posts widget's own Query settings to activate a given filter —
	 * that wiring happens in the Elementor editor, not from this class.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'elementor/query/' . self::QUERY_ID_POSTS, array( __CLASS__, 'filter_posts' ) );
		add_action( 'elementor/query/' . self::QUERY_ID_COURSES, array( __CLASS__, 'filter_courses' ) );
	}

	/**
	 * Restricts a Loop Grid/Posts query to the resolved Profile owner's own published
	 * 'post' content only.
	 *
	 * @param \WP_Query $query Elementor's underlying query.
	 * @return void
	 */
	public static function filter_posts( $query ): void {
		self::restrict_to_profile_owner( $query, 'post' );
	}

	/**
	 * Restricts a Loop Grid/Posts query to the resolved Profile owner's own published
	 * 'course' content only. Verify the 'course' post type slug against this site's real,
	 * documented content-ownership contract before enabling this Query ID in a live
	 * template — a mismatched slug only ever returns zero results, never someone else's
	 * content, since post_type and author are both restricted together below.
	 *
	 * @param \WP_Query $query Elementor's underlying query.
	 * @return void
	 */
	public static function filter_courses( $query ): void {
		self::restrict_to_profile_owner( $query, 'course' );
	}

	/**
	 * The shared restriction: target user's own published content of one post type only,
	 * and zero results whenever Profile Context cannot be resolved — never a query a
	 * visitor can steer, and never private/draft content.
	 *
	 * @param \WP_Query $query     Elementor's underlying query.
	 * @param string    $post_type Post type to restrict to.
	 * @return void
	 */
	private static function restrict_to_profile_owner( $query, string $post_type ): void {
		$query->set( 'post_status', 'publish' );
		$query->set( 'post_type', $post_type );

		$bootstrap = Bootstrap::instance();

		if ( null === $bootstrap ) {
			self::force_no_results( $query );
			return;
		}

		$profile_context = $bootstrap->get_profile_context();

		if ( null === $profile_context ) {
			self::force_no_results( $query );
			return;
		}

		$context = $profile_context->resolve();

		if ( null === $context ) {
			self::force_no_results( $query );
			return;
		}

		$target_user_id = (int) $context->target_user->ID;

		/**
		 * Filters the content-ownership author ID this Query ID restricts to. Defaults to
		 * the resolved Profile owner's own user ID (WordPress's native post_author) — one
		 * possible ownership contract, never assumed to be the only one. Sites documenting
		 * a different contract for a post type (e.g. a custom "instructor" meta key for
		 * courses) must supply their own callback here instead of relying on the default.
		 *
		 * @param int    $target_user_id Resolved Profile owner ID.
		 * @param string $post_type      Post type being queried.
		 */
		$author_id = (int) apply_filters( 'hal_member_profiles_query_owner_id', $target_user_id, $post_type );

		if ( $author_id <= 0 ) {
			self::force_no_results( $query );
			return;
		}

		$query->set( 'author', $author_id );
	}

	/**
	 * Forces an empty result set without erroring the page — the safe outcome whenever
	 * Profile Context cannot be resolved.
	 *
	 * @param \WP_Query $query Elementor's underlying query.
	 * @return void
	 */
	private static function force_no_results( $query ): void {
		$query->set( 'post__in', array( 0 ) );
	}
}
