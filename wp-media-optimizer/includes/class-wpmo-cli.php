<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: bulk optimize all media without browser timeouts.
 *
 *   wp media-optimizer status
 *   wp media-optimizer run [--type=<all|image|video>] [--retry-errors]
 */
class WPMO_CLI {

	/** Show how many items are pending. */
	public function status() {
		WP_CLI::log( 'تصاویر در انتظار: ' . count( WPMO_Optimizer::pending_ids( 'image' ) ) );
		WP_CLI::log( 'ویدیوهای در انتظار: ' . count( WPMO_Optimizer::pending_ids( 'video' ) ) );
		WP_CLI::log( 'WebP: ' . ( WPMO_Image::supported() ? 'OK' : 'NOT SUPPORTED' ) );
		WP_CLI::log( 'FFmpeg: ' . ( WPMO_Video::supported() ? 'OK' : 'NOT AVAILABLE' ) );
	}

	/**
	 * Optimize all existing media.
	 *
	 * [--type=<type>]
	 * : all, image or video. Default all.
	 *
	 * [--retry-errors]
	 * : Retry items that failed before.
	 */
	public function run( $args, $assoc ) {
		if ( ! empty( $assoc['retry-errors'] ) ) {
			WPMO_Optimizer::reset_errors();
		}
		$type = isset( $assoc['type'] ) ? $assoc['type'] : 'all';
		$ids  = WPMO_Optimizer::pending_ids( $type );
		$saved = 0;

		foreach ( $ids as $i => $id ) {
			$r = WPMO_Optimizer::optimize( $id );
			$n = sprintf( '[%d/%d] #%d', $i + 1, count( $ids ), $id );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( $n . ' ' . $r->get_error_message() );
				continue;
			}
			$saved += max( 0, $r['old_size'] - $r['new_size'] );
			WP_CLI::log( sprintf( '%s %s  %s → %s', $n, $r['file'], size_format( $r['old_size'], 1 ), size_format( $r['new_size'], 1 ) ) );
		}
		WP_CLI::success( 'تمام شد. فضای آزاد شده: ' . size_format( $saved, 1 ) );
	}
}
