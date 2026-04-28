<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Video;

/**
 * Builds the JSON payload that the lesson player consumes for one video.
 *
 * Knows the four supported providers:
 *
 *   - `vimeo`  — embed via `https://player.vimeo.com/video/{id}`.
 *   - `youtube` — embed via `https://www.youtube.com/embed/{id}`.
 *   - `file`   — direct media file; embed shape stays `null` so the
 *                frontend renders an HTML5 `<video>` tag instead.
 *   - `embed`  — opaque oEmbed/iframe; same `null` embed/external_id.
 *
 * Pure value-shape factory — no `wp_remote_*`, no shortcodes, no DB. The
 * JSON shape is stable and consumed by 5.1b's lesson controller.
 *
 * @author Tymofii Synianskyi
 */
final class VideoPayloadBuilder {

	/**
	 * @return array{provider:string,url:string,embed_url:?string,external_id:?string}|null
	 */
	public function build( string $provider, string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}

		return match ( $provider ) {
			'vimeo'   => $this->build_vimeo( $url ),
			'youtube' => $this->build_youtube( $url ),
			'file'    => $this->payload( 'file', $url, null, null ),
			'embed'   => $this->payload( 'embed', $url, null, null ),
			default   => null,
		};
	}

	/**
	 * @return array{provider:string,url:string,embed_url:?string,external_id:?string}
	 */
	private function build_vimeo( string $url ): array {
		$id = $this->extract_vimeo_id( $url );
		if ( null === $id ) {
			return $this->payload( 'vimeo', $url, null, null );
		}
		return $this->payload( 'vimeo', $url, 'https://player.vimeo.com/video/' . $id, $id );
	}

	/**
	 * @return array{provider:string,url:string,embed_url:?string,external_id:?string}
	 */
	private function build_youtube( string $url ): array {
		$id = $this->extract_youtube_id( $url );
		if ( null === $id ) {
			return $this->payload( 'youtube', $url, null, null );
		}
		return $this->payload( 'youtube', $url, 'https://www.youtube.com/embed/' . $id, $id );
	}

	private function extract_vimeo_id( string $url ): ?string {
		// Vimeo IDs are runs of digits in the URL path; pick the last numeric
		// segment so private-link forms like `vimeo.com/123456789/abcdef`
		// still resolve to the canonical video id.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		$segments     = array_values(
			array_filter(
				explode( '/', $path ),
				static fn ( string $s ): bool => '' !== $s
			)
		);
		$last_numeric = null;
		foreach ( $segments as $segment ) {
			if ( 1 === preg_match( '/^\d+$/', $segment ) ) {
				$last_numeric = $segment;
			}
		}
		return $last_numeric;
	}

	private function extract_youtube_id( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';

		// Short link form: youtu.be/{id}
		if ( is_string( $host ) && str_ends_with( $host, 'youtu.be' ) && '' !== $path ) {
			return $this->validate_youtube_id( explode( '/', $path )[0] );
		}

		// Embed form: youtube.com/embed/{id}
		if ( str_starts_with( $path, 'embed/' ) ) {
			$tail = substr( $path, strlen( 'embed/' ) );
			$id   = explode( '/', $tail )[0];
			return $this->validate_youtube_id( $id );
		}

		// Watch form: youtube.com/watch?v={id}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $params );
			if ( isset( $params['v'] ) && is_string( $params['v'] ) ) {
				return $this->validate_youtube_id( $params['v'] );
			}
		}

		return null;
	}

	private function validate_youtube_id( string $id ): ?string {
		if ( '' === $id ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $id ) ) {
			return null;
		}
		return $id;
	}

	/**
	 * @return array{provider:string,url:string,embed_url:?string,external_id:?string}
	 */
	private function payload( string $provider, string $url, ?string $embed_url, ?string $external_id ): array {
		return [
			'provider'    => $provider,
			'url'         => $url,
			'embed_url'   => $embed_url,
			'external_id' => $external_id,
		];
	}
}
