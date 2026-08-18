<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Mail;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\SnapshotBuilder;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Mail\CertificateIssuedMailer;
use VL\LMS\Mail\HtmlMailSender;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_User;

final class CertificateIssuedMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var Mockery\MockInterface&CertificateRepository */
	private $certificates;

	/** @var Mockery\MockInterface&AppUrlResolver */
	private $url_resolver;

	/** @var Mockery\MockInterface&HtmlMailSender */
	private $sender;

	private CertificateIssuedMailer $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		Filters\expectApplied( 'vl_lms_certificate_issued_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_certificate_issued_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->logger       = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->certificates = Mockery::mock( CertificateRepository::class );
		$this->url_resolver = Mockery::mock( AppUrlResolver::class );
		$this->url_resolver->shouldReceive( 'path' )
			->andReturnUsing( static fn ( string $path ): string => 'http://localhost:3000' . $path );
		$this->sender = Mockery::mock( HtmlMailSender::class );

		$this->mailer = new CertificateIssuedMailer(
			$this->logger,
			$this->certificates,
			$this->url_resolver,
			$this->sender
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Snapshot mirrors {@see SnapshotBuilder::build_snapshot()}'s real FLAT
	 * shape — the previous fixture invented a nested `course.title` key the
	 * builder never produces, which let the mailer read the wrong key and
	 * still pass. Pass `null` for `$course_title` to omit the key entirely.
	 */
	private function make_certificate( string $uuid = 'abc-123', ?string $course_title = 'WordPress Mastery' ): Certificate {
		$now      = new \DateTimeImmutable( '2026-05-06 12:00:00', new \DateTimeZone( 'UTC' ) );
		$snapshot = [
			'course_slug'          => 'wordpress-mastery',
			'learner_full_name'    => 'Bohdan Kovalenko',
			'learner_display_name' => 'Bohdan K.',
			'instructor_names'     => [ 'Olena P' ],
			'issuer_name'          => 'Green Paws LMS',
			'issued_at_iso'        => $now->format( \DateTimeInterface::ATOM ),
			'final_score_pct'      => 90,
			'template_version'     => 'v1',
		];
		if ( null !== $course_title ) {
			$snapshot = [ 'course_title' => $course_title ] + $snapshot;
		}

		return new Certificate(
			id: 11,
			uuid: $uuid,
			user_id: 5,
			course_id: 50,
			enrollment_id: 100,
			issued_at: $now,
			revoked_at: null,
			final_score: 90,
			final_max_score: 100,
			snapshot_data: $snapshot,
			pdf_path: null,
			created_at: $now,
			updated_at: $now
		);
	}

	private function stub_user( string $email = 'graduate@example.com' ): WP_User {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 5;
		$user->user_email = $email;
		$user->first_name = 'Bohdan';
		$user->user_login = 'bohdan';

		Functions\when( 'get_userdata' )->justReturn( $user );

		return $user;
	}

	public function test_sends_to_certificate_owner(): void {
		$this->certificates->shouldReceive( 'find' )->once()->with( 11 )->andReturn( $this->make_certificate() );
		$this->stub_user( 'graduate@example.com' );

		$captured_to = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_to ): bool {
					$captured_to = $to;
					return true;
				}
			);

		self::assertTrue( $this->mailer->send( 11, 5 ) );
		self::assertSame( 'graduate@example.com', $captured_to );
	}

	public function test_subject_includes_course_title(): void {
		$this->certificates->shouldReceive( 'find' )->once()->andReturn( $this->make_certificate( 'abc', 'WordPress Mastery' ) );
		$this->stub_user();

		$captured_subject = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_subject ): bool {
					$captured_subject = $subject;
					return true;
				}
			);

		$this->mailer->send( 11, 5 );

		self::assertStringContainsString( 'WordPress Mastery', (string) $captured_subject );
	}

	public function test_subject_falls_back_when_course_title_missing(): void {
		$this->certificates->shouldReceive( 'find' )->once()->andReturn( $this->make_certificate( 'abc', null ) );
		$this->stub_user();

		$captured_subject = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_subject ): bool {
					$captured_subject = $subject;
					return true;
				}
			);

		$this->mailer->send( 11, 5 );

		self::assertStringContainsString( 'your course', (string) $captured_subject );
	}

	public function test_subject_uses_course_title_from_real_snapshot_builder_output(): void {
		// Producer↔consumer drift pin: the snapshot flows through the REAL
		// SnapshotBuilder, so a key rename on either side breaks this test
		// instead of shipping a "your course" subject again.
		$now = new \DateTimeImmutable( '2026-05-06 12:00:00', new \DateTimeZone( 'UTC' ) );

		$course              = Mockery::mock( 'WP_Post' );
		$course->ID          = 50;
		$course->post_title  = 'Анестезіологія для практиків';
		$course->post_name   = 'anesteziolohiia';
		$course->post_author = '7';

		$user               = $this->stub_user();
		$user->last_name    = 'Kovalenko';
		$user->display_name = 'Bohdan K';

		Functions\when( 'get_post' )->justReturn( $course );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_option' )->returnArg( 2 );

		$instructors = Mockery::mock( CourseInstructorService::class );
		$instructors->shouldReceive( 'list_for_entity' )->andReturn( [] );

		$snapshot = ( new SnapshotBuilder( $instructors ) )->build_snapshot( 5, 50, 90, 100, $now );

		$certificate = new Certificate(
			id: 11,
			uuid: 'abc-123',
			user_id: 5,
			course_id: 50,
			enrollment_id: 100,
			issued_at: $now,
			revoked_at: null,
			final_score: 90,
			final_max_score: 100,
			snapshot_data: $snapshot,
			pdf_path: null,
			created_at: $now,
			updated_at: $now
		);

		$this->certificates->shouldReceive( 'find' )->once()->andReturn( $certificate );

		$captured_subject = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_subject ): bool {
					$captured_subject = $subject;
					return true;
				}
			);

		$this->mailer->send( 11, 5 );

		self::assertStringContainsString( 'Анестезіологія для практиків', (string) $captured_subject );
		self::assertStringNotContainsString( 'your course', (string) $captured_subject );
	}

	public function test_body_contains_certificate_view_url_with_uuid(): void {
		$this->certificates->shouldReceive( 'find' )->once()->andReturn( $this->make_certificate( 'cert-uuid-7' ) );
		$this->stub_user();

		$captured_body = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_body ): bool {
					$captured_body = $body;
					return true;
				}
			);

		$this->mailer->send( 11, 5 );

		self::assertStringContainsString( 'cert-uuid-7', (string) $captured_body );
		self::assertStringContainsString( 'certificates/cert-uuid-7', (string) $captured_body );
	}

	public function test_returns_false_when_html_mail_sender_returns_false(): void {
		$this->certificates->shouldReceive( 'find' )->once()->andReturn( $this->make_certificate() );
		$this->stub_user();

		$this->sender->shouldReceive( 'send' )->once()->andReturn( false );

		self::assertFalse( $this->mailer->send( 11, 5 ) );
	}
}
