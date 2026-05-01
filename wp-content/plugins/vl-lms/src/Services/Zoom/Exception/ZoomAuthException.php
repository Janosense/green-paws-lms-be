<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Exception;

/**
 * Auth / credentials problems: missing constants and options, OAuth
 * token endpoint refused our basic auth, or a 401 reply that survived
 * the single retry-with-fresh-token.
 *
 * @author Tymofii Synianskyi
 */
class ZoomAuthException extends ZoomException {
}
