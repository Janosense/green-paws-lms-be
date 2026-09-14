<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Import;

use WP_User;

/**
 * Who can become the lead instructor of an imported course: every user with
 * the `instructor` role, then the importing administrator
 * (`docs/DECISIONS.md` 2026-09-11 — scope of the importer v1).
 *
 * The preview's select offers this list, and the confirmation accepts only an
 * id from it.
 *
 * @author Tymofii Synianskyi
 */
final class InstructorCandidates {

	/**
	 * @return list<array{id: int, display_name: string, user_login: string}> Instructors by display name, then `$user_id` unless already listed.
	 */
	public function for_user( int $user_id ): array {
		$users = get_users(
			[
				'role'    => 'instructor',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => [ 'ID', 'display_name', 'user_login' ],
			]
		);

		$candidates = [];
		foreach ( $users as $user ) {
			if ( is_object( $user ) && isset( $user->ID, $user->display_name, $user->user_login ) ) {
				$candidates[] = [
					'id'           => (int) $user->ID,
					'display_name' => (string) $user->display_name,
					'user_login'   => (string) $user->user_login,
				];
			}
		}

		if ( in_array( $user_id, array_column( $candidates, 'id' ), true ) ) {
			return $candidates;
		}

		$current = get_userdata( $user_id );
		if ( $current instanceof WP_User ) {
			$candidates[] = [
				'id'           => (int) $current->ID,
				'display_name' => (string) $current->display_name,
				'user_login'   => (string) $current->user_login,
			];
		}

		return $candidates;
	}

	/**
	 * The candidate pre-selected for a course file's `author`: the first whose
	 * display name is exactly that text, else the fallback (the importing
	 * administrator). Instructors are listed first, so one of them wins over
	 * an administrator with the same name.
	 *
	 * @param list<array{id: int, display_name: string, user_login: string}> $candidates From {@see self::for_user()}.
	 */
	public static function preselect( array $candidates, string $author, int $fallback_id ): int {
		foreach ( $candidates as $candidate ) {
			if ( $candidate['display_name'] === $author ) {
				return $candidate['id'];
			}
		}

		return $fallback_id;
	}
}
