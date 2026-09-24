<?php
/**
 * Tests for site-wide two-factor enforcement (email by default).
 *
 * @package Two_Factor
 */

/**
 * Class Test_Two_Factor_Force_All_Users
 *
 * @package Two_Factor
 * @group core
 * @group force-all-users
 */
class Test_Two_Factor_Force_All_Users extends WP_UnitTestCase {

	/**
	 * Reset enforcement state between tests.
	 */
	public function tear_down() {
		Two_Factor_Core::cancel_force_all_users_backfill();
		delete_option( Two_Factor_Core::FORCE_ALL_USERS_OPTION_KEY );
		delete_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY );
		delete_option( Two_Factor_Core::FORCE_ALL_USERS_SKIPPED_OPTION );

		parent::tear_down();
	}

	/**
	 * Turn site-wide enforcement on.
	 */
	private function enable_force_all_users() {
		update_option( Two_Factor_Core::FORCE_ALL_USERS_OPTION_KEY, true );
	}

	/**
	 * Run the next backfill batch the way wp-cron does.
	 *
	 * WP-Cron unschedules an event before firing it. Without that, the original event
	 * stays scheduled and would mask a batch that failed to schedule the next one.
	 */
	private function run_scheduled_batch() {
		$timestamp = wp_next_scheduled( Two_Factor_Core::FORCE_ALL_USERS_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Two_Factor_Core::FORCE_ALL_USERS_CRON_HOOK );
		}

		Two_Factor_Core::process_force_all_users_batch();
	}

	/**
	 * Enforcement is off until the option is saved.
	 *
	 * @covers Two_Factor_Core::is_force_all_users_enabled
	 */
	public function test_force_all_users_is_off_by_default() {
		$this->assertFalse( Two_Factor_Core::is_force_all_users_enabled() );

		$this->enable_force_all_users();

		$this->assertTrue( Two_Factor_Core::is_force_all_users_enabled() );
	}

	/**
	 * Users with no methods fall back to email when enforced.
	 *
	 * @covers ::two_factor_maybe_force_email_for_user
	 */
	public function test_unconfigured_user_gets_email_when_enforced() {
		$user_id = self::factory()->user->create();

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );

		$this->enable_force_all_users();

		$this->assertSame( array( 'Two_Factor_Email' ), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertTrue( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * Users who already chose a method keep it.
	 *
	 * @covers ::two_factor_maybe_force_email_for_user
	 */
	public function test_configured_user_keeps_their_providers_when_enforced() {
		$user_id = self::factory()->user->create();
		Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Totp' );

		$this->enable_force_all_users();

		$this->assertSame( array( 'Two_Factor_Totp' ), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
	}

	/**
	 * Users without a deliverable address could never receive a code, so enforcing email would lock them out.
	 *
	 * @covers ::two_factor_maybe_force_email_for_user
	 */
	public function test_user_without_valid_email_is_not_forced() {
		$user_id = self::factory()->user->create( array( 'user_email' => '' ) );

		$this->enable_force_all_users();

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
		$this->assertFalse( Two_Factor_Core::is_user_using_two_factor( $user_id ) );
	}

	/**
	 * Email is not forced when the site has disabled the email provider.
	 *
	 * @covers ::two_factor_maybe_force_email_for_user
	 */
	public function test_email_not_forced_when_site_disables_email_provider() {
		$user_id = self::factory()->user->create();
		update_option( Two_Factor_Core::ENABLED_PROVIDERS_OPTION_KEY, array( 'Two_Factor_Totp' ) );

		$this->enable_force_all_users();

		$this->assertSame( array(), Two_Factor_Core::get_enabled_providers_for_user( $user_id ) );
	}

	/**
	 * Enrolling a user stores email as enabled and primary.
	 *
	 * @covers Two_Factor_Core::enable_email_for_user
	 */
	public function test_enable_email_for_user_sets_meta() {
		$user_id = self::factory()->user->create();

		$this->assertTrue( Two_Factor_Core::enable_email_for_user( $user_id ) );
		$this->assertSame( array( 'Two_Factor_Email' ), get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertSame( 'Two_Factor_Email', get_user_meta( $user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, true ) );
	}

	/**
	 * Enrolling leaves an already configured user untouched.
	 *
	 * @covers Two_Factor_Core::enable_email_for_user
	 */
	public function test_enable_email_for_user_leaves_configured_user_alone() {
		$user_id = self::factory()->user->create();
		Two_Factor_Core::enable_provider_for_user( $user_id, 'Two_Factor_Totp' );

		$this->assertTrue( Two_Factor_Core::enable_email_for_user( $user_id ) );
		$this->assertSame( array( 'Two_Factor_Totp' ), get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * Users without a valid address are never enrolled.
	 *
	 * @covers Two_Factor_Core::enable_email_for_user
	 */
	public function test_enable_email_for_user_skips_user_without_valid_email() {
		$user_id = self::factory()->user->create( array( 'user_email' => '' ) );

		$this->assertFalse( Two_Factor_Core::enable_email_for_user( $user_id ) );
		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * Scheduling and cancelling the backfill manages the cron event and offset.
	 *
	 * @covers Two_Factor_Core::schedule_force_all_users_backfill
	 * @covers Two_Factor_Core::cancel_force_all_users_backfill
	 * @covers Two_Factor_Core::is_force_all_users_backfill_running
	 */
	public function test_schedule_and_cancel_backfill() {
		Two_Factor_Core::schedule_force_all_users_backfill();

		$this->assertTrue( Two_Factor_Core::is_force_all_users_backfill_running() );
		$this->assertSame( 0, (int) get_option( Two_Factor_Core::FORCE_ALL_USERS_BATCH_OFFSET_OPTION ) );

		Two_Factor_Core::cancel_force_all_users_backfill();

		$this->assertFalse( Two_Factor_Core::is_force_all_users_backfill_running() );
		$this->assertFalse( get_option( Two_Factor_Core::FORCE_ALL_USERS_BATCH_OFFSET_OPTION ) );
	}

	/**
	 * A batch enrols eligible users, records its position and schedules the next batch.
	 *
	 * @covers Two_Factor_Core::process_force_all_users_batch
	 */
	public function test_batch_enrols_users_and_schedules_next_batch() {
		$unconfigured = self::factory()->user->create();
		$no_email     = self::factory()->user->create( array( 'user_email' => '' ) );

		$this->enable_force_all_users();
		Two_Factor_Core::schedule_force_all_users_backfill();
		$this->run_scheduled_batch();

		$this->assertSame( array( 'Two_Factor_Email' ), get_user_meta( $unconfigured, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $no_email, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertSame( $no_email, (int) get_option( Two_Factor_Core::FORCE_ALL_USERS_BATCH_OFFSET_OPTION ) );
		$this->assertTrue( Two_Factor_Core::is_force_all_users_backfill_running(), 'The batch should schedule the next one.' );

		// The next batch finds no more users and ends the backfill.
		$this->run_scheduled_batch();

		$this->assertFalse( Two_Factor_Core::is_force_all_users_backfill_running() );
	}

	/**
	 * Users the backfill cannot enrol are counted for the settings page, and the count survives the run ending.
	 *
	 * @covers Two_Factor_Core::process_force_all_users_batch
	 * @covers Two_Factor_Core::get_force_all_users_skipped_count
	 */
	public function test_batch_counts_users_it_cannot_enrol() {
		self::factory()->user->create( array( 'user_email' => '' ) );
		self::factory()->user->create( array( 'user_email' => '' ) );
		self::factory()->user->create();

		$this->enable_force_all_users();
		Two_Factor_Core::schedule_force_all_users_backfill();
		$this->run_scheduled_batch();
		$this->run_scheduled_batch();

		$this->assertFalse( Two_Factor_Core::is_force_all_users_backfill_running() );
		$this->assertSame( 2, Two_Factor_Core::get_force_all_users_skipped_count() );

		// A fresh run starts counting again.
		Two_Factor_Core::schedule_force_all_users_backfill();

		$this->assertSame( 0, Two_Factor_Core::get_force_all_users_skipped_count() );
	}

	/**
	 * Batches continue after the last processed user ID, so deleting users between batches cannot skip anyone.
	 *
	 * @covers Two_Factor_Core::process_force_all_users_batch
	 */
	public function test_batch_resumes_after_last_user_id_when_users_are_deleted() {
		$processed = self::factory()->user->create();
		$deleted   = self::factory()->user->create();
		$pending   = self::factory()->user->create();

		$this->enable_force_all_users();
		Two_Factor_Core::schedule_force_all_users_backfill();

		// Simulate an earlier batch that stopped at $deleted, which is then removed.
		update_option( Two_Factor_Core::FORCE_ALL_USERS_BATCH_OFFSET_OPTION, $deleted );
		wp_delete_user( $deleted );

		$this->run_scheduled_batch();

		$this->assertSame( array( 'Two_Factor_Email' ), get_user_meta( $pending, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $processed, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ), 'Users before the cursor are not revisited.' );
	}

	/**
	 * A batch cancels itself when enforcement has been switched off.
	 *
	 * @covers Two_Factor_Core::process_force_all_users_batch
	 */
	public function test_batch_stops_when_enforcement_turned_off() {
		$user_id = self::factory()->user->create();

		Two_Factor_Core::schedule_force_all_users_backfill();
		$this->run_scheduled_batch();

		$this->assertFalse( Two_Factor_Core::is_force_all_users_backfill_running() );
		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * New users are enrolled on registration while enforced.
	 *
	 * @covers ::two_factor_enable_email_for_new_user
	 */
	public function test_new_user_is_enrolled_when_enforced() {
		$this->enable_force_all_users();

		$user_id = self::factory()->user->create();

		$this->assertSame( array( 'Two_Factor_Email' ), get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * New users are not enrolled when enforcement is off.
	 *
	 * @covers ::two_factor_enable_email_for_new_user
	 */
	public function test_new_user_is_not_enrolled_when_not_enforced() {
		$user_id = self::factory()->user->create();

		$this->assertSame( '', get_user_meta( $user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, true ) );
	}

	/**
	 * Uninstall removes enforcement options and the scheduled backfill.
	 *
	 * @covers Two_Factor_Core::uninstall
	 */
	public function test_uninstall_removes_enforcement_options() {
		$this->enable_force_all_users();
		Two_Factor_Core::schedule_force_all_users_backfill();

		Two_Factor_Core::uninstall();

		$this->assertFalse( get_option( Two_Factor_Core::FORCE_ALL_USERS_OPTION_KEY ) );
		$this->assertFalse( get_option( Two_Factor_Core::FORCE_ALL_USERS_BATCH_OFFSET_OPTION ) );
		$this->assertFalse( get_option( Two_Factor_Core::FORCE_ALL_USERS_SKIPPED_OPTION ) );
		$this->assertFalse( Two_Factor_Core::is_force_all_users_backfill_running() );
	}
}
