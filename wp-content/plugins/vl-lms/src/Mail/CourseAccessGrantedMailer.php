<?php

declare(strict_types=1);

namespace VL\LMS\Mail;

use VL\LMS\Support\AppUrlResolver;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_User;

/**
 * Course-access-granted email. Triggered by
 * {@see \VL\LMS\Admin\Students\StudentEnrollmentFormHandler} when an admin
 * manually grants a student access to a course from the per-student detail
 * page. Mirrors {@see OrderPaidMailer} — purchaser-style "access is open"
 * notification, but for a comp grant with no order behind it.
 *
 * @author Tymofii Synianskyi
 */
class CourseAccessGrantedMailer {

	public function __construct(
		private readonly Logger $logger,
		private readonly AppUrlResolver $url_resolver,
		private readonly HtmlMailSender $sender
	) {
	}

	public function send( int $user_id, int $course_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email ) {
			$this->logger->warning(
				'CourseAccessGrantedMailer: user not found or has no email.',
				[
					'user_id'   => $user_id,
					'course_id' => $course_id,
				]
			);
			return false;
		}

		$course = get_post( $course_id );
		if ( ! $course instanceof WP_Post ) {
			$this->logger->warning(
				'CourseAccessGrantedMailer: course not found.',
				[
					'user_id'   => $user_id,
					'course_id' => $course_id,
				]
			);
			return false;
		}

		$title = wp_strip_all_tags( (string) $course->post_title );

		$subject = (string) apply_filters(
			'vl_lms_course_access_granted_subject',
			'Вам відкрито доступ до курсу — ' . $title,
			$user_id,
			$course_id
		);

		$body = (string) apply_filters(
			'vl_lms_course_access_granted_body',
			$this->default_body( $user, $course, $title ),
			$user_id,
			$course_id
		);

		return $this->sender->send( (string) $user->user_email, $subject, $body );
	}

	private function default_body( WP_User $user, WP_Post $course, string $title ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;
		$course_url    = $this->url_resolver->path( '/courses/' . (string) $course->post_name );

		return sprintf(
			'<p>Доброго дня, %s!</p>'
			. '<p>Адміністратор відкрив вам доступ до курсу «%s».</p>'
			. '<p>Перейдіть до курсу, щоб розпочати навчання:</p>'
			. '<p><a href="%s">Перейти до курсу</a></p>'
			. '<p>— Команда Green Paws</p>',
			esc_html( $greeting_name ),
			esc_html( $title ),
			esc_url( $course_url )
		);
	}
}
