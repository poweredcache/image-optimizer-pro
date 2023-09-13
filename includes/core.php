<?php
/**
 * Core plugin functionality.
 *
 * @package ImageOptimizerPro
 */

namespace ImageOptimizerPro\Core;

use const ImageOptimizerPro\Constants\ACTIVATION_REDIRECT_TRANSIENT;

/**
 * Default setup routine
 *
 * @return void
 */
function setup() {
	add_action( 'init', __NAMESPACE__ . '\\i18n' );
	add_action( 'init', __NAMESPACE__ . '\\init' );
	/**
	 * Fires after image optimizer pro loaded
	 *
	 * @hook image_optimizer_pro_loaded
	 *
	 * @since 1.0
	 */
	do_action( 'image_optimizer_pro_loaded' );
}

/**
 * Registers the default textdomain.
 *
 * @return void
 */
function i18n() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'image-optimizer-pro' ); // This filter is documented in /wp-includes/l10n.php.
	load_textdomain( 'image-optimizer-pro', WP_LANG_DIR . '/image-optimizer-pro/image-optimizer-pro-' . $locale . '.mo' );
	load_plugin_textdomain( 'image-optimizer-pro', false, plugin_basename( IMAGE_OPTIMIZER_PRO_PATH ) . '/languages/' );
}

/**
 * Initializes the plugin and fires an action other plugins can hook into.
 *
 * @return void
 */
function init() {
	/**
	 * Fires during init
	 *
	 * @hook image_optimizer_pro_init
	 *
	 * @since 1.0
	 */
	do_action( 'powered_cache_init' );
}

/**
 * Activate the plugin
 *  `IMAGE_OPTIMIZER_PRO_IS_NETWORK` useless on networkwide activation at first
 *
 * @param bool $network_wide Whether network-wide configuration or not
 *
 * @return void
 */
function activate( $network_wide ) {
	if ( defined( 'IMAGE_OPTIMIZER_PRO_LICENSE_KEY' ) ) {
		return;
	}

	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		if ( $network_wide ) {
			set_site_transient( ACTIVATION_REDIRECT_TRANSIENT, true, 30 );
		} else {
			set_transient( ACTIVATION_REDIRECT_TRANSIENT, true, 30 );
		}
	}

}

/**
 * Deactivate the plugin
 *
 * Uninstall routines should be in uninstall.php
 *
 * @param bool $network_wide Whether network-wide configuration or not
 *
 * @return void
 */
function deactivate( $network_wide ) {

}
