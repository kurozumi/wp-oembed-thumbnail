<?php

/*
  Plugin Name: WP oEmbed Thumbnail
  Version: 0.1-alpha
  Description: WordPressが対応しているoEmbed対応サイトのURLを記事中に埋め込むとそのURLのサムネイルを取得してアイキャッチ画像に登録します。
  Author: kurozumi
  Author URI: http://a-zumi.net
  Plugin URI: http://a-zumi.net
  Text Domain: wp-oembed-thumbnail
  Domain Path: /languages
 */

$oembed_thumbnail = new WP_oEmbed_Thumbnail;
$oembed_thumbnail->register();

class WP_oEmbed_Thumbnail {

	public function register()
	{
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}
	
	public function plugins_loaded()
	{
		add_action('transition_post_status', array(&$this, 'transition_post_status'), 99, 3);
	}

	public function transition_post_status($new_status, $old_status, $post)
	{
		if ($post->post_type == 'post' && $new_status == 'publish') {
			switch ($old_status) {
				case 'new':  // 前のステータスがないとき
				case 'draft':   // 下書き状態の投稿
				case 'pending': // レビュー待ちの投稿
				case 'auto-draft': // 新しく作成された内容がない投稿
				case 'future':  // 公開予約された投稿
					// アイキャッチを登録
					$this->set_post_thumbnail($post);
					break;
				case 'private': // 非公開の投稿
				case 'publish': // 公開された投稿やページ
					break;
			}
		}
	}

	public function set_post_thumbnail($post)
	{
		// アイキャッチ画像が登録済みなら終了
		if (has_post_thumbnail($post->ID))
			return;

		require_once( ABSPATH . WPINC . '/class-oembed.php' );
		$oembed = new WP_oEmbed();

		$provider = false;

		foreach ($oembed->providers as $matchmask => $data) {
			list( $providerurl, $regex ) = $data;

			if (!$regex) {
				$matchmask = '#' . str_replace('___wildcard___', '(.+)', preg_quote(str_replace('*', '___wildcard___', $matchmask), '#')) . '#i';
				$matchmask = preg_replace('|^#http\\\://|', '#https?\://', $matchmask);
			}
			
			if (preg_match($matchmask, $post->post_content, $matches)) {
				$provider = str_replace('{format}', 'json', $providerurl);
				break;
			}
		}
		
		if ($provider) {
			
			if(!($data = $oembed->fetch($provider, trim($matches[0]))))
				return false;
			
			if (!isset($data->thumbnail_url))
				return false;

			if(!($file_data = @file_get_contents($data->thumbnail_url)))
				return false;

			// アップロードディレクトリ
			$uploads = wp_upload_dir();

			// ファイル名
			$filename = wp_unique_filename($uploads['path'], basename(parse_url($data->thumbnail_url, PHP_URL_PATH)));
			
			// ファイルタイプ
			$wp_filetype = wp_check_filetype($filename, null);

			// アップロードパス
			$new_file = sprintf("%s/%s", $uploads['path'], $filename);

			if (!file_put_contents($new_file, $file_data))
				return false;

			$attachment = array(
				'guid' => sprintf("%s/%s", $uploads['url'], $filename),
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
				'post_content' => '',
				'post_status' => 'inherit',
			);

			// メディアライブラリに添付ファイルを挿入
			$attachment_id = wp_insert_attachment($attachment, $filename, $post->ID);

			if (!$attachment_id)
				return false;

			require_once( ABSPATH . 'wp-admin/includes/image.php' );

			// 画像添付ファイルのメタデータを生成
			$attachment_data = wp_generate_attachment_metadata($attachment_id, $new_file);

			if (empty($attachment_data))
				return false;

			// 画像添付ファイルのメタデータを更新
			if (!wp_update_attachment_metadata($attachment_id, $attachment_data))
				return false;

			// 画像添付ファイルのファイルパスを更新
			if (!update_attached_file($attachment_id, $new_file))
				return false;

			// アイキャッチ画像を設定
			if (!set_post_thumbnail($post->ID, $attachment_id))
				return false;
		}
	}

}
