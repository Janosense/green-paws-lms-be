<?php
/**
 * VL Headless Theme bootstrap.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-theme.php';
require_once __DIR__ . '/includes/class-frontend-redirect.php';
require_once __DIR__ . '/includes/class-cleanup.php';
require_once __DIR__ . '/includes/class-rest-hardening.php';
require_once __DIR__ . '/includes/class-admin-ux.php';

VL_Headless_Theme::boot();
