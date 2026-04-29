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
use VL\LMS\Learn\Content\Blocks\CodeBlockTransformer;
use VL\LMS\Learn\Content\Blocks\EmbedBlockTransformer;
use VL\LMS\Learn\Content\Blocks\FileBlockTransformer;
use VL\LMS\Learn\Content\Blocks\HeadingBlockTransformer;
use VL\LMS\Learn\Content\Blocks\HtmlFallbackBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ImageBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ListBlockTransformer;
use VL\LMS\Learn\Content\Blocks\ParagraphBlockTransformer;
use VL\LMS\Learn\Content\Blocks\QuoteBlockTransformer;
use VL\LMS\Learn\Content\Blocks\SeparatorBlockTransformer;
use VL\LMS\Learn\Content\Blocks\TableBlockTransformer;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\LessonContentTransformer;
use VL\LMS\Learn\Video\VideoPayloadBuilder;
use VL\LMS\Tests\Fixtures\InMemoryProgressRepository;
use WP_Post;

final class LessonContentTransformerTest extends TestCase {

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
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeTransformer( array $sibling_topics_for_lesson = [] ): LessonContentTransformer {
		$registry = new BlockTransformerRegistry(
			[
				new ParagraphBlockTransformer(),
				new HeadingBlockTransformer(),
				new ListBlockTransformer(),
				new ImageBlockTransformer(),
				new QuoteBlockTransformer(),
				new EmbedBlockTransformer( new VideoPayloadBuilder() ),
				new SeparatorBlockTransformer(),
				new CodeBlockTransformer(),
				new TableBlockTransformer(),
				new FileBlockTransformer(),
				new HtmlFallbackBlockTransformer(),
			]
		);

		$hierarchy = $this->stubHierarchy();

		return new class(
			new BlockParser(),
			$registry,
			new VideoPayloadBuilder(),
			$hierarchy,
			$this->progress,
			$sibling_topics_for_lesson
		) extends LessonContentTransformer {

			/**
			 * @param array<int, list<WP_Post>> $topics_by_lesson_id
			 */
			public function __construct(
				BlockParser $parser,
				BlockTransformerRegistry $registry,
				VideoPayloadBuilder $video_builder,
				EntityHierarchy $hierarchy,
				InMemoryProgressRepository $progress,
				private array $topics_by_lesson_id
			) {
				parent::__construct( $parser, $registry, $video_builder, $hierarchy, $progress );
			}

			protected function query_child_topics( int $lesson_id ): array {
				return $this->topics_by_lesson_id[ $lesson_id ] ?? [];
			}
		};
	}

