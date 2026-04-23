<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Access;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use stdClass;
use VL\LMS\Access\CoInstructorLookupInterface;
use VL\LMS\Access\InstructorAccessFilter;

final class InstructorAccessFilterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_adds_map_meta_cap_filter_once(): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );

		Filters\expectAdded( 'map_meta_cap' )
			->once()
			->with( [ $filter, 'filter_meta_caps' ], 10, 4 );

		$filter->register_hooks();
	}

	/**
	 * @dataProvider unsupported_caps
	 */
	public function test_unsupported_cap_returns_caps_unchanged( string $cap ): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );
		$caps   = [ 'manage_options' ];

		$result = $filter->filter_meta_caps( $caps, $cap, 7, [ 123 ] );

		self::assertSame( $caps, $result );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsupported_caps(): array {
		return [
			'publish_posts'  => [ 'publish_posts' ],
			'manage_options' => [ 'manage_options' ],
			'edit_users'     => [ 'edit_users' ],
		];
	}

	public function test_empty_args_returns_caps_unchanged(): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );
		$caps   = [ 'edit_others_vl_courses' ];

		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [] );

		self::assertSame( $caps, $result );
	}

	/**
	 * @dataProvider non_positive_post_ids
	 */
	public function test_non_positive_post_id_returns_caps_unchanged( mixed $value ): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );
		$caps   = [ 'edit_others_vl_courses' ];

		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ $value ] );

		self::assertSame( $caps, $result );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function non_positive_post_ids(): array {
		return [
			'zero'     => [ 0 ],
			'negative' => [ -5 ],
			'null'     => [ null ],
			'string'   => [ 'not-a-number' ],
		];
	}

	public function test_missing_post_returns_caps_unchanged(): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );
		Functions\when( 'get_post' )->justReturn( null );

		$caps   = [ 'edit_others_vl_courses' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	public function test_non_vl_post_type_returns_caps_unchanged(): void {
		$filter = new InstructorAccessFilter( $this->never_called_lookup() );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'post';
		$post->post_author = '99';
		Functions\when( 'get_post' )->justReturn( $post );

		$caps   = [ 'edit_others_posts' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	public function test_current_user_is_author_returns_caps_unchanged_without_lookup(): void {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->never();
		$filter = new InstructorAccessFilter( $lookup );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'vl_course';
		$post->post_author = '7';
		Functions\when( 'get_post' )->justReturn( $post );

		$caps   = [ 'edit_vl_courses' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	public function test_lookup_returning_false_returns_caps_unchanged(): void {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->once()->with( 7, 42 )->andReturnFalse();
		$filter = new InstructorAccessFilter( $lookup );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'vl_course';
		$post->post_author = '99';
		Functions\when( 'get_post' )->justReturn( $post );

		$caps   = [ 'edit_others_vl_courses' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	public function test_co_instructor_on_edit_post_returns_own_content_primitive(): void {
		$result = $this->run_filter_for_co_instructor(
			'vl_course',
			'edit_post',
			[ 'edit_posts' => 'edit_vl_courses' ]
		);

		self::assertSame( [ 'edit_vl_courses' ], $result );
	}

	public function test_co_instructor_on_delete_post_returns_own_content_primitive(): void {
		$result = $this->run_filter_for_co_instructor(
			'vl_course',
			'delete_post',
			[ 'delete_posts' => 'delete_vl_courses' ]
		);

		self::assertSame( [ 'delete_vl_courses' ], $result );
	}

	public function test_co_instructor_on_read_post_returns_own_content_primitive(): void {
		$result = $this->run_filter_for_co_instructor(
			'vl_course',
			'read_post',
			[ 'read_private_posts' => 'read_private_vl_courses' ]
		);

		self::assertSame( [ 'read_private_vl_courses' ], $result );
	}

	public function test_missing_post_type_object_returns_caps_unchanged(): void {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->once()->andReturnTrue();
		$filter = new InstructorAccessFilter( $lookup );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'vl_course';
		$post->post_author = '99';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_type_object' )->justReturn( null );

		$caps   = [ 'edit_others_vl_courses' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	public function test_missing_primitive_on_cap_object_returns_caps_unchanged(): void {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->once()->andReturnTrue();
		$filter = new InstructorAccessFilter( $lookup );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = 'vl_course';
		$post->post_author = '99';
		Functions\when( 'get_post' )->justReturn( $post );

		$type_object      = Mockery::mock( 'WP_Post_Type' );
		$type_object->cap = new stdClass();
		Functions\when( 'get_post_type_object' )->justReturn( $type_object );

		$caps   = [ 'edit_others_vl_courses' ];
		$result = $filter->filter_meta_caps( $caps, 'edit_post', 7, [ 42 ] );

		self::assertSame( $caps, $result );
	}

	/**
	 * @dataProvider vl_post_types
	 */
	public function test_co_instructor_primitive_is_derived_per_post_type(
		string $post_type,
		string $expected_primitive
	): void {
		$result = $this->run_filter_for_co_instructor(
			$post_type,
			'edit_post',
			[ 'edit_posts' => $expected_primitive ]
		);

		self::assertSame( [ $expected_primitive ], $result );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function vl_post_types(): array {
		return [
			'vl_course'     => [ 'vl_course', 'edit_vl_courses' ],
			'vl_lesson'     => [ 'vl_lesson', 'edit_vl_lessons' ],
			'vl_webinar'    => [ 'vl_webinar', 'edit_vl_webinars' ],
			'vl_assignment' => [ 'vl_assignment', 'edit_vl_assignments' ],
		];
	}

	private function never_called_lookup(): CoInstructorLookupInterface {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->never();
		return $lookup;
	}

	/**
	 * @param array<string, string> $cap_properties
	 *
	 * @return list<string>
	 */
	private function run_filter_for_co_instructor(
		string $post_type,
		string $meta_cap,
		array $cap_properties
	): array {
		$lookup = Mockery::mock( CoInstructorLookupInterface::class );
		$lookup->shouldReceive( 'is_co_instructor' )->once()->with( 7, 42 )->andReturnTrue();
		$filter = new InstructorAccessFilter( $lookup );

		$post              = Mockery::mock( 'WP_Post' );
		$post->post_type   = $post_type;
		$post->post_author = '99';
		Functions\when( 'get_post' )->justReturn( $post );

		$cap_object = new stdClass();
		foreach ( $cap_properties as $property => $value ) {
			$cap_object->{$property} = $value;
		}

		$type_object      = Mockery::mock( 'WP_Post_Type' );
		$type_object->cap = $cap_object;
		Functions\when( 'get_post_type_object' )->justReturn( $type_object );

		return $filter->filter_meta_caps( [ 'unused' ], $meta_cap, 7, [ 42 ] );
	}
}
