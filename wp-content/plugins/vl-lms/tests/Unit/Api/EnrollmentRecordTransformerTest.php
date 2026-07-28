<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Api\EnrollmentRecordTransformer;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use WP_Post;

final class EnrollmentRecordTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private EnrollmentRecordTransformer $transformer;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$this->transformer = new EnrollmentRecordTransformer( new CoverImageTransformer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_projects_active_enrollment_with_cover(): void {
		$this->meta = [
			'_vl_course_cover_image_id' => [ 10 => 50 ],
		];
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array|false {
				return match ( $size ) {
					'thumbnail'    => [ 'https://t/150.jpg', 150, 150, true ],
					'medium_large' => [ 'https://t/768.jpg', 768, 432, true ],
					default        => false,
				};
			}
		);

		$record = $this->transformer->transform(
			$this->enrollment( 100, 10, EnrollmentStatus::ACTIVE ),
			$this->course_post( 10, 'cardiology', 'Cardiology Fundamentals' ),
			self::stats()
		);

		self::assertSame( 100, $record['id'] );
		self::assertSame( 'active', $record['status'] );
		self::assertSame( 'self_signup', $record['source'] );
		self::assertSame( 0, $record['progress_pct'] );
		self::assertSame( 10, $record['course']['id'] );
		self::assertSame( 'cardiology', $record['course']['slug'] );
		self::assertSame( 'Cardiology Fundamentals', $record['course']['title'] );
		self::assertIsArray( $record['course']['cover'] );
		self::assertSame( 'https://t/768.jpg', $record['course']['cover']['card']['url'] );
		self::assertNull( $record['completed_at'] );
		self::assertNull( $record['expires_at'] );
	}

	public function test_projects_completed_enrollment_with_completed_at_set(): void {
		$record = $this->transformer->transform(
			$this->enrollment(
				id: 100,
				course_id: 10,
				status: EnrollmentStatus::COMPLETED,
				completed_at: '2026-04-20 09:30:00'
			),
			$this->course_post( 10, 'free-course', 'Free Course' ),
			self::stats()
		);

		self::assertSame( 'completed', $record['status'] );
		self::assertSame( '2026-04-20T09:30:00Z', $record['completed_at'] );
	}

	public function test_returns_null_cover_when_attachment_id_is_zero_or_missing(): void {
		// Zero attachment id.
		$this->meta = [
			'_vl_course_cover_image_id' => [ 10 => 0 ],
		];

		$record = $this->transformer->transform(
			$this->enrollment( 100, 10, EnrollmentStatus::ACTIVE ),
			$this->course_post( 10, 'cardio', 'Cardio' ),
			self::stats()
		);
		self::assertNull( $record['course']['cover'] );

		// Missing meta entirely (default '' from get_post_meta).
		$this->meta = [];

		$record = $this->transformer->transform(
			$this->enrollment( 100, 11, EnrollmentStatus::ACTIVE ),
			$this->course_post( 11, 'derma', 'Derma' ),
			self::stats()
		);
		self::assertNull( $record['course']['cover'] );
	}

	public function test_uses_card_size_variant_from_cover_image_transformer(): void {
		$this->meta = [
			'_vl_course_cover_image_id' => [ 10 => 50 ],
		];
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array|false {
				return 'medium_large' === $size
					? [ 'https://t/card.jpg', 768, 432, true ]
					: false;
			}
		);

		$record = $this->transformer->transform(
			$this->enrollment( 100, 10, EnrollmentStatus::ACTIVE ),
			$this->course_post( 10, 'cardio', 'Cardio' ),
			self::stats()
		);

		self::assertArrayHasKey( 'card', $record['course']['cover'] );
		self::assertSame( 'https://t/card.jpg', $record['course']['cover']['card']['url'] );
		self::assertSame( 768, $record['course']['cover']['card']['width'] );
		self::assertSame( 432, $record['course']['cover']['card']['height'] );
	}

	public function test_includes_course_slug_and_title(): void {
		$record = $this->transformer->transform(
			$this->enrollment( 100, 10, EnrollmentStatus::ACTIVE ),
			$this->course_post( 10, 'spring-cardio-2026', 'Spring Cardiology 2026' ),
			self::stats()
		);

		self::assertSame( 'spring-cardio-2026', $record['course']['slug'] );
		self::assertSame( 'Spring Cardiology 2026', $record['course']['title'] );
	}

	public function test_emits_iso_8601_utc_timestamps(): void {
		$record = $this->transformer->transform(
			$this->enrollment(
				id: 100,
				course_id: 10,
				status: EnrollmentStatus::COMPLETED,
				enrolled_at: '2026-04-15 12:00:00',
				completed_at: '2026-04-20 09:30:45',
				expires_at: '2026-12-31 23:59:59'
			),
			$this->course_post( 10, 'cardio', 'Cardio' ),
			self::stats()
		);

		self::assertSame( '2026-04-15T12:00:00Z', $record['enrolled_at'] );
		self::assertSame( '2026-04-20T09:30:45Z', $record['completed_at'] );
		self::assertSame( '2026-12-31T23:59:59Z', $record['expires_at'] );
	}

	public function test_embeds_stats_block_verbatim(): void {
		$stats = self::stats( lessons_total: 12, lessons_completed: 5, has_final_exam: true );

		$record = $this->transformer->transform(
			$this->enrollment( 100, 10, EnrollmentStatus::ACTIVE ),
			$this->course_post( 10, 'cardio', 'Cardio' ),
			$stats
		);

		self::assertSame( $stats, $record['stats'] );
	}

	/**
	 * @return array{
	 *     modules: array{total: int, completed: int},
	 *     lessons: array{total: int, completed: int},
	 *     topics: array{total: int, completed: int},
	 *     quizzes: array{total: int, passed: int, has_final_exam: bool}
	 * }
	 */
	private static function stats(
		int $lessons_total = 0,
		int $lessons_completed = 0,
		bool $has_final_exam = false
	): array {
		return [
			'modules' => [
				'total'     => 0,
				'completed' => 0,
			],
			'lessons' => [
				'total'     => $lessons_total,
				'completed' => $lessons_completed,
			],
			'topics'  => [
				'total'     => 0,
				'completed' => 0,
			],
			'quizzes' => [
				'total'          => 0,
				'passed'         => 0,
				'has_final_exam' => $has_final_exam,
			],
		];
	}

	private function course_post( int $id, string $slug, string $title ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_type   = 'vl_course';
		$post->post_status = 'publish';
		return $post;
	}

	private function enrollment(
		int $id,
		int $course_id,
		EnrollmentStatus $status,
		string $enrolled_at = '2026-04-15 12:00:00',
		?string $completed_at = null,
		?string $expires_at = null
	): Enrollment {
		return new Enrollment(
			id: $id,
			user_id: 5,
			course_id: $course_id,
			status: $status,
			source: EnrollmentSource::SELF_SIGNUP,
			source_group_id: null,
			source_order_id: null,
			enrolled_at: $enrolled_at,
			started_at: null,
			completed_at: $completed_at,
			expires_at: $expires_at,
			revoked_at: null,
			revoked_by: null,
			revoke_reason: null,
			progress_pct: 0,
			created_at: $enrolled_at,
			updated_at: $enrolled_at,
		);
	}
}
