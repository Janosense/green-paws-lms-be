<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes;

use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use WP_Post;
use WP_User;

/**
 * Course "Ко-інструктори" meta-box.
 *
 * Side-column UI for managing the co-instructor team of a `vl_course` row
 * in the `vl_course_instructors` table. The lead row (post_author) is
 * managed by {@see \VL\LMS\Services\CourseInstructors\AuthorSyncService}
 * and is intentionally excluded from this list.
 *
 * Save delegates to {@see CourseInstructorService::sync_co_instructors()}.
 *
 * @author Tymofii Synianskyi
 */
class CourseInstructorsMetaBox extends AbstractMetaBox {

	public function __construct(
		private readonly CourseInstructorService $service,
		private readonly CourseInstructorRepository $repository
	) {
		parent::__construct( 'vl_course' );
	}

	public function id(): string {
		return 'vl_lms_course_instructors';
	}

	public function title(): string {
		return 'Ко-інструктори';
	}

	public function context(): string {
		return 'side';
	}

	public function priority(): string {
		return 'default';
	}

	public function render( WP_Post $post ): void {
		$this->render_nonce();

		$lead_id = (int) $post->post_author;
		$rows    = $this->repository->list_for_entity( InstructorEntityType::COURSE, $post->ID );

		echo '<div class="vl-lms-co-instructors">';
		echo '<ul class="vl-lms-co-list">';

		foreach ( $rows as $row ) {
			if ( $row->user_id === $lead_id ) {
				continue;
			}
			if ( InstructorRole::CO_INSTRUCTOR !== $row->role_in_course ) {
				continue;
			}
			$user = get_userdata( $row->user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			printf(
				'<li class="vl-lms-co-item" data-user-id="%1$d">'
				. '<input type="hidden" name="vl_co_instructor_ids[]" value="%1$d" />'
				. '<span class="vl-lms-co-name">%2$s</span>'
				. '<span class="vl-lms-co-badge">Ко-інструктор</span>'
				. '<button type="button" class="button-link vl-lms-co-remove">Видалити</button>'
				. '</li>',
				(int) $user->ID,
				esc_html( $user->display_name )
			);
		}

		echo '</ul>';

		echo '<div class="vl-lms-co-search">';
		echo '<label for="vl-lms-co-search-input">Додати ко-інструктора</label>';
		echo '<input type="text" id="vl-lms-co-search-input" placeholder="Ім\'я або email" autocomplete="off" />';
		echo '<ul class="vl-lms-co-search-results" hidden></ul>';
		echo '</div>';

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! $this->verified( $post_id ) ) {
			return;
		}

		$lead_id = (int) $post->post_author;

		$raw = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::verified().
		if ( isset( $_POST['vl_co_instructor_ids'] ) && is_array( $_POST['vl_co_instructor_ids'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified; ints sanitized via absint below.
			$raw = wp_unslash( $_POST['vl_co_instructor_ids'] );
		}

		$ids = [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $value ) {
				$id = absint( $value );
				if ( $id <= 0 ) {
					continue;
				}
				if ( $id === $lead_id ) {
					continue;
				}
				$user = get_userdata( $id );
				if ( ! $user instanceof WP_User ) {
					continue;
				}
				if ( ! in_array( 'instructor', (array) $user->roles, true ) ) {
					continue;
				}
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		$this->service->sync_co_instructors( $post_id, $ids );
	}
}
