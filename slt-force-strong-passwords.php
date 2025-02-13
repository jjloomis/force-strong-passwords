<?php
/**
 * Plugin Name:  Force Strong Passwords (Custom)
 * Plugin URI:   https://github.com/jjloomis/force-strong-passwords
 * Description:  Forces privileged users to set a strong password (custom version)
 * Version:      1.8.1
 * Author:       Jason Cosper
 * Author URI:   http://jasoncosper.com/
 * License:      GPLv3
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:  force-strong-passwords
 * Domain Path:  /languages
 *
 * @link         https://jasoncosper.com/
 * @package      WordPress
 * @author       Jason Cosper
 * @version      1.8.1
 */

global $wp_version;


// Make sure we don't expose any info if called directly.
if ( ! function_exists( 'add_action' ) ) {
	esc_html_e( "Hi there! I'm just a plugin, not much I can do when called directly.", 'slt-force-strong-passwords' );
	exit;
}


/**
 * Initialize constants.
 */

// Our plugin.
define( 'FSP_PLUGIN_BASE', __FILE__ );

// Allow changing the version number in only one place (the header above).
$plugin_data = get_file_data( FSP_PLUGIN_BASE, array( 'Version' => 'Version' ) );
define( 'FSP_PLUGIN_VERSION', $plugin_data['Version'] );

/**
 * Custom tweak so plugin works with newer versions of PHP.
 */
define( 'SLT_FSP_USE_ZXCVBN', true );

if ( ! defined( 'SLT_FSP_CAPS_CHECK' ) ) {
	/**
	 * The default capabilities that will be checked for to trigger strong password enforcement
	 *
	 * @deprecated  Please use the slt_fsp_caps_check filter to customize the capabilities check for enforcement
	 * @since       1.1
	 */
	define( 'SLT_FSP_CAPS_CHECK', 'publish_posts,upload_files,edit_published_posts' );
}


