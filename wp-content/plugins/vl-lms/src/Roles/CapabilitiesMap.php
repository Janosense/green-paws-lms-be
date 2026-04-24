<?php

declare(strict_types=1);

namespace VL\LMS\Roles;

/**
 * Single source of truth for VL LMS roles and capabilities.
 *
 * Pure data — no WordPress API calls. Consumed by RolesInstaller (which
 * applies the map on activation and strips it on uninstall) and, in a
 * later task, by CPT registration (which reads the CPT cap shape via
 * `capability_type` + `map_meta_cap: true`).
 *
 * @author Tymofii Synianskyi
 */
final class CapabilitiesMap {

	/**
	 * Singular → plural map for the nine VL LMS custom post types.
	 *
	 * The singular slug becomes the meta-cap stem (`edit_{singular}`,
	 * `read_{singular}`, `delete_{singular}`); the plural slug becomes the
	 * primitive-cap stem (`edit_{plural}`, `create_{plural}`, etc.).
	 */
	public const array CPT_PLURAL_MAP = [
		'vl_course'        => 'vl_courses',
		'vl_module'        => 'vl_modules',
		'vl_lesson'        => 'vl_lessons',
		'vl_topic'         => 'vl_topics',
		'vl_session'       => 'vl_sessions',
		'vl_webinar'       => 'vl_webinars',
		'vl_quiz'          => 'vl_quizzes',
		'vl_quiz_question' => 'vl_quiz_questions',
		'vl_assignment'    => 'vl_assignments',
	];

	/**
	 * Primitive CPT cap templates granted to the instructor role for every CPT.
	 *
	 * Instructor intentionally lacks `edit_others_%s` and `delete_others_%s`
	 * — own-content restriction is enforced by WP's default `map_meta_cap`
	 * behavior on `post_author`. A later task will layer co-instructor
	 * access on top via `InstructorAccessFilter`.
	 */
	public const array INSTRUCTOR_CPT_CAP_SUFFIXES = [
		'edit_%s',
		'edit_published_%s',
		'edit_private_%s',
		'publish_%s',
		'delete_%s',
		'delete_published_%s',
		'delete_private_%s',
		'read_private_%s',
		'create_%s',
	];

	/**
	 * Full set of primitive CPT cap templates — granted to administrator.
	 */
	public const array ALL_PRIMITIVE_CPT_CAP_SUFFIXES = [
		'edit_%s',
		'edit_others_%s',
		'edit_private_%s',
		'edit_published_%s',
		'publish_%s',
		'read_private_%s',
		'delete_%s',
		'delete_private_%s',
		'delete_published_%s',
		'delete_others_%s',
		'create_%s',
	];

	/**
	 * Meta-cap templates — mapped per-post by WP at runtime.
	 */
	public const array META_CPT_CAP_SUFFIXES = [
		'edit_%s',
		'read_%s',
		'delete_%s',
	];

	/**
	 * Domain caps granted per role.
	 *
	 * `vl_manage_groups` is intentionally listed for both moderator and
	 * administrator — moderators own group management.
	 */
	public const array DOMAIN_CAPS_BY_ROLE = [
		'student'       => [
			'vl_enroll_in_course',
			'vl_view_enrolled_content',
			'vl_submit_quiz',
			'vl_submit_assignment',
			'vl_join_session',
			'vl_register_for_webinar',
			'vl_join_webinar',
			'vl_download_certificate',
		],
		'instructor'    => [
			'vl_enroll_in_course',
			'vl_view_enrolled_content',
			'vl_submit_quiz',
			'vl_submit_assignment',
			'vl_join_session',
			'vl_register_for_webinar',
			'vl_join_webinar',
			'vl_download_certificate',
			'vl_view_own_stats',
			'vl_grade_submissions',
		],
		'moderator'     => [
			'vl_enroll_in_course',
			'vl_view_enrolled_content',
			'vl_submit_quiz',
			'vl_submit_assignment',
			'vl_join_session',
			'vl_register_for_webinar',
			'vl_join_webinar',
			'vl_download_certificate',
			'vl_view_all_enrollments',
			'vl_manage_enrollments',
			'vl_moderate_discussions',
			'vl_review_submissions',
			'vl_manage_group_members',
			'vl_view_user_activity',
			'vl_manage_groups',
		],
		'administrator' => [
			'vl_enroll_in_course',
			'vl_view_enrolled_content',
			'vl_submit_quiz',
			'vl_submit_assignment',
			'vl_join_session',
			'vl_register_for_webinar',
			'vl_join_webinar',
			'vl_download_certificate',
			'vl_view_own_stats',
			'vl_grade_submissions',
			'vl_view_all_enrollments',
			'vl_manage_enrollments',
			'vl_moderate_discussions',
			'vl_review_submissions',
			'vl_manage_group_members',
			'vl_view_user_activity',
			'vl_manage_groups',
			'vl_manage_all_courses',
			'vl_manage_instructors',
			'vl_view_all_stats',
			'vl_issue_certificates',
			'vl_revoke_certificates',
			'vl_manage_finances',
		],
	];

