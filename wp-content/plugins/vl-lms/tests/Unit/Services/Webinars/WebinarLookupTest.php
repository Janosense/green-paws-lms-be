<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Webinars;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Webinars\WebinarLookup;
use WP_Post;

final class WebinarLookupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private function post( int $id, string $slug, string $type, string $status ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_type   = $type;
		$p->post_status = $status;
		return $p;
	}

	public function test_returns_post_when_published_webinar_matches_slug(): void {
		$post = $this->post( 42, 'vet-clinical-update', 'vl_webinar', 'publish' );

		$lookup = new class( $post ) extends WebinarLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		$result = $lookup->find_by_slug( 'vet-clinical-update' );

		self::assertSame( $post, $result );
	}

	public function test_returns_null_when_post_status_is_not_publish(): void {
		$post = $this->post( 42, 'vet-clinical-update', 'vl_webinar', 'draft' );

		$lookup = new class( $post ) extends WebinarLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		self::assertNull( $lookup->find_by_slug( 'vet-clinical-update' ) );
	}

	public function test_returns_null_when_no_match(): void {
		$lookup = new class() extends WebinarLookup {
			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [];
			}
		};

		self::assertNull( $lookup->find_by_slug( 'unknown' ) );
	}

	public function test_returns_null_for_empty_slug_without_query(): void {
		$lookup = new class() extends WebinarLookup {
			public int $calls = 0;

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				++$this->calls;
				return [];
			}
		};

		self::assertNull( $lookup->find_by_slug( '' ) );
		self::assertSame( 0, $lookup->calls );
	}

	public function test_query_args_filter_to_publish_webinars(): void {
		$lookup = new class() extends WebinarLookup {
			/** @var array<string, mixed>|null */
			public ?array $captured = null;

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				$this->captured = $args;
				return [];
			}
		};

		$lookup->find_by_slug( 'foo' );

		self::assertIsArray( $lookup->captured );
		self::assertSame( 'vl_webinar', $lookup->captured['post_type'] );
		self::assertSame( 'publish', $lookup->captured['post_status'] );
		self::assertSame( 'foo', $lookup->captured['name'] );
		self::assertSame( 1, $lookup->captured['posts_per_page'] );
	}

	public function test_skips_unrelated_post_types_returned_by_query(): void {
		$post = $this->post( 1, 'foo', 'post', 'publish' );

		$lookup = new class( $post ) extends WebinarLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		self::assertNull( $lookup->find_by_slug( 'foo' ) );
	}
}
