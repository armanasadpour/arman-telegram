<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step-by-step media optimization from the command line.
 *
 *   wp media-optimizer status
 *   wp media-optimizer scan                      # step 1: find unused media
 *   wp media-optimizer quarantine [--yes]        # step 1: move unused media to quarantine (reversible)
 *   wp media-optimizer convert [--type=image|video|all] [--retry-errors] [--include-unused]
 *   wp media-optimizer approve <ids>...|--all    # step 3: delete originals
 *   wp media-optimizer revert  <ids>...|--all    # step 3: restore originals & links
 *   wp media-optimizer restore <ids>...|--all    # bring quarantined media back
 *   wp media-optimizer purge   <ids>...|--all [--yes]  # permanently delete quarantined media
 */
class WPMO_CLI {

	/** Show the state of every step. */
	public function status() {
		$s = WPMO_Admin::stats();
		WP_CLI::log( 'WebP: ' . ( WPMO_Image::supported() ? 'OK' : 'NOT SUPPORTED' ) . ' | FFmpeg: ' . ( WPMO_Video::supported() ? 'OK' : 'NOT AVAILABLE' ) );
		WP_CLI::log( "۱) بررسی‌شده: {$s['scanned']} | بدون استفاده: {$s['unused']}" );
		WP_CLI::log( "۲) در انتظار تبدیل: تصویر {$s['images']} | ویدیو {$s['videos']} | خطا {$s['errors']}" );
		WP_CLI::log( "۳) منتظر تایید: {$s['pending']} | قرنطینه: {$s['quarantine']} | بازگردانده‌شده: {$s['reverted']}" );
		WP_CLI::log( "فضای آزادشده: {$s['saved_h']}" );
	}

	/** Step 1: find media not used anywhere. */
	public function scan() {
		$ids    = WPMO_Optimizer::scan_ids();
		$unused = 0;
		$bar    = \WP_CLI\Utils\make_progress_bar( 'بررسی', count( $ids ) );
		foreach ( $ids as $id ) {
			$r = WPMO_Usage::check_and_store( $id );
			if ( ! $r['used'] ) {
				$unused++;
			}
			$bar->tick();
		}
		$bar->finish();
		WP_CLI::success( count( $ids ) . " فایل بررسی شد. بدون استفاده: {$unused}" );
		foreach ( WPMO_Optimizer::unused_ids() as $id ) {
			WP_CLI::log( "  #{$id} " . wp_basename( (string) get_attached_file( $id ) ) );
		}
	}

	/**
	 * Step 1: move all unused media to quarantine (reversible).
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function quarantine( $args, $assoc ) {
		$ids = WPMO_Optimizer::unused_ids();
		WP_CLI::confirm( count( $ids ) . ' فایل بدون استفاده به قرنطینه منتقل شود؟', $assoc );
		$this->each( 'quarantine', $ids );
	}

	/**
	 * Step 2: convert images to WebP and compress videos (originals kept until approved).
	 *
	 * [--type=<type>]
	 * : all, image or video. Default all.
	 *
	 * [--retry-errors]
	 * : Retry failed and reverted items.
	 *
	 * [--include-unused]
	 * : Also convert media the scan found unused.
	 */
	public function convert( $args, $assoc ) {
		if ( ! empty( $assoc['retry-errors'] ) ) {
			WPMO_Optimizer::reset_errors( true );
		}
		$ids   = WPMO_Optimizer::pending_ids( isset( $assoc['type'] ) ? $assoc['type'] : 'all', empty( $assoc['include-unused'] ) ? null : false );
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
		WP_CLI::success( 'تبدیل تمام شد. صرفه‌جویی: ' . ( size_format( $saved, 1 ) ?: '0 B' ) . ' — برای حذف اصلی‌ها: wp media-optimizer approve --all' );
	}

	/** Alias of convert (v1 compatibility). */
	public function run( $args, $assoc ) {
		$this->convert( $args, $assoc );
	}

	/**
	 * Step 3: approve conversions (delete originals).
	 *
	 * [<ids>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All conversions waiting for approval.
	 */
	public function approve( $args, $assoc ) {
		$this->each( 'approve', $this->ids( $args, $assoc, WPMO_Optimizer::META_BACKUP ) );
	}

	/**
	 * Step 3: revert conversions (restore originals and links).
	 *
	 * [<ids>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All conversions waiting for approval.
	 */
	public function revert( $args, $assoc ) {
		$this->each( 'revert', $this->ids( $args, $assoc, WPMO_Optimizer::META_BACKUP ) );
	}

	/**
	 * Bring quarantined media back.
	 *
	 * [<ids>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : Everything in quarantine.
	 */
	public function restore( $args, $assoc ) {
		$this->each( 'restore', $this->ids( $args, $assoc, WPMO_Optimizer::META_QUARANTINE ) );
	}

	/**
	 * Permanently delete quarantined media.
	 *
	 * [<ids>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : Everything in quarantine.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function purge( $args, $assoc ) {
		$ids = $this->ids( $args, $assoc, WPMO_Optimizer::META_QUARANTINE );
		WP_CLI::confirm( count( $ids ) . ' فایل برای همیشه حذف شود؟', $assoc );
		$this->each( 'purge', $ids );
	}

	private function ids( $args, $assoc, $meta ) {
		if ( ! empty( $assoc['all'] ) ) {
			return WPMO_Optimizer::ids_with_meta( $meta );
		}
		if ( empty( $args ) ) {
			WP_CLI::error( 'شناسه‌ها را بدهید یا از --all استفاده کنید.' );
		}
		return array_map( 'absint', $args );
	}

	private function each( $op, $ids ) {
		$ok = 0;
		foreach ( $ids as $id ) {
			$r = WPMO_Optimizer::run_action( $op, $id );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( "#{$id} " . $r->get_error_message() );
			} else {
				$ok++;
				WP_CLI::log( "✔ #{$id}" );
			}
		}
		WP_CLI::success( "{$op}: {$ok} از " . count( $ids ) );
	}
}
