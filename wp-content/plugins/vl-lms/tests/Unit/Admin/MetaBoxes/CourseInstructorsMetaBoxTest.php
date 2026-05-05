<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\CourseInstructorsMetaBox;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use WP_Post;
use WP_User;

final class CourseInstructorsMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const string NONCE_FIELD = 'vl_lms_vl_lms_course_instructors_nonce';

	/** @var CourseInstructorService&Mockery\MockInterface */
	private $service;

	/** @var CourseInstructorRepository&Mockery\MockInterface */
	private $repository;

	private CourseInstructorsMetaBox $box;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ): int => (int) $v );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'vl_course' );

		$this->service    = Mockery::mock( CourseInstructorService::class );
		$this->repository = Mockery::mock( CourseInstructorRepository::class );
		$this->box        = new CourseInstructorsMetaBox( $this->service, $this->repository );

		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function user_with_role( int $id, string $role ): WP_User {
		$user        = new WP_User();
		$user->ID    = $id;
		$user->roles = [ $role ];
		return $user;
	}

	private function postMock( int $author_id = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->post_author = $author_id;
		assert( $post instanceof WP_Post );
		return $post;
	}

	public function test_save_calls_sync_with_filtered_instructor_ids(): void {
		$users = [
			5 => $this->user_with_role( 5, 'instructor' ),
			6 => $this->user_with_role( 6, 'instructor' ),
		];
		Functions\when( 'get_userdata' )->alias(
			static fn ( int $id ): false|WP_User => $users[ $id ] ?? false
		);

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'vl_co_instructor_ids' => [ 5, 6 ],
		];

		$this->service->shouldReceive( 'sync_co_instructors' )
			->once()
			->with( 100, [ 5, 6 ] );

		$this->box->save( 100, $this->postMock( 1 ) );
	}

	public function test_save_excludes_non_instructor_users(): void {
		$users = [
			5 => $this->user_with_role( 5, 'instructor' ),
			7 => $this->user_with_role( 7, 'subscriber' ),
		];
		Functions\when( 'get_userdata' )->alias(
			static fn ( int $id ): false|WP_User => $users[ $id ] ?? false
		);

		$_POST = [
			self::NONCE_FIELD      => 'nonce-x',
			'vl_co_instructor_ids' => [ 5, 7 ],
		];

		$this->service->shouldReceive( 'sync_co_instructors' )
			->once()
			->with( 200, [ 5 ] );

		$this->box->save( 200, $this->postMock( 1 ) );
	}
}
