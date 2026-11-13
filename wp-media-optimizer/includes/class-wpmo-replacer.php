<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces old media URLs with new ones across the database
 * (post content, excerpts, post meta, options) — serialized data safe.
 */
class WPMO_Replacer {

	/**
	 * Build a search=>replace map from uploads-relative paths.
	 * Keys are anchored on the uploads folder name (e.g. /uploads/2024/05/a.jpg) so they match
	 * absolute and relative URLs, http/https, www/non-www, sub-directory installs and CDN hosts,
	 * in plain, JSON-escaped and URL-encoded form.
	 *
	 * @param array $rel_map old relative path => new relative path.
	 */
	public static function build_map( $rel_map ) {
		$uploads = wp_get_upload_dir();
		$base    = '/' . wp_basename( untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) );

		$map = array();
		foreach ( $rel_map as $old => $new ) {
			if ( $old === $new || '' === $old ) {
				continue;
			}
			$o = $base . '/' . ltrim( $old, '/' );
			$n = $base . '/' . ltrim( $new, '/' );

			$map[ $o ] = $n;
			$map[ str_replace( '/', '\\/', $o ) ] = str_replace( '/', '\\/', $n ); // JSON (Elementor, Gutenberg attrs...).
			$map[ rawurlencode( $o ) ]           = rawurlencode( $n );
		}
		return $map;
	}

	public static function replace_everywhere( $rel_map ) {
		global $wpdb;

		$map = self::build_map( $rel_map );
		if ( empty( $map ) ) {
			return 0;
		}

		$changed = 0;
		$likes   = array();
		foreach ( array_keys( $map ) as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		// ---- Posts ----
		$where = implode( ' OR ', array_fill( 0, count( $likes ), '(post_content LIKE %s OR post_excerpt LIKE %s)' ) );
		$args  = array();
		foreach ( $likes as $l ) {
			$args[] = $l;
			$args[] = $l;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE {$where}", $args ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$content = strtr( $r->post_content, $map );
			$excerpt = strtr( $r->post_excerpt, $map );
			if ( $content !== $r->post_content || $excerpt !== $r->post_excerpt ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content, 'post_excerpt' => $excerpt ), array( 'ID' => $r->ID ) );
				clean_post_cache( $r->ID );
				$changed++;
			}
		}

		// ---- Meta tables & options ----
		$tables = array(
			array( $wpdb->postmeta, 'meta_id', 'meta_value' ),
			array( $wpdb->termmeta, 'meta_id', 'meta_value' ),
			array( $wpdb->usermeta, 'umeta_id', 'meta_value' ),
			array( $wpdb->options, 'option_id', 'option_value' ),
		);
		foreach ( $tables as $t ) {
			list( $table, $pk, $col ) = $t;
			$where = implode( ' OR ', array_fill( 0, count( $likes ), "{$col} LIKE %s" ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT {$pk} AS id, {$col} AS val FROM {$table} WHERE {$where}", $likes ) ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$new = self::replace_value( $r->val, $map );
				if ( $new !== $r->val ) {
					$wpdb->update( $table, array( $col => $new ), array( $pk => $r->id ) );
					$changed++;
				}
			}
		}

		wp_cache_flush();

		// Elementor keeps generated CSS with image URLs.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) { // phpcs:ignore
			}
		}

		return $changed;
	}

	/** Replace inside a raw DB value, keeping serialized data valid. */
	public static function replace_value( $raw, $map ) {
		if ( is_serialized( $raw ) ) {
			$data = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== $raw ) {
				return $raw; // Can't safely touch it.
			}
			if ( self::contains_incomplete_object( $data ) ) {
				return $raw;
			}
			return serialize( self::replace_recursive( $data, $map ) ); // phpcs:ignore
		}
		return strtr( $raw, $map );
	}

	private static function replace_recursive( $data, $map ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				return self::replace_value( $data, $map );
			}
			return strtr( $data, $map );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::replace_recursive( $v, $map );
			}
		}
		return $data;
	}

	private static function contains_incomplete_object( $data ) {
		if ( is_object( $data ) ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				if ( self::contains_incomplete_object( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
