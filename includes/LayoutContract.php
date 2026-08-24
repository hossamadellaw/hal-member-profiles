<?php
/**
 * Prevents publishing an Elementor Library Template that is incomplete or duplicated.
 *
 * @package HAL\MemberProfiles
 */

namespace HAL\MemberProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LayoutContract {

	const MARKER_NATIVE_HEADER      = 'native_header';
	const MARKER_CUSTOM_HEADER      = 'custom_header';
	const MARKER_PROFILE_NAVIGATION = 'profile_navigation';
	const MARKER_PROFILE_BODY       = 'profile_body';
	const MARKER_ACCOUNT_NAVIGATION = 'account_navigation';
	const MARKER_ACCOUNT_BODY       = 'account_body';

	/**
	 * Markers this contract recognizes; anything else is ignored, not fatal.
	 *
	 * @var string[]
	 */
	private const KNOWN_MARKERS = array(
		self::MARKER_NATIVE_HEADER,
		self::MARKER_CUSTOM_HEADER,
		self::MARKER_PROFILE_NAVIGATION,
		self::MARKER_PROFILE_BODY,
		self::MARKER_ACCOUNT_NAVIGATION,
		self::MARKER_ACCOUNT_BODY,
	);

	/**
	 * Marker name => how many times a Widget confirmed actual, non-empty, in-context output.
	 *
	 * @var array<string,int>
	 */
	private array $markers = array();

	/**
	 * Records that a Widget produced real output for this marker. Callers must only call
	 * this after confirming, themselves, that their own render was non-empty, within the
	 * correct context, and completed without exception; this class never inspects output,
	 * parses HTML/DOM/JSON, or checks CSS visibility.
	 *
	 * @param string $marker One of the MARKER_* constants.
	 * @return void
	 */
	public function register( string $marker ): void {
		if ( ! in_array( $marker, self::KNOWN_MARKERS, true ) ) {
			return;
		}

		$this->markers[ $marker ] = ( $this->markers[ $marker ] ?? 0 ) + 1;
	}

	/**
	 * Clears all recorded markers, for a fresh render attempt within the same request.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->markers = array();
	}

	/**
	 * Whether exactly one header marker, one navigation marker, and one body marker were
	 * each registered exactly once, with nothing missing and nothing duplicated.
	 *
	 * @param bool $navigation_optional Reserved for a Form documented and tested to have no
	 *                                  menu/tabs (see this card's own exception clause); must
	 *                                  stay false until that case is confirmed on the real
	 *                                  site and recorded in docs/compatibility-matrix.md.
	 * @return bool
	 */
	public function is_profile_contract_valid( bool $navigation_optional = false ): bool {
		$header_count = $this->count( self::MARKER_NATIVE_HEADER ) + $this->count( self::MARKER_CUSTOM_HEADER );

		if ( 1 !== $header_count ) {
			return false;
		}

		$navigation_count = $this->count( self::MARKER_PROFILE_NAVIGATION );

		if ( ! $navigation_optional && 1 !== $navigation_count ) {
			return false;
		}

		if ( $navigation_optional && $navigation_count > 1 ) {
			return false;
		}

		return 1 === $this->count( self::MARKER_PROFILE_BODY );
	}

	/**
	 * Whether Account Navigation and Account Body were each registered exactly once.
	 *
	 * @return bool
	 */
	public function is_account_contract_valid(): bool {
		return 1 === $this->count( self::MARKER_ACCOUNT_NAVIGATION )
			&& 1 === $this->count( self::MARKER_ACCOUNT_BODY );
	}

	/**
	 * @param string $marker Marker name.
	 * @return int
	 */
	private function count( string $marker ): int {
		return $this->markers[ $marker ] ?? 0;
	}
}
