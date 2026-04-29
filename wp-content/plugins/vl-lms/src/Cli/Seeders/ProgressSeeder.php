<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;
use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Repositories\LessonViewRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Services\Progress\ProgressEventRequest;
use VL\LMS\Services\Progress\ProgressService;

/**
 * Materialises §6.8 of the seeder spec — varied per-student progress states.
 *
 * Walks each enrolled course's curriculum in canonical learning order
 * (modules in `menu_order` / `ID`, lessons within modules likewise, topics
 * within lessons likewise) and for each "complete" leaf calls
 * {@see ProgressService::record()} with `ViewEventType::COMPLETE`. For the
 * in-progress leaf demanded by Student 2 / Course 2 we issue the four-event
 * sequence (`view_start`, `play`, `progress`, `pause`) at
 * `position_seconds = 90` so the resume toast is demoable on the frontend.
 *
 * Re-run policy: the seeder always wipes existing progress for demo-tagged
 * users before writing, so the row count after run 2 equals run 1
 * regardless of intervening content edits.
 *
 * The spec referenced `ProgressService::record_event()`, but the
 * production method signature is `record(int $user_id, ProgressEventRequest)`;
 * we wrap accordingly. Surfaced in the run log when this seeder runs.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressSeeder {

	public function __construct(
		private readonly ProgressService $service,
		private readonly ProgressRepository $progress_repo,
		private readonly LessonViewRepository $views_repo
	) {
	}

	/**
	 * @param list<array{student:string,user_id:int,course_index:int,course_id:int,plan:string}> $enrollments
	 *
	 * @return SeederResult
	 */
	public function run( SeederContext $context, array $enrollments ): SeederResult {
		$result = new SeederResult();

		$user_ids = array_values( array_unique( array_map( static fn ( array $e ): int => $e['user_id'], $enrollments ) ) );
		foreach ( $user_ids as $user_id ) {
			$this->progress_repo->delete_for_user( $user_id );
			$this->views_repo->delete_for_user( $user_id );
			$this->reset_enrollment_state_for_user( $user_id );
		}

		foreach ( $enrollments as $row ) {
			$this->apply_plan( $context, $row, $result );
		}

		$context->log(
			sprintf(
				/* translators: 1: progress events written. */
				__( 'Progress events written: %d.', 'vl-lms' ),
				$result->created
			)
		);

		return $result;
	}

	/**
	 * @param array{student:string,user_id:int,course_index:int,course_id:int,plan:string} $row
	 */
	private function apply_plan( SeederContext $context, array $row, SeederResult $result ): void {
		unset( $context );

		$leaves = $this->canonical_leaves( $row['course_id'] );
		if ( [] === $leaves ) {
			return;
		}

		$user_id      = $row['user_id'];
		$session_uuid = wp_generate_uuid4();

		switch ( $row['plan'] ) {
			case 'completed':
				$this->complete_leaves( $user_id, $session_uuid, $leaves, count( $leaves ), $result );
				break;
			case 'in_progress_25':
				$count = max( 1, (int) ceil( count( $leaves ) * 0.25 ) );
				$this->complete_leaves( $user_id, $session_uuid, $leaves, $count, $result );
				break;
			case 'in_progress_60':
				$count = max( 1, (int) ceil( count( $leaves ) * 0.60 ) );
				$this->complete_leaves( $user_id, $session_uuid, $leaves, $count, $result );
				break;
			case 'in_progress_60_with_resume':
				$count = max( 1, (int) ceil( count( $leaves ) * 0.60 ) );
				$this->complete_leaves( $user_id, $session_uuid, $leaves, $count, $result );
				if ( $count < count( $leaves ) ) {
					$this->emit_resume_sequence( $user_id, $session_uuid, $leaves[ $count ], $result );
				}
				break;
			case 'just_started_5':
				$this->emit_view_start( $user_id, $session_uuid, $leaves[0], $result );
				break;
		}
	}

	/**
	 * @param list<array{type:EntityType,id:int}> $leaves
	 */
	private function complete_leaves( int $user_id, string $session_uuid, array $leaves, int $count, SeederResult $result ): void {
		$count = min( $count, count( $leaves ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->emit_event( $user_id, $session_uuid, $leaves[ $i ], ViewEventType::COMPLETE, null, $result );
		}
	}

	/**
	 * @param array{type:EntityType,id:int} $leaf
	 */
	private function emit_view_start( int $user_id, string $session_uuid, array $leaf, SeederResult $result ): void {
		$this->emit_event( $user_id, $session_uuid, $leaf, ViewEventType::VIEW_START, 0, $result );
	}

	/**
	 * Four-row resume sequence: view_start → play → progress(90) → pause(90).
	 *
	 * @param array{type:EntityType,id:int} $leaf
	 */
	private function emit_resume_sequence( int $user_id, string $session_uuid, array $leaf, SeederResult $result ): void {
		$this->emit_event( $user_id, $session_uuid, $leaf, ViewEventType::VIEW_START, 0, $result );
		$this->emit_event( $user_id, $session_uuid, $leaf, ViewEventType::PLAY, 0, $result );
		$this->emit_event( $user_id, $session_uuid, $leaf, ViewEventType::PROGRESS, 90, $result );
		$this->emit_event( $user_id, $session_uuid, $leaf, ViewEventType::PAUSE, 90, $result );
	}

	/**
	 * @param array{type:EntityType,id:int} $leaf
	 */
	private function emit_event(
		int $user_id,
		string $session_uuid,
		array $leaf,
		ViewEventType $event_type,
		?int $position_seconds,
		SeederResult $result
	): void {
		try {
			$request = new ProgressEventRequest(
				entity_type: $leaf['type'],
				entity_id: $leaf['id'],
				session_uuid: $session_uuid,
				event_type: $event_type,
				position_seconds: $position_seconds,
				payload: null
			);
			$this->service->record( $user_id, $request );
			++$result->created;
		} catch ( \Throwable $e ) {
			++$result->failed;
			$result->messages[] = sprintf(
				/* translators: 1: leaf type, 2: leaf id, 3: error message. */
				__( 'Progress event failed for %1$s #%2$d: %3$s', 'vl-lms' ),
				$leaf['type']->value,
				$leaf['id'],
				$e->getMessage()
			);
		}
	}

	/**
	 * Walks a course's curriculum in canonical learning order and returns
	 * the trackable leaves (topics where they exist, otherwise lessons).
	 *
	 * @return list<array{type:EntityType,id:int}>
	 */
	private function canonical_leaves( int $course_id ): array {
		$leaves = [];

		$modules = $this->children_in_order( $course_id, 'vl_module' );
		foreach ( $modules as $module ) {
			$leaves = array_merge( $leaves, $this->lesson_leaves_for_parent( (int) $module->ID ) );
		}

		// Course-direct lessons (rare in this seed but valid).
		$leaves = array_merge( $leaves, $this->lesson_leaves_for_parent( $course_id ) );

		return $leaves;
	}

	/**
	 * @return list<array{type:EntityType,id:int}>
	 */
	private function lesson_leaves_for_parent( int $parent_id ): array {
		$out     = [];
		$lessons = $this->children_in_order( $parent_id, 'vl_lesson' );
		foreach ( $lessons as $lesson ) {
			$lesson_id = (int) $lesson->ID;
			$topics    = $this->children_in_order( $lesson_id, 'vl_topic' );
			if ( [] === $topics ) {
				$out[] = [
					'type' => EntityType::LESSON,
					'id'   => $lesson_id,
				];
				continue;
			}
			foreach ( $topics as $topic ) {
				$out[] = [
					'type' => EntityType::TOPIC,
					'id'   => (int) $topic->ID,
				];
			}
		}
		return $out;
	}

	/**
	 * @return list<\WP_Post>
	 */
	private function children_in_order( int $parent_id, string $post_type ): array {
		$query = new \WP_Query(
			[
				'post_type'              => $post_type,
				'post_parent'            => $parent_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$out   = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	/**
	 * Resets `vl_enrollments` rows for a user so the recompute / completion
	 * fan-up writes correct values from a clean slate. Status drops back to
	 * `active`, `progress_pct` to 0, and `completed_at` to null.
	 */
	private function reset_enrollment_state_for_user( int $user_id ): void {
		global $wpdb;
		$table = SchemaManager::enrollments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			[
				'progress_pct' => 0,
				'status'       => 'active',
				'completed_at' => null,
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'user_id' => $user_id ]
		);
	}
}
