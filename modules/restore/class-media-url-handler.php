<?php
/**
 * Media URL preservation/rewrite for partial restores.
 */

declare(strict_types=1);

namespace WUDT\Modules\Restore;

if (! defined('ABSPATH')) {
	exit;
}

class Media_URL_Handler {
	/**
	 * @return array<string,int>
	 */
	public function preserve_urls(string $source_site_url, string $fallback_base_url): array {
		global $wpdb;
		$source = rtrim(esc_url_raw($source_site_url), '/');
		$target = rtrim(esc_url_raw(home_url('/')), '/');
		$fallback = rtrim(esc_url_raw($fallback_base_url), '/');
		$uploads_path = '/wp-content/uploads/';
		$updated_posts = 0;
		$updated_meta  = 0;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
				'%' . $wpdb->esc_like($uploads_path) . '%'
			),
			ARRAY_A
		);
		foreach ((array) $posts as $post) {
			$content = (string) ($post['post_content'] ?? '');
			$new     = $this->replace_upload_url($content, $source, $target, $fallback, $uploads_path);
			if ($new !== $content) {
				$wpdb->update($wpdb->posts, array('post_content' => $new), array('ID' => (int) $post['ID']));
				$updated_posts++;
			}
		}

		$meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like($uploads_path) . '%'
			),
			ARRAY_A
		);
		foreach ((array) $meta as $row) {
			$value = (string) ($row['meta_value'] ?? '');
			$new   = $this->replace_upload_url($value, $source, $target, $fallback, $uploads_path);
			if ($new !== $value) {
				$wpdb->update($wpdb->postmeta, array('meta_value' => $new), array('meta_id' => (int) $row['meta_id']));
				$updated_meta++;
			}
		}

		return array('posts' => $updated_posts, 'meta' => $updated_meta);
	}

	private function replace_upload_url(string $content, string $source, string $target, string $fallback, string $uploads_path): string {
		$out = $content;
		if ('' !== $source) {
			$out = str_replace($source . $uploads_path, $target . $uploads_path, $out);
		}
		if ('' !== $fallback && '' !== $source) {
			$out = str_replace($source . $uploads_path, $fallback . $uploads_path, $out);
		}
		return $out;
	}
}
