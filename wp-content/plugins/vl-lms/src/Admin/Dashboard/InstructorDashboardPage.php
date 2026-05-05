<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Dashboard;

use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Post;

/**
 * Renders the Phase 9.2 "Панель інструктора" wp-admin page.
 *
 * Lists every course the current user owns (post_author) or co-instructs
 * (row in `{prefix}vl_course_instructors`), with enrollment / completion
 * counts and a "Переглянути" link that points at the frontend
 * `/learn/{lesson}?preview=1` URL.
 *
 * Not declared `final` — unit tests subclass to inject WP-stubs.
 *
 * @author Tymofii Synianskyi
 */
class InstructorDashboardPage {

	private const string FRONTEND_FALLBACK = 'http://localhost:3000';

	public function __construct(
		private readonly CourseInstructorRepository $instructors,
		private readonly CourseStatsQuery $stats
	) {
	}

	public function render(): void {
		$user_id = (int) get_current_user_id();
		$courses = $this->resolve_courses( $user_id );

		echo '<div class="wrap">';
		echo '<h1>Панель інструктора</h1>';

		if ( [] === $courses ) {
			echo '<p>Ви ще не створили жодного курсу.</p>';
			echo '</div>';
			return;
		}

		$course_ids   = array_map( static fn ( WP_Post $p ): int => (int) $p->ID, $courses );
		$enrollments  = $this->stats->enrollment_count_by_course( $course_ids );
		$completions  = $this->stats->completion_count_by_course( $course_ids );
		$total_enrols = array_sum( $enrollments );
		$total_comps  = array_sum( $completions );

		echo '<div class="vl-lms-dashboard-cards" style="display:flex;gap:16px;margin:16px 0;">';
		$this->render_card( 'Курси', count( $courses ) );
		$this->render_card( 'Записи', $total_enrols );
		$this->render_card( 'Завершення', $total_comps );
		echo '</div>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>Курс</th>';
		echo '<th>Записи</th>';
		echo '<th>Завершення</th>';
		echo '<th>Дії</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		foreach ( $courses as $course ) {
			$cid          = (int) $course->ID;
			$edit_link    = (string) get_edit_post_link( $cid );
			$preview_link = $this->preview_url_for_course( $cid );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_link ) . '">' . esc_html( (string) $course->post_title ) . '</a></td>';
			echo '<td>' . esc_html( (string) ( $enrollments[ $cid ] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $completions[ $cid ] ?? 0 ) ) . '</td>';
			echo '<td>';
			if ( '' !== $preview_link ) {
				echo '<a class="button" href="' . esc_url( $preview_link ) . '" target="_blank" rel="noopener">Переглянути</a>';
			} else {
				echo '<span aria-hidden="true">—</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	private function render_card( string $label, int $value ): void {
		echo '<div class="vl-lms-dashboard-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;min-width:160px;">';
		echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;">' . esc_html( (string) $value ) . '</div>';
		echo '</div>';
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function resolve_courses( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$assigned_ids = $this->instructors->get_course_ids_for_user( $user_id );

		$args = [
			'post_type'      => 'vl_course',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( [] === $assigned_ids ) {
			$args['author'] = $user_id;
		} else {
			$args['author__in'] = [ $user_id ];
			// `author OR id IN (assigned)` cannot be expressed with WP_Query
			// in a single call, so we union the two result sets in PHP.
		}

		$owned = $this->query_courses( $args );

		$assigned = [];
		if ( [] !== $assigned_ids ) {
			$assigned = $this->query_courses(
				[
					'post_type'      => 'vl_course',
					'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
					'posts_per_page' => 200,
					'post__in'       => $assigned_ids,
					'orderby'        => 'title',
					'order'          => 'ASC',
				]
			);
		}

		$by_id = [];
		foreach ( $owned as $c ) {
			$by_id[ (int) $c->ID ] = $c;
		}
		foreach ( $assigned as $c ) {
			$by_id[ (int) $c->ID ] = $c;
		}

		$out = array_values( $by_id );
		usort( $out, static fn ( WP_Post $a, WP_Post $b ): int => strcmp( (string) $a->post_title, (string) $b->post_title ) );
		return $out;
	}

	/**
	 * Test seam — bypasses `WP_Query` so unit tests can inject fakes.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return list<WP_Post>
	 */
	protected function query_courses( array $args ): array {
		$posts = get_posts( $args );
		if ( ! is_array( $posts ) ) {
			return [];
		}
		$out = [];
		foreach ( $posts as $p ) {
			if ( $p instanceof WP_Post ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private function preview_url_for_course( int $course_id ): string {
		$module = $this->first_published_child( $course_id, 'vl_module' );
		if ( ! $module instanceof WP_Post ) {
			return '';
		}
		$lesson = $this->first_published_child( (int) $module->ID, 'vl_lesson' );
		if ( ! $lesson instanceof WP_Post ) {
			return '';
		}
		$base = self::frontend_base_url();
		return $base . '/learn/' . (string) $lesson->post_name . '?preview=1';
	}

	protected function first_published_child( int $parent_id, string $post_type ): ?WP_Post {
		$posts = $this->query_courses(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post_parent'    => $parent_id,
				'posts_per_page' => 1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			]
		);
		return $posts[0] ?? null;
	}

	public static function frontend_base_url(): string {
		if ( defined( 'VL_FRONTEND_URL' ) && '' !== (string) constant( 'VL_FRONTEND_URL' ) ) {
			return rtrim( (string) constant( 'VL_FRONTEND_URL' ), '/' );
		}
		return self::FRONTEND_FALLBACK;
	}
}
