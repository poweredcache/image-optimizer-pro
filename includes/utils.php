<?php
/**
 * Utility functions
 *
 * @package ImageOptimizerPro
 */

namespace ImageOptimizerPro\Utils;

use ImageOptimizerPro\Encryption;
use const ImageOptimizerPro\Constants\LICENSE_ENDPOINT;
use const ImageOptimizerPro\Constants\LICENSE_INFO_TRANSIENT;
use const ImageOptimizerPro\Constants\LICENSE_KEY_OPTION;

/**
 * Is plugin activated network wide?
 *
 * @param string $plugin_file file path
 *
 * @return bool
 */
function is_network_wide( $plugin_file ) {
	if ( ! is_multisite() ) {
		return false;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
	}

	return is_plugin_active_for_network( plugin_basename( $plugin_file ) );
}


/**
 * Check whether request for bypass or process normally
 * Respects Powered Cache's bypass request too
 *
 * @return bool
 */
function bypass_request() {
	if ( isset( $_GET['nopoweredcache'] ) && $_GET['nopoweredcache'] ) { // phpcs:ignore
		return true;
	}

	if ( isset( $_GET['noimageoptimizer'] ) && $_GET['noimageoptimizer'] ) { // phpcs:ignore
		return true;
	}

	return false;
}


/**
 * Get license status
 *
 * @return mixed|void
 */
function get_license_info() {
	$license_info = get_transient( LICENSE_INFO_TRANSIENT );
	$license_key  = get_license_key();
	$license_url  = get_license_url();

	if ( false === $license_info && $license_key ) {
		$api_params = array(
			'action'      => 'info',
			'license_key' => $license_key,
			'license_url' => $license_url,
		);

		$response = wp_remote_post(
			LICENSE_ENDPOINT,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $api_params,
			)
		);

		$license_info = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $license_info ) {
			set_transient( LICENSE_INFO_TRANSIENT, $license_info, HOUR_IN_SECONDS * 12 );

			return $license_info;
		}

		// If the response failed, try again in 30 minutes
		$license_info = [
			'success'        => false,
			'license_status' => 'unknown',
		];

		set_transient( LICENSE_INFO_TRANSIENT, $license_info, MINUTE_IN_SECONDS * 30 );
	}

	return $license_info;
}


/**
 * Get license key
 *
 * @return mixed|void
 * @since 1.0
 */
function get_license_key() {
	if ( defined( 'IMAGE_OPTIMIZER_PRO_LICENSE_KEY' ) && IMAGE_OPTIMIZER_PRO_LICENSE_KEY ) {
		return IMAGE_OPTIMIZER_PRO_LICENSE_KEY;
	}

	if ( IMAGE_OPTIMIZER_PRO_IS_NETWORK ) {
		$license_key = get_site_option( LICENSE_KEY_OPTION );
	} else {
		$license_key = get_option( LICENSE_KEY_OPTION );
	}

	$encryption  = new Encryption();
	$license_key = $encryption->decrypt( $license_key );

	/**
	 * Filter license key
	 *
	 * @hook   image_optimizer_pro_license_key
	 *
	 * @param  {string} $license_key License key.
	 *
	 * @return {string} New value.
	 * @since  1.0
	 */
	return apply_filters( 'image_optimizer_pro_license_key', $license_key );
}


/**
 * Get license url
 *
 * @return string|null
 * @since 1.0
 */
function get_license_url() {
	$license_url = home_url();

	if ( defined( 'IMAGE_OPTIMIZER_PRO_LICENSE_KEY' ) && is_multisite() ) {
		$license_url = network_site_url();
	}

	return $license_url;
}

/**
 * Return user-readable feedback message based on the API response of license check
 *
 * @return mixed|string
 */
