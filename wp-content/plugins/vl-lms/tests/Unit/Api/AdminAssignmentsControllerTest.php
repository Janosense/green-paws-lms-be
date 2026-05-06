<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\AdminAssignmentsController;
use VL\LMS\Api\Transformers\SubmissionTransformer;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\InvalidScoreException;
use VL\LMS\Tests\Fixtures\InMemoryAssignmentSubmissionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminAssignmentsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&AssignmentSubmissionService */
	private $service;

	private InMemoryAssignmentSubmissionRepository $repo;

	private AdminAssignmentsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $payload ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class )->makePartial();
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					function ( int $status ) use ( $response ): WP_REST_Response {
						$response->status = $status;
						return $response;
					}
				);
				$response->shouldReceive( 'get_data' )->andReturn( $payload );
				$response->status = 200;
				return $response;
			}
		);

		$this->service = Mockery::mock( AssignmentSubmissionService::class );
		$this->repo    = new InMemoryAssignmentSubmissionRepository();

		$this->controller = new AdminAssignmentsController(
			'vl/v1',
			$this->service,
			$this->repo,
			new SubmissionTransformer()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_grade_returns_422_on_invalid_score(): void {
		$this->service->shouldReceive( 'grade' )
			->once()
			->andThrow( new InvalidScoreException( 200, 100 ) );

		$request = $this->request(
			[ 'id' => 5 ],
			[
				'score'    => 200,
				'feedback' => null,
			]
		);

		$response = $this->controller->handle_grade( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_score', $response->get_error_code() );
		self::assertSame( 422, $response->get_error_data()['status'] );
	}

	public function test_reject_returns_updated_submission(): void {
		$rejected = new Submission(
			id: 5,
			assignment_id: 200,
			user_id: 9,
			status: SubmissionStatus::REJECTED,
			submission_text: 'first attempt',
			submission_file_url: null,
			submission_file_name: null,
			score: null,
			feedback: 'incomplete',
			graded_by: 1,
			submitted_at: '2026-05-06 09:00:00',
			graded_at: '2026-05-06 11:00:00'
		);

		$this->service->shouldReceive( 'reject' )
			->once()
			->with( 5, 'incomplete', 1 )
			->andReturn( $rejected );

		$request = $this->request(
			[ 'id' => 5 ],
			[ 'feedback' => 'incomplete' ]
		);

		$response = $this->controller->handle_reject( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->status );
		$data = $response->get_data();
		self::assertSame( 'rejected', $data['data']['status'] );
		self::assertSame( 'incomplete', $data['data']['feedback'] );
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $body
	 */
	private function request( array $params, array $body ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		foreach ( $params as $name => $value ) {
			$request->shouldReceive( 'get_param' )->with( $name )->andReturn( $value );
		}
		$request->shouldReceive( 'get_param' )->andReturn( null );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );
		return $request;
	}
}
