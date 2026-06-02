<?php
/**
 * Exporter: gathers selected Web Stories + their assets into a ZIP bundle.
 *
 * @package Ekwa\WebStoriesExportImport
 */

namespace Ekwa\WSEI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a portable ZIP bundle (manifest.json + assets/) for a set of stories.
 */
class Exporter {

	/**
	 * Meta keys copied verbatim onto each exported story.
	 *
	 * @var string[]
	 */
	private $story_meta_keys = array(
		'web_stories_poster',
		'web_stories_publisher_logo',
		'_thumbnail_id',
	);

	/**
	 * Attachment meta keys worth carrying along with each asset.
	 *
	 * @var string[]
	 */
	private $asset_meta_keys = array(
		'_wp_attachment_image_alt',
		'web_stories_is_poster',
		'web_stories_poster_id',
		'web_stories_is_muted',
		'web_stories_optimized_id',
		'web_stories_base_color',
		'web_stories_blurhash',
		'web_stories_trim_data',
	);

	/**
	 * Temp files downloaded for offloaded media, deleted after the ZIP closes.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Get all Web Stories for the export listing UI.
	 *
	 * @return array[] Each: id, title, status, date, modified.
	 */
	public function get_stories() {
		$query = new \WP_Query(
			array(
				'post_type'      => EKWA_WSEI_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title ? $post->post_title : __( '(no title)', 'ekwa-wsei' ),
				'status'   => $post->post_status,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			);
		}
		return $out;
	}

	/**
	 * Export the given story IDs to a ZIP file and stream it to the browser.
	 *
	 * Sends headers and exits. On failure, returns a WP_Error (caller handles).
	 *
	 * @param int[] $story_ids Story post IDs to export.
	 * @return \WP_Error|void
	 */
	public function export_and_stream( array $story_ids ) {
		$result = $this->build_zip( $story_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->stream_download( $result['path'], $result['filename'], true );
	}

	/**
	 * Stream a file as a binary download, defeating zlib output compression and
	 * buffering so the ZIP arrives intact. Exits when done.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename Download filename.
	 * @param bool   $delete   Delete the file after streaming.
	 * @return void
	 */
	private function stream_download( $path, $filename, $delete = false ) {
		// Stop the server from gzip-compressing the binary ZIP, which would make
		// the declared Content-Length wrong and truncate/corrupt the download.
		@ini_set( 'zlib.output_compression', 'Off' );
		@ini_set( 'output_buffering', 'Off' );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		@set_time_limit( 0 );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$size           = (int) @filesize( $path );
		$compression_on = (bool) ini_get( 'zlib.output_compression' );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: none' );
		// Only advertise a length if the bytes will NOT be recompressed; with
		// compression still forced on, a fixed length would truncate the file.
		if ( ! $compression_on && $size > 0 ) {
			header( 'Content-Length: ' . $size );
		}

		$handle = fopen( $path, 'rb' );
		if ( false !== $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1024 * 1024 ); // 1 MB chunks.
				flush();
			}
			fclose( $handle );
		}

