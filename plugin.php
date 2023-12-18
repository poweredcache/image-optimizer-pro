<?php
/**
 * Plugin Name:       Image Optimizer Pro
 * Plugin URI:        https://poweredcache.com/image-optimizer-pro/
 * Description:       On-the-fly image optimization for WordPress. It automatically converts and serves images in AVIF or webp format where the browser supports, ensuring faster load times and enhanced user experience.
 * Version:           1.0
 * Requires at least: 5.7
 * Requires PHP:      7.2.5
 * Author:            Powered Cache
 * Author URI:        https://poweredcache.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-optimizer-pro
 * Domain Path:       /languages
 *
 * @package           ImageOptimizerPro
 */

namespace ImageOptimizerPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Useful global constants.
define( 'IMAGE_OPTIMIZER_PRO_VERSION', '1.0' );
define( 'IMAGE_OPTIMIZER_PRO_PLUGIN_FILE', __FILE__ );
define( 'IMAGE_OPTIMIZER_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'IMAGE_OPTIMIZER_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'IMAGE_OPTIMIZER_PRO_INC', IMAGE_OPTIMIZER_PRO_PATH . 'includes/' );
define( 'IMAGE_OPTIMIZER_PRO_REQUIRED_PHP_VERSION', '7.2.5' );


/**
 * Adds an admin notice to installations that don't meet minimum PHP requirement and stop running the plugin
 *
 * @return void
 */
function php_requirements_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	?>
	<div id="message" class="error notice is-dismissible">
		<p><strong><?php esc_html_e( 'Your site does not support Image Optimizer PRO.', 'image-optimizer-pro' ); ?></strong></p>
		<?php /* translators: 1: current PHP version, 2: required PHP version */ ?>
		<p><?php printf( esc_html__( 'Your site is currently running PHP version %1$s, while Image Optimizer PRO requires version %2$s or greater.', 'image-optimizer-pro' ), esc_html( phpversion() ), esc_html( IMAGE_OPTIMIZER_PRO_REQUIRED_PHP_VERSION ) ); ?></p>
		<p><?php esc_html_e( 'Please update your PHP version or deactivate Image Optimizer PRO.', 'image-optimizer-pro' ); ?></p>
	</div>
	<?php
}

if ( version_compare( phpversion(), IMAGE_OPTIMIZER_PRO_REQUIRED_PHP_VERSION, '<' ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\php_requirements_notice' );
	add_action( 'network_admin_notices', __NAMESPACE__ . '\\php_requirements_notice' );

	return;
}

// Require Composer autoloader if it exists.
if ( file_exists( IMAGE_OPTIMIZER_PRO_PATH . '/vendor/autoload.php' ) ) {
	require_once IMAGE_OPTIMIZER_PRO_PATH . 'vendor/autoload.php';
}

// Include files.
require_once IMAGE_OPTIMIZER_PRO_INC . 'constants.php';
require_once IMAGE_OPTIMIZER_PRO_INC . 'utils.php';
require_once IMAGE_OPTIMIZER_PRO_INC . 'core.php';

/**
 * PSR-4-ish autoloading
 *
 * @since 1.0
 */
spl_autoload_register(
	function ( $class ) {
		// project-specific namespace prefix.
		$prefix = 'ImageOptimizerPro\\';

		// base directory for the namespace prefix.
		$base_dir = __DIR__ . '/includes/classes/';

		// does the class use the namespace prefix?
		$len = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );

		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// if the file exists, require it.
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

$network_activated = Utils\is_network_wide( IMAGE_OPTIMIZER_PRO_PLUGIN_FILE );
if ( ! defined( 'IMAGE_OPTIMIZER_PRO_IS_NETWORK' ) ) {
	define( 'IMAGE_OPTIMIZER_PRO_IS_NETWORK', $network_activated );
}

if ( Utils\bypass_request() ) {
	return;
}

Admin\Dashboard::factory();
Optimizer::factory();
\ImageOptimizerPro\Core\setup();

register_activation_hook( __FILE__, '\ImageOptimizerPro\Core\activate' );
