<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Exception;

/**
 * Base for all Zoom-integration runtime errors. Subclassed by
 * {@see ZoomAuthException} (credentials / OAuth issues) and
 * {@see ZoomApiException} (HTTP-level failures).
 *
 * @author Tymofii Synianskyi
 */
class ZoomException extends \RuntimeException {
}