		if ( $delete ) {
			@unlink( $path );
		}
		exit;
	}

	/**
	 * Build the export ZIP on disk.
	 *
	 * @param int[] $story_ids Story post IDs.
	 * @return array|\WP_Error { path, filename } on success.
	 */
	public function build_zip( array $story_ids ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error(
				'no_zip',
				__( 'The PHP ZipArchive extension is required to create export bundles but is not available on this server.', 'ekwa-wsei' )
			);
		}

		$story_ids = array_values( array_unique( array_map( 'intval', $story_ids ) ) );
		if ( empty( $story_ids ) ) {
			return new \WP_Error( 'no_stories', __( 'No stories were selected for export.', 'ekwa-wsei' ) );
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$uploads = wp_get_upload_dir();
		$baseurl = $uploads['baseurl'];

		$manifest = array(
			'format'                 => EKWA_WSEI_FORMAT,
			'format_version'         => EKWA_WSEI_FORMAT_VERSION,
			'plugin_version'         => EKWA_WSEI_VERSION,
			'generated'              => gmdate( 'c' ),
			'source_site_url'        => home_url(),
			'source_uploads_baseurl' => $baseurl,
			'stories'                => array(),
			'assets'                 => array(),
		);

		// Collect every referenced attachment id across all selected stories.
		$asset_ids = array();

		foreach ( $story_ids as $story_id ) {
			$post = get_post( $story_id );
			if ( ! $post || EKWA_WSEI_POST_TYPE !== $post->post_type ) {
				continue;
			}

			$story_meta = array();
			foreach ( $this->story_meta_keys as $key ) {
				$value = get_post_meta( $post->ID, $key, true );
				if ( '' !== $value && null !== $value ) {
					$story_meta[ $key ] = $value;
				}
			}

			$manifest['stories'][] = array(
				'old_id'                => $post->ID,
				'title'                 => $post->post_title,
				'slug'                  => $post->post_name,
				'status'                => $post->post_status,
				'excerpt'               => $post->post_excerpt,
				'date'                  => $post->post_date,
				'date_gmt'              => $post->post_date_gmt,
				'menu_order'            => $post->menu_order,
				'post_content'          => $post->post_content,
				'post_content_filtered' => $post->post_content_filtered,
				'meta'                  => $story_meta,
			);

			foreach ( $this->collect_story_asset_ids( $post, $story_meta, $baseurl ) as $aid ) {
				$asset_ids[ $aid ] = true;
			}
		}

		if ( empty( $manifest['stories'] ) ) {
			return new \WP_Error( 'no_valid_stories', __( 'None of the selected items are valid Web Stories.', 'ekwa-wsei' ) );
		}

		// Prepare the ZIP.
		$tmp_dir = self::temp_dir();
		wp_mkdir_p( $tmp_dir );

		// Bail early if the disk is nearly full — otherwise ZipArchive would
		// write a truncated (corrupt) archive. Estimate need from the assets.
		$needed = 0;
		foreach ( array_keys( $asset_ids ) as $aid ) {
			$f = get_attached_file( (int) $aid );
			if ( $f && file_exists( $f ) ) {
				$needed += (int) filesize( $f );
			}
		}
		$free = @disk_free_space( $tmp_dir );
		if ( false !== $free && $needed > 0 && $free < ( $needed * 1.2 + 10 * MB_IN_BYTES ) ) {
			return new \WP_Error(
				'low_disk',
				sprintf(
					/* translators: 1: needed size, 2: free size */
					__( 'Not enough free disk space to build this batch (needs ~%1$s, only %2$s free). Free up space, or use a smaller batch size and download/move each ZIP before building more.', 'ekwa-wsei' ),
					size_format( $needed ),
					size_format( $free )
				)
			);
		}

		$zip_filename = 'web-stories-export-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip_path     = $tmp_dir . '/' . $zip_filename;

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'zip_open_failed', __( 'Could not create the export ZIP file.', 'ekwa-wsei' ) );
		}

		// Add each asset file and record its manifest entry. A single bad asset
		// must not abort the whole export.
		foreach ( array_keys( $asset_ids ) as $aid ) {
			try {
				$entry = $this->build_asset_entry( (int) $aid, $zip );
				if ( $entry ) {
					$manifest['assets'][] = $entry;
				}
			} catch ( \Throwable $e ) {
				error_log( '[ekwa-wsei] export skipped asset #' . (int) $aid . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
		}

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->addFromString(
			'README.txt',
			"This bundle was created by the Ekwa Web Stories Export & Import plugin.\n" .
			"Import it from another site via: Web Stories Export/Import > Import.\n" .
			'Stories: ' . count( $manifest['stories'] ) . ', Assets: ' . count( $manifest['assets'] ) . "\n"
		);

		// IMPORTANT: ZipArchive writes deferred file data here. A failure (e.g.
		// disk full, locked source file) means a truncated/corrupt ZIP, so we
		// must check the result instead of reporting a false success.
		$close_ok = $zip->close();

		// The deferred file data has now been written, so any temp downloads
		// can go.
		$this->cleanup_temp_files();

		if ( ! $close_ok ) {
			@unlink( $zip_path );
			return new \WP_Error(
				'zip_close_failed',
				__( 'The export ZIP could not be finalised (the disk may be full or a media file was unreadable). Try a smaller batch size.', 'ekwa-wsei' )
			);
		}

		// Verify the finished archive really is a valid, complete ZIP. NOTE: in
		// PHP 8+, calling close() on a ZipArchive whose open() failed throws a
		// ValueError (and "@" does not suppress thrown errors), so only close
		// when the archive actually opened.
		$verify   = new \ZipArchive();
		$opened   = ( true === $verify->open( $zip_path, \ZipArchive::CHECKCONS ) );
		$has_mani = $opened && ( false !== $verify->locateName( 'manifest.json' ) );
		if ( $opened ) {
			$verify->close();
		}
		if ( ! $has_mani ) {
			@unlink( $zip_path );
			return new \WP_Error(
				'zip_corrupt',
				__( 'The export ZIP failed an integrity check and was discarded. Try a smaller batch size.', 'ekwa-wsei' )
			);
		}

		return array(
			'path'     => $zip_path,
			'filename' => $zip_filename,
			'size'     => filesize( $zip_path ),
			'stories'  => count( $manifest['stories'] ),
			'assets'   => count( $manifest['assets'] ),
		);
	}

	/**
	 * Absolute path to the directory holding temporary export ZIPs.
	 *
	 * @return string
	 */
	public static function temp_dir() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'ekwa-wsei-tmp';
	}

	/**
	 * Remove leftover export ZIPs older than the given age (default 2 hours).
	 *
	 * @param int $max_age Seconds.
	 * @return void
	 */
	public static function cleanup_temp( $max_age = 7200 ) {
		$dir = self::temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$now = time();
		foreach ( (array) glob( $dir . '/*.zip' ) as $file ) {
			if ( is_file( $file ) && ( $now - (int) @filemtime( $file ) ) > $max_age ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Stream a previously-built temp ZIP to the browser.
	 *
	 * The file is intentionally NOT deleted after streaming, so a download that
	 * gets truncated (e.g. by the browser when several start at once) can simply
	 * be clicked again. Old files are swept by cleanup_temp() instead.
	 *
	 * @param string $filename Basename of the temp ZIP (no path).
	 * @return \WP_Error|void Returns WP_Error on failure; otherwise exits.
	 */
	public function serve_temp_file( $filename ) {
		$filename = wp_basename( $filename );
		// Only allow files we created.
		if ( ! preg_match( '/^web-stories-export-[0-9A-Za-z\-]+\.zip$/', $filename ) ) {
			return new \WP_Error( 'bad_file', __( 'Invalid download request.', 'ekwa-wsei' ) );
		}
		$path = self::temp_dir() . '/' . $filename;
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'not_found', __( 'That export file has expired. Please rebuild the batch.', 'ekwa-wsei' ) );
		}

		// Keep the file (do not delete) so an interrupted download is retryable.
		$this->stream_download( $path, $filename, false );
	}

	/**
	 * Determine every attachment id referenced by a story.
	 *
	 * @param \WP_Post $post       The story.
	 * @param array    $story_meta Already-collected story meta.
	 * @param string   $baseurl    Uploads base URL.
	 * @return int[]
	 */
	private function collect_story_asset_ids( $post, array $story_meta, $baseurl ) {
		$ids = array();

		// 1. Featured image + publisher logo meta (direct attachment ids).
		if ( ! empty( $story_meta['_thumbnail_id'] ) ) {
			$ids[ (int) $story_meta['_thumbnail_id'] ] = true;
		}
		if ( ! empty( $story_meta['web_stories_publisher_logo'] ) ) {
			$ids[ (int) $story_meta['web_stories_publisher_logo'] ] = true;
		}

		// 2. Integer ids embedded in the editor JSON (resource id / posterId).
		if ( ! empty( $post->post_content_filtered ) ) {
			$data = json_decode( $post->post_content_filtered, true );
			if ( is_array( $data ) ) {
				$json_ids = array();
				Helpers::collect_ids_from_story_data( $data, $json_ids );
				foreach ( array_keys( $json_ids ) as $jid ) {
					$ids[ (int) $jid ] = true;
				}
			}
		}

		// 3. Resolve any uploads URLs found in both content fields back to ids.
		$haystack = (string) $post->post_content . "\n" . (string) $post->post_content_filtered;
		foreach ( Helpers::extract_urls( $haystack, $baseurl ) as $url ) {
			$aid = Helpers::url_to_attachment_id( $url );
			if ( $aid ) {
				$ids[ $aid ] = true;
			}
		}

		// 4. wp-image-123 classes in the AMP markup.
		if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $m ) ) {
			foreach ( $m[1] as $mid ) {
				$ids[ (int) $mid ] = true;
			}
		}

		// Keep only ids that are real attachments.
		$valid = array();
		foreach ( array_keys( $ids ) as $id ) {
			if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
				$valid[] = $id;
			}
		}
		return $valid;
	}

	/**
	 * Build a manifest entry for one attachment and add its file(s) to the ZIP.
	 *
	 * @param int         $id  Attachment id.
	 * @param \ZipArchive $zip Open zip archive.
	 * @return array|null Manifest entry, or null if the file is unavailable.
	 */
	private function build_asset_entry( $id, \ZipArchive $zip ) {
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$file_path = get_attached_file( $id );
		$url       = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}

		$filename = $file_path ? wp_basename( $file_path ) : wp_basename( $url );
		$zip_dir  = Helpers::asset_zip_dir( $id, $filename );
		$zip_path = $zip_dir . $filename;

		$added = false;
		if ( $file_path && file_exists( $file_path ) ) {
			$added = $zip->addFile( $file_path, $zip_path );
		}
		if ( ! $added ) {
			// Fall back to downloading the file via its URL (e.g. offloaded
			// media). Stream it to a temp file rather than loading it into
			// memory, then add that file.
			$tmp = $this->fetch_remote_to_file( $url );
			if ( null === $tmp ) {
				return null;
			}
			$this->temp_files[] = $tmp;
			$added = $zip->addFile( $tmp, $zip_path );
			if ( ! $added ) {
				return null;
			}
		}
		// Skip compression for media (already-compressed jpg/png/mp4/etc.).
		// Deflating them is slow, can time out, and saves almost nothing.
		$this->store_no_compress( $zip, $zip_path );

		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();

		// Original (un-scaled) image, if WordPress kept one.
		$original_zip_path = '';
		$original_filename = '';
		if ( ! empty( $metadata['original_image'] ) && $file_path ) {
			$orig_path = path_join( dirname( $file_path ), $metadata['original_image'] );
			if ( file_exists( $orig_path ) && wp_basename( $orig_path ) !== $filename ) {
				$original_filename = wp_basename( $orig_path );
				$original_zip_path = $zip_dir . 'original/' . $original_filename;
				if ( $zip->addFile( $orig_path, $original_zip_path ) ) {
					$this->store_no_compress( $zip, $original_zip_path );
				}
			}
		}

		$meta = array();
		foreach ( $this->asset_meta_keys as $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( '' !== $value && null !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		return array(
			'old_id'            => $id,
			'title'             => $attachment->post_title,
			'slug'              => $attachment->post_name,
			'caption'           => $attachment->post_excerpt,
			'description'       => $attachment->post_content,
			'alt'               => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime_type'         => $attachment->post_mime_type,
			'date'              => $attachment->post_date,
			'url'               => $url,
			'filename'          => $filename,
			'zip_path'          => $zip_path,
			'original_filename' => $original_filename,
			'original_zip_path' => $original_zip_path,
			'metadata'          => $metadata,
			'meta'              => $meta,
		);
	}

	/**
	 * Download a remote file straight to a temp file (no in-memory buffering).
	 *
	 * @param string $url URL.
	 * @return string|null Absolute temp path, or null on failure.
	 */
	private function fetch_remote_to_file( $url ) {
		$tmp = wp_tempnam( 'ekwa-wsei-asset' );
		if ( ! $tmp ) {
			return null;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 120,
				'stream'    => true,
				'filename'  => $tmp,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! file_exists( $tmp ) || 0 === filesize( $tmp ) ) {
			@unlink( $tmp );
			return null;
		}
		return $tmp;
	}

	/**
	 * Delete any temp files downloaded for offloaded media.
	 *
	 * @return void
	 */
	private function cleanup_temp_files() {
		foreach ( $this->temp_files as $tmp ) {
			if ( $tmp && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
		}
		$this->temp_files = array();
	}

	/**
	 * Mark a ZIP entry as "stored" (uncompressed). No-op on libzip builds that
	 * do not expose per-entry compression control.
	 *
	 * @param \ZipArchive $zip   Open archive.
	 * @param string      $entry Entry name inside the archive.
	 * @return void
	 */
	private function store_no_compress( \ZipArchive $zip, $entry ) {
		if ( method_exists( $zip, 'setCompressionName' ) && defined( 'ZipArchive::CM_STORE' ) ) {
			@$zip->setCompressionName( $entry, \ZipArchive::CM_STORE );
		}
	}
}
