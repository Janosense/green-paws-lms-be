<?php

declare(strict_types=1);

namespace VL\LMS\Roles;

/**
 * Applies the CapabilitiesMap to WordPress.
 *
 * Idempotent: `install()` can be called on activation repeatedly without
 * producing duplicate roles, stray caps, or PHP notices. `uninstall()` is
 * the mirror operation — it removes custom roles, strips `vl_*` caps from
 * administrator, and rehomes orphaned users to `subscriber`.
 *
 * Core caps (`read`, `upload_files`) are never removed — they belong to
 * WordPress, not to this plugin.
 *
 * @author Tymofii Synianskyi
 */
final class RolesInstaller {

	public static function install(): void {
		foreach ( CapabilitiesMap::roles_with_display_names() as $slug => $display_name ) {
			$role_caps = self::caps_as_assoc( CapabilitiesMap::all_caps_for_role( $slug ) );
			$existing  = get_role( $slug );

			if ( null === $existing ) {
				add_role( $slug, self::translate_display_name( $slug, $display_name ), $role_caps );
				continue;
			}

			foreach ( array_keys( $role_caps ) as $cap ) {
				if ( ! $existing->has_cap( $cap ) ) {
					$existing->add_cap( $cap );
				}
			}
		}

		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			foreach ( self::administrator_plugin_caps() as $cap ) {
				if ( ! $administrator->has_cap( $cap ) ) {
					$administrator->add_cap( $cap );
				}
			}
		}
	}

	public static function uninstall(): void {
		foreach ( array_keys( CapabilitiesMap::roles_with_display_names() ) as $slug ) {
			$users = get_users( [ 'role' => $slug ] );
			foreach ( $users as $user ) {
				$user->remove_role( $slug );
				if ( empty( $user->roles ) ) {
					$user->add_role( 'subscriber' );
				}
			}
			remove_role( $slug );
		}

		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			foreach ( self::administrator_plugin_caps() as $cap ) {
				$administrator->remove_cap( $cap );
			}
		}
	}

	/**
	 * Caps this plugin owns on the administrator role — CPT caps plus
	 * domain caps. Does NOT include `read` / `upload_files`.
	 *
	 * @return list<string>
	 */
	private static function administrator_plugin_caps(): array {
		return array_values(
			array_unique(
				array_merge( CapabilitiesMap::all_cpt_caps(), CapabilitiesMap::domain_caps() )
			)
		);
	}

	/**
	 * WPCS requires `__()` to receive a literal string — we keep the
	 * mapping here so CapabilitiesMap can stay WP-free.
	 */
	private static function translate_display_name( string $slug, string $fallback ): string {
		return match ( $slug ) {
			'instructor' => __( 'Instructor', 'vl-lms' ),
			'moderator'  => __( 'Moderator', 'vl-lms' ),
			'student'    => __( 'Student', 'vl-lms' ),
			default      => $fallback,
		};
	}

	/**
	 * @param list<string> $caps
	 * @return array<string, bool>
	 */
	private static function caps_as_assoc( array $caps ): array {
		$assoc = [];
		foreach ( $caps as $cap ) {
			$assoc[ $cap ] = true;
		}
		return $assoc;
	}
}
