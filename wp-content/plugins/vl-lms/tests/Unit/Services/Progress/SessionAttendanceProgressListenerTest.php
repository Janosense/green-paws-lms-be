<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Progress\CompletionPropagator;
use VL\LMS\Services\Progress\CourseProgressCalculator;
use VL\LMS\Services\Progress\SessionAttendanceProgressListener;

final class SessionAttendanceProgressListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&CourseProgressCalculator */
	private $calculator;

	/** @var Mockery\MockInterface&CompletionPropagator */
	private $propagator;

	private SessionAttendanceProgressListener $listener;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->calculator = Mockery::mock( CourseProgressCalculator::class );
		$this->propagator = Mockery::mock( CompletionPropagator::class );
		$this->listener   = new SessionAttendanceProgressListener( $this->calculator, $this->propagator );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_recomputes_and_reevaluates_for_known_user(): void {
		$this->calculator->shouldReceive( 'recompute' )->once()->with( 5, 100 )->andReturn( 100 );
		$this->propagator->shouldReceive( 'reevaluate_course_completion' )->once()->with( 5, 100 )->andReturn( true );

		$this->listener->on_attendance_recorded( 200, 5, 100 );
	}

	public function test_short_circuits_when_user_id_is_null(): void {
		$this->calculator->shouldNotReceive( 'recompute' );
		$this->propagator->shouldNotReceive( 'reevaluate_course_completion' );

		$this->listener->on_attendance_recorded( 200, null, 100 );
	}

	public function test_short_circuits_when_user_id_non_positive(): void {
		$this->calculator->shouldNotReceive( 'recompute' );

		$this->listener->on_attendance_recorded( 200, 0, 100 );
	}

	public function test_short_circuits_when_course_id_non_positive(): void {
		$this->calculator->shouldNotReceive( 'recompute' );

		$this->listener->on_attendance_recorded( 200, 5, 0 );
	}
}
