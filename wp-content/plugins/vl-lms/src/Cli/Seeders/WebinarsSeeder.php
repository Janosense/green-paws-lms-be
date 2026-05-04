<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\Services\Zoom\Sync\MeetingSynchronizer;

/**
 * Seeds the 5 demo webinars — one live, two scheduled, two completed.
 *
 * Each webinar gets a cover image, a lead author (rotated through the
 * three demo instructors), and a price (mix of free + 500–2000 UAH). Times
 * are anchored on `current_time( 'mysql' )` so re-runs of the seeder
 * remain consistent relative to "now".
 *
 * @author Tymofii Synianskyi
 */
final class WebinarsSeeder {

	public const string DEMO_META_KEY = '_vl_demo_seed';

	/** @var list<array{slug:string,title:string,offset_hours:float,duration_minutes:int,status:string,price:float,categories:list<string>}> */
	private const array WEBINARS = [
		[
			'slug'             => 'gp-webinar-antibiotics-2026',
			'title'            => 'Що нового в антибіотикотерапії 2026',
			'offset_hours'     => 0.0,
			'duration_minutes' => 90,
			'status'           => 'live',
			'price'            => 0.0,
			'categories'       => [ 'Внутрішні хвороби' ],
		],
		[
			'slug'             => 'gp-webinar-icu-12h',
			'title'            => 'Інтенсивна терапія дрібних тварин: 12 годин',
			'offset_hours'     => 336.0, // 14 days
			'duration_minutes' => 120,
			'status'           => 'scheduled',
			'price'            => 1500.0,
			'categories'       => [ 'Невідкладна допомога' ],
		],
		[
			'slug'             => 'gp-webinar-feline-behaviour',
			'title'            => 'Поведінкові розлади у котів: діагностика і лікування',
			'offset_hours'     => 720.0, // 30 days
			'duration_minutes' => 90,
			'status'           => 'scheduled',
			'price'            => 800.0,
			'categories'       => [ 'Внутрішні хвороби' ],
		],
		[
			'slug'             => 'gp-webinar-anemia-lab',
			'title'            => 'Лабораторна діагностика анемій',
			'offset_hours'     => -720.0, // 30 days ago
			'duration_minutes' => 90,
			'status'           => 'completed',
			'price'            => 500.0,
			'categories'       => [ 'Внутрішні хвороби' ],
		],
		[
			'slug'             => 'gp-webinar-postop-orthopedics',
			'title'            => 'Постоперативний догляд після ортопедичних втручань',
			'offset_hours'     => -1440.0, // 60 days ago
			'duration_minutes' => 120,
			'status'           => 'completed',
			'price'            => 2000.0,
			'categories'       => [ 'Хірургія', 'Ортопедія' ],
		],
	];

	public function __construct( private readonly MediaSeeder $media ) {
	}

