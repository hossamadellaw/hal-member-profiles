<?php
/**
 * Pure-PHP unit tests for LayoutContract — zero WordPress dependency, so this suite runs
 * (and is meaningfully verifiable) with or without a WordPress test environment present.
 *
 * @package HAL\MemberProfiles\Tests\Unit
 */

namespace HAL\MemberProfiles\Tests\Unit;

use HAL\MemberProfiles\LayoutContract;
use PHPUnit\Framework\TestCase;

final class LayoutContractTest extends TestCase {

	public function test_profile_contract_invalid_when_empty(): void {
		$contract = new LayoutContract();

		$this->assertFalse( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_valid_with_native_header_navigation_body(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertTrue( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_valid_with_custom_header_instead_of_native(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_CUSTOM_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertTrue( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_invalid_when_both_headers_registered(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_CUSTOM_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertFalse( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_invalid_when_marker_duplicated(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertFalse( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_invalid_when_navigation_missing(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertFalse( $contract->is_profile_contract_valid() );
	}

	public function test_profile_contract_navigation_optional_flag_allows_zero_navigation(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertTrue( $contract->is_profile_contract_valid( true ) );
	}

	public function test_profile_contract_navigation_optional_flag_still_rejects_duplicate_navigation(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertFalse( $contract->is_profile_contract_valid( true ) );
	}

	public function test_account_contract_invalid_when_empty(): void {
		$contract = new LayoutContract();

		$this->assertFalse( $contract->is_account_contract_valid() );
	}

	public function test_account_contract_valid_with_navigation_and_body(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_ACCOUNT_NAVIGATION );
		$contract->register( LayoutContract::MARKER_ACCOUNT_BODY );

		$this->assertTrue( $contract->is_account_contract_valid() );
	}

	public function test_account_contract_invalid_when_body_duplicated(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_ACCOUNT_NAVIGATION );
		$contract->register( LayoutContract::MARKER_ACCOUNT_BODY );
		$contract->register( LayoutContract::MARKER_ACCOUNT_BODY );

		$this->assertFalse( $contract->is_account_contract_valid() );
	}

	public function test_profile_markers_never_satisfy_account_contract(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertFalse( $contract->is_account_contract_valid() );
	}

	public function test_reset_clears_previously_registered_markers(): void {
		$contract = new LayoutContract();
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );
		$this->assertTrue( $contract->is_profile_contract_valid() );

		$contract->reset();

		$this->assertFalse( $contract->is_profile_contract_valid() );
	}

	public function test_unknown_marker_name_is_ignored_without_error(): void {
		$contract = new LayoutContract();
		$contract->register( 'not_a_real_marker' );
		$contract->register( LayoutContract::MARKER_NATIVE_HEADER );
		$contract->register( LayoutContract::MARKER_PROFILE_NAVIGATION );
		$contract->register( LayoutContract::MARKER_PROFILE_BODY );

		$this->assertTrue( $contract->is_profile_contract_valid() );
	}
}
