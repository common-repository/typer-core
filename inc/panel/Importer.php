<?php

namespace Seventhqueen\Typer\Core\Panel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Importer
 * @package Seventhqueen\Typer\Core\Panel
 */
class Importer {

	/**
	 * @var
	 */
	private static $instance;

	/**
	 * @var string
	 */
	private $api_url;

	/**
	 * @var array
	 */
	private static $pages_data = [];

	/**
	 * @var array
	 */
	public $messages = [];

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var string
	 */
	public $session = '';

	/**
	 * @var bool
	 */
	public $data_imported = false;

	/**
	 * Array with pages imported
	 *
	 * @var array
	 */
	public $pages_imported = [];

	/**
	 * Save mapping for search and replace
	 * @var array
	 */
	private $url_remap = [];

	/**
	 * Keep track of images imported
	 *
	 * @var array
	 */
	public $images_imported = [];

	/**
	 * Save the images that will be imported
	 * @var array
	 */
	public $total_images = [];

	/**
	 * Keep a history of all imported images on the site
	 * @var null
	 */
	public $image_history = null;

	/**
	 * Save images from post content for later import
	 * @var array
	 */
	public $content_images = [];

	/**
	 * Save attached posts images for later import
	 * @var array
	 */
	public $attached_images = [];

	/**
	 * Save slide media images for later import
	 * @var array
	 */
	public $slide_meta_images = [];

	/**
	 * Save elementor images for later import
	 * @var array
	 */
	public $elementor_images = [];

	/**
	 * Save featured images for later import
	 * @var array
	 */
	public $featured_images = [];

	/**
	 * Save external id and url for image import
	 * @var array
	 */
	public $failed_images = [];

	/**
	 * @var array
	 */
	public $remote_images = [];

	/**
	 * @var string
	 */
	public $remote_url_base = '';

	/**
	 * @var string
	 */
	public $local_url_base = '';

	/**
	 * @var int
	 */
	public $processes = 0;

	/**
	 * @var int
	 */
	public $done_processes = 0;

	/**
	 * @var null
	 */
	public $progress_pid = null;

	/**
	 * @var null
	 */
	private $progress = null;

	/**
	 * @var string
	 */
	private $theme_slug;

	/**
	 * @var false|string
	 */
	private $theme_version;

	/**
	 *
	 * @return Importer
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->theme_slug    = 'typer';
		$this->theme_version = wp_get_theme( get_template() )->get( 'Version' );
		$this->api_url       = 'https://feeder.seventhqueen.com/wp-json/sq/v1/demos/' . $this->theme_slug;

		add_action( 'admin_menu', [ $this, 'register_panel_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'import_assets' ] );

		add_action( 'wp_ajax_sq_single_import', [ $this, 'do_ajax' ] );
		add_action( 'wp_ajax_sq_set_as_home', [ $this, 'set_as_homepage' ] );

		add_action( 'svq/import/after_process', [ $this, 'jet_popup_post_process' ], 10 );

		add_filter( 'typer_import_columns', '__return_zero' );
		add_action( 'typer_import_page_content', [ $this, 'tpl_main_import_page_content' ] );

		if ( isset( $_REQUEST['sq_single_import'] ) && $_REQUEST['sq_single_import'] && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_filter( 'wp_redirect', static function () {
				return false;
			} );
		}
	}

	/**
	 * Register panel page
	 */
	public function register_panel_page() {
		add_theme_page(
			sprintf( esc_html__( '%s Importer', 'typer-core' ), 'Typer' ),
			sprintf( esc_html__( '%s Importer', 'typer-core' ), 'Typer' ),
			'manage_options',
			$this->theme_slug . '-import',
			[ $this, 'panel_menu_callback' ]
		);
	}

	public function panel_menu_callback() {
		$site_url      = apply_filters( 'typer_admin_site_url', 'https://' . $this->theme_slug . '.seventhqueen.com' );
		$wrapper_class = apply_filters( 'typer_welcome_wrapper_class', [ $this->theme_slug . '_import' ] );

		?>
        <div class="sqp_typer_options wrap sqp-m-0 <?php echo esc_attr( implode( ' ', $wrapper_class ) ); ?>">
            <div class="sqp-bg-white sqp-py-5 sqp-mb-5 sqp-shadow">
                <div class="sqp-container sqp-mx-auto sqp-px-5 sqp-flex sqp-flex-wrap sqp-justify-between sqp-items-center">
                    <div class="sqp-text-left">
                        <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener"
                           class="sqp-text-base sqp-flex sqp-items-center sqp-content-center sqp-no-underline">
                            <img src="<?php echo esc_url( get_parent_theme_file_uri( 'assets/img/logo-black.png' ) ); ?>"
                                 class="sqp-border-0 sqp-w-32" alt="Typer">
                            <span class="sqp-bg-gray-300 sqp-text-gray-600 sqp-ml-4 sqp-px-2 sqp-py-1 sqp-text-xs sqp-rounded sqp-no-underline">
                                <?php echo $this->theme_version; ?>
                            </span>

							<?php do_action( 'typer_welcome_page_header_title' ); ?>
                        </a>
                    </div>

					<?php do_action( 'typer_header_right_section' ); ?>

                </div>
            </div>

			<?php do_action( 'typer_menu_panel_action' ); ?>
        </div>
		<?php
	}

	private function query_demo_sets() {
		$transient = 'sq_typer_import_data';

		if ( isset( $_GET['sq-clear-cache'] ) ) {
			delete_transient( $transient );
		}

		if ( $data = get_transient( $transient ) ) {
			return $data;
		}
		$data = [];

		// Get remote file
		$response = wp_remote_get( $this->api_url, [
			'headers' => [
				'Theme-Version' => $this->theme_version,
				'Theme-License' => '' // license code
			]
		] );

		// Check for error
		if ( ! is_wp_error( $response ) ) {
			// Parse remote HTML file
			$file_contents = wp_remote_retrieve_body( $response );
			// Check for error
			if ( ! is_wp_error( $file_contents ) ) {

				$data = json_decode( $file_contents, true );
				set_transient( $transient, $data, 60 * 60 * 12 );
			}
		}

		return $data;
	}

	/**
	 * Retrieve the demo sets
	 */
	public static function get_demo_sets() {
		if ( empty( self::$pages_data ) ) {
			$demo_data = self::instance()->query_demo_sets();

			self::add_demo_sets( $demo_data );
		}

		return self::$pages_data;
	}

	/**
	 * Add multiple demo sets
	 *
	 * @param $data
	 */
	public static function add_demo_sets( $data ) {
		if (!is_array(self::$pages_data)) {
			self::$pages_data = array();
		}
		
		if (is_array($data)) {
			self::$pages_data = array_merge(self::$pages_data, $data);
		}
	}

	/**
	 * Add a demo set
	 *
	 * @param string $slug
	 * @param array $data
	 */
	public static function add_demo_set( $slug, $data = [] ) {
		self::$pages_data[ $slug ] = $data;
	}


	/** ---------------------------------------------------------------------------
	 * Enqueue scripts
	 * ---------------------------------------------------------------------------- */

