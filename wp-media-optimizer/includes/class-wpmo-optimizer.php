<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core: upload hooks + optimizing existing attachments.
 */
class WPMO_Optimizer {

	const META_DONE  = '_wpmo_optimized';
	const META_ERROR = '_wpmo_error';
	const CRON_HOOK  = 'wpmo_compress_video';

	public static function init() {
		// 1) Images: convert the uploaded file itself before WordPress creates the attachment.
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_handle_upload' ), 5, 2 );

		// 2) Videos: compress in background after the attachment exists.
		add_action( 'add_attachment', array( __CLASS__, 'on_add_attachment' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_compress_video' ) );

		// Let WordPress resize big uploads to our configured max width.
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'big_image_threshold' ), 20 );
	}

	public static function big_image_threshold( $threshold ) {
		$w = (int) WPMO_Settings::get( 'max_image_width' );
		return $w > 0 ? $w : false;
	}

	/* ------------------------------------------------------------------ *
	 * Upload time
	 * ------------------------------------------------------------------ */

	public static function on_handle_upload( $upload, $context = 'upload' ) {
		if ( ! WPMO_Settings::get( 'convert_on_upload' ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}
		if ( empty( $upload['file'] ) || ! WPMO_Image::is_convertible( $upload['type'] ) ) {
			return $upload;
		}

		$new = WPMO_Image::convert( $upload['file'], $upload['type'] );
		if ( is_wp_error( $new ) ) {
			return $upload; // Keep original upload untouched on failure.
		}

		if ( WPMO_Settings::get( 'delete_originals' ) ) {
			wp_delete_file( $upload['file'] );
		}

		$upload['file'] = $new;
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . wp_basename( $new );
		$upload['type'] = 'image/webp';

		return $upload;
	}

	public static function on_add_attachment( $id ) {
		if ( ! WPMO_Settings::get( 'compress_videos' ) ) {
			return;
		}
		$mime = get_post_mime_type( $id );
		if ( ! WPMO_Video::is_convertible( $mime ) ) {
			return;
		}
		// Run outside the upload request so the upload stays fast.
		wp_schedule_single_event( time() + 10, self::CRON_HOOK, array( (int) $id ) );
	}

	public static function cron_compress_video( $id ) {
		self::optimize_video( (int) $id );
	}

	/* ------------------------------------------------------------------ *
	 * Queue for bulk processing
	 * ------------------------------------------------------------------ */

	/** IDs of attachments that still need work. */
	public static function pending_ids( $type = 'all' ) {
		global $wpdb;

		$mimes = array();
		if ( 'all' === $type || 'image' === $type ) {
			$mimes = array_merge( $mimes, WPMO_Image::$convertible );
		}
		if ( 'all' === $type || 'video' === $type ) {
			$mimes = array_merge( $mimes, WPMO_Video::$convertible );
		}
		$in = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($in)
			AND d.meta_id IS NULL AND e.meta_id IS NULL
			ORDER BY p.ID ASC";

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( self::META_DONE, self::META_ERROR ), $mimes ) ) ) ); // phpcs:ignore
	}

	public static function reset_errors() {
		delete_post_meta_by_key( self::META_ERROR );
	}

	/** @return array|WP_Error Result info. */
	public static function optimize( $id ) {
		$mime = get_post_mime_type( $id );
		if ( WPMO_Image::is_convertible( $mime ) ) {
			return self::optimize_image( $id );
		}
		if ( WPMO_Video::is_convertible( $mime ) ) {
			return self::optimize_video( $id );
		}
		return new WP_Error( 'wpmo_type', 'نوع فایل پشتیبانی نمی‌شود.' );
	}

	/* ------------------------------------------------------------------ *
	 * Existing image -> WebP
	 * ------------------------------------------------------------------ */

	public static function optimize_image( $id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$attached = get_attached_file( $id );
		$original = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : $attached;
		$source   = ( $original && file_exists( $original ) ) ? $original : $attached;

		if ( ! $source || ! file_exists( $source ) ) {
			return self::fail( $id, 'فایل روی دیسک وجود ندارد.' );
		}

		$old_meta  = wp_get_attachment_metadata( $id );
		$old_files = self::attachment_files( $id, $old_meta, $attached );
		$old_size  = 0;
		foreach ( $old_files as $f ) {
			$old_size += file_exists( $f ) ? filesize( $f ) : 0;
		}

		$old_mime = get_post_mime_type( $id );
		$new      = WPMO_Image::convert( $source, $old_mime );
		if ( is_wp_error( $new ) ) {
			return self::fail( $id, $new->get_error_message() );
		}

		$old_stem = preg_replace( '/\.[^.]+$/', '', wp_basename( $source ) );
		$new_stem = preg_replace( '/\.[^.]+$/', '', wp_basename( $new ) );

		// Point the attachment to the new file and rebuild all sizes from it.
		update_attached_file( $id, $new );
		self::update_post_file_fields( $id, $new, 'image/webp' );

		$meta = wp_generate_attachment_metadata( $id, $new );
		if ( empty( $meta ) || is_wp_error( $meta ) ) {
			// Rollback.
			update_attached_file( $id, $attached );
			self::update_post_file_fields( $id, $attached, $old_mime );
			wp_delete_file( $new );
			return self::fail( $id, 'ساخت سایزهای تصویر ناموفق بود.' );
		}
		if ( ! empty( $old_meta['image_meta'] ) && empty( $meta['image_meta'] ) ) {
			$meta['image_meta'] = $old_meta['image_meta'];
		}
		wp_update_attachment_metadata( $id, $meta );

		$new_attached = get_attached_file( $id );
		$new_files    = self::attachment_files( $id, $meta, $new_attached );

		// Map every old URL to the matching new one.
		$rel_map = array();
		foreach ( $old_files as $old ) {
			$guess = dirname( $old ) . '/' . self::swap_stem_ext( wp_basename( $old ), $old_stem, $new_stem, 'webp' );
			$target = file_exists( $guess ) ? $guess : $new_attached;
			$rel_map[ self::rel( $old ) ] = self::rel( $target );
		}

		// Delete old files.
		if ( WPMO_Settings::get( 'delete_originals' ) ) {
			foreach ( $old_files as $old ) {
				if ( ! in_array( $old, $new_files, true ) && file_exists( $old ) ) {
					wp_delete_file( $old );
				}
			}
		}

		$changed = WPMO_Replacer::replace_everywhere( $rel_map );

		$new_size = 0;
		foreach ( $new_files as $f ) {
			$new_size += file_exists( $f ) ? filesize( $f ) : 0;
		}

		update_post_meta( $id, self::META_DONE, array( 'old' => $old_size, 'new' => $new_size, 'time' => time() ) );
		delete_post_meta( $id, self::META_ERROR );

		return array(
			'id'       => $id,
			'type'     => 'image',
			'file'     => wp_basename( $new_attached ),
			'old_size' => $old_size,
			'new_size' => $new_size,
			'refs'     => $changed,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Video compression
	 * ------------------------------------------------------------------ */

	public static function optimize_video( $id ) {
		if ( get_post_meta( $id, self::META_DONE, true ) ) {
			return new WP_Error( 'wpmo_done', 'قبلاً بهینه شده است.' );
		}
		$src = get_attached_file( $id );
		if ( ! $src || ! file_exists( $src ) ) {
			return self::fail( $id, 'فایل روی دیسک وجود ندارد.' );
		}

		$res = WPMO_Video::compress( $src );
		if ( is_wp_error( $res ) ) {
			return self::fail( $id, $res->get_error_message() );
		}

		$new = $res['path'];

		if ( $res['replaced'] && $new !== $src ) {
			// Format changed (e.g. .mov -> .mp4): repoint attachment, fix URLs, delete original.
			update_attached_file( $id, $new );
			self::update_post_file_fields( $id, $new, 'video/mp4' );
			WPMO_Replacer::replace_everywhere( array( self::rel( $src ) => self::rel( $new ) ) );
			if ( WPMO_Settings::get( 'delete_originals' ) ) {
				wp_delete_file( $src );
			}
		}

		if ( $res['replaced'] ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$meta = wp_generate_attachment_metadata( $id, $new );
			if ( $meta && ! is_wp_error( $meta ) ) {
				wp_update_attachment_metadata( $id, $meta );
			}
		}

		update_post_meta( $id, self::META_DONE, array( 'old' => $res['old_size'], 'new' => $res['new_size'], 'time' => time() ) );
		delete_post_meta( $id, self::META_ERROR );

		return array(
			'id'       => $id,
			'type'     => 'video',
			'file'     => wp_basename( $new ),
			'old_size' => $res['old_size'],
			'new_size' => $res['new_size'],
			'skipped'  => ! $res['replaced'],
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function fail( $id, $msg ) {
		update_post_meta( $id, self::META_ERROR, $msg );
		return new WP_Error( 'wpmo_fail', $msg );
	}

	/** All files on disk that belong to an image attachment. */
	private static function attachment_files( $id, $meta, $attached ) {
		$files = array();
		if ( $attached ) {
			$files[] = $attached;
		}
		$dir = $attached ? dirname( $attached ) : '';
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . '/' . $meta['original_image'];
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$files[] = $dir . '/' . $s['file'];
					}
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	private static function swap_stem_ext( $basename, $old_stem, $new_stem, $ext ) {
		$name = preg_replace( '/\.[^.]+$/', '', $basename );
		if ( 0 === strpos( $name, $old_stem ) ) {
			$name = $new_stem . substr( $name, strlen( $old_stem ) );
		}
		return $name . '.' . $ext;
	}

	/** Path relative to uploads dir (like _wp_attached_file). */
	public static function rel( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$path    = wp_normalize_path( $path );
		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
	}

	private static function update_post_file_fields( $id, $file, $mime ) {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		$guid    = trailingslashit( $uploads['baseurl'] ) . self::rel( $file );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime, 'guid' => $guid ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}

	public static function format_bytes( $b ) {
		return size_format( (int) $b, 1 );
	}
}
