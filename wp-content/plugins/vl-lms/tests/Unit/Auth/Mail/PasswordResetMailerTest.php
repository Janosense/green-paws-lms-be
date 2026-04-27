<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\Mail;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\Mail\PasswordResetMailer;
use VL\LMS\Support\Logger;

final class PasswordResetMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private PasswordResetMailer $mailer;

	/** @var list<array{name: string, callback: callable, priority: int}> */
	private array $active_filters;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );

		$this->active_filters = [];
		Functions\when( 'add_filter' )->alias(
			function ( string $name, callable $callback, int $priority = 10 ): bool {
				$this->active_filters[] = [
					'name'     => $name,
					'callback' => $callback,
					'priority' => $priority,
				];
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $name, callable $callback, int $priority = 10 ): bool {
				foreach ( $this->active_filters as $index => $entry ) {
					if ( $entry['name'] === $name && $entry['callback'] === $callback && $entry['priority'] === $priority ) {
						unset( $this->active_filters[ $index ] );
						return true;
					}
				}
				return false;
			}
		);

		$this->logger = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->mailer = new PasswordResetMailer( $this->logger );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_user( int $id = 42, string $email = 'user@example.test', string $first_name = 'Ada', string $login = 'ada' ): object {
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $id;
		$user->user_email = $email;
		$user->first_name = $first_name;
		$user->user_login = $login;
		return $user;
	}

	public function test_send_calls_wp_mail_with_expected_recipient_subject_and_reset_url(): void {
		if ( defined( 'VL_LMS_APP_URL' ) ) {
			$this->markTestSkipped( 'VL_LMS_APP_URL already defined; cannot verify constant-driven URL deterministically.' );
		}

		$user = $this->make_user();

		$captured = null;
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( string $to, string $subject, string $body ) use ( &$captured ): bool {
					$captured = compact( 'to', 'subject', 'body' );
					return true;
				}
			);

		$result = $this->mailer->send( $user, 'PLAIN_TOKEN_XYZ' );

		self::assertTrue( $result );
		self::assertNotNull( $captured );
		self::assertSame( 'user@example.test', $captured['to'] );
		self::assertSame( 'Reset your password', $captured['subject'] );
		self::assertStringContainsString( '/reset-password?token=PLAIN_TOKEN_XYZ', $captured['body'] );
		self::assertStringContainsString( 'Ada', $captured['body'] );
	}

	public function test_send_honours_subject_and_body_filters(): void {
		$user = $this->make_user();

		Filters\expectApplied( 'vl_lms_password_reset_email_subject' )
			->once()
			->andReturn( 'Filtered subject' );
		Filters\expectApplied( 'vl_lms_password_reset_email_body' )
			->once()
			->andReturn( '<p>Filtered body</p>' );

		$captured = null;
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( string $to, string $subject, string $body ) use ( &$captured ): bool {
					$captured = compact( 'to', 'subject', 'body' );
					return true;
				}
			);

		$this->mailer->send( $user, 'PLAIN_TOKEN' );

		self::assertSame( 'Filtered subject', $captured['subject'] );
		self::assertSame( '<p>Filtered body</p>', $captured['body'] );
	}

	public function test_send_scopes_content_type_filter_and_removes_it_after(): void {
		Functions\expect( 'wp_mail' )->once()->andReturnTrue();

		$this->mailer->send( $this->make_user(), 'PLAIN_TOKEN' );

		foreach ( $this->active_filters as $entry ) {
			self::assertNotSame(
				'wp_mail_content_type',
				$entry['name'],
				'wp_mail_content_type filter must be removed after send() completes.'
			);
		}
	}

	public function test_send_removes_content_type_filter_even_when_wp_mail_throws(): void {
		Functions\expect( 'wp_mail' )->once()->andThrow( new \RuntimeException( 'mail down' ) );

		try {
			$this->mailer->send( $this->make_user(), 'PLAIN_TOKEN' );
			self::fail( 'Expected RuntimeException to propagate out of send().' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'mail down', $e->getMessage() );
		}

		foreach ( $this->active_filters as $entry ) {
			self::assertNotSame(
				'wp_mail_content_type',
				$entry['name'],
				'Content-type filter must be removed via finally even when wp_mail throws.'
			);
		}
	}

	public function test_send_logs_warning_only_once_when_app_url_constant_is_missing(): void {
		if ( defined( 'VL_LMS_APP_URL' ) ) {
			$this->markTestSkipped( 'VL_LMS_APP_URL already defined; cannot verify fallback warning.' );
		}

		Functions\expect( 'wp_mail' )->twice()->andReturnTrue();

		$this->logger->shouldReceive( 'warning' )
			->once()
			->with( Mockery::pattern( '/VL_LMS_APP_URL/' ) );

		$user = $this->make_user();
		$this->mailer->send( $user, 'TOKEN_A' );
		$this->mailer->send( $user, 'TOKEN_B' );
	}

	public function test_send_uses_login_when_first_name_is_empty(): void {
		$user = $this->make_user( first_name: '', login: 'alpha-login' );

		$captured = null;
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( string $to, string $subject, string $body ) use ( &$captured ): bool {
					$captured = $body;
					return true;
				}
			);

		$this->mailer->send( $user, 'PLAIN_TOKEN' );

		self::assertStringContainsString( 'alpha-login', (string) $captured );
	}
}
