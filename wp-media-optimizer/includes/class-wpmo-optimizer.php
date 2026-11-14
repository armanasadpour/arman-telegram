<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core workflow:
 *   1. Scan     – find unused media (WPMO_Usage) → quarantine (reversible) → purge (permanent).
 *   2. Convert  – images → WebP, videos → compressed MP4. Originals are moved to a backup.
 *   3. Approve  – admin approves: originals deleted.  Revert: originals restored, links restored.
 */
class WPMO_Optimizer {

	const META_DONE       = '_wpmo_optimized';
	const META_ERROR      = '_wpmo_error';
	const META_BACKUP     = '_wpmo_backup';      // Conversion waiting for approval.
	const META_REVERTED   = '_wpmo_reverted';    // Admin rejected the conversion – don't convert again.
	const META_QUARANTINE = '_wpmo_quarantine';  // Unused file moved out of the site.
	const CRON_HOOK       = 'wpmo_compress_video';

	/** Upload-time originals waiting for their attachment ID. new file => info. */
	private static $upload_stash = array();

	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_handle_upload' ), 5, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'on_add_attachment' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_compress_video' ) );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'big_image_threshold' ), 20 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		// Hide quarantined media from the media library.
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'hide_quarantined_ajax' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_quarantined_list' ) );
	}

	public static function big_image_threshold( $threshold ) {
		$w = (int) WPMO_Settings::get( 'max_image_width' );
		return $w > 0 ? $w : false;
	}

	public static function on_delete_attachment( $id ) {
		WPMO_Backup::remove( $id );
	}

	private static function not_quarantined_clause() {
		return array(
			'key'     => self::META_QUARANTINE,
			'compare' => 'NOT EXISTS',
		);
	}

	public static function hide_quarantined_ajax( $q ) {
		$q['meta_query']   = isset( $q['meta_query'] ) ? (array) $q['meta_query'] : array();
		$q['meta_query'][] = self::not_quarantined_clause();
		return $q;
	}

	public static function hide_quarantined_list( $q ) {
		global $pagenow;
		if ( is_admin() && $q->is_main_query() && 'upload.php' === $pagenow ) {
			$mq   = (array) $q->get( 'meta_query' );
			$mq[] = self::not_quarantined_clause();
			$q->set( 'meta_query', $mq );
		}
	}

	/* ================================================================== *
	 * Upload time
	 * ================================================================== */

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

		if ( WPMO_Settings::get( 'require_approval' ) ) {
			// Park the original until the attachment exists, then file it under its ID.
			$tmp = WPMO_Backup::base() . '/_upload/' . wp_generate_password( 12, false ) . '/' . wp_basename( $upload['file'] );
			if ( WPMO_Backup::move( $upload['file'], $tmp ) ) {
				self::$upload_stash[ wp_normalize_path( $new ) ] = array(
					'tmp'  => $tmp,
					'orig' => wp_normalize_path( $upload['file'] ),
					'mime' => $upload['type'],
				);
			}
		} else {
			wp_delete_file( $upload['file'] );
		}

		$upload['file'] = $new;
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . wp_basename( $new );
		$upload['type'] = 'image/webp';
		return $upload;
	}

	public static function on_add_attachment( $id ) {
		$file = wp_normalize_path( (string) get_attached_file( $id ) );

		if ( isset( self::$upload_stash[ $file ] ) ) {
			$s   = self::$upload_stash[ $file ];
			$rel = WPMO_Backup::rel( $s['orig'] );
			unset( self::$upload_stash[ $file ] );

			if ( WPMO_Backup::move( $s['tmp'], WPMO_Backup::path( $id, 'convert', $rel ) ) ) {
				@rmdir( dirname( $s['tmp'] ) );
				update_post_meta( $id, self::META_BACKUP, array(
					'files'        => array( $rel ),
					'old_attached' => $rel,
					'old_meta'     => array(), // Sizes are regenerated on revert.
					'old_mime'     => $s['mime'],
					'source'       => 'upload',
					'time'         => time(),
				) );
				update_post_meta( $id, self::META_DONE, array(
					'old'  => WPMO_Backup::size( $id, 'convert', array( $rel ) ),
					'new'  => file_exists( $file ) ? filesize( $file ) : 0,
					'time' => time(),
				) );
			}
		}

		if ( WPMO_Settings::get( 'compress_videos' ) && WPMO_Video::is_convertible( get_post_mime_type( $id ) ) ) {
			// Run outside the upload request so the upload stays fast.
			wp_schedule_single_event( time() + 10, self::CRON_HOOK, array( (int) $id ) );
		}
	}

	public static function cron_compress_video( $id ) {
		self::optimize_video( (int) $id );
	}

	/* ================================================================== *
	 * Queues
	 * ================================================================== */

	private static function mimes( $type ) {
		$mimes = array();
		if ( 'all' === $type || 'image' === $type ) {
			$mimes = array_merge( $mimes, WPMO_Image::$convertible );
		}
		if ( 'all' === $type || 'video' === $type ) {
			$mimes = array_merge( $mimes, WPMO_Video::$convertible );
		}
		return $mimes;
	}

	/** Attachments that still need conversion (step 2). */
	public static function pending_ids( $type = 'all', $skip_unused = null ) {
		global $wpdb;
		if ( null === $skip_unused ) {
			$skip_unused = (bool) WPMO_Settings::get( 'skip_unused' );
		}
		$mimes = self::mimes( $type );
		$in    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		$exclude = array( self::META_DONE, self::META_ERROR, self::META_REVERTED, self::META_QUARANTINE, self::META_BACKUP );
		$joins   = '';
		$conds   = '';
		foreach ( $exclude as $i => $k ) {
			$joins .= " LEFT JOIN {$wpdb->postmeta} x{$i} ON x{$i}.post_id = p.ID AND x{$i}.meta_key = %s";
			$conds .= " AND x{$i}.meta_id IS NULL";
		}
		$args = $exclude;
		if ( $skip_unused ) {
			$joins .= " LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s";
			$conds .= " AND ( u.meta_value IS NULL OR u.meta_value <> 'unused' )";
			$args[] = WPMO_Usage::META;
		}

		$sql = "SELECT p.ID FROM {$wpdb->posts} p {$joins}
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($in) {$conds}
			ORDER BY p.ID ASC";

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $args, $mimes ) ) ) ); // phpcs:ignore
	}

	/** All images & videos to scan for usage (step 1). */
	public static function scan_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s
			 WHERE p.post_type = 'attachment' AND ( p.post_mime_type LIKE 'image/%%' OR p.post_mime_type LIKE 'video/%%' )
			 AND q.meta_id IS NULL ORDER BY p.ID ASC",
			self::META_QUARANTINE
		) ) );
	}

	/** IDs having a given meta key (optionally with a value). */
	public static function ids_with_meta( $key, $value = null ) {
		global $wpdb;
		if ( null === $value ) {
			$sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", $key );
		} else {
			$sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id", $key, $value );
		}
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore
	}

	/** Unused (scanned) media that are still on the site. */
	public static function unused_ids() {
		return array_values( array_diff( self::ids_with_meta( WPMO_Usage::META, 'unused' ), self::ids_with_meta( self::META_QUARANTINE ) ) );
	}

	public static function reset_errors( $also_reverted = false ) {
		delete_post_meta_by_key( self::META_ERROR );
		if ( $also_reverted ) {
			delete_post_meta_by_key( self::META_REVERTED );
		}
	}

	/* ================================================================== *
	 * Step 2: Convert (originals → backup)
	 * ================================================================== */

	/** @return array|WP_Error */
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

	public static function optimize_image( $id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( get_post_meta( $id, self::META_QUARANTINE, true ) ) {
			return new WP_Error( 'wpmo_q', 'این فایل در قرنطینه است.' );
		}

		$attached = get_attached_file( $id );
		$original = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : $attached;
		$source   = ( $original && file_exists( $original ) ) ? $original : $attached;
		if ( ! $source || ! file_exists( $source ) ) {
			return self::fail( $id, 'فایل روی دیسک وجود ندارد.' );
		}

		$old_meta  = wp_get_attachment_metadata( $id );
		$old_mime  = get_post_mime_type( $id );
		$old_files = self::attachment_files( $old_meta, $attached );
		$old_size  = self::files_size( $old_files );

		$new = WPMO_Image::convert( $source, $old_mime );
		if ( is_wp_error( $new ) ) {
			return self::fail( $id, $new->get_error_message() );
		}

		update_attached_file( $id, $new );
		self::update_post_file_fields( $id, $new, 'image/webp' );

		$meta = wp_generate_attachment_metadata( $id, $new );
		if ( empty( $meta ) || is_wp_error( $meta ) ) {
			self::rollback( $id, array( $new ), $attached, $old_mime, $old_meta );
			return self::fail( $id, 'ساخت سایزهای تصویر ناموفق بود.' );
		}
		if ( ! empty( $old_meta['image_meta'] ) && empty( $meta['image_meta'] ) ) {
			$meta['image_meta'] = $old_meta['image_meta'];
		}
		wp_update_attachment_metadata( $id, $meta );

		$new_attached = get_attached_file( $id );
		$new_files    = self::attachment_files( $meta, $new_attached );

		// Originals → backup (reversible).
		$moved = WPMO_Backup::stash( $id, 'convert', array_diff( $old_files, $new_files ) );
		if ( is_wp_error( $moved ) ) {
			self::rollback( $id, $new_files, $attached, $old_mime, $old_meta );
			return self::fail( $id, $moved->get_error_message() );
		}

		$changed = WPMO_Replacer::replace_everywhere( self::size_map( $old_meta, $attached, $meta, $new_attached ) );

		update_post_meta( $id, self::META_BACKUP, array(
			'files'        => $moved,
			'old_attached' => WPMO_Backup::rel( $attached ),
			'old_meta'     => $old_meta,
			'old_mime'     => $old_mime,
			'source'       => 'bulk',
			'time'         => time(),
		) );

		$new_size = self::files_size( $new_files );
		update_post_meta( $id, self::META_DONE, array( 'old' => $old_size, 'new' => $new_size, 'time' => time() ) );
		delete_post_meta( $id, self::META_ERROR );

		if ( ! WPMO_Settings::get( 'require_approval' ) ) {
			self::approve( $id );
		}

		return array(
			'id'       => $id,
			'type'     => 'image',
			'file'     => wp_basename( $new_attached ),
			'old_size' => $old_size,
			'new_size' => $new_size,
			'refs'     => $changed,
		);
	}

	public static function optimize_video( $id ) {
		if ( get_post_meta( $id, self::META_DONE, true ) || get_post_meta( $id, self::META_QUARANTINE, true ) ) {
			return new WP_Error( 'wpmo_done', 'قبلاً پردازش شده یا در قرنطینه است.' );
		}
		$src = get_attached_file( $id );
		if ( ! $src || ! file_exists( $src ) ) {
			return self::fail( $id, 'فایل روی دیسک وجود ندارد.' );
		}

		$res = WPMO_Video::compress( $src );
		if ( is_wp_error( $res ) ) {
			return self::fail( $id, $res->get_error_message() );
		}

		$is_mp4 = (bool) preg_match( '/\.mp4$/i', $src );

		// Already optimal MP4: nothing to gain, keep the original.
		if ( $is_mp4 && $res['new_size'] >= $res['old_size'] ) {
			@unlink( $res['tmp'] );
			update_post_meta( $id, self::META_DONE, array( 'old' => $res['old_size'], 'new' => $res['old_size'], 'time' => time() ) );
			delete_post_meta( $id, self::META_ERROR );
			return array(
				'id'       => $id,
				'type'     => 'video',
				'file'     => wp_basename( $src ),
				'old_size' => $res['old_size'],
				'new_size' => $res['old_size'],
				'skipped'  => true,
			);
		}

		$old_meta = wp_get_attachment_metadata( $id );
		$old_mime = get_post_mime_type( $id );

		$moved = WPMO_Backup::stash( $id, 'convert', array( $src ) );
		if ( is_wp_error( $moved ) ) {
			@unlink( $res['tmp'] );
			return self::fail( $id, $moved->get_error_message() );
		}

		$dir  = dirname( $src );
		$dest = $is_mp4 ? $src : $dir . '/' . wp_unique_filename( $dir, preg_replace( '/\.[^.]+$/', '', wp_basename( $src ) ) . '.mp4' );
		if ( ! @rename( $res['tmp'], $dest ) ) {
			@unlink( $res['tmp'] );
			WPMO_Backup::unstash( $id, 'convert', $moved );
			WPMO_Backup::remove( $id, 'convert' );
			return self::fail( $id, 'جایگزینی فایل ویدیو ناموفق بود.' );
		}

		if ( $dest !== $src ) {
			update_attached_file( $id, $dest );
			self::update_post_file_fields( $id, $dest, 'video/mp4' );
			WPMO_Replacer::replace_everywhere( array( WPMO_Backup::rel( $src ) => WPMO_Backup::rel( $dest ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $id, $dest );
		if ( $meta && ! is_wp_error( $meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
		}

		update_post_meta( $id, self::META_BACKUP, array(
			'files'        => $moved,
			'old_attached' => WPMO_Backup::rel( $src ),
			'old_meta'     => $old_meta,
			'old_mime'     => $old_mime,
			'source'       => 'bulk',
			'time'         => time(),
		) );
		update_post_meta( $id, self::META_DONE, array( 'old' => $res['old_size'], 'new' => $res['new_size'], 'time' => time() ) );
		delete_post_meta( $id, self::META_ERROR );

		if ( ! WPMO_Settings::get( 'require_approval' ) ) {
			self::approve( $id );
		}

		return array(
			'id'       => $id,
			'type'     => 'video',
			'file'     => wp_basename( $dest ),
			'old_size' => $res['old_size'],
			'new_size' => $res['new_size'],
			'skipped'  => false,
		);
	}

	/* ================================================================== *
	 * Step 3: Approve / Revert
	 * ================================================================== */

	/** Admin approved: delete the originals for good. */
	public static function approve( $id ) {
		if ( ! get_post_meta( $id, self::META_BACKUP, true ) ) {
			return new WP_Error( 'wpmo_nobackup', 'موردی برای تایید وجود ندارد.' );
		}
		WPMO_Backup::remove( $id, 'convert' );
		delete_post_meta( $id, self::META_BACKUP );
		return true;
	}

	/** Admin rejected: restore originals, metadata and every link on the site. */
	public static function revert( $id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$m = get_post_meta( $id, self::META_BACKUP, true );
		if ( empty( $m['files'] ) || empty( $m['old_attached'] ) ) {
			return new WP_Error( 'wpmo_nobackup', 'نسخه پشتیبان برای این فایل وجود ندارد.' );
		}
		foreach ( $m['files'] as $rel ) {
			if ( ! file_exists( WPMO_Backup::path( $id, 'convert', $rel ) ) ) {
				return new WP_Error( 'wpmo_missing_backup', 'فایل پشتیبان پیدا نشد: ' . $rel );
			}
		}

		$cur_attached = get_attached_file( $id );
		$cur_meta     = wp_get_attachment_metadata( $id );
		$cur_files    = self::attachment_files( $cur_meta, $cur_attached );

		foreach ( $m['files'] as $rel ) {
			$t = WPMO_Backup::abs( $rel );
			if ( file_exists( $t ) && ! in_array( $t, $cur_files, true ) ) {
				return new WP_Error( 'wpmo_conflict', 'فایل دیگری با همین نام در مسیر اصلی وجود دارد: ' . $rel );
			}
		}

		// Move converted files aside first, so we can undo if anything fails.
		$aside = WPMO_Backup::stash( $id, 'revert-tmp', $cur_files );
		if ( is_wp_error( $aside ) ) {
			return $aside;
		}
		$ok = WPMO_Backup::unstash( $id, 'convert', $m['files'] );
		if ( is_wp_error( $ok ) ) {
			WPMO_Backup::unstash( $id, 'revert-tmp', $aside );
			WPMO_Backup::remove( $id, 'revert-tmp' );
			return $ok;
		}
		WPMO_Backup::remove( $id, 'revert-tmp' );

		$old_main = WPMO_Backup::abs( $m['old_attached'] );
		update_attached_file( $id, $old_main );
		self::update_post_file_fields( $id, $old_main, $m['old_mime'] );

		if ( ! empty( $m['old_meta'] ) ) {
			$meta = $m['old_meta'];
		} else {
			// Upload-time conversion: original sizes never existed, build them now.
			$meta = wp_generate_attachment_metadata( $id, $old_main );
		}
		wp_update_attachment_metadata( $id, $meta );

		WPMO_Replacer::replace_everywhere( self::size_map( $cur_meta, $cur_attached, $meta, get_attached_file( $id ) ) );

		delete_post_meta( $id, self::META_BACKUP );
		delete_post_meta( $id, self::META_DONE );
		update_post_meta( $id, self::META_REVERTED, time() );
		WPMO_Backup::remove( $id, 'convert' );
		return true;
	}

	/* ================================================================== *
	 * Step 1: Unused media → quarantine → purge / restore
	 * ================================================================== */

	public static function quarantine( $id ) {
		if ( get_post_meta( $id, self::META_QUARANTINE, true ) ) {
			return new WP_Error( 'wpmo_q', 'قبلاً در قرنطینه است.' );
		}
		// Re-check right now: it may have been inserted somewhere since the scan.
		$u = WPMO_Usage::check_and_store( $id );
		if ( $u['used'] ) {
			return new WP_Error( 'wpmo_used', 'این فایل اکنون استفاده می‌شود: ' . $u['where'] );
		}
		$attached = get_attached_file( $id );
		$files    = self::attachment_files( wp_get_attachment_metadata( $id ), $attached );
		$moved    = WPMO_Backup::stash( $id, 'quarantine', $files );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		update_post_meta( $id, self::META_QUARANTINE, array(
			'files' => $moved,
			'main'  => WPMO_Backup::rel( $attached ),
			'time'  => time(),
		) );
		return true;
	}

	public static function restore( $id ) {
		$q = get_post_meta( $id, self::META_QUARANTINE, true );
		if ( ! is_array( $q ) ) {
			return new WP_Error( 'wpmo_noq', 'این فایل در قرنطینه نیست.' );
		}
		$ok = WPMO_Backup::unstash( $id, 'quarantine', $q['files'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		WPMO_Backup::remove( $id, 'quarantine' );
		delete_post_meta( $id, self::META_QUARANTINE );
		update_post_meta( $id, WPMO_Usage::META, 'kept' ); // Admin chose to keep it.
		return true;
	}

	/** Permanently delete a quarantined attachment. */
	public static function purge( $id ) {
		if ( ! get_post_meta( $id, self::META_QUARANTINE, true ) ) {
			return new WP_Error( 'wpmo_noq', 'فقط فایل‌های قرنطینه‌شده قابل حذف دائمی هستند.' );
		}
		WPMO_Backup::remove( $id );
		// Detach file info so WordPress doesn't touch a file that may now belong to another upload.
		delete_post_meta( $id, '_wp_attached_file' );
		delete_post_meta( $id, '_wp_attachment_metadata' );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );
		return wp_delete_attachment( $id, true ) ? true : new WP_Error( 'wpmo_del', 'حذف ناموفق بود.' );
	}

	/** Run one workflow action. */
	public static function run_action( $op, $id ) {
		switch ( $op ) {
			case 'convert':
				return self::optimize( $id );
			case 'approve':
				return self::approve( $id );
			case 'revert':
				return self::revert( $id );
			case 'quarantine':
				return self::quarantine( $id );
			case 'restore':
				return self::restore( $id );
			case 'purge':
				return self::purge( $id );
			case 'scan':
				return WPMO_Usage::check_and_store( $id );
		}
		return new WP_Error( 'wpmo_op', 'عملیات نامعتبر.' );
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	private static function fail( $id, $msg ) {
		update_post_meta( $id, self::META_ERROR, $msg );
		return new WP_Error( 'wpmo_fail', $msg );
	}

	private static function rollback( $id, $new_files, $attached, $old_mime, $old_meta ) {
		foreach ( $new_files as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
		update_attached_file( $id, $attached );
		self::update_post_file_fields( $id, $attached, $old_mime );
		wp_update_attachment_metadata( $id, $old_meta );
	}

	/** All files on disk that belong to an attachment. */
	public static function attachment_files( $meta, $attached ) {
		$files = array();
		if ( ! $attached ) {
			return $files;
		}
		$attached = wp_normalize_path( $attached );
		$dir      = dirname( $attached );
		$files[]  = $attached;
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
		return array_values( array_filter( array_unique( $files ), 'file_exists' ) );
	}

	private static function files_size( $files ) {
		$s = 0;
		foreach ( $files as $f ) {
			$s += file_exists( $f ) ? filesize( $f ) : 0;
		}
		return $s;
	}

	/**
	 * Map every file of "from" (main, original, each size) to the matching file of "to" (by size name).
	 * Sizes that don't exist in "to" map to its main file. Returns uploads-relative paths.
	 */
	private static function size_map( $from_meta, $from_attached, $to_meta, $to_attached ) {
		$fd   = dirname( wp_normalize_path( $from_attached ) );
		$td   = dirname( wp_normalize_path( $to_attached ) );
		$main = WPMO_Backup::rel( $to_attached );
		$map  = array( WPMO_Backup::rel( $from_attached ) => $main );

		if ( is_array( $from_meta ) && ! empty( $from_meta['original_image'] ) ) {
			$map[ WPMO_Backup::rel( $fd . '/' . $from_meta['original_image'] ) ] = ! empty( $to_meta['original_image'] )
				? WPMO_Backup::rel( $td . '/' . $to_meta['original_image'] )
				: $main;
		}
		if ( is_array( $from_meta ) && ! empty( $from_meta['sizes'] ) ) {
			foreach ( $from_meta['sizes'] as $name => $s ) {
				if ( empty( $s['file'] ) ) {
					continue;
				}
				$map[ WPMO_Backup::rel( $fd . '/' . $s['file'] ) ] = ! empty( $to_meta['sizes'][ $name ]['file'] )
					? WPMO_Backup::rel( $td . '/' . $to_meta['sizes'][ $name ]['file'] )
					: $main;
			}
		}
		return $map;
	}

	private static function update_post_file_fields( $id, $file, $mime ) {
		global $wpdb;
		$u    = wp_get_upload_dir();
		$guid = trailingslashit( $u['baseurl'] ) . WPMO_Backup::rel( $file );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime, 'guid' => $guid ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}
}
