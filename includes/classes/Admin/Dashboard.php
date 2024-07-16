<?php
/**
 * Admin dashboard
 *
 * @package ImageOptimizerPro
 */

namespace ImageOptimizerPro\Admin;

use ImageOptimizerPro\Encryption;
use function ImageOptimizerPro\Utils\get_license_info;
use function ImageOptimizerPro\Utils\get_license_key;
use function ImageOptimizerPro\Utils\get_license_status_message;
use function ImageOptimizerPro\Utils\get_license_url;
use function ImageOptimizerPro\Utils\is_license_active;
use function ImageOptimizerPro\Utils\is_local_site;
use function ImageOptimizerPro\Utils\mask_string;
use const ImageOptimizerPro\Constants\ACTIVATION_REDIRECT_TRANSIENT;
use const ImageOptimizerPro\Constants\LICENSE_ENDPOINT;
use const ImageOptimizerPro\Constants\LICENSE_INFO_TRANSIENT;
use const ImageOptimizerPro\Constants\LICENSE_KEY_OPTION;
use const ImageOptimizerPro\Constants\MENU_SLUG;
use const ImageOptimizerPro\Constants\SETTING_OPTION;

/**
 * Class Dashboard
 */
class Dashboard {
	/**
	 * placeholder
	 *
	 * @since 1.0
	 */
	public function __construct() {
	}

	/**
	 * Return an instance of the current class
	 *
	 * @return Dashboard
	 * @since 1.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}


	/**
	 * Setup routine
	 *
	 * @return void
	 */
	public function setup() {
		if ( IMAGE_OPTIMIZER_PRO_IS_NETWORK ) {
			add_action( 'network_admin_notices', [ $this, 'local_site_notice' ] );
			add_action( 'network_admin_notices', [ $this, 'maybe_add_license_notice' ] );
			add_action( 'network_admin_notices', [ $this, 'maybe_add_pc_notice' ] );
			add_action( 'network_admin_menu', [ $this, 'add_menu' ] );
		} else {
			add_action( 'admin_notices', [ $this, 'local_site_notice' ] );
			add_action( 'admin_notices', [ $this, 'maybe_add_license_notice' ] );
			add_action( 'admin_notices', [ $this, 'maybe_add_pc_notice' ] );
			add_action( 'admin_menu', [ $this, 'add_menu' ] );
		}

		add_action( 'admin_notices', [ $this, 'maybe_display_message' ] );

		add_action( 'admin_init', [ $this, 'save_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_message' ] );
	}

	/**
	 * Either show or hide the notice for the current user
	 *
	 * @return bool
	 */
	private function show_notice() {
		$notice_capability = IMAGE_OPTIMIZER_PRO_IS_NETWORK ? 'manage_network_options' : 'manage_options';

		return current_user_can( $notice_capability );
	}

