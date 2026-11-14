<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects whether an attachment is used anywhere on the site.
 *
 * Checked: post/page/product/... content & excerpt (by URL, wp-image-ID, block "id",
 * [gallery ids], gallery block "ids"), featured images, WooCommerce product galleries,
 * custom fields (ACF, Elementor, page builders: URL / ID / JSON / serialized),
 * term images, site logo / icon, theme mods, widgets, menus, comments.
 *
 * Errs on the side of "used": anything that looks like a reference keeps the file.
 */
class WPMO_Usage {

	const META       = '_wpmo_usage';        // 'used' | 'unused'
	const META_WHERE = '_wpmo_usage_where';

	/** @return array { used: bool, where: string } */
	public static function check( $id ) {
		$id = (int) $id;
		foreach ( array( 'by_id', 'by_url' ) as $m ) {
			$where = self::$m( $id );
			if ( $where ) {
				return array( 'used' => true, 'where' => $where );
			}
		}
		return array( 'used' => false, 'where' => '' );
	}

	public static function check_and_store( $id ) {
		$r = self::check( $id );
		update_post_meta( $id, self::META, $r['used'] ? 'used' : 'unused' );
		update_post_meta( $id, self::META_WHERE, $r['where'] );
		return $r;
	}

	/* ---------------- References by attachment ID ---------------- */

