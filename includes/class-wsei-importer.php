<?php
/**
 * Importer: reads an export bundle, sideloads assets, re-links and inserts stories.
 *
 * @package Ekwa\WebStoriesExportImport
 */

namespace Ekwa\WSEI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores a bundle created by the Exporter into the current site.
 */
class Importer {

	/**
	 * Old attachment id => new attachment id.
	 *
	 * @var array<int,int>
	 */
	private $id_map = array();

	/**
	 * Old asset URL => new asset URL (full size + every known intermediate size).
	 *
	 * @var array<string,string>
	 */
	private $url_map = array();

	/**
	 * Per-asset regex catch-all rules for any size variant not covered above.
	 *
	 * @var array<int,array{pattern:string,replace:string}>
	 */
	private $regex_rules = array();

	/**
	 * Accumulated warnings to surface to the user.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * De-dup table: "sourceSite|oldAttachmentId" => new attachment id.
	 * Lets shared assets (e.g. the publisher logo) be imported once across a
	 * multi-batch import instead of duplicated in every batch.
	 *
	 * @var array<string,int>
	 */
	private $seen_assets = array();

	/**
	 * Aggregated stories imported across every processed ZIP.
	 *
	 * @var array[]
	 */
	private $all_stories = array();

	/**
	 * Distinct source-site URLs seen across the processed bundles.
	 *
	 * @var array<string,bool>
	 */
	private $source_sites = array();

	/**
	 * Counters across every processed ZIP.
	 *
	 * @var int
	 */
	private $stat_fresh = 0;
	private $stat_reused = 0;
	private $stat_total = 0;

	/**
	 * Import from one OR many uploaded $_FILES entries (batch ZIPs).
	 *
	 * Assets shared between batches are imported only once.
	 *
	 * @param array[] $files List of $_FILES-style arrays.
	 * @return array|\WP_Error Aggregate result summary or a fatal error.
	 */
	public function import_uploaded_files( array $files ) {
		if ( empty( $files ) ) {
			return new \WP_Error( 'no_upload', __( 'No files were uploaded.', 'ekwa-wsei' ) );
		}

		$uploads  = wp_get_upload_dir();
		$processed = 0;

		foreach ( $files as $file ) {
			if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				$this->warnings[] = sprintf(
					/* translators: %s: file name */
					__( 'Skipped "%s": not a valid upload.', 'ekwa-wsei' ),
					isset( $file['name'] ) ? $file['name'] : '?'
				);
				continue;
			}
			if ( ! empty( $file['error'] ) ) {
				$this->warnings[] = sprintf(
					/* translators: 1: file name, 2: error code */
					__( 'Skipped "%1$s": upload error code %2$d.', 'ekwa-wsei' ),
					isset( $file['name'] ) ? $file['name'] : '?',
					(int) $file['error']
				);
				continue;
			}

			$work_dir = trailingslashit( $uploads['basedir'] ) . 'ekwa-wsei-import-' . wp_generate_password( 8, false, false );
			wp_mkdir_p( $work_dir );
			$zip_dest = $work_dir . '/bundle.zip';

			if ( ! @move_uploaded_file( $file['tmp_name'], $zip_dest ) ) {
				Helpers::rrmdir( $work_dir );
				$this->warnings[] = sprintf(
					/* translators: %s: file name */
					__( 'Could not store uploaded file "%s".', 'ekwa-wsei' ),
					isset( $file['name'] ) ? $file['name'] : '?'
				);
				continue;
			}

			$res = $this->import_zip( $zip_dest, $work_dir );
			Helpers::rrmdir( $work_dir );

			if ( is_wp_error( $res ) ) {
				$this->warnings[] = sprintf(
					/* translators: 1: file name, 2: error */
					__( 'Bundle "%1$s" failed: %2$s', 'ekwa-wsei' ),
					isset( $file['name'] ) ? $file['name'] : '?',
					$res->get_error_message()
				);
				continue;
			}
			++$processed;
		}

		if ( 0 === $processed && empty( $this->all_stories ) ) {
			return new \WP_Error(
				'all_failed',
				implode( ' ', array_slice( $this->warnings, 0, 5 ) ) ?: __( 'No bundles could be imported.', 'ekwa-wsei' )
			);
		}

