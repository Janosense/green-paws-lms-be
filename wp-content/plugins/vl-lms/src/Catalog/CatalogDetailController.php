<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

use VL\LMS\Catalog\Detail\CourseDetailTransformer;
use VL\LMS\Catalog\Detail\WebinarDetailTransformer;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for `GET /vl/v1/catalog/courses/{slug}` and
 * `GET /vl/v1/catalog/webinars/{slug}`.
 *
 * Resolves the slug to a published `vl_course` / `vl_webinar` post,
 * then delegates to the appropriate detail transformer. Drafts,
 * private, trashed, and pending posts are treated as nonexistent
 * (404, not 403 — we don't want to leak the slug's existence).
 *
 * Caching: detail responses are heavier than list responses (full
 * content + curriculum + all instructors). The natural insertion
 * point for an object-cache layer is {@see self::find_published_post()}
 * for the slug → post lookup and the per-slug response cache keyed on
 * `vl_lms:catalog:course:{slug}:{post_modified_gmt}` (or webinar
 * equivalent). Caching is intentionally not implemented in 3.2 — leave
 * the comment as a Phase 9 hook.
 *
 * @author Tymofii Synianskyi
 */
final class CatalogDetailController {

	// `[^/]+` rather than `[a-z0-9-]+` so non-ASCII slugs (e.g. Cyrillic
	// ones produced before the `Slug\SlugTransliterationListener` create-time
	// transliteration was wired up) still resolve. WordPress URL-decodes the
	// route segment before the regex match, so a request for
	// `%D1%82%D0%B5%D1%81%D1%82-...` is matched as `тест-...`. The
	// `sanitize_title` arg sanitizer plus the exact `name=` lookup in
	// `find_published_post()` are charset-agnostic.
	public const string COURSE_ROUTE  = '/catalog/courses/(?P<slug>[^/]+)';
	public const string WEBINAR_ROUTE = '/catalog/webinars/(?P<slug>[^/]+)';

	public function __construct(
		private readonly string $rest_namespace,
		private readonly CourseDetailTransformer $course_detail,
		private readonly WebinarDetailTransformer $webinar_detail,
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			$this->rest_namespace,
			self::COURSE_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_course' ],
				'permission_callback' => '__return_true',
				'args'                => $this->slug_args(),
			]
		);

		register_rest_route(
			$this->rest_namespace,
			self::WEBINAR_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_webinar' ],
				'permission_callback' => '__return_true',
				'args'                => $this->slug_args(),
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course( WP_REST_Request $request ) {
		$post = $this->find_published_post( PostType::COURSE, (string) $request->get_param( 'slug' ) );
		if ( null === $post ) {
			return $this->not_found();
		}

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->course_detail->transform( $post ),
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_webinar( WP_REST_Request $request ) {
		$post = $this->find_published_post( PostType::WEBINAR, (string) $request->get_param( 'slug' ) );
		if ( null === $post ) {
			return $this->not_found();
		}

		return rest_ensure_response(
			[
				'success' => true,
				'data'    => $this->webinar_detail->transform( $post ),
			]
		);
	}

	private function find_published_post( PostType $post_type, string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			[
				'name'             => $slug,
				'post_type'        => $post_type->value,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		if ( ! is_array( $posts ) || [] === $posts ) {
			return null;
		}

		$post = $posts[0];
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		return $post;
	}

	private function not_found(): WP_Error {
		return new WP_Error(
			'vl_lms_not_found',
			__( 'Resource not found', 'vl-lms' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function slug_args(): array {
		return [
			'slug' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			],
		];
	}
}