	private static function by_id( $id ) {
		global $wpdb;
		$s = (string) $id;

		// Featured image / WooCommerce gallery / custom fields holding the ID.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT post_id, meta_key FROM {$wpdb->postmeta}
			 WHERE post_id <> %d AND (
				( meta_value = %s AND ( meta_key IN ('_thumbnail_id','_wp_attachment_context_ref','_video_thumbnail_id') OR ( meta_key NOT LIKE %s AND meta_key <> 'total_sales' ) ) )
				OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %s, meta_value ) )
			 ) LIMIT 1",
			$id, $s, $wpdb->esc_like( '_' ) . '%', $s
		) );
		if ( $row ) {
			return self::post_label( $row->post_id ) . ' (' . $row->meta_key . ')';
		}

		// Post content: wp-image-ID, block JSON ids, gallery shortcode/block ids, attachment_id=ID.
		$likes = array(
			'%wp-image-' . $s . '%',
			'%wp-video-' . $s . '%',
			'%"id":' . $s . '%',
			'%"id":"' . $s . '"%',
			'%"mediaId":' . $s . '%',
			'%attachment_id=' . $s . '%',
			'%ids=%' . $s . '%',
			'%"ids":[%' . $s . '%',
		);
		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND ( {$where} )",
			$likes
		) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			if ( self::text_has_id( $r->post_content, $id ) ) {
				return self::post_label( $r->ID );
			}
		}

		// Custom fields with JSON / serialized IDs (Elementor, ACF gallery...).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id <> %d AND meta_key NOT LIKE %s AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata')
			 AND ( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )",
			$id,
			$wpdb->esc_like( '_wpmo' ) . '%',
			'%"id":' . $s . '%',
			'%"id":"' . $s . '"%',
			'%:"' . $s . '";%',
			'%wp-image-' . $s . '%'
		) );
		foreach ( $rows as $r ) {
			if ( self::text_has_id( $r->meta_value, $id ) || preg_match( '/s:\d+:"' . $s . '";/', $r->meta_value ) ) {
				return self::post_label( $r->post_id ) . ' (' . $r->meta_key . ')';
			}
		}

		// Site options: logo, icon, placeholders, theme mods, widgets.
		$opt = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_value = %s AND ( option_name IN ('site_icon','site_logo','woocommerce_placeholder_image')
			 OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ) LIMIT 1",
			$s, '%logo%', '%image%', '%icon%'
		) );
		if ( $opt ) {
			return 'تنظیمات: ' . $opt;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value LIKE %s",
			'theme\_mods\_%', 'widget\_%', '%' . $s . '%'
		) );
		foreach ( $rows as $r ) {
			if ( preg_match( '/"(custom_logo|attachment_id|site_logo|site_icon|header_image|background_image_id|image_id|id)";(?:i:' . $s . ';|s:\d+:"' . $s . '";)/', $r->option_value )
				|| self::text_has_id( $r->option_value, $id ) ) {
				return 'تنظیمات/ابزارک: ' . $r->option_name;
			}
		}

		// Term images (WooCommerce category thumbnails etc.).
		$term = $wpdb->get_var( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value = %s
			 AND ( meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s ) LIMIT 1",
			$s, '%thumbnail%', '%image%', '%logo%', '%icon%', '%\_id'
		) );
		if ( $term ) {
			return 'دسته/برچسب #' . $term;
		}

		return '';
	}

	private static function text_has_id( $text, $id ) {
		$id = (int) $id;
		if ( preg_match( '/wp-(?:image|video|audio)-' . $id . '(?!\d)/', $text ) ) {
			return true;
		}
		if ( preg_match( '/\\\\?"(?:id|mediaId|attachment_id|imageId|image_id)\\\\?"\s*:\s*\\\\?"?' . $id . '(?![\d])/', $text ) ) {
			return true;
		}
		if ( preg_match( '/attachment_id=' . $id . '(?!\d)/', $text ) ) {
			return true;
		}
		if ( preg_match_all( '/\bids\s*=\s*\\\\?["\']([\d,\s]+)\\\\?["\']/', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				if ( in_array( $id, array_map( 'intval', explode( ',', $list ) ), true ) ) {
					return true;
				}
			}
		}
		if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s"]*)\]/', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				if ( in_array( $id, array_map( 'intval', explode( ',', str_replace( '"', '', $list ) ) ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ---------------- References by file URL ---------------- */

	/** "2024/05/photo" (no extension, no -scaled) — matches the file and all its sizes. */
	private static function stem( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$rel  = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $rel ) {
			return '';
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$rel = ( false !== strpos( $rel, '/' ) ? dirname( $rel ) . '/' : '' ) . $meta['original_image'];
		}
		$rel = preg_replace( '/\.[^.\/]+$/', '', $rel );
		return preg_replace( '/-scaled$/', '', $rel );
	}

	private static function by_url( $id ) {
		global $wpdb;
		$stem = self::stem( $id );
		if ( '' === $stem ) {
			return '';
		}
		$u       = wp_get_upload_dir();
		$folder  = wp_basename( untrailingslashit( wp_normalize_path( $u['basedir'] ) ) ); // usually "uploads"
		$plain   = $folder . '/' . $stem;
		$escaped = str_replace( '/', '\\/', $plain );

		$segments = array_map(
			function ( $p ) {
				return preg_quote( $p, '~' );
			},
			explode( '/', $plain )
		);
		$regex = '~' . implode( '(?:/|\\\\/|%2F)', $segments ) . '(?:-[^\s"\'\\\\/<>?#]*)?\.[A-Za-z0-9]{2,5}~i';

		$l1 = '%' . $wpdb->esc_like( $plain ) . '%';
		$l2 = '%' . $wpdb->esc_like( $escaped ) . '%';

		$checks = array(
			array( "SELECT ID AS id, post_content AS v FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND ( post_content LIKE %s OR post_content LIKE %s )", 'post' ),
			array( "SELECT ID AS id, post_excerpt AS v FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND ( post_excerpt LIKE %s OR post_excerpt LIKE %s )", 'post' ),
			array( "SELECT post_id AS id, meta_value AS v FROM {$wpdb->postmeta} WHERE post_id <> " . (int) $id . " AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wpmo_backup') AND ( meta_value LIKE %s OR meta_value LIKE %s )", 'post' ),
			array( "SELECT option_name AS id, option_value AS v FROM {$wpdb->options} WHERE ( option_value LIKE %s OR option_value LIKE %s )", 'option' ),
			array( "SELECT term_id AS id, meta_value AS v FROM {$wpdb->termmeta} WHERE ( meta_value LIKE %s OR meta_value LIKE %s )", 'term' ),
			array( "SELECT user_id AS id, meta_value AS v FROM {$wpdb->usermeta} WHERE ( meta_value LIKE %s OR meta_value LIKE %s )", 'user' ),
			array( "SELECT comment_ID AS id, comment_content AS v FROM {$wpdb->comments} WHERE ( comment_content LIKE %s OR comment_content LIKE %s )", 'comment' ),
		);

		foreach ( $checks as $c ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $c[0], $l1, $l2 ) ); // phpcs:ignore
			foreach ( $rows as $r ) {
				if ( preg_match( $regex, $r->v ) ) {
					switch ( $c[1] ) {
						case 'post':
							return self::post_label( $r->id );
						case 'option':
							return 'تنظیمات: ' . $r->id;
						case 'term':
							return 'دسته/برچسب #' . $r->id;
						case 'user':
							return 'کاربر #' . $r->id;
						default:
							return 'دیدگاه #' . $r->id;
					}
				}
			}
		}
		return '';
	}

	private static function post_label( $post_id ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return '#' . $post_id;
		}
		$title = $p->post_title ? $p->post_title : '(بدون عنوان)';
		return $p->post_type . ' #' . $p->ID . ' «' . wp_html_excerpt( $title, 40, '…' ) . '»';
	}
}
