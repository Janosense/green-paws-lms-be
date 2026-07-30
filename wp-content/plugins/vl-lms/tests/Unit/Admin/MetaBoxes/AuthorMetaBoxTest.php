<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\AuthorMetaBox;
use WP_Post;
use WP_User;

final class AuthorMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function postMock( int $author_id = 7, string $post_type = 'vl_course' ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 100;
		$post->post_author = (string) $author_id;
		$post->post_type   = $post_type;
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * `get_post_type_object` double deriving the one cap the box reads
	 * from the post type it is asked for.
	 */
	private function stub_post_type_object(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static fn ( string $post_type ): object => (object) [
				'cap' => (object) [ 'edit_others_posts' => str_replace( 'vl_', 'edit_others_vl_', $post_type ) . 's' ],
			]
		);
	}

	public function test_course_box_shape(): void {
		$box = new AuthorMetaBox( 'vl_course' );

		self::assertSame( 'vl_lms_course_author', $box->id() );
		self::assertSame( 'vl_course', $box->post_type() );
		self::assertSame( 'Автор курсу', $box->title() );
		self::assertSame( 'side', $box->context() );
		self::assertSame( 'high', $box->priority() );
	}

	public function test_webinar_box_shape(): void {
		$box = new AuthorMetaBox( 'vl_webinar' );

		self::assertSame( 'vl_lms_webinar_author', $box->id() );
		self::assertSame( 'vl_webinar', $box->post_type() );
		self::assertSame( 'Автор вебінару', $box->title() );
		self::assertSame( 'side', $box->context() );
		self::assertSame( 'high', $box->priority() );
	}

	public function test_render_outputs_instructor_dropdown_for_cap_holders(): void {
		$this->stub_post_type_object();
		Functions\when( 'current_user_can' )->alias(
			static fn ( string $cap ): bool => 'edit_others_vl_courses' === $cap
		);

		Functions\expect( 'wp_dropdown_users' )
			->once()
			->with(
				Mockery::on(
					static fn ( array $args ): bool =>
						'post_author_override' === $args['name']
						&& 'instructor' === $args['role']
						&& 7 === $args['selected']
						&& true === $args['include_selected']
				)
			);

		ob_start();
		( new AuthorMetaBox( 'vl_course' ) )->render( $this->postMock( 7 ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Автор (головний інструктор)', $html );
		self::assertStringContainsString( 'залишиться в команді як ко-інструктор', $html );
	}

	/**
	 * The gate is the post type's own `edit_others_*` primitive — a
	 * webinar box must ask about webinars, not courses.
	 */
	public function test_render_for_webinar_gates_on_the_webinar_cap(): void {
		$this->stub_post_type_object();

		$checked = [];
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap ) use ( &$checked ): bool {
				$checked[] = $cap;
				return true;
			}
		);
		Functions\expect( 'wp_dropdown_users' )->once();

		ob_start();
		( new AuthorMetaBox( 'vl_webinar' ) )->render( $this->postMock( 7, 'vl_webinar' ) );
		ob_end_clean();

		self::assertSame( [ 'edit_others_vl_webinars' ], $checked );
	}

	public function test_render_is_readonly_without_edit_others_cap(): void {
		$this->stub_post_type_object();
		Functions\when( 'current_user_can' )->justReturn( false );

		$author               = new WP_User();
		$author->ID           = 7;
		$author->display_name = 'Олена Іваненко';
		Functions\when( 'get_userdata' )->justReturn( $author );

		Functions\expect( 'wp_dropdown_users' )->never();

		ob_start();
		( new AuthorMetaBox( 'vl_course' ) )->render( $this->postMock( 7 ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Олена Іваненко', $html );
		self::assertStringNotContainsString( 'post_author_override', $html );
	}

	/**
	 * Persistence belongs to core's `post_author_override` handling
	 * (`_wp_translate_postdata()`), which writes `post_author` before
	 * `save_post` fires — the box itself must never issue a second write.
	 */
	public function test_save_is_a_deliberate_noop(): void {
		$_POST = [ 'post_author_override' => '9' ];

		Functions\expect( 'wp_update_post' )->never();

		( new AuthorMetaBox( 'vl_course' ) )->save( 100, $this->postMock( 7 ) );

		$_POST = [];
		$this->addToAssertionCount( 1 );
	}
}
