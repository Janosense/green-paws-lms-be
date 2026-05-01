<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Certificate\Pdf;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Certificate\Pdf\CertificateRenderer;
use VL\LMS\Certificate\Pdf\QrCodeGenerator;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Support\Logger;

final class CertificateRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&QrCodeGenerator */
	private $qr;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private CertificateRenderer $renderer;

	private \DateTimeImmutable $now;

	private string $frontend_option = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP shim for tests; the template guards on ABSPATH.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );

		$this->now      = new \DateTimeImmutable( '2026-04-29T10:00:00+00:00' );
		$this->qr       = Mockery::mock( QrCodeGenerator::class );
		$this->logger   = Mockery::mock( Logger::class )->shouldIgnoreMissing();
		$this->renderer = new CertificateRenderer( $this->qr, $this->logger );

		$ref = &$this->frontend_option;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default = false ) use ( &$ref ): mixed {
				if ( 'vl_lms_frontend_url' === $key ) {
					return $ref;
				}
				return $default;
			}
		);

		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_date' )->alias(
			static fn ( string $format, int $ts ): string => gmdate( 'j F Y', $ts )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function certificate( ?int $score_pct = 92 ): Certificate {
		return new Certificate(
			1,
			'8e2c4d2a-0000-4000-8000-000000000001',
			5,
			50,
			21,
			$this->now,
			null,
			$score_pct,
			null === $score_pct ? null : 100,
			[
				'course_title'         => 'Анестезіологія для практиків',
				'course_slug'          => 'anaesthesiology-for-practitioners',
				'learner_full_name'    => 'Богдан Коваль',
				'learner_display_name' => 'Богдан К.',
				'instructor_names'     => [ 'Олена Шевченко', 'Ілля Орел' ],
				'issuer_name'          => 'Green Paws LMS',
				'issued_at_iso'        => '2026-04-29T10:00:00+00:00',
				'final_score_pct'      => $score_pct,
				'template_version'     => 'v1',
			],
			null,
			$this->now,
			$this->now
		);
	}

	public function test_html_includes_doctype_and_course_title(): void {
		$this->qr->shouldReceive( 'generate_for_url' )
			->once()
			->andReturn( 'data:image/png;base64,QQ==' );

		$html = $this->renderer->render( $this->certificate() );

		self::assertStringContainsString( '<!doctype html>', $html );
		self::assertStringContainsString( 'Анестезіологія для практиків', $html );
		self::assertStringContainsString( 'Богдан Коваль', $html );
	}

	public function test_html_embeds_qr_data_uri(): void {
		$this->qr->shouldReceive( 'generate_for_url' )
			->once()
			->andReturn( 'data:image/png;base64,QQ==' );

		$html = $this->renderer->render( $this->certificate() );

		self::assertStringContainsString( 'data:image/png;base64,QQ==', $html );
	}

	public function test_score_line_present_when_pct_non_null(): void {
		$this->qr->shouldReceive( 'generate_for_url' )->andReturn( '' );

		$html = $this->renderer->render( $this->certificate( 92 ) );

		self::assertStringContainsString( 'Підсумкова оцінка', $html );
		self::assertStringContainsString( '92%', $html );
	}

	public function test_score_line_omitted_when_pct_null(): void {
		$this->qr->shouldReceive( 'generate_for_url' )->andReturn( '' );

		$html = $this->renderer->render( $this->certificate( null ) );

		self::assertStringNotContainsString( 'Підсумкова оцінка', $html );
	}

	public function test_uses_singular_label_for_one_instructor(): void {
		$this->qr->shouldReceive( 'generate_for_url' )->andReturn( '' );

		$cert = $this->certificate();
		// Override to one instructor.
		$reflect                      = new \ReflectionClass( Certificate::class );
		$snapshot                     = $cert->snapshot_data;
		$snapshot['instructor_names'] = [ 'Solo Teacher' ];

		$cert = new Certificate(
			$cert->id,
			$cert->uuid,
			$cert->user_id,
			$cert->course_id,
			$cert->enrollment_id,
			$cert->issued_at,
			$cert->revoked_at,
			$cert->final_score,
			$cert->final_max_score,
			$snapshot,
			$cert->pdf_path,
			$cert->created_at,
			$cert->updated_at
		);

		$html = $this->renderer->render( $cert );
		self::assertStringContainsString( 'Інструктор:', $html );
		self::assertStringNotContainsString( 'Інструктори:', $html );
	}

	public function test_uses_plural_label_for_multiple_instructors(): void {
		$this->qr->shouldReceive( 'generate_for_url' )->andReturn( '' );

		$html = $this->renderer->render( $this->certificate() );

		self::assertStringContainsString( 'Інструктори:', $html );
	}

	public function test_qr_target_uses_frontend_url_when_configured(): void {
		$this->frontend_option = 'https://frontend.test';

		$this->qr->shouldReceive( 'generate_for_url' )
			->once()
			->with( 'https://frontend.test/certificates/8e2c4d2a-0000-4000-8000-000000000001' )
			->andReturn( '' );

		$this->renderer->render( $this->certificate() );
	}

	public function test_qr_target_falls_back_to_home_url(): void {
		$this->frontend_option = '';

		$this->qr->shouldReceive( 'generate_for_url' )
			->once()
			->with( 'https://example.test/certificates/8e2c4d2a-0000-4000-8000-000000000001' )
			->andReturn( '' );

		$this->renderer->render( $this->certificate() );
	}

	public function test_html_includes_uuid_for_visible_reference(): void {
		$this->qr->shouldReceive( 'generate_for_url' )->andReturn( '' );

		$html = $this->renderer->render( $this->certificate() );

		self::assertStringContainsString( '8e2c4d2a-0000-4000-8000-000000000001', $html );
	}
}
