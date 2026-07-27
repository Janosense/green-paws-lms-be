<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;
use VL\LMS\Tests\Fixtures\InMemoryQuizAttemptRepository;
use WP_User;

final class StudentsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryGroupRepository $groups;
	private InMemoryGroupMemberRepository $members;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryQuizAttemptRepository $quiz_attempts;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $s ): void {
				echo esc_html( $s );
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'update_meta_cache' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$this->groups        = new InMemoryGroupRepository();
		$this->members       = new InMemoryGroupMemberRepository();
		$this->enrollments   = new InMemoryEnrollmentRepository();
		$this->quiz_attempts = new InMemoryQuizAttemptRepository();

		// `AccessEntityType` import is here to keep PHPCS / linting happy
		// for future extensions; not used in this file's assertions.
		\class_exists( AccessEntityType::class );

		$_REQUEST = [];
	}

	protected function tearDown(): void {
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeTable(): TestableStudentsListTable {
		return new TestableStudentsListTable(
			$this->groups,
			$this->members,
			$this->enrollments,
			$this->quiz_attempts
		);
	}

	public function test_get_columns_lists_the_documented_five(): void {
		$table = $this->makeTable();

		self::assertSame(
			[ 'name', 'email', 'groups', 'completed_count', 'quiz_attempts' ],
			array_keys( $table->get_columns() )
		);
	}

	public function test_column_name_renders_last_then_first_linked_to_detail(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_email = 'a@b.c';

		Functions\when( 'get_user_meta' )->alias(
			static function ( $id, string $key ): string {
				return 'last_name' === $key ? 'Kovalenko' : ( 'first_name' === $key ? 'Olena' : '' );
			}
		);

		$out = $this->makeTable()->column_name( $user );

		self::assertStringContainsString( 'Kovalenko Olena', $out );
		self::assertStringContainsString( 'action=view', $out );
		self::assertStringContainsString( 'id=7', $out );
	}

	public function test_prepare_items_with_no_filters_builds_role_sorted_query(): void {
		$table             = $this->makeTable();
		$table->fake_users = [];
		$table->fake_total = 0;

		$table->prepare_items();

		$args = $table->captured_args ?? [];
		self::assertSame( 'student', $args['role'] );
		self::assertSame( 'meta_value', $args['orderby'] );
		self::assertSame( 'last_name', $args['meta_key'] );
		self::assertSame( 'ASC', $args['order'] );
		self::assertSame( 20, $args['number'] );
		self::assertSame( 1, $args['paged'] );
		self::assertArrayNotHasKey( 'search', $args );
		self::assertArrayNotHasKey( 'include', $args );
		self::assertArrayNotHasKey( 'exclude', $args );
	}

	public function test_prepare_items_applies_search_param_with_wildcards(): void {
		$_REQUEST['s']     = 'kovalenko';
		$table             = $this->makeTable();
		$table->fake_users = [];
		$table->fake_total = 0;

		$table->prepare_items();

		$args = $table->captured_args ?? [];
		self::assertSame( '*kovalenko*', $args['search'] );
		self::assertSame( [ 'user_login', 'user_email', 'display_name' ], $args['search_columns'] );
	}

	public function test_prepare_items_with_group_filter_resolves_member_ids_to_include(): void {
		$group_id = $this->groups->insert(
			[
				'name'     => 'Cohort',
				'slug'     => 'cohort',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$this->members->insert(
			[
				'group_id'      => $group_id,
				'user_id'       => 11,
				'role_in_group' => 'member',
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		$this->members->insert(
			[
				'group_id'      => $group_id,
				'user_id'       => 22,
				'role_in_group' => 'member',
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$_REQUEST['group_id'] = (string) $group_id;
		$table                = $this->makeTable();
		$table->fake_users    = [];

		$table->prepare_items();

		$args = $table->captured_args ?? [];
		self::assertArrayHasKey( 'include', $args );
		self::assertEqualsCanonicalizing( [ 11, 22 ], $args['include'] );
	}

	public function test_prepare_items_with_unknown_group_id_short_circuits_to_zero_items(): void {
		$_REQUEST['group_id'] = '999';
		$table                = $this->makeTable();
		$table->fake_users    = [];

		$table->prepare_items();

		self::assertSame( [], $table->items );
		// The empty-include branch short-circuits before `fetch_users` runs.
		self::assertNull( $table->captured_args );
	}

	public function test_prepare_items_with_no_group_filter_excludes_grouped_users(): void {
		$group_id = $this->groups->insert(
			[
				'name'     => 'Cohort',
				'slug'     => 'cohort',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$this->members->insert(
			[
				'group_id'      => $group_id,
				'user_id'       => 11,
				'role_in_group' => 'member',
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$_REQUEST['group_id'] = '-1';
		$table                = $this->makeTable();
		$table->fake_users    = [];

		$table->prepare_items();

		$args = $table->captured_args ?? [];
		self::assertArrayHasKey( 'exclude', $args );
		self::assertSame( [ 11 ], $args['exclude'] );
	}

	/**
	 * @param array{status?: QuizAttemptStatus, passed?: bool|null} $overrides
	 */
	private function seed_attempt(
		int $user_id,
		int $quiz_id,
		QuizAttemptStatus $status = QuizAttemptStatus::SUBMITTED,
		?bool $passed = false
	): void {
		$now = new \DateTimeImmutable( '2026-05-01 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->quiz_attempts->insert(
			new QuizAttempt(
				0,
				$user_id,
				$quiz_id,
				50,
				$status,
				$now,
				QuizAttemptStatus::IN_PROGRESS === $status ? null : $now,
				600,
				null,
				QuizAttemptStatus::IN_PROGRESS === $status ? null : 50,
				100,
				$passed,
				70,
				[],
				$now,
				$now
			)
		);
	}

	private function prepared_table_for_user( WP_User $user ): TestableStudentsListTable {
		$table             = $this->makeTable();
		$table->fake_users = [ $user ];
		$table->fake_total = 1;
		$table->prepare_items();
		return $table;
	}

	private function student( int $id = 7 ): WP_User {
		$user             = new WP_User();
		$user->ID         = $id;
		$user->user_email = 'a@b.c';
		$user->roles      = [ 'student' ];
		return $user;
	}

	public function test_column_quiz_attempts_shows_passed_over_attempted_with_sitting_count(): void {
		// Two failed sittings then a pass on quiz 101, plus an untouched
		// second quiz the student failed once.
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, false );
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, false );
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, true );
		$this->seed_attempt( 7, 102, QuizAttemptStatus::SUBMITTED, false );

		$user  = $this->student();
		$table = $this->prepared_table_for_user( $user );

		$html = $table->column_quiz_attempts( $user );

		// 1 of 2 distinct quizzes cleared, across 4 sittings.
		self::assertStringContainsString( '<strong>1 / 2</strong>', $html );
		self::assertStringContainsString( 'Спроб: 4', $html );
	}

	public function test_column_quiz_attempts_counts_a_repeatedly_passed_quiz_once(): void {
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, true );
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, true );

		$user  = $this->student();
		$table = $this->prepared_table_for_user( $user );

		self::assertStringContainsString( '<strong>1 / 1</strong>', $table->column_quiz_attempts( $user ) );
	}

	public function test_column_quiz_attempts_counts_an_open_sitting(): void {
		$this->seed_attempt( 7, 101, QuizAttemptStatus::IN_PROGRESS, null );

		$user  = $this->student();
		$table = $this->prepared_table_for_user( $user );

		$html = $table->column_quiz_attempts( $user );

		self::assertStringContainsString( '<strong>0 / 1</strong>', $html );
		self::assertStringContainsString( 'Спроб: 1', $html );
	}

	public function test_column_quiz_attempts_renders_dash_when_never_attempted(): void {
		$user  = $this->student();
		$table = $this->prepared_table_for_user( $user );

		self::assertSame( '<em>—</em>', $table->column_quiz_attempts( $user ) );
	}

	public function test_column_quiz_attempts_is_scoped_per_student(): void {
		$this->seed_attempt( 7, 101, QuizAttemptStatus::SUBMITTED, true );
		$this->seed_attempt( 8, 101, QuizAttemptStatus::SUBMITTED, true );
		$this->seed_attempt( 8, 102, QuizAttemptStatus::SUBMITTED, true );

		$user  = $this->student( 7 );
		$table = $this->prepared_table_for_user( $user );

		self::assertStringContainsString( '<strong>1 / 1</strong>', $table->column_quiz_attempts( $user ) );
	}

	public function test_column_completed_count_reads_pre_batched_map(): void {
		// One student with two completed enrollments.
		$this->enrollments->seed(
			[
				'user_id'   => 7,
				'course_id' => 100,
				'status'    => 'completed',
			]
		);
		$this->enrollments->seed(
			[
				'user_id'   => 7,
				'course_id' => 101,
				'status'    => 'completed',
			]
		);
		// One ACTIVE row should not be counted.
		$this->enrollments->seed(
			[
				'user_id'   => 7,
				'course_id' => 102,
				'status'    => 'active',
			]
		);

		$user              = new WP_User();
		$user->ID          = 7;
		$user->user_email  = 'a@b.c';
		$user->roles       = [ 'student' ];
		$table             = $this->makeTable();
		$table->fake_users = [ $user ];
		$table->fake_total = 1;

		$table->prepare_items();

		self::assertStringContainsString( '>2<', $table->column_completed_count( $user ) );
	}

	public function test_column_groups_returns_without_group_for_unmembered_user(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_email = 'a@b.c';

		$table             = $this->makeTable();
		$table->fake_users = [ $user ];
		$table->fake_total = 1;

		$table->prepare_items();

		self::assertStringContainsString( '(без групи)', $table->column_groups( $user ) );
	}

	public function test_column_email_renders_mailto_link(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_email = 'student@example.com';

		$out = $this->makeTable()->column_email( $user );

		self::assertStringContainsString( 'mailto:student@example.com', $out );
	}

	public function test_extra_tablenav_renders_all_groups_and_no_group_options(): void {
		$active_id = $this->groups->insert(
			[
				'name'     => 'Cohort A',
				'slug'     => 'cohort-a',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$archived  = $this->groups->insert(
			[
				'name'     => 'Cohort B',
				'slug'     => 'cohort-b',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ARCHIVED->value,
			]
		);

		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		$this->makeTable()->extra_tablenav( 'top' );
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'name="group_id"', $out );
		self::assertStringContainsString( 'value="0"', $out );
		self::assertStringContainsString( 'value="-1"', $out );
		self::assertStringContainsString( 'value="' . $active_id . '"', $out );
		self::assertStringContainsString( 'Cohort A', $out );
		self::assertStringNotContainsString( 'Cohort B', $out );
		self::assertStringNotContainsString( 'value="' . $archived . '"', $out );
	}
}