// Initialize other stuff.
add_action( 'plugins_loaded', 'slt_fsp_init' );
function slt_fsp_init() {

	// Text domain for translation.
	load_plugin_textdomain( 'slt-force-strong-passwords', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	// Hooks.
	add_action( 'user_profile_update_errors', 'slt_fsp_validate_profile_update', 0, 3 );
	add_action( 'validate_password_reset', 'slt_fsp_validate_strong_password', 10, 2 );
	add_action( 'resetpass_form', 'slt_fsp_validate_resetpass_form', 10 );

	if ( SLT_FSP_USE_ZXCVBN ) {

		// Enforce zxcvbn check with JS by passing strength check through to server.
		add_action( 'admin_enqueue_scripts', 'slt_fsp_enqueue_force_zxcvbn_script' );
		add_action( 'login_enqueue_scripts', 'slt_fsp_enqueue_force_zxcvbn_script' );

	}

}

/**
 * Enqueue `force-zxcvbn` check script.
 * Gives you the unminified version if `SCRIPT_DEBUG` is set to 'true'.
 */
function slt_fsp_enqueue_force_zxcvbn_script() {
	$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
	wp_enqueue_script( 'slt-fsp-force-zxcvbn', plugin_dir_url( __FILE__ ) . 'force-zxcvbn' . $suffix . '.js', array( 'jquery' ), FSP_PLUGIN_VERSION );
	wp_enqueue_script( 'slt-fsp-admin-js', plugin_dir_url( __FILE__ ) . 'js-admin' . $suffix . '.js', array( 'jquery' ), FSP_PLUGIN_VERSION );
}

/**
 * Check user profile update and throw an error if the password isn't strong.
 */
function slt_fsp_validate_profile_update( $errors, $update, $user_data ) {
	return slt_fsp_validate_strong_password( $errors, $user_data );
}

/**
 * Check password reset form and throw an error if the password isn't strong.
 */
function slt_fsp_validate_resetpass_form( $user_data ) {
	return slt_fsp_validate_strong_password( false, $user_data );
}


/**
 * Functionality used by both user profile and reset password validation.
 */
function slt_fsp_validate_strong_password( $errors, $user_data ) {
	$password_ok = true;
	$enforce     = true;
	$password    = ( isset( $_POST['pass1'] ) && trim( $_POST['pass1'] ) ) ? sanitize_text_field( $_POST['pass1'] ) : false;
	$role        = isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : false;
	$user_id     = isset( $user_data->ID ) ? sanitize_text_field( $user_data->ID ) : false;
	$username    = isset( $_POST['user_login'] ) ? sanitize_text_field( $_POST['user_login'] ) : $user_data->user_login;

	// No password set?
	// Already got a password error?
	if ( ( false === $password ) || ( is_wp_error( $errors ) && $errors->get_error_data( 'pass' ) ) ) {
		return $errors;
	}

	// Should a strong password be enforced for this user?
	if ( $user_id ) {

		// User ID specified.
		$enforce = slt_fsp_enforce_for_user( $user_id );

	} else {

		// No ID yet, adding new user - omit check for "weaker" roles.
		if ( $role && in_array( $role, apply_filters( 'slt_fsp_weak_roles', array( 'subscriber', 'contributor' ) ) ) ) {
			$enforce = false;
		}
	}

	// Enforce?
	if ( $enforce ) {

		// Using zxcvbn?
		if ( SLT_FSP_USE_ZXCVBN ) {

			// Check the strength passed from the zxcvbn meter.
			$compare_strong       = html_entity_decode( __( 'strong' ), ENT_QUOTES, 'UTF-8' );
			$compare_strong_reset = html_entity_decode( __( 'hide-if-no-js strong' ), ENT_QUOTES, 'UTF-8' );
			if ( ! in_array( $_POST['slt-fsp-pass-strength-result'], array( null, $compare_strong, $compare_strong_reset ), true ) ) {
				$password_ok = false;
			}
		} else {

			// Old-style check.
			if ( slt_fsp_password_strength( $password, $username ) !== 4 ) {
				$password_ok = false;
			}
		}
	}

	// Error?
	if ( ! $password_ok && is_wp_error( $errors ) ) { // Is this a WP error object?
		$errors->add( 'pass', apply_filters( 'slt_fsp_error_message', __( '<strong>ERROR</strong>: Please make the password a strong one.', 'slt-force-strong-passwords' ) ) );
	}

	return $errors;
}


/**
 * Check whether the given WP user should be forced to have a strong password
 *
 * Tests on basic capabilities that can compromise a site. Doesn't check on higher capabilities.
 * It's assumed the someone who can't publish_posts won't be able to update_core!
 *
 * @since   1.1
 * @uses    SLT_FSP_CAPS_CHECK
 * @uses    apply_filters()
 * @uses    user_can()
 * @param   int $user_id A user ID.
 * @return  boolean
 */
function slt_fsp_enforce_for_user( $user_id ) {
	$enforce = true;

	// Force strong passwords from network admin screens.
	if ( is_network_admin() ) {
		return $enforce;
	}

	$check_caps = explode( ',', SLT_FSP_CAPS_CHECK );
	$check_caps = apply_filters( 'slt_fsp_caps_check', $check_caps );
	$check_caps = (array) $check_caps;
	if ( ! empty( $check_caps ) ) {
		$enforce = false; // Now we won't enforce unless the user has one of the caps specified.
		foreach ( $check_caps as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				$enforce = true;
				break;
			}
		}
	}
	return $enforce;
}


/**
 * Check for password strength - based on JS function in pre-3.7 WP core: /wp-admin/js/password-strength-meter.js
 *
 * @since   1.0
 * @param   string $i   The password.
 * @param   string $f   The user's username.
 * @return  integer 1 = very weak; 2 = weak; 3 = medium; 4 = strong
 */
function slt_fsp_password_strength( $i, $f ) {
	$h = 1;
	$e = 2;
	$b = 3;
	$a = 4;
	$d = 0;
	$g = null;
	$c = null;
	if ( strlen( $i ) < 4 ) {
		return $h;
	}
	if ( strtolower( $i ) === strtolower( $f ) ) {
		return $e;
	}
	if ( preg_match( '/[0-9]/', $i ) ) {
		$d += 10;
	}
	if ( preg_match( '/[a-z]/', $i ) ) {
		$d += 26;
	}
	if ( preg_match( '/[A-Z]/', $i ) ) {
		$d += 26;
	}
	if ( preg_match( '/[^a-zA-Z0-9]/', $i ) ) {
		$d += 31;
	}
	$g = log( pow( $d, strlen( $i ) ) );
	$c = $g / log( 2 );
	if ( $c < 40 ) {
		return $e;
	}
	if ( $c < 56 ) {
		return $b;
	}
	return $a;
}
