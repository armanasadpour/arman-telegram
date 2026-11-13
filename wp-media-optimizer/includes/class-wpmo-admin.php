<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMO_Admin {

	const SLUG = 'wpmo-media-optimizer';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wpmo_queue', array( __CLASS__, 'ajax_queue' ) );
		add_action( 'wp_ajax_wpmo_process', array( __CLASS__, 'ajax_process' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=' . self::SLUG ) ) . '">تنظیمات و بهینه‌سازی</a>' );
		return $links;
	}

	public static function menu() {
		add_media_page( 'بهینه‌ساز رسانه', 'بهینه‌ساز رسانه', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register_settings() {
		register_setting( 'wpmo', WPMO_Settings::OPTION, array( 'sanitize_callback' => array( 'WPMO_Settings', 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'wpmo-admin', WPMO_URL . 'assets/admin.js', array(), WPMO_VERSION, true );
		wp_localize_script(
			'wpmo-admin',
			'WPMO',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wpmo' ),
			)
		);
	}

	private static function check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی ندارید.', 403 );
		}
		check_ajax_referer( 'wpmo', 'nonce' );
	}

	public static function ajax_queue() {
		self::check();
		if ( ! empty( $_POST['retry'] ) ) {
			WPMO_Optimizer::reset_errors();
		}
		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'all';
		if ( ! in_array( $type, array( 'all', 'image', 'video' ), true ) ) {
			$type = 'all';
		}
		$ids = WPMO_Optimizer::pending_ids( $type );
		if ( ! WPMO_Video::supported() ) {
			// Skip videos when FFmpeg is missing so images can still be processed.
			$ids = array_values( array_filter( $ids, function ( $id ) {
				return ! WPMO_Video::is_convertible( get_post_mime_type( $id ) );
			} ) );
		}
		wp_send_json_success( array( 'ids' => $ids ) );
	}

	public static function ajax_process() {
		self::check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( 'شناسه نامعتبر.' );
		}
		@set_time_limit( 0 );
		$r = WPMO_Optimizer::optimize( $id );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( '#' . $id . ' — ' . $r->get_error_message() );
		}
		$r['old_h'] = size_format( $r['old_size'], 1 );
		$r['new_h'] = size_format( $r['new_size'], 1 );
		wp_send_json_success( $r );
	}

	private static function stats() {
		global $wpdb;
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", WPMO_Optimizer::META_DONE ) );
		$saved = 0;
		foreach ( $rows as $r ) {
			$v = maybe_unserialize( $r );
			if ( is_array( $v ) ) {
				$saved += max( 0, (int) $v['old'] - (int) $v['new'] );
			}
		}
		$errors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", WPMO_Optimizer::META_ERROR ) );
		return array( 'done' => count( $rows ), 'saved' => $saved, 'errors' => $errors );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = WPMO_Settings::all();
		$stats  = self::stats();
		$imgs   = count( WPMO_Optimizer::pending_ids( 'image' ) );
		$vids   = count( WPMO_Optimizer::pending_ids( 'video' ) );
		$webp   = WPMO_Image::supported();
		$ffmpeg = WPMO_Video::supported();
		$name   = WPMO_Settings::OPTION;
		$ok     = '<span style="color:#00a32a">✔ فعال</span>';
		$bad    = '<span style="color:#d63638">✘ در دسترس نیست</span>';
		?>
		<div class="wrap" dir="rtl" style="max-width:960px">
			<h1>بهینه‌ساز رسانه (WebP + فشرده‌سازی ویدیو)</h1>

			<table class="widefat striped" style="margin:16px 0">
				<tbody>
					<tr><td>پشتیبانی WebP (Imagick / GD)</td><td><?php echo $webp ? $ok : $bad; // phpcs:ignore ?></td></tr>
					<tr><td>FFmpeg برای ویدیو</td><td><?php echo $ffmpeg ? $ok : $bad . ' — تبدیل ویدیو انجام نمی‌شود؛ از هاستینگ بخواهید FFmpeg و تابع exec را فعال کند.'; // phpcs:ignore ?></td></tr>
					<tr><td>تصاویر در انتظار تبدیل</td><td><strong><?php echo (int) $imgs; ?></strong></td></tr>
					<tr><td>ویدیوهای در انتظار فشرده‌سازی</td><td><strong><?php echo (int) $vids; ?></strong></td></tr>
					<tr><td>رسانه‌های بهینه‌شده</td><td><?php echo (int) $stats['done']; ?> (فضای آزادشده: <?php echo esc_html( size_format( $stats['saved'], 1 ) ?: '0 B' ); ?>)</td></tr>
					<tr><td>خطاها</td><td><?php echo (int) $stats['errors']; ?></td></tr>
				</tbody>
			</table>

			<h2>بهینه‌سازی همه رسانه‌های موجود</h2>
			<div class="notice notice-warning inline"><p>
				<strong>هشدار:</strong> این عملیات فایل‌های اصلی را <u>برای همیشه حذف</u> می‌کند (اگر گزینه «حذف فایل اصلی» روشن باشد) و آدرس‌ها را در نوشته‌ها، برگه‌ها، متاها و تنظیمات جایگزین می‌کند.
				حتماً <strong>قبل از شروع از فایل‌ها و دیتابیس بکاپ بگیرید.</strong>
				برای سایت‌های بزرگ استفاده از WP-CLI توصیه می‌شود: <code dir="ltr">wp media-optimizer run</code>
			</p></div>
			<p>
				<select id="wpmo-type">
					<option value="all">همه (تصویر + ویدیو)</option>
					<option value="image">فقط تصاویر</option>
					<option value="video">فقط ویدیوها</option>
				</select>
				<label><input type="checkbox" id="wpmo-retry"> تلاش مجدد برای موارد خطادار</label>
			</p>
			<p>
				<button class="button button-primary button-hero" id="wpmo-start">شروع بهینه‌سازی</button>
				<button class="button button-hero" id="wpmo-stop" disabled>توقف</button>
			</p>
			<div style="background:#ddd;border-radius:4px;height:22px;overflow:hidden;margin:12px 0">
				<div id="wpmo-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s"></div>
			</div>
			<p id="wpmo-status"></p>
			<pre id="wpmo-log" dir="ltr" style="background:#1d2327;color:#c3c4c7;padding:12px;max-height:320px;overflow:auto;font-size:12px;display:none"></pre>

			<hr>
			<h2>تنظیمات</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'wpmo' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>تبدیل خودکار تصاویر</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[convert_on_upload]" value="1" <?php checked( $o['convert_on_upload'] ); ?>> تبدیل JPG / PNG / GIF / BMP / TIFF به WebP بلافاصله پس از آپلود</label></td></tr>
					<tr><th>کیفیت WebP</th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( $name ); ?>[webp_quality]" value="<?php echo (int) $o['webp_quality']; ?>" class="small-text"> <p class="description">۸۰ تا ۸۵ = بدون افت کیفیت قابل مشاهده با کمترین حجم. (پیشنهادی: ۸۲)</p></td></tr>
					<tr><th>PNG بدون افت</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[png_lossless]" value="1" <?php checked( $o['png_lossless'] ); ?>> برای PNG از کیفیت ۱۰۰ استفاده شود (مناسب لوگو و اسکرین‌شات)</label></td></tr>
					<tr><th>GIF متحرک</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[skip_animated_gif]" value="1" <?php checked( $o['skip_animated_gif'] ); ?>> GIFهای متحرک تبدیل نشوند (وگرنه انیمیشن از بین می‌رود)</label></td></tr>
					<tr><th>حداکثر عرض تصویر</th><td><input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[max_image_width]" value="<?php echo (int) $o['max_image_width']; ?>" class="small-text"> px <p class="description">تصاویر بزرگ‌تر کوچک می‌شوند. ۰ = بدون تغییر اندازه.</p></td></tr>
					<tr><th>فشرده‌سازی ویدیو</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[compress_videos]" value="1" <?php checked( $o['compress_videos'] ); ?>> ویدیوهای آپلودی در پس‌زمینه فشرده شوند (H.264/MP4)</label></td></tr>
					<tr><th>مسیر FFmpeg</th><td><input type="text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[ffmpeg_path]" value="<?php echo esc_attr( $o['ffmpeg_path'] ); ?>" class="regular-text"></td></tr>
					<tr><th>CRF ویدیو</th><td><input type="number" min="0" max="51" name="<?php echo esc_attr( $name ); ?>[video_crf]" value="<?php echo (int) $o['video_crf']; ?>" class="small-text"> <p class="description">عدد کمتر = کیفیت بیشتر. ۲۳ تا ۲۸ بهترین تعادل؛ ۲۶ پیشنهادی (حجم بسیار کمتر بدون افت محسوس).</p></td></tr>
					<tr><th>Preset</th><td><select name="<?php echo esc_attr( $name ); ?>[video_preset]">
						<?php foreach ( array( 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' ) as $p ) : ?>
							<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $o['video_preset'], $p ); ?>><?php echo esc_html( $p ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">کندتر = حجم کمتر با همان کیفیت. slow پیشنهادی است.</p></td></tr>
					<tr><th>حداکثر عرض ویدیو</th><td><input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[video_max_width]" value="<?php echo (int) $o['video_max_width']; ?>" class="small-text"> px <p class="description">۱۹۲۰ = Full HD. ۰ = حفظ رزولوشن اصلی.</p></td></tr>
					<tr><th>بیت‌ریت صدا</th><td><input type="number" min="32" max="320" name="<?php echo esc_attr( $name ); ?>[audio_bitrate]" value="<?php echo (int) $o['audio_bitrate']; ?>" class="small-text"> kbps</td></tr>
					<tr><th>حذف فایل اصلی</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_originals]" value="1" <?php checked( $o['delete_originals'] ); ?>> پس از تبدیل موفق، فایل اصلی حذف شود</label></td></tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
	}
}
