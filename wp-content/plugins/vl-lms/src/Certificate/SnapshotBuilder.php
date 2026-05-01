<?php

declare(strict_types=1);

namespace VL\LMS\Certificate;

use VL\LMS\Domain\CourseInstructor\InstructorEntityType;
use VL\LMS\Services\CourseInstructors\CourseInstructorService;
use WP_Post;
use WP_User;

/**
 * Builds the immutable JSON payload that the certificate row carries
 * forward to PDF rendering and the public verification surface.
 *
 * Snapshot semantics: every value is captured once, at issuance time.
 * Subsequent changes to the source course title, learner profile, or
 * instructor roster never alter a previously-issued certificate.
 *
 * The builder is deliberately small and pure-ish — it consumes WP
 * globals (`get_post`, `get_user_by`, `get_option`) but does not write,
 * does not touch the filesystem, and does not query custom tables
 * directly (the instructor list flows through
 * {@see CourseInstructorService}). All collaborators are mockable so
 * unit tests can run without booting WordPress.
 *
 * @author Tymofii Synianskyi
 */
class SnapshotBuilder {

	public const string TEMPLATE_VERSION = 'v1';

	public function __construct(
		private readonly CourseInstructorService $instructors
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build_snapshot(
		int $user_id,
		int $course_id,
		?int $final_score,
		?int $final_max_score,
		\DateTimeImmutable $issued_at
	): array {
		$course = $this->resolve_course( $course_id );
		$user   = $this->resolve_user( $user_id );

		$full_name    = $this->resolve_full_name( $user );
		$display_name = $this->resolve_display_name( $user );

		return [
			'course_title'         => null === $course ? '' : (string) $course->post_title,
			'course_slug'          => null === $course ? '' : (string) $course->post_name,
			'learner_full_name'    => $full_name,
			'learner_display_name' => $display_name,
			'instructor_names'     => $this->resolve_instructor_names( $course_id, $course ),
			'issuer_name'          => $this->resolve_issuer_name(),
			'issued_at_iso'        => $issued_at->format( \DateTimeInterface::ATOM ),
			'final_score_pct'      => $this->compute_score_pct( $final_score, $final_max_score ),
			'template_version'     => self::TEMPLATE_VERSION,
		];
	}

	private function resolve_course( int $course_id ): ?WP_Post {
		$post = get_post( $course_id );
		return $post instanceof WP_Post ? $post : null;
	}

	private function resolve_user( int $user_id ): ?WP_User {
		$user = get_user_by( 'id', $user_id );
		return $user instanceof WP_User ? $user : null;
	}

	private function resolve_full_name( ?WP_User $user ): string {
		if ( null === $user ) {
			return '';
		}
		$first  = trim( (string) ( $user->first_name ?? '' ) );
		$last   = trim( (string) ( $user->last_name ?? '' ) );
		$joined = trim( $first . ' ' . $last );
		if ( '' !== $joined ) {
			return $joined;
		}
		$display = trim( (string) ( $user->display_name ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}
		return (string) ( $user->user_login ?? '' );
	}

	/**
	 * "First name + last initial" — e.g. "Богдан К."
	 *
	 * Falls back through `display_name` → `user_login` if first/last are
	 * not set. The dot is appended only when an initial is available.
	 */
	private function resolve_display_name( ?WP_User $user ): string {
		if ( null === $user ) {
			return '';
		}
		$first = trim( (string) ( $user->first_name ?? '' ) );
		$last  = trim( (string) ( $user->last_name ?? '' ) );
		if ( '' !== $first && '' !== $last ) {
			$initial = mb_substr( $last, 0, 1 );
			return $first . ' ' . $initial . '.';
		}
		if ( '' !== $first ) {
			return $first;
		}
		$display = trim( (string) ( $user->display_name ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}
		return (string) ( $user->user_login ?? '' );
	}

	/**
	 * @return list<string>
	 */
	private function resolve_instructor_names( int $course_id, ?WP_Post $course ): array {
		$rows = $this->instructors->list_for_entity( InstructorEntityType::COURSE, $course_id );

		$names = [];
		foreach ( $rows as $row ) {
			$user = $this->resolve_user( $row->user_id );
			if ( null === $user ) {
				continue;
			}
			$display = trim( (string) ( $user->display_name ?? '' ) );
			if ( '' !== $display ) {
				$names[] = $display;
			}
		}

		if ( [] !== $names ) {
			return $names;
		}

		// Fallback: the course's post_author when the instructor table is
		// empty. Useful for newly-created courses before an instructor
		// row has been promoted.
		if ( null === $course || null === $course->post_author ) {
			return [];
		}
		$author = $this->resolve_user( (int) $course->post_author );
		if ( null === $author ) {
			return [];
		}
		$display = trim( (string) ( $author->display_name ?? '' ) );
		return '' === $display ? [] : [ $display ];
	}

	private function resolve_issuer_name(): string {
		$value = get_option( 'vl_lms_certificate_issuer', 'Green Paws LMS' );
		return is_string( $value ) && '' !== $value ? $value : 'Green Paws LMS';
	}

	private function compute_score_pct( ?int $score, ?int $max ): ?int {
		if ( null === $score || null === $max || 0 === $max ) {
			return null;
		}
		return (int) round( ( $score / $max ) * 100, 0, PHP_ROUND_HALF_UP );
	}
}
