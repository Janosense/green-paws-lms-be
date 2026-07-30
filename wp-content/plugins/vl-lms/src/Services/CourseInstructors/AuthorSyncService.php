<?php

declare(strict_types=1);

namespace VL\LMS\Services\CourseInstructors;

use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Domain\CourseInstructor\InstructorRole;
use VL\LMS\Repositories\CourseInstructorRepository;
use WP_Post;

/**
 * Keeps `{prefix}vl_course_instructors` in lock-step with the
 * `post_author` of each `vl_course` / `vl_webinar` post.
 *
 * On every non-trivial save, exactly one row with
 * `role_in_course = 'lead'` must exist for the post, and it must belong
 * to the current `post_author`. Any previous lead (from before an
 * admin-initiated author change) is preserved as `co_instructor` — less
 * destructive than deletion, and the admin can explicitly remove them
 * later via {@see CourseInstructorService::remove_instructor()}.
 *
 * On permanent deletion (NOT trash), every row for the entity is
 * dropped; trash leaves the assignments in place so that untrashing
 * restores the team.
 *
 * @author Tymofii Synianskyi
 */
final class AuthorSyncService {

	/**
	 * Post statuses where `save_post` represents an in-progress or live
	 * record — the statuses we DO sync. Trash and auto-draft are
	 * explicitly excluded because those transitions don't represent a
	 * real authorship change and would otherwise rewrite rows on every
	 * trash/untrash cycle.
	 *
	 * @var list<string>
	 */
	private const array SYNCED_STATUSES = [ 'publish', 'private', 'draft', 'pending', 'future' ];

	public function __construct( private readonly CourseInstructorRepository $repo ) {
	}

	public function register_hooks(): void {
		// Generic `save_post` at 20 — deliberately not `save_post_{$type}`,
		// which core fires *before* the generic hook and therefore before
		// AdminProvider's meta-box fan-out (priority 10) has run
		// `sync_co_instructors()`. Running after that diff means an
		// author change demotes the outgoing lead once the co-instructor
		// list is already reconciled, so the demoted row survives the save
		// instead of being diffed away (the submitted list was rendered
		// while they were still lead, so it never contains them).
		// `map_post_type()` filters out every other post type.
		add_action( 'save_post', [ $this, 'on_save_post' ], 20, 3 );
		add_action( 'deleted_post', [ $this, 'on_post_deleted' ], 10, 2 );
	}

	/**
	 * `save_post` handler.
	 *
	 * The third argument `$update` is unused — we re-derive state from
	 * the table rather than trusting whether WP considers this a new
	 * post, because the two can disagree (e.g. import scripts, manual
	 * `wp_insert_post` with predefined IDs).
	 */
	public function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, self::SYNCED_STATUSES, true ) ) {
			return;
		}

		$entity_type = $this->map_post_type( $post->post_type );
		if ( null === $entity_type ) {
			return;
		}

		$this->sync_lead( $entity_type, $post_id, (int) $post->post_author );
	}

	public function on_post_deleted( int $post_id, WP_Post $post ): void {
		$entity_type = $this->map_post_type( $post->post_type );
		if ( null === $entity_type ) {
			return;
		}

		$this->repo->delete_all_for_entity( $entity_type, $post_id );
	}

	/**
	 * Ensures the current `post_author` is the lead and any stale lead
	 * rows get demoted to `co_instructor`. See class docblock for the
	 * policy rationale.
	 */
	private function sync_lead( InstructorEntityType $entity_type, int $entity_id, int $new_author_id ): void {
		if ( $new_author_id <= 0 ) {
			return;
		}

		$existing_for_author = $this->repo->find_assignment( $entity_type, $entity_id, $new_author_id );

		if ( null === $existing_for_author ) {
			$this->repo->insert(
				[
					'entity_type'    => $entity_type->value,
					'entity_id'      => $entity_id,
					'user_id'        => $new_author_id,
					'role_in_course' => InstructorRole::LEAD->value,
					'display_order'  => 0,
					'assigned_at'    => gmdate( 'Y-m-d H:i:s' ),
					'assigned_by'    => $new_author_id,
				]
			);
		} elseif ( InstructorRole::LEAD !== $existing_for_author->role_in_course ) {
			$this->repo->update(
				$existing_for_author->id,
				[ 'role_in_course' => InstructorRole::LEAD->value ]
			);
		}

		foreach ( $this->repo->list_for_entity( $entity_type, $entity_id ) as $assignment ) {
			if ( $assignment->user_id === $new_author_id ) {
				continue;
			}
			if ( InstructorRole::LEAD === $assignment->role_in_course ) {
				$this->repo->update(
					$assignment->id,
					[ 'role_in_course' => InstructorRole::CO_INSTRUCTOR->value ]
				);
			}
		}
	}

	private function map_post_type( string $post_type ): ?InstructorEntityType {
		return match ( $post_type ) {
			'vl_course'  => InstructorEntityType::COURSE,
			'vl_webinar' => InstructorEntityType::WEBINAR,
			default      => null,
		};
	}
}