	private function stubHierarchy(): EntityHierarchy {
		$posts = $this->posts;
		return new class( $posts ) extends EntityHierarchy {

			/** @param array<int, WP_Post> $posts */
			public function __construct( private array $posts ) {
			}

			public function resolveCourse( WP_Post $post ): ?WP_Post {
				$id = (int) $post->course_ref_id ?? 0;
				return $this->posts[ $id ] ?? null;
			}

			public function resolveModule( WP_Post $post ): ?WP_Post {
				$id = (int) ( $post->module_ref_id ?? 0 );
				if ( 0 === $id ) {
					return null;
				}
				return $this->posts[ $id ] ?? null;
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
		?int $module_ref_id = null
	): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_type    = $type;
		$post->post_name    = $slug;
		$post->post_title   = $title;
		$post->post_status  = 'publish';
		$post->post_parent  = 0;
		$post->menu_order   = $menu_order;
		$post->post_content = '';
		if ( null !== $course_ref_id ) {
			$post->course_ref_id = $course_ref_id;
		}
		if ( null !== $module_ref_id ) {
			$post->module_ref_id = $module_ref_id;
		}

		assert( $post instanceof WP_Post );
		$this->posts[ $id ] = $post;
		return $post;
	}

	private function setMeta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $key ][ $post_id ] = $value;
	}

	public function test_full_happy_path_shape(): void {
		$course               = $this->post( 100, 'vl_course', 'feline-cardio', 'Feline Cardiology' );
		$module               = $this->post( 110, 'vl_module', 'module-1-basics', 'Module 1: Basics' );
		$lesson               = $this->post( 123, 'vl_lesson', 'intro-to-cardiology', 'Intro to Cardiology', 1, 100, 110 );
		$lesson->post_content = 'Welcome to the lesson';

		$this->setMeta( 123, '_vl_lesson_video_url', 'https://vimeo.com/76979871' );
		$this->setMeta( 123, '_vl_lesson_video_provider', 'vimeo' );
		$this->setMeta( 123, '_vl_lesson_duration_seconds', 1800 );
		$this->setMeta( 123, '_vl_lesson_is_preview', '' );
		$this->setMeta( 123, '_vl_lesson_requires_completion', '1' );
		$this->setMeta(
			123,
			'_vl_lesson_attachments',
			[
				[
					'url'  => 'https://example.com/cheatsheet.pdf',
					'name' => 'Cardiology cheatsheet.pdf',
					'size' => '102400',
				],
			]
		);

		$topic = $this->post( 200, 'vl_topic', 'anatomy-of-feline-heart', 'Anatomy of feline heart', 1 );
		$this->setMeta( 200, '_vl_topic_duration_seconds', 600 );

		$this->progress->upsert(
			5,
			EntityType::LESSON,
			123,
			100,
			ProgressStatus::IN_PROGRESS,
			240,
			null,
			new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$transformer = $this->makeTransformer( [ 123 => [ $topic ] ] );

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 123, $payload['id'] );
		self::assertSame( 'intro-to-cardiology', $payload['slug'] );
		self::assertSame( 'Intro to Cardiology', $payload['title'] );
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
		self::assertSame( 1, $payload['menu_order'] );
		self::assertSame( 1800, $payload['duration_seconds'] );
		self::assertFalse( $payload['is_preview'] );
		self::assertTrue( $payload['requires_completion'] );
		self::assertSame(
			[
				'provider'    => 'vimeo',
				'url'         => 'https://vimeo.com/76979871',
				'embed_url'   => 'https://player.vimeo.com/video/76979871',
				'external_id' => '76979871',
			],
			$payload['video']
		);
		self::assertSame(
			[
				[
					'url'  => 'https://example.com/cheatsheet.pdf',
					'name' => 'Cardiology cheatsheet.pdf',
					'size' => 102400,
				],
			],
			$payload['attachments']
		);
		self::assertCount( 1, $payload['content']['blocks'] );
		self::assertSame( 'paragraph', $payload['content']['blocks'][0]['type'] );
		self::assertSame(
			[
				[
					'id'               => 200,
					'slug'             => 'anatomy-of-feline-heart',
					'title'            => 'Anatomy of feline heart',
					'menu_order'       => 1,
					'duration_seconds' => 600,
				],
			],
			$payload['topics']
		);
		self::assertSame( 'in_progress', $payload['progress']['status'] );
		self::assertSame( 240, $payload['progress']['position_seconds'] );
		self::assertNull( $payload['progress']['completed_at'] );
	}

	public function test_video_is_null_when_url_is_empty(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$this->setMeta( 123, '_vl_lesson_video_url', '' );
		$this->setMeta( 123, '_vl_lesson_video_provider', 'vimeo' );

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertNull( $payload['video'] );
	}

	public function test_module_is_null_when_lesson_is_course_direct_child(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertNull( $payload['module'] );
	}

	public function test_progress_defaults_to_not_started_when_no_row_exists(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame(
			[
				'status'           => 'not_started',
				'position_seconds' => null,
				'completed_at'     => null,
			],
			$payload['progress']
		);
	}

	public function test_progress_completed_at_is_iso_8601_when_set(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$completed_at = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->progress->upsert(
			5,
			EntityType::LESSON,
			123,
			100,
			ProgressStatus::COMPLETED,
			null,
			$completed_at,
			$completed_at
		);

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 'completed', $payload['progress']['status'] );
		self::assertSame( $completed_at->format( \DateTimeInterface::ATOM ), $payload['progress']['completed_at'] );
	}

	public function test_attachments_filters_entries_missing_url_or_name(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$this->setMeta(
			123,
			'_vl_lesson_attachments',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A.pdf',
					'size' => 1,
				],
				[
					'name' => 'B.pdf',
					'size' => 2,
				],
				[
					'url'  => 'https://example.com/c.pdf',
					'size' => 3,
				],
				[
					'url'  => 'https://example.com/d.pdf',
					'name' => 'D.pdf',
				],
			]
		);

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertCount( 2, $payload['attachments'] );
		self::assertSame( 'A.pdf', $payload['attachments'][0]['name'] );
		self::assertSame( 'D.pdf', $payload['attachments'][1]['name'] );
		self::assertSame( 0, $payload['attachments'][1]['size'] );
	}

	public function test_attachments_size_is_coerced_to_int(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$this->setMeta(
			123,
			'_vl_lesson_attachments',
			[
				[
					'url'  => 'https://example.com/a.pdf',
					'name' => 'A.pdf',
					'size' => '4096',
				],
			]
		);

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( 4096, $payload['attachments'][0]['size'] );
		self::assertIsInt( $payload['attachments'][0]['size'] );
	}

	public function test_topics_is_empty_array_when_no_children(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$transformer = $this->makeTransformer();

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( [], $payload['topics'] );
	}

	public function test_topics_preserve_order_from_query(): void {
		$this->post( 100, 'vl_course', 'c', 'Course' );
		$lesson = $this->post( 123, 'vl_lesson', 'intro', 'Intro', 1, 100 );

		$first  = $this->post( 201, 'vl_topic', 'a', 'A', 1 );
		$middle = $this->post( 202, 'vl_topic', 'b', 'B', 2 );
		$last   = $this->post( 203, 'vl_topic', 'c', 'C', 3 );

		$transformer = $this->makeTransformer( [ 123 => [ $first, $middle, $last ] ] );

		$payload = $transformer->transform( $lesson, 5, AccessDecision::allow( 100, false ) );

		self::assertSame( [ 201, 202, 203 ], array_column( $payload['topics'], 'id' ) );
	}
}
