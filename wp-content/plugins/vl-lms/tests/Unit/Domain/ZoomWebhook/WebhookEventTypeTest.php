<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\ZoomWebhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;

final class WebhookEventTypeTest extends TestCase {

	/**
	 * @return list<array{string, WebhookEventType}>
	 */
	public static function known_values(): array {
		return [
			[ 'meeting.started', WebhookEventType::MEETING_STARTED ],
			[ 'meeting.ended', WebhookEventType::MEETING_ENDED ],
			[ 'meeting.participant_joined', WebhookEventType::MEETING_PARTICIPANT_JOINED ],
			[ 'meeting.participant_left', WebhookEventType::MEETING_PARTICIPANT_LEFT ],
			[ 'recording.completed', WebhookEventType::RECORDING_COMPLETED ],
			[ 'endpoint.url_validation', WebhookEventType::ENDPOINT_URL_VALIDATION ],
		];
	}

	/**
	 * @dataProvider known_values
	 */
	public function test_from_string_resolves_known_value( string $value, WebhookEventType $expected ): void {
		self::assertSame( $expected, WebhookEventType::from_string( $value ) );
	}

	public function test_from_string_returns_null_for_unknown_value(): void {
		self::assertNull( WebhookEventType::from_string( 'meeting.exploded' ) );
	}

	public function test_from_string_returns_null_for_empty_value(): void {
		self::assertNull( WebhookEventType::from_string( '' ) );
	}
}
