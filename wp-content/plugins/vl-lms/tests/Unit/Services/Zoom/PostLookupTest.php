<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\PostLookup;
use VL\LMS\Services\Zoom\Sync\PostKind;
use WP_Post;

final class PostLookupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private function post( int $id, string $post_type ): WP_Post {
		$p            = Mockery::mock( 'WP_Post' );
		$p->ID        = $id;
		$p->post_type = $post_type;
		return $p;
	}

	public function test_resolves_session_meeting(): void {
		$post = $this->post( 11, 'vl_session' );

		$lookup = new class( $post ) extends PostLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		$result = $lookup->find_by_meeting_id( 'abc123' );

		self::assertNotNull( $result );
		self::assertSame( $post, $result->post );
		self::assertSame( PostKind::SESSION, $result->kind );
	}

	public function test_resolves_webinar_meeting(): void {
		$post = $this->post( 22, 'vl_webinar' );

		$lookup = new class( $post ) extends PostLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		$result = $lookup->find_by_meeting_id( 'abc123' );

		self::assertNotNull( $result );
		self::assertSame( PostKind::WEBINAR, $result->kind );
	}

	public function test_returns_null_when_no_match(): void {
		$lookup = new class() extends PostLookup {
			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [];
			}
		};

		self::assertNull( $lookup->find_by_meeting_id( 'unknown' ) );
	}

	public function test_returns_null_for_empty_meeting_id(): void {
		$lookup = new class() extends PostLookup {
			public int $calls = 0;

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				++$this->calls;
				return [];
			}
		};

		self::assertNull( $lookup->find_by_meeting_id( '' ) );
		self::assertSame( 0, $lookup->calls, 'empty meeting_id should short-circuit before the query' );
	}

	public function test_returns_null_when_post_type_is_unrelated(): void {
		$post = $this->post( 33, 'post' );

		$lookup = new class( $post ) extends PostLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		self::assertNull( $lookup->find_by_meeting_id( 'whatever' ) );
	}

	public function test_query_args_include_both_post_types_and_meta_or(): void {
		$captured = null;

		$lookup = new class( $captured ) extends PostLookup {
			/** @var array<string, mixed>|null */
			public ?array $captured = null;

			public function __construct( ?array $_ ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				$this->captured = $args;
				return [];
			}
		};

		$lookup->find_by_meeting_id( 'XYZ' );

		self::assertIsArray( $lookup->captured );
		self::assertSame( [ 'vl_session', 'vl_webinar' ], $lookup->captured['post_type'] );
		self::assertSame( 1, $lookup->captured['posts_per_page'] );
		self::assertSame( 'OR', $lookup->captured['meta_query']['relation'] );
		self::assertSame( '_vl_session_zoom_meeting_id', $lookup->captured['meta_query'][0]['key'] );
		self::assertSame( 'XYZ', $lookup->captured['meta_query'][0]['value'] );
		self::assertSame( '_vl_webinar_zoom_meeting_id', $lookup->captured['meta_query'][1]['key'] );
		self::assertSame( 'XYZ', $lookup->captured['meta_query'][1]['value'] );
	}
}
