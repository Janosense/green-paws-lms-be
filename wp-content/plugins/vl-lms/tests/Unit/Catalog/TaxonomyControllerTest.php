<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\TaxonomyController;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use WP_Error;
use WP_REST_Response;
use WP_Term;

final class TaxonomyControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TaxonomyController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias( static fn ( string $v ): string => strtolower( trim( $v ) ) );
		Functions\when( 'rest_sanitize_boolean' )->alias( static fn ( mixed $v ): bool => (bool) $v );
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'set_status' )->andReturnSelf();
				return $response;
			}
		);

		$this->controller = new TaxonomyController( 'vl/v1', new TaxonomyTermTransformer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_one_route_with_taxonomy_param(): void {
		$captured = null;
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$captured ): void {
				$captured = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertIsArray( $captured );
		self::assertSame( 'vl/v1', $captured['namespace'] );
		self::assertStringContainsString( '/taxonomies/', $captured['route'] );
		self::assertSame( '__return_true', $captured['args']['permission_callback'] );
	}

	public function test_unknown_taxonomy_returns_400(): void {
		$request = $this->build_request( [ 'taxonomy' => 'vl_unknown' ] );

		$response = $this->controller->list_terms( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_invalid_taxonomy', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_unknown_post_type_returns_400(): void {
		$request = $this->build_request(
			[
				'taxonomy'  => 'vl_category',
				'post_type' => 'vl_lesson',
			]
		);

		$response = $this->controller->list_terms( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_invalid_post_type', $response->get_error_code() );
	}

	public function test_in_use_filters_out_terms_with_zero_count(): void {
		Functions\when( 'get_terms' )->justReturn(
			[
				$this->term( 1, 'cardiology', 'Cardiology', 'vl_specialty', 0, 3 ),
				$this->term( 2, 'oncology', 'Oncology', 'vl_specialty', 0, 0 ),
			]
		);

		$request = $this->build_request(
			[
				'taxonomy' => 'vl_specialty',
				'in_use'   => true,
			]
		);

		$response = $this->controller->list_terms( $request );

		$payload = $this->extract_payload( $response );
		self::assertCount( 1, $payload['data']['items'] );
		self::assertSame( 'cardiology', $payload['data']['items'][0]['slug'] );
	}

	public function test_post_type_filter_passes_object_ids_to_get_terms(): void {
		Functions\when( 'get_posts' )->justReturn( [ 100, 101 ] );

		$captured_args = null;
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ) use ( &$captured_args ): array {
				$captured_args = $args;
				return [];
			}
		);

		$request = $this->build_request(
			[
				'taxonomy'  => 'vl_specialty',
				'post_type' => 'vl_course',
			]
		);

		$this->controller->list_terms( $request );

		self::assertSame( [ 100, 101 ], $captured_args['object_ids'] );
	}

	public function test_hierarchical_taxonomy_emits_parent_slug_field(): void {
		Functions\when( 'get_terms' )->justReturn(
			[
				$this->term( 7, 'cardiology', 'Cardiology', 'vl_category', 0, 4 ),
			]
		);

		$request = $this->build_request( [ 'taxonomy' => 'vl_category' ] );

		$payload = $this->extract_payload( $this->controller->list_terms( $request ) );

		self::assertArrayHasKey( 'parent_slug', $payload['data']['items'][0] );
		self::assertNull( $payload['data']['items'][0]['parent_slug'] );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function build_request( array $params ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn ( string $key ): mixed => $params[ $key ] ?? null
		);
		return $request;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extract_payload( WP_REST_Response|WP_Error $response ): array {
		self::assertInstanceOf( WP_REST_Response::class, $response );
		return $response->get_data();
	}

	private function term(
		int $id,
		string $slug,
		string $name,
		string $taxonomy,
		int $parent,
		int $count
	): WP_Term {
		$term           = Mockery::mock( 'WP_Term' );
		$term->term_id  = $id;
		$term->slug     = $slug;
		$term->name     = $name;
		$term->taxonomy = $taxonomy;
		$term->parent   = $parent;
		$term->count    = $count;
		return $term;
	}
}
