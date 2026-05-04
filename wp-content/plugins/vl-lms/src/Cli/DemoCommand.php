<?php

declare(strict_types=1);

namespace VL\LMS\Cli;

use VL\LMS\Cli\Content\LessonContentBuilder;
use VL\LMS\Cli\Seeders\CourseInstructorsSeeder;
use VL\LMS\Cli\Seeders\CoursesSeeder;
use VL\LMS\Cli\Seeders\EnrollmentsSeeder;
use VL\LMS\Cli\Seeders\MediaSeeder;
use VL\LMS\Cli\Seeders\ProgressSeeder;
use VL\LMS\Cli\Seeders\TaxonomiesSeeder;
use VL\LMS\Cli\Seeders\UsersSeeder;
use VL\LMS\Cli\Seeders\WebinarsSeeder;
use VL\LMS\Container;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Repositories\LessonViewRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Progress\ProgressService;
use WP_CLI;

/**
 * `wp vl-lms demo …` — top-level orchestrator for the demo data subsystem.
 *
 * Exposes three subcommands:
 *
 * - `seed`   — populates the database with the catalog/dashboard fixtures.
 * - `reset`  — removes every artifact tagged with the demo marker.
 * - `status` — prints a one-shot count summary.
 *
 * The orchestrator owns ordering and threads IDs forward between
 * sub-seeders. Sub-seeders never call each other directly.
 *
 * @author Tymofii Synianskyi
 */
final class DemoCommand {

	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Seed demo content.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Bypass the production-environment guard.
	 *
	 * [--skip-progress]
	 * : Skip the progress / lesson-views write loop.
	 *
	 * [--skip-zoom=<bool>]
	 * : Suppress real Zoom sync calls and stamp deterministic fake meeting
	 * meta on every seeded session/webinar. Default: true on non-production
	 * environments, false otherwise.
	 *
	 * @param list<string>          $args       Positional args (unused).
	 * @param array<string, mixed>  $assoc_args Associative args.
	 */
	public function seed( array $args, array $assoc_args ): void {
		unset( $args );

		$force         = (bool) ( $assoc_args['force'] ?? false );
		$skip_progress = (bool) ( $assoc_args['skip-progress'] ?? false );
		$skip_zoom     = $this->resolve_skip_zoom( $assoc_args );

		$this->guard_environment( $force );

		$context = new SeederContext(
			environment_type: $this->environment_type(),
			force: $force,
			skip_progress: $skip_progress,
			seed: 42,
			logger: static function ( string $message ): void {
				WP_CLI::log( $message );
			},
			skip_zoom: $skip_zoom
		);

		if ( $skip_zoom ) {
			WP_CLI::log( __( 'Demo seed: Zoom sync bypassed; deterministic fake meeting meta will be written.', 'vl-lms' ) );
		}

		WP_CLI::log( __( 'Starting vl-lms demo seed.', 'vl-lms' ) );

		// 1. Taxonomies.
		$taxonomies = new TaxonomiesSeeder();
		$taxonomies->run( $context );

		// 2. Media — inline image first so LessonContentBuilder can reference it.
		$media           = new MediaSeeder( VL_LMS_DIR . 'assets/demo/' );
		$inline_image_id = $media->ensure_inline( $context );

		// 3. Users.
		$users        = new UsersSeeder( $media );
		$users_result = $users->run( $context );
		/** @var array<string,int> $instructor_ids */
		$instructor_ids = $users_result['instructors'];
		/** @var array<string,int> $student_ids */
		$student_ids = $users_result['students'];

		// 4. Courses (with modules / lessons / topics / quizzes / questions / sessions).
		$content_builder = new LessonContentBuilder( $inline_image_id );
		$courses_seeder  = new CoursesSeeder( $media, $taxonomies, $content_builder );
		$courses_run     = $courses_seeder->run( $context, $instructor_ids );
		$course_records  = $courses_run['courses'];

		// 5. Webinars.
		$webinars        = new WebinarsSeeder( $media );
		$webinars_result = $webinars->run( $context, $instructor_ids );

		// 6. Co-instructors.
		$ci_service = $this->resolve_course_instructor_service();
		$ci_repo    = $this->resolve( CourseInstructorRepository::class, CourseInstructorRepository::class );
		( new CourseInstructorsSeeder( $ci_service, $ci_repo ) )->run( $context, $course_records, $instructor_ids );

		// 7. Enrollments.
		$enrollment_service = $this->resolve( EnrollmentService::class, null );
		$course_index_to_id = [];
		foreach ( $course_records as $idx => $record ) {
			$course_index_to_id[ $idx ] = $record['id'];
		}
		$enrollments_seeder = new EnrollmentsSeeder( $enrollment_service );
		$enrollments_run    = $enrollments_seeder->run( $context, $student_ids, $course_index_to_id, $course_records );

		// 8. Progress (optional).
		if ( ! $skip_progress ) {
			$progress_service = $this->resolve( ProgressService::class, null );
			$progress_repo    = $this->resolve( ProgressRepository::class, ProgressRepository::class );
			$views_repo       = $this->resolve( LessonViewRepository::class, LessonViewRepository::class );
			$progress_seeder  = new ProgressSeeder( $progress_service, $progress_repo, $views_repo );
			$progress_seeder->run( $context, $enrollments_run['enrollments'] );
		} else {
			WP_CLI::log( __( 'Skipping ProgressSeeder per --skip-progress.', 'vl-lms' ) );
		}

		// Final summary.
		WP_CLI::success(
			sprintf(
				/* translators: 1: course count, 2: webinar count. */
				__( 'Demo seed complete: %1$d courses + %2$d webinars populated.', 'vl-lms' ),
				count( $course_records ),
				count( $webinars_result['webinars'] )
			)
		);
	}

