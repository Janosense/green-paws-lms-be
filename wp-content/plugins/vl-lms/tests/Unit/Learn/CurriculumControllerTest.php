<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\RestAuthenticator;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Learn\CurriculumController;
use VL\LMS\Learn\CurriculumTransformer;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class CurriculumControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&CurriculumTransformer */
	private $transformer;

	private InMemoryEnrollmentRepository $enrollment_repo;

	private EnrollmentService $enrollments;

	private CurriculumController $controller;

	/** @var array<string, list<WP_Post>> Keyed by `"{post_type}|{slug}"`. */
	private array $posts_by_query = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? strtolower( trim( $v ) ) : ''
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( mixed $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				return $response;
			}
		);

		$index = &$this->posts_by_query;
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$index ): array {
				$key = ( $args['post_type'] ?? '' ) . '|' . ( $args['name'] ?? '' );
				return $index[ $key ] ?? [];
			}
		);

		$this->authenticator   = Mockery::mock( RestAuthenticator::class );
		$this->transformer     = Mockery::mock( CurriculumTransformer::class );
		$this->enrollment_repo = new InMemoryEnrollmentRepository();
		$this->enrollments     = new EnrollmentService( $this->enrollment_repo );

		$this->controller = new CurriculumController(
			'vl/v1',
			$this->authenticator,
			$this->enrollments,
			$this->transformer
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function user( int $id, bool $has_view_cap = true ): \WP_User {
		$user        = Mockery::mock( 'WP_User' );
		$user->ID    = $id;
		$user->roles = [ 'student' ];
		$user->shouldReceive( 'has_cap' )
			->with( CurriculumController::VIEW_CAPABILITY )
			->andReturn( $has_view_cap );
		return $user;
	}

	private function course( int $id, string $slug ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_course';
		$post->post_name   = $slug;
		$post->post_status = 'publish';
		$post->post_title  = ucwords( str_replace( '-', ' ', $slug ) );
		assert( $post instanceof WP_Post );
		$this->posts_by_query[ 'vl_course|' . $slug ] = [ $post ];
		return $post;
	}

	private function request( string $slug ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => 'slug' === $name ? $slug : null
		);
		$request->shouldReceive( 'get_header' )->andReturn( '' );
		return $request;
	}

	private function seed_active_enrollment( int $user_id, int $course_id ): void {
		$this->enrollment_repo->seed(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
	}

	public function test_register_routes_registers_curriculum_route(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 1, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/learn/courses/(?P<slug>[a-z0-9\-]+)/curriculum', $calls[0]['route'] );
		self::assertSame( 'GET', $calls[0]['args']['methods'] );
		self::assertTrue( $calls[0]['args']['args']['slug']['required'] );
	}

	// -------------------------------------------------------------------
	// permission_callback
	// -------------------------------------------------------------------

	public function test_permission_callback_returns_false_when_not_authenticated(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		self::assertFalse( $this->controller->permission_callback( $this->request( 'x' ) ) );
	}

	public function test_permission_callback_returns_false_when_user_lacks_view_cap(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5, false ) );

		self::assertFalse( $this->controller->permission_callback( $this->request( 'x' ) ) );
	}

	public function test_permission_callback_returns_true_when_authed_and_capable(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5, true ) );

		self::assertTrue( $this->controller->permission_callback( $this->request( 'x' ) ) );
	}

	// -------------------------------------------------------------------
	// handle
	// -------------------------------------------------------------------

	public function test_handler_returns_unauthorized_when_authenticator_returns_null(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->handle( $this->request( 'feline-cardio' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'unauthorized', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_handler_returns_404_when_course_slug_not_found(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$result = $this->controller->handle( $this->request( 'no-such-course' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'course_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_handler_returns_403_not_enrolled_when_no_active_enrollment(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->course( 100, 'feline-cardio' );

		$result = $this->controller->handle( $this->request( 'feline-cardio' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_handler_returns_403_when_enrollment_revoked(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->course( 100, 'feline-cardio' );
		$this->enrollment_repo->seed(
			[
				'user_id'   => 5,
				'course_id' => 100,
				'status'    => EnrollmentStatus::REVOKED->value,
			]
		);

		$result = $this->controller->handle( $this->request( 'feline-cardio' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
	}

	public function test_handler_returns_envelope_on_success(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$course = $this->course( 100, 'feline-cardio' );
		$this->seed_active_enrollment( 5, 100 );

		$payload = [ 'course' => [ 'id' => 100 ] ];
		$this->transformer->shouldReceive( 'transform' )
			->once()
			->with( $course, 5 )
			->andReturn( $payload );

		$response = $this->controller->handle( $this->request( 'feline-cardio' ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame(
			[
				'success' => true,
				'data'    => $payload,
			],
			$response->get_data()
		);
	}
}
