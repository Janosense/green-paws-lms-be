<?php
// One-shot WP-CLI script: bulk-transliterate existing Cyrillic slugs on the
// inner-course CPTs to ASCII via `Slug\CyrillicTransliterator`.
//
// Catalog post types (`vl_course`, `vl_webinar`) are intentionally excluded
// — see `Slug\SlugTransliterationListener` for the rationale. If you want
// to migrate those too, add the post type below explicitly and re-run.
//
// Usage:
//   ddev exec --dir /var/www/html/wp-content/plugins/vl-lms \
//     wp eval-file scripts/rename-cyrillic-slugs.php
//
// `declare(strict_types=1)` is intentionally omitted — wp-cli `eval-file`
// wraps the script in `eval()`, which forbids non-leading declares.

use VL\LMS\Slug\CyrillicTransliterator;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}

global $wpdb;

$post_types = [
	'vl_module',
	'vl_lesson',
	'vl_topic',
	'vl_quiz',
	'vl_quiz_question',
	'vl_session',
	'vl_assignment',
];

$transliterator = new CyrillicTransliterator();
$updated        = 0;
$skipped        = 0;

foreach ( $post_types as $post_type ) {
	$rows = get_posts(
		[
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		]
	);

	foreach ( $rows as $post ) {
		$current = (string) $post->post_name;
		if ( '' === $current ) {
			continue;
		}

		// Pure ASCII slugs are skipped — re-running the migration is a no-op.
		if ( 1 === preg_match( '/^[a-z0-9-]+$/', $current ) ) {
			++$skipped;
			continue;
		}

		$candidate = $transliterator->slug( $current );
		if ( '' === $candidate || $candidate === $current ) {
			++$skipped;
			continue;
		}

		// `wp_unique_post_slug` honours the same -2/-3 suffix policy as a
		// normal save, so siblings under the same parent never collide.
		$unique = wp_unique_post_slug(
			$candidate,
			(int) $post->ID,
			(string) $post->post_status,
			$post_type,
			(int) $post->post_parent
		);

		// Direct $wpdb->update keeps the rename surgical — no save_post
		// hooks fire, no revisions are created, no meta-box mutations
		// trigger. We only flip post_name; everything else stays put.
		$wpdb->update(
			$wpdb->posts,
			[ 'post_name' => $unique ],
			[ 'ID' => (int) $post->ID ],
			[ '%s' ],
			[ '%d' ]
		);
		clean_post_cache( (int) $post->ID );

		WP_CLI::log(
			sprintf(
				'%s #%d: %s → %s',
				$post_type,
				(int) $post->ID,
				$current,
				$unique
			)
		);
		++$updated;
	}
}

WP_CLI::success( sprintf( 'Renamed %d slugs (%d already ASCII or unmappable).', $updated, $skipped ) );
