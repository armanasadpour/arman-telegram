<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin options with sane defaults.
 */
class WPMO_Settings {

	const OPTION = 'wpmo_settings';

	public static function defaults() {
		return array(
			'convert_on_upload'  => 1,   // Convert images to WebP right after upload.
			'webp_quality'       => 82,  // 0-100. 80-85 is visually lossless for photos.
			'png_lossless'       => 0,   // Use lossless WebP for PNG (bigger but pixel-perfect).
			'skip_animated_gif'  => 1,   // Animated GIFs would lose animation.
			'max_image_width'    => 2560,// 0 = no resize. WordPress "big image" threshold.
			'compress_videos'    => 1,   // Compress videos after upload (background).
			'ffmpeg_path'        => 'ffmpeg',
			'video_crf'          => 26,  // x264 CRF: 18 = near-lossless, 23 = default, 28 = small.
			'video_preset'       => 'slow',
			'video_max_width'    => 1920,// 0 = keep resolution.
			'audio_bitrate'      => 128, // kbps.
			'delete_originals'   => 1,   // Remove the original file after a successful conversion.
		);
	}

	public static function install_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = array();

		foreach ( array( 'convert_on_upload', 'png_lossless', 'skip_animated_gif', 'compress_videos', 'delete_originals' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}

		$out['webp_quality']    = max( 1, min( 100, (int) ( $input['webp_quality'] ?? $d['webp_quality'] ) ) );
		$out['max_image_width'] = max( 0, (int) ( $input['max_image_width'] ?? $d['max_image_width'] ) );
		$out['video_crf']       = max( 0, min( 51, (int) ( $input['video_crf'] ?? $d['video_crf'] ) ) );
		$out['video_max_width'] = max( 0, (int) ( $input['video_max_width'] ?? $d['video_max_width'] ) );
		$out['audio_bitrate']   = max( 32, min( 320, (int) ( $input['audio_bitrate'] ?? $d['audio_bitrate'] ) ) );

		$presets             = array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' );
		$preset              = $input['video_preset'] ?? $d['video_preset'];
		$out['video_preset'] = in_array( $preset, $presets, true ) ? $preset : $d['video_preset'];

		// Only allow a plain executable path (no shell metacharacters).
		$path               = trim( (string) ( $input['ffmpeg_path'] ?? $d['ffmpeg_path'] ) );
		$out['ffmpeg_path'] = preg_match( '#^[A-Za-z0-9_./:\\\\ -]+$#', $path ) ? $path : $d['ffmpeg_path'];

		return $out;
	}
}