function get_license_status_message() {
	// API response for license check
	$license_info = get_license_info();

	if ( $license_info && 'valid' === $license_info['license_status'] ) {
		$message = esc_html__( 'Your license is valid and activated.', 'image-optimizer-pro' );

		if ( isset( $license_info['expires'] ) ) {
			if ( 'lifetime' === $license_info['expires'] ) {
				$message .= esc_html__( 'Lifetime License.', 'image-optimizer-pro' );
			} else {
				$message .= sprintf(
				/* translators: %s: license key expiration time */
					esc_html__( 'Your license key expires on %s.' ),
					date_i18n( get_option( 'date_format' ), strtotime( $license_info['expires'], current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				);
			}
		}

		if ( $license_info['site_count'] && $license_info['license_limit'] ) {
			$message .= sprintf(
			/* translators: %1$s: the number of active sites. %2$s: max sites */
				esc_html__( 'You have %1$s / %2$s sites activated.', 'image-optimizer-pro' ),
				absint( $license_info['site_count'] ),
				absint( $license_info['license_limit'] )
			);
		}
	}

	if ( $license_info && isset( $license_info['errors'] ) && ! empty( $license_info['errors'] ) ) {
		// first err code
		$error_keys = array_keys( $license_info['errors'] );
		$err_code   = isset( $error_keys[0] ) ? $error_keys[0] : 'unkdown';

		switch ( $err_code ) {
			case 'missing_license_key':
				$message = esc_html__( 'License key does not exist', 'image-optimizer-pro' );
				break;

			case 'expired_license_key':
				$message = sprintf(
				/* translators: %s: license key expiration time */
					__( 'Your license key expired on %s.' ),
					date_i18n( get_option( 'date_format' ), strtotime( $license_info['expires'], current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				);
				break;
			case 'unregistered_license_domain':
				$message = esc_html__( 'Unregistered domain address', 'image-optimizer-pro' );
				break;
			case 'invalid_license_or_domain':
				$message = esc_html__( 'Invalid license or url', 'image-optimizer-pro' );
				break;
			case 'can_not_add_new_domain':
				$message = esc_html__( 'Can not add a new domain.', 'image-optimizer-pro' );
				break;

			default:
				$message = esc_html__( 'An error occurred, please try again.', 'image-optimizer-pro' );
				break;
		}
	}

	if ( ! $license_info || ( isset( $license_info['license_status'] ) && 'unknown' === $license_info['license_status'] ) ) {
		$message = esc_html__( 'Please enter a valid license key and activate it.', 'image-optimizer-pro' );
	}

	return $message;
}


/**
 * Mask given string
 *
 * @param string $input_string  String
 * @param int    $unmask_length The lenght of unmask
 *
 * @return string
 * @since 1.0
 */
function mask_string( $input_string, $unmask_length ) {
	$output_string = substr( $input_string, 0, $unmask_length );

	if ( strlen( $input_string ) > $unmask_length ) {
		$output_string .= str_repeat( '*', strlen( $input_string ) - $unmask_length );
	}

	return $output_string;
}


/**
 * If the site is a local site.
 *
 * @return bool
 * @since 1.0
 */
function is_local_site() {
	$site_url = site_url();

	// Check for localhost and sites using an IP only first.
	$is_local = $site_url && false === strpos( $site_url, '.' );

	// Use Core's environment check, if available. Added in 5.5.0 / 5.5.1 (for `local` return value).
	if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
		$is_local = true;
	}

	// Then check for usual usual domains used by local dev tools.
	$known_local = array(
		'#\.local$#i',
		'#\.localhost$#i',
		'#\.test$#i',
		'#\.docksal$#i',      // Docksal.
		'#\.docksal\.site$#i', // Docksal.
		'#\.dev\.cc$#i',       // ServerPress.
		'#\.lndo\.site$#i',    // Lando.
	);

	if ( ! $is_local ) {
		foreach ( $known_local as $url ) {
			if ( preg_match( $url, $site_url ) ) {
				$is_local = true;
				break;
			}
		}
	}

	return $is_local;
}

/**
 * Check license is valid and activated
 *
 * @return bool
 * @since 1.0
 */
function is_license_active() {
	$license_info = get_license_info();
	if ( $license_info && ! empty( $license_info['license_status'] ) && 'valid' === $license_info['license_status'] ) {
		return true;
	}

	return false;
}
