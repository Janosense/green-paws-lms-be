<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Learn\Access\LessonAccessGate;
use VL\LMS\Learn\LessonContentController;
use VL\LMS\Learn\LessonContentTransformer;
use VL\LMS\Learn\TopicContentTransformer;
use VL\LMS\Auth\RestAuthenticator;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class LessonContentControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&RestAuthenticator */
	private $authenticator;

	/** @var Mockery\MockInterface&LessonAccessGate */
	private $gate;

	/** @var Mockery\MockInterface&LessonContentTransformer */
	private $lesson_transformer;

	/** @var Mockery\MockInterface&TopicContentTransformer */
	private $topic_transformer;

	private LessonContentController $controller;

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
				$status = 200;
				$response->shouldReceive( 'set_status' )->andReturnUsing(
					static function ( int $code ) use ( $response, &$status ): WP_REST_Response {
						$status = $code;
						return $response;
					}
				);
				$response->shouldReceive( 'get_status' )->andReturnUsing(
					static function () use ( &$status ): int {
						return $status;
					}
				);
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

		$this->authenticator      = Mockery::mock( RestAuthenticator::class );
		$this->gate               = Mockery::mock( LessonAccessGate::class );
		$this->lesson_transformer = Mockery::mock( LessonContentTransformer::class );
		$this->topic_transformer  = Mockery::mock( TopicContentTransformer::class );

		$this->controller = new LessonContentController(
			'vl/v1',
			$this->authenticator,
			$this->gate,
			$this->lesson_transformer,
			$this->topic_transformer
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
			->with( LessonContentController::VIEW_CAPABILITY )
			->andReturn( $has_view_cap );
		return $user;
	}

	private function post( int $id, string $type, string $slug, string $status = 'publish' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_name   = $slug;
		$post->post_status = $status;
		$post->post_title  = ucwords( str_replace( '-', ' ', $slug ) );
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function seed_published_post( WP_Post $post ): void {
		$key                          = $post->post_type . '|' . $post->post_name;
		$this->posts_by_query[ $key ] = [ $post ];
	}

	private function request( string $slug ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $name ): mixed => 'slug' === $name ? $slug : null
		);
		$request->shouldReceive( 'get_header' )->andReturn( '' );
		$request->shouldReceive( 'offsetExists' )->andReturnUsing(
			static fn ( string $name ): bool => 'slug' === $name
		);
		$request->shouldReceive( 'offsetGet' )->andReturnUsing(
			static fn ( string $name ): mixed => 'slug' === $name ? $slug : null
		);
		return $request;
	}

	public function test_register_routes_registers_lesson_and_topic_endpoints(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/learn/lessons/(?P<slug>[a-z0-9\-]+)', $calls[0]['route'] );
		self::assertSame( 'GET', $calls[0]['args']['methods'] );
		self::assertTrue( $calls[0]['args']['args']['slug']['required'] );
		self::assertSame( '/learn/topics/(?P<slug>[a-z0-9\-]+)', $calls[1]['route'] );
		self::assertSame( 'GET', $calls[1]['args']['methods'] );
	}

	// ---------------------------------------------------------------------
	// permission_callback
	// ---------------------------------------------------------------------

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

	// ---------------------------------------------------------------------
	// handle_lesson
	// ---------------------------------------------------------------------

	public function test_lesson_handler_returns_unauthorized_when_authenticator_returns_null(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->handle_lesson( $this->request( 'intro' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'unauthorized', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_lesson_handler_returns_404_when_slug_not_found(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$result = $this->controller->handle_lesson( $this->request( 'no-such-slug' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'lesson_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_lesson_handler_maps_parent_not_found_to_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->seed_published_post( $this->post( 123, 'vl_lesson', 'orphaned' ) );
		$this->gate->shouldReceive( 'check' )->andReturn( AccessDecision::deny( 'parent_not_found' ) );

		$result = $this->controller->handle_lesson( $this->request( 'orphaned' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'parent_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_lesson_handler_maps_course_unpublished_to_404(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->seed_published_post( $this->post( 123, 'vl_lesson', 'intro' ) );
		$this->gate->shouldReceive( 'check' )->andReturn( AccessDecision::deny( 'course_unpublished', 100 ) );

		$result = $this->controller->handle_lesson( $this->request( 'intro' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'course_unpublished', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_lesson_handler_maps_not_enrolled_to_403(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->seed_published_post( $this->post( 123, 'vl_lesson', 'intro' ) );
		$this->gate->shouldReceive( 'check' )->andReturn( AccessDecision::deny( 'not_enrolled', 100 ) );

		$result = $this->controller->handle_lesson( $this->request( 'intro' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_lesson_handler_returns_envelope_on_success(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$lesson = $this->post( 123, 'vl_lesson', 'intro' );
		$this->seed_published_post( $lesson );

		$decision = AccessDecision::allow( 100, false );
		$this->gate->shouldReceive( 'check' )->andReturn( $decision );
		$this->lesson_transformer->shouldReceive( 'transform' )
			->once()
			->with( $lesson, 5, $decision )
			->andReturn( [ 'id' => 123 ] );

		$response = $this->controller->handle_lesson( $this->request( 'intro' ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame(
			[
				'success' => true,
				'data'    => [ 'id' => 123 ],
			],
			$response->get_data()
		);
	}

	// ---------------------------------------------------------------------
	// handle_topic
	// ---------------------------------------------------------------------

	public function test_topic_handler_returns_unauthorized_when_authenticator_returns_null(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( null );

		$result = $this->controller->handle_topic( $this->request( 'a' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'unauthorized', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_topic_handler_returns_404_when_slug_not_found(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );

		$result = $this->controller->handle_topic( $this->request( 'no-such-topic' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'topic_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_topic_handler_maps_not_enrolled_to_403(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$this->seed_published_post( $this->post( 200, 'vl_topic', 'anatomy' ) );
		$this->gate->shouldReceive( 'check' )->andReturn( AccessDecision::deny( 'not_enrolled', 100 ) );

		$result = $this->controller->handle_topic( $this->request( 'anatomy' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'not_enrolled', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_topic_handler_returns_envelope_on_success(): void {
		$this->authenticator->shouldReceive( 'user_from_request' )->andReturn( $this->user( 5 ) );
		$topic = $this->post( 200, 'vl_topic', 'anatomy' );
		$this->seed_published_post( $topic );

		$decision = AccessDecision::allow( 100, false );
		$this->gate->shouldReceive( 'check' )->andReturn( $decision );
		$this->topic_transformer->shouldReceive( 'transform' )
			->once()
			->with( $topic, 5, $decision )
			->andReturn( [ 'id' => 200 ] );

		$response = $this->controller->handle_topic( $this->request( 'anatomy' ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame(
			[
				'success' => true,
				'data'    => [ 'id' => 200 ],
			],
			$response->get_data()
		);
	}
}
