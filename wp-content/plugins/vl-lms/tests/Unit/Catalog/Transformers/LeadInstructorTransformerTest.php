<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Transformers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Transformers\LeadInstructorTransformer;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;

final class LeadInstructorTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private LeadInstructorTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transformer = new LeadInstructorTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_null_when_lead_is_null(): void {
		self::assertNull( $this->transformer->transform( null ) );
	}

	public function test_returns_null_when_user_is_missing(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		self::assertNull( $this->transformer->transform( $this->lead( 5, 7 ) ) );
	}

	public function test_uses_attachment_avatar_when_meta_points_at_real_image(): void {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 7;
		$user->display_name = 'Dr. Olena Petrenko';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->justReturn( '11' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			[ 'https://example.test/avatar/medium.jpg', 300, 300, false ]
		);

		$out = $this->transformer->transform( $this->lead( 5, 7 ) );

		self::assertIsArray( $out );
		self::assertSame( 7, $out['id'] );
		self::assertSame( 'Dr. Olena Petrenko', $out['display_name'] );
		self::assertSame( 'https://example.test/avatar/medium.jpg', $out['avatar']['url'] );
		self::assertSame( 96, $out['avatar']['size'] );
	}

	public function test_falls_back_to_gravatar_when_no_avatar_meta(): void {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 7;
		$user->display_name = 'Dr. Olena Petrenko';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->justReturn( '0' );
		Functions\when( 'get_avatar_url' )->justReturn( 'https://gravatar.test/abc?s=96' );

		$out = $this->transformer->transform( $this->lead( 5, 7 ) );

		self::assertIsArray( $out );
		self::assertSame( 'https://gravatar.test/abc?s=96', $out['avatar']['url'] );
		self::assertSame( 96, $out['avatar']['size'] );
	}

	public function test_falls_back_to_gravatar_when_attachment_resolution_fails(): void {
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = 7;
		$user->display_name = 'Dr. Olena Petrenko';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->justReturn( '11' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_avatar_url' )->justReturn( 'https://gravatar.test/abc?s=96' );

		$out = $this->transformer->transform( $this->lead( 5, 7 ) );

		self::assertIsArray( $out );
		self::assertSame( 'https://gravatar.test/abc?s=96', $out['avatar']['url'] );
	}

	private function lead( int $id, int $user_id ): CourseInstructor {
		return new CourseInstructor(
			id: $id,
			entity_type: InstructorEntityType::COURSE,
			entity_id: 100,
			user_id: $user_id,
			role_in_course: InstructorRole::LEAD,
			display_order: 0,
			assigned_at: '2026-01-01 00:00:00',
			assigned_by: $user_id
		);
	}
}
