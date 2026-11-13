<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video compression with FFmpeg (H.264 + AAC, MP4, faststart).
 */
class WPMO_Video {

	/** Formats re-encoded to MP4. WebM/OGV are already efficient, they are left alone. */
	public static $convertible = array( 'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/avi', 'video/x-ms-wmv', 'video/x-matroska', 'video/x-m4v', 'video/3gpp', 'video/3gpp2', 'video/x-flv', 'video/mpeg' );

	public static function exec_available() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	public static function ffmpeg() {
		return WPMO_Settings::get( 'ffmpeg_path' );
	}

	public static function supported() {
		static $ok = null;
		if ( null !== $ok ) {
			return $ok;
		}
		if ( ! self::exec_available() ) {
			return $ok = false;
		}
		$out = array();
		$ret = 1;
		@exec( escapeshellarg( self::ffmpeg() ) . ' -hide_banner -version 2>&1', $out, $ret );
		return $ok = ( 0 === $ret );
	}

	public static function is_convertible( $mime ) {
		return in_array( $mime, self::$convertible, true );
	}

	/**
	 * Compress a video. Result is always an .mp4 file.
	 *
	 * @return array|WP_Error { path: new file path, replaced: bool (false = result was not smaller, original kept) }
	 */
	public static function compress( $src ) {
		if ( ! file_exists( $src ) ) {
			return new WP_Error( 'wpmo_missing', 'فایل پیدا نشد: ' . $src );
		}
		if ( ! self::supported() ) {
			return new WP_Error( 'wpmo_noffmpeg', 'FFmpeg روی سرور در دسترس نیست یا تابع exec غیرفعال است.' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		@ignore_user_abort( true );

		$dir  = dirname( $src );
		$stem = preg_replace( '/\.[^.]+$/', '', wp_basename( $src ) );
		$tmp  = $dir . '/' . $stem . '.wpmo-tmp-' . wp_generate_password( 6, false ) . '.mp4';

		$crf    = (int) WPMO_Settings::get( 'video_crf' );
		$preset = WPMO_Settings::get( 'video_preset' );
		$maxw   = (int) WPMO_Settings::get( 'video_max_width' );
		$abr    = (int) WPMO_Settings::get( 'audio_bitrate' );

		// Downscale only if wider than max, keep aspect, force even dimensions.
		$vf = $maxw > 0
			? "scale='trunc(min({$maxw},iw)/2)*2':-2"
			: "scale='trunc(iw/2)*2':'trunc(ih/2)*2'";

		$cmd = escapeshellarg( self::ffmpeg() )
			. ' -hide_banner -loglevel error -y -i ' . escapeshellarg( $src )
			. ' -map 0:v:0 -map 0:a:0? -map_metadata -1'
			. ' -c:v libx264 -preset ' . escapeshellarg( $preset ) . ' -crf ' . $crf
			. ' -profile:v high -pix_fmt yuv420p -vf ' . escapeshellarg( $vf )
			. ' -c:a aac -b:a ' . $abr . 'k -ac 2'
			. ' -movflags +faststart '
			. escapeshellarg( $tmp ) . ' 2>&1';

		$out = array();
		$ret = 1;
		exec( $cmd, $out, $ret );

		if ( 0 !== $ret || ! file_exists( $tmp ) || filesize( $tmp ) < 1024 ) {
			@unlink( $tmp );
			return new WP_Error( 'wpmo_ffmpeg', 'خطای FFmpeg: ' . implode( "\n", array_slice( $out, -5 ) ) );
		}

		$is_mp4   = (bool) preg_match( '/\.mp4$/i', $src );
		$old_size = filesize( $src );
		$new_size = filesize( $tmp );

		// Already-optimized MP4: keep the original when we couldn't make it smaller.
		if ( $is_mp4 && $new_size >= $old_size ) {
			@unlink( $tmp );
			return array(
				'path'     => $src,
				'replaced' => false,
				'old_size' => $old_size,
				'new_size' => $old_size,
			);
		}

		if ( $is_mp4 ) {
			$dest = $src; // Same name, overwrite in place.
		} else {
			$dest = $dir . '/' . wp_unique_filename( $dir, $stem . '.mp4' );
		}

		if ( ! @rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			return new WP_Error( 'wpmo_rename', 'جایگزینی فایل ویدیو ناموفق بود.' );
		}

		return array(
			'path'     => $dest,
			'replaced' => true,
			'old_size' => $old_size,
			'new_size' => $new_size,
		);
	}
}
