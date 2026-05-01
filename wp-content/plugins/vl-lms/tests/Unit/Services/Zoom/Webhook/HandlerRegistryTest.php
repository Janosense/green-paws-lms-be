<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerRegistry;

final class HandlerRegistryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @return array{
	 *     registry: HandlerRegistry,
	 *     started: EventHandler,
	 *     ended: EventHandler,
	 *     joined: EventHandler,
	 *     left: EventHandler,
	 *     rec: EventHandler,
	 * }
	 */
	private function registry(): array {
		$started   = Mockery::mock( EventHandler::class );
		$ended     = Mockery::mock( EventHandler::class );
		$joined    = Mockery::mock( EventHandler::class );
		$left      = Mockery::mock( EventHandler::class );
		$recording = Mockery::mock( EventHandler::class );
		$registry  = new HandlerRegistry( $started, $ended, $joined, $left, $recording );
		return [
			'registry' => $registry,
			'started'  => $started,
			'ended'    => $ended,
			'joined'   => $joined,
			'left'     => $left,
			'rec'      => $recording,
		];
	}

	public function test_each_operational_event_routes_to_its_handler(): void {
		$bag      = $this->registry();
		$registry = $bag['registry'];

		self::assertSame( $bag['started'], $registry->find( WebhookEventType::MEETING_STARTED ) );
		self::assertSame( $bag['ended'], $registry->find( WebhookEventType::MEETING_ENDED ) );
		self::assertSame( $bag['joined'], $registry->find( WebhookEventType::MEETING_PARTICIPANT_JOINED ) );
		self::assertSame( $bag['left'], $registry->find( WebhookEventType::MEETING_PARTICIPANT_LEFT ) );
		self::assertSame( $bag['rec'], $registry->find( WebhookEventType::RECORDING_COMPLETED ) );
	}

	public function test_url_validation_is_not_routed(): void {
		$bag      = $this->registry();
		$registry = $bag['registry'];

		self::assertNull( $registry->find( WebhookEventType::ENDPOINT_URL_VALIDATION ) );
	}
}
