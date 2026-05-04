<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Roles;

use PHPUnit\Framework\TestCase;
use VL\LMS\Roles\CapabilitiesMap;

final class CapabilitiesMapTest extends TestCase {

	public function test_cpt_slugs_returns_nine_expected_slugs_in_stable_order(): void {
		self::assertSame(
			[
				'vl_course',
				'vl_module',
				'vl_lesson',
				'vl_topic',
				'vl_session',
				'vl_webinar',
				'vl_quiz',
				'vl_quiz_question',
				'vl_assignment',
			],
			CapabilitiesMap::cpt_slugs()
		);
	}

	public function test_cpt_plural_map_matches_expected_pairs(): void {
		$map = CapabilitiesMap::cpt_plural_map();

		self::assertSame( 'vl_courses', $map['vl_course'] );
		self::assertSame( 'vl_modules', $map['vl_module'] );
		self::assertSame( 'vl_lessons', $map['vl_lesson'] );
		self::assertSame( 'vl_topics', $map['vl_topic'] );
		self::assertSame( 'vl_sessions', $map['vl_session'] );
		self::assertSame( 'vl_webinars', $map['vl_webinar'] );
		self::assertSame( 'vl_quizzes', $map['vl_quiz'] );
		self::assertSame( 'vl_quiz_questions', $map['vl_quiz_question'] );
		self::assertSame( 'vl_assignments', $map['vl_assignment'] );
	}

	public function test_all_cpt_meta_caps_contains_expected_entries(): void {
		$caps = CapabilitiesMap::all_cpt_meta_caps();

		self::assertContains( 'edit_vl_course', $caps );
		self::assertContains( 'read_vl_quiz_question', $caps );
		self::assertContains( 'delete_vl_assignment', $caps );
	}

	public function test_all_cpt_primitive_caps_contains_expected_entries(): void {
		$caps = CapabilitiesMap::all_cpt_primitive_caps();

		self::assertContains( 'edit_others_vl_courses', $caps );
		self::assertContains( 'create_vl_quiz_questions', $caps );
		self::assertContains( 'delete_published_vl_sessions', $caps );
	}

	public function test_cpt_caps_for_administrator_contains_all_primitives(): void {
		$caps = CapabilitiesMap::cpt_caps_for_role( 'administrator' );

		self::assertContains( 'edit_others_vl_courses', $caps );
		self::assertContains( 'create_vl_assignments', $caps );
	}

	public function test_cpt_caps_for_instructor_excludes_others_variants(): void {
		$caps = CapabilitiesMap::cpt_caps_for_role( 'instructor' );

		self::assertContains( 'edit_vl_courses', $caps );
		self::assertContains( 'publish_vl_lessons', $caps );

		$others = array_values(
			array_filter(
				$caps,
				static fn ( string $cap ): bool =>
					str_starts_with( $cap, 'edit_others_' )
					|| str_starts_with( $cap, 'delete_others_' )
			)
		);
		self::assertSame( [], $others );
	}

	public function test_cpt_caps_for_moderator_is_empty(): void {
		self::assertSame( [], CapabilitiesMap::cpt_caps_for_role( 'moderator' ) );
	}

	public function test_cpt_caps_for_student_is_empty(): void {
		self::assertSame( [], CapabilitiesMap::cpt_caps_for_role( 'student' ) );
	}

	public function test_base_caps_for_student_is_read_only(): void {
		self::assertSame( [ 'read' ], CapabilitiesMap::base_caps_for_role( 'student' ) );
	}

	public function test_base_caps_for_moderator_contains_read_and_upload_files(): void {
		self::assertSame(
			[ 'read', 'upload_files' ],
			CapabilitiesMap::base_caps_for_role( 'moderator' )
		);
	}

	public function test_domain_caps_for_student_has_fourteen_caps(): void {
		$caps = CapabilitiesMap::domain_caps_for_role( 'student' );

		self::assertCount( 14, $caps );
		self::assertContains( 'vl_enroll_in_course', $caps );
		self::assertContains( 'vl_view_lesson', $caps );
		self::assertContains( 'vl_download_certificate', $caps );
		self::assertContains( 'vl_view_session_recording', $caps );
		self::assertContains( 'vl_view_webinar_recording', $caps );
		self::assertContains( 'vl_purchase_course', $caps );
		self::assertContains( 'vl_view_own_orders', $caps );
	}

