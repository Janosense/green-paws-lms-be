<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\RegistrationWindow;
use VL\LMS\Catalog\Search\RelevanceRanker;
use VL\LMS\Catalog\Search\SearchController;
use VL\LMS\Catalog\Search\SearchQuery;
use VL\LMS\Catalog\Search\SearchQueryRunner;
use VL\LMS\Catalog\TaxonomyTermTransformer;
use VL\LMS\Catalog\Transformers\CourseCardTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Catalog\Transformers\WebinarCardTransformer;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Error;
use WP_REST_Response;

/**
 * The transformer classes (`CourseCardTransformer`, `WebinarCardTransformer`)
 * are marked `final`, which Mockery cannot proxy. Following the existing
 * convention in `CatalogControllerTest`, we instantiate real transformers
 * — they are pure functions of pre-fetched data and never invoked in these
 * tests because all sections return empty post lists.
 */
final class SearchControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SearchController $controller;

	/** @var Mockery\MockInterface&SearchQueryRunner */
	private $runner;

	/** @var Mockery\MockInterface&CourseInstructorRepository */
	private $instructors;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( mixed $v ): string => is_string( $v ) ? trim( $v ) : ''
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ): WP_REST_Response {
				$response = Mockery::mock( WP_REST_Response::class );
				$response->shouldReceive( 'get_data' )->andReturn( $data );
				$response->shouldReceive( 'set_status' )->andReturnSelf();
				return $response;
			}
		);

		$this->runner      = Mockery::mock( SearchQueryRunner::class );
		$this->instructors = Mockery::mock( CourseInstructorRepository::class );
		$this->instructors->shouldReceive( 'find_leads_for_entities' )->andReturn( [] );

		$cover            = new CoverImageTransformer();
		$lead             = new LeadInstructorTransformer();
		$term             = new TaxonomyTermTransformer();
		$this->controller = new SearchController(
			'vl/v1',
			new SearchQuery(),
			$this->runner,
			new RelevanceRanker(),
			new CourseCardTransformer( $cover, $lead, $term ),
			new WebinarCardTransformer( $cover, $lead, $term, new RegistrationWindow() ),
			$this->instructors,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_routes_registers_search(): void {
		$calls = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$calls ): void {
				$calls[] = compact( 'namespace', 'route', 'args' );
			}
		);

		$this->controller->register_routes();

		self::assertCount( 1, $calls );
		self::assertSame( 'vl/v1', $calls[0]['namespace'] );
		self::assertSame( '/search', $calls[0]['route'] );
		self::assertSame( 'GET', $calls[0]['args']['methods'] );
		self::assertSame( '__return_true', $calls[0]['args']['permission_callback'] );
	}

	public function test_search_returns_400_when_q_is_missing(): void {
		$request = $this->request( [] );

		$response = $this->controller->search( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_search_q_required', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_search_returns_400_when_q_is_empty_string(): void {
		$request = $this->request( [ 'q' => '' ] );

		$response = $this->controller->search( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_search_q_required', $response->get_error_code() );
	}

	public function test_search_returns_400_when_q_is_whitespace_only(): void {
		$request = $this->request( [ 'q' => "   \t  " ] );

		$response = $this->controller->search( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'vl_lms_search_q_required', $response->get_error_code() );
	}

	public function test_success_envelope_carries_both_sub_objects(): void {
		$this->runner->shouldReceive( 'run' )->andReturn(
			[
				'posts'       => [],
				'total'       => 0,
				'total_pages' => 0,
			]
		);

		$request  = $this->request( [ 'q' => 'cardiology' ] );
		$response = $this->controller->search( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$envelope = $response->get_data();
		self::assertTrue( $envelope['success'] );
		self::assertSame( 'cardiology', $envelope['data']['q'] );
		self::assertArrayHasKey( 'courses', $envelope['data'] );
		self::assertArrayHasKey( 'webinars', $envelope['data'] );
		self::assertSame( [], $envelope['data']['courses']['items'] );
		self::assertSame( [], $envelope['data']['webinars']['items'] );
		self::assertSame(
			[
				'page'        => 1,
				'per_page'    => 12,
				'total'       => 0,
				'total_pages' => 0,
			],
			$envelope['data']['courses']['pagination']
		);
	}

	public function test_runner_is_invoked_for_both_post_types(): void {
		$this->runner
			->shouldReceive( 'run' )
			->twice()
			->andReturn(
				[
					'posts'       => [],
					'total'       => 0,
					'total_pages' => 0,
				]
			);

		$request = $this->request( [ 'q' => 'cardiology' ] );
		$this->controller->search( $request );
	}

	public function test_runner_receives_post_type_arg_for_each_section(): void {
		$received_post_types = [];

		$this->runner
			->shouldReceive( 'run' )
			->andReturnUsing(
				function ( array $args ) use ( &$received_post_types ): array {
					$received_post_types[] = $args['post_type'];
					return [
						'posts'       => [],
						'total'       => 0,
						'total_pages' => 0,
					];
				}
			);

		$request = $this->request( [ 'q' => 'cardiology' ] );
		$this->controller->search( $request );

		self::assertSame( [ 'vl_course', 'vl_webinar' ], $received_post_types );
	}

	public function test_pagination_is_reported_independently_for_each_section(): void {
		$this->runner
			->shouldReceive( 'run' )
			->andReturnUsing(
				function ( array $args ): array {
					if ( 'vl_course' === $args['post_type'] ) {
						return [
							'posts'       => [],
							'total'       => 5,
							'total_pages' => 1,
						];
					}
					return [
						'posts'       => [],
						'total'       => 30,
						'total_pages' => 3,
					];
				}
			);

		$request  = $this->request(
			[
				'q'    => 'cardiology',
				'page' => 2,
			]
		);
		$response = $this->controller->search( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$envelope = $response->get_data();
		self::assertSame( 2, $envelope['data']['courses']['pagination']['page'] );
		self::assertSame( 5, $envelope['data']['courses']['pagination']['total'] );
		self::assertSame( 1, $envelope['data']['courses']['pagination']['total_pages'] );
		self::assertSame( 2, $envelope['data']['webinars']['pagination']['page'] );
		self::assertSame( 30, $envelope['data']['webinars']['pagination']['total'] );
		self::assertSame( 3, $envelope['data']['webinars']['pagination']['total_pages'] );
	}

	public function test_q_is_passed_through_to_runner(): void {
		$received_q = null;

		$this->runner
			->shouldReceive( 'run' )
			->andReturnUsing(
				function ( array $args, $ranker, string $q ) use ( &$received_q ): array {
					$received_q = $q;
					return [
						'posts'       => [],
						'total'       => 0,
						'total_pages' => 0,
					];
				}
			);

		$request = $this->request( [ 'q' => '  cardiology  ' ] );
		$this->controller->search( $request );

		self::assertSame( 'cardiology', $received_q );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( array $params ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_params' )->andReturn( $params );
		return $request;
	}
}