	/**
	 * Add menu
	 *
	 * @since 1.0
	 */
	public function add_menu() {
		// dont add settings page when the license key is defined
		if ( defined( 'IMAGE_OPTIMIZER_PRO_LICENSE_KEY' ) ) {
			return;
		}

		$capability = IMAGE_OPTIMIZER_PRO_IS_NETWORK ? 'manage_network_options' : 'manage_options';
		$parent     = IMAGE_OPTIMIZER_PRO_IS_NETWORK ? 'settings.php' : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'Image Optimizer Pro', 'image-optimizer-pro' ),
			__( 'Image Optimizer Pro', 'image-optimizer-pro' ),
			$capability,
			'image-optimizer-pro',
			[ $this, 'render_dashboard' ]
		);
	}

	/**
	 * Render dashboard
	 *
	 * @since 1.0
	 */
	public function render_dashboard() {
		$license_key  = get_license_key();
		$license_info = get_license_info();
		$settings     = \ImageOptimizerPro\Utils\get_settings();

		if ( is_network_admin() ) {
			settings_errors();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Image Optimizer Pro', 'image-optimizer-pro' ); ?></h1>
			<form method="post" action="" id="image-optimizer-pro-settings-form" name="image-optimizer-pro-settings-form">
				<?php wp_nonce_field( 'image-optimizer-pro-nonce', 'image-optimizer-pro-settings' ); ?>
				<table class="form-table">
					<tbody>
					<tr>
						<th scope="row"><label for="license_key"><?php esc_html_e( 'License Key', 'image-optimizer-pro' ); ?></label></th>
						<td>
							<input type="text" size="40" id="license_key" name="license_key" value="<?php echo esc_attr( mask_string( $license_key, 3 ) ); ?>">
							<?php if ( false !== $license_info && 'valid' === $license_info['license_status'] ) : ?>
								<input type="submit" class="button-secondary" name="license_deactivate" value="<?php esc_attr_e( 'Deactivate License', 'image-optimizer-pro' ); ?>" />
							<?php else : ?>
								<input type="submit" class="button-secondary" name="license_activate" value="<?php esc_attr_e( 'Activate License', 'image-optimizer-pro' ); ?>" />
							<?php endif; ?>
							<br />
							<span class="description"><?php echo esc_html( get_license_status_message() ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row">
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">
									<span><?php esc_html_e( 'Use WebP over AVIF', 'image-optimizer-pro' ); ?></span>
								</legend>
								<label for="preferred_format">
									<input name="preferred_format" type="checkbox" id="preferred_format" value="webp" <?php checked( 'webp', $settings['preferred_format'] ); ?>>
									<?php esc_html_e( 'Use WebP over AVIF', 'image-optimizer-pro' ); ?>
								</label>
							</fieldset>
							<span class="description">
								<?php esc_html_e( 'Activate this option to prioritize the WebP format over AVIF for image optimization.', 'image-optimizer-pro' ); ?>
							</span>
						</td>
					</tr>
					</tbody>
				</table>
				<p>
					<?php submit_button( esc_html__( 'Save Changes', 'image-optimizer-pro' ), 'submit primary', 'submit', false ); ?>
					<?php if ( ! is_multisite() && ! is_local_site() && false !== $license_info && 'valid' === $license_info['license_status'] ) : ?>
						<a
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=image_optimizer_pro_cache_purge' ), 'image_optimizer_pro_cache_purge' ) ); ?>"
							class="button-secondary" id="clear_image_optimizer_pro_cache">
							<?php esc_html_e( 'Clear Image Optimizer Cache', 'image-optimizer-pro' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</form>
		</div>

		<?php
	}

	/**
	 * Show notice for local site
	 *
	 * @return void
	 * @since 1.0
	 */
	public function local_site_notice() {
		if ( ! $this->show_notice() ) {
			return;
		}
		if ( ! is_local_site() ) {
			return;
		}

		$capability = IMAGE_OPTIMIZER_PRO_IS_NETWORK ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'You cannot use Image Optimizer on localhost. Image Optimization service only works for the public accessible domains.', 'image-optimizer-pro' ); ?></p>
		</div>

		<?php
	}

	/**
	 * Show notice for license
	 *
	 * @return void
	 * @since 1.0
	 */
	public function maybe_add_license_notice() {
		if ( is_license_active() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'You need to activate your license to use Image Optimizer Pro.', 'image-optimizer-pro' ); ?>
			</p>
		</div>

		<?php
	}

	/**
	 * Show notice for Powered Cache when Image Optimizer is already actived with Powered Cache Premium
	 *
	 * @return void
	 * @since 1.0
	 */
	public function maybe_add_pc_notice() {
		if ( ! $this->show_notice() ) {
			return;
		}

		if ( ! defined( 'POWERED_CACHE_PREMIUM_VERSION' ) ) {
			return;
		}

		if ( ! function_exists( '\PoweredCache\Utils\get_settings' ) ) {
			return;
		}

		$setting = \PoweredCache\Utils\get_settings();

		if ( ! $setting['enable_image_optimization'] ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><?php echo wp_kses_post( __( 'Image Optimizer is already actived on Powered Cache Premium. You can mange it under the settings: <code>Powered Cache > Media Optimization > Image Optimization</code>. You can safely deactivate the Image Optimizer PRO plugin.', 'image-optimizer-pro' ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Save settings
	 *
	 * @since 1.0
	 */
	public function save_settings() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! isset( $_POST['image-optimizer-pro-settings'] ) || ! wp_verify_nonce( sanitize_key( $_POST['image-optimizer-pro-settings'] ), 'image-optimizer-pro-nonce' ) ) {
			return;
		}

		$settings                     = [];
		$settings['preferred_format'] = sanitize_text_field( wp_unslash( $_POST['preferred_format'] ?? '' ) );

		$license_key         = sanitize_text_field( filter_input( INPUT_POST, 'license_key' ) );
		$current_license_key = mask_string( $license_key, 3 );
		$old_license_key     = mask_string( get_license_key(), 3 );

		if ( $current_license_key === $old_license_key ) {
			$license_key = get_license_key();
		}

		$encryption            = new Encryption();
		$encrypted_license_key = $encryption->encrypt( $license_key );

		if ( IMAGE_OPTIMIZER_PRO_IS_NETWORK ) {
			update_site_option( SETTING_OPTION, $settings );
			update_site_option( LICENSE_KEY_OPTION, $encrypted_license_key );
		} else {
			update_option( SETTING_OPTION, $settings, false );
			update_option( LICENSE_KEY_OPTION, $encrypted_license_key, false );
		}

		add_settings_error( 'image-optimizer-pro', 'image-optimizer-pro', esc_html__( 'Settings saved.', 'image-optimizer-pro' ), 'success' );

		if ( isset( $_POST['license_activate'] ) ) {
			wp_remote_post(
				LICENSE_ENDPOINT,
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'body'      => [
						'action'      => 'activate',
						'license_key' => sanitize_text_field( $license_key ),
						'license_url' => get_license_url(),
					],
				)
			);
			delete_transient( LICENSE_INFO_TRANSIENT );
		} elseif ( isset( $_POST['license_deactivate'] ) ) {
			wp_remote_post(
				LICENSE_ENDPOINT,
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'body'      => [
						'action'      => 'deactivate',
						'license_key' => sanitize_text_field( $license_key ),
						'license_url' => get_license_url(),
					],
				)
			);
			delete_transient( LICENSE_INFO_TRANSIENT );
		}

		if ( empty( $_POST['license_key'] ) ) {
			delete_transient( LICENSE_INFO_TRANSIENT );
		}
	}


	/**
	 * Maybe redirect after activation
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( \defined( 'DOING_AJAX' ) && \DOING_AJAX ) {
			return;
		}

		if ( IMAGE_OPTIMIZER_PRO_IS_NETWORK ) {
			$has_activation_redirect = get_site_transient( ACTIVATION_REDIRECT_TRANSIENT );
			$delete_function         = 'delete_site_transient';
			$redirect_url            = \network_admin_url( 'settings.php?page=image-optimizer-pro' );
		} else {
			$has_activation_redirect = get_transient( ACTIVATION_REDIRECT_TRANSIENT );
			$delete_function         = 'delete_transient';
			$redirect_url            = \admin_url( 'options-general.php?page=image-optimizer-pro' );
		}

		if ( $has_activation_redirect ) {
			$delete_function( ACTIVATION_REDIRECT_TRANSIENT );
			\wp_safe_redirect( $redirect_url, 302, 'Image Optimizer Pro' );
			exit;
		}
	}

	/**
	 * Add privacy message
	 *
	 * @return void
	 * @see https://developer.wordpress.org/plugins/privacy/
	 */
	public function add_privacy_message() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Image Optimizer Pro', 'image-optimizer-pro' ),
				__(
					'We collect information about visitors who use our image optimization service, similar to what is typically recorded in standard web server access logs. Specifically, when visitors access images, we record data such as IP addresses, user agents (which identify the browser or tool used to access the image), referrer URLs (indicating the source webpage from which the image was requested), and the Site URL (the address of the webpage where the image is displayed). This type of data collection is a standard practice for monitoring and enhancing web services.',
					'image-optimizer-pro'
				)
			);
		}
	}

	/**
	 * Maybe display feedback messages when certain action is taken
	 *
	 * @since 1.1
	 */
	public function maybe_display_message() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['iop_action'] ) ) {
			return;
		}

		/**
		 * Dont display multiple message when saving the options while having the query_params
		 */
		if ( ! empty( $_POST ) ) {
			return;
		}

		$screen = get_current_screen();

		$success_messages = [
			'purge_image_optimizer_cache' => esc_html__( 'Image optimizer cache purged successfully!', 'image-optimizer-pro' ),
		];

		$err_messages = [
			'purge_image_optimizer_cache_failed' => esc_html__( 'Could not purge image optimizer cache. Please try again later and ensure your license key is activated!', 'image-optimizer-pro' ),
		];

		if ( isset( $success_messages[ $_GET['iop_action'] ] ) ) {
			if ( MENU_SLUG === $screen->parent_base ) { // display with shared-ui on plugin page
				add_settings_error( $screen->parent_file, MENU_SLUG, $success_messages[ $_GET['iop_action'] ], 'success' ); // phpcs:ignore

				return;
			}

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $success_messages[ $_GET['iop_action'] ] ); // phpcs:ignore
		}

		if ( isset( $err_messages[ $_GET['iop_action'] ] ) ) {
			if ( MENU_SLUG === $screen->parent_base ) { // display with shared-ui on plugin page
				add_settings_error( $screen->parent_file, MENU_SLUG, $err_messages[ $_GET['iop_action'] ], 'error' ); // phpcs:ignore

				return;
			}

			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', $err_messages[ $_GET['iop_action'] ] ); // phpcs:ignore
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

}
