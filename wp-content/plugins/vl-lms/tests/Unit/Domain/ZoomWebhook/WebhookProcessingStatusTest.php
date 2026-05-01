<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\ZoomWebhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;

final class WebhookProcessingStatusTest extends TestCase {

	/**
	 * @return list<array{string, WebhookProcessingStatus}>
	 */
	public static function known_values(): array {
		return [
			[ 'pending', WebhookProcessingStatus::PENDING ],
			[ 'processed', WebhookProcessingStatus::PROCESSED ],
			[ 'failed', WebhookProcessingStatus::FAILED ],
			[ 'ignored', WebhookProcessingStatus::IGNORED ],
		];
	}

	/**
	 * @dataProvider known_values
	 */
	public function test_from_string_resolves_known_value( string $value, WebhookProcessingStatus $expected ): void {
		self::assertSame( $expected, WebhookProcessingStatus::from_string( $value ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		WebhookProcessingStatus::from_string( 'half-baked' );
	}
}
