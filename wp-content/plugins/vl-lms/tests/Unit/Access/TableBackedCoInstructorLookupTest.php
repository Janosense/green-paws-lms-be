<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Access;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Access\TableBackedCoInstructorLookup;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;

final class TableBackedCoInstructorLookupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_false_for_non_positive_user_id_without_touching_repo(): void {
		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )->never();

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertFalse( $lookup->is_co_instructor( 0, 500 ) );
		self::assertFalse( $lookup->is_co_instructor( -1, 500 ) );
	}

	public function test_returns_false_for_non_positive_post_id_without_touching_repo(): void {
		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )->never();

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertFalse( $lookup->is_co_instructor( 7, 0 ) );
		self::assertFalse( $lookup->is_co_instructor( 7, -1 ) );
	}

	public function test_returns_false_when_post_type_is_unrelated(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )->never();

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertFalse( $lookup->is_co_instructor( 7, 500 ) );
	}

	public function test_returns_true_for_vl_course_when_repo_says_assigned(): void {
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )
			->once()
			->with( InstructorEntityType::COURSE, 500, 7 )
			->andReturn( true );

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertTrue( $lookup->is_co_instructor( 7, 500 ) );
	}

	public function test_returns_false_for_vl_course_when_repo_says_not_assigned(): void {
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )
			->once()
			->with( InstructorEntityType::COURSE, 500, 7 )
			->andReturn( false );

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertFalse( $lookup->is_co_instructor( 7, 500 ) );
	}

	public function test_returns_true_for_vl_webinar_when_repo_says_assigned(): void {
		Functions\when( 'get_post_type' )->justReturn( 'vl_webinar' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )
			->once()
			->with( InstructorEntityType::WEBINAR, 777, 7 )
			->andReturn( true );

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertTrue( $lookup->is_co_instructor( 7, 777 ) );
	}

	public function test_second_call_with_same_pair_uses_cache(): void {
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )
			->once()
			->andReturn( true );

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertTrue( $lookup->is_co_instructor( 7, 500 ) );
		self::assertTrue( $lookup->is_co_instructor( 7, 500 ), 'Second call must not hit the repo.' );
	}

	public function test_cache_keys_distinguish_users_and_posts(): void {
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$repo = Mockery::mock( CourseInstructorRepository::class );
		$repo->shouldReceive( 'is_assigned' )
			->with( InstructorEntityType::COURSE, 500, 7 )
			->once()
			->andReturn( true );
		$repo->shouldReceive( 'is_assigned' )
			->with( InstructorEntityType::COURSE, 500, 8 )
			->once()
			->andReturn( false );
		$repo->shouldReceive( 'is_assigned' )
			->with( InstructorEntityType::COURSE, 501, 7 )
			->once()
			->andReturn( false );

		$lookup = new TableBackedCoInstructorLookup( $repo );

		self::assertTrue( $lookup->is_co_instructor( 7, 500 ) );
		self::assertFalse( $lookup->is_co_instructor( 8, 500 ) );
		self::assertFalse( $lookup->is_co_instructor( 7, 501 ) );
	}

	public function test_unrelated_post_type_result_is_also_cached(): void {
		$call_count = 0;
		Functions\when( 'get_post_type' )->alias(
			static function () use ( &$call_count ): string {
				++$call_count;
				return 'post';
			}
		);

		$repo   = Mockery::mock( CourseInstructorRepository::class );
		$lookup = new TableBackedCoInstructorLookup( $repo );

		$lookup->is_co_instructor( 7, 500 );
		$lookup->is_co_instructor( 7, 500 );

		self::assertSame( 1, $call_count, 'get_post_type must only be queried once for the same post.' );
	}
}
