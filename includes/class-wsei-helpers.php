<?php
/**
 * Shared helper utilities for the Ekwa Web Stories Export/Import plugin.
 *
 * @package Ekwa\WebStoriesExportImport
 */

namespace Ekwa\WSEI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers used by both the Exporter and Importer.
 */
class Helpers {

	/**
	 * Capability required to use the export/import tools.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required to run exports/imports.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return apply_filters( 'ekwa_wsei_capability', 'manage_options' );
	}

	/**
	 * Whether the Google Web Stories plugin's post type is registered.
	 *
	 * Export listing needs it; import does not strictly require it because the
	 * posts can still be inserted into the database.
	 *
	 * @return bool
	 */
	public static function web_stories_active() {
		return post_type_exists( EKWA_WSEI_POST_TYPE );
	}

	/**
	 * File extensions we treat as story assets when scanning content for URLs.
	 *
	 * @return string[]
	 */
	public static function asset_extensions() {
		return array(
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico',
			'mp4', 'webm', 'mov', 'm4v', 'ogv', 'ogg', '3gp',
			'mp3', 'm4a', 'wav', 'aac',
		);
	}

	/**
	 * Strip a WordPress "-123x456" intermediate-size suffix from a file path/URL.
	 *
	 * @param string $url File path or URL.
	 * @return string
	 */
	public static function strip_size_suffix( $url ) {
		return (string) preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+(?:\?.*)?$)/', '', $url );
	}

	/**
	 * Resolve a (possibly resized) attachment URL to its attachment ID.
	 *
	 * @param string $url Attachment URL.
	 * @return int Attachment ID, or 0 if not found.
	 */
	public static function url_to_attachment_id( $url ) {
		$url = self::normalize_url( $url );
		if ( '' === $url ) {
			return 0;
		}

		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			return (int) $id;
		}

		// Try again without an intermediate size suffix (e.g. image-300x200.jpg).
		$full = self::strip_size_suffix( $url );
		if ( $full !== $url ) {
			$id = attachment_url_to_postid( $full );
		}

		return (int) $id;
	}

	/**
	 * Normalize a URL for comparison: trim, decode escaped slashes, strip query/fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = (string) $url;
		$url = str_replace( '\/', '/', $url ); // Un-escape JSON-style slashes.
		$url = trim( $url );

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		$q = strpos( $url, '?' );
		if ( false !== $q ) {
			$url = substr( $url, 0, $q );
		}

		return $url;
	}

	/**
	 * Extract every uploads URL referenced anywhere in a chunk of text
	 * (works for both AMP HTML and the story JSON).
	 *
	 * @param string $text    Content to scan.
	 * @param string $baseurl Uploads base URL of the source site (optional, narrows matches).
	 * @return string[] Unique, normalized URLs.
	 */
	public static function extract_urls( $text, $baseurl = '' ) {
		if ( '' === (string) $text ) {
			return array();
		}

		$text = str_replace( '\/', '/', $text ); // Handle JSON escaped slashes.
		$ext  = implode( '|', array_map( 'preg_quote', self::asset_extensions() ) );

		// Match http(s) URLs ending in one of our asset extensions (with optional ?query).
		$pattern = '#https?://[^\s"\'\\\\<>()]+?\.(?:' . $ext . ')(?:\?[^\s"\'\\\\<>()]*)?#i';

		if ( ! preg_match_all( $pattern, $text, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[0] as $url ) {
			$url = self::normalize_url( $url );
			if ( '' === $url ) {
				continue;
			}
			if ( $baseurl && false === strpos( $url, $baseurl ) ) {
				continue; // Only assets from this site's uploads folder.
			}
			$urls[ $url ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * Recursively walk a decoded JSON structure and collect integer values
	 * found under attachment-id keys (Web Stories resource "id" / "posterId").
	 *
	 * @param mixed $data Decoded JSON node.
	 * @param array $ids  Accumulator (by reference).
	 * @return void
	 */
	public static function collect_ids_from_story_data( $data, array &$ids ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( ( 'id' === $key || 'posterId' === $key ) && is_int( $value ) && $value > 0 ) {
					$ids[ $value ] = true;
				} elseif ( is_array( $value ) ) {
					self::collect_ids_from_story_data( $value, $ids );
				}
			}
		}
	}

	/**
	 * Apply a URL search/replace map to a string, replacing the longest keys
	 * first and covering both plain and JSON-escaped-slash variants.
	 *
	 * @param string $text    Subject text.
	 * @param array  $url_map Map of old URL => new URL.
	 * @return string
	 */
	public static function replace_urls( $text, array $url_map ) {
		if ( '' === (string) $text || empty( $url_map ) ) {
			return $text;
		}

		// Sort by key length descending so size variants / longer URLs win first.
		uksort(
			$url_map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$search  = array();
		$replace = array();
		foreach ( $url_map as $old => $new ) {
			if ( '' === (string) $old || $old === $new ) {
				continue;
			}
			// Plain form.
			$search[]  = $old;
			$replace[] = $new;
			// JSON-escaped-slash form (post_content_filtered stores escaped slashes).
			$old_esc = str_replace( '/', '\/', $old );
			$new_esc = str_replace( '/', '\/', $new );
			if ( $old_esc !== $old ) {
				$search[]  = $old_esc;
				$replace[] = $new_esc;
			}
		}

		return str_replace( $search, $replace, $text );
	}

	/**
	 * Build a unique, filesystem-safe slug fragment for an asset folder.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $file Filename.
	 * @return string
	 */
	public static function asset_zip_dir( $id, $file ) {
		return 'assets/' . (int) $id . '/';
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
