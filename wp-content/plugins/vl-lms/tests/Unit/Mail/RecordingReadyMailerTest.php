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
use VL\LMS\Mail\RecordingReadyMailer;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_User;

final class RecordingReadyMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var Mockery\MockInterface&AppUrlResolver */
	private $url_resolver;

	/** @var Mockery\MockInterface&HtmlMailSender */
	private $sender;

	private RecordingReadyMailer $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		Filters\expectApplied( 'vl_lms_recording_ready_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_recording_ready_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->logger       = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->url_resolver = Mockery::mock( AppUrlResolver::class );
		$this->url_resolver->shouldReceive( 'path' )
			->andReturnUsing( static fn ( string $path ): string => 'http://localhost:3000' . $path );
		$this->sender = Mockery::mock( HtmlMailSender::class );

		$this->mailer = new RecordingReadyMailer(
			$this->logger,
			$this->url_resolver,
			$this->sender
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_post( string $type, string $slug, string $title ): WP_Post {
		$post            = Mockery::mock( WP_Post::class );
		$post->ID        = 300;
		$post->post_type = $type;
		$post->post_name = $slug;

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_the_title' )->justReturn( $title );

		return $post;
	}

	private function stub_user(): WP_User {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 9;
		$user->user_email = 'viewer@example.com';
		$user->first_name = 'Iryna';
		$user->user_login = 'iryna';

		Functions\when( 'get_userdata' )->justReturn( $user );

		return $user;
	}

	public function test_sends_to_session_attendee_when_post_kind_is_session(): void {
		$this->stub_post( 'vl_session', 'unit-3-live', 'Unit 3 Live' );
		$this->stub_user();

		$captured_to   = null;
		$captured_body = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_to, &$captured_body ): bool {
					$captured_to   = $to;
					$captured_body = $body;
					return true;
				}
			);

		$this->mailer->send( 300, 9, PostKind::SESSION );

		self::assertSame( 'viewer@example.com', $captured_to );
		self::assertStringContainsString( '/learn/sessions/unit-3-live', (string) $captured_body );
	}

	public function test_sends_to_webinar_registrant_when_post_kind_is_webinar(): void {
		$this->stub_post( 'vl_webinar', 'kickoff', 'Kickoff Webinar' );
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

		$this->mailer->send( 300, 9, PostKind::WEBINAR );

		self::assertStringContainsString( '/dashboard/webinars/kickoff', (string) $captured_body );
	}

	public function test_subject_includes_entity_title_and_recording_marker(): void {
		$this->stub_post( 'vl_webinar', 'kickoff', 'Kickoff Webinar' );
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

		$this->mailer->send( 300, 9, PostKind::WEBINAR );

		self::assertStringContainsString( 'Kickoff Webinar', (string) $captured_subject );
		self::assertStringContainsString( 'recording', strtolower( (string) $captured_subject ) );
	}

	public function test_returns_false_when_html_mail_sender_returns_false(): void {
		$this->stub_post( 'vl_session', 'orientation', 'Orientation' );
		$this->stub_user();

		$this->sender->shouldReceive( 'send' )->once()->andReturn( false );

		self::assertFalse( $this->mailer->send( 300, 9, PostKind::SESSION ) );
	}
}