	public function import_assets() {
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], $this->theme_slug ) === 0 ) {

			wp_enqueue_script( 'jquery-ui-tooltip' );

			wp_enqueue_style( 'typer-import', get_theme_file_uri( '/assets/admin/css/import.css' ), [], $this->theme_version );
			wp_enqueue_script( 'typer-import', get_theme_file_uri( '/assets/admin/js/import.js' ), [
				'jquery',
				'jquery-ui-tooltip'
			], $this->theme_version, true );
		}
	}

	public function set_as_homepage() {
		if ( session_id() ) {
			session_write_close();
		}
		check_ajax_referer( 'import_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => esc_html__( 'We&apos;re sorry, something went wrong.', 'typer-core' ),
			] );
			exit;
		}

		if ( isset( $_POST['pid'] ) ) {
			$post_id = $_POST['pid'];
			if ( get_post_status( $post_id ) === 'publish' ) {
				if ( 'page' === get_post_type( $post_id ) ) {
					update_option( 'page_on_front', $post_id );
					update_option( 'show_on_front', 'page' );
					wp_send_json_success( [
						'message' => esc_html__( 'Successfully set as homepage!', 'typer-core' ),
					] );
					exit;
				}
			}
		}
		wp_send_json_success( [
			'message' => esc_html__( 'An error occurred setting the page as home!!!', 'typer-core' ),
		] );
		exit;
	}

	public function do_ajax() {
		if ( session_id() ) {
			session_write_close();
		}

		check_ajax_referer( 'import_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => $this->set_error_message( esc_html__( 'We&apos;re sorry, the demo failed to import.', 'typer-core' ) ),
			] );
			exit;
		}

		if ( ! isset( $_POST['options'] ) ) {
			wp_send_json_error( [
				'message' => $this->set_error_message( esc_html__( 'Something went wrong. Please try again.', 'typer-core' ) ),
			] );
			exit;
		}

		$data = [];

		parse_str( $_POST['options'], $data );

		if ( ! isset( $data['import_demo'] ) ) {
			wp_send_json_error( [
				'message' => $this->set_error_message( esc_html__( 'Something went wrong with the data sent. Please try again.', 'typer-core' ) ),
			] );
			exit;
		}

		$demo_sets   = self::get_demo_sets();
		$current_set = $data['import_demo'];

		if ( ! array_key_exists( $current_set, $demo_sets ) ) {
			wp_send_json_error( [
				'message' => $this->set_error_message( esc_html__( 'Something went wrong with the data sent. Please try again.', 'typer-core' ) ),
			] );
			exit;
		}

		$set_data     = $demo_sets[ $current_set ];
		$progress_pid = $_POST['pid'];

		$response = $this->process_import( [
			'set_data' => $set_data,
			'pid'      => $progress_pid,
			'data'     => $data,
		] );

		$this->send_ajax_response( $response );
	}

	/**
	 * @param array|\WP_Error $data
	 */
	private function send_ajax_response( $data ) {
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [
				'message' => $this->set_error_message(
					esc_html__( 'There was an error in the import process. Try to do the import once again!', 'typer-core' ) .
					'<br>' . $data->get_error_message()
				),
				'debug'   => implode( ',', $this->messages ),
			] );
			exit;
		}

		if ( is_array( $data ) ) {
			$response            = $data;
			$response['debug']   = implode( ',', $this->messages );
			$response['message'] = $data['message'];
		} else {
			$response            = [];
			$response['process'] = $data;
			$response['debug']   = implode( ',', $this->messages );
			$response['message'] = implode( ',', $this->messages );
		}

		/* make sure we are regenerating theme dynamic file */
		//svq_generate_dynamic_css();

		wp_send_json_success( $response );
	}

	private function set_error_message( $msg ) {
		$header = '<div class="bg-msg fail-msg"><span class="dashicons dashicons-warning"></span></div>';

		return $header . $msg;
	}

	private function set_success_message( $msg ) {
		$header = '<div class="bg-msg success-msg"><span class="dashicons dashicons-yes"></span></div>';

		return $header . $msg;
	}

	private function should_process_step( $step ) {
		if ( isset( $this->progress['steps'] ) ) {
			if ( isset( $this->progress['steps'][ $step ] ) ) {
				return false;
			}
		}

		return true;
	}

	public function get_progress( $pid ) {
		return get_transient( 'sq_import_' . $pid );
	}

	public function set_progress( $pid, $data, $stop_process = false ) {
		if ( $pid ) {
			$new_data = $data;

			if ( $current_data = $this->get_progress( $pid ) ) {
				$new_data = array_merge( $current_data, $data );
			}

			if ( isset( $data['step'] ) ) {
				$new_data['steps'][ $data['step'] ] = true;
			}

			$new_data['done_processes'] = $this->done_processes;
			$new_data['processes']      = $this->processes;

			if ( ! isset( $data['progress'] ) ) {
				if ( 0 === $this->done_processes ) {
					$new_data['progress'] = 1;
				} else {
					$new_data['progress'] = floor( $this->done_processes / $this->processes * 100 );
				}
			}

			/* Extra persistent data */
			if ( ! empty( $this->pages_imported ) ) {
				$new_data['pages_imported'] = $this->pages_imported;
			}

			set_transient( 'sq_import_' . $pid, $new_data, 60 * 60 );

			if ( $stop_process === true ) {
				$this->send_ajax_response( [
					'message'  => $new_data['text'],
					'process'  => $new_data['step'],
					'progress' => $new_data['progress'],
				] );
			}
		}
	}

	/**
	 * Process all the import steps
	 *
	 * @param array $options
	 *
	 * @return array|\WP_Error
	 */
	public function process_import( $options ) {
		$imported         = false;
		$content_imported = false;

		$set_data           = $options['set_data'];
		$progress_pid       = $options['pid'];
		$this->progress_pid = $progress_pid;
		$progress           = $this->get_progress( $progress_pid );
		$this->progress     = $progress;
		$data               = $options['data'];

		if ( isset( $progress['image_imported'] ) ) {
			$this->images_imported = $progress['image_imported'];
		}
		if ( isset( $progress['pages_imported'] ) ) {
			$this->pages_imported = $progress['pages_imported'];
		}
		if ( isset( $progress['imported'] ) ) {
			$imported = true;
		}
		if ( isset( $progress['content_imported'] ) ) {
			$content_imported = true;
		}
		if ( isset( $progress['done_processes'] ) ) {
			$this->done_processes = $progress['done_processes'];
		} else {
			$this->done_processes = 0;
		}
		if ( isset( $progress['processes'] ) ) {
			$this->processes = $progress['processes'];
		} else {
			$this->processes = count( $data ) + 1;
		}
		if ( isset( $progress['remote_url_base'] ) ) {
			$this->remote_url_base = $progress['remote_url_base'];
		}
		if ( isset( $progress['total_images'] ) ) {
			$this->total_images = $progress['total_images'];
		}
		if ( isset( $progress['elementor_images'] ) ) {
			$this->elementor_images = $progress['elementor_images'];
		}
		if ( isset( $progress['url_remap'] ) ) {
			$this->url_remap = $progress['url_remap'];
		}
		if ( isset( $progress['attached_images'] ) ) {
			$this->attached_images = $progress['attached_images'];
		}
		if ( isset( $progress['slide_meta_images'] ) ) {
			$this->slide_meta_images = $progress['slide_meta_images'];
		}
		if ( isset( $progress['featured_images'] ) ) {
			$this->featured_images = $progress['featured_images'];
		}
		if ( isset( $progress['content_images'] ) ) {
			$this->content_images = $progress['content_images'];
		}
		if ( isset( $progress['failed_images'] ) ) {
			$this->failed_images = $progress['failed_images'];
		}

		// Importer classes
		if ( ! class_exists( '\WP_Importer' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		// Activate required plugins
		if ( isset( $data['activate_plugins'] ) && $this->should_process_step( 'plugins' ) ) {
			$this->processes += count( $set_data['plugins'] ) - 1;
			$this->activate_plugins( $set_data );
		}

		// Import content
		if ( isset( $set_data['content'] ) && is_array( $set_data['content'] ) ) {
			foreach ( $set_data['content'] as $content ) {
				if ( ! isset( $data['import_content'], $content['id'] ) || ! in_array( $content['id'], $data['import_content'] ) ) {
					continue;
				}

				if ( ! $this->should_process_step( 'content_' . $content['id'] ) ) {
					continue;
				}

				$content_type = 'content';
				if ( isset( $content['type'] ) && is_string( $content['type'] ) ) {
					$content_type = $content['type'];
				}

				$imported     = true;
				$ok_to_import = true;
				if ( 'menu' === $content_type ) {
					if ( is_nav_menu( $content['id'] ) ) {
						$ok_to_import = false;
					}
				} else {
					$content_imported = true;
				}

				if ( 'stax' === $content_type ) {
					//import_stax_zones
					$this->import_stax_zones( $content['link'] );

					$this->set_progress( $progress_pid, [
						'text'     => 'Imported Stax templates...',
						'imported' => true,
						'step'     => 'stax',
					], true );

				} elseif ( 'widgets' === $content_type ) {

					//widgets
					$file_path = $content['link'];

					$widgets_file_data = wp_remote_get( $file_path );
					if ( ! is_wp_error( $widgets_file_data ) ) {
						$file_data = wp_remote_retrieve_body( $widgets_file_data );

						if ( ! is_wp_error( $file_data ) ) {
							$this->import_widget_data( $file_data );

							$this->messages[] = esc_html__( 'Imported widgets', 'typer-core' );
						} else {
							$this->messages[] = $file_data->get_error_message();
						}
					}

					$this->set_progress( $progress_pid, [
						'text'     => esc_html__( 'Imported widgets...', 'typer-core' ),
						'imported' => true,
						'step'     => 'content_' . $content['id'],
					], true );

				} elseif ( 'options' === $content_type ) {

					$this->import_options( $content['link'] );

					$this->messages[] = esc_html__( 'Imported Customizer settings', 'typer-core' );

					$this->set_progress( $progress_pid, [
						'text'     => esc_html__( 'Imported Customizer settings...', 'typer-core' ),
						'imported' => true,
						'step'     => 'content_' . $content['id'],
					], true );

				} elseif ( 'revslider' === $content_type ) {
					$sliders = (array) $content['id'];

					if ( ! empty( $sliders ) ) {
						foreach ( $sliders as $file_name ) {
							/* if a slider doesn't already exist */
							if ( $this->check_existing_slider( $file_name ) ) {

								/* Download the file and import it */
								if ( $this->check_revslider_file( $content['link'], $file_name ) ) {
									//file name provided without extension
									$this->import_revslider( $file_name );
									$this->messages[] = sprintf( esc_html__( 'Imported Revslider %s', 'typer-core' ), $file_name );
								}
							}
						}
					}

					$this->set_progress( $progress_pid, [
						'text'     => 'Imported Revolution slider...',
						'imported' => true,
						'step'     => 'content_' . $content['id'],
					], true );

				} else {
					if ( $ok_to_import && $content['link'] ) {

						$this->import_content( $content['link'], true );

						$this->messages[] = sprintf( esc_html__( '%s complete', 'typer-core' ), $content['name'] );
					}

					$this->set_progress( $progress_pid, [
						'text'             => 'Imported ' . ucfirst( $content['name'] ) . '...',
						'imported'         => true,
						'content_imported' => true,
						'step'             => 'content_' . $content['id'],
					] );

				}

				$this->done_processes ++;
			}

			// set menu locations
			if ( isset( $set_data['menu_mapping'] ) && ! empty( $set_data['menu_mapping'] ) ) {
				foreach ( $set_data['menu_mapping'] as $set_datum ) {

					$locations = [];

					if ( isset( $set_datum['location'] ) && isset( $set_datum['menu'] ) ) {
						$locations[ $set_datum['location'] ] = $set_datum['menu'];
					}
					$this->import_menu_location( $locations );

				}
			}
		}

		//check bp profile fields
		if ( isset( $data['import_bp_fields'] ) && isset( $set_data['bp_fields'] ) && $this->should_process_step( 'bp_fields' ) ) {
			$imported = true;

			$this->import_bp_fields( $set_data['bp_fields'] );
			$this->messages[] = esc_html__( 'Imported BuddyPress profile fields', 'typer-core' );
			$this->done_processes ++;

			$this->set_progress( $progress_pid, [
				'text'     => 'Imported BuddyPress profile fields',
				'imported' => true,
				'step'     => 'bp_fields',
			] );
		}

		if ( isset( $data['import_pmpro'], $set_data['pmpro'] ) && $this->should_process_step( 'pmpro' ) ) {
			$imported = true;

			$this->import_pmpro( $set_data['pmpro'] );

			$this->messages[] = esc_html__( 'Imported PMPRO Levels', 'typer-core' );
			$this->done_processes ++;

			$this->set_progress( $progress_pid, [
				'text'     => 'Imported PMPRO Levels',
				'imported' => true,
				'step'     => 'pmpro',
			] );
		}

		//replace imported image URLs with self hosted images
		if ( $content_imported ) {
			$this->processes ++;
			$this->post_process_posts();
			$this->done_processes ++;
		}

		$success_message = '<h3>' . esc_html__( 'Awesome. Your import is ready!!!', 'typer-core' ) . '</h3>';

		$posts_summary = '';
		if ( ! empty( $this->pages_imported ) ) {
			$this->pages_imported = array_reverse( $this->pages_imported, true );
			foreach ( $this->pages_imported as $pid => $item ) {
				$posts_summary .= get_the_title( $pid );
				$posts_summary .= '<a href="#" title="' . __( 'Set as HomePage', 'typer-core' ) . '" class="svq-set-as-home" data-pid="' . $pid . '">' .
				                  '<span class="dashicons dashicons-admin-home"></span> ' .
				                  '</a>' .
				                  '<a target="_blank" href="' . get_permalink( $pid ) . '" title="' . esc_html__( 'View Page', 'typer-core' ) . '">' .
				                  '<span class="dashicons dashicons-visibility"></span>' .
				                  '</a>' .
				                  '<a target="_blank" title="' . esc_html__( 'Edit Page', 'typer-core' ) . '" href="' . get_admin_url( null, 'post.php?post=' . $pid . '&action=edit' ) . '">' .
				                  '<span class="dashicons dashicons-edit"></span>' .
				                  '</a><br>';
			}
		} else if ( isset( $data['import_page'] ) ) {
			$success_message = esc_html__( 'Your selected page already exists. Please check also Trash!', 'typer-core' );
		}

		if ( $posts_summary ) {
			$success_message .= '<p class="import-summary">' .
			                    __( 'Imported Pages:', 'typer-core' ) . '<br>' .
			                    $posts_summary .
			                    '</p>';
		}

		if ( ! $imported ) {
			$this->error .= __( 'Nothing was selected for import!!!', 'typer-core' );
		}

		return $this->get_import_response( $this->set_success_message( $success_message ) );
	}

	private function get_import_response( $success_message, $process = null ) {
		if ( '' === $this->error ) {
			$response = [
				'message' => $success_message,
			];
			if ( $process !== null ) {
				$response['process'] = $process;
			}

			return $response;
		}

		return new \WP_Error( '__k__', $this->error );
	}

	public function activate_plugins( $set_data, $set_progress = false ) {
		if ( isset( $set_data['plugins'] ) && ! empty( $set_data['plugins'] ) ) {
			foreach ( $set_data['plugins'] as $plugin ) {
				if ( is_array( $plugin ) ) {
					$plugin = $plugin['slug'];
				}

				$msg              = '';
				$plugin_nice_name = ucfirst( str_replace( [ '_', '-' ], ' ', $plugin ) );

				$addons_manager = null;

				if ( class_exists( \Seventhqueen\Typer\Panel\Addons_Manager::class ) ) {
					$addons_manager = \Seventhqueen\Typer\Panel\Addons_Manager::instance();
				}

				if ( ! $addons_manager ) {
					return;
				}

				// continue if the plugin is not registered
				if ( ! isset( $addons_manager->plugins[ $plugin ] ) ) {
					continue;
				}

				if ( ! $addons_manager->is_plugin_installed( $plugin ) ) {
					$install = $addons_manager->do_plugin_install( $plugin, false );
					if ( isset( $install['error'] ) ) {
						$this->error .= '<br>' . $plugin_nice_name . ': ' . $install['error'];
					}
					$msg              = sprintf( esc_html__( 'Installed %s plugin', 'typer-core' ), $plugin_nice_name );
					$this->messages[] = $msg;
				}

				if ( ! $addons_manager->check_plugin_active( $plugin ) ) {
					$activate = $addons_manager->do_plugin_activate( $plugin, false );
					if ( isset( $activate['error'] ) ) {
						$this->error .= '<br>' . $plugin_nice_name . ': ' . $activate['error'];
					}
					$msg              = sprintf( esc_html__( 'Activated %s plugin', 'typer-core' ), $plugin_nice_name );
					$this->messages[] = $msg;
				}

				if ( $set_progress ) {
					$this->done_processes ++;
					if ( $msg ) {
						$this->set_progress( $this->progress_pid, [
							'text' => $msg,
							'step' => 'plugin_' . $plugin,
						], true );
					}
				}
			}

			//make sure to set plugins process as complete
			if ( $set_progress ) {
				$this->set_progress(
					$this->progress_pid,
					[
						'text' => esc_html__( 'Plugins installed. Starting content import.', 'typer-core' ),
						'step' => 'plugins',
					],
					true
				);
			}
		}
	}

	/**
	 * Import PMPRO Levels
	 *
	 * @param string $file
	 *
	 * @return bool
	 */
	public function import_pmpro( $file = 'pmpro' ) {
		$imported_ids = get_option( 'svq_' . get_template() . '_import_pmpro_' . $file );
		if ( ! is_array( $imported_ids ) ) {
			$imported_ids = [];
		}

		$file_data = wp_remote_get( SVQ_IMPORT_DEMO_URL . $file . '.txt' );
		if ( is_wp_error( $file_data ) ) {
			return false;
		}

		$data = wp_remote_retrieve_body( $file_data );
		$data = json_decode( $data );

		global $wpdb;

		foreach ( $data->levels as $level ) {
			$wpdb->replace(
				$wpdb->pmpro_membership_levels,
				[
					'id'                => isset( $imported_ids[ $level->id ] ) ? $imported_ids[ $level->id ] : 0,
					'name'              => $level->name,
					'description'       => $level->description,
					'confirmation'      => '',
					'initial_payment'   => $level->initial_payment,
					'billing_amount'    => $level->billing_amount,
					'cycle_number'      => $level->cycle_number,
					'cycle_period'      => $level->cycle_period,
					'billing_limit'     => $level->billing_limit,
					'trial_amount'      => $level->trial_amount,
					'trial_limit'       => $level->trial_limit,
					'expiration_number' => $level->expiration_number,
					'expiration_period' => $level->expiration_period,
					'allow_signups'     => 1
				],
				[
					'%d',        //id
					'%s',        //name
					'%s',        //description
					'%s',        //confirmation
					'%f',        //initial_payment
					'%f',        //billing_amount
					'%d',        //cycle_number
					'%s',        //cycle_period
					'%d',        //billing_limit
					'%f',        //trial_amount
					'%d',        //trial_limit
					'%d',        //expiration_number
					'%s',        //expiration_period
					'%d',        //allow_signups
				]
			);

			if ( isset( $imported_ids[ $level->id ] ) ) {
				$the_id = $imported_ids[ $level->id ];
			} elseif ( ! $wpdb->insert_id ) {
				continue;
			} else {
				$the_id = $wpdb->insert_id;
			}

			$imported_ids[ $level->id ] = $the_id;

			if ( isset( $level->seeko_pmpro_color ) ) {
				$color = sanitize_text_field( $level->seeko_pmpro_color );
			} else {
				$color = false;
			}

			if ( isset( $level->seeko_pmpro_popular ) ) {
				$popular = 'yes';
			} else {
				$popular = false;
			}

			$options = get_option( get_template() . '_pmpro' );
			if ( ! $options ) {
				$options = [];
			}
			$options[ $the_id ] = [
				'color'   => $color,
				'popular' => $popular,
			];

			update_option( get_template() . '_pmpro', $options, 'no' );
		}

		update_option( 'svq_' . get_template() . '_import_pmpro_' . $file, $imported_ids );

		return true;
	}

	/** ---------------------------------------------------------------------------
	 * Import | Content
	 *
	 * @param string $file
	 * @param bool $force_attachments
	 *
	 * @return mixed
	 *
	 * ---------------------------------------------------------------------------- */
	public function import_content( $file = false, $force_attachments = false ) {
		if ( ! $file ) {
			return false;
		}

		if ( ! class_exists( '\WP_Importer' ) || ! class_exists( '\SVQ_WP_Import' ) ) {
			return new \WP_Error( '__k__', __( 'SQ Import Helper plugin needs to be active. Please try again.', 'typer-core' ) );
		}

		$import = new \SVQ_WP_Import();

		$xml = $file;

		if ( true == $force_attachments ) {
			$import->fetch_attachments = true;
		} else {
			$import->fetch_attachments = ( $_POST && array_key_exists( 'attachments', $_POST ) && $_POST['attachments'] ) ? true : false;
		}

		ob_start();
		$import->import( $xml );

		return ob_end_clean();
	}

	public function import_sidebars( $path ) {
		//add any extra sidebars
		$sidebars_file_data = wp_remote_get( $path );
		if ( ! is_wp_error( $sidebars_file_data ) ) {
			$sidebars_data = unserialize( wp_remote_retrieve_body( $sidebars_file_data ) );
			$old_sidebars  = get_option( 'sbg_sidebars' );
			if ( ! empty( $old_sidebars ) ) {
				$sidebars_data = array_merge( $sidebars_data, $old_sidebars );
			}
			update_option( 'sbg_sidebars', $sidebars_data );
		}
	}

	/** ---------------------------------------------------------------------------
	 * Parse JSON import file
	 *
	 * @param $json_data
	 * http://wordpress.org/plugins/widget-settings-importexport/
	 * ---------------------------------------------------------------------------- */
	public function import_widget_data( $json_data ) {

		$json_data    = json_decode( $json_data, true );
		$sidebar_data = $json_data[0];
		$widget_data  = $json_data[1];

		// prepare widgets table
		$widgets = [];
		foreach ( $widget_data as $k_w => $widget_type ) {
			if ( $k_w ) {
				$widgets[ $k_w ] = [];
				foreach ( $widget_type as $k_wt => $widget ) {
					if ( is_int( $k_wt ) ) {
						$widgets[ $k_w ][ $k_wt ] = 1;
					}
				}
			}
		}

		// sidebars
		foreach ( $sidebar_data as $title => $sidebar ) {
			$count = count( $sidebar );
			for ( $i = 0; $i < $count; $i ++ ) {
				$widget               = [];
				$widget['type']       = trim( substr( $sidebar[ $i ], 0, strrpos( $sidebar[ $i ], '-' ) ) );
				$widget['type-index'] = trim( substr( $sidebar[ $i ], strrpos( $sidebar[ $i ], '-' ) + 1 ) );
				if ( ! isset( $widgets[ $widget['type'] ][ $widget['type-index'] ] ) ) {
					unset( $sidebar_data[ $title ][ $i ] );
				}
			}
			$sidebar_data[ $title ] = array_values( $sidebar_data[ $title ] );
		}

		// widgets
		foreach ( $widgets as $widget_title => $widget_value ) {
			foreach ( $widget_value as $widget_key => $widget_value2 ) {
				$widgets[ $widget_title ][ $widget_key ] = $widget_data[ $widget_title ][ $widget_key ];
			}
		}

		$sidebar_data = [ array_filter( $sidebar_data ), $widgets ];
		$this->parse_import_data( $sidebar_data );
	}

	/**
	 * Import widgets
	 * http://wordpress.org/plugins/widget-settings-importexport/
	 *
	 * @param $import_array
	 *
	 * @return bool
	 */
	public function parse_import_data( $import_array ) {
		$sidebars_data = $import_array[0];
		$widget_data   = $import_array[1];

		$current_sidebars = get_option( 'sidebars_widgets' );
		$new_widgets      = [];

		foreach ( $sidebars_data as $import_sidebar => $import_widgets ) :

			$current_sidebars[ $import_sidebar ] = [];

			foreach ( $import_widgets as $import_widget ) :
				//if the sidebar exists
				if ( isset( $current_sidebars[ $import_sidebar ] ) ) :
					$title               = trim( substr( $import_widget, 0, strrpos( $import_widget, '-' ) ) );
					$index               = trim( substr( $import_widget, strrpos( $import_widget, '-' ) + 1 ) );
					$current_widget_data = get_option( 'widget_' . $title );
					$new_widget_name     = self::get_new_widget_name( $title, $index );
					$new_index           = trim( substr( $new_widget_name, strrpos( $new_widget_name, '-' ) + 1 ) );
					if ( ! empty( $new_widgets[ $title ] ) && is_array( $new_widgets[ $title ] ) ) {
						while ( array_key_exists( $new_index, $new_widgets[ $title ] ) ) {
							$new_index ++;
						}
					}
					if ( ! $current_widget_data ) {
						$current_widget_data = [];
					}

					$current_sidebars[ $import_sidebar ][] = $title . '-' . $new_index;
					if ( array_key_exists( $title, $new_widgets ) ) {
						$new_widgets[ $title ][ $new_index ] = $widget_data[ $title ][ $index ];
						$multiwidget                         = $new_widgets[ $title ]['_multiwidget'];

						if ( isset( $new_widgets[ $title ]['_multiwidget'] ) ) {
							unset( $new_widgets[ $title ]['_multiwidget'] );
						}

						$new_widgets[ $title ]['_multiwidget'] = $multiwidget;
					} else {
						$current_widget_data[ $new_index ] = $widget_data[ $title ][ $index ];
						$current_multiwidget               = isset( $current_widget_data['_multiwidget'] ) ? $current_widget_data['_multiwidget'] : '';
						$new_multiwidget                   = isset( $widget_data[ $title ]['_multiwidget'] ) ? $widget_data[ $title ]['_multiwidget'] : false;
						$multiwidget                       = ( $current_multiwidget != $new_multiwidget ) ? $current_multiwidget : 1;

						if ( isset( $current_widget_data['_multiwidget'] ) ) {
							unset( $current_widget_data['_multiwidget'] );
						}

						$current_widget_data['_multiwidget'] = $multiwidget;
						$new_widgets[ $title ]               = $current_widget_data;
					}
				endif;
			endforeach;
		endforeach;
		if ( isset( $new_widgets ) && isset( $current_sidebars ) ) {
			update_option( 'sidebars_widgets', $current_sidebars );
			foreach ( $new_widgets as $title => $content ) {
				$content = apply_filters( 'widget_data_import', $content, $title );
				update_option( 'widget_' . $title, $content );
			}

			return true;
		}

		return false;
	}

	/** ---------------------------------------------------------------------------
	 * Get new widget name
	 * http://wordpress.org/plugins/widget-settings-importexport/
	 * ---------------------------------------------------------------------------- */
	public function get_new_widget_name( $widget_name, $widget_index ) {
		$current_sidebars = get_option( 'sidebars_widgets' );
		$all_widget_array = [];
		foreach ( $current_sidebars as $sidebar => $widgets ) {
			if ( ! empty( $widgets ) && is_array( $widgets ) && $sidebar !== 'wp_inactive_widgets' ) {
				foreach ( $widgets as $widget ) {
					$all_widget_array[] = $widget;
				}
			}
		}

		while ( in_array( $widget_name . '-' . $widget_index, $all_widget_array ) ) {
			$widget_index ++;
		}

		return $widget_name . '-' . $widget_index;
	}

	/**
	 * Import theme options
	 *
	 * @param string $file
	 */
	public function import_options( $file = '' ) {
		if ( '' === $file ) {
			return;
		}

		$options_file_data = wp_remote_get( $file );
		if ( ! is_wp_error( $options_file_data ) ) {
			$file_data = wp_remote_retrieve_body( $options_file_data );

			if ( ! is_wp_error( $file_data ) && $data = json_decode( $file_data, true ) ) {
				foreach ( $data as $k => $v ) {
					set_theme_mod( $k, $v );
				}
				set_theme_mod( 'stime', time() );

				do_action( 'svq/import/after_import_options' );
			}
		}

	}

	/**
	 * Check if a Revslider with the given name exists
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public function check_existing_slider( $name ) {
		if ( ! class_exists( '\RevSlider' ) ) {
			$this->error = 'Please activate Revolution slider and do the import again!';

			return false;
		}

		$revslider = new \RevSlider();
		$sliders   = $revslider->getArrSliders();

		foreach ( $sliders as $slider ) {
			if ( $name == $slider->getAlias() ) {
				return false;
			}
		}

		return true;
	}

	public function check_revslider_file( $remote_path, $file_name ) {

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/slider_imports';

		$file_final_path = trailingslashit( $file_path ) . $file_name . '.zip';

		if ( ! file_exists( $file_final_path ) || 0 < filesize( $file_final_path ) ) {

			if ( ! is_dir( $file_path ) ) {
				wp_mkdir_p( $file_path );
			}

			// Get remote file
			$response = wp_remote_get( $remote_path );

			// Check for error
			if ( is_wp_error( $response ) ) {
				$this->error = 'Revolution slider could not be imported. Import manually from WP admin - Revolution Slider';
				$this->error .= '<br><small>Details: ' . $response->get_error_code() . '</small>';

				return false;
			}

			// Parse remote HTML file
			$file_contents = wp_remote_retrieve_body( $response );

			// Check for error
			if ( is_wp_error( $file_contents ) ) {
				$this->error = 'Revolution slider could not be imported. Import manually from WP admin - Revolution Slider';

				return false;
			}

			if ( ! svq_fs_put_contents( $file_final_path, $file_contents ) ) {
				$this->error = 'Revolution slider could not be written to disk. Check file permissions with hosting provider. Import manually from WP admin - Revolution Slider';

				return false;
			}
		}

		return true;
	}

	/** ---------------------------------------------------------------------------
	 * Import | RevSlider
	 *
	 * @param string $path
	 * @param string $name
	 * ---------------------------------------------------------------------------- */
	public function import_revslider( $name = '' ) {
		if ( class_exists( '\RevSlider' ) ) {
			$upload_dir = wp_upload_dir();
			$path       = $upload_dir['basedir'] . '/slider_imports';

			ob_start();
			//filename provided without extension
			$full_path = trailingslashit( $path ) . $name . '.zip';

			if ( $this->check_existing_slider( $name ) && file_exists( $full_path ) ) {
				$slider = new \RevSlider();
				$slider->importSliderFromPost( true, true, $full_path );
			}
			$this->messages[] = ob_get_clean();
		}
	}

	public function import_bp_fields( $bp_fields, $extra_replace = true ) {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'xprofile' ) ) {
			return;
		}

		$imported_ids = [];
		$existing_ids = [];
		$i            = 0;

		foreach ( $bp_fields as $field ) {
			$i ++;
			if ( ! $existing_ids[] = xprofile_get_field_id_from_name( $field['name'] ) ) {
				$id             = xprofile_insert_field(
					[
						'field_group_id' => 1,
						'name'           => $field['name'],
						'can_delete'     => $field['can_delete'],
						'field_order'    => $i,
						'is_required'    => $field['is_required'],
						'type'           => $field['type'],
					]
				);
				$imported_ids[] = $id;

				if ( $id && isset( $field['options'] ) && ! empty( $field['options'] ) ) {
					$j = 0;
					foreach ( $field['options'] as $option ) {
						$j ++;
						xprofile_insert_field( [
							'field_group_id' => 1,
							'parent_id'      => $id,
							'type'           => $field['type'],
							'name'           => $option,
							'option_order'   => $j,
						] );
					}
				}
			}
		}

		if ( $extra_replace ) {
			$ids = $existing_ids + $imported_ids;
			$this->replace_sks_data( $ids );
			//$this->replace_bps_data( $ids, 'Main page form' );
		}
	}

	/**
	 * @param string $file
	 *
	 * @return bool
	 */
	public function import_stax_zones( $file = '' ) {
		if ( ! defined( 'STAX_VERSION' ) ) {
			$this->error = 'Stax Header Builder plugin needs to be active';

			return false;
		}

		// Get remote file
		$response = wp_remote_get( $file );

		// Check for error
		if ( is_wp_error( $response ) ) {
			$this->error = 'Stax Import file could not be downloaded. Please manually import the templates.';
			$this->error .= '<br><small>Details: ' . $response->get_error_code() . '</small>';

			return false;
		}

		// Parse remote HTML file
		$file_contents = wp_remote_retrieve_body( $response );

		// Check for error
		if ( is_wp_error( $file_contents ) ) {
			$this->error = 'Stax Import file could not be downloaded. Please manually import the templates.';

			return false;
		}

		$processed_data = \Stax\Import::instance()->process_content( $file_contents );

		if ( $processed_data && ! empty( $processed_data ) ) {
			foreach ( $processed_data as $data ) {

				if ( ! isset( $data['fonts'] ) ) {
					$data['fonts'] = [];
				}

				\Stax\Model_Zones::instance()->make( $data );
			}
		}

		return true;
	}

	public function replace_sks_data( $ids ) {
		if ( ! empty( $ids ) ) {
			$q_args = [
				'post_type'      => 'seeko_form',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'meta_key'       => 'sq_import',
				'meta_value'     => '1',
			];

			$the_query = new \WP_Query( $q_args );

			// The Loop
			if ( $the_query->have_posts() ) {
				while ( $the_query->have_posts() ) {
					$the_query->the_post();

					$post_meta = get_post_meta( get_the_ID(), '_seeko_options', true );
					$meta      = apply_filters( 'typer_search_get_form_meta', $post_meta );

					if ( isset( $meta['context'] ) && isset( $meta['context']['members'] ) && isset( $meta['context']['members']['fields'] ) ) {
						foreach ( $meta['context']['members']['fields'] as $k => $item ) {
							if ( isset( $ids[ $item['id'] ] ) ) {
								$meta['context']['members']['fields'][ $k ]['id'] = $ids[ $item['id'] ];
							}
						}
					}

					update_post_meta( get_the_ID(), '_seeko_options', $meta );
					delete_post_meta( get_the_ID(), 'sq_import' );
					update_post_meta( get_the_ID(), '_sq_imported', '1' );
				}

			}
			/* Restore original Post Data */
			wp_reset_postdata();
		}
	}

	public function replace_bps_data( $ids, $page_title ) {
		if ( ! empty( $ids ) ) {
			$field_code = [];
			foreach ( $ids as $id ) {
				$field_code[] = 'field_' . $id;
			}

			//Main page form
			//bps_form
			$args  = [
				'post_type'  => 'bps_form',
				'title'      => $page_title,
				'meta_key'   => 'sq_import',
				'meta_value' => '1',
			];
			$query = new \WP_Query( $args );
			$posts = $query->posts;

			if ( ! empty( $posts ) && is_array( $posts ) ) {
				foreach ( $posts as $post ) {
					$form_values = get_post_meta( $post->ID, 'bps_options' );
					foreach ( $form_values as $form_value ) {
						if ( isset( $form_value['field_name'] ) ) {
							$new_option_value               = $form_value;
							$new_option_value['field_name'] = $ids;
							$new_option_value['field_code'] = $field_code;

							delete_post_meta( $post->ID, 'bps_options' );
							update_post_meta( $post->ID, 'bps_options', $new_option_value );

							update_post_meta( $post->ID, '_sq_imported', '1' );

							break;
						}
					}
				}
			}

			/* Restore original Post Data */
			wp_reset_postdata();
		}
	}

	private function get_imported_posts() {
		$args  = [
			'post_type'      => [ 'post', 'page', 'elementor_library', 'jet-popup' ],
			'posts_per_page' => - 1,
			'meta_query'     => [
				[
					'key'     => 'sq_import',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_sq_imported',
					'compare' => 'NOT EXISTS',
				],
			],
		];
		$query = new \WP_Query( $args );

		/* Restore original Post Data */
		wp_reset_postdata();

		return $query->get_posts();
	}

	private function post_process_posts() {
		$upload_dir = wp_upload_dir();
		if ( is_ssl() && strpos( $upload_dir['baseurl'], 'https://' ) === false ) {
			$upload_dir['baseurl'] = str_ireplace( 'http', 'https', $upload_dir['baseurl'] );
		}
		$this->local_url_base = trailingslashit( $upload_dir['baseurl'] );

		//calculate total images & pre process data
		if ( $this->should_process_step( 'calc_images' ) ) {
			$posts = $this->get_imported_posts();
			foreach ( $posts as $post ) {

				//save the imported page
				if ( 'page' === get_post_type( $post->ID ) ) {
					$this->pages_imported[ $post->ID ] = $post->ID;
				}

				$import_base = '';
				/* set import domain */
				if ( get_post_meta( $post->ID, 'sq_base', true ) ) {
					$import_base = get_post_meta( $post->ID, 'sq_base', true );
				}

				//set import remote base
				if ( $import_base ) {
					$this->remote_url_base = trailingslashit( $import_base );
				}

				do_action( 'svq/import/before_process', $post, $this );

				/* Fetch images for import */
				$this->get_images_from_post( $post );

				/* Try to convert VC Grid ids */
				$this->process_vc_grids( $post );

				/* Set GeoDirectory homepage to imported page */
				$this->set_geodir_home( $post );

				if ( $featured_image = get_post_meta( $post->ID, '_thumbnail_id', true ) ) {
					$this->featured_images[ $post->ID ] = $featured_image;
				}

				do_action( 'svq/import/after_process', $post, $this );

				//add import meta
				add_post_meta( $post->ID, '_sq_imported', 1 );
			}

			$data = [
				'text'              => esc_html__( 'Reading images for the import', 'typer-core' ),
				'step'              => 'calc_images',
				'remote_url_base'   => $this->remote_url_base,
				'total_images'      => $this->total_images,
				'elementor_images'  => $this->elementor_images,
				'url_remap'         => $this->url_remap,
				'featured_images'   => $this->featured_images,
				'attached_images'   => $this->attached_images,
				'content_images'    => $this->content_images,
				'slide_meta_images' => $this->slide_meta_images,
			];
			$this->set_progress( $this->progress_pid, $data, true );
		}

		/* Import images from content */
		$this->process_post_images();

		//set featured images
		$this->remap_featured_images();

		//replace any found images
		$this->replace_attachment_urls();

		//delete meta from imported content
		$this->delete_import_data();
	}

	// return the difference in length between two strings

	public function get_images_from_post( $post ) {
		/* get attached images */
		if ( $attached_images = get_post_meta( $post->ID, 'sq_attach', true ) ) {
			if ( ! empty( $attached_images ) ) {
				$this->attached_images[ $post->ID ] = $attached_images;
				foreach ( $attached_images as $attached_image ) {
					$this->total_images[ md5( $attached_image ) ] = $attached_image;
				}
			}
		}

		$img_data = get_post_meta( $post->ID, 'sq_img_data', true );

		/* Get images from VC single image and VC gallery */
		if ( ! empty( $img_data ) && preg_match_all( '/(images="[0-9,]+")|(include="[0-9,]+")|(image="[0-9]+")/i', $post->post_content, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				//get image links by ids
				$img_id = str_replace( [ 'image="', 'include="', 'images="', '"' ], '', $match );

				if ( isset( $img_data[ $img_id ] ) ) {
					$img_url = $img_data[ $img_id ];


					$img_id_array  = explode( ',', $img_id );
					$img_url_array = explode( ',', $img_url );

					$this->content_images[] = [
						'post_id'   => $post->ID,
						'id_array'  => $img_id_array,
						'url_array' => $img_url_array,
						'match'     => $match,
						'new_match' => str_replace( $img_id, $img_url, $match ),
					];
					foreach ( $img_url_array as $img_url ) {
						$this->total_images[ md5( $img_url ) ] = $img_url;
					}
				}
			}
		}

		/* Get images from media slider */
		if ( ( $meta = get_post_meta( $post->ID, '_kleo_slider', true ) ) && ! empty( $meta ) ) {
			$this->slide_meta_images[ $post->ID ] = $meta;
			foreach ( $meta as $m ) {
				$this->total_images[ md5( $m ) ] = $m;
			}
		}

		/* get Elementor images */
		if ( ( $meta = get_post_meta( $post->ID, '_elementor_data', true ) ) && $meta && ! empty( $meta ) ) {
			preg_match_all( '/https[^"\']*?(jpg|png|gif|jpeg)/i', $meta, $matches );

			if ( isset( $matches[0] ) && ! empty( $matches[0] ) ) {

				$this->elementor_images[ $post->ID ] = $matches[0];
				foreach ( wp_unslash( $matches[0] ) as $m ) {
					$this->total_images[ md5( $m ) ] = $m;
				}
			}
		}

		return false;
	}

	public function process_vc_grids( $post ) {
		$grid_data = get_post_meta( $post->ID, 'sq_vc_grids', true );

		/* Get images from VC single image and VC gallery */
		if ( ! empty( $grid_data ) && preg_match_all( '/item="[0-9]+"/i', $post->post_content, $matches ) ) {

			foreach ( $matches[0] as $match ) {
				//get image links by ids
				$grid_id = str_replace( [ 'item="', '"' ], '', $match );
				if ( isset( $grid_data[ $grid_id ] ) ) {
					$grid_name = $grid_data[ $grid_id ];

					if ( $query = $this->get_post_by_slug( $grid_name ) ) {
						$current_grid              = get_post( $query );
						$this->url_remap[ $match ] = 'item="' . $current_grid->ID . '"';
					}
				}
			}
		}
	}

	public function get_post_by_slug( $slug ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s", $slug ) );
	}

	public function set_geodir_home( $post ) {
		if ( ( $meta = get_post_meta( $post->ID, '_kleo_header_content', true ) ) && strpos( $meta, '[gd_homepage_' ) !== false ) {
			update_option( 'geodir_home_page', $post->ID );
		}
	}

	public function process_post_images() {
		$old_base_no_http = str_replace( [ 'http://', 'https://' ], '', $this->remote_url_base );

		//attached images
		if ( ! empty( $this->attached_images ) ) {
			foreach ( $this->attached_images as $post_id => $attached_images ) {
				if ( ! empty( $attached_images ) ) {

					foreach ( $attached_images as $k => $v ) {
						$this->remote_images[ $k ] = $v;
						$this->import_image( $v, $post_id );
					}
				}
			}
		}

		//content images
		if ( ! empty( $this->content_images ) ) {
			foreach ( $this->content_images as $content_image ) {
				$post_id       = $content_image['post_id'];
				$img_id_array  = $content_image['id_array'];
				$img_url_array = $content_image['url_array'];
				$match         = $content_image['match'];
				$new_match     = $content_image['new_match'];

				$count = 0;
				foreach ( $img_url_array as $remote_url ) {

					$this->remote_images[ $img_id_array[ $count ] ] = $remote_url;

					$new_image = $this->import_image( $remote_url, $post_id );
					if ( ! empty( $new_image ) && isset( $new_image['id'] ) ) {
						$new_match = str_replace( $remote_url, $new_image['id'], $new_match );
					}

					$count ++;
				}
				$this->url_remap[ $match ] = $new_match;

			}
			//failsafe domain replace
			$this->url_remap[ 'http://' . $old_base_no_http ]  = $this->local_url_base;
			$this->url_remap[ 'https://' . $old_base_no_http ] = $this->local_url_base;
		}

		//Media slider images
		if ( ! empty( $this->slide_meta_images ) ) {
			foreach ( $this->slide_meta_images as $post_id => $slide_meta_image ) {
				$updated = false;
				foreach ( $slide_meta_image as $key => $slide ) {
					$image = $this->import_image( $slide, $post_id );
					if ( ! empty( $image ) && isset( $image['id'] ) ) {
						$slide_meta_image[ $key ] = $image['url'];
						$updated                  = true;
					}
				}
				if ( $updated ) {
					update_post_meta( $post_id, '_kleo_slider', $slide_meta_image );
				}
			}
		}

		//Update Elementor img
		if ( ! empty( $this->elementor_images ) ) {
			foreach ( $this->elementor_images as $post_id => $el_images ) {
				$updated    = false;
				$new_images = [];

				foreach ( $el_images as $key => $el_img ) {

					$image = $this->import_image( wp_unslash( $el_img ), $post_id );

					if ( ! empty( $image ) && isset( $image['id'] ) ) {
						$new_images[ $key ] = wp_slash( $image['url'] );
						$updated            = true;
					}
				}

				if ( $updated ) {

					$meta = get_post_meta( $post_id, '_elementor_data', true );
					$meta = str_replace( $el_images, $new_images, $meta );

					$sq_attach = $this->attached_images[ $post_id ];
					if ( ! empty( $sq_attach ) ) {

						$new_ids = [];
						preg_match_all( '/"id":([0-9]+)}/', $meta, $matches );
						foreach ( $matches[1] as $k => $match ) {

							if ( isset( $sq_attach[ $match ] ) ) {

								//get local imported id
								$imported_url = $sq_attach[ $match ];
								if ( in_array( $imported_url, $this->images_imported ) ) {
									$new_img_id = array_search( $imported_url, $this->images_imported );
									if ( $new_img_id ) {
										$new_ids[ $k ] = '"id":' . $new_img_id . '}';
									} else {
										unset( $matches[0][ $k ] );
									}
								} else {
									unset( $matches[0][ $k ] );
								}
							} else {
								unset( $matches[0][ $k ] );
							}

						}
						if ( ! empty( $new_ids ) ) {
							$meta = str_replace( $matches[0], $new_ids, $meta );
						}

						//img type 2
						$new_ids = [];
						preg_match_all( '/"image":{"id":([0-9]+),/', $meta, $matches );
						foreach ( $matches[1] as $k => $match ) {

							if ( isset( $sq_attach[ $match ] ) ) {

								//get local imported id
								$imported_url = $sq_attach[ $match ];
								if ( in_array( $imported_url, $this->images_imported ) ) {
									$new_img_id = array_search( $imported_url, $this->images_imported );
									if ( $new_img_id ) {
										$new_ids[ $k ] = '"image":{"id":' . $new_img_id . ',';
									} else {
										unset( $matches[0][ $k ] );
									}

								} else {
									unset( $matches[0][ $k ] );
								}
							} else {
								unset( $matches[0][ $k ] );
							}

						}
						if ( ! empty( $new_ids ) ) {
							$meta = str_replace( $matches[0], $new_ids, $meta );
						}

					}

					//update links
					$meta = str_replace( wp_slash( $this->remote_url_base ), wp_slash( $this->local_url_base ), $meta );

					update_post_meta( $post_id, '_elementor_data', wp_slash( $meta ) );
				}
			}
		}

		return false;
	}

	/**
	 * Import remote image
	 *
	 * @param string $link
	 * @param integer $post_id
	 *
	 * @return bool|array;
	 */
	private function import_image( $link = '', $post_id = null, $add_count = false ) {
		if ( in_array( $link, $this->failed_images ) ) {
			return false;
		}

		$total_images   = count( $this->total_images );
		$imported_image = [];
		if ( ! $post_id || '' == $link ) {
			return $imported_image;
		}
		$local_url = $this->remote_to_local_url( $link, $post_id );

		//$this->messages[] = 'Importing image: ' . $link;

		if ( null === $this->image_history ) {
			$this->image_history = get_option( 'sq_image_history', [] );
		}

		/* Look in imported images history */
		if ( ! empty( $this->image_history ) ) {
			foreach ( $this->image_history as $item ) {
				if ( $link == $item['remote'] ) {
					$local_url = $item['local'];
				}
			}
		}

		if ( $img_id = attachment_url_to_postid( $local_url ) ) {
			$imported_image['id']             = $img_id;
			$imported_image['url']            = $local_url;
			$this->images_imported[ $img_id ] = $link;

			//$this->messages[] = 'Image already uploaded.';

			return $imported_image;
		}

		//if image is not found locally, continue the quest
		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$new_image = media_sideload_image( $link, $post_id, null, 'src' );

		if ( ! is_wp_error( $new_image ) ) {
			$this->messages[] = 'Image just uploaded: ' . $link;

			$img_id                = attachment_url_to_postid( $new_image );
			$imported_image['id']  = $img_id;
			$imported_image['url'] = $new_image;

			$this->images_imported[ $img_id ]    = $link;
			$this->image_history[ md5( $link ) ] = [
				'remote' => $link,
				'local'  => $new_image,
			];

			if ( ! empty( $this->image_history ) ) {
				update_option( 'sq_image_history', $this->image_history );
			}

			if ( $add_count ) {
				$this->total_images[ md5( $link ) ] = $link;
			}
		} else {
			$this->failed_images[] = $link;
			$this->messages[]      = 'Failed to upload: ' . $link . ' Err: ' . $new_image->get_error_message();
		}

		if ( $total_images > 0 ) {
			$text             = 'Importing Images ' . count( $this->images_imported ) . '/' . $total_images;
			$this->messages[] = $text;

			$this->set_progress( $this->progress_pid, [
				//'text' => implode( ', ', $this->messages ),
				'text'            => $text,
				'images_imported' => $this->images_imported,
				'step'            => 'images',
				'failed_images'   => $this->failed_images
			], true );
		}

		return $imported_image;
	}

	public function remote_to_local_url( $url, $post_id ) {
		$remote_base_no_protocol = str_replace( [ 'http://', 'https://' ], '', $this->remote_url_base );
		$url_no_protocol         = str_replace( [ 'http://', 'https://' ], '', $this->local_url_base );

		if ( false !== strpos( $url_no_protocol, $remote_base_no_protocol ) ) {
			$local_url = str_replace( array(
				'https://' . $remote_base_no_protocol,
				'http://' . $remote_base_no_protocol
			), array( $this->local_url_base, $this->local_url_base ), $url );
		} else {
			$time = current_time( 'mysql' );
			if ( $post = get_post( $post_id ) ) {
				if ( substr( $post->post_date, 0, 4 ) > 0 ) {
					$time = $post->post_date;
				}
			}
			$uploads   = wp_upload_dir( $time );
			$name      = basename( $url );
			$filename  = wp_unique_filename( $uploads['path'], $name );
			$local_url = $uploads['path'] . "/$filename";
		}

		return $local_url;
	}

	public function remap_featured_images() {
		if ( ! empty( $this->featured_images ) ) {
			foreach ( $this->featured_images as $post_id => $image_id ) {
				if ( isset( $this->remote_images[ $image_id ] ) ) {

					$remote_url = $this->remote_images[ $image_id ];
					$new_image  = $this->import_image( $remote_url, $post_id );
					if ( ! empty( $new_image ) && isset( $new_image['id'] ) ) {
						update_post_meta( $post_id, '_thumbnail_id', $new_image['id'] );
					}
				}
			}
		}
	}

	public function replace_attachment_urls() {
		global $wpdb;

		if ( empty( $this->url_remap ) ) {
			return;
		}

		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, [ $this, 'cmpr_strlen' ] );

		foreach ( $this->url_remap as $from_url => $to_url ) {
			// remap urls in post_content
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)", $from_url, $to_url ) );
			// remap enclosure urls
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key='enclosure'", $from_url, $to_url ) );
		}
	}

	/**
	 * Delete post meta required by import logic
	 */
	public function delete_import_data() {
		delete_post_meta_by_key( 'sq_img_data' );
		delete_post_meta_by_key( 'sq_attach' );
		delete_post_meta_by_key( 'sq_vc_grids' );
		delete_post_meta_by_key( 'sq_domain' );
		delete_post_meta_by_key( 'sq_base' );
		delete_post_meta_by_key( 'sq_import' );
	}

	public function cmpr_strlen( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}

	/**
	 * Import | Menu - Locations
	 *
	 * @param array $locations Menu locations and names
	 */
	public function import_menu_location( $locations = [] ) {
		if ( empty( $locations ) ) {
			return;
		}
		$menus = wp_get_nav_menus();

		$current_menus = get_theme_mod( 'nav_menu_locations' );

		foreach ( $locations as $key => $val ) {
			foreach ( $menus as $menu ) {
				if ( $menu->slug == $val ) {
					$current_menus[ $key ] = absint( $menu->term_id );
				}
			}
		}
		set_theme_mod( 'nav_menu_locations', $current_menus );
	}

	/**
	 * @param $post \WP_Post
	 * @param $instance Importer
	 */
	public function jet_popup_post_process( $post ) {
		if ( ! function_exists( 'jet_popup' ) ) {
			return;
		}
		if ( $post->post_type === 'jet-popup' ) {
			$popup_page_settings = get_post_meta( $post->ID, '_elementor_page_settings', true );
			$type                = get_post_meta( $post->ID, '_elementor_template_type', true );

			if ( isset( $popup_page_settings['jet_popup_conditions'] ) ) {
				$conditions_key = 'jet_popup_conditions';
				$saved          = get_option( $conditions_key, [] );

				if ( ! isset( $saved[ $type ] ) ) {
					$saved[ $type ] = [];
				}

				$saved[ $type ][ $post->ID ] = $popup_page_settings['jet_popup_conditions'];

				update_option( $conditions_key, $saved, true );
			}
		}
	}

	/** ---------------------------------------------------------------------------
	 * Import
	 * ---------------------------------------------------------------------------- */
	public function import() {
		$this->show_message();

		?>

        <div id="typer-wrapper" class="typer-import wrap">
            <h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
            <form class="typer-import-form" action="" method="post"
                  onSubmit="if(!confirm('Really import the data?')){return false;}">
                <input type="hidden" name="typer_import_nonce"
                       value="<?php echo wp_create_nonce( 'import_nonce' ); ?>"/>
                <h3>Import Demo pack</h3>
				<?php $this->generate_boxes_html(); ?>
                <div class="clear clearfix"></div>
            </form>
        </div>

		<?php
	}

	public function show_message() {
		// message box
		if ( $this->error ) {
			echo '<div class="error settings-error">';
			echo '<p><strong>' . $this->error . '</strong></p>';
			echo '</div>';
		} elseif ( $this->data_imported ) {
			echo '<div class="updated settings-error">';
			echo '<p><strong>' . __( 'Import successful. Have fun!', 'typer-core' ) . '</strong></p>';
			echo '</div>';
		}
	}

	public function generate_boxes_html() {
		?>
        <div class="sqp-flex sqp-flex-wrap sqp--mx-3 sqp-overflow-hidden">

			<?php
			$demo_sets = self::get_demo_sets();

			?>

			<?php foreach ( $demo_sets as $k => $v ) : ?>

                <div class="sqp-py-3 sqp-w-full md:sqp-w-1/2 lg:sqp-w-1/3 xl:sqp-w-1/3 sqp-overflow-hidden">
                    <div class="sqp-bg-white sqp-border sqp-border-solid sqp-border-gray-300 sqp-text-center sqp-mx-3 sqp-h-full sqp-relative">
                        <div class="sqp-w-full">
                            <img src="<?php echo esc_attr( $v['image'] ); ?>" class="sqp-w-full"
                                 alt="<?php echo esc_html( $v['name'] ); ?>">
                        </div>
                        <div class="demo-options">
                            <div class="to-left">
                                <span class="sqp-block sqp-text-xl sqp-font-bold sqp-mt-3 sqp-mb-4"><?php echo esc_html( $v['name'] ); ?></span>
                                <div class="demo-checkboxes">

									<?php if ( isset( $v['content'] ) && ! empty( $v['content'] ) ) : ?>
										<?php foreach ( $v['content'] as $content ) : ?>
											<?php

											if ( isset( $content['checked'] ) && $content['checked'] ) {
												$checked = ' checked="checked"';
											} else {
												$checked = '';
											}
											?>
                                            <label>
                                                <input type="checkbox"
                                                       name="import_content[]"
                                                       value="<?php echo esc_attr( $content['id'] ); ?>"
                                                       class="check-page"<?php echo strip_tags( $checked ); ?>
                                                > <?php echo esc_html( $content['name'] ); ?>
                                            </label>
                                            <br>
										<?php endforeach; ?>
									<?php endif; ?>

									<?php if ( isset( $v['widgets'] ) ) : ?>
                                        <label><input type="checkbox" name="import_widgets[]" checked
                                                      value="<?php echo esc_attr( $k ); ?>"> <?php esc_html_e( 'Import Widgets', 'typer-core' ); ?>
                                        </label>
                                        <br>
									<?php endif; ?>

									<?php if ( isset( $v['stax'] ) ) : ?>
                                        <label><input type="checkbox" name="import_stax[]" checked
                                                      value="<?php echo esc_attr( $k ); ?>"> <?php esc_html_e( 'Import Stax Templates', 'typer-core' ); ?>
                                        </label>
                                        <br>
									<?php endif; ?>

									<?php if ( isset( $v['revslider'] ) ) : ?>
                                        <label><input type="checkbox" name="import_revslider[]" checked
                                                      value="<?php echo esc_attr( $k ); ?>"> <?php esc_html_e( 'Import Revolution Slider', 'typer-core' ); ?>
                                        </label>
                                        <br>
									<?php endif; ?>

									<?php if ( isset( $v['bp_fields'] ) ) : ?>
                                        <label><input type="checkbox" name="import_bp_fields[]" checked
                                                      value="<?php echo esc_attr( $k ); ?>"> <?php esc_html_e( 'Import Dummy Profile fields', 'typer-core' ); ?>
                                        </label>
                                        <br>
									<?php endif; ?>

									<?php if ( isset( $v['options'] ) ) : ?>
                                        <label><input type="checkbox" name="import_options[]" checked
                                                      value="<?php echo esc_attr( $v['options'] ); ?>"> <?php esc_html_e( 'Import Theme options', 'typer-core' ); ?>
                                        </label>
										<?php
										$extra_options_data = esc_html__( 'This will change some of your theme options. Make sure to export them first.', 'typer-core' );
										echo ' <span class="dashicons dashicons-editor-help tooltip-me" title="' . $extra_options_data . '"></span>';
										?>
                                        <br>
									<?php endif; ?>

									<?php if ( isset( $v['plugins'] ) && ! empty( $v['plugins'] ) ) : ?>
                                        <label>
                                            <input type="checkbox" name="activate_plugins[]" checked
                                                   value="<?php echo esc_attr( $k ); ?>">
											<?php echo esc_html__( 'Activate required plugins', 'typer-core' ); ?>
                                        </label>
										<?php
										$extra_plugin_data = [];
										foreach ( $v['plugins'] as $plugin ) {
											$extra_plugin_data[] = $plugin['name'];
										}
										echo ' <span class="dashicons dashicons-editor-help tooltip-me" title="' . join( ', ', $extra_plugin_data ) . '"></span>';
										?>
                                        <br>
									<?php endif; ?>

									<?php
									$extra_data = isset( $v['details'] ) ? $v['details'] : '';
									if ( '' !== $extra_data ) : ?>
                                        <span
                                                class="demo-detail">Extra notes: <?php echo wp_kses_post( $extra_data ); ?></span>
									<?php endif; ?>
                                    <br>
                                    <small>It is recommended to leave all options checked to reproduce our demo.</small>
                                    <br>
                                </div>
                            </div>
                            <div class="sqp-w-full sqp-flex sqp-items-center sqp-justify-center sqp-mb-3">
                                <button type="submit" name="import_demo" value="<?php echo esc_attr( $k ); ?>"
                                        class="sqp_btn_intent sqp_btn_md sqp-font-normal import-demo-btn">
		                            <?php esc_html_e( 'Import', 'typer-core' ) ?>
                                </button>
                                <a class="sqp_btn_link sqp_btn_md sqp-font-normal sqp-flex sqp-items-center sqp-content-center sqp-ml-3" href="<?php echo esc_url( $v['preview_link'] ); ?>" target="_blank">
		                            <?php esc_html_e( 'Preview', 'typer-core' ); ?>
                                </a>
                            </div>
                            <div class="clear clearfix"></div>
                        </div>
                    </div>
                </div>

			<?php endforeach; ?>

        </div>
		<?php
	}


	public function tpl_main_import_page_content() {
		?>
        <div class="sqp-w-full">
			<?php $this->show_message(); ?>

            <h3 class="sqp-text-2xl sqp-font-medium"><?php esc_html_e( 'Importing demo content is easy.', 'typer-core' ); ?></h3>
            <p><?php esc_html_e( 'Our special Importer makes it simple to add ready to use pages to your site.', 'typer-core' ); ?></p>

            <form class="typer-import-form" action="" method="post"
                  onSubmit="if(!confirm('<?php esc_attr_e( 'Really import the data?', 'typer-core' ); ?>')){return false;}">

                <input type="hidden" name="typer_import_nonce"
                       value="<?php echo wp_create_nonce( 'import_nonce' ); ?>"/>
				<?php $this->generate_boxes_html(); ?>
            </form>
        </div>
		<?php
	}

}
