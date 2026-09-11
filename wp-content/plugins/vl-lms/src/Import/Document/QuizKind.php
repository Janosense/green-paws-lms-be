<?php

declare(strict_types=1);

namespace VL\LMS\Import\Document;

/**
 * Which quiz heading a {@see QuizSpec} came from: `## Тест модуля N` or
 * `## Підсумковий тест`.
 *
 * @author Tymofii Synianskyi
 */
enum QuizKind: string {

	case MODULE = 'module';
	case FINAL  = 'final';
}
