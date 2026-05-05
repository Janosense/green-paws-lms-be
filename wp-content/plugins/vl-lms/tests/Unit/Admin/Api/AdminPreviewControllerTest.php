<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Api\AdminPreviewController;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

final class AdminPreviewControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, ?WP_Post> */
	private array $courses_by_slug = [];

	/** @var array<string, list<WP_Post>> */
	private array $children = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				return $response;
			}
		);

		$this->courses_by_slug = [];
		$this->children        = [];

		$courses  = &$this->courses_by_slug;
		$children = &$this->children;

		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug, string $output = OBJECT, string $post_type = 'post' ) use ( &$courses ): ?WP_Post {
				unset( $output );
				return $courses[ $post_type . '|' . $slug ] ?? null;
			}
		);
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$children ): array {
				$key = ( $args['post_type'] ?? '' ) . '|' . ( (int) ( $args['post_parent'] ?? 0 ) );
				return $children[ $key ] ?? [];
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_404_when_course_not_found(): void {
		$controller = new AdminPreviewController( 'vl/v1' );
		$request    = $this->request( [ 'slug' => 'unknown-course' ] );

		$response = $controller->preview_info( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'course_not_found', $response->get_error_code() );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_returns_preview_url_with_first_lesson_slug(): void {
		$course = $this->post( 100, 'cardiology', 'vl_course' );
		$module = $this->post( 200, 'module-one', 'vl_module' );
		$lesson = $this->post( 300, 'introduction-to-anatomy', 'vl_lesson' );

		$this->courses_by_slug['vl_course|cardiology'] = $course;
		$this->children['vl_module|100']               = [ $module ];
		$this->children['vl_lesson|200']               = [ $lesson ];

		$controller = new AdminPreviewController( 'vl/v1' );
		$request    = $this->request( [ 'slug' => 'cardiology' ] );

		$response = $controller->preview_info( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertSame( 'introduction-to-anatomy', $data['first_lesson_slug'] );
		self::assertStringEndsWith( '?preview=1', $data['preview_url'] );
		self::assertStringContainsString( '/learn/introduction-to-anatomy', $data['preview_url'] );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( array $params ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		foreach ( $params as $name => $value ) {
			$request->shouldReceive( 'get_param' )->with( $name )->andReturn( $value );
		}
		$request->shouldReceive( 'get_param' )->andReturn( null );
		return $request;
	}

	private function post( int $id, string $slug, string $type ): WP_Post {
		$post              = Mockery::mock( WP_Post::class );
		$post->ID          = $id;
		$post->post_name   = $slug;
		$post->post_title  = ucfirst( $slug );
		$post->post_type   = $type;
		$post->post_status = 'publish';
		return $post;
	}
}
