<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Students;

use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Repositories\GroupRepository;
use WP_User;
use WP_User_Query;

/**
 * Admin "Студенти" list screen — every WP user with `role=student` plus
 * LMS-relevant join data (active group memberships, completed-course
 * count). Read-only; mutations live in the Groups admin.
 *
 * Joins are pre-batched in `prepare_items()` to keep the rendering loop
 * off `vl_enrollments` / `vl_group_members` per row. `WP_User_Query`
 * construction is indirected through {@see self::fetch_users()} so unit
 * tests can subclass and inject a fake result without booting wpdb.
 *
 * Not declared `final` — tests subclass to swap `fetch_users` and to
 * assert the constructed query args.
 *
 * @author Tymofii Synianskyi
 */
class StudentsListTable extends \WP_List_Table {

	public const string PAGE_SLUG = 'vl-lms-students';

	private const int PER_PAGE = 20;

	/** @var array<int, int> user_id => completed count (pre-batched in `prepare_items`) */
	private array $completed_by_user = [];

	/** @var array<int, list<GroupMember>> user_id => active memberships (pre-batched) */
	private array $memberships_by_user = [];

	/** @var array<int, Group> group_id => Group (lazy-filled by `column_groups`) */
	private array $group_cache = [];

	public function __construct(
		private readonly GroupRepository $groups,
		private readonly GroupMemberRepository $members,
		private readonly EnrollmentRepository $enrollments
	) {
		parent::__construct(
			[
				'singular' => 'student',
				'plural'   => 'students',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'first_name'      => "Ім'я",
			'last_name'       => 'Прізвище',
			'email'           => 'Email',
			'groups'          => 'Групи',
			'completed_count' => 'Завершено курсів',
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$page = (int) ( $this->request_string( 'paged' ) ?? '1' );
		$page = max( 1, $page );

		$search   = $this->request_string( 's' );
		$group_id = $this->request_int( 'group_id' );

		$args = [
			'role'        => 'student',
			'number'      => self::PER_PAGE,
			'paged'       => $page,
			'orderby'     => 'meta_value',
			'meta_key'    => 'last_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- list view is admin-only and paginated.
			'order'       => 'ASC',
			'fields'      => 'all',
			'count_total' => true,
		];

		if ( null !== $search && '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		$args = $this->apply_group_filter( $args, $group_id );

		// Short-circuit: an empty `include` array must yield zero results
		// (WP_User_Query treats `[]` as "no filter" — opposite of intent).
		if ( isset( $args['include'] ) && [] === $args['include'] ) {
			$this->items = [];
			$this->set_pagination_args(
				[
					'total_items' => 0,
					'per_page'    => self::PER_PAGE,
					'total_pages' => 1,
				]
			);
			return;
		}

		$result   = $this->fetch_users( $args );
		$users    = $result['users'];
		$total    = $result['total'];
		$user_ids = [];
		foreach ( $users as $user ) {
			if ( $user instanceof WP_User ) {
				$user_ids[] = (int) $user->ID;
			}
		}

		if ( [] !== $user_ids ) {
			update_meta_cache( 'user', $user_ids );
			$this->completed_by_user   = $this->enrollments->count_completed_for_users( $user_ids );
			$this->memberships_by_user = $this->members->list_active_for_users( $user_ids );
		}

		$this->items = $users;

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			]
		);
	}

	/**
	 * Resolve the `group_id` URL filter to the right `WP_User_Query` arg.
	 *
	 * - `null` / `0` → no filter applied.
	 * - Positive int → `include` member user_ids for that group (empty
	 *   array → caller short-circuits to zero results, intentionally).
	 * - `-1` ("без групи") → `exclude` every user with any active
	 *   membership row, surfacing only ungrouped students.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function apply_group_filter( array $args, ?int $group_id ): array {
		if ( null === $group_id || 0 === $group_id ) {
			return $args;
		}

		if ( -1 === $group_id ) {
			$grouped_ids = [];
			foreach ( $this->groups->list_by_status( GroupStatus::ACTIVE ) as $group ) {
				foreach ( $this->members->list_active_members( $group->id ) as $member ) {
					$grouped_ids[ $member->user_id ] = true;
				}
			}
			if ( [] !== $grouped_ids ) {
				$args['exclude'] = array_keys( $grouped_ids );
			}
			return $args;
		}

		$ids = [];
		foreach ( $this->members->list_active_members( $group_id ) as $member ) {
			$ids[] = (int) $member->user_id;
		}
		$args['include'] = $ids;
		return $args;
	}

	/**
	 * Indirected so unit tests can subclass and inject a fake user set.
	 *
	 * @param array<string, mixed> $args
	 * @return array{users: list<WP_User>, total: int}
	 */
	protected function fetch_users( array $args ): array {
		$query = new WP_User_Query( $args );
		$users = $query->get_results();
		$out   = [];
		foreach ( $users as $user ) {
			if ( $user instanceof WP_User ) {
				$out[] = $user;
			}
		}
		return [
			'users' => $out,
			'total' => (int) $query->get_total(),
		];
	}

	/**
	 * @param WP_User $item
	 * @param string  $column_name
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}

	public function column_first_name( WP_User $user ): string {
		$value = (string) get_user_meta( $user->ID, 'first_name', true );
		if ( '' === $value ) {
			return '<em>—</em>';
		}
		return esc_html( $value );
	}

	public function column_last_name( WP_User $user ): string {
		$value = (string) get_user_meta( $user->ID, 'last_name', true );
		if ( '' === $value ) {
			// Fall back to display_name so the row still has *some* identifier.
			$fallback = (string) $user->display_name;
			return '' === $fallback
				? '<em>—</em>'
				: '<em>' . esc_html( $fallback ) . '</em>';
		}
		return esc_html( $value );
	}

	public function column_email( WP_User $user ): string {
		$email = (string) $user->user_email;
		if ( '' === $email ) {
			return '<em>—</em>';
		}
		return sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $email ) );
	}

	public function column_groups( WP_User $user ): string {
		$memberships = $this->memberships_by_user[ (int) $user->ID ] ?? [];
		if ( [] === $memberships ) {
			return '<em>' . esc_html__( '(без групи)', 'vl-lms' ) . '</em>';
		}

		$parts = [];
		foreach ( $memberships as $member ) {
			$group = $this->resolve_group( $member->group_id );
			if ( null === $group ) {
				continue;
			}
			$url     = add_query_arg(
				[
					'page'   => GroupsListPage::PAGE_SLUG,
					'action' => 'edit',
					'id'     => $group->id,
				],
				admin_url( 'admin.php' )
			);
			$parts[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html( $group->name )
			);
		}

		if ( [] === $parts ) {
			return '<em>' . esc_html__( '(без групи)', 'vl-lms' ) . '</em>';
		}

		return implode( ', ', $parts );
	}

	public function column_completed_count( WP_User $user ): string {
		$count = (int) ( $this->completed_by_user[ (int) $user->ID ] ?? 0 );
		return sprintf( '<span style="text-align:right">%d</span>', $count );
	}

	/**
	 * @param string $which
	 */
	public function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current = $this->request_int( 'group_id' );

		echo '<div class="alignleft actions">';
		echo '<select name="group_id">';
		$this->render_group_option( '0', null === $current || 0 === $current, __( 'Усі групи', 'vl-lms' ) );
		$this->render_group_option( '-1', -1 === $current, __( 'Без групи', 'vl-lms' ) );

		foreach ( $this->groups->list_by_status( GroupStatus::ACTIVE ) as $group ) {
			$this->render_group_option(
				(string) $group->id,
				$current === $group->id,
				$group->name
			);
		}

		echo '</select>';
		submit_button( __( 'Фільтрувати', 'vl-lms' ), '', 'filter_action', false );
		echo '</div>';
	}

	public function no_items(): void {
		esc_html_e( 'Студентів не знайдено.', 'vl-lms' );
	}

	private function render_group_option( string $value, bool $selected, string $label ): void {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			$selected ? ' selected="selected"' : '',
			esc_html( $label )
		);
	}

	private function resolve_group( int $group_id ): ?Group {
		if ( isset( $this->group_cache[ $group_id ] ) ) {
			return $this->group_cache[ $group_id ];
		}
		$group = $this->groups->find_by_id( $group_id );
		if ( null === $group ) {
			return null;
		}
		$this->group_cache[ $group_id ] = $group;
		return $group;
	}

	private function request_string( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs.
		$raw = wp_unslash( $_REQUEST[ $key ] );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$value = sanitize_text_field( $raw );
		return '' === $value ? null : $value;
	}

	private function request_int( string $key ): ?int {
		$raw = $this->request_string( $key );
		if ( null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		return (int) $raw;
	}
}
