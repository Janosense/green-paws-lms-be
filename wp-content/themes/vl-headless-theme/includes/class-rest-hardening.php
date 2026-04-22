<?php
/**
 * Close unauthenticated user enumeration via the default REST endpoints.
 *
 * vl-lms exposes its own authenticated user-related endpoints under vl/v1/.
 * This layer removes the public WP endpoints to deny anonymous enumeration.
 */

defined('ABSPATH') || exit;

final class VL_Headless_Rest_Hardening {

    public function register(): void {
        add_filter('rest_endpoints', [$this, 'block_user_enumeration']);
    }

    public function block_user_enumeration($endpoints) {
        if (!is_array($endpoints) || is_user_logged_in()) {
            return $endpoints;
        }

        unset(
            $endpoints['/wp/v2/users'],
            $endpoints['/wp/v2/users/(?P<id>[\d]+)']
        );

        return $endpoints;
    }
}
