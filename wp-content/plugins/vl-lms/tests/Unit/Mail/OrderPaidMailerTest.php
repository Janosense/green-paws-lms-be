<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Mail;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Mail\HtmlMailSender;
use VL\LMS\Mail\OrderPaidMailer;
use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_User;

final class OrderPaidMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	/** @var Mockery\MockInterface&AppUrlResolver */
	private $url_resolver;

	/** @var Mockery\MockInterface&HtmlMailSender */
	private $sender;

	private OrderPaidMailer $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_date' )->alias(
			static fn ( string $format, int $ts ): string => gmdate( $format, $ts )
		);

		Filters\expectApplied( 'vl_lms_order_paid_subject' )->zeroOrMoreTimes()->andReturnFirstArg();
		Filters\expectApplied( 'vl_lms_order_paid_body' )->zeroOrMoreTimes()->andReturnFirstArg();

		$this->logger       = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->url_resolver = Mockery::mock( AppUrlResolver::class );
		$this->url_resolver->shouldReceive( 'path' )
			->andReturnUsing( static fn ( string $path ): string => 'http://localhost:3000' . $path );
		$this->sender = Mockery::mock( HtmlMailSender::class );

		$this->mailer = new OrderPaidMailer(
			$this->logger,
			$this->url_resolver,
			$this->sender
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function make_order( PurchasableEntityType $type, string $slug, string $title = 'Sample' ): Order {
		return new Order(
			11,
			'order-uuid-1',
			7,
			OrderStatus::PAID,
			'liqpay',
			'lp-1',
			$type,
			500,
			$slug,
			$title,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' ),
			self::utc( '2026-05-01 12:30:00' )
		);
	}

	private function make_payment(): Payment {
		return new Payment(
			null,
			11,
			PaymentProvider::LIQPAY,
			'lp-1',
			'pay',
			PaymentStatus::SUCCESS,
			'success',
			PaymentTransactionType::CHARGE,
			Money::from_major_decimal( '1500.00', 'UAH' ),
			'{}',
			self::utc( '2026-05-01 12:30:00' ),
			'idem-1'
		);
	}

	private function stub_user( string $email = 'buyer@example.com' ): WP_User {
		$user             = Mockery::mock( WP_User::class );
		$user->ID         = 7;
		$user->user_email = $email;
		$user->first_name = 'Olena';
		$user->user_login = 'olena';

		Functions\when( 'get_userdata' )->justReturn( $user );

		return $user;
	}

	public function test_sends_to_order_purchaser(): void {
		$this->stub_user( 'buyer@example.com' );

		$captured_to = null;
		$this->sender->shouldReceive( 'send' )
			->once()
			->andReturnUsing(
				function ( string $to, string $subject, string $body ) use ( &$captured_to ): bool {
					$captured_to = $to;
					return true;
				}
			);

		self::assertTrue( $this->mailer->send( $this->make_order( PurchasableEntityType::COURSE, 'cms-101' ), $this->make_payment() ) );
		self::assertSame( 'buyer@example.com', $captured_to );
	}

	public function test_subject_signals_successful_purchase_with_entity_title(): void {
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

		$this->mailer->send( $this->make_order( PurchasableEntityType::COURSE, 'cms-101', 'CMS 101' ), $this->make_payment() );

		self::assertStringContainsString( 'CMS 101', (string) $captured_subject );
		self::assertStringContainsString( 'Дякуємо', (string) $captured_subject );
	}

	public function test_body_cta_for_course_entity_points_to_courses_route(): void {
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

		$this->mailer->send( $this->make_order( PurchasableEntityType::COURSE, 'cms-101' ), $this->make_payment() );

		self::assertStringContainsString( 'http://localhost:3000/courses/cms-101', (string) $captured_body );
	}

	public function test_body_cta_for_webinar_entity_points_to_dashboard_webinars_route(): void {
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

		$this->mailer->send( $this->make_order( PurchasableEntityType::WEBINAR, 'kickoff' ), $this->make_payment() );

		self::assertStringContainsString( 'http://localhost:3000/dashboard/webinars/kickoff', (string) $captured_body );
	}

	public function test_returns_false_when_html_mail_sender_returns_false(): void {
		$this->stub_user();

		$this->sender->shouldReceive( 'send' )->once()->andReturn( false );

		self::assertFalse( $this->mailer->send( $this->make_order( PurchasableEntityType::COURSE, 'cms-101' ), $this->make_payment() ) );
	}
}
