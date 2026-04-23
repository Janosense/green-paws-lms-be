<?php

declare(strict_types=1);

namespace VL\LMS\Access;

use VL\LMS\Roles\CapabilitiesMap;
use WP_Post;
use WP_Post_Type;

/**
 * Extends WordPress' default `map_meta_cap` behavior so listed
 * co-instructors share the post author's edit/delete/read rights.
 *
 * WP's core `map_meta_cap` ties `edit_post`, `delete_post`, and `read_post`
 * on `map_meta_cap: true` post types to `post_author`. That breaks the
 * multi-instructor model where several users may need the same access to a
 * course, lesson, or quiz. When the current user is not the author but the
 * configured {@see CoInstructorLookupInterface} reports them as a
 * co-instructor, this filter rewrites the required caps to the post type's
 * own-content primitive — mirroring what WP would return for the author.
 *
 * The lookup is abstracted behind an interface so that the storage backing
 * co-instructor relationships (currently a no-op `NullCoInstructorLookup`,
 * later the `vl_course_instructors` table) can be swapped without touching
 * this filter.
 *
 * @author Tymofii Synianskyi
 */
final class InstructorAccessFilter {

	/**
	 * Meta-caps this filter considers. WP resolves these per-post through
	 * `map_meta_cap` for CPTs declared with `map_meta_cap: true`.
	 *
	 * @var list<string>
	 */
	private const HANDLED_META_CAPS = [ 'edit_post', 'delete_post', 'read_post' ];

	public function __construct( private readonly CoInstructorLookupInterface $lookup ) {
	}

	public function register_hooks(): void {
		add_filter( 'map_meta_cap', [ $this, 'filter_meta_caps' ], 10, 4 );
	}

	/**
	 * `map_meta_cap` callback.
	 *
	 * Grants a co-instructor the same own-content primitive that WP would
	 * grant the post author. Returns `$caps` unchanged whenever any of the
	 * guard conditions fail — the filter never widens access and never
	 * throws.
	 *
	 * @param list<string>  $caps    Primitive caps WP will require.
	 * @param string        $cap     Meta-cap being mapped.
	 * @param int           $user_id User whose caps are being checked.
	 * @param array<mixed>  $args    Extra args; `$args[0]` is the target post ID.
	 *
	 * @return list<string>
	 */
	public function filter_meta_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! $this->supported_meta_cap( $cap ) ) {
			return $caps;
		}

		$post_id = $this->target_post_id( $args );
		if ( null === $post_id ) {
			return $caps;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $caps;
		}

		if ( ! $this->is_vl_post_type( $post->post_type ) ) {
			return $caps;
		}

		if ( (int) $post->post_author === $user_id ) {
			return $caps;
		}

		if ( ! $this->lookup->is_co_instructor( $user_id, $post_id ) ) {
			return $caps;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return $caps;
		}

		$primitive = $this->primitive_cap_for( $cap, $post_type_object );
		if ( null === $primitive ) {
			return $caps;
		}

		return [ $primitive ];
	}

	private function supported_meta_cap( string $cap ): bool {
		return in_array( $cap, self::HANDLED_META_CAPS, true );
	}

	/**
	 * @param array<mixed> $args
	 */
	private function target_post_id( array $args ): ?int {
		if ( ! array_key_exists( 0, $args ) ) {
			return null;
		}

		$candidate = $args[0];
		if ( ! is_numeric( $candidate ) ) {
			return null;
		}

		$post_id = (int) $candidate;
		return $post_id > 0 ? $post_id : null;
	}

	private function is_vl_post_type( string $post_type ): bool {
		return in_array( $post_type, CapabilitiesMap::cpt_slugs(), true );
	}

	/**
	 * Primitive cap that a post author would be required to hold for the
	 * given meta-cap on `$post_type_object`. Returns `null` for unknown
	 * meta-caps or when the cap object lacks the expected property —
	 * defensive, since third-party filters can mutate it.
	 */
	private function primitive_cap_for( string $meta_cap, WP_Post_Type $post_type_object ): ?string {
		$cap_object = $post_type_object->cap;

		$primitive = match ( $meta_cap ) {
			'edit_post'   => $cap_object->edit_posts ?? null,
			'delete_post' => $cap_object->delete_posts ?? null,
			'read_post'   => $cap_object->read_private_posts ?? null,
			default       => null,
		};

		if ( ! is_string( $primitive ) || '' === $primitive ) {
			return null;
		}

		return $primitive;
	}
}
