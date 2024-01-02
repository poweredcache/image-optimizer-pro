<?php
/**
 * Image Optimizer Functionality
 *
 * @package ImageOptimizerPro
 */

namespace ImageOptimizerPro;

use \WP_REST_Request as WP_REST_Request;
use \WP_Post as WP_Post;
use \WP_Error as WP_Error;
use function ImageOptimizerPro\Utils\is_license_active;
use function ImageOptimizerPro\Utils\is_local_site;

/**
 * Class Optimizer
 */
class Optimizer {
	/**
	 * Singleton.
	 *
	 * @var null
	 */
	private static $instance = null;

	/**
	 * Allowed extensions.
	 *
	 * @var string[] Allowed extensions must be supported image
	 */
	protected static $extensions
		= [
			'gif',
			'jpg',
			'jpeg',
			'png',
			'webp',
			'svg',
			'avif',
			'bmp',
		];

	/**
	 * Image sizes.
	 * Don't access this directly. Instead, use self::image_sizes() so it's actually populated with something.
	 *
	 * @var array Image sizes.
	 */
	protected static $image_sizes = null;

	/**
	 * Preferred image format.
	 * It is used to determine the preferred image format for the current request.
	 *
	 * @var null
	 */
	protected static $preferred_image_formats = null;

	/**
	 * Singleton implementation
	 *
	 * @return object
	 */
	public static function factory() {
		if ( ! is_a( self::$instance, 'Optimizer' ) ) {
			self::$instance = new Optimizer();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Silence is golden.
	 */
	private function __construct() {
	}

	/**
	 * Setup routione
	 *
	 * @return null
	 * @uses add_action, add_filter
	 */
	private function setup() {
		/**
		 * Filters ImageOptimizer integration
		 *
		 * @hook   powered_cache_image_optimizer_disable
		 *
		 * @param  {boolean} False by default.
		 *
		 * @return {boolean} New value.
		 * @since  1.0
		 */
		$is_disabled = apply_filters( 'powered_cache_image_optimizer_disable', false );

		if ( $is_disabled ) {
			return;
		}

		// Disables ImageOptimization on local
		if ( is_local_site() ) {
			return;
		}

		// require a valid license
		if ( ! is_license_active() ) {
			return;
		}

		// Skip integration for Block Editor requests.
		if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && wp_is_json_request() ) {
			return true;
		}

		$settings = \ImageOptimizerPro\Utils\get_settings();
		// set preferred image format
		self::$preferred_image_formats = $settings['preferred_format'];

		// skip photonized urls when image optimizer active
		add_filter( 'jetpack_photon_skip_for_url', '__return_true' );
		add_filter( 'wp_resource_hints', [ $this, 'add_dns_prefetch' ], 10, 2 );

		// Images in post content and galleries.
		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), 9999999 );
		add_filter( 'get_post_galleries', array( __CLASS__, 'filter_the_galleries' ), 9999999 );
		add_filter( 'widget_media_image_instance', array( __CLASS__, 'filter_the_image_widget' ), 9999999 );

