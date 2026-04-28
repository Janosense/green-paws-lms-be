<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;
use VL\LMS\Learn\Video\VideoPayloadBuilder;

/**
 * Transforms a `core/embed` block into `{type: embed, provider, url, embed_url}`.
 *
 * Provider detection looks at the URL host: `vimeo.com` /
 * `player.vimeo.com` → `vimeo`, `youtube.com` / `youtu.be` → `youtube`,
 * everything else → `other`. The `embed_url` is filled only for vimeo /
 * youtube using {@see VideoPayloadBuilder} so the embed shape stays in
 * lockstep with the lesson video shape.
 *
 * @author Tymofii Synianskyi
 */
final class EmbedBlockTransformer implements BlockTransformer {

	public function __construct( private readonly VideoPayloadBuilder $video_builder ) {
	}

	public function supports( string $block_name ): bool {
		return 'core/embed' === $block_name;
	}

	/**
	 * @return array{type:string,provider:string,url:string,embed_url:?string}
	 */
	public function transform( ParsedBlock $block ): array {
		$url = isset( $block->attrs['url'] ) && is_string( $block->attrs['url'] )
			? (string) $block->attrs['url']
			: '';

		$provider = $this->detect_provider( $url );

		$embed_url = null;
		if ( 'vimeo' === $provider || 'youtube' === $provider ) {
			$payload = $this->video_builder->build( $provider, $url );
			if ( null !== $payload ) {
				$embed_url = $payload['embed_url'];
			}
		}

		return [
			'type'      => 'embed',
			'provider'  => $provider,
			'url'       => esc_url_raw( $url ),
			'embed_url' => $embed_url,
		];
	}

	private function detect_provider( string $url ): string {
		if ( '' === $url ) {
			return 'other';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return 'other';
		}

		$host = strtolower( $host );

		if ( 'vimeo.com' === $host || 'player.vimeo.com' === $host || str_ends_with( $host, '.vimeo.com' ) ) {
			return 'vimeo';
		}
		if ( 'youtu.be' === $host || 'youtube.com' === $host || 'www.youtube.com' === $host || str_ends_with( $host, '.youtube.com' ) ) {
			return 'youtube';
		}

		return 'other';
	}
}
