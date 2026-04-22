<?php
/**
 * Last-resort template. FrontendRedirect intercepts public requests
 * via template_redirect first; this file only fires when redirection
 * is skipped (e.g. VL_FRONTEND_URL is undefined).
 */

defined('ABSPATH') || exit;

status_header(200);
header('Content-Type: application/json; charset=utf-8');

$frontend = defined('VL_FRONTEND_URL') ? VL_FRONTEND_URL : '';

echo wp_json_encode([
    'message'  => 'This is a headless WordPress backend. Public content is served by the frontend application.',
    'frontend' => $frontend,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
