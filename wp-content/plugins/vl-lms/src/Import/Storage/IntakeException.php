<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

use RuntimeException;

/**
 * Why an upload was not accepted. The reason is a stable code the wp-admin
 * handlers turn into their notice; the message is written for the
 * administrator.
 *
 * @author Tymofii Synianskyi
 */
final class IntakeException extends RuntimeException {

	public const UPLOAD_FAILED       = 'upload.failed';
	public const TOO_LARGE           = 'upload.too_large';
	public const WRONG_TYPE          = 'upload.wrong_type';
	public const ARCHIVE_UNSUPPORTED = 'archive.unsupported';
	public const ARCHIVE_UNREADABLE  = 'archive.unreadable';
	public const ARCHIVE_TOO_LARGE   = 'archive.too_large';
	public const NO_COURSE_FILE      = 'archive.no_course_file';
	public const EXTRACT_FAILED      = 'archive.extract_failed';

	private function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}

	/**
	 * @param string $detail WordPress's own upload error, when there is one.
	 */
	public static function upload_failed( string $detail = '' ): self {
		$message = '' === $detail
			? __( 'Не вдалося завантажити файл курсу. Спробуйте ще раз.', 'vl-lms' )
			/* translators: %s: the upload error reported by WordPress. */
			: sprintf( __( 'Не вдалося завантажити файл курсу: %s', 'vl-lms' ), $detail );

		return new self( self::UPLOAD_FAILED, $message );
	}

	public static function too_large( int $limit ): self {
		return new self(
			self::TOO_LARGE,
			/* translators: %s: the upload limit, e.g. "8 MB". */
			sprintf( __( 'Файл курсу більший за дозволені %s.', 'vl-lms' ), (string) size_format( $limit ) )
		);
	}

	public static function wrong_type(): self {
		return new self( self::WRONG_TYPE, __( 'Завантажте файл курсу у форматі .md або архів .zip.', 'vl-lms' ) );
	}

	public static function archive_unsupported(): self {
		return new self( self::ARCHIVE_UNSUPPORTED, __( 'Сервер не може відкривати архіви .zip. Завантажте файл course.md без архіву.', 'vl-lms' ) );
	}

	public static function archive_unreadable(): self {
		return new self( self::ARCHIVE_UNREADABLE, __( 'Не вдалося прочитати архів .zip. Перевірте, чи файл не пошкоджено.', 'vl-lms' ) );
	}

	public static function archive_too_large( int $limit ): self {
		return new self(
			self::ARCHIVE_TOO_LARGE,
			/* translators: %s: the upload limit, e.g. "8 MB". */
			sprintf( __( 'Розпакований архів більший за дозволені %s.', 'vl-lms' ), (string) size_format( $limit ) )
		);
	}

	public static function no_course_file(): self {
		return new self( self::NO_COURSE_FILE, __( 'В архіві немає файлу course.md: він має лежати в корені архіву або в єдиній папці верхнього рівня.', 'vl-lms' ) );
	}

	/**
	 * @param string $detail WordPress's own extraction error, when there is one.
	 */
	public static function extract_failed( string $detail = '' ): self {
		$message = '' === $detail
			? __( 'Не вдалося розпакувати архів на сервері.', 'vl-lms' )
			/* translators: %s: the extraction error reported by WordPress. */
			: sprintf( __( 'Не вдалося розпакувати архів на сервері: %s', 'vl-lms' ), $detail );

		return new self( self::EXTRACT_FAILED, $message );
	}
}
