<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Content;

/**
 * Deterministic video provider/URL pairs for seeded lessons and topics.
 *
 * Distribution is keyed off `$index % 20` so the demo data exercises every
 * adapter the lesson-player runtime supports (file / vimeo / youtube /
 * none). Re-running the seeder reproduces the exact same provider/URL pair
 * for each lesson position — required for idempotency.
 *
 * @author Tymofii Synianskyi
 */
final class VideoPlaylist {

	/**
	 * @return array{provider: ?string, url: ?string}
	 */
	public static function for_index( int $index ): array {
		$bucket = ( ( $index % 20 ) + 20 ) % 20;

		if ( $bucket <= 11 ) {
			$files = [
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/Sintel.mp4',
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/TearsOfSteel.mp4',
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
				'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
			];
			return [
				'provider' => 'file',
				'url'      => $files[ $bucket % count( $files ) ],
			];
		}

		if ( $bucket <= 16 ) {
			return [
				'provider' => 'vimeo',
				'url'      => 'https://vimeo.com/76979871',
			];
		}

		if ( $bucket <= 18 ) {
			return [
				'provider' => 'youtube',
				'url'      => 'https://www.youtube.com/watch?v=YE7VzlLtp-4',
			];
		}

		return [
			'provider' => null,
			'url'      => null,
		];
	}
}