	/**
	 * @param array<string,int> $instructor_ids
	 *
	 * @return array{summary: SeederResult, webinars: list<int>}
	 */
	public function run( SeederContext $context, array $instructor_ids ): array {
		$summary = new SeederResult();
		$out     = [];

		$instructor_logins = array_keys( $instructor_ids );
		$instructor_count  = count( $instructor_logins );
		$now               = time();

		foreach ( self::WEBINARS as $idx => $spec ) {
			$existing = $this->find_demo_webinar( $spec['slug'] );
			if ( $existing > 0 ) {
				++$summary->skipped;
				$out[] = $existing;
				continue;
			}

			$lead_login   = $instructor_logins[ $idx % max( 1, $instructor_count ) ] ?? '';
			$lead_user_id = '' === $lead_login ? 0 : $instructor_ids[ $lead_login ];

			$start_ts = $now + (int) ( $spec['offset_hours'] * HOUR_IN_SECONDS );
			$end_ts   = $start_ts + ( $spec['duration_minutes'] * MINUTE_IN_SECONDS );

			$insert_args = [
				'post_type'    => 'vl_webinar',
				'post_status'  => 'publish',
				'post_title'   => $spec['title'],
				'post_name'    => $spec['slug'],
				'post_content' => $this->description( $spec ),
				'post_excerpt' => sprintf( 'Вебінар: %s', $spec['title'] ),
				'post_author'  => $lead_user_id,
			];

			$post_id = $context->skip_zoom
				? MeetingSynchronizer::bypass( static fn () => wp_insert_post( $insert_args, true ) )
				: wp_insert_post( $insert_args, true );

			if ( is_wp_error( $post_id ) ) {
				++$summary->failed;
				continue;
			}

			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::DEMO_META_KEY, '1' );
			update_post_meta( $post_id, '_vl_webinar_scheduled_start', gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ) );
			update_post_meta( $post_id, '_vl_webinar_scheduled_end', gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ) );
			update_post_meta( $post_id, '_vl_webinar_status', $spec['status'] );
			update_post_meta( $post_id, '_vl_webinar_price', $spec['price'] );
			update_post_meta( $post_id, '_vl_webinar_currency', 'UAH' );
			update_post_meta( $post_id, '_vl_webinar_max_attendees', 200 );

			if ( $context->skip_zoom ) {
				$this->stamp_fake_zoom_meta( $post_id );
			} else {
				update_post_meta( $post_id, '_vl_webinar_zoom_join_url', 'https://zoom.example.test/j/' . wp_generate_password( 10, false ) );
			}

			if ( 'completed' === $spec['status'] ) {
				update_post_meta( $post_id, '_vl_webinar_recording_url', 'https://recordings.example.test/' . $spec['slug'] );
				update_post_meta( $post_id, '_vl_webinar_recording_access_days', 60 );
			}

			$cover_id = $this->media->ensure_cover( $context, MediaSeeder::COVER_PREFIX_WEBINAR . ( $idx + 1 ) );
			if ( $cover_id > 0 ) {
				update_post_meta( $post_id, '_vl_webinar_cover_image_id', $cover_id );
				set_post_thumbnail( $post_id, $cover_id );
			}

			foreach ( $spec['categories'] as $cat_name ) {
				$slug = sanitize_title( $cat_name );
				$term = get_term_by( 'slug', $slug, 'vl_category' );
				if ( $term instanceof \WP_Term ) {
					wp_set_object_terms( $post_id, [ (int) $term->term_id ], 'vl_category', true );
				}
			}

			$context->log(
				sprintf(
					/* translators: 1: index, 2: total, 3: title. */
					__( 'Webinar %1$d/%2$d: %3$s', 'vl-lms' ),
					$idx + 1,
					count( self::WEBINARS ),
					$spec['title']
				)
			);

			++$summary->created;
			$out[] = $post_id;
		}

		return [
			'summary'  => $summary,
			'webinars' => $out,
		];
	}

	/**
	 * @param array{slug:string,title:string,offset_hours:float,duration_minutes:int,status:string,price:float,categories:list<string>} $spec
	 */
	/**
	 * Phase 7.6 — write deterministic Zoom-meta fakes when `--skip-zoom`
	 * is engaged. Mirrors the meta keys `MeetingSynchronizer` would have
	 * populated; the deterministic suffix lets tests assert exact values.
	 */
	private function stamp_fake_zoom_meta( int $post_id ): void {
		update_post_meta( $post_id, '_vl_webinar_zoom_meeting_id', 'demo-' . $post_id );
		update_post_meta( $post_id, '_vl_webinar_zoom_join_url', 'https://example.test/join/demo-' . $post_id );
		update_post_meta( $post_id, '_vl_webinar_zoom_start_url', 'https://example.test/start/demo-' . $post_id );
		update_post_meta( $post_id, '_vl_webinar_zoom_password', sprintf( 'demo%06d', $post_id ) );
	}

	/**
	 * @param array{slug:string,title:string,offset_hours:float,duration_minutes:int,status:string,price:float,categories:list<string>} $spec
	 */
	private function description( array $spec ): string {
		return sprintf(
			'<!-- wp:paragraph --><p>Вебінар «%1$s» — практичний онлайн-розбір клінічних випадків із подальшим Q&A. Запис буде доступний учасникам після завершення.</p><!-- /wp:paragraph -->',
			esc_html( $spec['title'] )
		);
	}

	private function find_demo_webinar( string $slug ): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'vl_webinar',
				'name'                   => $slug,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
				'meta_query'             => [
					[
						'key'   => self::DEMO_META_KEY,
						'value' => '1',
					],
				],
			]
		);
		$ids   = $query->posts;
		if ( ! is_array( $ids ) || [] === $ids ) {
			return 0;
		}
		return (int) $ids[0];
	}
}
