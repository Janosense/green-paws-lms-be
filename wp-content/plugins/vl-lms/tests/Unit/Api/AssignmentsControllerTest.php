<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\AssignmentsController;
use VL\LMS\Api\Transformers\SubmissionTransformer;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\AssignmentSubmissionService;
use VL\LMS\Services\Assignments\Exception\AssignmentSubmissionFailedException;
use VL\LMS\Tests\Fixtures\InMemoryAssignmentSubmissionRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class AssignmentsControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&AssignmentSubmissionService */
	private $service;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	private InMemoryAssignmentSubmissionRepository $repo;

	private TestableAssignmentsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
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

		$this->service       = Mockery::mock( AssignmentSubmissionService::class );
		$this->authenticator = Mockery::mock( RestAuthenticator::class );
		$this->repo          = new InMemoryAssignmentSubmissionRepository();

		$this->controller = new TestableAssignmentsController(
			'vl/v1',
			$this->service,
			$this->repo,
			new SubmissionTransformer(),
			$this->authenticator
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_submissions_returns_201_on_new_submission(): void {
		$user                              = $this->user( 9 );
		$assignment                        = $this->post( 200, 'demo-assignment' );
		$this->controller->assignment_post = $assignment;

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$submission = new Submission(
			id: 1,
			assignment_id: 200,
			user_id: 9,
			status: SubmissionStatus::PENDING,
			submission_text: 'Hello',
			submission_file_url: null,
			submission_file_name: null,
			score: null,
			feedback: null,
			graded_by: null,
			submitted_at: '2026-05-06 10:00:00',
			graded_at: null
		);

		$this->service->shouldReceive( 'submit' )
			->once()
			->with( 200, 9, 'Hello', null, null )
			->andReturn( $submission );

		$request = $this->request(
			[ 'slug' => 'demo-assignment' ],
			[ 'submission_text' => 'Hello' ]
		);

		$response = $this->controller->handle_submit( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->status );
	}

	public function test_post_submissions_returns_409_on_locked_submission(): void {
		$user                              = $this->user( 9 );
		$assignment                        = $this->post( 200, 'demo-assignment' );
		$this->controller->assignment_post = $assignment;

		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $user );

		$this->service->shouldReceive( 'submit' )
			->once()
			->andThrow(
				new AssignmentSubmissionFailedException(
					AssignmentSubmissionFailedException::SUBMISSION_LOCKED,
					'locked'
				)
			);

		$request = $this->request(
			[ 'slug' => 'demo-assignment' ],
			[ 'submission_text' => 'second try' ]
		);

		$response = $this->controller->handle_submit( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'submission_locked', $response->get_error_code() );
		self::assertSame( 409, $response->get_error_data()['status'] );
	}

	private function user( int $id ): WP_User {
		$user     = Mockery::mock( WP_User::class );
		$user->ID = $id;
		return $user;
	}

	private function post( int $id, string $slug ): WP_Post {
		$post             = Mockery::mock( WP_Post::class );
		$post->ID         = $id;
		$post->post_name  = $slug;
		$post->post_title = $slug;
		$post->post_type  = 'vl_assignment';
		return $post;
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
