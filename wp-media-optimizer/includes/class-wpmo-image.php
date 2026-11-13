<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low level image -> WebP conversion using the WordPress image editor (Imagick or GD).
 */
class WPMO_Image {

	public static $convertible = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff' );

	public static function supported() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	public static function is_convertible( $mime ) {
		return in_array( $mime, self::$convertible, true );
	}

	public static function is_animated_gif( $file ) {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$count = 0;
		while ( ! feof( $fh ) && $count < 2 ) {
			$chunk  = fread( $fh, 1024 * 100 );
			$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk );
		}
		fclose( $fh );
		return $count > 1;
	}

	/**
	 * Convert $src into a .webp file next to it.
	 *
	 * @return string|WP_Error Path of the new webp file.
	 */
	public static function convert( $src, $mime = '' ) {
		if ( ! file_exists( $src ) ) {
			return new WP_Error( 'wpmo_missing', 'فایل پیدا نشد: ' . $src );
		}
		if ( ! $mime ) {
			$ft   = wp_check_filetype( $src );
			$mime = $ft['type'];
		}
		if ( ! self::is_convertible( $mime ) ) {
			return new WP_Error( 'wpmo_type', 'نوع فایل قابل تبدیل نیست: ' . $mime );
		}
		if ( 'image/gif' === $mime && WPMO_Settings::get( 'skip_animated_gif' ) && self::is_animated_gif( $src ) ) {
			return new WP_Error( 'wpmo_animated', 'GIF متحرک رد شد.' );
		}
		if ( ! self::supported() ) {
			return new WP_Error( 'wpmo_nosupport', 'سرور از WebP پشتیبانی نمی‌کند (Imagick یا GD با WebP لازم است).' );
		}

		$editor = wp_get_image_editor( $src );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		// Fix EXIF orientation of camera photos.
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}

		$quality = (int) WPMO_Settings::get( 'webp_quality' );
		if ( 'image/png' === $mime && WPMO_Settings::get( 'png_lossless' ) ) {
			$quality = 100;
		}
		$editor->set_quality( $quality );

		$dir  = dirname( $src );
		$name = wp_basename( $src );
		$stem = preg_replace( '/\.[^.]+$/', '', $name );
		$dest = $dir . '/' . wp_unique_filename( $dir, $stem . '.webp' );

		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( empty( $saved['path'] ) || ! file_exists( $saved['path'] ) || 0 === filesize( $saved['path'] ) ) {
			return new WP_Error( 'wpmo_save', 'ذخیره WebP ناموفق بود.' );
		}

		// WP image editors strip EXIF/metadata by default (image_strip_meta filter).
		return $saved['path'];
	}
}
