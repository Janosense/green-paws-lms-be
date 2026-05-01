<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Webhook;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;
use VL\LMS\Services\Zoom\Webhook\EventHandler;
use VL\LMS\Services\Zoom\Webhook\HandlerOutcome;
use VL\LMS\Services\Zoom\Webhook\HandlerRegistry;
use VL\LMS\Services\Zoom\Webhook\WebhookEventDispatcher;
use VL\LMS\Services\Zoom\Webhook\WebhookRequest;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryZoomWebhookEventRepository;

final class WebhookEventDispatcherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private function clock(): \Closure {
		return static fn (): \DateTimeImmutable
			=> new \DateTimeImmutable( '2026-05-01T10:00:00Z' );
	}

	private function request( string $event, string $tracking_id = 'tr-1', string $body = '{}' ): WebhookRequest {
		return new WebhookRequest( $event, [ 'id' => 'm-1' ], 'A', $tracking_id, 1714540800000, $body, '' );
	}

	private function registry( EventHandler $started ): HandlerRegistry {
		$ended = Mockery::mock( EventHandler::class );
		$j     = Mockery::mock( EventHandler::class );
		$l     = Mockery::mock( EventHandler::class );
		$r     = Mockery::mock( EventHandler::class );

		return new HandlerRegistry( $started, $ended, $j, $l, $r );
	}

	public function test_duplicate_tracking_id_short_circuits_as_ignored(): void {
		$repo = new InMemoryZoomWebhookEventRepository();
		// Pre-seed an existing row.
		$repo->record(
			'tr-1',
			WebhookEventType::MEETING_STARTED,
			1,
			'm-1',
			'{}',
			new \DateTimeImmutable( '2026-04-30T10:00:00Z' )
		);

		$started = Mockery::mock( EventHandler::class );
		$started->shouldNotReceive( 'handle' );

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->clock()
		);

		$result = $dispatcher->dispatch( $this->request( 'meeting.started' ) );

		self::assertSame( WebhookProcessingStatus::IGNORED, $result->status );
		self::assertSame( 'duplicate_tracking_id', $result->message );
	}

	public function test_unsupported_event_recorded_and_ignored(): void {
		$repo    = new InMemoryZoomWebhookEventRepository();
		$started = Mockery::mock( EventHandler::class );
		$started->shouldNotReceive( 'handle' );

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->clock()
		);

		$result = $dispatcher->dispatch( $this->request( 'meeting.unknown_thing' ) );

		self::assertSame( WebhookProcessingStatus::IGNORED, $result->status );
		self::assertSame( 'unsupported_event', $result->message );
		self::assertSame( 1, $repo->count_by_status( WebhookProcessingStatus::IGNORED ) );
	}

	public function test_supported_event_invokes_handler_and_marks_processed(): void {
		$repo    = new InMemoryZoomWebhookEventRepository();
		$started = Mockery::mock( EventHandler::class );
		$started->shouldReceive( 'handle' )->once()
			->andReturn( HandlerOutcome::applied( 'status_advanced_to_live', 'ok' ) );

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->clock()
		);

		$result = $dispatcher->dispatch( $this->request( 'meeting.started' ) );

		self::assertSame( WebhookProcessingStatus::PROCESSED, $result->status );
		self::assertNotNull( $result->outcome );
		self::assertSame( 'status_advanced_to_live', $result->outcome->action_label );
		self::assertSame( 1, $repo->count_by_status( WebhookProcessingStatus::PROCESSED ) );
	}

	public function test_handler_throwable_marks_failed(): void {
		$repo    = new InMemoryZoomWebhookEventRepository();
		$started = Mockery::mock( EventHandler::class );
		$started->shouldReceive( 'handle' )->once()
			->andThrow( new \RuntimeException( 'kaboom' ) );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldReceive( 'error' )->once();
		$logger->shouldIgnoreMissing();

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			$logger,
			$this->clock()
		);

		$result = $dispatcher->dispatch( $this->request( 'meeting.started' ) );

		self::assertSame( WebhookProcessingStatus::FAILED, $result->status );
		self::assertNotNull( $result->exception );
		self::assertSame( 'kaboom', $result->exception->getMessage() );
		self::assertSame( 1, $repo->count_by_status( WebhookProcessingStatus::FAILED ) );
	}

	public function test_record_race_treated_as_duplicate(): void {
		// Repo always throws on record() — simulating the unique-constraint
		// race past the find_by_tracking_id() check.
		$repo = new class() extends \VL\LMS\Repositories\ZoomWebhookEventRepository {
			public function record(
				string $tracking_id,
				WebhookEventType $event_type,
				int $event_ts_ms,
				?string $object_id,
				string $payload_json,
				\DateTimeImmutable $received_at
			): \VL\LMS\Domain\ZoomWebhook\WebhookEvent {
				throw new \RuntimeException( 'duplicate key' );
			}

			public function find_by_tracking_id( string $tracking_id ): ?\VL\LMS\Domain\ZoomWebhook\WebhookEvent {
				return null;
			}
		};

		$started = Mockery::mock( EventHandler::class );
		$started->shouldNotReceive( 'handle' );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldReceive( 'warning' )->once();
		$logger->shouldIgnoreMissing();

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			$logger,
			$this->clock()
		);

		$result = $dispatcher->dispatch( $this->request( 'meeting.started' ) );

		self::assertSame( WebhookProcessingStatus::IGNORED, $result->status );
		self::assertSame( 'duplicate_tracking_id', $result->message );
	}

	public function test_dispatcher_never_throws_even_when_handler_throws_unwrapped(): void {
		$repo    = new InMemoryZoomWebhookEventRepository();
		$started = Mockery::mock( EventHandler::class );
		$started->shouldReceive( 'handle' )->once()
			->andThrow( new \LogicException( 'unexpected non-handler exception' ) );

		$dispatcher = new WebhookEventDispatcher(
			$repo,
			$this->registry( $started ),
			Mockery::mock( Logger::class )->shouldIgnoreMissing(),
			$this->clock()
		);

		// The whole point: this call must not bubble the exception.
		$result = $dispatcher->dispatch( $this->request( 'meeting.started' ) );

		self::assertSame( WebhookProcessingStatus::FAILED, $result->status );
	}
}
