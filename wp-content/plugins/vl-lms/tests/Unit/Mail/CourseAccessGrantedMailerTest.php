<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Mail;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Mail\CourseAccessGrantedMailer;
use VL\LMS\Mail\HtmlMailSender;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_User;

final class CourseAccessGrantedMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var Mockery\MockInterface&AppUrlResolver */
	private $url_resolver;

	/** @var Mockery\MockInterface&HtmlMailSender */
	private $sender;

	private CourseAccessGrantedMailer $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		Filters\expectApplied( 'vl_lms_course_access_granted_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_course_access_granted_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->logger       = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->url_resolver = Mockery::mock( AppUrlResolver::class );
		$this->url_resolver->shouldReceive( 'path' )
			->andReturnUsing( static fn ( string $path ): string => 'http://localhost:3000' . $path );
		$this->sender = Mockery::mock( HtmlMailSender::class );

		$this->mailer = new CourseAccessGrantedMailer(
			$this->logger,
			$this->url_resolver,
			$this->sender
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_user( string $email = 'student@example.com' ): WP_User {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 7;
		$user->user_email = $email;
		$user->first_name = 'Olena';
		$user->user_login = 'olena';

		Functions\when( 'get_userdata' )->justReturn( $user );

		return $user;
	}

	private function stub_course( string $slug = 'cms-101', string $title = 'CMS 101' ): void {
		$post             = Mockery::mock( WP_Post::class );
		$post->ID         = 99;
		$post->post_name  = $slug;
		$post->post_title = $title;

		Functions\when( 'get_post' )->justReturn( $post );
	}

	public function test_returns_false_and_logs_when_user_has_no_email(): void {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 7;
		$user->user_email = '';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->logger->shouldReceive( 'warning' )->once();
		$this->sender->shouldNotReceive( 'send' );

		self::assertFalse( $this->mailer->send( 7, 99 ) );
	}

	public function test_returns_false_when_course_missing(): void {
		$this->stub_user();
		Functions\when( 'get_post' )->justReturn( null );

		$this->logger->shouldReceive( 'warning' )->once();
		$this->sender->shouldNotReceive( 'send' );

		self::assertFalse( $this->mailer->send( 7, 99 ) );
	}

	public function test_sends_to_student_with_course_title_and_link(): void {
		$this->stub_user( 'student@example.com' );
		$this->stub_course( 'cms-101', 'CMS 101' );

		$captured = [];
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured ): bool {
					$captured = compact( 'to', 'subject', 'body' );
					return true;
				}
			);

		self::assertTrue( $this->mailer->send( 7, 99 ) );
		self::assertSame( 'student@example.com', $captured['to'] );
		self::assertStringContainsString( 'CMS 101', $captured['subject'] );
		self::assertStringContainsString( 'http://localhost:3000/courses/cms-101', $captured['body'] );
	}

	public function test_returns_false_when_sender_returns_false(): void {
		$this->stub_user();
		$this->stub_course();
		$this->sender->shouldReceive( 'send' )->once()->andReturn( false );

		self::assertFalse( $this->mailer->send( 7, 99 ) );
	}
}
