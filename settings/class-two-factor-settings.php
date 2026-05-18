<?php
/**
 * Admin settings UI for the Two-Factor plugin.
 * Provides a site-wide settings screen for disabling individual Two-Factor providers.
 *
 * @since 0.16
 *
 * @package Two_Factor
 */

/**
 * Settings screen renderer for Two-Factor.
 *
 * @since 0.16
 */
class Two_Factor_Settings {

	/**
	 * Render the settings page.
	 * Also handles saving of settings when the form is submitted.
	 *
	 * @since 0.16
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$users_updated = 0;

		// Handle save.
		if ( isset( $_POST['two_factor_settings_submit'] ) ) {
			check_admin_referer( 'two_factor_save_settings', 'two_factor_settings_nonce' );

			$posted = isset( $_POST['two_factor_enabled_providers'] ) && is_array( $_POST['two_factor_enabled_providers'] ) ? wp_unslash( $_POST['two_factor_enabled_providers'] ) : array();

			// Sanitize posted values immediately.
			$posted = array_map( 'sanitize_text_field', (array) $posted );
			// Remove empty values.
			$enabled = array_values( array_filter( $posted, 'strlen' ) );

			$force_all_users = ! empty( $_POST['two_factor_force_all_users'] );

			if ( $force_all_users && ! in_array( Two_Factor_Core::FORCE_ALL_USERS_DEFAULT_PROVIDER, $enabled, true ) ) {
				$enabled[] = Two_Factor_Core::FORCE_ALL_USERS_DEFAULT_PROVIDER;
			}

			update_option( 'two_factor_enabled_providers', array_values( array_unique( $enabled ) ) );
			update_option( Two_Factor_Core::FORCE_ALL_USERS_OPTION_KEY, $force_all_users );

			if ( $force_all_users ) {
				$users_updated = Two_Factor_Core::apply_force_all_users_to_all_accounts();
			}

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'two-factor' ) . '</p></div>';
			if ( $force_all_users && $users_updated > 0 ) {
				echo '<div class="updated"><p>';
				printf(
					/* translators: %d: number of users */
					esc_html(
						_n(
							'Email two-factor authentication was enabled for %d user who did not have a method configured.',
							'Email two-factor authentication was enabled for %d users who did not have a method configured.',
							$users_updated,
							'two-factor'
						)
					),
					(int) $users_updated
				);
				echo '</p></div>';
			}
		}

		// Build provider list for display using public core API.
		$provider_instances = array();
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_providers' ) ) {
			$provider_instances = Two_Factor_Core::get_providers();
			if ( ! is_array( $provider_instances ) ) {
				$provider_instances = array();
			}
		}

		// Default to all providers enabled when the option has never been saved.
		$all_provider_keys = array_keys( $provider_instances );
		$saved_enabled     = get_option( 'two_factor_enabled_providers', $all_provider_keys );
		$force_all_users   = class_exists( 'Two_Factor_Core' ) && Two_Factor_Core::is_force_all_users_enabled();

		echo '<div class="wrap two-factor-settings">';
		echo '<h1>' . esc_html__( 'Two-Factor Settings', 'two-factor' ) . '</h1>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'two_factor_save_settings', 'two_factor_settings_nonce' );

		echo '<h2>' . esc_html__( 'Enabled Providers', 'two-factor' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Choose which Two-Factor providers are available on this site. All providers are enabled by default.', 'two-factor' ) . '</p>';

		echo '<fieldset class="two-factor-providers"><legend class="screen-reader-text">' . esc_html__( 'Providers', 'two-factor' ) . '</legend>';
		echo '<table class="form-table"><tbody>';

		if ( empty( $provider_instances ) ) {
			echo '<tr><td>' . esc_html__( 'No providers found.', 'two-factor' ) . '</td></tr>';
		} else {
			// Render a compact stacked list of provider checkboxes below the title/description.
			echo '<tr>';
			echo '<td>';
			foreach ( $provider_instances as $provider_key => $instance ) {
				$label = method_exists( $instance, 'get_label' ) ? $instance->get_label() : $provider_key;

				echo '<p class="provider-item"><label for="provider_' . esc_attr( $provider_key ) . '">';
				echo '<input type="checkbox" name="two_factor_enabled_providers[]" id="provider_' . esc_attr( $provider_key ) . '" value="' . esc_attr( $provider_key ) . '" ' . checked( in_array( $provider_key, (array) $saved_enabled, true ), true, false ) . ' /> ';
				echo esc_html( $label );
				echo '</label></p>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</fieldset>';

		echo '<h2>' . esc_html__( 'Site-wide Enforcement', 'two-factor' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Require for all users', 'two-factor' ) . '</th>';
		echo '<td>';
		echo '<label for="two_factor_force_all_users">';
		echo '<input type="checkbox" name="two_factor_force_all_users" id="two_factor_force_all_users" value="1" ' . checked( $force_all_users, true, false ) . ' /> ';
		echo esc_html__( 'Enable two-factor authentication for all users', 'two-factor' );
		echo '</label>';
		echo '<p class="description">';
		echo esc_html__( 'Users without a configured method will use email codes by default. They can add other methods, such as an authenticator app or backup codes, from their profile.', 'two-factor' );
		echo '</p>';
		echo '</td>';
		echo '</tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save Settings', 'two-factor' ), 'primary', 'two_factor_settings_submit' );
		echo '</form>';

		echo '</div>';
	}
}
