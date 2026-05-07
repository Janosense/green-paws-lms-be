<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\CatalogDetailController;
use VL\LMS\Catalog\Detail\CourseDetailTransformer;
use VL\LMS\Catalog\Detail\WebinarDetailTransformer;
use WP_Error;
use WP_Post;
use WP_REST_Response;

final class CatalogDetailControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CatalogDetailController $controller;

	/** @var Mockery\MockInterface&CourseDetailTransformer */
	private $course_detail;

	/** @var Mockery\MockInterface&WebinarDetailTransformer */
	private $webinar_detail;

	/** @var array<string, list<WP_Post>> */
	private array $posts_by_slug = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? strtolower( trim( $v ) ) : ''
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				return $response;
			}
		);

		Functions\when( 'get_posts' )->alias(
			fn ( array $args ): array => $this->posts_by_slug[ $this->cache_key( $args ) ] ?? []
		);

		$this->course_detail  = Mockery::mock( CourseDetailTransformer::class );
		$this->webinar_detail = Mockery::mock( WebinarDetailTransformer::class );

		$this->controller = new CatalogDetailController(
			'vl/v1',
			$this->course_detail,
			$this->webinar_detail,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_course_and_webinar(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertStringContainsString( '/catalog/courses/', $calls[0]['route'] );
		self::assertSame( 'GET', $calls[0]['args']['methods'] );
		self::assertSame( '__return_true', $calls[0]['args']['permission_callback'] );
		self::assertStringContainsString( '/catalog/webinars/', $calls[1]['route'] );
		self::assertSame( '__return_true', $calls[1]['args']['permission_callback'] );
	}

	public function test_route_regex_accepts_non_ascii_slugs(): void {
		// Regression: courses created before the create-time transliteration
		// listener was wired up may still have Cyrillic post_name in the DB.
		// The catalog detail route must accept those — otherwise WordPress
		// short-circuits at the regex match and never reaches the controller.
		self::assertMatchesRegularExpression(
			'#^' . str_replace( '#', '\\#', CatalogDetailController::COURSE_ROUTE ) . '$#u',
			'/catalog/courses/тест-когорта'
		);
		self::assertMatchesRegularExpression(
			'#^' . str_replace( '#', '\\#', CatalogDetailController::WEBINAR_ROUTE ) . '$#u',
			'/catalog/webinars/весняна-кардіологія'
		);
	}

	public function test_get_course_resolves_cyrillic_slug(): void {
		$post = $this->post( 555, 'тест-когорта', 'vl_course' );
		$this->posts_by_slug[ $this->cache_key(
			[
				'name'      => 'тест-когорта',
				'post_type' => 'vl_course',
			]
		) ]   = [ $post ];

		$this->course_detail
			->shouldReceive( 'transform' )
			->once()
			->with( $post )
			->andReturn( [ 'id' => 555 ] );

		$request  = $this->request( [ 'slug' => 'тест-когорта' ] );
		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 555, $response->get_data()['data']['id'] );
	}

	public function test_get_course_returns_404_for_missing_slug(): void {
		$request = $this->request( [ 'slug' => 'unknown-slug' ] );

		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_get_course_returns_404_for_empty_slug(): void {
		$request = $this->request( [ 'slug' => '' ] );

		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_not_found', $response->get_error_code() );
	}

	public function test_get_course_returns_404_when_slug_belongs_to_webinar(): void {
		// `get_posts` is filtered by post_type=vl_course; a slug that only
		// exists as a webinar must not bleed into the course endpoint.
		$webinar = $this->post( 234, 'spring-cardiology', 'vl_webinar' );
		$this->posts_by_slug[ $this->cache_key(
			[
				'name'      => 'spring-cardiology',
				'post_type' => 'vl_webinar',
			]
		) ]      = [ $webinar ];

		$request  = $this->request( [ 'slug' => 'spring-cardiology' ] );
		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_not_found', $response->get_error_code() );
	}

	public function test_get_course_returns_envelope_when_post_exists(): void {
		$post = $this->post( 123, 'cardiology-fundamentals', 'vl_course' );
		$this->posts_by_slug[ $this->cache_key(
			[
				'name'      => 'cardiology-fundamentals',
				'post_type' => 'vl_course',
			]
		) ]   = [ $post ];

		$this->course_detail
			->shouldReceive( 'transform' )
			->once()
			->with( $post )
			->andReturn(
				[
					'id'   => 123,
					'slug' => 'cardiology-fundamentals',
				]
			);

		$request  = $this->request( [ 'slug' => 'cardiology-fundamentals' ] );
		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$envelope = $response->get_data();
		self::assertTrue( $envelope['success'] );
		self::assertSame( 123, $envelope['data']['id'] );
	}

	public function test_get_webinar_returns_envelope_when_post_exists(): void {
		$post = $this->post( 234, 'spring-cardiology', 'vl_webinar' );
		$this->posts_by_slug[ $this->cache_key(
			[
				'name'      => 'spring-cardiology',
				'post_type' => 'vl_webinar',
			]
		) ]   = [ $post ];

		$this->webinar_detail
			->shouldReceive( 'transform' )
			->once()
			->with( $post )
			->andReturn( [ 'id' => 234 ] );

		$request  = $this->request( [ 'slug' => 'spring-cardiology' ] );
		$response = $this->controller->get_webinar( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 234, $response->get_data()['data']['id'] );
	}

	public function test_get_webinar_returns_404_when_slug_belongs_to_course(): void {
		$course = $this->post( 123, 'cardiology', 'vl_course' );
		$this->posts_by_slug[ $this->cache_key(
			[
				'name'      => 'cardiology',
				'post_type' => 'vl_course',
			]
		) ]     = [ $course ];

		$request  = $this->request( [ 'slug' => 'cardiology' ] );
		$response = $this->controller->get_webinar( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_not_found', $response->get_error_code() );
	}

	public function test_drafts_and_other_statuses_are_treated_as_not_found(): void {
		// We restrict get_posts to post_status=publish, so a draft post
		// never lands in the lookup. That's the "drafts return 404" path.
		$request  = $this->request( [ 'slug' => 'draft-only' ] );
		$response = $this->controller->get_course( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_not_found', $response->get_error_code() );
	}

	private function request( array $params ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		foreach ( $params as $name => $value ) {
			$request->shouldReceive( 'get_param' )->with( $name )->andReturn( $value );
		}
		$request->shouldReceive( 'get_param' )->andReturn( null );
		return $request;
	}

	private function post( int $id, string $slug, string $type ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_name   = $slug;
		$post->post_title  = ucfirst( $slug );
		$post->post_type   = $type;
		$post->post_status = 'publish';
		return $post;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function cache_key( array $args ): string {
		return ( $args['name'] ?? '' ) . '|' . ( $args['post_type'] ?? '' );
	}
}
