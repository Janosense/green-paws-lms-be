<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\User\InstructorProfileMetaRegistrar;

/**
 * Creates the demo users — 3 instructors + 4 students.
 *
 * Instructors get a public bio (`vl_instructor_bio`) and an avatar
 * (`vl_instructor_avatar_id` — sourced via {@see MediaSeeder}). Students
 * carry no extra meta.
 *
 * Each user is tagged with `vl_demo_seed = '1'` user meta so the reset
 * subcommand can find and remove it without touching real users.
 *
 * @author Tymofii Synianskyi
 */
final class UsersSeeder {

	public const string DEMO_META_KEY = 'vl_demo_seed';

	/** @var list<array{login:string, email:string, first:string, last:string, display:string, bio:string}> */
	private const array INSTRUCTORS = [
		[
			'login'   => 'instructor.melnychenko',
			'email'   => 'instructor.melnychenko@example.test',
			'first'   => 'Олена',
			'last'    => 'Мельниченко',
			'display' => 'Олена Мельниченко',
			'bio'     => '<p>Олена Мельниченко — кардіологиня-терапевт із 12-річним клінічним досвідом, працює у мережі ветеринарних клінік Києва. Член Європейського товариства ветеринарної кардіології (ESVC). Спеціалізується на діагностиці й веденні дилатаційної кардіоміопатії собак, гіпертрофічної кардіоміопатії котів та невідкладних кардіологічних станів. Регулярно проводить навчальні семінари для практикуючих лікарів та публікує клінічні розбори в українських фахових виданнях.</p>',
		],
		[
			'login'   => 'instructor.lytvynenko',
			'email'   => 'instructor.lytvynenko@example.test',
			'first'   => 'Андрій',
			'last'    => 'Литвиненко',
			'display' => 'Андрій Литвиненко',
			'bio'     => '<p>Андрій Литвиненко — ветеринарний хірург-ортопед, 15 років практики, керує хірургічним відділенням приватної клініки у Львові. Випускник AO Vet, основний клінічний інтерес — TPLO та сучасні методи остеосинтезу при складних переломах. Постійний доповідач конференцій SCVS, співавтор кількох клінічних протоколів з реабілітації після ортопедичних втручань.</p>',
		],
		[
			'login'   => 'instructor.shevchenko',
			'email'   => 'instructor.shevchenko@example.test',
			'first'   => 'Тетяна',
			'last'    => 'Шевченко',
			'display' => 'Тетяна Шевченко',
			'bio'     => '<p>Тетяна Шевченко — ветеринарна анестезіологиня з 9-річним досвідом, працює у Дніпрі. Член ESAVS, спеціалізується на анестезіологічному супроводі геріатричних і поліморбідних пацієнтів. Активно впроваджує збалансовану регіональну анестезію в щоденну практику та проводить майстер-класи з безпеки пацієнта в анестезії.</p>',
		],
	];

	/** @var list<array{login:string, email:string, first:string, last:string, display:string}> */
	private const array STUDENTS = [
		[
			'login'   => 'student.bohdan',
			'email'   => 'student.1@example.test',
			'first'   => 'Богдан',
			'last'    => 'Кравченко',
			'display' => 'Богдан Кравченко',
		],
		[
			'login'   => 'student.sofia',
			'email'   => 'student.2@example.test',
			'first'   => 'Софія',
			'last'    => 'Мороз',
			'display' => 'Софія Мороз',
		],
		[
			'login'   => 'student.dmytro',
			'email'   => 'student.3@example.test',
			'first'   => 'Дмитро',
			'last'    => 'Бойко',
			'display' => 'Дмитро Бойко',
		],
		[
			'login'   => 'student.iryna',
			'email'   => 'student.4@example.test',
			'first'   => 'Ірина',
			'last'    => 'Ткаченко',
			'display' => 'Ірина Ткаченко',
		],
	];

	public function __construct( private readonly MediaSeeder $media ) {
	}

	/**
	 * Returns user IDs keyed by login.
	 *
	 * @return array{instructors: array<string,int>, students: array<string,int>, summary: SeederResult}
	 */
	public function run( SeederContext $context ): array {
		$summary     = new SeederResult();
		$instructors = [];
		$students    = [];

		foreach ( self::INSTRUCTORS as $idx => $profile ) {
			$user_id = $this->ensure_user( $context, $profile, 'instructor', $summary );
			if ( $user_id > 0 ) {
				$avatar_id = $this->media->ensure_avatar( $context, MediaSeeder::AVATAR_PREFIX . ( $idx + 1 ) );
				update_user_meta( $user_id, InstructorProfileMetaRegistrar::AVATAR_META_KEY, $avatar_id );
				update_user_meta( $user_id, InstructorProfileMetaRegistrar::BIO_META_KEY, wp_kses_post( $profile['bio'] ) );
				$instructors[ $profile['login'] ] = $user_id;
			}
		}

		foreach ( self::STUDENTS as $profile ) {
			$user_id = $this->ensure_user( $context, $profile, 'student', $summary );
			if ( $user_id > 0 ) {
				$students[ $profile['login'] ] = $user_id;
			}
		}

		$context->log(
			sprintf(
				/* translators: 1: created count, 2: skipped count. */
				__( 'Users: %1$d created, %2$d skipped.', 'vl-lms' ),
				$summary->created,
				$summary->skipped
			)
		);

		return [
			'instructors' => $instructors,
			'students'    => $students,
			'summary'     => $summary,
		];
	}

	/**
	 * @param array{login:string, email:string, first:string, last:string, display:string, bio?:string} $profile
	 */
	private function ensure_user( SeederContext $context, array $profile, string $role, SeederResult $summary ): int {
		unset( $context );

		$existing = get_user_by( 'login', $profile['login'] );
		if ( $existing instanceof \WP_User ) {
			$marker = get_user_meta( (int) $existing->ID, self::DEMO_META_KEY, true );
			if ( '1' !== (string) $marker ) {
				// Slug collision with a real user — leave the real user
				// alone and report a skip.
				++$summary->skipped;
				return 0;
			}
			++$summary->skipped;
			return (int) $existing->ID;
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $profile['login'],
				'user_email'   => $profile['email'],
				'user_pass'    => wp_generate_password( 20 ),
				'first_name'   => $profile['first'],
				'last_name'    => $profile['last'],
				'display_name' => $profile['display'],
				'role'         => $role,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			++$summary->failed;
			$summary->messages[] = sprintf(
				/* translators: 1: login, 2: error message. */
				__( 'Failed to insert user "%1$s": %2$s', 'vl-lms' ),
				$profile['login'],
				$user_id->get_error_message()
			);
			return 0;
		}

		update_user_meta( (int) $user_id, self::DEMO_META_KEY, '1' );
		// Demo users bypass the email-verification gate so login works immediately.
		update_user_meta( (int) $user_id, '_vl_email_verified', '1' );
		++$summary->created;

		return (int) $user_id;
	}
}
