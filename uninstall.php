<?php
/**
 * Uninstall Image Optimizer PRO
 * Plugin uninstall routine
 *
 * @package ImageOptimizerPro
 */

// phpcs:disable WordPress.WhiteSpace.PrecisionAlignment.Found

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once 'plugin.php';


if ( is_multisite() ) {
	$sites = get_sites();
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		image_optimizer_pro_uninstall_site();
		restore_current_blog();
	}
} else {
	image_optimizer_pro_uninstall_site();
}

/**
 * Uninstall Image Optimizer PRO
 *
 * @since 1.0
 */
function image_optimizer_pro_uninstall_site() {
	// remove license key
	delete_option( \ImageOptimizerPro\Constants\LICENSE_KEY_OPTION );
	delete_site_option( \ImageOptimizerPro\Constants\LICENSE_KEY_OPTION );

	// delete license transient
	delete_transient( \ImageOptimizerPro\Constants\LICENSE_INFO_TRANSIENT );
	delete_site_transient( \ImageOptimizerPro\Constants\LICENSE_INFO_TRANSIENT );
}
