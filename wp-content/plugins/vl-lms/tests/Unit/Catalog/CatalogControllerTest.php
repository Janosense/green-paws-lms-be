<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\CatalogController;
use VL\LMS\Catalog\CatalogQuery;
use VL\LMS\Catalog\Detail\RegistrationWindow;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Error;
use WP_REST_Response;

final class CatalogControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CatalogController $controller;

	/** @var Mockery\MockInterface&CourseInstructorRepository */
	private $instructors;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? strtolower( trim( $v ) ) : ''
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'set_status' )->andReturnSelf();
				return $response;
			}
		);

		$this->instructors = Mockery::mock( CourseInstructorRepository::class );
		$this->instructors->shouldReceive( 'find_leads_for_entities' )->andReturn( [] );

		$cover            = new CoverImageTransformer();
		$lead             = new LeadInstructorTransformer();
		$term             = new TaxonomyTermTransformer();
		$this->controller = new CatalogController(
			'vl/v1',
			new CatalogQuery(),
			new CourseCardTransformer( $cover, $lead, $term ),
			new WebinarCardTransformer( $cover, $lead, $term, new RegistrationWindow() ),
			$this->instructors,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_courses_and_webinars(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 2, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/catalog/courses', $calls[0]['route'] );
		self::assertSame( 'GET', $calls[0]['args']['methods'] );
		self::assertSame( '__return_true', $calls[0]['args']['permission_callback'] );
		self::assertSame( '/catalog/webinars', $calls[1]['route'] );
		self::assertSame( '__return_true', $calls[1]['args']['permission_callback'] );
	}

	public function test_list_courses_returns_400_on_unknown_sort(): void {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_params' )->andReturn( [ 'sort' => 'banana' ] );

		$response = $this->controller->list_courses( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_invalid_sort', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_list_courses_returns_400_when_courses_get_upcoming_sort(): void {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_params' )->andReturn( [ 'sort' => 'upcoming' ] );

		$response = $this->controller->list_courses( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_invalid_sort', $response->get_error_code() );
	}
}
