<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\Content\LessonContentBuilder;
use VL\LMS\Cli\Content\VideoPlaylist;
use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;

/**
 * Seeds the 8 demo courses with their full content tree.
 *
 * For each course: 6 self-paced + 2 cohort. Self-paced courses get
 * 3 modules × 4 lessons (12 lessons each); ~20% of lessons get 2 child
 * topics; ~5% are flagged as preview. 50% of self-paced courses get one
 * informational quiz with 5 questions. Cohort courses get 4–6 sessions.
 *
 * Every post is tagged with `_vl_demo_seed = '1'` so reset can find it.
 * Cover images are reused across runs by stable key (see {@see MediaSeeder}).
 *
 * Lessons and topics get realistic Gutenberg block content via
 * {@see LessonContentBuilder} and deterministic video URLs via
 * {@see VideoPlaylist}, so the player runtime sees real input on every
 * lesson the seeder produces.
 *
 * @author Tymofii Synianskyi
 */
final class CoursesSeeder {

	public const string DEMO_META_KEY = '_vl_demo_seed';

	/** @var list<array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool}> */
	private const array COURSES = [
		[
			'slug'       => 'course-aukultaciya-sercya-osnovy',
			'title'      => 'Основи аускультації серця у дрібних тварин',
			'type'       => 'self_paced',
			'difficulty' => 'basic',
			'price'      => 0.0,
			'categories' => [ 'Кардіологія' ],
			'specialty'  => 'Терапевт',
			'theme'      => 'кардіологія дрібних тварин',
			'modules'    => [
				[
					'slug'    => 'mod-anatomy',
					'title'   => 'Анатомія серця і фізіологія тонів',
					'lessons' => [
						'Камери серця та клапанний апарат',
						'Перший і другий тони серця',
						'Розщеплення тонів та галопні ритми',
						'Шуми серця: класифікація',
					],
				],
				[
					'slug'    => 'mod-techniques',
					'title'   => 'Техніка аускультації',
					'lessons' => [
						'Точки аускультації клапанів',
						'Положення пацієнта і фонендоскоп',
						'Аускультація у неспокійних пацієнтів',
						'Документування знахідок',
					],
				],
				[
					'slug'    => 'mod-pathology',
					'title'   => 'Патологічні шуми',
					'lessons' => [
						'Систолічні шуми регургітації',
						'Діастолічні шуми',
						'Безперервні шуми',
						'Коли шум — варіант норми',
					],
				],
			],
			'with_quiz'  => true,
		],
		[
			'slug'       => 'course-emergency-cardiology',
			'title'      => 'Невідкладна кардіологія: від тріажу до стабілізації',
			'type'       => 'self_paced',
			'difficulty' => 'advanced',
			'price'      => 3500.0,
			'categories' => [ 'Кардіологія', 'Невідкладна допомога' ],
			'specialty'  => 'Лікар невідкладної допомоги',
			'theme'      => 'невідкладна кардіологія',
			'modules'    => [
				[
					'slug'    => 'mod-triage',
					'title'   => 'Тріаж і початкова оцінка',
					'lessons' => [
						'Швидка оцінка стабільності',
						'Респіраторний дистрес кардіогенного походження',
						'Ознаки шоку у кардіологічного пацієнта',
						'Лабораторний мінімум на старті',
					],
				],
				[
					'slug'    => 'mod-stabilization',
					'title'   => 'Стабілізація та терапія',
					'lessons' => [
						'Кисень, седація і моніторинг',
						'Діуретики у гострому набряку',
						'Антиаритмічна підтримка',
						'Перикардіоцентез: показання і техніка',
					],
				],
				[
					'slug'    => 'mod-handover',
					'title'   => 'Передача пацієнта',
					'lessons' => [
						'Стабілізаційний чек-лист',
						'Комунікація з власником',
						'Передача в стаціонар',
						'Документація випадку',
					],
				],
			],
			'with_quiz'  => true,
		],
		[
			'slug'       => 'course-echocardiography',
			'title'      => 'Ехокардіографія в практиці ветеринарного кардіолога',
			'type'       => 'self_paced',
			'difficulty' => 'expert',
			'price'      => 8000.0,
			'categories' => [ 'Кардіологія' ],
			'specialty'  => 'Кардіолог',
			'theme'      => 'ехокардіографія',
			'modules'    => [
				[
					'slug'    => 'mod-windows',
					'title'   => 'Стандартні акустичні вікна',
					'lessons' => [
						'Парастернальне праве довге вікно',
						'Парастернальне праве коротке вікно',
						'Апікальне ліве вікно',
						'Підреберне вікно',
					],
				],
				[
					'slug'    => 'mod-measurements',
					'title'   => 'Базові вимірювання та індекси',
					'lessons' => [
						'M-mode: критичні параметри',
						'Допплер: PW, CW, кольоровий',
						'Розміри лівого передсердя',
						'Систолічна функція ЛШ',
					],
				],
				[
					'slug'    => 'mod-pathology',
					'title'   => 'Патологічні знахідки',
					'lessons' => [
						'ДКМП у догів великих порід',
						'ГКМП у котів',
						'Ендокардіоз мітрального клапана',
						'Перикардіальний випіт',
					],
				],
			],
			'with_quiz'  => false,
		],
		[
			'slug'       => 'course-itchy-dog',
			'title'      => 'Дерматологія: підхід до сверблячої собаки',
			'type'       => 'self_paced',
			'difficulty' => 'basic',
			'price'      => 1500.0,
			'categories' => [ 'Дерматологія' ],
			'specialty'  => 'Дерматолог',
			'theme'      => 'дерматологія',
			'modules'    => [
				[
					'slug'    => 'mod-history',
					'title'   => 'Анамнез і збір інформації',
					'lessons' => [
						'Структура дерматологічного анамнезу',
						'Сезонність і паттерн свербежу',
						'Дієтичні чинники',
						'Контактна історія та середовище',
					],
				],
				[
					'slug'    => 'mod-exam',
					'title'   => 'Клінічний огляд і первинні тести',
					'lessons' => [
						'Розподіл уражень шкіри',
						'Цитологія і соскоб',
						'Вологий тест на блохи',
						'Трихоскопія',
					],
				],
				[
					'slug'    => 'mod-plan',
					'title'   => 'Базовий план ведення',
					'lessons' => [
						'Контроль ектопаразитів',
						'Підхід до харчового виключення',
						'Топічна терапія',
						'Коли потрібна біопсія',
					],
				],
			],
			'with_quiz'  => true,
		],
		[
			'slug'       => 'course-anesthesia-orthopedic',
			'title'      => 'Анестезіологія в ортопедичній хірургії',
			'type'       => 'self_paced',
			'difficulty' => 'advanced',
			'price'      => 5000.0,
			'categories' => [ 'Анестезіологія' ],
			'specialty'  => 'Анестезіолог',
			'theme'      => 'анестезіологія в ортопедії',
			'modules'    => [
				[
					'slug'    => 'mod-pre',
					'title'   => 'Передопераційна оцінка',
					'lessons' => [
						'ASA класифікація у щоденній практиці',
						'Аналіз ризиків ортопедичного пацієнта',
						'Оптимізація перед операцією',
						'Інформована згода власника',
					],
				],
				[
					'slug'    => 'mod-techniques',
					'title'   => 'Анестезіологічні техніки',
					'lessons' => [
						'Збалансована загальна анестезія',
						'Реґіонарна анестезія: блокади на тазовій кінцівці',
						'Епідуральна анальгезія',
						'Інтраопераційний моніторинг',
					],
				],
				[
					'slug'    => 'mod-post',
					'title'   => 'Післяопераційне ведення',
					'lessons' => [
						'Мультимодальна анальгезія',
						'Профілактика післяопераційного делірію',
						'Ранній рестарт ентерального харчування',
						'Алгоритм ескалації знеболення',
					],
				],
			],
			'with_quiz'  => false,
		],
		[
			'slug'       => 'course-endodontics',
			'title'      => 'Стоматологія дрібних тварин: ендодонтія',
			'type'       => 'self_paced',
			'difficulty' => 'advanced',
			'price'      => 6000.0,
			'categories' => [ 'Стоматологія' ],
			'specialty'  => 'Стоматолог',
			'theme'      => 'ветеринарна ендодонтія',
			'modules'    => [
				[
					'slug'    => 'mod-anatomy',
					'title'   => 'Анатомія та діагностика',
					'lessons' => [
						'Анатомія коренів і пульпи',
						'Інтраоральна рентгенографія',
						'Ознаки незворотної патології пульпи',
						'Документування знахідок',
					],
				],
				[
					'slug'    => 'mod-techniques',
					'title'   => 'Техніки лікування',
					'lessons' => [
						'Прямі покриття пульпи',
						'Часткова пульпотомія',
						'Стандартна ендодонтична обробка',
						'Ретроградне пломбування',
					],
				],
				[
					'slug'    => 'mod-followup',
					'title'   => 'Спостереження і ускладнення',
					'lessons' => [
						'Контрольні рентген-знімки',
						'Періапікальні ускладнення',
						'Перелом інструмента в каналі',
						'План повторних візитів',
					],
				],
			],
			'with_quiz'  => true,
		],
		[
			'slug'       => 'course-cohort-abdomen-trauma',
			'title'      => 'Хірургічний інтенсив: травма черевної порожнини',
			'type'       => 'cohort',
			'difficulty' => 'advanced',
			'price'      => 7500.0,
			'categories' => [ 'Хірургія', 'Невідкладна хірургія' ],
			'specialty'  => 'Хірург',
			'theme'      => 'абдомінальна хірургічна травма',
			'modules'    => [],
			'sessions'   => [
				[
					'title'            => 'Сесія 1: Тріаж і первинна стабілізація',
					'offset_days'      => 7,
					'duration_minutes' => 90,
					'status'           => 'scheduled',
				],
				[
					'title'            => 'Сесія 2: Діагностичний апарат у тяжкого пацієнта',
					'offset_days'      => 10,
					'duration_minutes' => 90,
					'status'           => 'scheduled',
				],
				[
					'title'            => 'Сесія 3: Лапаротомія по життєвим показанням',
					'offset_days'      => 14,
					'duration_minutes' => 120,
					'status'           => 'scheduled',
				],
				[
					'title'            => 'Сесія 4: Контроль кровотечі та ушкоджень печінки',
					'offset_days'      => 17,
					'duration_minutes' => 120,
					'status'           => 'scheduled',
				],
				[
					'title'            => 'Сесія 5: Післяопераційна реанімація',
					'offset_days'      => 21,
					'duration_minutes' => 90,
					'status'           => 'scheduled',
				],
			],
			'with_quiz'  => false,
		],
		[
			'slug'       => 'course-cohort-tplo',
			'title'      => 'Майстер-клас з ортопедії: TPLO',
			'type'       => 'cohort',
			'difficulty' => 'expert',
			'price'      => 15000.0,
			'categories' => [ 'Хірургія', 'Ортопедія' ],
			'specialty'  => 'Хірург',
			'theme'      => 'ортопедична хірургія TPLO',
			'modules'    => [],
			'sessions'   => [
				[
					'title'            => 'Сесія 1: Біомеханіка коліна та діагностика',
					'offset_days'      => -14,
					'duration_minutes' => 120,
					'status'           => 'completed',
				],
				[
					'title'            => 'Сесія 2: Передопераційне планування TPLO',
					'offset_days'      => -7,
					'duration_minutes' => 120,
					'status'           => 'completed',
				],
				[
					'title'            => 'Сесія 3: Інтраопераційний майстер-клас',
					'offset_days'      => 0,
					'duration_minutes' => 180,
					'status'           => 'live',
				],
				[
					'title'            => 'Сесія 4: Розбір помилок і ускладнень',
					'offset_days'      => 7,
					'duration_minutes' => 120,
					'status'           => 'scheduled',
				],
				[
					'title'            => 'Сесія 5: Реабілітація після TPLO',
					'offset_days'      => 14,
					'duration_minutes' => 90,
					'status'           => 'scheduled',
				],
			],
			'with_quiz'  => false,
		],
	];

