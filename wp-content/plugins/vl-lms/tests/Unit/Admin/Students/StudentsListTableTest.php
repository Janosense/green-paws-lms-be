<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;
use WP_User;

final class StudentsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryGroupRepository $groups;
	private InMemoryGroupMemberRepository $members;
	private InMemoryEnrollmentRepository $enrollments;

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

		$this->groups      = new InMemoryGroupRepository();
		$this->members     = new InMemoryGroupMemberRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();

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
		return new TestableStudentsListTable( $this->groups, $this->members, $this->enrollments );
	}

	public function test_get_columns_lists_the_documented_five(): void {
		$table = $this->makeTable();

		self::assertSame(
			[ 'first_name', 'last_name', 'email', 'groups', 'completed_count' ],
			array_keys( $table->get_columns() )
		);
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
