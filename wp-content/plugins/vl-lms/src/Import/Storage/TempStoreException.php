<?php

declare(strict_types=1);

namespace VL\LMS\Import\Storage;

use RuntimeException;

/**
 * Why a temp folder could not be created or opened. The reason is a stable
 * code the wp-admin handlers turn into their notice; the message is written
 * for the administrator.
 *
 * @author Tymofii Synianskyi
 */
final class TempStoreException extends RuntimeException {

	public const UNKNOWN_TOKEN = 'storage.unknown_token';
	public const EXPIRED_TOKEN = 'storage.expired_token';
	public const FOREIGN_TOKEN = 'storage.foreign_token';
	public const UNWRITABLE    = 'storage.unwritable';

	private function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}

	public static function unknown_token(): self {
		return new self( self::UNKNOWN_TOKEN, __( 'Завантажений файл курсу не знайдено. Завантажте його ще раз.', 'vl-lms' ) );
	}

	public static function expired_token(): self {
		return new self( self::EXPIRED_TOKEN, __( 'Час на підтвердження імпорту минув. Завантажте файл курсу ще раз.', 'vl-lms' ) );
	}

	public static function foreign_token(): self {
		return new self( self::FOREIGN_TOKEN, __( 'Цей файл курсу завантажив інший користувач.', 'vl-lms' ) );
	}

	public static function unwritable(): self {
		return new self( self::UNWRITABLE, __( 'Не вдалося зберегти файл курсу на сервері. Перевірте права на запис у папку завантажень.', 'vl-lms' ) );
	}
}
