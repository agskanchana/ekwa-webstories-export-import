<?php
/**
 * Main plugin controller: admin menu, request handling, asset enqueue.
 *
 * @package Ekwa\WebStoriesExportImport
 */

namespace Ekwa\WSEI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton controller.
 */
class Plugin {

	const MENU_SLUG     = 'ekwa-wsei';
	const NONCE_EXPORT  = 'ekwa_wsei_export';
	const NONCE_IMPORT  = 'ekwa_wsei_import';
	const NONCE_BATCH   = 'ekwa_wsei_batch';
	const RESULT_PREFIX = 'ekwa_wsei_result_';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_ekwa_wsei_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ekwa_wsei_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_ekwa_wsei_download', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_ekwa_wsei_export_batch', array( $this, 'ajax_export_batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . EKWA_WSEI_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add a quick link to the plugins list row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url           = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$links['tool'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export / Import', 'ekwa-wsei' ) . '</a>';
		return $links;
	}

	/**
	 * Register the admin menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Web Stories Export / Import', 'ekwa-wsei' ),
			__( 'Stories Export/Import', 'ekwa-wsei' ),
			Helpers::capability(),
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-images-alt2',
			59
		);
	}

	/**
	 * Enqueue admin CSS/JS on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'ekwa-wsei-admin', EKWA_WSEI_URL . 'assets/css/admin.css', array(), EKWA_WSEI_VERSION );
		wp_enqueue_script( 'ekwa-wsei-admin', EKWA_WSEI_URL . 'assets/js/admin.js', array(), EKWA_WSEI_VERSION, true );
		wp_localize_script(
			'ekwa-wsei-admin',
			'ekwaWsei',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'downloadUrl'  => admin_url( 'admin-post.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_BATCH ),
				'i18n'         => array(
					'building'   => __( 'Building batch %1$d of %2$d…', 'ekwa-wsei' ),
					'done'       => __( 'All %d batches built. Click each link below to download (they expire in ~2 hours).', 'ekwa-wsei' ),
					'failed'     => __( 'Batch %1$d failed: %2$s', 'ekwa-wsei' ),
					'batchLabel' => __( 'Batch %1$d — %2$d stories, %3$d assets', 'ekwa-wsei' ),
					'download'   => __( 'Download', 'ekwa-wsei' ),
					'noneChosen' => __( 'Please select at least one story to export.', 'ekwa-wsei' ),
					'downloadAll' => __( 'Download all', 'ekwa-wsei' ),
				),
			)
		);
	}

	/**
	 * Render the tool page.
	 */
	public function render_page() {
		if ( ! current_user_can( Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ekwa-wsei' ) );
		}

		$exporter = new Exporter();
		$stories  = Helpers::web_stories_active() ? $exporter->get_stories() : array();
		$result   = $this->pull_result();

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export';
		if ( ! in_array( $active_tab, array( 'export', 'import' ), true ) ) {
			$active_tab = 'export';
		}

		require EKWA_WSEI_DIR . 'admin/views/admin-page.php';
	}

	/**
	 * Handle the export form submission (streams a ZIP download).
	 */
	public function handle_export() {
		if ( ! current_user_can( Helpers::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ekwa-wsei' ) );
		}
		check_admin_referer( self::NONCE_EXPORT );

		$story_ids = array();
		if ( isset( $_POST['story_ids'] ) && is_array( $_POST['story_ids'] ) ) {
			$story_ids = array_map( 'intval', wp_unslash( $_POST['story_ids'] ) );
		}

		// "Export all" convenience.
		if ( empty( $story_ids ) && ! empty( $_POST['export_all'] ) ) {
			foreach ( ( new Exporter() )->get_stories() as $s ) {
				$story_ids[] = (int) $s['id'];
			}
		}

		if ( empty( $story_ids ) ) {
			$this->redirect_with_result(
				'export',
				array(
					'type'    => 'error',
					'message' => __( 'Please select at least one story to export.', 'ekwa-wsei' ),
				)
			);
		}

		$exporter = new Exporter();
		$result   = $exporter->export_and_stream( $story_ids );

		// Only reached if export failed (success exits inside the method).
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result(
				'export',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
			);
		}
		exit;
	}

	/**
	 * AJAX: build ONE batch ZIP for the given story ids and return a download link.
	 *
	 * Keeps each request small so large libraries never time out / 500.
	 */
	public function ajax_export_batch() {
		if ( ! current_user_can( Helpers::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa-wsei' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_BATCH, 'nonce' );

		$story_ids = array();
		if ( isset( $_POST['story_ids'] ) && is_array( $_POST['story_ids'] ) ) {
			$story_ids = array_map( 'intval', wp_unslash( $_POST['story_ids'] ) );
		}
		if ( empty( $story_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No stories in this batch.', 'ekwa-wsei' ) ), 400 );
		}

		// Opportunistically clean up old downloads.
		Exporter::cleanup_temp();

		$exporter = new Exporter();
		$result   = $exporter->build_zip( $story_ids );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$download_url = add_query_arg(
			array(
				'action'   => 'ekwa_wsei_download',
				'file'     => rawurlencode( $result['filename'] ),
				'_wpnonce' => wp_create_nonce( self::NONCE_BATCH ),
			),
			admin_url( 'admin-post.php' )
		);

		wp_send_json_success(
			array(
				'filename'    => $result['filename'],
				'downloadUrl' => $download_url,
				'stories'     => (int) $result['stories'],
				'assets'      => (int) $result['assets'],
			)
		);
	}

	/**
	 * Stream a previously-built batch ZIP, then delete it.
	 */
	public function handle_download() {
		if ( ! current_user_can( Helpers::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ekwa-wsei' ) );
		}
		check_admin_referer( self::NONCE_BATCH );

		$file     = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$exporter = new Exporter();
		$result   = $exporter->serve_temp_file( $file );

		// Only reached on failure (success streams + exits).
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result(
				'export',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
			);
		}
		exit;
	}

	/**
	 * Handle the import form submission.
	 */
	public function handle_import() {
		if ( ! current_user_can( Helpers::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ekwa-wsei' ) );
		}
		check_admin_referer( self::NONCE_IMPORT );

		$importer = new Importer();
		$server_path = isset( $_POST['server_path'] ) ? sanitize_text_field( wp_unslash( $_POST['server_path'] ) ) : '';

		if ( $server_path ) {
			$result = $importer->import_server_file( $server_path );
		} else {
			$files = $this->collect_uploaded_bundles();
			if ( empty( $files ) ) {
				$result = new \WP_Error( 'no_input', __( 'Please choose one or more bundle ZIPs to import, or provide a server path.', 'ekwa-wsei' ) );
			} else {
				$result = $importer->import_uploaded_files( $files );
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result(
				'import',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
			);
		}

		$this->redirect_with_result(
			'import',
			array(
				'type'    => 'success',
				'import'  => $result,
			)
		);
	}

	/**
	 * Normalize the uploaded bundle field (single or multiple) into a clean
	 * list of per-file arrays, skipping empty slots.
	 *
	 * @return array[] List of { name, type, tmp_name, error, size }.
	 */
	private function collect_uploaded_bundles() {
		if ( empty( $_FILES['ekwa_wsei_bundle'] ) || ! isset( $_FILES['ekwa_wsei_bundle']['name'] ) ) {
			return array();
		}

		$field = $_FILES['ekwa_wsei_bundle'];
		$out   = array();

		if ( is_array( $field['name'] ) ) {
			$count = count( $field['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( empty( $field['name'][ $i ] ) || UPLOAD_ERR_NO_FILE === (int) $field['error'][ $i ] ) {
					continue;
				}
				$out[] = array(
					'name'     => sanitize_file_name( $field['name'][ $i ] ),
					'type'     => $field['type'][ $i ],
					'tmp_name' => $field['tmp_name'][ $i ],
					'error'    => $field['error'][ $i ],
					'size'     => $field['size'][ $i ],
				);
			}
		} elseif ( ! empty( $field['name'] ) ) {
			$out[] = array(
				'name'     => sanitize_file_name( $field['name'] ),
				'type'     => $field['type'],
				'tmp_name' => $field['tmp_name'],
				'error'    => $field['error'],
				'size'     => $field['size'],
			);
		}

		return $out;
	}

	/**
	 * Store a result payload in a per-user transient and redirect back.
	 *
	 * @param string $tab     Tab to return to.
	 * @param array  $payload Result payload.
	 */
	private function redirect_with_result( $tab, array $payload ) {
		set_transient( self::RESULT_PREFIX . get_current_user_id(), $payload, 120 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'tab'     => $tab,
					'ekresult' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Retrieve and clear the stored result payload.
	 *
	 * @return array|null
	 */
	private function pull_result() {
		if ( empty( $_GET['ekresult'] ) ) {
			return null;
		}
		$key     = self::RESULT_PREFIX . get_current_user_id();
		$payload = get_transient( $key );
		delete_transient( $key );
		return is_array( $payload ) ? $payload : null;
	}
}
