<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Mail;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Mail\HtmlMailSender;
use VL\LMS\Mail\WebinarReminderMailer;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_User;

final class WebinarReminderMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var Mockery\MockInterface&AppUrlResolver */
	private $url_resolver;

	/** @var Mockery\MockInterface&HtmlMailSender */
	private $sender;

	private WebinarReminderMailer $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		Filters\expectApplied( 'vl_lms_webinar_reminder_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_webinar_reminder_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->logger       = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->url_resolver = Mockery::mock( AppUrlResolver::class );
		$this->url_resolver->shouldReceive( 'path' )
			->andReturnUsing( static fn ( string $path ): string => 'http://localhost:3000' . $path );
		$this->sender = Mockery::mock( HtmlMailSender::class );

		$this->mailer = new WebinarReminderMailer(
			$this->logger,
			$this->url_resolver,
			$this->sender
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_webinar( int $id, string $slug, string $title ): WP_Post {
		$post            = Mockery::mock( WP_Post::class );
		$post->ID        = $id;
		$post->post_type = 'vl_webinar';
		$post->post_name = $slug;

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_the_title' )->justReturn( $title );

		return $post;
	}

	private function stub_user( string $email = 'attendee@example.com' ): WP_User {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 7;
		$user->user_email = $email;
		$user->first_name = 'Lina';
		$user->user_login = 'lina';

		Functions\when( 'get_userdata' )->justReturn( $user );

		return $user;
	}

	public function test_sends_email_to_registered_user_with_correct_recipient(): void {
		$this->stub_webinar( 200, 'kickoff', 'Kickoff Webinar' );
		$this->stub_user( 'attendee@example.com' );

		$captured_to = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_to ): bool {
					$captured_to = $to;
					return true;
				}
			);

		self::assertTrue( $this->mailer->send( 200, 7, '24h' ) );
		self::assertSame( 'attendee@example.com', $captured_to );
	}

	public function test_subject_includes_webinar_title_and_24h_marker_for_24h_variant(): void {
		$this->stub_webinar( 200, 'kickoff', 'Kickoff Webinar' );
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

		$this->mailer->send( 200, 7, '24h' );

		self::assertStringContainsString( 'Kickoff Webinar', (string) $captured_subject );
		self::assertStringContainsString( 'tomorrow', (string) $captured_subject );
	}

	public function test_subject_includes_webinar_title_and_1h_marker_for_1h_variant(): void {
		$this->stub_webinar( 200, 'kickoff', 'Kickoff Webinar' );
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

		$this->mailer->send( 200, 7, '1h' );

		self::assertStringContainsString( 'Kickoff Webinar', (string) $captured_subject );
		self::assertStringContainsString( 'one hour', (string) $captured_subject );
	}

	public function test_body_contains_join_url_pointing_to_dashboard_webinars_route(): void {
		$this->stub_webinar( 200, 'kickoff', 'Kickoff Webinar' );
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

		$this->mailer->send( 200, 7, '24h' );

		self::assertStringContainsString( 'http://localhost:3000/dashboard/webinars/kickoff', (string) $captured_body );
	}

	public function test_returns_false_when_html_mail_sender_returns_false(): void {
		$this->stub_webinar( 200, 'kickoff', 'Kickoff Webinar' );
		$this->stub_user();

		$this->sender->shouldReceive( 'send' )->once()->andReturn( false );

		self::assertFalse( $this->mailer->send( 200, 7, '24h' ) );
	}
}
