<?php
/**
 * Tests for second-factor validation outside wp-login.php, and for login-flow bypasses.
 *
 * @package Two_Factor
 */

/**
 * Class Test_Two_Factor_Second_Factor_Security
 *
 * @package Two_Factor
 * @group core
 * @group second-factor-security
 */
class Test_Two_Factor_Second_Factor_Security extends WP_UnitTestCase {

	/**
	 * Clean up request state between tests.
	 */
	public function tear_down() {
		unset( $_REQUEST['authcode'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
		delete_option( 'faustwp_settings' );

		parent::tear_down();
	}

	/**
	 * Create a user with TOTP as their only method.
	 *
	 * @return array{0: WP_User, 1: string} The user and their TOTP key.
	 */
	private function create_totp_user() {
		$user = self::factory()->user->create_and_get();
		$key  = Two_Factor_Totp::generate_key();

		Two_Factor_Totp::get_instance()->set_user_totp_key( $user->ID, $key );
		Two_Factor_Core::enable_provider_for_user( $user->ID, 'Two_Factor_Totp' );

		return array( $user, $key );
	}

	/**
	 * A correct code is accepted and clears earlier failures.
	 *
	 * @covers Two_Factor_Core::validate_second_factor
	 */
	public function test_valid_code_is_accepted_and_clears_failures() {
		list( $user, $key ) = $this->create_totp_user();
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 );

		$_REQUEST['authcode'] = Two_Factor_Totp::calc_totp( $key );

		$this->assertTrue( Two_Factor_Core::validate_second_factor( $user, 'Two_Factor_Totp' ) );
		$this->assertSame( '', get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
	}

	/**
	 * A wrong code is rejected, counted, and reported to wp_login_failed.
	 *
	 * @covers Two_Factor_Core::validate_second_factor
	 */
	public function test_invalid_code_is_rejected_and_counted() {
		list( $user ) = $this->create_totp_user();
		$failed_login = new MockAction();
		add_action( 'wp_login_failed', array( $failed_login, 'action' ) );

		$_REQUEST['authcode'] = '000000';

		$result = Two_Factor_Core::validate_second_factor( $user, 'Two_Factor_Totp' );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_invalid', $result->get_error_code() );
		$this->assertSame( 1, (int) get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		$this->assertSame( 1, $failed_login->get_call_count() );
	}

	/**
	 * Once rate limited, even a correct code is refused without being checked.
	 *
	 * @covers Two_Factor_Core::validate_second_factor
	 */
	public function test_rate_limited_user_is_refused_even_with_valid_code() {
		list( $user, $key ) = $this->create_totp_user();
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 5 );
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );

		$_REQUEST['authcode'] = Two_Factor_Totp::calc_totp( $key );

		$result = Two_Factor_Core::validate_second_factor( $user, 'Two_Factor_Totp' );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_too_fast', $result->get_error_code() );
	}

	/**
	 * A provider the user has not enabled cannot be used to authenticate them.
	 *
	 * @covers Two_Factor_Core::validate_second_factor
	 */
	public function test_provider_not_enabled_for_user_is_refused() {
		list( $user ) = $this->create_totp_user();

		$result = Two_Factor_Core::validate_second_factor( $user, 'Two_Factor_Email' );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_provider_missing', $result->get_error_code() );
	}

	/**
	 * A non-user is refused.
	 *
	 * @covers Two_Factor_Core::validate_second_factor
	 */
	public function test_invalid_user_is_refused() {
		$result = Two_Factor_Core::validate_second_factor( false, 'Two_Factor_Totp' );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_invalid_user', $result->get_error_code() );
	}

	/**
	 * Request headers must never let a 2FA user skip the second factor.
	 *
	 * Regression test: an earlier fork skipped 2FA when Origin or Referer matched a
	 * configured headless frontend URL. Both headers are set by the client.
	 *
	 * @covers Two_Factor_Core::wp_login
	 */
	public function test_wp_login_ignores_spoofed_frontend_origin() {
		list( $user ) = $this->create_totp_user();

		update_option( 'faustwp_settings', array( 'frontend_uri' => 'https://frontend.example.org' ) );
		$_SERVER['HTTP_ORIGIN']  = 'https://frontend.example.org';
		$_SERVER['HTTP_REFERER'] = 'https://frontend.example.org';

		// wp_login() exits after rendering the 2FA prompt; stop it once it clears the password-only session.
		$stop = static function () {
			throw new Two_Factor_Redirect_Exception( 'cleared' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_action( 'clear_auth_cookie', $stop );

		try {
			Two_Factor_Core::wp_login( $user->user_login, $user );
			$this->fail( 'wp_login() returned early instead of requiring the second factor.' );
		} catch ( Two_Factor_Redirect_Exception $e ) {
			$this->assertSame( 'cleared', $e->getMessage() );
		} finally {
			remove_action( 'clear_auth_cookie', $stop );
		}
	}
}
