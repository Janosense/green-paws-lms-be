<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Students\StudentEnrollmentFormHandler;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Mail\CourseAccessGrantedMailer;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_User;

final class StudentEnrollmentFormHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollments;

	/** @var Mockery\MockInterface&CourseAccessGrantedMailer */
	private $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->mailer      = Mockery::mock( CourseAccessGrantedMailer::class );

		$_POST = [];
		$_GET  = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function handler(): TestableStudentEnrollmentFormHandler {
		return new TestableStudentEnrollmentFormHandler(
			new EnrollmentService( $this->enrollments ),
			$this->enrollments,
			$this->mailer
		);
	}

	private function stub_student( int $user_id ): void {
		Functions\when( 'get_userdata' )->alias(
			static function () use ( $user_id ): WP_User {
				$user        = new WP_User();
				$user->ID    = $user_id;
				$user->roles = [ 'student' ];
				return $user;
			}
		);
	}

	private function stub_published_course(): void {
		Functions\when( 'get_post' )->alias(
			static function () {
				$post              = Mockery::mock( 'WP_Post' );
				$post->ID          = 321;
				$post->post_type   = 'vl_course';
				$post->post_status = 'publish';
				return $post;
			}
		);
	}

	public function test_grant_rejects_non_student(): void {
		$_POST = [
			'user_id'   => '42',
			'course_id' => '321',
		];
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->mailer->shouldNotReceive( 'send' );

		$h = $this->handler();
		$h->handle_grant();

		self::assertStringContainsString( 'notice=user_not_found', (string) $h->redirected_to );
		self::assertNull( $this->enrollments->find_for_user_and_course( 42, 321 ) );
	}

	public function test_grant_rejects_unpublished_course(): void {
		$_POST = [
			'user_id'   => '7',
			'course_id' => '999',
		];
		$this->stub_student( 7 );
		Functions\when( 'get_post' )->justReturn( null );

		$this->mailer->shouldNotReceive( 'send' );

		$h = $this->handler();
		$h->handle_grant();

		self::assertStringContainsString( 'notice=course_not_found', (string) $h->redirected_to );
		self::assertNull( $this->enrollments->find_for_user_and_course( 7, 999 ) );
	}

	public function test_grant_enrolls_with_grant_source_and_sends_email(): void {
		$_POST = [
			'user_id'   => '7',
			'course_id' => '321',
		];
		$this->stub_student( 7 );
		$this->stub_published_course();

		$this->mailer->shouldReceive( 'send' )->once()->with( 7, 321 )->andReturn( true );

		$h = $this->handler();
		$h->handle_grant();

		self::assertStringContainsString( 'notice=course_granted', (string) $h->redirected_to );
		self::assertStringContainsString( 'tab=courses', (string) $h->redirected_to );

		$enrollment = $this->enrollments->find_for_user_and_course( 7, 321 );
		self::assertNotNull( $enrollment );
		self::assertSame( EnrollmentStatus::ACTIVE, $enrollment->status );
		self::assertSame( EnrollmentSource::GRANT, $enrollment->source );
	}

	public function test_revoke_marks_enrollment_revoked(): void {
		$id   = $this->enrollments->seed(
			[
				'user_id'   => 7,
				'course_id' => 321,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);
		$_GET = [
			'user_id'   => '7',
			'course_id' => '321',
		];

		$h = $this->handler();
		$h->handle_revoke();

		self::assertStringContainsString( 'notice=course_revoked', (string) $h->redirected_to );
		$enrollment = $this->enrollments->find_by_id( $id );
		self::assertNotNull( $enrollment );
		self::assertSame( EnrollmentStatus::REVOKED, $enrollment->status );
	}

	public function test_revoke_without_active_enrollment_reports_not_found(): void {
		$_GET = [
			'user_id'   => '7',
			'course_id' => '321',
		];

		$h = $this->handler();
		$h->handle_revoke();

		self::assertStringContainsString( 'notice=enrollment_not_found', (string) $h->redirected_to );
	}

	public function test_action_constants_are_stable(): void {
		self::assertSame( 'vl_lms_student_grant_course', StudentEnrollmentFormHandler::ACTION_GRANT );
		self::assertSame( 'vl_lms_student_revoke_course', StudentEnrollmentFormHandler::ACTION_REVOKE );
		self::assertSame( 'vl_manage_enrollments', StudentEnrollmentFormHandler::CAPABILITY );
	}
}
