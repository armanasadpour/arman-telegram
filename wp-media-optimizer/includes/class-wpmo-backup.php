<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reversible file storage.
 *
 * Files are MOVED (not copied) to uploads/wpmo-backup/{attachment_id}/{kind}/{relative path}
 * so they can be restored exactly where they were, or deleted for good after admin approval.
 *   kind = convert    : originals replaced by a converted file (waiting for approval)
 *   kind = quarantine : unused media removed from the site (waiting for permanent delete)
 */
class WPMO_Backup {

	const DIR = 'wpmo-backup';

	public static function base() {
		$u = wp_get_upload_dir();
		$d = wp_normalize_path( trailingslashit( $u['basedir'] ) . self::DIR );
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		if ( ! file_exists( $d . '/.htaccess' ) ) {
			@file_put_contents( $d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $d;
	}

	/** Path relative to uploads dir (like _wp_attached_file). */
	public static function rel( $path ) {
		$u    = wp_get_upload_dir();
		$base = wp_normalize_path( trailingslashit( $u['basedir'] ) );
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
	}

	/** Absolute path from uploads-relative path. */
	public static function abs( $rel ) {
		$u = wp_get_upload_dir();
		return wp_normalize_path( trailingslashit( $u['basedir'] ) . ltrim( $rel, '/' ) );
	}

	public static function path( $id, $kind, $rel = '' ) {
		return self::base() . '/' . (int) $id . '/' . $kind . ( '' !== $rel ? '/' . ltrim( $rel, '/' ) : '' );
	}

	public static function move( $from, $to ) {
		wp_mkdir_p( dirname( $to ) );
		if ( @rename( $from, $to ) ) {
			return true;
		}
		if ( @copy( $from, $to ) ) {
			@unlink( $from );
			return true;
		}
		return false;
	}

	/**
	 * Move files into the backup area.
	 *
	 * @param array $abs_files Absolute paths inside uploads.
	 * @return array|WP_Error  Relative paths that were moved.
	 */
	public static function stash( $id, $kind, $abs_files ) {
		$moved = array();
		foreach ( $abs_files as $f ) {
			if ( ! file_exists( $f ) ) {
				continue;
			}
			$rel = self::rel( $f );
			if ( ! self::move( $f, self::path( $id, $kind, $rel ) ) ) {
				self::unstash( $id, $kind, $moved ); // Roll back what we already moved.
				return new WP_Error( 'wpmo_stash', 'انتقال فایل به پشتیبان ناموفق بود: ' . $rel );
			}
			$moved[] = $rel;
		}
		return $moved;
	}

	/**
	 * Move files back from the backup area to their original place.
	 *
	 * @return true|WP_Error
	 */
	public static function unstash( $id, $kind, $rels ) {
		foreach ( $rels as $rel ) {
			$src = self::path( $id, $kind, $rel );
			$dst = self::abs( $rel );
			if ( ! file_exists( $src ) ) {
				return new WP_Error( 'wpmo_missing_backup', 'فایل پشتیبان پیدا نشد: ' . $rel );
			}
			if ( file_exists( $dst ) ) {
				return new WP_Error( 'wpmo_conflict', 'فایل دیگری با همین نام در مسیر اصلی وجود دارد: ' . $rel );
			}
		}
		foreach ( $rels as $rel ) {
			if ( ! self::move( self::path( $id, $kind, $rel ), self::abs( $rel ) ) ) {
				return new WP_Error( 'wpmo_unstash', 'بازگردانی فایل ناموفق بود: ' . $rel );
			}
		}
		return true;
	}

	public static function size( $id, $kind, $rels ) {
		$s = 0;
		foreach ( (array) $rels as $rel ) {
			$p  = self::path( $id, $kind, $rel );
			$s += file_exists( $p ) ? filesize( $p ) : 0;
		}
		return $s;
	}

	/** Delete one kind (or everything) of an attachment's backup. */
	public static function remove( $id, $kind = '' ) {
		$dir = self::base() . '/' . (int) $id . ( $kind ? '/' . $kind : '' );
		self::rrmdir( $dir );
		$parent = self::base() . '/' . (int) $id;
		if ( is_dir( $parent ) && count( scandir( $parent ) ) <= 2 ) {
			@rmdir( $parent );
		}
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}

	/** Remove upload stashes that were never linked to an attachment (older than 1 day). */
	public static function cleanup_upload_tmp() {
		$d = self::base() . '/_upload';
		if ( ! is_dir( $d ) ) {
			return;
		}
		foreach ( glob( $d . '/*', GLOB_ONLYDIR ) as $sub ) {
			if ( filemtime( $sub ) < time() - DAY_IN_SECONDS ) {
				self::rrmdir( $sub );
			}
		}
	}
}
