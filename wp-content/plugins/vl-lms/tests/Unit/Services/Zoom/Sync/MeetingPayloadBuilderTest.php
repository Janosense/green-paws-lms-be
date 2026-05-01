<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\MeetingPayloadBuilder;
use VL\LMS\Services\Zoom\Sync\PasswordGenerator;
use VL\LMS\Services\Zoom\Sync\PostKind;
use WP_Post;

final class MeetingPayloadBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_timezone_string' )->justReturn( 'Europe/Kyiv' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Brain Monkey shim aliases wp_json_encode in the unit-test runtime.
			static fn ( $value ): string|false => json_encode( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fixed_password_generator( string $value ): PasswordGenerator {
		return new class( $value ) extends PasswordGenerator {
			public function __construct( private readonly string $value ) {}
			public function generate(): string {
				return $this->value;
			}
		};
	}

	private function post( string $title, string $content ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = 42;
		$post->post_title   = $title;
		$post->post_content = $content;
		return $post;
	}

	public function test_happy_path_produces_documented_zoom_shape(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'PWPWPWPWPW' ) );

		$payload = $builder->build(
			$this->post( 'Demo Session', 'Some agenda' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => '2026-05-10T10:30:00Z',
			],
			null
		);

		self::assertSame( 'Demo Session', $payload['topic'] );
		self::assertSame( 2, $payload['type'] );
		self::assertSame( '2026-05-10T09:00:00Z', $payload['start_time'] );
		self::assertSame( 90, $payload['duration'] );
		self::assertSame( 'Europe/Kyiv', $payload['timezone'] );
		self::assertSame( 'PWPWPWPWPW', $payload['password'] );
		self::assertSame( 'Some agenda', $payload['agenda'] );

		self::assertSame(
			[
				'host_video'        => true,
				'participant_video' => false,
				'join_before_host'  => false,
				'mute_upon_entry'   => true,
				'waiting_room'      => false,
				'audio'             => 'both',
				'auto_recording'    => 'cloud',
			],
			$payload['settings']
		);
	}

	public function test_topic_truncated_to_two_hundred_characters(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'pw' ) );
		$title   = str_repeat( 'a', 250 );

		$payload = $builder->build(
			$this->post( $title, '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => '2026-05-10T10:00:00Z',
			],
			null
		);

		self::assertSame( 200, strlen( $payload['topic'] ) );
		self::assertSame( str_repeat( 'a', 200 ), $payload['topic'] );
	}

	public function test_duration_falls_back_to_sixty_when_end_missing(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'pw' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => null,
			],
			null
		);

		self::assertSame( 60, $payload['duration'] );
	}

	public function test_duration_falls_back_to_sixty_when_end_before_start(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'pw' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T10:00:00Z',
				'scheduled_end'   => '2026-05-10T09:00:00Z',
			],
			null
		);

		self::assertSame( 60, $payload['duration'] );
	}

	public function test_start_time_normalised_to_utc_z_regardless_of_offset(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'pw' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T12:00:00+03:00',
				'scheduled_end'   => '2026-05-10T13:00:00+03:00',
			],
			null
		);

		self::assertSame( '2026-05-10T09:00:00Z', $payload['start_time'] );
	}

	public function test_start_time_omitted_when_scheduled_start_absent(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'pw' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => null,
				'scheduled_end'   => null,
			],
			null
		);

		self::assertArrayNotHasKey( 'start_time', $payload );
	}

	public function test_password_reused_when_provided(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'GENERATED!' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => '2026-05-10T10:00:00Z',
			],
			'EXISTING12'
		);

		self::assertSame( 'EXISTING12', $payload['password'] );
	}

	public function test_password_generated_when_existing_is_empty_string(): void {
		$builder = new MeetingPayloadBuilder( $this->fixed_password_generator( 'GENERATED!' ) );

		$payload = $builder->build(
			$this->post( 'X', '' ),
			PostKind::SESSION,
			[
				'scheduled_start' => '2026-05-10T09:00:00Z',
				'scheduled_end'   => '2026-05-10T10:00:00Z',
			],
			''
		);

		self::assertSame( 'GENERATED!', $payload['password'] );
	}

	public function test_fingerprint_is_deterministic_for_same_payload(): void {
		$payload = [
			'topic' => 'X',
			'type'  => 2,
		];

		self::assertSame(
			MeetingPayloadBuilder::fingerprint( $payload ),
			MeetingPayloadBuilder::fingerprint( $payload )
		);
	}

	public function test_fingerprint_is_stable_under_key_reorder(): void {
		$a = [
			'topic'    => 'X',
			'type'     => 2,
			'settings' => [
				'b' => 1,
				'a' => 2,
			],
		];
		$b = [
			'type'     => 2,
			'topic'    => 'X',
			'settings' => [
				'a' => 2,
				'b' => 1,
			],
		];

		self::assertSame(
			MeetingPayloadBuilder::fingerprint( $a ),
			MeetingPayloadBuilder::fingerprint( $b )
		);
	}

	public function test_fingerprint_changes_when_value_changes(): void {
		$a = [ 'topic' => 'A' ];
		$b = [ 'topic' => 'B' ];

		self::assertNotSame(
			MeetingPayloadBuilder::fingerprint( $a ),
			MeetingPayloadBuilder::fingerprint( $b )
		);
	}
}
