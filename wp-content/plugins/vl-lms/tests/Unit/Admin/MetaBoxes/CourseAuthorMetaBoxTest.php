<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\CourseAuthorMetaBox;
use WP_Post;
use WP_User;

final class CourseAuthorMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CourseAuthorMetaBox $box;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$this->box = new CourseAuthorMetaBox();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function postMock( int $author_id = 7 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 100;
		$post->post_author = (string) $author_id;
		$post->post_type   = 'vl_course';
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * `get_post_type_object` double carrying only the cap the box reads.
	 */
	private function stub_post_type_object(): void {
		Functions\when( 'get_post_type_object' )->justReturn(
			(object) [ 'cap' => (object) [ 'edit_others_posts' => 'edit_others_vl_courses' ] ]
		);
	}

	public function test_box_shape(): void {
		self::assertSame( 'vl_lms_course_author', $this->box->id() );
		self::assertSame( 'vl_course', $this->box->post_type() );
		self::assertSame( 'side', $this->box->context() );
		self::assertSame( 'high', $this->box->priority() );
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
		$this->box->render( $this->postMock( 7 ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Автор (головний інструктор)', $html );
		self::assertStringContainsString( 'залишиться в команді як ко-інструктор', $html );
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
		$this->box->render( $this->postMock( 7 ) );
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

		$this->box->save( 100, $this->postMock( 7 ) );

		$_POST = [];
		$this->addToAssertionCount( 1 );
	}
}
