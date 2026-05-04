<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Orders\PurchasableLookup;
use WP_Post;

final class PurchasableLookupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_resolves_published_course(): void {
		$post = $this->stub_post( 1, 'web-design', 'publish' );

		$lookup = new class( $post ) extends PurchasableLookup {
			public function __construct( private readonly WP_Post $post ) {
			}

			protected function find_post( string $slug, string $post_type ): ?WP_Post {
				return ( 'web-design' === $slug && 'vl_course' === $post_type ) ? $this->post : null;
			}
		};

		$resolved = $lookup->find( PurchasableEntityType::COURSE, 'web-design' );

		self::assertNotNull( $resolved );
		self::assertSame( 1, $resolved->ID );
	}

	public function test_resolves_published_webinar(): void {
		$post = $this->stub_post( 2, 'live-ama', 'publish' );

		$lookup = new class( $post ) extends PurchasableLookup {
			public function __construct( private readonly WP_Post $post ) {
			}

			protected function find_post( string $slug, string $post_type ): ?WP_Post {
				return ( 'live-ama' === $slug && 'vl_webinar' === $post_type ) ? $this->post : null;
			}
		};

		$resolved = $lookup->find( PurchasableEntityType::WEBINAR, 'live-ama' );

		self::assertNotNull( $resolved );
		self::assertSame( 2, $resolved->ID );
	}

	public function test_returns_null_for_missing_slug(): void {
		$lookup = new class() extends PurchasableLookup {
			protected function find_post( string $slug, string $post_type ): ?WP_Post {
				return null;
			}
		};

		self::assertNull( $lookup->find( PurchasableEntityType::COURSE, 'nope' ) );
	}

	public function test_returns_null_for_draft_post(): void {
		$post = $this->stub_post( 3, 'draft', 'draft' );

		$lookup = new class( $post ) extends PurchasableLookup {
			public function __construct( private readonly WP_Post $post ) {
			}

			protected function find_post( string $slug, string $post_type ): ?WP_Post {
				return $this->post;
			}
		};

		self::assertNull( $lookup->find( PurchasableEntityType::COURSE, 'draft' ) );
	}

	public function test_returns_null_for_trashed_post(): void {
		$post = $this->stub_post( 4, 'trashed', 'trash' );

		$lookup = new class( $post ) extends PurchasableLookup {
			public function __construct( private readonly WP_Post $post ) {
			}

			protected function find_post( string $slug, string $post_type ): ?WP_Post {
				return $this->post;
			}
		};

		self::assertNull( $lookup->find( PurchasableEntityType::COURSE, 'trashed' ) );
	}

	public function test_returns_null_for_empty_slug(): void {
		$lookup = new PurchasableLookup();

		self::assertNull( $lookup->find( PurchasableEntityType::COURSE, '' ) );
	}

	private function stub_post( int $id, string $slug, string $status ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_name   = $slug;
		$post->post_status = $status;
		$post->post_title  = 'Title-' . $slug;
		return $post;
	}
}
