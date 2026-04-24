<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

/**
 * Application-level account classification, independent of WP role.
 *
 * The WP role a new account receives (`student`) is a separate concern
 * from the domain-level "kind" — an account is first the thing it is
 * (student, future: moderator), and only secondarily whatever WP role
 * the plugin happens to map it to. Storing both decouples future
 * moderator onboarding from student onboarding without churning the
 * WP role graph.
 *
 * Adding a new kind is a two-line change: append a `public const` here
 * and add it to {@see self::ALLOWED}.
 *
 * @author Tymofii Synianskyi
 */
final class AccountKind {

	public const string STUDENT = 'student';

	/**
	 * Allow-list of accepted `account_kind` values on
	 * {@see \VL\LMS\Auth\Registration\RegistrationRequest}.
	 *
	 * @var list<string>
	 */
	public const array ALLOWED = [ self::STUDENT ];

	/**
	 * Prevent instantiation — this class exists only to expose constants.
	 */
	private function __construct() {
	}
}