	/**
	 * Remove all demo data tagged with the seed marker.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Bypass the production-environment guard.
	 *
	 * @param list<string>          $args       Positional args (unused).
	 * @param array<string, mixed>  $assoc_args Associative args.
	 */
	public function reset( array $args, array $assoc_args ): void {
		unset( $args );

		$force = (bool) ( $assoc_args['force'] ?? false );
		$this->guard_environment( $force );

		$counts = $this->status_counts();
		WP_CLI::confirm(
			sprintf(
				/* translators: 1..n: counts. */
				__(
					'About to remove demo data: %1$d courses, %2$d modules, %3$d lessons, %4$d topics, %5$d quizzes, %6$d quiz questions, %7$d sessions, %8$d webinars, %9$d users, %10$d terms, %11$d attachments. Continue?',
					'vl-lms'
				),
				$counts['vl_course'],
				$counts['vl_module'],
				$counts['vl_lesson'],
				$counts['vl_topic'],
				$counts['vl_quiz'],
				$counts['vl_quiz_question'],
				$counts['vl_session'],
				$counts['vl_webinar'],
				$counts['users'],
				$counts['terms'],
				$counts['attachments']
			)
		);

		global $wpdb;

		$user_ids       = $this->demo_user_ids();
		$post_ids       = [
			'vl_quiz_question' => $this->demo_post_ids( 'vl_quiz_question' ),
			'vl_quiz'          => $this->demo_post_ids( 'vl_quiz' ),
			'vl_topic'         => $this->demo_post_ids( 'vl_topic' ),
			'vl_lesson'        => $this->demo_post_ids( 'vl_lesson' ),
			'vl_module'        => $this->demo_post_ids( 'vl_module' ),
			'vl_session'       => $this->demo_post_ids( 'vl_session' ),
			'vl_course'        => $this->demo_post_ids( 'vl_course' ),
			'vl_webinar'       => $this->demo_post_ids( 'vl_webinar' ),
		];
		$attachment_ids = $this->demo_post_ids( 'attachment' );

		$progress_repo = $this->resolve( ProgressRepository::class, ProgressRepository::class );
		$views_repo    = $this->resolve( LessonViewRepository::class, LessonViewRepository::class );
		$instr_repo    = $this->resolve( CourseInstructorRepository::class, CourseInstructorRepository::class );

		$views_deleted    = 0;
		$progress_deleted = 0;
		foreach ( $user_ids as $uid ) {
			$views_deleted    += $views_repo->delete_for_user( $uid );
			$progress_deleted += $progress_repo->delete_for_user( $uid );
		}

		$enrollments_deleted = 0;
		if ( [] !== $user_ids ) {
			$ids_csv = implode( ',', array_map( 'intval', $user_ids ) );
			$table   = \VL\LMS\Database\SchemaManager::enrollments_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$enrollments_deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE user_id IN ({$ids_csv})" );
		}

		$ci_deleted = 0;
		foreach ( $post_ids['vl_course'] as $course_id ) {
			$ci_deleted += $instr_repo->delete_all_for_entity( InstructorEntityType::COURSE, $course_id );
		}
		foreach ( $post_ids['vl_webinar'] as $webinar_id ) {
			$ci_deleted += $instr_repo->delete_all_for_entity( InstructorEntityType::WEBINAR, $webinar_id );
		}

		$post_summary = [];
		foreach ( $post_ids as $type => $ids ) {
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
			$post_summary[ $type ] = count( $ids );
		}

		$users_deleted  = 0;
		$fallback_admin = $this->resolve_admin_id();
		foreach ( $user_ids as $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( wp_delete_user( $uid, $fallback_admin ) ) {
				++$users_deleted;
			}
		}

		$terms_deleted = $this->delete_demo_terms();

		$attachments_deleted = 0;
		foreach ( $attachment_ids as $att_id ) {
			if ( wp_delete_attachment( $att_id, true ) ) {
				++$attachments_deleted;
			}
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1..n: deletion counts. */
				__(
					'Removed %1$d enrollments, %2$d progress rows, %3$d lesson_views rows, %4$d course_instructors rows, %5$d courses, %6$d webinars, %7$d modules, %8$d lessons, %9$d topics, %10$d quizzes, %11$d quiz_questions, %12$d sessions, %13$d users, %14$d terms, %15$d attachments.',
					'vl-lms'
				),
				$enrollments_deleted,
				$progress_deleted,
				$views_deleted,
				$ci_deleted,
				$post_summary['vl_course'],
				$post_summary['vl_webinar'],
				$post_summary['vl_module'],
				$post_summary['vl_lesson'],
				$post_summary['vl_topic'],
				$post_summary['vl_quiz'],
				$post_summary['vl_quiz_question'],
				$post_summary['vl_session'],
				$users_deleted,
				$terms_deleted,
				$attachments_deleted
			)
		);
	}

	/**
	 * Print a count summary of every demo artifact in the database.
	 *
	 * @param list<string>          $args       Positional args (unused).
	 * @param array<string, mixed>  $assoc_args Associative args (unused).
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$counts = $this->status_counts();

		$rows = [];
		foreach ( $counts as $key => $value ) {
			$rows[] = [
				'artifact' => $key,
				'count'    => $value,
			];
		}

		// @phpstan-ignore-next-line — function exists at runtime via the WP-CLI bundle.
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'artifact', 'count' ] );
		WP_CLI::success( __( 'Demo status report complete.', 'vl-lms' ) );
	}

	/**
	 * @return array<string, int>
	 */
	private function status_counts(): array {
		$post_types = [ 'vl_course', 'vl_module', 'vl_lesson', 'vl_topic', 'vl_quiz', 'vl_quiz_question', 'vl_session', 'vl_webinar' ];
		$counts     = [];
		foreach ( $post_types as $type ) {
			$counts[ $type ] = count( $this->demo_post_ids( $type ) );
		}
		$counts['attachments'] = count( $this->demo_post_ids( 'attachment' ) );
		$counts['users']       = count( $this->demo_user_ids() );
		$counts['terms']       = count( $this->demo_term_ids() );
		return $counts;
	}

	/**
	 * @return list<int>
	 */
	private function demo_post_ids( string $post_type ): array {
		$status = 'attachment' === $post_type ? 'inherit' : 'any';
		$query  = new \WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => $status,
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
				'meta_query'             => [
					[
						'key'   => '_vl_demo_seed',
						'value' => '1',
					],
				],
			]
		);
		$ids    = $query->posts;
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * @return list<int>
	 */
	private function demo_user_ids(): array {
		$users = get_users(
			[
				'meta_key'   => 'vl_demo_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1',            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => -1,
			]
		);
		if ( ! is_array( $users ) ) {
			return [];
		}
		return array_values( array_map( 'intval', $users ) );
	}

	/**
	 * @return list<array{term_id:int, taxonomy:string}>
	 */
	private function demo_term_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id AS term_id, tt.taxonomy AS taxonomy
				 FROM {$wpdb->termmeta} tm
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tm.meta_key = %s AND tm.meta_value = %s",
				'vl_demo_seed',
				'1'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = [
					'term_id'  => (int) $row['term_id'],
					'taxonomy' => (string) $row['taxonomy'],
				];
			}
		}
		return $out;
	}

	private function delete_demo_terms(): int {
		$deleted = 0;
		foreach ( $this->demo_term_ids() as $row ) {
			if ( 'vl_difficulty' === $row['taxonomy'] ) {
				continue;
			}
			$result = wp_delete_term( $row['term_id'], $row['taxonomy'] );
			if ( true === $result ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	private function guard_environment( bool $force ): void {
		$type = $this->environment_type();
		if ( 'production' !== $type ) {
			return;
		}
		if ( ! $force ) {
			WP_CLI::error( __( 'Refusing to run on a production environment without --force.', 'vl-lms' ) );
		}
		WP_CLI::warning( __( 'Running vl-lms demo on a PRODUCTION environment.', 'vl-lms' ) );
		WP_CLI::confirm( __( 'You are about to seed/reset demo data on a PRODUCTION environment. Continue?', 'vl-lms' ) );
	}

	/**
	 * Resolve the `--skip-zoom` flag with a three-way default.
	 *
	 *   - `--skip-zoom=true` / `--skip-zoom=1` → force skip.
	 *   - `--skip-zoom=false` / `--skip-zoom=0` → force engage Zoom sync.
	 *   - omitted → `wp_get_environment_type() !== 'production'`.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	private function resolve_skip_zoom( array $assoc_args ): bool {
		if ( ! array_key_exists( 'skip-zoom', $assoc_args ) ) {
			return 'production' !== $this->environment_type();
		}
		$raw = $assoc_args['skip-zoom'];
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		$normalized = strtolower( trim( (string) $raw ) );
		return in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true );
	}

	private function environment_type(): string {
		$type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
		return '' === $type ? 'production' : $type;
	}

	private function resolve_admin_id(): ?int {
		$admins = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);
		if ( is_array( $admins ) && [] !== $admins ) {
			return (int) $admins[0];
		}
		return null;
	}

	private function resolve_course_instructor_service(): CourseInstructorService {
		if ( $this->container->has( CourseInstructorService::class ) ) {
			$service = $this->container->get( CourseInstructorService::class );
			if ( $service instanceof CourseInstructorService ) {
				return $service;
			}
		}
		return new CourseInstructorService( new CourseInstructorRepository() );
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $id
	 * @param class-string<T>|null $fallback_class Class to instantiate (no args) when the container is missing the binding.
	 *
	 * @return T
	 */
	private function resolve( string $id, ?string $fallback_class ): object {
		if ( $this->container->has( $id ) ) {
			$instance = $this->container->get( $id );
			if ( $instance instanceof $id ) {
				return $instance;
			}
		}
		if ( null === $fallback_class ) {
			WP_CLI::error( sprintf( /* translators: %s: service id */ __( 'Required service "%s" is not registered.', 'vl-lms' ), $id ) );
		}
		return new $fallback_class();
	}
}