		// Core image retrieval.
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'should_rest_image_downsize' ), 10, 3 );
		add_action( 'rest_after_insert_attachment', array( $this, 'should_rest_image_downsize_insert_attachment' ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'cleanup_rest_image_downsize' ) );

		// Responsive image srcset substitution.
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset_array' ), 10, 5 );
		add_filter( 'wp_calculate_image_sizes', array( $this, 'filter_sizes' ), 1, 2 ); // Early so themes can still easily filter.

		// Helpers for maniuplated images.
		add_action( 'wp_enqueue_scripts', array( $this, 'action_wp_enqueue_scripts' ), 9 );

		add_filter( 'powered_cache_image_optimizer_skip_for_url', [ $this, 'banned_domains' ], 9, 2 );
		add_filter( 'widget_text', [ $this, 'support_text_widgets' ] );
		add_filter( 'post_thumbnail_url', [ $this, 'maybe_modify_post_thumbnail_url' ] );
		add_filter( 'powered_cache_delayed_js_skip', [ $this, 'maybe_delayed_js_skip' ], 10, 2 );
		add_action( 'setup_theme', [ $this, 'start_buffer' ] );
	}

	/**
	 * * IN-CONTENT IMAGE MANIPULATION FUNCTIONS
	 **/

	/**
	 * Match all images and any relevant <a> tags in a block of HTML.
	 *
	 * @param string $content Some HTML.
	 *
	 * @return array An array of $images matches, where $images[0] is
	 *         an array of full matches, and the link_url, img_tag,
	 *         and img_url keys are arrays of those matches.
	 */
	public static function parse_images_from_html( $content ) {
		$images = array();

		if ( preg_match_all( '#(?:<a[^>]+?href=["|\'](?P<link_url>[^\s]+?)["|\'][^>]*?>\s*)?(?P<img_tag><(?:img|amp-img|amp-anim)[^>]*?\s+?src=["|\'](?P<img_url>[^\s]+?)["|\'].*?>){1}(?:\s*</a>)?#is', $content, $images ) ) {
			foreach ( $images as $key => $unused ) {
				// Simplify the output as much as possible, mostly for confirming test results.
				if ( is_numeric( $key ) && $key > 0 ) {
					unset( $images[ $key ] );
				}
			}

			return $images;
		}

		return array();
	}

	/**
	 * Try to determine height and width from strings WP appends to resized image filenames.
	 *
	 * @param string $src The image URL.
	 *
	 * @return array An array consisting of width and height.
	 */
	public static function parse_dimensions_from_filename( $src ) {
		$width_height_string = array();

		if ( preg_match( '#-(\d+)x(\d+)\.(?:' . implode( '|', self::get_extensions() ) . '){1}$#i', $src, $width_height_string ) ) {
			$width  = (int) $width_height_string[1];
			$height = (int) $width_height_string[2];

			if ( $width && $height ) {
				return array( $width, $height );
			}
		}

		return array( false, false );
	}

	/**
	 * Identify images in post content, and if images are local (uploaded to the current site), pass through ImageOptimization.
	 *
	 * @param string $content The content.
	 *
	 * @return string
	 * @uses   self::validate_image_url, apply_filters, image_optimizer_url, esc_url
	 * @filter the_content
	 */
	public static function filter_the_content( $content ) {
		$images = self::parse_images_from_html( $content );

		if ( ! empty( $images ) ) {
			$content_width = self::get_content_width();

			$image_sizes = self::image_sizes();

			$upload_dir = wp_get_upload_dir();

			foreach ( $images[0] as $index => $tag ) {
				// Default to resize, though fit may be used in certain cases where a dimension cannot be ascertained.
				$transform = 'resize';

				// Start with a clean attachment ID each time.
				$attachment_id = false;

				// Flag if we need to munge a fullsize URL.
				$fullsize_url = false;

				// Identify image source.
				$src_orig = $images['img_url'][ $index ];
				$src      = $src_orig;

				/**
				 * Allow specific images to be skipped by ImageOptimization.
				 *
				 * @hook   image_optimizer_pro_skip_image
				 *
				 * @param bool false Should ImageOptimization ignore this image. Default to false.
				 * @param string $src Image URL.
				 * @param string $tag Image Tag (Image HTML output).
				 *
				 * @return {boolean} New value.
				 * @since  1.0
				 */
				if ( apply_filters( 'powered_cache_image_optimizer_skip_image', false, $src, $tag ) ) {
					continue;
				}

				// Support Automattic's Lazy Load plugin.
				// Can't modify $tag yet as we need unadulterated version later.
				if ( preg_match( '#data-lazy-src=["|\'](.+?)["|\']#i', $images['img_tag'][ $index ], $lazy_load_src ) ) {
					$placeholder_src_orig = $src;
					$placeholder_src      = $placeholder_src_orig;
					$src_orig             = $lazy_load_src[1];
					$src                  = $src_orig;
				} elseif ( preg_match( '#data-lazy-original=["|\'](.+?)["|\']#i', $images['img_tag'][ $index ], $lazy_load_src ) ) {
					$placeholder_src_orig = $src;
					$placeholder_src      = $placeholder_src_orig;
					$src_orig             = $lazy_load_src[1];
					$src                  = $src_orig;
				} elseif ( preg_match( '#data-lazyload=["|\'](.+?)["|\']#i', $images['img_tag'][ $index ], $lazy_load_src ) ) {
					$placeholder_src_orig = $src;
					$placeholder_src      = $placeholder_src_orig;
					$src_orig             = $lazy_load_src[1];
					$src                  = $src_orig;
				}

				// Check if image URL should be used with ImageOptimization.
				if ( self::validate_image_url( $src ) ) {
					// Find the width and height attributes.
					$width  = false;
					$height = false;

					// First, check the image tag. Note we only check for pixel sizes now; HTML4 percentages have never been correctly
					// supported, so we stopped pretending to support them in JP 9.1.0.
					if ( preg_match( '#[\s|"|\']width=["|\']?([\d%]+)["|\']?#i', $images['img_tag'][ $index ], $width_string ) ) {
						$width = false === strpos( $width_string[1], '%' ) ? $width_string[1] : false;
					}

					if ( preg_match( '#[\s|"|\']height=["|\']?([\d%]+)["|\']?#i', $images['img_tag'][ $index ], $height_string ) ) {
						$height = false === strpos( $height_string[1], '%' ) ? $height_string[1] : false;
					}

					// Detect WP registered image size from HTML class.
					if ( preg_match( '#class=["|\']?[^"\']*size-([^"\'\s]+)[^"\']*["|\']?#i', $images['img_tag'][ $index ], $size ) ) {
						$size = array_pop( $size );

						if ( false === $width && false === $height && 'full' !== $size && array_key_exists( $size, $image_sizes ) ) {
							$width     = (int) $image_sizes[ $size ]['width'];
							$height    = (int) $image_sizes[ $size ]['height'];
							$transform = $image_sizes[ $size ]['crop'] ? 'resize' : 'fit';
						}
					} else {
						unset( $size );
					}

					// WP Attachment ID, if uploaded to this site.
					if (
						preg_match( '#class=["|\']?[^"\']*wp-image-([\d]+)[^"\']*["|\']?#i', $images['img_tag'][ $index ], $attachment_id )
						&& 0 === strpos( $src, $upload_dir['baseurl'] )
						&& /**
						 * Filter whether an image using an attachment ID in its class has to be uploaded to the local site to go through ImageOptimization.
						 *
						 * @hook   image_optimizer_pro_image_is_local
						 *
						 * @param bool false Was the image uploaded to the local site. Default to false.
						 * @param array $args   {
						 *                      Array of image details.
						 *
						 * @type        $src    Image URL.
						 * @type tag Image tag (Image HTML output).
						 * @type        $images Array of information about the image.
						 * @type        $index  Image index.
						 *                      }
						 * @return {boolean} New value.
						 * @since  1.0
						 * @since  1.0
						 */
						apply_filters( 'powered_cache_image_optimizer_image_is_local', false, compact( 'src', 'tag', 'images', 'index' ) )
					) {
						$attachment_id = (int) array_pop( $attachment_id );

						if ( $attachment_id ) {
							$attachment = get_post( $attachment_id );

							// Basic check on returned post object.
							if ( is_object( $attachment ) && ! is_wp_error( $attachment ) && 'attachment' === $attachment->post_type ) {
								$src_per_wp = wp_get_attachment_image_src( $attachment_id, isset( $size ) ? $size : 'full' );

								if ( self::validate_image_url( $src_per_wp[0] ) ) {
									$src          = $src_per_wp[0];
									$fullsize_url = true;

									// Prevent image distortion if a detected dimension exceeds the image's natural dimensions.
									if ( ( false !== $width && $width > $src_per_wp[1] ) || ( false !== $height && $height > $src_per_wp[2] ) ) {
										$width  = false === $width ? false : min( $width, $src_per_wp[1] );
										$height = false === $height ? false : min( $height, $src_per_wp[2] );
									}

									// If no width and height are found, max out at source image's natural dimensions.
									// Otherwise, respect registered image sizes' cropping setting.
									if ( false === $width && false === $height ) {
										$width     = $src_per_wp[1];
										$height    = $src_per_wp[2];
										$transform = 'fit';
									} elseif ( isset( $size ) && array_key_exists( $size, $image_sizes ) && isset( $image_sizes[ $size ]['crop'] ) ) {
										$transform = (bool) $image_sizes[ $size ]['crop'] ? 'resize' : 'fit';
									}
								}
							} else {
								unset( $attachment_id );
								unset( $attachment );
							}
						}
					}

					// If image tag lacks width and height arguments, try to determine from strings WP appends to resized image filenames.
					if ( false === $width && false === $height ) {
						list( $width, $height ) = self::parse_dimensions_from_filename( $src );
					}

					$width_orig     = $width;
					$height_orig    = $height;
					$transform_orig = $transform;

					// If width is available, constrain to $content_width.
					if ( false !== $width && is_numeric( $content_width ) && $width > $content_width ) {
						if ( false !== $height ) {
							$height = round( ( $content_width * $height ) / $width );
						}
						$width = $content_width;
					}

					// Set a width if none is found and $content_width is available.
					// If width is set in this manner and height is available, use `fit` instead of `resize` to prevent skewing.
					if ( false === $width && is_numeric( $content_width ) ) {
						$width = (int) $content_width;

						if ( false !== $height ) {
							$transform = 'fit';
						}
					}

					// Detect if image source is for a custom-cropped thumbnail and prevent further URL manipulation.
					if ( ! $fullsize_url && preg_match_all( '#-e[a-z0-9]+(-\d+x\d+)?\.(' . implode( '|', self::get_extensions() ) . '){1}$#i', basename( $src ), $filename ) ) {
						$fullsize_url = true;
					}

					// Build URL, first maybe removing WP's resized string so we pass the original image to ImageOptimization.
					if ( ! $fullsize_url && 0 === strpos( $src, $upload_dir['baseurl'] ) ) {
						$src = self::strip_image_dimensions_maybe( $src );
					}

					// Build array of ImageOptimization args and expose to filter before passing to ImageOptimization URL function.
					$args = array();

					if ( false !== $width && false !== $height ) {
						$args[ $transform ] = $width . ',' . $height;
					} elseif ( false !== $width ) {
						$args['w'] = $width;
					} elseif ( false !== $height ) {
						$args['h'] = $height;
					}

					/**
					 * Filter the array of ImageOptimization arguments added to an image when it goes through ImageOptimization.
					 * By default, only includes width and height values.
					 *
					 * @hook   image_optimizer_pro_post_image_args
					 *
					 * @param array    $args           Array of ImageOptimization Arguments.
					 * @param array    $details        {
					 *                                 Array of image details.
					 *
					 * @type string    $tag            Image tag (Image HTML output).
					 * @type string    $src            Image URL.
					 * @type string    $src_orig       Original Image URL.
					 * @type int|false $width          Image width.
					 * @type int|false $height         Image height.
					 * @type int|false $width_orig     Original image width before constrained by content_width.
					 * @type int|false $height_orig    Original Image height before constrained by content_width.
					 * @type string    $transform      Transform.
					 * @type string    $transform_orig Original transform before constrained by content_width.
					 *                                 }
					 * @return {array} New value.
					 * @since  1.0
					 */
					$args = apply_filters( 'powered_cache_image_optimizer_post_image_args', $args, compact( 'tag', 'src', 'src_orig', 'width', 'height', 'width_orig', 'height_orig', 'transform', 'transform_orig' ) );

					$image_optimizer_url = self::image_optimizer_url( $src, $args );

					// Modify image tag if ImageOptimization function provides a URL
					// Ensure changes are only applied to the current image by copying and modifying the matched tag, then replacing the entire tag with our modified version.
					if ( $src !== $image_optimizer_url ) {
						$new_tag = $tag;

						// If present, replace the link href with a ImageOptimizationed URL for the full-size image.
						if ( ! empty( $images['link_url'][ $index ] ) && self::validate_image_url( $images['link_url'][ $index ] ) ) {
							$new_tag = preg_replace( '#(href=["|\'])' . preg_quote( $images['link_url'][ $index ], '#' ) . '(["|\'])#i', '\1' . self::image_optimizer_url( $images['link_url'][ $index ] ) . '\2', $new_tag, 1 );
						}

						// Supplant the original source value with our ImageOptimization URL.
						$image_optimizer_url = esc_url( $image_optimizer_url );
						$new_tag             = str_replace( $src_orig, $image_optimizer_url, $new_tag );

						// If Lazy Load is in use, pass placeholder image through ImageOptimization.
						if ( isset( $placeholder_src ) && self::validate_image_url( $placeholder_src ) ) {
							$placeholder_src = self::image_optimizer_url( $placeholder_src );

							if ( $placeholder_src !== $placeholder_src_orig ) {
								$new_tag = str_replace( $placeholder_src_orig, esc_url( $placeholder_src ), $new_tag );
							}

							unset( $placeholder_src );
						}

						// If we are not transforming the image with resize, fit, or letterbox (lb), then we should remove
						// the width and height arguments (including HTML4 percentages) from the image to prevent distortion.
						// Even if $args['w'] and $args['h'] are present, ImageOptimization does not crop to those dimensions. Instead,
						// it appears to favor height.
						//
						// If we are transforming the image via one of those methods, let's update the width and height attributes.
						if ( empty( $args['resize'] ) && empty( $args['fit'] ) && empty( $args['lb'] ) ) {
							$new_tag = preg_replace( '#(?<=\s)(width|height)=["|\']?[\d%]+["|\']?\s?#i', '', $new_tag );
						} else {
							$resize_args = isset( $args['resize'] ) ? $args['resize'] : false;
							if ( false === $resize_args ) {
								$resize_args = ( ! $resize_args && isset( $args['fit'] ) )
									? $args['fit']
									: false;
							}
							if ( false === $resize_args ) {
								$resize_args = ( ! $resize_args && isset( $args['lb'] ) )
									? $args['lb']
									: false;
							}

							$resize_args = array_map( 'trim', explode( ',', $resize_args ) );

							// (?<=\s)         - Ensure width or height attribute is preceded by a space
							// (width=["|\']?) - Matches, and captures, width=, width=", or width='
							// [\d%]+          - Matches 1 or more digits or percent signs
							// (["|\']?)       - Matches, and captures, ", ', or empty string
							// \s              - Ensures there's a space after the attribute
							$new_tag = preg_replace( '#(?<=\s)(width=["|\']?)[\d%]+(["|\']?)\s?#i', sprintf( '${1}%d${2} ', $resize_args[0] ), $new_tag );
							$new_tag = preg_replace( '#(?<=\s)(height=["|\']?)[\d%]+(["|\']?)\s?#i', sprintf( '${1}%d${2} ', $resize_args[1] ), $new_tag );
						}

						// Tag an image for dimension checking.
						if ( ! self::is_amp_endpoint() ) {
							$new_tag = preg_replace( '#(\s?/)?>(\s*</a>)?$#i', ' data-recalc-dims="1"\1>\2', $new_tag );
						}

						// Replace original tag with modified version.
						$content = str_replace( $tag, $new_tag, $content );
					}
				} elseif ( preg_match( '#^http(s)?://img.poweredcache.net#', $src ) && ! empty( $images['link_url'][ $index ] ) && self::validate_image_url( $images['link_url'][ $index ] ) ) {
					$new_tag = preg_replace( '#(href=["|\'])' . preg_quote( $images['link_url'][ $index ], '#' ) . '(["|\'])#i', '\1' . self::image_optimizer_url( $images['link_url'][ $index ] ) . '\2', $tag, 1 );

					$content = str_replace( $tag, $new_tag, $content );
				}
			}
		}

		return $content;
	}

	/**
	 * Filter Core galleries
	 *
	 * @param array $galleries Gallery array.
	 *
	 * @return array
	 */
	public static function filter_the_galleries( $galleries ) {
		if ( empty( $galleries ) || ! is_array( $galleries ) ) {
			return $galleries;
		}

		// Pass by reference, so we can modify them in place.
		foreach ( $galleries as &$this_gallery ) {
			if ( is_string( $this_gallery ) ) {
				$this_gallery = self::filter_the_content( $this_gallery );
			}
		}
		unset( $this_gallery ); // break the reference.

		return $galleries;
	}

	/**
	 * Runs the image widget through ImageOptimizer.
	 *
	 * @param array $instance Image widget instance data.
	 *
	 * @return array
	 */
	public static function filter_the_image_widget( $instance ) {
		if ( ! $instance['attachment_id'] && $instance['url'] ) {
			self::image_optimizer_url(
				$instance['url'],
				array(
					'w' => $instance['width'],
					'h' => $instance['height'],
				)
			);
		}

		return $instance;
	}

	/**
	 * * CORE IMAGE RETRIEVAL
	 **/

	/**
	 * Filter post thumbnail image retrieval, passing images through ImageOptimization
	 *
	 * @param string|bool  $image         Image URL.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|array $size          Declared size or a size array.
	 *
	 * @return string|bool
	 * @uses   is_admin, apply_filters, wp_get_attachment_url, self::validate_image_url, this::image_sizes, image_optimizer_url
	 * @filter image_downsize
	 */
	public function filter_image_downsize( $image, $attachment_id, $size ) {
		/**
		 * Provide plugins a way of running ImageOptimization for images in the WordPress Dashboard (wp-admin).
		 * Note: enabling this will result in ImageOptimization URLs added to your post content, which could make migrations across domains (and off ImageOptimization) a bit more challenging.
		 *
		 * @hook   powered_cache_image_optimizer_admin_allow_image_downsize
		 *
		 * @param bool false Stop ImageOptimization from being run on the Dashboard. Default to false.
		 * @param array $args          {
		 *                             Array of image details.
		 *
		 * @type        $image         Image URL.
		 * @type        $attachment_id Attachment ID of the image.
		 * @type        $size          Image size. Can be a string (name of the image size, e.g. full) or an array of width and height.
		 *                             }
		 * @return {boolean} New value.
		 * @since  1.0
		 */
		$allow_image_downsize = apply_filters( 'powered_cache_image_optimizer_admin_allow_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) );

		// Don't foul up the admin side of things, unless a plugin wants to.
		if ( is_admin() && false === $allow_image_downsize ) {
			return $image;
		}

		/**
		 * Provide plugins a way of preventing ImageOptimization from being applied to images retrieved from WordPress Core.
		 *
		 * @module ImageOptimizer
		 *
		 * @param bool false Stop ImageOptimization from being applied to the image. Default to false.
		 * @param array $args          {
		 *                             Array of image details.
		 *
		 * @type        $image         Image URL.
		 * @type        $attachment_id Attachment ID of the image.
		 * @type        $size          Image size. Can be a string (name of the image size, e.g. full) or an array of width and height.
		 *                             }
		 * @since  1.0
		 */
		if ( apply_filters( 'powered_cache_image_optimizer_override_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) ) ) {
			return $image;
		}

		// Get the image URL and proceed with ImageOptimization if successful.
		$image_url = wp_get_attachment_url( $attachment_id );

		// Set this to true later when we know we have size meta.
		$has_size_meta = false;

		if ( $image_url ) {
			// Check if image URL should be used with ImageOptimization.
			if ( ! self::validate_image_url( $image_url ) ) {
				return $image;
			}

			$intermediate = true; // For the fourth array item returned by the image_downsize filter.

			// If an image is requested with a size known to WordPress, use that size's settings with ImageOptimization.
			// WP states that `add_image_size()` should use a string for the name, but doesn't enforce that.
			// Due to differences in how Core and ImageOptimization check for the registered image size, we check both types.
			if ( ( is_string( $size ) || is_int( $size ) ) && array_key_exists( $size, self::image_sizes() ) ) {
				$image_args = self::image_sizes();
				$image_args = $image_args[ $size ];

				$image_optimizer_args = array();

				$image_meta = image_get_intermediate_size( $attachment_id, $size );

				// 'full' is a special case: We need consistent data regardless of the requested size.
				if ( 'full' === $size ) {
					$image_meta   = wp_get_attachment_metadata( $attachment_id );
					$intermediate = false;
				} elseif ( ! $image_meta ) {
					// If we still don't have any image meta at this point, it's probably from a custom thumbnail size
					// for an image that was uploaded before the custom image was added to the theme.  Try to determine the size manually.
					$image_meta = wp_get_attachment_metadata( $attachment_id );

					if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
						$image_resized = image_resize_dimensions( $image_meta['width'], $image_meta['height'], $image_args['width'], $image_args['height'], $image_args['crop'] );
						if ( $image_resized ) { // This could be false when the requested image size is larger than the full-size image.
							$image_meta['width']  = $image_resized[6];
							$image_meta['height'] = $image_resized[7];
						}
					}
				}

				if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
					$image_args['width']  = (int) $image_meta['width'];
					$image_args['height'] = (int) $image_meta['height'];

					list( $image_args['width'], $image_args['height'] ) = image_constrain_size_for_editor( $image_args['width'], $image_args['height'], $size, 'display' );
					$has_size_meta                                      = true;
				}

				// Expose determined arguments to a filter before passing to ImageOptimization.
				$transform = $image_args['crop'] ? 'resize' : 'fit';

				// Check specified image dimensions and account for possible zero values; ImageOptimizer fails to resize if a dimension is zero.
				if ( 0 === $image_args['width'] || 0 === $image_args['height'] ) {
					if ( 0 === $image_args['width'] && 0 < $image_args['height'] ) {
						$image_optimizer_args['h'] = $image_args['height'];
					} elseif ( 0 === $image_args['height'] && 0 < $image_args['width'] ) {
						$image_optimizer_args['w'] = $image_args['width'];
					}
				} else {
					$image_meta = wp_get_attachment_metadata( $attachment_id );
					if ( ( 'resize' === $transform ) && $image_meta ) {
						if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
							// Lets make sure that we don't upscale images since wp never upscales them as well.
							$smaller_width  = ( ( $image_meta['width'] < $image_args['width'] ) ? $image_meta['width'] : $image_args['width'] );
							$smaller_height = ( ( $image_meta['height'] < $image_args['height'] ) ? $image_meta['height'] : $image_args['height'] );

							$image_optimizer_args[ $transform ] = $smaller_width . ',' . $smaller_height;
						}
					} else {
						$image_optimizer_args[ $transform ] = $image_args['width'] . ',' . $image_args['height'];
					}
				}

				/**
				 * Filter the ImageOptimization Arguments added to an image when going through ImageOptimization, when that image size is a string.
				 * Image size will be a string (e.g. "full", "medium") when it is known to WordPress.
				 *
				 * @hook   powered_cache_image_optimizer_image_downsize_string
				 *
				 * @param array     $image_optimizer_args Array of ImageOptimization arguments.
				 * @param array     $args                 {
				 *                                        Array of image details.
				 *
				 * @type array      $image_args           Array of Image arguments (width, height, crop).
				 * @type string     $image_url            Image URL.
				 * @type int        $attachment_id        Attachment ID of the image.
				 * @type string|int $size                 Image size. Can be a string (name of the image size, e.g. full) or an integer.
				 * @type string     $transform            Value can be resize or fit.
				 * @return {array} New value.
				 * @since  1.0
				 *                                        }
				 */
				$image_optimizer_args = apply_filters( 'powered_cache_image_optimizer_image_downsize_string', $image_optimizer_args, compact( 'image_args', 'image_url', 'attachment_id', 'size', 'transform' ) );

				// Generate ImageOptimization URL.
				$image = array(
					self::image_optimizer_url( $image_url, $image_optimizer_args ),
					$has_size_meta ? $image_args['width'] : false,
					$has_size_meta ? $image_args['height'] : false,
					$intermediate,
				);
			} elseif ( is_array( $size ) ) {
				// Pull width and height values from the provided array, if possible.
				$width  = isset( $size[0] ) ? (int) $size[0] : false;
				$height = isset( $size[1] ) ? (int) $size[1] : false;

				// Don't bother if necessary parameters aren't passed.
				if ( ! $width || ! $height ) {
					return $image;
				}

				$image_meta = wp_get_attachment_metadata( $attachment_id );
				if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
					$image_resized = image_resize_dimensions( $image_meta['width'], $image_meta['height'], $width, $height );

					if ( $image_resized ) { // This could be false when the requested image size is larger than the full-size image.
						$width  = $image_resized[6];
						$height = $image_resized[7];
					} else {
						$width  = $image_meta['width'];
						$height = $image_meta['height'];
					}

					$has_size_meta = true;
				}

				list( $width, $height ) = image_constrain_size_for_editor( $width, $height, $size );

				// Expose arguments to a filter before passing to ImageOptimization.
				$image_optimizer_args = array(
					'fit' => $width . ',' . $height,
				);

				/**
				 * Filter the ImageOptimization Arguments added to an image when going through ImageOptimization,
				 * when the image size is an array of height and width values.
				 *
				 * @hook   powered_cache_image_optimizer_image_downsize_array
				 *
				 * @param array $image_optimizer_args Array of ImageOptimization arguments.
				 * @param array $args                 {
				 *                                    Array of image details.
				 *
				 * @type        $width                Image width.
				 * @type height Image height.
				 * @type        $image_url            Image URL.
				 * @type        $attachment_id        Attachment ID of the image.
				 *                                    }
				 * @return {array} New value.
				 * @since  1.0
				 */
				$image_optimizer_args = apply_filters( 'powered_cache_image_optimizer_image_downsize_array', $image_optimizer_args, compact( 'width', 'height', 'image_url', 'attachment_id' ) );

				// Generate ImageOptimization URL.
				$image = array(
					self::image_optimizer_url( $image_url, $image_optimizer_args ),
					$has_size_meta ? $width : false,
					$has_size_meta ? $height : false,
					$intermediate,
				);
			}
		}

		return $image;
	}

	/**
	 * Filters an array of image `srcset` values, replacing each URL with its ImageOptimization equivalent.
	 *
	 * @param array $sources       An array of image urls and widths.
	 * @param array $size_array    The size array for srcset.
	 * @param array $image_src     The image srcs.
	 * @param array $image_meta    The image meta.
	 * @param int   $attachment_id Attachment ID.
	 *
	 * @return array An array of ImageOptimization image urls and widths.
	 * @since 1.0
	 */
	public function filter_srcset_array( $sources = array(), $size_array = array(), $image_src = array(), $image_meta = array(), $attachment_id = 0 ) {
		if ( ! is_array( $sources ) || array() === $sources ) {
			return $sources;
		}
		$upload_dir = wp_get_upload_dir();

		foreach ( $sources as $i => $source ) {
			if ( ! self::validate_image_url( $source['url'] ) ) {
				continue;
			}

			/**
			 * Whether skip or not skip the URL
			 *
			 * @hook   powered_cache_image_optimizer_skip_image
			 *
			 * @param  {boolean} false by default
			 * @param  {string} $url Source url
			 * @param  {array} $source Img source.
			 *
			 * @return {boolean} New value.
			 * @since  1.0
			 */
			if ( apply_filters( 'powered_cache_image_optimizer_skip_image', false, $source['url'], $source ) ) {
				continue;
			}

			$url                    = $source['url'];
			list( $width, $height ) = self::parse_dimensions_from_filename( $url );

			// It's quicker to get the full size with the data we have already, if available.
			if ( ! empty( $attachment_id ) ) {
				$url = wp_get_attachment_url( $attachment_id );
			} else {
				$url = self::strip_image_dimensions_maybe( $url );
			}

			$args = array();
			if ( 'w' === $source['descriptor'] ) {
				if ( $height && ( (int) $source['value'] === $width ) ) {
					$args['resize'] = $width . ',' . $height;
				} else {
					$args['w'] = $source['value'];
				}
			}

			$sources[ $i ]['url'] = self::image_optimizer_url( $url, $args );
		}

		/**
		 * At this point, $sources is the original srcset with ImageOptimizationized URLs.
		 * Now, we're going to construct additional sizes based on multiples of the content_width.
		 * This will reduce the gap between the largest defined size and the original image.
		 */

		/**
		 * Filter the multiplier ImageOptimization uses to create new srcset items.
		 * Return false to short-circuit and bypass auto-generation.
		 *
		 * @hook   powered_cache_image_optimizer_srcset_multipliers
		 *
		 * @param array|bool $multipliers Array of multipliers to use or false to bypass.
		 *
		 * @return {array} New value.
		 * @since  1.0
		 */
		$multipliers = apply_filters( 'powered_cache_image_optimizer_srcset_multipliers', array( 2, 3 ) );
		$url         = trailingslashit( $upload_dir['baseurl'] ) . $image_meta['file'];

		if (
			/** Short-circuit via powered_cache_image_optimizer_srcset_multipliers filter. */
			is_array( $multipliers )
			&& ! apply_filters( 'powered_cache_image_optimizer_skip_image', false, $url, null )
			/** Verify basic meta is intact. */
			&& isset( $image_meta['width'] )
			&& isset( $image_meta['height'] )
			&& isset( $image_meta['file'] )
			/** Verify we have the requested width/height. */
			&& isset( $size_array[0] )
			&& isset( $size_array[1] )
		) {

			$fullwidth  = $image_meta['width'];
			$fullheight = $image_meta['height'];
			$reqwidth   = $size_array[0];
			$reqheight  = $size_array[1];

			$constrained_size = wp_constrain_dimensions( $fullwidth, $fullheight, $reqwidth );
			$expected_size    = array( $reqwidth, $reqheight );

			if ( abs( $constrained_size[0] - $expected_size[0] ) <= 1 && abs( $constrained_size[1] - $expected_size[1] ) <= 1 ) {
				$crop = 'soft';
				$base = self::get_content_width() ? self::get_content_width() : 1000; // Provide a default width if none set by the theme.
			} else {
				$crop = 'hard';
				$base = $reqwidth;
			}

			$currentwidths = array_keys( $sources );
			$newsources    = null;

			foreach ( $multipliers as $multiplier ) {

				$newwidth = $base * $multiplier;
				foreach ( $currentwidths as $currentwidth ) {
					// If a new width would be within 100 pixes of an existing one or larger than the full size image, skip.
					if ( abs( $currentwidth - $newwidth ) < 50 || ( $newwidth > $fullwidth ) ) {
						continue 2; // Bump out back to the $multipliers as $multiplier.
					}
				} //end foreach ( $currentwidths as $currentwidth ){

				if ( 'soft' === $crop ) {
					$args = array(
						'w' => $newwidth,
					);
				} else { // hard crop, e.g. add_image_size( 'example', 200, 200, true ).
					$args = array(
						'zoom'   => $multiplier,
						'resize' => $reqwidth . ',' . $reqheight,
					);
				}

				$newsources[ $newwidth ] = array(
					'url'        => self::image_optimizer_url( $url, $args ),
					'descriptor' => 'w',
					'value'      => $newwidth,
				);
			} //end foreach ( $multipliers as $multiplier )
			if ( is_array( $newsources ) ) {
				$sources = array_replace( $sources, $newsources );
			}
		} //end if isset( $image_meta['width'] ) && isset( $image_meta['file'] ) )

		return $sources;
	}

	/**
	 * Filters an array of image `sizes` values, using $content_width instead of image's full size.
	 *
	 * @param array $sizes An array of media query breakpoints.
	 * @param array $size  Width and height of the image.
	 *
	 * @return array An array of media query breakpoints.
	 * @since 1.0
	 */
	public function filter_sizes( $sizes, $size ) {
		if ( ! doing_filter( 'the_content' ) ) {
			return $sizes;
		}
		$content_width = self::get_content_width();
		if ( ! $content_width ) {
			$content_width = 1000;
		}

		if ( ( is_array( $size ) && $size[0] < $content_width ) ) {
			return $sizes;
		}

		return sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $content_width );
	}

	/**
	 * * GENERAL FUNCTIONS
	 **/

	/**
	 * Ensure image URL is valid for ImageOptimization.
	 * Though ImageOptimization functions address some of the URL issues, we should avoid unnecessary processing if we know early on that the image isn't supported.
	 *
	 * @param string $url Image URL.
	 *
	 * @return bool
	 * @uses wp_parse_args
	 */
	protected static function validate_image_url( $url ) {
		$parsed_url = wp_parse_url( $url );

		if ( ! $parsed_url ) {
			return false;
		}

		// Parse URL and ensure needed keys exist, since the array returned by `wp_parse_url` only includes the URL components it finds.
		$url_info = wp_parse_args(
			$parsed_url,
			array(
				'scheme' => null,
				'host'   => null,
				'port'   => null,
				'path'   => null,
			)
		);

		// Bail if scheme isn't http or port is set that isn't port 80.
		if (
			( 'http' !== $url_info['scheme'] || ! in_array( $url_info['port'], array( 80, null ), true ) )
			&& /**
			 * Allow ImageOptimization to fetch images that are served via HTTPS.
			 *
			 * @hook   powered_cache_image_optimizer_reject_https
			 *
			 * @param  {boolean}  $reject_https Should ImageOptimization ignore images using the HTTPS scheme. Default to false.
			 *
			 * @return {boolean} New value.
			 * @since  1.0
			 */
			apply_filters( 'powered_cache_image_optimizer_reject_https', false )
		) {
			return false;
		}

		// Bail if no host is found.
		if ( is_null( $url_info['host'] ) ) {
			return false;
		}

		// Bail if the image already went through ImageOptimization.
		if ( preg_match( '#^img.poweredcache.net$#i', $url_info['host'] ) ) {
			return false;
		}

		// Bail if no path is found.
		if ( is_null( $url_info['path'] ) ) {
			return false;
		}

		// Ensure image extension is acceptable.
		if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), self::get_extensions(), true ) ) {
			return false;
		}

		// If we got this far, we should have an acceptable image URL
		// But let folks filter to decline if they prefer.

		/**
		 * Overwrite the results of the validation steps an image goes through before to be considered valid to be used by ImageOptimization.
		 *
		 * @hook   powered_cache_image_optimizer_validate_image_url
		 *
		 * @param  {boolean} true Is the image URL valid and can it be used by ImageOptimization. Default to true.
		 * @param  {string} $url        Image URL.
		 * @param  {array}  $parsed_url Array of information about the image.
		 *
		 * @return {boolean} New value.
		 * @since  1.0
		 */
		return apply_filters( 'powered_cache_image_optimizer_validate_image_url', true, $url, $parsed_url );
	}

	/**
	 * Checks if the file exists before it passes the file to ImageOptimizer.
	 *
	 * @param string $src The image URL.
	 *
	 * @return string
	 **/
	public static function strip_image_dimensions_maybe( $src ) {
		$stripped_src = $src;

		// Build URL, first removing WP's resized string so we pass the original image to ImageOptimization.
		if ( preg_match( '#(-\d+x\d+)\.(' . implode( '|', self::get_extensions() ) . '){1}$#i', $src, $src_parts ) ) {
			$stripped_src = str_replace( $src_parts[1], '', $src );
			$upload_dir   = wp_get_upload_dir();

			// Extracts the file path to the image minus the base url.
			$file_path = substr( $stripped_src, strlen( $upload_dir['baseurl'] ) );

			if ( file_exists( $upload_dir['basedir'] . $file_path ) ) {
				$src = $stripped_src;
			}
		}

		return $src;
	}

	/**
	 * Provide an array of available image sizes and corresponding dimensions.
	 * Similar to get_intermediate_image_sizes() except that it includes image sizes' dimensions, not just their names.
	 *
	 * @return array
	 * @uses get_option
	 * @global $wp_additional_image_sizes
	 */
	protected static function image_sizes() {
		if ( null === self::$image_sizes ) {
			global $_wp_additional_image_sizes;

			// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes.
			$images = array(
				'thumb'        => array(
					'width'  => (int) get_option( 'thumbnail_size_w' ),
					'height' => (int) get_option( 'thumbnail_size_h' ),
					'crop'   => (bool) get_option( 'thumbnail_crop' ),
				),
				'medium'       => array(
					'width'  => (int) get_option( 'medium_size_w' ),
					'height' => (int) get_option( 'medium_size_h' ),
					'crop'   => false,
				),
				'medium_large' => array(
					'width'  => (int) get_option( 'medium_large_size_w' ),
					'height' => (int) get_option( 'medium_large_size_h' ),
					'crop'   => false,
				),
				'large'        => array(
					'width'  => (int) get_option( 'large_size_w' ),
					'height' => (int) get_option( 'large_size_h' ),
					'crop'   => false,
				),
				'full'         => array(
					'width'  => null,
					'height' => null,
					'crop'   => false,
				),
			);

			// Compatibility mapping as found in wp-includes/media.php.
			$images['thumbnail'] = $images['thumb'];

			// Update class variable, merging in $_wp_additional_image_sizes if any are set.
			if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) ) {
				self::$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
			} else {
				self::$image_sizes = $images;
			}
		}

		return is_array( self::$image_sizes ) ? self::$image_sizes : array();
	}

	/**
	 * Pass og:image URLs through ImageOptimization
	 *
	 * @param array $tags       Open graph tags.
	 * @param array $parameters Image parameters.
	 *
	 * @return array Open graph tags.
	 * @uses image_optimizer_url
	 */
	public function filter_open_graph_tags( $tags, $parameters ) {
		if ( empty( $tags['og:image'] ) ) {
			return $tags;
		}

		$image_optimizer_args = array(
			'fit' => sprintf( '%d,%d', 2 * $parameters['image_width'], 2 * $parameters['image_height'] ),
		);

		if ( is_array( $tags['og:image'] ) ) {
			$images = array();
			foreach ( $tags['og:image'] as $image ) {
				$images[] = self::image_optimizer_url( $image, $image_optimizer_args );
			}
			$tags['og:image'] = $images;
		} else {
			$tags['og:image'] = self::image_optimizer_url( $tags['og:image'], $image_optimizer_args );
		}

		return $tags;
	}

	/**
	 * Enqueue ImageOptimization helper script
	 *
	 * @return null
	 * @uses   wp_enqueue_script, plugins_url
	 * @action wp_enqueue_script
	 */
	public function action_wp_enqueue_scripts() {
		if ( self::is_amp_endpoint() ) {
			return;
		}
		$url = IMAGE_OPTIMIZER_PRO_URL . 'dist/js/image-optimizer.js';

		wp_enqueue_script(
			'image-optimizer-pro',
			$url,
			[],
			IMAGE_OPTIMIZER_PRO_VERSION,
			true
		);
	}

	/**
	 * Determine if image_downsize should utilize ImageOptimization via REST API.
	 * The WordPress Block Editor (Gutenberg) and other REST API consumers using the wp/v2/media endpoint, especially in the "edit"
	 * context is more akin to the is_admin usage of ImageOptimization (see filter_image_downsize). Since consumers are trying to edit content in posts,
	 * ImageOptimization should not fire as it will fire later on display. By aborting an attempt to ImageOptimizationize an image here, we
	 * prevents issues like https://github.com/Automattic/jetpack/issues/10580 .
	 * To determine if we're using the wp/v2/media endpoint, we hook onto the `rest_request_before_callbacks` filter and
	 * if determined we are using it in the edit context, we'll false out the `powered_cache_image_optimizer_override_image_downsize` filter.
	 *
	 * @param null|\WP_Error   $response      REST API response.
	 * @param array            $endpoint_data Endpoint data. Not used, but part of the filter.
	 * @param \WP_REST_Request $request       Request used to generate the response.
	 *
	 * @return null|WP_Error The original response object without modification.
	 */
	public function should_rest_image_downsize( $response, $endpoint_data, $request ) {
		if ( ! is_a( $request, 'WP_REST_Request' ) ) {
			return $response; // Something odd is happening. Do nothing and return the response.
		}

		if ( is_wp_error( $response ) ) {
			// If we're going to return an error, we don't need to do anything with ImageOptimization.
			return $response;
		}

		$this->should_rest_image_downsize_override( $request );

		return $response;

	}

	/**
	 * Helper function to check if a WP_REST_Request is the media endpoint in the edit context.
	 *
	 * @param WP_REST_Request $request The current REST request.
	 */
	private function should_rest_image_downsize_override( WP_REST_Request $request ) {
		$route = $request->get_route();

		if (
			false !== strpos( $route, 'wp/v2/media' )
			&& 'edit' === $request->get_param( 'context' )
		) {
			// Don't use `__return_true()`: Use something unique. See ::_override_image_downsize_in_rest_edit_context()
			// Late execution to avoid conflict with other plugins as we really don't want to run in this situation.
			add_filter(
				'image_optimizer_pro_override_image_downsize',
				array(
					$this,
					'override_image_downsize_in_rest_edit_context',
				),
				9999999
			);
		}
	}

	/**
	 * Brings in should_rest_image_downsize for the rest_after_insert_attachment hook.
	 *
	 * @param WP_Post         $attachment Inserted or updated attachment object.
	 * @param WP_REST_Request $request    Request object.
	 *
	 * @since 1.0
	 */
	public function should_rest_image_downsize_insert_attachment( WP_Post $attachment, WP_REST_Request $request ) {
		if ( ! is_a( $request, 'WP_REST_Request' ) ) {
			// Something odd is happening.
			return;
		}

		$this->should_rest_image_downsize_override( $request );

	}

	/**
	 * Remove the override we may have added in ::should_rest_image_downsize()
	 * Since ::_override_image_downsize_in_rest_edit_context() is only
	 * every used here, we can always remove it without ever worrying
	 * about breaking any other configuration.
	 *
	 * @param mixed $response REST API Response.
	 *
	 * @return mixed Unchanged $response
	 */
	public function cleanup_rest_image_downsize( $response ) {
		remove_filter(
			'image_optimizer_pro_override_image_downsize',
			array(
				$this,
				'override_image_downsize_in_rest_edit_context',
			),
			9999999
		);

		return $response;
	}

	/**
	 * Used internally by ::should_rest_image_downsize() to not optimize
	 * image URLs in ?context=edit REST requests.
	 * MUST NOT be used anywhere else.
	 * We use a unique function instead of __return_true so that we can clean up
	 * after ourselves without breaking anyone else's filters.
	 *
	 * @return true
	 * @internal
	 */
	public function override_image_downsize_in_rest_edit_context() {
		return true;
	}

	/**
	 * Return whether the current page is AMP.
	 * This method may only be called at the wp action or later.
	 *
	 * @return bool Whether AMP page.
	 */
	private static function is_amp_endpoint() {
		if ( function_exists( '\is_amp_endpoint' ) && \is_amp_endpoint() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the content width
	 *
	 * @return mixed|void
	 * @since 1.0
	 */
	public static function get_content_width() {
		$content_width = ( isset( $GLOBALS['content_width'] ) && is_numeric( $GLOBALS['content_width'] ) )
			? $GLOBALS['content_width']
			: false;

		/**
		 * Filter the Content Width value.
		 *
		 * @hook   powered_cache_image_optimizer_content_width
		 *
		 * @param  {string} $content_width Content Width value.
		 *
		 * @return {string} New value.
		 * @since  1.0
		 */
		return apply_filters( 'powered_cache_image_optimizer_content_width', $content_width );
	}


	/**
	 * Generates a ImageOptimization URL.
	 *
	 * @param string       $image_url URL to the publicly accessible image you want to manipulate.
	 * @param array|string $args      An array of arguments, i.e. array( 'w' => '300', 'resize' => array( 123, 456 ) ), or in string form (w=123&h=456).
	 * @param string|null  $scheme    URL protocol.
	 *
	 * @return string The raw final URL. You should run this through esc_url() before displaying it.
	 */
	public static function image_optimizer_url( $image_url, $args = array(), $scheme = null ) {
		$image_url = trim( $image_url );

		if ( ! empty( $args['resize'] ) ) {
			list( $width, $height ) = explode( ',', $args['resize'] );
			unset( $args['resize'] );
			$args['rs'] = 'fill';
			$args['w']  = $width;
			$args['h']  = $height;
		}

		if ( ! empty( $args['fit'] ) ) {
			list( $width, $height ) = explode( ',', $args['fit'] );
			unset( $args['fit'] );
			$args['rs'] = 'fit';
			$args['w']  = $width;
			$args['h']  = $height;
		}

		/**
		 * Allow specific image URls to avoid going through ImageOptimization.
		 *
		 * @hook   powered_cache_image_optimizer_skip_for_url
		 *
		 * @param  {boolean} false Should the image be returned as is, without going through ImageOptimization. Default to false.
		 * @param  {string}       $image_url Image URL.
		 * @param  {array|string} $args      Array of ImageOptimization arguments.
		 * @param  {string|null}  $scheme    Image scheme. Default to null.
		 *
		 * @return {boolean} New value.
		 * @since  1.0
		 */
		if ( false !== apply_filters( 'powered_cache_image_optimizer_skip_for_url', false, $image_url, $args, $scheme ) ) {
			return $image_url;
		}

		/**
		 * Filter the original image URL before it goes through ImageOptimization.
		 *
		 * @hook   powered_cache_image_optimizer_pre_image_url
		 *
		 * @param  {string}       $image_url Image URL.
		 * @param  {array|string} $args      Array of ImageOptimization arguments.
		 * @param  {string|null}  $scheme    Image scheme. Default to null.
		 *
		 * @return {string} $image_url New value.
		 * @since  1.0
		 */
		$image_url = apply_filters( 'powered_cache_image_optimizer_pre_image_url', $image_url, $args, $scheme );
		/**
		 * Filter the original ImageOptimization image parameters before ImageOptimization is applied to an image.
		 *
		 * @hook   powered_cache_image_optimizer_pre_args
		 *
		 * @param  {array|string} $args      Array of ImageOptimization arguments.
		 * @param  {string}       $image_url Image URL.
		 * @param  {string|null}  $scheme    Image scheme. Default to null.
		 *
		 * @return {array} New value.
		 * @since  1.0
		 */
		$args = apply_filters( 'powered_cache_image_optimizer_pre_args', $args, $image_url, $scheme );

		if ( empty( $image_url ) ) {
			return $image_url;
		}

		$image_url_parts = wp_parse_url( $image_url );

		// Unable to parse.
		if ( ! is_array( $image_url_parts ) || empty( $image_url_parts['host'] ) || empty( $image_url_parts['path'] ) ) {
			return $image_url;
		}

		if ( is_array( $args ) ) {
			// Convert values that are arrays into strings.
			foreach ( $args as $arg => $value ) {
				if ( is_array( $value ) ) {
					$args[ $arg ] = implode( ',', $value );
				}
			}

			// Encode values.
			// See https://core.trac.wordpress.org/ticket/17923 .
			$args = rawurlencode_deep( $args );
		}

		$custom_image_optimizer_url = apply_filters( 'powered_cache_image_optimizer_domain', '', $image_url );
		$custom_image_optimizer_url = esc_url( $custom_image_optimizer_url );

		// You can't run a ImageOptimization URL through ImageOptimization again because query strings are stripped.
		// So if the image is already a ImageOptimization URL, append the new arguments to the existing URL.
		// Alternately, if it's a *.files.wordpress.com url, then keep the domain as is.
		if (
			in_array( $image_url_parts['host'], [ 'img.poweredcache.net' ], true )
			|| wp_parse_url( $custom_image_optimizer_url, PHP_URL_HOST ) === $image_url_parts['host']
		) {
			$image_optimizer_url = add_query_arg( $args, $image_url );

			return self::url_schema( $image_optimizer_url, $scheme );
		}

		/**
		 * Allow ImageOptimization to use query strings as well.
		 * By default, ImageOptimization doesn't support query strings so we ignore them and look only at the path.
		 * This setting is ImageOptimization Server dependent.
		 *
		 * @module ImageOptimizer
		 *
		 * @param bool false Should images using query strings go through ImageOptimization. Default is false.
		 * @param string $image_url_parts ['host'] Image URL's host.
		 *
		 * @since  1.0
		 */
		if ( ! apply_filters( 'powered_cache_image_optimizer_any_extension_for_domain', false, $image_url_parts['host'] ) ) {
			// ImageOptimization doesn't support query strings so we ignore them and look only at the path.
			// However some source images are served via PHP so check the no-query-string extension.
			// For future proofing, this is an excluded list of common issues rather than an allow list.
			$extension = pathinfo( $image_url_parts['path'], PATHINFO_EXTENSION );
			if ( empty( $extension ) || in_array( $extension, array( 'php', 'ashx' ), true ) ) {
				return $image_url;
			}
		}

		$image_host_path = $image_url_parts['host'] . $image_url_parts['path'];

		/**
		 * Filters the domain used by the ImageOptimization module.
		 *
		 * @hook   powered_cache_image_optimizer_domain
		 *
		 * @param  {string} https://img.poweredcache.net Domain used by ImageOptimization.
		 * @param  {string} $image_url URL of the image to be optimized.
		 *
		 * @return {string} New value.
		 * @since  1.0
		 */
		$image_optimizer_domain = apply_filters( 'powered_cache_image_optimizer_domain', 'https://img.poweredcache.net', $image_url );
		$image_optimizer_domain = trailingslashit( esc_url( $image_optimizer_domain ) );
		$image_optimizer_url    = $image_optimizer_domain . $image_host_path;

		/**
		 * Add query strings to ImageOptimization URL.
		 * By default, ImageOptimization doesn't support query strings so we ignore them.
		 * This setting is ImageOptimization Server dependent.
		 *
		 * @hook   powered_cache_image_optimizer_add_query_string_to_domain
		 *
		 * @param  {boolean} false Should query strings be added to the image URL. Default is false.
		 * @param  {string} $image_url_parts ['host'] Image URL's host.
		 *
		 * @return {boolean} New value.
		 * @since  1.0
		 */
		if ( isset( $image_url_parts['query'] ) && apply_filters( 'powered_cache_image_optimizer_add_query_string_to_domain', false, $image_url_parts['host'] ) ) {
			$image_optimizer_url .= '?q=' . rawurlencode( $image_url_parts['query'] );
		}

		if ( $args ) {
			if ( is_array( $args ) ) {
				$image_optimizer_url = add_query_arg( $args, $image_optimizer_url );
			} else {
				// You can pass a query string for complicated requests but where you still want CDN subdomain help, etc.
				$image_optimizer_url .= '?' . $args;
			}
		}

		if ( isset( $image_url_parts['scheme'] ) && 'https' === $image_url_parts['scheme'] ) {
			$image_optimizer_url = add_query_arg( array( 'ssl' => 1 ), $image_optimizer_url );
		}

		if ( ! empty( self::$preferred_image_formats ) && 'webp' === self::$preferred_image_formats ) {
			$image_optimizer_url = add_query_arg( array( 'format' => 'webp' ), $image_optimizer_url );
		}

		return self::url_schema( $image_optimizer_url, $scheme );
	}

	/**
	 * Sets the scheme for a URL
	 *
	 * @param string $url    URL to set scheme.
	 * @param string $scheme Scheme to use. Accepts http, https, network_path.
	 *
	 * @return string URL.
	 */
	public static function url_schema( $url, $scheme ) {
		if ( ! in_array( $scheme, array( 'http', 'https', 'network_path' ), true ) ) {
			if ( preg_match( '#^(https?:)?//#', $url ) ) {
				return $url;
			}

			$scheme = 'http';
		}

		if ( 'network_path' === $scheme ) {
			$scheme_slashes = '//';
		} else {
			$scheme_slashes = "$scheme://";
		}

		return preg_replace( '#^([a-z:]+)?//#i', $scheme_slashes, $url );
	}


	/**
	 * Check to skip ImageOptimization for a known domain that shouldn't be ImageOptimizationized.
	 *
	 * @param bool   $skip      If the image should be skipped by ImageOptimization.
	 * @param string $image_url URL of the image.
	 *
	 * @return bool Should the image be skipped by ImageOptimization.
	 */
	public static function banned_domains( $skip, $image_url ) {
		$banned_host_patterns = array(
			'/^chart\.googleapis\.com$/',
			'/^chart\.apis\.google\.com$/',
			'/^graph\.facebook\.com$/',
			'/\.fbcdn\.net$/',
			'/\.paypalobjects\.com$/',
			'/\.dropbox\.com$/',
			'/\.cdninstagram\.com$/',
			'/^(commons|upload)\.wikimedia\.org$/',
			'/\.wikipedia\.org$/',
		);

		$host = wp_parse_url( $image_url, PHP_URL_HOST );

		foreach ( $banned_host_patterns as $banned_host_pattern ) {
			if ( 1 === preg_match( $banned_host_pattern, $host ) ) {
				return true;
			}
		}

		return $skip;
	}


	/**
	 * ImageOptimization - Support Text Widgets.
	 *
	 * @access public
	 *
	 * @param string $content Content from text widget.
	 *
	 * @return string
	 */
	public static function support_text_widgets( $content ) {
		return self::filter_the_content( $content );
	}

	/**
	 * Get supported extensions
	 *
	 * @return mixed|void
	 * @since 1.0
	 */
	public static function get_extensions() {
		/**
		 * Filters supported extensions
		 *
		 * @hook   powered_cache_image_optimizer_extensions
		 *
		 * @param  {array} Supported extensions
		 *
		 * @return {array} New value.
		 * @since  1.0
		 */
		return apply_filters( 'powered_cache_image_optimizer_extensions', self::$extensions );
	}


	/**
	 * Add img.poweredcache.net to dns prefetch list
	 *
	 * @param array  $urls          List of URLs
	 * @param string $relation_type relation type
	 *
	 * @return array
	 * @since 1.0
	 */
	public function add_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type && is_array( $urls ) ) {
			$urls[] = '//img.poweredcache.net';
		}

		return $urls;
	}

	/**
	 * Maybe modify post_thumbnail_url
	 *
	 * @param string $thumbnail_url Thumbnail url
	 *
	 * @return mixed|string
	 * @since 1.0
	 */
	public function maybe_modify_post_thumbnail_url( $thumbnail_url ) {
		if ( false === stripos( $thumbnail_url, 'img.poweredcache.net' ) ) {
			$thumbnail_url = $this->image_optimizer_url( $thumbnail_url );
		}

		return $thumbnail_url;
	}


	/**
	 * Skip image-optimizer for delayed script
	 *
	 * @param boolean $is_delay_skipped Whether skip or not skip delayed JS
	 * @param string  $script           script
	 *
	 * @return boolean
	 * @since 1.0
	 */
	public function maybe_delayed_js_skip( $is_delay_skipped, $script ) {
		if ( false !== stripos( $script, IMAGE_OPTIMIZER_PRO_URL . 'dist/js/image-optimizer.js' ) ) {
			return true;
		}

		return $is_delay_skipped;
	}

	/**
	 * Start output buffering
	 *
	 * @since 1.0
	 */
	public function start_buffer() {
		ob_start( [ '\ImageOptimizerPro\Optimizer', 'end_buffering' ] );
	}


	/**
	 * Replace origin URLs with Image Optimizer URL.
	 *
	 * @param string $contents Output buffer.
	 * @param int    $phase    Bitmask of PHP_OUTPUT_HANDLER_* constants.
	 *
	 * @return string|string[]|null
	 * @since 1.0
	 */
	private static function end_buffering( $contents, $phase ) {
		if ( $phase & PHP_OUTPUT_HANDLER_FINAL || $phase & PHP_OUTPUT_HANDLER_END ) {
			$integration_status = true;
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
				$integration_status = false;
			}

			// check conditional tags
			if ( is_admin() || is_trackback() || is_robots() || is_preview() ) {
				$integration_status = false;
			}

			/**
			 * Filters ImageOptimizer integration for output buffer
			 *
			 * @hook   powered_cache_image_optimizer_process_buffer
			 *
			 * @param  {boolean} $integration_status depends on conditional tags and request
			 *
			 * @return {boolean} New value.
			 * @since  1.0
			 */
			$integration_status = (bool) apply_filters( 'powered_cache_image_optimizer_process_buffer', $integration_status );

			if ( $integration_status ) {
				return self::filter_the_content( $contents );
			}
		}

		return $contents;
	}

}