		return $this->result();
	}

	/**
	 * Back-compat: import a single uploaded $_FILES entry.
	 *
	 * @param array $file A single $_FILES array element.
	 * @return array|\WP_Error
	 */
	public function import_uploaded_file( array $file ) {
		return $this->import_uploaded_files( array( $file ) );
	}

	/**
	 * Import from a ZIP already present on the server (e.g. for large bundles).
	 *
	 * @param string $zip_path Absolute path to a .zip bundle.
	 * @return array|\WP_Error
	 */
	public function import_server_file( $zip_path ) {
		$zip_path = wp_normalize_path( (string) $zip_path );
		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new \WP_Error( 'not_found', __( 'The specified file does not exist or is not readable.', 'ekwa-wsei' ) );
		}

		$uploads  = wp_get_upload_dir();
		$work_dir = trailingslashit( $uploads['basedir'] ) . 'ekwa-wsei-import-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $work_dir );

		$res = $this->import_zip( $zip_path, $work_dir );
		Helpers::rrmdir( $work_dir );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->result();
	}

	/**
	 * Build the aggregate result summary from accumulated state.
	 *
	 * @return array
	 */
	private function result() {
		return array(
			'stories'         => $this->all_stories,
			'assets_imported' => $this->stat_fresh,
			'assets_reused'   => $this->stat_reused,
			'assets_total'    => $this->stat_total,
			'warnings'        => $this->warnings,
			'source_site'     => implode( ', ', array_keys( $this->source_sites ) ),
		);
	}

	/**
	 * Core import routine for ONE ZIP. Accumulates into instance state.
	 *
	 * @param string $zip_path Absolute path to the bundle ZIP.
	 * @param string $work_dir Writable working directory for extraction.
	 * @return true|\WP_Error
	 */
	private function import_zip( $zip_path, $work_dir ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'no_zip', __( 'The PHP ZipArchive extension is required but is not available on this server.', 'ekwa-wsei' ) );
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );
		// Best-effort extra headroom for image/thumbnail processing. Harmless if
		// the host forbids ini_set or the real limit is system RAM.
		@ini_set( 'memory_limit', '512M' );

		$extract_dir = $work_dir . '/extracted';
		wp_mkdir_p( $extract_dir );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'open_failed', __( 'Could not open the ZIP bundle.', 'ekwa-wsei' ) );
		}

		// Guard against "zip slip": reject absolute paths or parent traversal.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				continue;
			}
			$name = str_replace( '\\', '/', $name );
			if ( '/' === substr( $name, 0, 1 ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
				$zip->close();
				return new \WP_Error( 'unsafe_zip', __( 'The bundle contains unsafe file paths and was rejected.', 'ekwa-wsei' ) );
			}
		}

		if ( ! $zip->extractTo( $extract_dir ) ) {
			$zip->close();
			return new \WP_Error( 'extract_failed', __( 'Could not extract the ZIP bundle.', 'ekwa-wsei' ) );
		}
		$zip->close();

		$manifest_path = $extract_dir . '/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return new \WP_Error( 'no_manifest', __( 'This ZIP does not look like a Web Stories export bundle (manifest.json is missing).', 'ekwa-wsei' ) );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['format'] ) || EKWA_WSEI_FORMAT !== $manifest['format'] ) {
			return new \WP_Error( 'bad_manifest', __( 'The manifest is invalid or was produced by an incompatible plugin version.', 'ekwa-wsei' ) );
		}

		// Make sure attachment + media helpers are available outside admin context.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$source_site = isset( $manifest['source_site_url'] ) ? (string) $manifest['source_site_url'] : '';
		if ( '' !== $source_site ) {
			$this->source_sites[ $source_site ] = true;
		}

		// 1. Import every asset first and build the remap tables (dedup-aware).
		$assets = isset( $manifest['assets'] ) && is_array( $manifest['assets'] ) ? $manifest['assets'] : array();
		$this->stat_total += count( $assets );
		foreach ( $assets as $asset ) {
			$this->import_asset( $asset, $extract_dir, $source_site );
			// Free memory between assets — important on memory-constrained hosts,
			// since thumbnail generation can hold a lot of image data.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// 2. Import each story with re-linked content and meta.
		$stories = isset( $manifest['stories'] ) && is_array( $manifest['stories'] ) ? $manifest['stories'] : array();
		foreach ( $stories as $story ) {
			$new = $this->import_story( $story );
			if ( is_wp_error( $new ) ) {
				$this->warnings[] = sprintf(
					/* translators: 1: story title, 2: error message */
					__( 'Story "%1$s" could not be imported: %2$s', 'ekwa-wsei' ),
					isset( $story['title'] ) ? $story['title'] : '?',
					$new->get_error_message()
				);
				continue;
			}
			$this->all_stories[] = $new;
		}

		return true;
	}

	/**
	 * Post meta key stamped on every imported attachment so the same source
	 * asset can be recognised and reused on a later, separate import.
	 */
	const SOURCE_META_KEY = '_ekwa_wsei_src';

	/**
	 * Sideload a single asset into the media library and record remap entries.
	 *
	 * The same source asset is reused instead of duplicated when it has already
	 * been imported — both within this run (across batches) AND in an earlier,
	 * separate import (looked up by post meta), so importing batches one at a
	 * time stays just as clean as importing them all together.
	 *
	 * @param array  $asset       Asset manifest entry.
	 * @param string $extract_dir Root of the extracted bundle.
	 * @param string $source_site Source site URL (for the de-dup key).
	 * @return int New attachment id, or 0 on failure.
	 */
	private function import_asset( array $asset, $extract_dir, $source_site = '' ) {
		$old_id   = isset( $asset['old_id'] ) ? (int) $asset['old_id'] : 0;
		$zip_path = isset( $asset['zip_path'] ) ? $asset['zip_path'] : '';
		$src      = $extract_dir . '/' . ltrim( $zip_path, '/' );

		$dedup_key = $source_site . '|' . $old_id;

		// 1. Reuse a copy already imported in this run (fast, in-memory).
		if ( $old_id && isset( $this->seen_assets[ $dedup_key ] ) ) {
			$reused = $this->reuse_asset( $asset, (int) $this->seen_assets[ $dedup_key ], $old_id, $dedup_key );
			if ( $reused ) {
				return $reused;
			}
		}

		// 2. Reuse a copy imported during an earlier, separate import (persistent).
		if ( $old_id && '' !== $source_site ) {
			$existing = $this->find_existing_asset( $dedup_key );
			if ( $existing ) {
				$reused = $this->reuse_asset( $asset, $existing, $old_id, $dedup_key );
				if ( $reused ) {
					return $reused;
				}
			}
		}

		if ( ! $old_id || '' === $zip_path || ! file_exists( $src ) ) {
			$this->warnings[] = sprintf(
				/* translators: %s: asset filename */
				__( 'Asset file missing from bundle, skipped: %s', 'ekwa-wsei' ),
				isset( $asset['filename'] ) ? $asset['filename'] : (string) $old_id
			);
			return 0;
		}

		$filename = isset( $asset['filename'] ) && $asset['filename'] ? $asset['filename'] : wp_basename( $src );

		// Place the file into the uploads folder using a streaming filesystem
		// copy. We deliberately avoid file_get_contents()/wp_upload_bits(), which
		// would load the whole asset (potentially a large video) into memory and
		// can exhaust a low memory_limit / a memory-pressured server.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->warnings[] = sprintf(
				/* translators: 1: filename, 2: error */
				__( 'Could not write asset "%1$s": %2$s', 'ekwa-wsei' ),
				$filename,
				$upload_dir['error']
			);
			return 0;
		}

		wp_mkdir_p( $upload_dir['path'] );
		$unique    = wp_unique_filename( $upload_dir['path'], $filename );
		$new_file  = trailingslashit( $upload_dir['path'] ) . $unique;
		$new_url   = trailingslashit( $upload_dir['url'] ) . $unique;

		if ( ! @copy( $src, $new_file ) ) {
			$this->warnings[] = sprintf(
				/* translators: %s: filename */
				__( 'Could not copy asset "%s" into the uploads folder (check disk space and permissions).', 'ekwa-wsei' ),
				$filename
			);
			return 0;
		}

		// Match the permissions WordPress normally applies to uploaded files.
		$stat  = @stat( dirname( $new_file ) );
		$perms = $stat ? ( $stat['mode'] & 0000666 ) : 0644;
		@chmod( $new_file, $perms );

		$filetype = wp_check_filetype( $new_file );
		$mime     = ! empty( $asset['mime_type'] ) ? $asset['mime_type'] : ( $filetype['type'] ? $filetype['type'] : 'application/octet-stream' );

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => isset( $asset['title'] ) && '' !== $asset['title'] ? $asset['title'] : preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => isset( $asset['description'] ) ? $asset['description'] : '',
			'post_excerpt'   => isset( $asset['caption'] ) ? $asset['caption'] : '',
			'post_status'    => 'inherit',
		);

		$new_id = wp_insert_attachment( wp_slash( $attachment ), $new_file, 0, true );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			$this->warnings[] = sprintf(
				/* translators: %s: filename */
				__( 'Failed to create attachment for "%s".', 'ekwa-wsei' ),
				$filename
			);
			return 0;
		}

		// Alt text.
		if ( ! empty( $asset['alt'] ) ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', wp_slash( $asset['alt'] ) );
		}

		// Carry over Web Stories' own attachment meta (muted flag, base color, etc.).
		if ( ! empty( $asset['meta'] ) && is_array( $asset['meta'] ) ) {
			foreach ( $asset['meta'] as $mk => $mv ) {
				if ( '_wp_attachment_image_alt' === $mk ) {
					continue;
				}
				update_post_meta( $new_id, $mk, wp_slash( $mv ) );
			}
		}

		// Regenerate intermediate sizes / metadata for the new file.
		$new_meta = wp_generate_attachment_metadata( $new_id, $new_file );
		if ( is_array( $new_meta ) ) {
			wp_update_attachment_metadata( $new_id, $new_meta );
		} else {
			$new_meta = array();
		}

		// Stamp the source so this asset can be reused on a later separate import.
		if ( '' !== $source_site ) {
			update_post_meta( $new_id, self::SOURCE_META_KEY, $dedup_key );
		}

		$this->id_map[ $old_id ]          = (int) $new_id;
		$this->seen_assets[ $dedup_key ]  = (int) $new_id;
		++$this->stat_fresh;
		$this->build_url_map( $asset, $new_id, $new_url, $new_meta );

		return (int) $new_id;
	}

	/**
	 * Reuse an already-imported attachment: re-establish the id/URL remap
	 * tables for it and count it as reused. Returns 0 if the attachment has
	 * since been deleted (so the caller falls through to a fresh import).
	 *
	 * @param array  $asset     Asset manifest entry.
	 * @param int    $new_id    Existing attachment id to reuse.
	 * @param int    $old_id    Original attachment id from the source site.
	 * @param string $dedup_key Cache key.
	 * @return int Attachment id, or 0 if it no longer exists.
	 */
	private function reuse_asset( array $asset, $new_id, $old_id, $dedup_key ) {
		$new_id  = (int) $new_id;
		$new_url = $new_id ? wp_get_attachment_url( $new_id ) : '';
		if ( ! $new_url || 'attachment' !== get_post_type( $new_id ) ) {
			// Stale reference (deleted media) — forget it and re-import fresh.
			unset( $this->seen_assets[ $dedup_key ] );
			return 0;
		}

		$new_meta = wp_get_attachment_metadata( $new_id );
		$this->id_map[ $old_id ]         = $new_id;
		$this->seen_assets[ $dedup_key ] = $new_id;
		$this->build_url_map( $asset, $new_id, $new_url, is_array( $new_meta ) ? $new_meta : array() );
		++$this->stat_reused;

		return $new_id;
	}

	/**
	 * Find an attachment previously imported from the same source asset.
	 *
	 * @param string $dedup_key "sourceSite|oldAttachmentId".
	 * @return int Attachment id, or 0 if none.
	 */
	private function find_existing_asset( $dedup_key ) {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE_META_KEY,
				'meta_value'       => $dedup_key,
			)
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Build URL remap entries (full size + every intermediate size) for one asset.
	 *
	 * @param array  $asset    Old asset manifest entry.
	 * @param int    $new_id   New attachment id.
	 * @param string $new_url  New full-size URL.
	 * @param array  $new_meta New attachment metadata.
	 * @return void
	 */
	private function build_url_map( array $asset, $new_id, $new_url, array $new_meta ) {
		$old_url = isset( $asset['url'] ) ? Helpers::normalize_url( $asset['url'] ) : '';
		if ( '' === $old_url ) {
			return;
		}

		$this->url_map[ $old_url ] = $new_url;

		$old_dir = trailingslashit( dirname( $old_url ) );
		$new_dir = trailingslashit( dirname( $new_url ) );

		$old_meta  = isset( $asset['metadata'] ) && is_array( $asset['metadata'] ) ? $asset['metadata'] : array();
		$old_sizes = isset( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ? $old_meta['sizes'] : array();
		$new_sizes = isset( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ? $new_meta['sizes'] : array();

		foreach ( $old_sizes as $size_name => $old_size ) {
			if ( empty( $old_size['file'] ) ) {
				continue;
			}
			$old_size_url = $old_dir . $old_size['file'];

			$new_size_file = '';
			if ( isset( $new_sizes[ $size_name ]['file'] ) ) {
				$new_size_file = $new_sizes[ $size_name ]['file'];
			} else {
				// Match by closest dimensions.
				$new_size_file = $this->match_size_by_dimensions( $old_size, $new_sizes );
			}

			$this->url_map[ $old_size_url ] = $new_size_file ? ( $new_dir . $new_size_file ) : $new_url;
		}

		// Original (un-scaled) image, best effort -> new full size.
		if ( ! empty( $asset['original_filename'] ) ) {
			$this->url_map[ $old_dir . $asset['original_filename'] ] = $new_url;
		}

		// Regex catch-all for any custom/unknown size variant that shares the
		// prefix. Built in two slash forms: plain (HTML) and JSON-escaped (\/).
		$old_base = preg_replace( '/\.[^.]+$/', '', wp_basename( $old_url ) );
		$old_ext  = pathinfo( $old_url, PATHINFO_EXTENSION );
		$new_base = preg_replace( '/\.[^.]+$/', '', wp_basename( $new_url ) );
		$new_ext  = pathinfo( $new_url, PATHINFO_EXTENSION );

		if ( $old_base && $old_ext ) {
			$old_prefix     = $old_dir . $old_base;
			$new_prefix     = $new_dir . $new_base;
			$old_prefix_esc = str_replace( '/', '\/', $old_prefix );
			$new_prefix_esc = str_replace( '/', '\/', $new_prefix );

			$this->regex_rules[] = array(
				'pattern'     => '#' . preg_quote( $old_prefix, '#' ) . '(-\d+x\d+)?\.' . preg_quote( $old_ext, '#' ) . '#i',
				'replace'     => str_replace( '\\', '\\\\', $new_prefix ) . '$1.' . $new_ext,
				'pattern_esc' => '#' . preg_quote( $old_prefix_esc, '#' ) . '(-\d+x\d+)?\.' . preg_quote( $old_ext, '#' ) . '#i',
				'replace_esc' => str_replace( '\\', '\\\\', $new_prefix_esc ) . '$1.' . $new_ext,
			);
		}
	}

	/**
	 * Find the new size filename whose dimensions best match an old size.
	 *
	 * @param array $old_size  Old size descriptor (width/height/file).
	 * @param array $new_sizes New sizes map.
	 * @return string New size filename or '' if none.
	 */
	private function match_size_by_dimensions( array $old_size, array $new_sizes ) {
		$ow   = isset( $old_size['width'] ) ? (int) $old_size['width'] : 0;
		$oh   = isset( $old_size['height'] ) ? (int) $old_size['height'] : 0;
		$best = '';
		$best_delta = PHP_INT_MAX;

		foreach ( $new_sizes as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$delta = abs( ( (int) $size['width'] ) - $ow ) + abs( ( (int) $size['height'] ) - $oh );
			if ( $delta < $best_delta ) {
				$best_delta = $delta;
				$best       = $size['file'];
			}
		}

		// Only accept a reasonably close match.
		return ( $best_delta <= 4 ) ? $best : '';
	}

	/**
	 * Insert one story with fully re-linked content and meta.
	 *
	 * @param array $story Story manifest entry.
	 * @return array|\WP_Error { id, title, edit_link, view_link } or error.
	 */
	private function import_story( array $story ) {
		$content          = isset( $story['post_content'] ) ? $story['post_content'] : '';
		$content_filtered = isset( $story['post_content_filtered'] ) ? $story['post_content_filtered'] : '';

		// Re-link the AMP markup.
		$content = $this->relink_html( $content );

		// Re-link + re-id the editor JSON.
		$content_filtered = $this->relink_story_data( $content_filtered );

		$postarr = array(
			'post_type'             => EKWA_WSEI_POST_TYPE,
			'post_status'           => isset( $story['status'] ) ? $story['status'] : 'draft',
			'post_title'            => isset( $story['title'] ) ? $story['title'] : '',
			'post_name'             => isset( $story['slug'] ) ? $story['slug'] : '',
			'post_excerpt'          => isset( $story['excerpt'] ) ? $story['excerpt'] : '',
			'post_content'          => $content,
			'post_content_filtered' => $content_filtered,
			'menu_order'            => isset( $story['menu_order'] ) ? (int) $story['menu_order'] : 0,
		);

		if ( ! empty( $story['date'] ) ) {
			$postarr['post_date']     = $story['date'];
			$postarr['post_date_gmt'] = ! empty( $story['date_gmt'] ) ? $story['date_gmt'] : '';
			$postarr['edit_date']     = true;
		}

		$new_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Re-linked meta.
		$meta = isset( $story['meta'] ) && is_array( $story['meta'] ) ? $story['meta'] : array();

		// Featured image / poster attachment.
		if ( ! empty( $meta['_thumbnail_id'] ) ) {
			$old_thumb = (int) $meta['_thumbnail_id'];
			if ( isset( $this->id_map[ $old_thumb ] ) ) {
				update_post_meta( $new_id, '_thumbnail_id', (int) $this->id_map[ $old_thumb ] );
			}
		}

		// Publisher logo attachment id.
		if ( ! empty( $meta['web_stories_publisher_logo'] ) ) {
			$old_logo = (int) $meta['web_stories_publisher_logo'];
			if ( isset( $this->id_map[ $old_logo ] ) ) {
				update_post_meta( $new_id, 'web_stories_publisher_logo', (int) $this->id_map[ $old_logo ] );
			}
		}

		// Poster meta (array with a url field).
		if ( ! empty( $meta['web_stories_poster'] ) && is_array( $meta['web_stories_poster'] ) ) {
			$poster = $meta['web_stories_poster'];
			if ( ! empty( $poster['url'] ) ) {
				$poster['url'] = $this->relink_html( $poster['url'] );
			}
			update_post_meta( $new_id, 'web_stories_poster', wp_slash( $poster ) );
		}

		return array(
			'id'        => (int) $new_id,
			'title'     => get_the_title( $new_id ),
			'edit_link' => get_edit_post_link( $new_id, 'raw' ),
			'view_link' => get_permalink( $new_id ),
		);
	}

	/**
	 * Apply URL remapping (+ regex catch-all + wp-image-ID) to an HTML string.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	private function relink_html( $html ) {
		$html = Helpers::replace_urls( $html, $this->url_map );

		foreach ( $this->regex_rules as $rule ) {
			$html = preg_replace( $rule['pattern'], $rule['replace'], $html );
		}

		// Re-point wp-image-OLDID classes.
		if ( ! empty( $this->id_map ) ) {
			$html = preg_replace_callback(
				'/wp-image-(\d+)/',
				function ( $m ) {
					$old = (int) $m[1];
					return isset( $this->id_map[ $old ] ) ? 'wp-image-' . (int) $this->id_map[ $old ] : $m[0];
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Re-link and re-id the editor story JSON.
	 *
	 * Operates on the raw JSON string (never decode/re-encode) so the exact
	 * structure — including empty objects {} and float precision of element
	 * positions — is preserved byte-for-byte apart from our targeted swaps.
	 *
	 * @param string $json Raw post_content_filtered JSON.
	 * @return string
	 */
	private function relink_story_data( $json ) {
		if ( '' === (string) $json ) {
			return $json;
		}

		// 1. Swap all known URLs (full size + every intermediate size). The
		// helper covers both plain and JSON-escaped-slash ("\/") forms.
		$json = Helpers::replace_urls( $json, $this->url_map );

		// 2. Catch-all for any remaining custom-size variants, in both forms.
		foreach ( $this->regex_rules as $rule ) {
			$json = preg_replace( $rule['pattern_esc'], $rule['replace_esc'], $json );
			$json = preg_replace( $rule['pattern'], $rule['replace'], $json );
		}

		// 3. Remap resource / poster attachment ids in place. Matches only the
		// numeric value of "id":N / "posterId":N (never string uuids or wider
		// numbers like 1234 when remapping 123).
		foreach ( $this->id_map as $old => $new ) {
			if ( (int) $old === (int) $new ) {
				continue;
			}
			$json = preg_replace(
				'/("(?:id|posterId)"\s*:\s*)' . (int) $old . '(?![0-9])/',
				'${1}' . (int) $new,
				$json
			);
		}

		return $json;
	}
}