	public function __construct(
		private readonly MediaSeeder $media,
		private readonly TaxonomiesSeeder $taxonomies,
		private readonly LessonContentBuilder $content_builder
	) {
	}

	/**
	 * @param array<string,int> $instructor_ids Login → user ID
	 *
	 * @return array{summary: SeederResult, courses: list<array{slug:string,id:int,type:string,modules: list<array{id:int,lessons:list<array{id:int,topics:list<int>}>}>, sessions: list<int>, quiz: ?array{id:int,questions:list<int>}}>}
	 */
	public function run( SeederContext $context, array $instructor_ids ): array {
		$summary = new SeederResult();
		$courses = [];

		$instructor_logins = array_keys( $instructor_ids );
		$instructor_count  = count( $instructor_logins );
		$global_lesson_idx = 0;

		foreach ( self::COURSES as $course_index => $spec ) {
			$lead_login   = $instructor_logins[ $course_index % max( 1, $instructor_count ) ] ?? '';
			$lead_user_id = '' === $lead_login ? 0 : $instructor_ids[ $lead_login ];

			$course_post_id = $this->ensure_course( $context, $course_index, $spec, $lead_user_id, $summary );
			if ( 0 === $course_post_id ) {
				continue;
			}

			$modules_out  = [];
			$sessions_out = [];
			$quiz_out     = null;
			$course_type  = $spec['type'];

			if ( 'self_paced' === $course_type ) {
				$preview_lesson_index = $course_index % 4;
				$module_index         = 0;
				foreach ( $spec['modules'] as $module_spec ) {
					$module_id = $this->ensure_module( $course_post_id, $course_index, $module_index, $module_spec, $lead_user_id, $summary );
					if ( 0 === $module_id ) {
						++$module_index;
						continue;
					}

					$lesson_records = [];
					$lesson_index   = 0;
					foreach ( $module_spec['lessons'] as $lesson_title ) {
						$is_preview = ( 0 === $module_index && $lesson_index === $preview_lesson_index );
						$lesson_id  = $this->ensure_lesson(
							$module_id,
							$course_index,
							$module_index,
							$lesson_index,
							$lesson_title,
							$spec,
							$is_preview,
							$lead_user_id,
							$global_lesson_idx,
							$summary
						);
						$topic_ids  = [];
						if ( $lesson_id > 0 ) {
							$has_topics = ( 0 === ( $global_lesson_idx % 5 ) );
							if ( $has_topics ) {
								$topic_ids = $this->ensure_topics(
									$lesson_id,
									$course_index,
									$module_index,
									$lesson_index,
									$lesson_title,
									$spec,
									$lead_user_id,
									$global_lesson_idx,
									$summary
								);
							}
						}
						$lesson_records[] = [
							'id'     => $lesson_id,
							'topics' => $topic_ids,
						];
						++$global_lesson_idx;
						++$lesson_index;
					}

					$modules_out[] = [
						'id'      => $module_id,
						'lessons' => $lesson_records,
					];
					++$module_index;
				}

				if ( $spec['with_quiz'] ) {
					$quiz_out = $this->ensure_quiz( $course_post_id, $course_index, $spec, $lead_user_id, $summary );
				}
			} else {
				$sessions_out = $this->ensure_sessions( $course_post_id, $course_index, $spec, $lead_user_id, $summary, $context );
			}

			$courses[] = [
				'slug'     => (string) $spec['slug'],
				'id'       => $course_post_id,
				'type'     => $course_type,
				'modules'  => $modules_out,
				'sessions' => $sessions_out,
				'quiz'     => $quiz_out,
			];

			$context->log(
				sprintf(
					/* translators: 1: index, 2: total, 3: title. */
					__( 'Course %1$d/%2$d: %3$s', 'vl-lms' ),
					$course_index + 1,
					count( self::COURSES ),
					$spec['title']
				)
			);
		}

		return [
			'summary' => $summary,
			'courses' => $courses,
		];
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $spec
	 */
	private function ensure_course( SeederContext $context, int $index, array $spec, int $lead_user_id, SeederResult $summary ): int {
		$existing = $this->find_demo_post_by_slug( $spec['slug'], 'vl_course' );
		if ( $existing > 0 ) {
			++$summary->skipped;
			return $existing;
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'vl_course',
				'post_status'  => 'publish',
				'post_title'   => $spec['title'],
				'post_name'    => $spec['slug'],
				'post_content' => $this->course_description( $spec ),
				'post_excerpt' => $this->course_excerpt( $spec ),
				'post_author'  => $lead_user_id,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			++$summary->failed;
			return 0;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::DEMO_META_KEY, '1' );
		update_post_meta( $post_id, '_vl_course_type', $spec['type'] );
		update_post_meta( $post_id, '_vl_course_price', $spec['price'] );
		update_post_meta( $post_id, '_vl_course_currency', 'UAH' );
		update_post_meta( $post_id, '_vl_course_enrollment_open', true );

		if ( 'cohort' === $spec['type'] && isset( $spec['sessions'] ) && [] !== $spec['sessions'] ) {
			$starts = strtotime( '+1 day' );
			$ends   = strtotime( '+30 days' );
			if ( false !== $starts ) {
				update_post_meta( $post_id, '_vl_course_starts_at', gmdate( 'Y-m-d\TH:i:s\Z', $starts ) );
			}
			if ( false !== $ends ) {
				update_post_meta( $post_id, '_vl_course_ends_at', gmdate( 'Y-m-d\TH:i:s\Z', $ends ) );
			}
			update_post_meta( $post_id, '_vl_course_enrollment_opens_at', gmdate( 'Y-m-d\TH:i:s\Z', time() ) );
		}

		$cover_id = $this->media->ensure_cover( $context, MediaSeeder::COVER_PREFIX_COURSE . ( $index + 1 ) );
		if ( $cover_id > 0 ) {
			update_post_meta( $post_id, '_vl_course_cover_image_id', $cover_id );
			set_post_thumbnail( $post_id, $cover_id );
		}

		$this->assign_taxonomies( $post_id, $spec );

		++$summary->created;
		return $post_id;
	}

	/**
	 * @param array{slug:string,title:string,lessons:list<string>} $spec
	 */
	private function ensure_module( int $course_id, int $course_index, int $module_index, array $spec, int $lead_user_id, SeederResult $summary ): int {
		$slug     = sprintf( 'gp-c%d-%s', $course_index + 1, $spec['slug'] );
		$existing = $this->find_demo_post_by_slug( $slug, 'vl_module' );
		if ( $existing > 0 ) {
			++$summary->skipped;
			return $existing;
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'vl_module',
				'post_status'  => 'publish',
				'post_title'   => $spec['title'],
				'post_name'    => $slug,
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $spec['title'] ) . '</p><!-- /wp:paragraph -->',
				'post_parent'  => $course_id,
				'menu_order'   => $module_index,
				'post_author'  => $lead_user_id,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			++$summary->failed;
			return 0;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::DEMO_META_KEY, '1' );
		++$summary->created;
		return $post_id;
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $course_spec
	 */
	private function ensure_lesson(
		int $module_id,
		int $course_index,
		int $module_index,
		int $lesson_index,
		string $lesson_title,
		array $course_spec,
		bool $is_preview,
		int $lead_user_id,
		int $global_lesson_idx,
		SeederResult $summary
	): int {
		$slug     = sprintf( 'gp-c%d-m%d-l%d-%s', $course_index + 1, $module_index + 1, $lesson_index + 1, sanitize_title( $lesson_title ) );
		$existing = $this->find_demo_post_by_slug( $slug, 'vl_lesson' );
		if ( $existing > 0 ) {
			++$summary->skipped;
			return $existing;
		}

		$content = $this->content_builder->build_lesson(
			$lesson_title,
			$course_spec['theme'],
			$course_spec['difficulty'],
			$global_lesson_idx
		);

		$post_id = wp_insert_post(
			[
				'post_type'    => 'vl_lesson',
				'post_status'  => 'publish',
				'post_title'   => $lesson_title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_parent'  => $module_id,
				'menu_order'   => $lesson_index,
				'post_author'  => $lead_user_id,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			++$summary->failed;
			return 0;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::DEMO_META_KEY, '1' );

		$video = VideoPlaylist::for_index( $global_lesson_idx );
		if ( null !== $video['provider'] && null !== $video['url'] ) {
			update_post_meta( $post_id, '_vl_lesson_video_url', $video['url'] );
			update_post_meta( $post_id, '_vl_lesson_video_provider', $video['provider'] );
			update_post_meta( $post_id, '_vl_lesson_duration_seconds', 600 + ( ( $global_lesson_idx % 11 ) * 60 ) );
		} else {
			update_post_meta( $post_id, '_vl_lesson_duration_seconds', 300 + ( ( $global_lesson_idx % 7 ) * 60 ) );
		}

		if ( $is_preview ) {
			update_post_meta( $post_id, '_vl_lesson_is_preview', true );
		}

		++$summary->created;
		return $post_id;
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $course_spec
	 *
	 * @return list<int>
	 */
	private function ensure_topics(
		int $lesson_id,
		int $course_index,
		int $module_index,
		int $lesson_index,
		string $lesson_title,
		array $course_spec,
		int $lead_user_id,
		int $global_lesson_idx,
		SeederResult $summary
	): array {
		$topics = [
			sprintf( 'Розбір випадку: %s', $lesson_title ),
			sprintf( 'Помилки на практиці: %s', $lesson_title ),
		];

		$out = [];
		foreach ( $topics as $topic_index => $topic_title ) {
			$slug     = sprintf( 'gp-c%d-m%d-l%d-t%d', $course_index + 1, $module_index + 1, $lesson_index + 1, $topic_index + 1 );
			$existing = $this->find_demo_post_by_slug( $slug, 'vl_topic' );
			if ( $existing > 0 ) {
				++$summary->skipped;
				$out[] = $existing;
				continue;
			}

			$content = $this->content_builder->build_topic(
				$topic_title,
				$course_spec['theme'],
				$course_spec['difficulty'],
				$global_lesson_idx + $topic_index
			);

			$post_id = wp_insert_post(
				[
					'post_type'    => 'vl_topic',
					'post_status'  => 'publish',
					'post_title'   => $topic_title,
					'post_name'    => $slug,
					'post_content' => $content,
					'post_parent'  => $lesson_id,
					'menu_order'   => $topic_index,
					'post_author'  => $lead_user_id,
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				++$summary->failed;
				continue;
			}

			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::DEMO_META_KEY, '1' );

			$topic_video_index = $global_lesson_idx + 7 + $topic_index;
			$video             = VideoPlaylist::for_index( $topic_video_index );
			if ( null !== $video['provider'] && null !== $video['url'] ) {
				update_post_meta( $post_id, '_vl_topic_video_url', $video['url'] );
				update_post_meta( $post_id, '_vl_topic_video_provider', $video['provider'] );
			}
			update_post_meta( $post_id, '_vl_topic_duration_seconds', 300 + ( ( $topic_video_index % 6 ) * 60 ) );

			++$summary->created;
			$out[] = $post_id;
		}

		return $out;
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $course_spec
	 *
	 * @return array{id:int, questions:list<int>}|null
	 */
	private function ensure_quiz( int $course_id, int $course_index, array $course_spec, int $lead_user_id, SeederResult $summary ): ?array {
		$quiz_slug = sprintf( 'gp-c%d-quiz', $course_index + 1 );
		$existing  = $this->find_demo_post_by_slug( $quiz_slug, 'vl_quiz' );

		if ( $existing > 0 ) {
			$existing_questions = get_posts(
				[
					'post_type'        => 'vl_quiz_question',
					'post_status'      => 'publish',
					'post_parent'      => $existing,
					'posts_per_page'   => -1,
					'orderby'          => 'menu_order',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'suppress_filters' => false,
				]
			);
			++$summary->skipped;
			return [
				'id'        => $existing,
				'questions' => array_values( array_map( 'intval', $existing_questions ) ),
			];
		}

		$quiz_id = wp_insert_post(
			[
				'post_type'    => 'vl_quiz',
				'post_status'  => 'publish',
				'post_title'   => sprintf( 'Перевірка знань: %s', $course_spec['title'] ),
				'post_name'    => $quiz_slug,
				'post_content' => '<!-- wp:paragraph --><p>Інформаційний тест для самоперевірки.</p><!-- /wp:paragraph -->',
				'post_parent'  => $course_id,
				'menu_order'   => 0,
				'post_author'  => $lead_user_id,
			],
			true
		);

		if ( is_wp_error( $quiz_id ) ) {
			++$summary->failed;
			return null;
		}

		$quiz_id = (int) $quiz_id;
		update_post_meta( $quiz_id, self::DEMO_META_KEY, '1' );
		update_post_meta( $quiz_id, '_vl_quiz_passing_threshold', 70 );
		update_post_meta( $quiz_id, '_vl_quiz_is_final_exam', false );
		++$summary->created;

		$questions    = $this->question_specs( $course_spec );
		$question_ids = [];
		foreach ( $questions as $q_index => $q ) {
			$q_slug      = sprintf( '%s-q%d', $quiz_slug, $q_index + 1 );
			$question_id = wp_insert_post(
				[
					'post_type'    => 'vl_quiz_question',
					'post_status'  => 'publish',
					'post_title'   => $q['title'],
					'post_name'    => $q_slug,
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $q['title'] ) . '</p><!-- /wp:paragraph -->',
					'post_parent'  => $quiz_id,
					'menu_order'   => $q_index,
					'post_author'  => $lead_user_id,
				],
				true
			);
			if ( is_wp_error( $question_id ) ) {
				++$summary->failed;
				continue;
			}
			$question_id = (int) $question_id;
			update_post_meta( $question_id, self::DEMO_META_KEY, '1' );
			update_post_meta( $question_id, '_vl_question_type', $q['type'] );
			update_post_meta( $question_id, '_vl_question_points', 1 );
			update_post_meta( $question_id, '_vl_question_answers', $q['answers'] );
			$question_ids[] = $question_id;
			++$summary->created;
		}

		return [
			'id'        => $quiz_id,
			'questions' => $question_ids,
		];
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $course_spec
	 *
	 * @return list<int>
	 */
	private function ensure_sessions( int $course_id, int $course_index, array $course_spec, int $lead_user_id, SeederResult $summary, SeederContext $context ): array {
		$out   = [];
		$now   = time();
		$specs = $course_spec['sessions'] ?? [];

		foreach ( $specs as $session_index => $session_spec ) {
			$slug     = sprintf( 'gp-c%d-s%d', $course_index + 1, $session_index + 1 );
			$existing = $this->find_demo_post_by_slug( $slug, 'vl_session' );
			if ( $existing > 0 ) {
				++$summary->skipped;
				$out[] = $existing;
				continue;
			}

			$start_ts = $now + (int) ( $session_spec['offset_days'] * DAY_IN_SECONDS );
			$end_ts   = $start_ts + ( $session_spec['duration_minutes'] * MINUTE_IN_SECONDS );

			$insert_args = [
				'post_type'    => 'vl_session',
				'post_status'  => 'publish',
				'post_title'   => $session_spec['title'],
				'post_name'    => $slug,
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $session_spec['title'] ) . '</p><!-- /wp:paragraph -->',
				'post_parent'  => $course_id,
				'menu_order'   => $session_index,
				'post_author'  => $lead_user_id,
			];

			$post_id = $context->skip_zoom
				? \VL\LMS\Services\Zoom\Sync\MeetingSynchronizer::bypass( static fn () => wp_insert_post( $insert_args, true ) )
				: wp_insert_post( $insert_args, true );

			if ( is_wp_error( $post_id ) ) {
				++$summary->failed;
				continue;
			}

			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::DEMO_META_KEY, '1' );
			update_post_meta( $post_id, '_vl_session_number', $session_index + 1 );
			update_post_meta( $post_id, '_vl_session_scheduled_start', gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ) );
			update_post_meta( $post_id, '_vl_session_scheduled_end', gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ) );
			update_post_meta( $post_id, '_vl_session_status', $session_spec['status'] );

			if ( $context->skip_zoom ) {
				update_post_meta( $post_id, '_vl_session_zoom_meeting_id', 'demo-' . $post_id );
				update_post_meta( $post_id, '_vl_session_zoom_join_url', 'https://example.test/join/demo-' . $post_id );
				update_post_meta( $post_id, '_vl_session_zoom_start_url', 'https://example.test/start/demo-' . $post_id );
				update_post_meta( $post_id, '_vl_session_zoom_password', sprintf( 'demo%06d', $post_id ) );
			} else {
				update_post_meta( $post_id, '_vl_session_zoom_join_url', 'https://zoom.example.test/j/' . wp_generate_password( 10, false ) );
			}

			++$summary->created;
			$out[] = $post_id;
		}

		return $out;
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $spec
	 */
	private function course_description( array $spec ): string {
		$intro = sprintf(
			'<!-- wp:paragraph --><p>Курс «%1$s» побудований навколо щоденних викликів у %2$s. Програма поєднує клінічні розбори, відеоматеріали та структуровані алгоритми ведення пацієнта.</p><!-- /wp:paragraph -->',
			esc_html( $spec['title'] ),
			esc_html( $spec['theme'] )
		);
		$body  = '<!-- wp:paragraph --><p>Після проходження курсу учасник матиме чіткий план дій у типових клінічних ситуаціях, розуміння місця додаткових досліджень та готову систему документування рішень.</p><!-- /wp:paragraph -->';
		return $intro . "\n\n" . $body;
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $spec
	 */
	private function course_excerpt( array $spec ): string {
		return sprintf( 'Практичний курс із %s для ветеринарних лікарів.', $spec['theme'] );
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $spec
	 */
	private function assign_taxonomies( int $post_id, array $spec ): void {
		$category_ids = [];
		foreach ( $spec['categories'] as $cat_name ) {
			$slug = sanitize_title( $cat_name );
			$term = get_term_by( 'slug', $slug, 'vl_category' );
			if ( $term instanceof \WP_Term ) {
				$category_ids[] = (int) $term->term_id;
			}
		}
		if ( [] !== $category_ids ) {
			wp_set_object_terms( $post_id, $category_ids, 'vl_category' );
		}

		$specialty_slug = sanitize_title( $spec['specialty'] );
		$specialty_term = get_term_by( 'slug', $specialty_slug, 'vl_specialty' );
		if ( $specialty_term instanceof \WP_Term ) {
			wp_set_object_terms( $post_id, [ (int) $specialty_term->term_id ], 'vl_specialty' );
		}

		$difficulty_id = $this->taxonomies->difficulty_term_id( $spec['difficulty'] );
		if ( $difficulty_id > 0 ) {
			wp_set_object_terms( $post_id, [ $difficulty_id ], 'vl_difficulty' );
		}
	}

	/**
	 * @param array{slug:string,title:string,type:string,difficulty:string,price:float,categories:list<string>,specialty:string,theme:string,modules:list<array{slug:string,title:string,lessons:list<string>}>,sessions?:list<array{title:string,offset_days:float,duration_minutes:int,status:string}>,with_quiz:bool} $course_spec
	 *
	 * @return list<array{title:string, type:string, answers:list<array{id:string,text:string,is_correct:bool,explanation:string}>}>
	 */
	private function question_specs( array $course_spec ): array {
		$theme = $course_spec['theme'];
		return [
			[
				'title'   => sprintf( 'Який підхід вважається золотим стандартом первинного огляду в %s?', $theme ),
				'type'    => 'single_choice',
				'answers' => [
					[
						'id'          => 'a1',
						'text'        => 'Системний клінічний огляд із документуванням знахідок',
						'is_correct'  => true,
						'explanation' => 'Структурований огляд лежить в основі діагностики.',
					],
					[
						'id'          => 'a2',
						'text'        => 'Призначення максимальної панелі лабораторних тестів одразу',
						'is_correct'  => false,
						'explanation' => 'Без огляду тести часто непотрібні або вводять в оману.',
					],
					[
						'id'          => 'a3',
						'text'        => 'Опитування власника без фізикального обстеження',
						'is_correct'  => false,
						'explanation' => 'Потрібне і те, і інше.',
					],
				],
			],
			[
				'title'   => 'Які з тверджень щодо клінічного мислення є коректними? (оберіть усі правильні)',
				'type'    => 'multiple_choice',
				'answers' => [
					[
						'id'          => 'b1',
						'text'        => 'Диференційний ряд варто переоцінювати після кожного нового результату',
						'is_correct'  => true,
						'explanation' => 'Це базовий принцип ітеративної діагностики.',
					],
					[
						'id'          => 'b2',
						'text'        => 'Найімовірніший діагноз не виключає менш імовірних',
						'is_correct'  => true,
						'explanation' => 'Завжди тримаємо план Б.',
					],
					[
						'id'          => 'b3',
						'text'        => 'Тести підтвердження достатньо без клінічної кореляції',
						'is_correct'  => false,
						'explanation' => 'Інтерпретація завжди в клінічному контексті.',
					],
				],
			],
			[
				'title'   => 'Чи коректно стверджувати, що чутливість і специфічність тесту залежать лише від його технології?',
				'type'    => 'true_false',
				'answers' => [
					[
						'id'          => 'c1',
						'text'        => 'Так',
						'is_correct'  => false,
						'explanation' => 'Це залежить також від популяції пацієнтів і клінічного контексту.',
					],
					[
						'id'          => 'c2',
						'text'        => 'Ні',
						'is_correct'  => true,
						'explanation' => 'Контекст і популяція впливають на діагностичні характеристики.',
					],
				],
			],
			[
				'title'   => sprintf( 'Що з переліченого є найважливішим для безпечного ведення в %s?', $theme ),
				'type'    => 'single_choice',
				'answers' => [
					[
						'id'          => 'd1',
						'text'        => 'Чек-листи й документація рішень',
						'is_correct'  => true,
						'explanation' => 'Чек-листи знижують частоту помилок.',
					],
					[
						'id'          => 'd2',
						'text'        => 'Покладатися виключно на досвід',
						'is_correct'  => false,
						'explanation' => 'Досвід не замінює системи.',
					],
					[
						'id'          => 'd3',
						'text'        => 'Уникати комунікації з власником',
						'is_correct'  => false,
						'explanation' => 'Комунікація — частина процесу лікування.',
					],
				],
			],
			[
				'title'   => 'Які з принципів безпечної терапії варто враховувати при поліморбідних пацієнтах? (оберіть усі правильні)',
				'type'    => 'multiple_choice',
				'answers' => [
					[
						'id'          => 'e1',
						'text'        => 'Перевірка лікарських взаємодій',
						'is_correct'  => true,
						'explanation' => 'Це базовий захід безпеки.',
					],
					[
						'id'          => 'e2',
						'text'        => 'Корекція дозувань під функцію нирок та печінки',
						'is_correct'  => true,
						'explanation' => 'Без цього зростає токсичність.',
					],
					[
						'id'          => 'e3',
						'text'        => 'Застосування максимальних доз "для надійності"',
						'is_correct'  => false,
						'explanation' => 'Це підвищує токсичність без виграшу в ефективності.',
					],
				],
			],
		];
	}

	private function find_demo_post_by_slug( string $slug, string $post_type ): int {
		$query = new \WP_Query(
			[
				'post_type'              => $post_type,
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
