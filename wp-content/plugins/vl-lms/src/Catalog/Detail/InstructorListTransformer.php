<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Detail;

use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\User\InstructorProfileMetaRegistrar;
use WP_User;

/**
 * Builds the `instructors` block for a course / webinar detail response.
 *
 * Returns the full assignment list (lead + co-instructors) sorted by
 * `display_order ASC`, then `id ASC` for ties — the order is already
 * carried by the {@see CourseInstructor} list passed in (the repository
 * sorts at SELECT time). Each entry exposes `role_in_course`, the kses-
 * filtered HTML bio from `vl_instructor_bio`, and a non-null avatar
 * (custom upload via `vl_instructor_avatar_id` if present, Gravatar
 * fallback otherwise).
 *
 * Sibling of {@see \VL\LMS\Catalog\Transformers\LeadInstructorTransformer},
 * which still serves the lighter card payload (no bio, no role).
 *
 * @author Tymofii Synianskyi
 */
final class InstructorListTransformer {

	private const AVATAR_SIZE = 96;

	/**
	 * @param list<CourseInstructor> $assignments
	 *
	 * @return list<array{
	 *     id: int,
	 *     display_name: string,
	 *     role_in_course: string,
	 *     avatar: array{url: string, size: int},
	 *     bio: string
	 * }>
	 */
	public function transform( array $assignments ): array {
		$out = [];
		foreach ( $assignments as $row ) {
			$user = get_user_by( 'id', $row->user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$out[] = [
				'id'             => (int) $user->ID,
				'display_name'   => (string) $user->display_name,
				'role_in_course' => $row->role_in_course->value,
				'avatar'         => $this->resolve_avatar( $user ),
				'bio'            => (string) get_user_meta(
					(int) $user->ID,
					InstructorProfileMetaRegistrar::BIO_META_KEY,
					true
				),
			];
		}
		return $out;
	}

	/**
	 * @return array{url: string, size: int}
	 */
	private function resolve_avatar( WP_User $user ): array {
		$attachment_id = (int) get_user_meta(
			(int) $user->ID,
			InstructorProfileMetaRegistrar::AVATAR_META_KEY,
			true
		);
		if ( $attachment_id > 0 ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
			if ( is_array( $src ) && '' !== (string) $src[0] ) {
				return [
					'url'  => (string) $src[0],
					'size' => self::AVATAR_SIZE,
				];
			}
		}

		return [
			'url'  => (string) get_avatar_url( $user->ID, [ 'size' => self::AVATAR_SIZE ] ),
			'size' => self::AVATAR_SIZE,
		];
	}
}
