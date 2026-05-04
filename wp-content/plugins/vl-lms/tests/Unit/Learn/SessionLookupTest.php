<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\SessionLookup;
use WP_Post;

final class SessionLookupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private function post( int $id, string $slug, string $type, string $status ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_type   = $type;
		$p->post_status = $status;
		return $p;
	}

	public function test_returns_post_for_published_session(): void {
		$post = $this->post( 42, 'session-1', 'vl_session', 'publish' );

		$lookup = new class( $post ) extends SessionLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		self::assertSame( $post, $lookup->find_by_slug( 'session-1' ) );
	}

	public function test_returns_null_for_draft_session(): void {
		$post = $this->post( 42, 'session-1', 'vl_session', 'draft' );

		$lookup = new class( $post ) extends SessionLookup {
			public function __construct( private readonly WP_Post $stub ) {}

			/** @return list<WP_Post> */
			protected function run_query( array $args ): array {
				return [ $this->stub ];
			}
		};

		self::assertNull( $lookup->find_by_slug( 'session-1' ) );
	}

	public function test_short_circuits_for_empty_slug(): void {
		$lookup = new class() extends SessionLookup {
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

	public function test_query_args_filter_to_publish_session(): void {
		$lookup = new class() extends SessionLookup {
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
		self::assertSame( 'vl_session', $lookup->captured['post_type'] );
		self::assertSame( 'publish', $lookup->captured['post_status'] );
		self::assertSame( 'foo', $lookup->captured['name'] );
	}
}
