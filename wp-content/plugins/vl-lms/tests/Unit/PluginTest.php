<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VL\LMS\Plugin;

final class PluginTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( true );
		// Phase 8.2 — `OrderExpirationCron::register()` runs on every boot.
		// Stub the WP-Cron seam so the unit boot smoke-tests don't reach
		// for an uninitialized cron table.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'time' )->justReturn( 1714654800 );
		$this->reset_plugin_state();
	}

	protected function tearDown(): void {
		$this->reset_plugin_state();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boot_short_circuits_when_dependencies_are_missing(): void {
		Plugin::set_dependency_checker( static fn (): bool => false );

		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'init' )->never();
		Actions\expectAdded( 'rest_api_init' )->never();
		Actions\expectAdded( 'after_setup_theme' )->never();
		Actions\expectDone( 'vl_lms/booted' )->never();

		Plugin::instance()->boot();
	}

	public function test_boot_registers_hooks_and_fires_action_when_dependencies_present(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		// Four hooks fire on `init`: textdomain loading, CPT registration,
		// taxonomy registration, instructor profile user-meta registration.
		Actions\expectAdded( 'init' )->times( 4 );
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectAdded( 'after_setup_theme' )->once();
		Actions\expectDone( 'vl_lms/booted' )->once();

		Plugin::instance()->boot();
	}

	public function test_boot_is_idempotent(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		// Four hooks fire on `init`: textdomain loading, CPT registration,
		// taxonomy registration, instructor profile user-meta registration.
		Actions\expectAdded( 'init' )->times( 4 );
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectAdded( 'after_setup_theme' )->once();
		Actions\expectDone( 'vl_lms/booted' )->once();

		$plugin = Plugin::instance();
		$plugin->boot();
		$plugin->boot();
		$plugin->boot();
	}

	public function test_boot_registers_first_run_listener_when_pending_flag_is_set(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );
		Functions\when( 'get_option' )->justReturn( '1' );

		// Five init listeners now: textdomain, CPTs, taxonomies, instructor profile meta, first-run tasks.
		Actions\expectAdded( 'init' )->times( 5 );

		Plugin::instance()->boot();
	}

	public function test_container_resolves_catalog_and_taxonomy_controllers(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		self::assertNotNull( $container );

		$catalog = $container->get( \VL\LMS\Catalog\CatalogController::class );
		self::assertInstanceOf( \VL\LMS\Catalog\CatalogController::class, $catalog );

		$taxonomy = $container->get( \VL\LMS\Catalog\TaxonomyController::class );
		self::assertInstanceOf( \VL\LMS\Catalog\TaxonomyController::class, $taxonomy );

		$profile = $container->get( \VL\LMS\User\InstructorProfileMetaRegistrar::class );
		self::assertInstanceOf( \VL\LMS\User\InstructorProfileMetaRegistrar::class, $profile );

		$hero = $container->get( \VL\LMS\Support\HeroImageSize::class );
		self::assertInstanceOf( \VL\LMS\Support\HeroImageSize::class, $hero );

		$detail = $container->get( \VL\LMS\Catalog\CatalogDetailController::class );
		self::assertInstanceOf( \VL\LMS\Catalog\CatalogDetailController::class, $detail );

		$search = $container->get( \VL\LMS\Catalog\Search\SearchController::class );
		self::assertInstanceOf( \VL\LMS\Catalog\Search\SearchController::class, $search );
	}

	public function test_container_resolves_phase_8_1_order_bindings(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		self::assertNotNull( $container );

		$orders_controller = $container->get( \VL\LMS\Api\OrdersController::class );
		self::assertInstanceOf( \VL\LMS\Api\OrdersController::class, $orders_controller );

		$order_service = $container->get( \VL\LMS\Orders\OrderService::class );
		self::assertInstanceOf( \VL\LMS\Orders\OrderService::class, $order_service );

		$provider = $container->get( \VL\LMS\Payments\PaymentProvider::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\LiqPayClient::class, $provider );

		$liqpay_settings = $container->get( \VL\LMS\Payments\LiqPay\Settings::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\Settings::class, $liqpay_settings );
	}

	public function test_container_resolves_phase_8_2_payments_and_cron_bindings(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		self::assertNotNull( $container );

		$payments_controller = $container->get( \VL\LMS\Api\PaymentsController::class );
		self::assertInstanceOf( \VL\LMS\Api\PaymentsController::class, $payments_controller );

		$callback_handler = $container->get( \VL\LMS\Payments\LiqPay\CallbackHandler::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\CallbackHandler::class, $callback_handler );

		$callback_parser = $container->get( \VL\LMS\Payments\LiqPay\CallbackParser::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\CallbackParser::class, $callback_parser );

		$signature_verifier = $container->get( \VL\LMS\Payments\LiqPay\SignatureVerifier::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\SignatureVerifier::class, $signature_verifier );

		$fanout = $container->get( \VL\LMS\Orders\OrderEnrollmentFanout::class );
		self::assertInstanceOf( \VL\LMS\Orders\OrderEnrollmentFanout::class, $fanout );

		$cron = $container->get( \VL\LMS\Orders\OrderExpirationCron::class );
		self::assertInstanceOf( \VL\LMS\Orders\OrderExpirationCron::class, $cron );
	}

	public function test_container_resolves_phase_8_3_refund_bindings(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		self::assertNotNull( $container );

		$refund_service = $container->get( \VL\LMS\Refunds\RefundService::class );
		self::assertInstanceOf( \VL\LMS\Refunds\RefundService::class, $refund_service );

		$revoker = $container->get( \VL\LMS\Refunds\OrderRefundEnrollmentRevoker::class );
		self::assertInstanceOf( \VL\LMS\Refunds\OrderRefundEnrollmentRevoker::class, $revoker );

		$admin_controller = $container->get( \VL\LMS\Api\AdminOrdersController::class );
		self::assertInstanceOf( \VL\LMS\Api\AdminOrdersController::class, $admin_controller );

		$refund_provider = $container->get( \VL\LMS\Payments\RefundCapableProvider::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\LiqPayClient::class, $refund_provider );

		$http_client = $container->get( \VL\LMS\Payments\LiqPay\HttpClient::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\HttpClient::class, $http_client );

		$refund_parser = $container->get( \VL\LMS\Payments\LiqPay\RefundResponseParser::class );
		self::assertInstanceOf( \VL\LMS\Payments\LiqPay\RefundResponseParser::class, $refund_parser );
	}

	public function test_container_resolves_phase_8_5_notification_bindings(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		self::assertNotNull( $container );

		$paid_mailer = $container->get( \VL\LMS\Mail\OrderPaidMailer::class );
		self::assertInstanceOf( \VL\LMS\Mail\OrderPaidMailer::class, $paid_mailer );

		$refunded_mailer = $container->get( \VL\LMS\Mail\OrderRefundedMailer::class );
		self::assertInstanceOf( \VL\LMS\Mail\OrderRefundedMailer::class, $refunded_mailer );

		$failed_mailer = $container->get( \VL\LMS\Mail\OrderFailedMailer::class );
		self::assertInstanceOf( \VL\LMS\Mail\OrderFailedMailer::class, $failed_mailer );

		$paid_listener = $container->get( \VL\LMS\Services\Notifications\OrderPaidListener::class );
		self::assertInstanceOf( \VL\LMS\Services\Notifications\OrderPaidListener::class, $paid_listener );

		$refunded_listener = $container->get( \VL\LMS\Services\Notifications\OrderRefundedListener::class );
		self::assertInstanceOf( \VL\LMS\Services\Notifications\OrderRefundedListener::class, $refunded_listener );

		$failed_listener = $container->get( \VL\LMS\Services\Notifications\OrderFailedListener::class );
		self::assertInstanceOf( \VL\LMS\Services\Notifications\OrderFailedListener::class, $failed_listener );
	}

	public function test_default_dependency_check_uses_class_exists(): void {
		// Override cleared in setUp; the default path relies on the real
		// facade class, which is not loaded in the unit suite.
		self::assertFalse( Plugin::instance()->dependencies_met() );
	}

	private function reset_plugin_state(): void {
		Plugin::set_dependency_checker( null );

		$reflection = new ReflectionClass( Plugin::class );
		$reflection->getProperty( 'instance' )->setValue( null, null );
	}
}