	public function test_domain_caps_for_instructor_has_expected_grants(): void {
		$caps = CapabilitiesMap::domain_caps_for_role( 'instructor' );

		self::assertContains( 'vl_view_own_stats', $caps );
		self::assertContains( 'vl_grade_submissions', $caps );
		self::assertNotContains( 'vl_manage_groups', $caps );
		self::assertNotContains( 'vl_manage_finances', $caps );
	}

	public function test_domain_caps_for_moderator_has_expected_grants(): void {
		$caps = CapabilitiesMap::domain_caps_for_role( 'moderator' );

		self::assertContains( 'vl_manage_groups', $caps );
		self::assertContains( 'vl_review_submissions', $caps );
		self::assertNotContains( 'vl_grade_submissions', $caps );
		self::assertNotContains( 'vl_manage_finances', $caps );
	}

	public function test_domain_caps_for_administrator_contains_all_thirty(): void {
		$caps = CapabilitiesMap::domain_caps_for_role( 'administrator' );

		self::assertCount( 30, $caps );
		self::assertContains( 'vl_manage_finances', $caps );
		self::assertContains( 'vl_issue_certificates', $caps );
		self::assertContains( 'vl_view_lesson', $caps );
		self::assertContains( 'vl_view_session_recording', $caps );
		self::assertContains( 'vl_view_webinar_recording', $caps );
		self::assertContains( 'vl_purchase_course', $caps );
		self::assertContains( 'vl_view_own_orders', $caps );
		self::assertContains( 'vl_refund_orders', $caps );
	}

	public function test_view_session_recording_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_view_session_recording',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_view_session_recording.', $role )
			);
		}
	}

	public function test_view_webinar_recording_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_view_webinar_recording',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_view_webinar_recording.', $role )
			);
		}
	}

	public function test_register_for_webinar_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_register_for_webinar',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_register_for_webinar.', $role )
			);
		}
	}

	public function test_submit_quiz_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_submit_quiz',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_submit_quiz.', $role )
			);
		}
	}

	public function test_view_certificate_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_view_certificate',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_view_certificate.', $role )
			);
		}
	}

	public function test_view_lesson_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_view_lesson',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_view_lesson.', $role )
			);
		}
	}

	public function test_enroll_in_course_cap_is_granted_to_every_role_except_subscriber(): void {
		// Subscriber is the WP fallback role for unverified accounts and is
		// intentionally absent from `DOMAIN_CAPS_BY_ROLE`. The four LMS roles
		// each get the enrollment cap so a freshly verified user (or any
		// staff role) can enroll in a free course.
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_enroll_in_course',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_enroll_in_course.', $role )
			);
		}

		$this->expectException( \InvalidArgumentException::class );
		CapabilitiesMap::domain_caps_for_role( 'subscriber' );
	}

	public function test_all_caps_for_unknown_role_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		CapabilitiesMap::all_caps_for_role( 'unknown-role' );
	}

	public function test_purchase_course_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_purchase_course',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_purchase_course.', $role )
			);
		}
	}

	public function test_view_own_orders_cap_is_granted_to_every_lms_role(): void {
		foreach ( [ 'student', 'instructor', 'moderator', 'administrator' ] as $role ) {
			self::assertContains(
				'vl_view_own_orders',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must hold vl_view_own_orders.', $role )
			);
		}
	}

	public function test_refund_orders_cap_is_administrator_only(): void {
		self::assertContains( 'vl_refund_orders', CapabilitiesMap::domain_caps_for_role( 'administrator' ) );
		foreach ( [ 'student', 'instructor', 'moderator' ] as $role ) {
			self::assertNotContains(
				'vl_refund_orders',
				CapabilitiesMap::domain_caps_for_role( $role ),
				sprintf( 'Role "%s" must not hold vl_refund_orders.', $role )
			);
		}
	}

	public function test_roles_with_display_names_returns_three_custom_roles(): void {
		self::assertSame(
			[
				'instructor' => 'Instructor',
				'moderator'  => 'Moderator',
				'student'    => 'Student',
			],
			CapabilitiesMap::roles_with_display_names()
		);
	}
}
