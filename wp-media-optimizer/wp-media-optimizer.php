<?php
/**
 * Plugin Name: Media Optimizer (WebP + Video Compressor)
 * Description: تبدیل خودکار تصاویر به WebP هنگام آپلود، فشرده‌سازی ویدیوها با FFmpeg، و بهینه‌سازی گروهی همه رسانه‌های موجود سایت (با حذف فایل اصلی).
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Arman
 * License:     GPL-2.0-or-later
 * Text Domain: wp-media-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMO_VERSION', '1.0.0' );
define( 'WPMO_FILE', __FILE__ );
define( 'WPMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMO_URL', plugin_dir_url( __FILE__ ) );

require_once WPMO_DIR . 'includes/class-wpmo-settings.php';
require_once WPMO_DIR . 'includes/class-wpmo-image.php';
require_once WPMO_DIR . 'includes/class-wpmo-video.php';
require_once WPMO_DIR . 'includes/class-wpmo-replacer.php';
require_once WPMO_DIR . 'includes/class-wpmo-optimizer.php';
require_once WPMO_DIR . 'includes/class-wpmo-admin.php';

add_action( 'plugins_loaded', array( 'WPMO_Optimizer', 'init' ) );

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'WPMO_Admin', 'init' ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPMO_DIR . 'includes/class-wpmo-cli.php';
	WP_CLI::add_command( 'media-optimizer', 'WPMO_CLI' );
}

register_activation_hook( __FILE__, array( 'WPMO_Settings', 'install_defaults' ) );
