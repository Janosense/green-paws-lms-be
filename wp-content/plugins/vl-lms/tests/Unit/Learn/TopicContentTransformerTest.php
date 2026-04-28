<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Learn\Content\BlockParser;
use VL\LMS\Learn\Content\BlockTransformerRegistry;
use VL\LMS\Learn\Content\Blocks\HtmlFallbackBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ParagraphBlockTransformer;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\TopicContentTransformer;
use VL\LMS\Learn\Video\VideoPayloadBuilder;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use WP_Post;

final class TopicContentTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, WP_Post> */
	private array $posts = [];

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	private InMemoryProgressRepository $progress;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->posts    = [];
		$this->meta     = [];
		$this->progress = new InMemoryProgressRepository();

		$meta_ref = &$this->meta;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = false ) use ( &$meta_ref ): mixed {
				return $meta_ref[ $key ][ $post_id ] ?? '';
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'parse_blocks' )->alias(
			static function ( string $content ): array {
				if ( '' === trim( $content ) ) {
					return [];
				}
				return [
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [],
						'innerHTML'    => '<p>' . $content . '</p>',
						'innerBlocks'  => [],
						'innerContent' => [ '<p>' . $content . '</p>' ],
					],
				];
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeTransformer(): TopicContentTransformer {
		$registry = new BlockTransformerRegistry(
			[
				new ParagraphBlockTransformer(),
				new HtmlFallbackBlockTransformer(),
			]
		);

		return new TopicContentTransformer(
			new BlockParser(),
			$registry,
			new VideoPayloadBuilder(),
			$this->stubHierarchy(),
			$this->progress
		);
	}

	private function stubHierarchy(): EntityHierarchy {
		$posts = $this->posts;
		return new class( $posts ) extends EntityHierarchy {

			/** @param array<int, WP_Post> $posts */
			public function __construct( private array $posts ) {
			}

			public function resolveCourse( WP_Post $post ): ?WP_Post {
				$id = (int) ( $post->course_ref_id ?? 0 );
				return 0 === $id ? null : ( $this->posts[ $id ] ?? null );
			}

			public function resolveModule( WP_Post $post ): ?WP_Post {
				$id = (int) ( $post->module_ref_id ?? 0 );
				return 0 === $id ? null : ( $this->posts[ $id ] ?? null );
			}

			public function resolveLesson( WP_Post $post ): ?WP_Post {
				$id = (int) ( $post->lesson_ref_id ?? 0 );
				return 0 === $id ? null : ( $this->posts[ $id ] ?? null );
			}
		};
	}

	private function post(
		int $id,
		string $type,
		string $slug,
		string $title,
		int $menu_order = 0,
		?int $course_ref_id = null,
		?int $module_ref_id = null,
		?int $lesson_ref_id = null
	): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->post_parent = 0;
		$post->menu_order  = $menu_order;
		$post->post_content = '';
		if ( null !== $course_ref_id ) {
			$post->course_ref_id = $course_ref_id;
		}
		if ( null !== $module_ref_id ) {
			$post->module_ref_id = $module_ref_id;
		}
		if ( null !== $lesson_ref_id ) {
			$post->lesson_ref_id = $lesson_ref_id;
		}

		assert( $post instanceof WP_Post );
		$this->posts[ $id ] = $post;
		return $post;
	}

	private function setMeta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $key ][ $post_id ] = $value;
	}

	public function test_full_happy_path_shape(): void {
		$course = $this->post( 100, 'vl_course', 'feline-cardio', 'Feline Cardiology' );
		$module = $this->post( 110, 'vl_module', 'module-1-basics', 'Module 1: Basics' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro' );
		$topic  = $this->post( 200, 'vl_topic', 'anatomy', 'Anatomy', 1, 100, 110, 123 );
		$topic->post_content = 'Topic body';

		$this->setMeta( 200, '_vl_topic_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		$this->setMeta( 200, '_vl_topic_video_provider', 'youtube' );
		$this->setMeta( 200, '_vl_topic_duration_seconds', 600 );

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 200, $payload['id'] );
		self::assertSame( 'anatomy', $payload['slug'] );
		self::assertSame( 'Anatomy', $payload['title'] );
		self::assertSame(
			[
				'id'    => 100,
				'slug'  => 'feline-cardio',
				'title' => 'Feline Cardiology',
			],
			$payload['course']
		);
		self::assertSame(
			[
				'id'    => 110,
				'slug'  => 'module-1-basics',
				'title' => 'Module 1: Basics',
			],
			$payload['module']
		);
		self::assertSame(
			[
				'id'    => 123,
				'slug'  => 'intro',
				'title' => 'Intro',
			],
			$payload['lesson']
		);
		self::assertSame( 1, $payload['menu_order'] );
		self::assertSame( 600, $payload['duration_seconds'] );
		self::assertSame( 'youtube', $payload['video']['provider'] );
		self::assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $payload['video']['embed_url'] );
		self::assertCount( 1, $payload['content']['blocks'] );
		self::assertSame( 'paragraph', $payload['content']['blocks'][0]['type'] );
	}

	public function test_response_omits_lesson_only_fields(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$topic = $this->post( 200, 'vl_topic', 'a', 'A', 1, 100 );

		$payload = $this->makeTransformer()->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertArrayNotHasKey( 'topics', $payload );
		self::assertArrayNotHasKey( 'attachments', $payload );
		self::assertArrayNotHasKey( 'requires_completion', $payload );
		self::assertArrayNotHasKey( 'is_preview', $payload );
	}

	public function test_module_is_null_when_lesson_skips_module_level(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'l', 'Lesson' );
		$topic  = $this->post( 200, 'vl_topic', 'a', 'A', 1, 100, null, 123 );

		$payload = $this->makeTransformer()->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertNull( $payload['module'] );
		self::assertNotNull( $payload['lesson'] );
	}

	public function test_video_is_null_when_url_is_empty(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$topic = $this->post( 200, 'vl_topic', 'a', 'A', 1, 100 );
		$this->setMeta( 200, '_vl_topic_video_url', '' );

		$payload = $this->makeTransformer()->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertNull( $payload['video'] );
	}

	public function test_progress_defaults_to_not_started(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$topic = $this->post( 200, 'vl_topic', 'a', 'A', 1, 100 );

		$payload = $this->makeTransformer()->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 'not_started', $payload['progress']['status'] );
		self::assertNull( $payload['progress']['position_seconds'] );
		self::assertNull( $payload['progress']['completed_at'] );
	}

	public function test_progress_completed_at_is_iso_8601_when_set(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$topic = $this->post( 200, 'vl_topic', 'a', 'A', 1, 100 );

		$completed_at = new \DateTimeImmutable( '2026-04-28 09:30:00', new \DateTimeZone( 'UTC' ) );
		$this->progress->upsert(
			5,
			EntityType::TOPIC,
			200,
			100,
			ProgressStatus::COMPLETED,
			null,
			$completed_at,
			$completed_at
		);

		$payload = $this->makeTransformer()->transform( $topic, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 'completed', $payload['progress']['status'] );
		self::assertSame( $completed_at->format( \DateTimeInterface::ATOM ), $payload['progress']['completed_at'] );
	}
}