	private const array CUSTOM_ROLES = [
		'instructor' => 'Instructor',
		'moderator'  => 'Moderator',
		'student'    => 'Student',
	];

	private const array KNOWN_ROLES = [ 'student', 'instructor', 'moderator', 'administrator' ];

	/**
	 * Singular slugs of the nine VL LMS CPTs, in declaration order.
	 *
	 * @return list<string>
	 */
	public static function cpt_slugs(): array {
		return array_keys( self::CPT_PLURAL_MAP );
	}

	/**
	 * @return array<string, string>
	 */
	public static function cpt_plural_map(): array {
		return self::CPT_PLURAL_MAP;
	}

	/**
	 * Every meta-cap for every CPT, deduplicated.
	 *
	 * @return list<string>
	 */
	public static function all_cpt_meta_caps(): array {
		$caps = [];
		foreach ( self::cpt_slugs() as $singular ) {
			foreach ( self::META_CPT_CAP_SUFFIXES as $template ) {
				$caps[] = sprintf( $template, $singular );
			}
		}
		return array_values( array_unique( $caps ) );
	}

	/**
	 * Every primitive cap for every CPT, deduplicated.
	 *
	 * @return list<string>
	 */
	public static function all_cpt_primitive_caps(): array {
		$caps = [];
		foreach ( self::CPT_PLURAL_MAP as $plural ) {
			foreach ( self::ALL_PRIMITIVE_CPT_CAP_SUFFIXES as $template ) {
				$caps[] = sprintf( $template, $plural );
			}
		}
		return array_values( array_unique( $caps ) );
	}

	/**
	 * Meta-caps plus primitive caps for every CPT, deduplicated.
	 *
	 * @return list<string>
	 */
	public static function all_cpt_caps(): array {
		return array_values(
			array_unique(
				array_merge( self::all_cpt_meta_caps(), self::all_cpt_primitive_caps() )
			)
		);
	}

	/**
	 * Primitive CPT caps granted to a role — meta-caps are not included
	 * because WP derives them via `map_meta_cap` at check time.
	 *
	 * @return list<string>
	 */
	public static function cpt_caps_for_role( string $role ): array {
		self::assert_known_role( $role );

		if ( 'administrator' === $role ) {
			return self::all_cpt_primitive_caps();
		}

		if ( 'instructor' === $role ) {
			$caps = [];
			foreach ( self::CPT_PLURAL_MAP as $plural ) {
				foreach ( self::INSTRUCTOR_CPT_CAP_SUFFIXES as $template ) {
					$caps[] = sprintf( $template, $plural );
				}
			}
			return array_values( array_unique( $caps ) );
		}

		return [];
	}

	/**
	 * WP core caps granted to a role — kept separate so uninstall can
	 * leave them alone.
	 *
	 * @return list<string>
	 */
	public static function base_caps_for_role( string $role ): array {
		self::assert_known_role( $role );

		if ( 'student' === $role ) {
			return [ 'read' ];
		}
		return [ 'read', 'upload_files' ];
	}

	/**
	 * All `vl_*` domain caps (equivalent to the administrator set).
	 *
	 * @return list<string>
	 */
	public static function domain_caps(): array {
		return self::DOMAIN_CAPS_BY_ROLE['administrator'];
	}

	/**
	 * @return list<string>
	 */
	public static function domain_caps_for_role( string $role ): array {
		self::assert_known_role( $role );
		return self::DOMAIN_CAPS_BY_ROLE[ $role ];
	}

	/**
	 * Complete cap list for a role: base + CPT primitive + domain.
	 *
	 * Returns cap strings; callers convert to WP's `['cap' => true]`
	 * format when passing to `add_role`.
	 *
	 * @return list<string>
	 */
	public static function all_caps_for_role( string $role ): array {
		self::assert_known_role( $role );

		return array_values(
			array_unique(
				array_merge(
					self::base_caps_for_role( $role ),
					self::cpt_caps_for_role( $role ),
					self::domain_caps_for_role( $role )
				)
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function roles_with_display_names(): array {
		return self::CUSTOM_ROLES;
	}

	private static function assert_known_role( string $role ): void {
		if ( ! in_array( $role, self::KNOWN_ROLES, true ) ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown role "%s".', $role )
			);
		}
	}
}
