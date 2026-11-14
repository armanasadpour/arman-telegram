<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: a step-by-step wizard under Media → Media Optimizer.
 *   Step 1  Scan        find unused media → quarantine (reversible) → permanent delete / restore
 *   Step 2  Convert     WebP / compressed MP4, originals kept in backup
 *   Step 3  Approve     delete originals  |  revert everything
 */
class WPMO_Admin {

	const SLUG       = 'wpmo-media-optimizer';
	const LIST_LIMIT = 500;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wpmo_ids', array( __CLASS__, 'ajax_ids' ) );
		add_action( 'wp_ajax_wpmo_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_wpmo_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_wpmo_stats', array( __CLASS__, 'ajax_stats' ) );
		add_action( 'wp_ajax_wpmo_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'admin_notices', array( __CLASS__, 'pending_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( $tab = '' ) {
		return admin_url( 'upload.php?page=' . self::SLUG . ( $tab ? '#' . $tab : '' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">بهینه‌ساز رسانه</a>' );
		return $links;
	}

	public static function menu() {
		add_media_page( 'بهینه‌ساز رسانه', 'بهینه‌ساز رسانه', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register_settings() {
		register_setting( 'wpmo', WPMO_Settings::OPTION, array( 'sanitize_callback' => array( 'WPMO_Settings', 'sanitize' ) ) );
	}

	/** Remind the admin that converted files are waiting for approval. */
	public static function pending_notice() {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ( $screen && 'media_page_' . self::SLUG === $screen->id ) ) {
			return;
		}
		$n = count( WPMO_Optimizer::ids_with_meta( WPMO_Optimizer::META_BACKUP ) );
		if ( $n > 0 && $screen && in_array( $screen->id, array( 'upload', 'dashboard', 'plugins' ), true ) ) {
			printf(
				'<div class="notice notice-info"><p>بهینه‌ساز رسانه: <strong>%d</strong> فایل تبدیل شده منتظر تایید شماست (فایل‌های اصلی هنوز نگه داشته شده‌اند). <a href="%s">بررسی و تایید</a></p></div>',
				(int) $n,
				esc_url( self::url( 'approve' ) )
			);
		}
	}

	public static function assets( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}
		WPMO_Backup::cleanup_upload_tmp();
		wp_enqueue_style( 'wpmo-admin', WPMO_URL . 'assets/admin.css', array(), WPMO_VERSION );
		wp_enqueue_script( 'wpmo-admin', WPMO_URL . 'assets/admin.js', array(), WPMO_VERSION, true );
		wp_localize_script(
			'wpmo-admin',
			'WPMO',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wpmo' ),
				'limit' => self::LIST_LIMIT,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی ندارید.', 403 );
		}
		check_ajax_referer( 'wpmo', 'nonce' );
	}

	/** ID lists for each step. */
	public static function ajax_ids() {
		self::check();
		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
		switch ( $kind ) {
			case 'scan':
				$ids = WPMO_Optimizer::scan_ids();
				break;
			case 'convert':
				if ( ! empty( $_POST['retry'] ) ) {
					WPMO_Optimizer::reset_errors( true );
				}
				$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'all';
				$type = in_array( $type, array( 'all', 'image', 'video' ), true ) ? $type : 'all';
				$ids  = WPMO_Optimizer::pending_ids( $type, ! empty( $_POST['skip_unused'] ) );
				if ( ! WPMO_Video::supported() ) {
					$ids = array_values( array_filter( $ids, function ( $id ) {
						return ! WPMO_Video::is_convertible( get_post_mime_type( $id ) );
					} ) );
				}
				break;
			case 'unused':
				$ids = WPMO_Optimizer::unused_ids();
				break;
			case 'pending':
				$ids = WPMO_Optimizer::ids_with_meta( WPMO_Optimizer::META_BACKUP );
				break;
			case 'quarantine':
				$ids = WPMO_Optimizer::ids_with_meta( WPMO_Optimizer::META_QUARANTINE );
				break;
			default:
				wp_send_json_error( 'نوع نامعتبر.' );
		}
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	/** Run one operation on a small batch of attachments. */
	public static function ajax_batch() {
		self::check();
		$op  = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		if ( ! in_array( $op, array( 'scan', 'convert', 'approve', 'revert', 'quarantine', 'restore', 'purge' ), true ) ) {
			wp_send_json_error( 'عملیات نامعتبر.' );
		}
		@set_time_limit( 0 );

		$out = array();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				$out[] = array( 'id' => $id, 'ok' => false, 'msg' => 'شناسه نامعتبر.' );
				continue;
			}
			$name = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
			$r    = WPMO_Optimizer::run_action( $op, $id );
			if ( is_wp_error( $r ) ) {
				$out[] = array( 'id' => $id, 'ok' => false, 'name' => $name, 'msg' => $r->get_error_message() );
				continue;
			}
			$row = array( 'id' => $id, 'ok' => true, 'name' => $name );
			if ( is_array( $r ) ) {
				$row = array_merge( $row, $r );
				if ( isset( $r['old_size'] ) ) {
					$row['old_h'] = size_format( $r['old_size'], 1 );
					$row['new_h'] = size_format( $r['new_size'], 1 );
					$row['name']  = $r['file'];
				}
			}
			$out[] = $row;
		}
		wp_send_json_success( array( 'results' => $out ) );
	}

	/** Detailed rows for the tables. */
	public static function ajax_list() {
		self::check();
		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
		if ( 'unused' === $kind ) {
			$ids = WPMO_Optimizer::unused_ids();
		} elseif ( 'pending' === $kind ) {
			$ids = WPMO_Optimizer::ids_with_meta( WPMO_Optimizer::META_BACKUP );
		} elseif ( 'quarantine' === $kind ) {
			$ids = WPMO_Optimizer::ids_with_meta( WPMO_Optimizer::META_QUARANTINE );
		} else {
			wp_send_json_error( 'نوع نامعتبر.' );
		}

		$items = array();
		$total = 0;
		foreach ( $ids as $i => $id ) {
			$item   = self::item( $kind, $id );
			$total += isset( $item['save'] ) ? $item['save'] : ( isset( $item['size'] ) ? $item['size'] : 0 );
			if ( $i < self::LIST_LIMIT ) {
				$items[] = $item;
			}
		}
		wp_send_json_success( array(
			'ids'     => array_map( 'intval', $ids ),
			'items'   => $items,
			'total'   => count( $ids ),
			'bytes_h' => size_format( $total, 1 ) ?: '0 B',
		) );
	}

	private static function item( $kind, $id ) {
		$mime     = get_post_mime_type( $id );
		$attached = get_attached_file( $id );
		$item     = array(
			'id'    => $id,
			'title' => get_the_title( $id ),
			'mime'  => $mime,
			'edit'  => get_edit_post_link( $id, 'raw' ),
			'date'  => get_the_date( 'Y/m/d', $id ),
		);

		if ( 'unused' === $kind ) {
			$files         = WPMO_Optimizer::attachment_files( wp_get_attachment_metadata( $id ), $attached );
			$size          = array_sum( array_map( 'filesize', $files ) );
			$item['name']  = wp_basename( (string) $attached );
			$item['size']  = $size;
			$item['size_h'] = size_format( $size, 1 ) ?: '0 B';
			$item['files'] = count( $files );
			$item['url']   = wp_get_attachment_url( $id );
			$item['thumb'] = 0 === strpos( $mime, 'image/' ) ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		} elseif ( 'pending' === $kind ) {
			$m             = get_post_meta( $id, WPMO_Optimizer::META_BACKUP, true );
			$old           = WPMO_Backup::size( $id, 'convert', $m['files'] );
			$files         = WPMO_Optimizer::attachment_files( wp_get_attachment_metadata( $id ), $attached );
			$new           = array_sum( array_map( 'filesize', $files ) );
			$item['name']  = wp_basename( (string) $attached );
			$item['orig']  = wp_basename( $m['old_attached'] );
			$item['old_h'] = size_format( $old, 1 ) ?: '0 B';
			$item['new_h'] = size_format( $new, 1 ) ?: '0 B';
			$item['save']  = max( 0, $old - $new );
			$item['pct']   = $old > 0 ? round( ( 1 - $new / $old ) * 100 ) : 0;
			$item['source'] = isset( $m['source'] ) ? $m['source'] : 'bulk';
			$item['url']   = wp_get_attachment_url( $id );
			$item['thumb'] = 0 === strpos( $mime, 'image/' ) ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
			$item['orig_url'] = self::preview_url( $id, 'convert' );
		} else {
			$q             = get_post_meta( $id, WPMO_Optimizer::META_QUARANTINE, true );
			$size          = WPMO_Backup::size( $id, 'quarantine', $q['files'] );
			$item['name']  = wp_basename( $q['main'] );
			$item['size']  = $size;
			$item['size_h'] = size_format( $size, 1 ) ?: '0 B';
			$item['files'] = count( $q['files'] );
			$item['orig_url'] = self::preview_url( $id, 'quarantine' );
			$item['when']  = wp_date( 'Y/m/d H:i', $q['time'] );
		}
		return $item;
	}

	private static function preview_url( $id, $kind ) {
		return add_query_arg(
			array(
				'action' => 'wpmo_preview',
				'id'     => $id,
				'kind'   => $kind,
				'nonce'  => wp_create_nonce( 'wpmo' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** Stream a backed-up original so the admin can compare before approving. */
	public static function ajax_preview() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '', 'wpmo' ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$kind = isset( $_GET['kind'] ) && 'quarantine' === $_GET['kind'] ? 'quarantine' : 'convert';
		if ( 'convert' === $kind ) {
			$m   = get_post_meta( $id, WPMO_Optimizer::META_BACKUP, true );
			$rel = is_array( $m ) ? $m['old_attached'] : '';
		} else {
			$m   = get_post_meta( $id, WPMO_Optimizer::META_QUARANTINE, true );
			$rel = is_array( $m ) ? $m['main'] : '';
		}
		$file = $rel ? WPMO_Backup::path( $id, $kind, $rel ) : '';
		if ( ! $file || ! file_exists( $file ) ) {
			wp_die( 'فایل پیدا نشد.', 404 );
		}
		$ft = wp_check_filetype( $file );
		nocache_headers();
		header( 'Content-Type: ' . ( $ft['type'] ? $ft['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( wp_basename( $file ) ) . '"' );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	public static function ajax_stats() {
		self::check();
		wp_send_json_success( self::stats() );
	}

	public static function stats() {
		global $wpdb;
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", WPMO_Optimizer::META_DONE ) );
		$saved = 0;
		foreach ( $rows as $r ) {
			$v = maybe_unserialize( $r );
			if ( is_array( $v ) ) {
				$saved += max( 0, (int) $v['old'] - (int) $v['new'] );
			}
		}
		$count = function ( $key, $val = null ) {
			return count( WPMO_Optimizer::ids_with_meta( $key, $val ) );
		};
		return array(
			'scanned'    => $count( WPMO_Usage::META ),
			'unused'     => count( WPMO_Optimizer::unused_ids() ),
			'images'     => count( WPMO_Optimizer::pending_ids( 'image' ) ),
			'videos'     => count( WPMO_Optimizer::pending_ids( 'video' ) ),
			'pending'    => $count( WPMO_Optimizer::META_BACKUP ),
			'quarantine' => $count( WPMO_Optimizer::META_QUARANTINE ),
			'done'       => count( $rows ),
			'saved_h'    => size_format( $saved, 1 ) ?: '0 B',
			'errors'     => $count( WPMO_Optimizer::META_ERROR ),
			'reverted'   => $count( WPMO_Optimizer::META_REVERTED ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = WPMO_Settings::all();
		$st     = self::stats();
		$webp   = WPMO_Image::supported();
		$ffmpeg = WPMO_Video::supported();
		$name   = WPMO_Settings::OPTION;
		$f      = function ( $k ) use ( $name ) {
			return esc_attr( $name . '[' . $k . ']' );
		};
		?>
		<div class="wrap wpmo" dir="rtl">
			<h1>بهینه‌ساز رسانه</h1>

			<div class="wpmo-env">
				<span class="<?php echo $webp ? 'ok' : 'bad'; ?>">WebP: <?php echo $webp ? 'فعال' : 'پشتیبانی نمی‌شود'; ?></span>
				<span class="<?php echo $ffmpeg ? 'ok' : 'bad'; ?>">FFmpeg: <?php echo $ffmpeg ? 'فعال' : 'در دسترس نیست (ویدیوها تبدیل نمی‌شوند)'; ?></span>
				<span>فضای آزادشده تاکنون: <strong data-stat="saved_h"><?php echo esc_html( $st['saved_h'] ); ?></strong></span>
			</div>

			<nav class="wpmo-steps">
				<a href="#scan" data-tab="scan"><b>۱</b> بررسی <em data-stat="unused"><?php echo (int) $st['unused']; ?></em></a>
				<a href="#convert" data-tab="convert"><b>۲</b> تبدیل <em data-stat="convert"><?php echo (int) ( $st['images'] + $st['videos'] ); ?></em></a>
				<a href="#approve" data-tab="approve"><b>۳</b> تایید مدیر <em data-stat="approve"><?php echo (int) ( $st['pending'] + $st['quarantine'] ); ?></em></a>
				<a href="#settings" data-tab="settings">تنظیمات</a>
			</nav>

			<!-- ================= STEP 1: SCAN ================= -->
			<section class="wpmo-tab" id="wpmo-tab-scan">
				<h2>مرحله ۱: بررسی فایل‌های بدون استفاده</h2>
				<p>همه تصاویر و ویدیوهای کتابخانه بررسی می‌شوند که آیا در نوشته‌ها، برگه‌ها، محصولات، گالری‌ها، تصویر شاخص، گالری محصول ووکامرس، فیلدهای سفارشی (ACF، المنتور و…)، لوگو/آیکن سایت، ابزارک‌ها، فهرست‌ها و دسته‌ها استفاده شده‌اند یا نه.</p>
				<p>
					<button class="button button-primary button-hero" data-run="scan">شروع بررسی</button>
					<button class="button button-hero" data-stop>توقف</button>
				</p>
				<div class="wpmo-progress"><div></div></div>
				<p class="wpmo-status"></p>

				<div class="wpmo-list" data-list="unused">
					<h3>فایل‌های بدون استفاده <span class="wpmo-count"></span></h3>
					<div class="notice notice-info inline"><p>
						انتقال به قرنطینه <strong>قابل بازگشت است</strong>: فایل‌ها از سایت و کتابخانه رسانه برداشته می‌شوند ولی پاک نمی‌شوند.
						در مرحله ۳ می‌توانید آن‌ها را برای همیشه حذف یا بازگردانی کنید. تصاویری که فقط در فایل‌های قالب یا CSS استفاده شده‌اند قابل تشخیص نیستند؛ قبل از حذف دائمی سایت را بررسی کنید.
					</p></div>
					<div class="wpmo-actions">
						<button class="button button-primary" data-op="quarantine" data-scope="selected">انتقال انتخاب‌شده‌ها به قرنطینه</button>
						<button class="button" data-op="quarantine" data-scope="all">انتقال همه به قرنطینه</button>
					</div>
					<div class="wpmo-table"></div>
				</div>
			</section>

			<!-- ================= STEP 2: CONVERT ================= -->
			<section class="wpmo-tab" id="wpmo-tab-convert">
				<h2>مرحله ۲: تبدیل و فشرده‌سازی</h2>
				<p>تصاویر به WebP و ویدیوها به MP4 فشرده تبدیل می‌شوند و آدرس‌ها در کل سایت به‌روز می‌شوند.
					<?php if ( $o['require_approval'] ) : ?>
						<strong>فایل‌های اصلی حذف نمی‌شوند</strong>؛ در پوشه پشتیبان نگه داشته می‌شوند تا در مرحله ۳ تایید یا بازگردانی کنید.
					<?php else : ?>
						<strong style="color:#d63638">توجه: «نیاز به تایید مدیر» در تنظیمات خاموش است؛ فایل‌های اصلی بلافاصله حذف می‌شوند.</strong>
					<?php endif; ?>
				</p>
				<p>
					تصاویر در انتظار: <strong data-stat="images"><?php echo (int) $st['images']; ?></strong> —
					ویدیوهای در انتظار: <strong data-stat="videos"><?php echo (int) $st['videos']; ?></strong>
					<?php if ( $st['errors'] ) : ?> — خطا: <strong data-stat="errors"><?php echo (int) $st['errors']; ?></strong><?php endif; ?>
				</p>
				<p>
					<select id="wpmo-type">
						<option value="all">همه (تصویر + ویدیو)</option>
						<option value="image">فقط تصاویر</option>
						<option value="video">فقط ویدیوها</option>
					</select>
					<label><input type="checkbox" id="wpmo-skip-unused" <?php checked( $o['skip_unused'] ); ?>> فایل‌های بدون استفاده (مرحله ۱) تبدیل نشوند</label>
					<label><input type="checkbox" id="wpmo-retry"> تلاش دوباره برای موارد خطادار و بازگردانده‌شده</label>
				</p>
				<p>
					<button class="button button-primary button-hero" data-run="convert">شروع تبدیل</button>
					<button class="button button-hero" data-stop>توقف</button>
				</p>
				<div class="wpmo-progress"><div></div></div>
				<p class="wpmo-status"></p>
				<pre class="wpmo-log" dir="ltr"></pre>
			</section>

			<!-- ================= STEP 3: APPROVE ================= -->
			<section class="wpmo-tab" id="wpmo-tab-approve">
				<h2>مرحله ۳: تایید مدیر</h2>

				<div class="wpmo-list" data-list="pending">
					<h3>فایل‌های تبدیل‌شده در انتظار تایید <span class="wpmo-count"></span></h3>
					<p>فایل جدید را با «اصلی» مقایسه کنید. <strong>تایید</strong> = حذف دائمی فایل اصلی و آزاد شدن فضا.
						<strong>بازگردانی</strong> = فایل اصلی، همه سایزها و همه لینک‌های سایت به حالت قبل برمی‌گردند و فایل تبدیل‌شده حذف می‌شود.</p>
					<div class="wpmo-actions">
						<button class="button button-primary" data-op="approve" data-scope="selected">تایید انتخاب‌شده‌ها (حذف اصلی)</button>
						<button class="button" data-op="revert" data-scope="selected">بازگردانی انتخاب‌شده‌ها</button>
						<span class="wpmo-sep"></span>
						<button class="button" data-op="approve" data-scope="all">تایید همه</button>
						<button class="button" data-op="revert" data-scope="all">بازگردانی همه</button>
					</div>
					<div class="wpmo-table"></div>
				</div>

				<hr>

				<div class="wpmo-list" data-list="quarantine">
					<h3>قرنطینه (فایل‌های بدون استفاده) <span class="wpmo-count"></span></h3>
					<p><strong>حذف دائمی</strong> = فایل و رکورد آن در کتابخانه رسانه برای همیشه پاک می‌شود. <strong>بازگردانی</strong> = فایل به سایت و کتابخانه رسانه برمی‌گردد.</p>
					<div class="wpmo-actions">
						<button class="button button-primary wpmo-danger" data-op="purge" data-scope="selected">حذف دائمی انتخاب‌شده‌ها</button>
						<button class="button" data-op="restore" data-scope="selected">بازگردانی انتخاب‌شده‌ها</button>
						<span class="wpmo-sep"></span>
						<button class="button wpmo-danger" data-op="purge" data-scope="all">حذف دائمی همه</button>
						<button class="button" data-op="restore" data-scope="all">بازگردانی همه</button>
					</div>
					<div class="wpmo-table"></div>
				</div>
				<div class="wpmo-progress"><div></div></div>
				<p class="wpmo-status"></p>
				<pre class="wpmo-log" dir="ltr"></pre>
			</section>

			<!-- ================= SETTINGS ================= -->
			<section class="wpmo-tab" id="wpmo-tab-settings">
				<h2>تنظیمات</h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'wpmo' ); ?>
					<table class="form-table" role="presentation">
						<tr><th>نیاز به تایید مدیر</th><td><label><input type="checkbox" name="<?php echo $f( 'require_approval' ); ?>" value="1" <?php checked( $o['require_approval'] ); ?>> فایل اصلی تا تایید مدیر در پشتیبان نگه داشته شود (قابل بازگشت). <strong>پیشنهاد: روشن</strong></label></td></tr>
						<tr><th>تبدیل خودکار هنگام آپلود</th><td><label><input type="checkbox" name="<?php echo $f( 'convert_on_upload' ); ?>" value="1" <?php checked( $o['convert_on_upload'] ); ?>> JPG / PNG / GIF / BMP / TIFF بلافاصله پس از آپلود به WebP تبدیل شود</label></td></tr>
						<tr><th>فشرده‌سازی خودکار ویدیو</th><td><label><input type="checkbox" name="<?php echo $f( 'compress_videos' ); ?>" value="1" <?php checked( $o['compress_videos'] ); ?>> ویدیوهای آپلودی در پس‌زمینه فشرده شوند (H.264 / MP4)</label></td></tr>
						<tr><th>رد کردن فایل‌های بدون استفاده</th><td><label><input type="checkbox" name="<?php echo $f( 'skip_unused' ); ?>" value="1" <?php checked( $o['skip_unused'] ); ?>> فایل‌هایی که در مرحله ۱ بدون استفاده شناخته شده‌اند تبدیل نشوند</label></td></tr>
						<tr><th>کیفیت WebP</th><td><input type="number" min="1" max="100" name="<?php echo $f( 'webp_quality' ); ?>" value="<?php echo (int) $o['webp_quality']; ?>" class="small-text"><p class="description">۸۰ تا ۸۵ = بدون افت کیفیت قابل مشاهده با کمترین حجم (پیشنهادی: ۸۲).</p></td></tr>
						<tr><th>PNG بدون افت</th><td><label><input type="checkbox" name="<?php echo $f( 'png_lossless' ); ?>" value="1" <?php checked( $o['png_lossless'] ); ?>> برای PNG کیفیت ۱۰۰ (مناسب لوگو و اسکرین‌شات)</label></td></tr>
						<tr><th>GIF متحرک</th><td><label><input type="checkbox" name="<?php echo $f( 'skip_animated_gif' ); ?>" value="1" <?php checked( $o['skip_animated_gif'] ); ?>> GIF متحرک تبدیل نشود (وگرنه انیمیشن از بین می‌رود)</label></td></tr>
						<tr><th>حداکثر عرض تصویر</th><td><input type="number" min="0" name="<?php echo $f( 'max_image_width' ); ?>" value="<?php echo (int) $o['max_image_width']; ?>" class="small-text"> px<p class="description">۰ = بدون تغییر اندازه.</p></td></tr>
						<tr><th>مسیر FFmpeg</th><td><input type="text" dir="ltr" name="<?php echo $f( 'ffmpeg_path' ); ?>" value="<?php echo esc_attr( $o['ffmpeg_path'] ); ?>" class="regular-text"></td></tr>
						<tr><th>CRF ویدیو</th><td><input type="number" min="0" max="51" name="<?php echo $f( 'video_crf' ); ?>" value="<?php echo (int) $o['video_crf']; ?>" class="small-text"><p class="description">کمتر = کیفیت بیشتر. ۲۳ تا ۲۸ بهترین تعادل؛ ۲۶ پیشنهادی.</p></td></tr>
						<tr><th>Preset</th><td><select name="<?php echo $f( 'video_preset' ); ?>">
							<?php foreach ( array( 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' ) as $p ) : ?>
								<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $o['video_preset'], $p ); ?>><?php echo esc_html( $p ); ?></option>
							<?php endforeach; ?>
						</select><p class="description">کندتر = حجم کمتر با همان کیفیت (پیشنهادی: slow).</p></td></tr>
						<tr><th>حداکثر عرض ویدیو</th><td><input type="number" min="0" name="<?php echo $f( 'video_max_width' ); ?>" value="<?php echo (int) $o['video_max_width']; ?>" class="small-text"> px<p class="description">۱۹۲۰ = Full HD. ۰ = حفظ رزولوشن.</p></td></tr>
						<tr><th>بیت‌ریت صدا</th><td><input type="number" min="32" max="320" name="<?php echo $f( 'audio_bitrate' ); ?>" value="<?php echo (int) $o['audio_bitrate']; ?>" class="small-text"> kbps</td></tr>
					</table>
					<?php submit_button( 'ذخیره تنظیمات' ); ?>
				</form>
				<p class="description">WP-CLI: <code dir="ltr">wp media-optimizer scan | quarantine | convert | approve | revert | restore | purge</code></p>
			</section>
		</div>
		<?php
	}
}
