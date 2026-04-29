<?php

declare(strict_types=1);

namespace VL\LMS\Database;

/**
 * Owns every custom DB table the plugin ships and the version option that
 * gates schema migrations.
 *
 * `install()` is idempotent — it compares the stored
 * {@see self::DB_VERSION_OPTION} value to {@see self::CURRENT_DB_VERSION}
 * and short-circuits when they match, so repeated activations never
 * re-run `dbDelta`. Bumping `CURRENT_DB_VERSION` re-enters the
 * migration path on the next activation.
 *
 * `uninstall()` drops every `vl_*` table in one place so new tables
 * added in later phases only need their DROP line here.
 *
 * @author Tymofii Synianskyi
 */
final class SchemaManager {

	public const string DB_VERSION_OPTION  = 'vl_lms_db_version';
	public const string CURRENT_DB_VERSION = '5';

	/**
	 * Returns the full prefixed table name for a base suffix.
	 *
	 * The single point where `$wpdb->prefix` is joined with the `vl_`
	 * namespace — every other class routes through the per-table accessors
	 * below so the prefix convention can change without a codebase-wide
	 * rename.
	 */
	public static function table_name( string $base ): string {
		global $wpdb;
		return $wpdb->prefix . 'vl_' . $base;
	}

	public static function enrollments_table(): string {
		return self::table_name( 'enrollments' );
	}

	public static function groups_table(): string {
		return self::table_name( 'groups' );
	}

	public static function group_members_table(): string {
		return self::table_name( 'group_members' );
	}

	public static function group_access_table(): string {
		return self::table_name( 'group_access' );
	}

	public static function course_instructors_table(): string {
		return self::table_name( 'course_instructors' );
	}

	public static function progress_table(): string {
		return self::table_name( 'progress' );
	}

	public static function lesson_views_table(): string {
		return self::table_name( 'lesson_views' );
	}

	public static function quiz_attempts_table(): string {
		return self::table_name( 'quiz_attempts' );
	}

	public static function quiz_answers_table(): string {
		return self::table_name( 'quiz_answers' );
	}

	public static function certificates_table(): string {
		return self::table_name( 'certificates' );
	}

	/**
	 * Installs (or migrates) the schema when the stored DB version is
	 * behind {@see self::CURRENT_DB_VERSION}. Safe to call on every
	 * activation — it is a no-op after the first successful run.
	 *
	 * `dbDelta` is idempotent on individual `CREATE TABLE` statements, so
	 * re-running the enrollments create on a v1→v2 upgrade is harmless.
	 */
	public static function install(): void {
		$current = get_option( self::DB_VERSION_OPTION );
		if ( is_string( $current ) && self::CURRENT_DB_VERSION === $current ) {
			return;
		}

		self::create_enrollments_table();
		self::create_groups_table();
		self::create_group_members_table();
		self::create_group_access_table();
		self::create_course_instructors_table();
		self::create_progress_table();
		self::create_lesson_views_table();
		self::create_quiz_attempts_table();
		self::create_quiz_answers_table();
		self::create_certificates_table();

		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * Drops every `vl_*` table and clears the version option.
	 *
	 * Invoked by `uninstall.php`. Deactivation never calls this — the
	 * user must explicitly delete the plugin before data is destroyed.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = [
			self::certificates_table(),
			self::quiz_answers_table(),
			self::quiz_attempts_table(),
			self::lesson_views_table(),
			self::progress_table(),
			self::course_instructors_table(),
			self::group_access_table(),
			self::group_members_table(),
			self::groups_table(),
			self::enrollments_table(),
		];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Ensures `dbDelta` is loaded once per `install()` run. The admin
	 * upgrade include is cheap to require but not available in cron or
	 * CLI bootstraps by default.
	 */
	private static function require_db_delta(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_enrollments`.
	 *
	 * Column and index declarations follow the `dbDelta` formatting rules:
	 * two spaces between column name and type, uppercase keywords, `PRIMARY
	 * KEY` / `UNIQUE KEY` / `KEY` each on their own line. `dbDelta` is
	 * sensitive to whitespace — reformatting this SQL will break upgrades.
	 */
	private static function create_enrollments_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::enrollments_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			source_group_id BIGINT UNSIGNED NULL DEFAULT NULL,
			source_order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			enrolled_at DATETIME NOT NULL,
			started_at DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			revoked_by BIGINT UNSIGNED NULL DEFAULT NULL,
			revoke_reason VARCHAR(255) NULL DEFAULT NULL,
			progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user_course (user_id, course_id),
			KEY idx_course (course_id),
			KEY idx_user_status (user_id, status),
			KEY idx_group (source_group_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_groups`.
	 */
	private static function create_groups_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::groups_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(200) NOT NULL,
			description TEXT NULL DEFAULT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'ad_hoc',
			owner_id BIGINT UNSIGNED NOT NULL,
			max_members INT UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_slug (slug),
			KEY idx_owner (owner_id),
			KEY idx_status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_group_members`.
	 *
	 * `uk_group_user_active (group_id, user_id, left_at)` is the critical
	 * index: MySQL treats multiple NULLs in a UNIQUE index as distinct,
	 * so each `(group, user)` can have exactly one `left_at IS NULL` row
	 * (the active membership) plus any number of historical
	 * `left_at = datetime` rows (audit trail of past stints).
	 */
	private static function create_group_members_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::group_members_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role_in_group VARCHAR(20) NOT NULL DEFAULT 'member',
			joined_at DATETIME NOT NULL,
			left_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_group_user_active (group_id, user_id, left_at),
			KEY idx_user (user_id),
			KEY idx_group (group_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_course_instructors`.
	 *
	 * The many-to-many join between users and course/webinar posts. The
	 * unique constraint `uk_entity_user (entity_type, entity_id, user_id)`
	 * enforces at most one assignment per `(entity, user)` tuple —
	 * promotions (e.g. co-instructor → lead) UPDATE the existing row.
	 */
	private static function create_course_instructors_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::course_instructors_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(20) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role_in_course VARCHAR(30) NOT NULL DEFAULT 'co_instructor',
			display_order INT UNSIGNED NOT NULL DEFAULT 0,
			assigned_at DATETIME NOT NULL,
			assigned_by BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_entity_user (entity_type, entity_id, user_id),
			KEY idx_user (user_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_group_access`.
	 */
	private static function create_group_access_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::group_access_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			entity_type VARCHAR(20) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			access_type VARCHAR(20) NOT NULL DEFAULT 'granted',
			granted_at DATETIME NOT NULL,
			granted_by BIGINT UNSIGNED NOT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_group_entity (group_id, entity_type, entity_id),
			KEY idx_entity (entity_type, entity_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_progress`.
	 *
	 * Row identity is `(user_id, entity_type, entity_id)` — the unique key
	 * `uniq_user_entity` enforces it so the upsert path can rely on the DB
	 * to reject duplicate progress rows for the same user/entity. The
	 * compound `idx_user_course_status` exists for the dashboard read
	 * (filter a user's rows by course and status) which is the dominant
	 * Phase 5 query shape.
	 */
	private static function create_progress_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::progress_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			entity_type VARCHAR(20) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'not_started',
			position_seconds INT UNSIGNED NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			last_seen_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_entity (user_id, entity_type, entity_id),
			KEY idx_user_course_status (user_id, course_id, status),
			KEY idx_course (course_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_lesson_views`.
	 *
	 * Append-only event log. `idx_user_lesson_time` powers per-user lesson
	 * timelines, `idx_session` groups events sharing a `session_uuid`, and
	 * `idx_lesson_event` supports per-lesson event histograms.
	 */
	private static function create_lesson_views_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::lesson_views_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			topic_id BIGINT UNSIGNED NULL DEFAULT NULL,
			session_uuid CHAR(36) NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			position_seconds INT UNSIGNED NULL DEFAULT NULL,
			payload LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_lesson_time (user_id, lesson_id, created_at),
			KEY idx_session (session_uuid),
			KEY idx_lesson_event (lesson_id, event_type)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_quiz_attempts`.
	 *
	 * One row per quiz attempt. Snapshot columns (`time_limit_seconds`,
	 * `passing_threshold`, `max_score`, `question_order`) are frozen on
	 * `start()` and never re-read from the source CPT — so historical
	 * attempts are immune to instructor edits made after the attempt began.
	 * The `(user_id, course_id)` index supports the course-completion gate
	 * lookup ("does this user have a passing final-exam attempt?") without
	 * re-walking the post-parent tree at check time.
	 */
	private static function create_quiz_attempts_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::quiz_attempts_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			quiz_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'in_progress',
			started_at DATETIME NOT NULL,
			submitted_at DATETIME NULL DEFAULT NULL,
			time_limit_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			time_taken_seconds INT UNSIGNED NULL DEFAULT NULL,
			score INT UNSIGNED NULL DEFAULT NULL,
			max_score INT UNSIGNED NOT NULL DEFAULT 0,
			passed TINYINT(1) NULL DEFAULT NULL,
			passing_threshold TINYINT UNSIGNED NOT NULL DEFAULT 0,
			question_order LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_quiz (user_id, quiz_id),
			KEY user_course (user_id, course_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_quiz_answers`.
	 *
	 * One row per (attempt, question). The unique key `attempt_question`
	 * enforces "one answer per question per attempt" — the upsert path keys
	 * off this constraint so save-as-you-go writes (Phase 6.1) can rely on
	 * `INSERT … ON DUPLICATE KEY UPDATE` rather than SELECT-then-{insert/update}.
	 * Scoring columns (`is_correct`, `points_awarded`) are null until submit,
	 * when the scoring engine writes them in a single batched update.
	 */
	private static function create_quiz_answers_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::quiz_answers_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attempt_id BIGINT UNSIGNED NOT NULL,
			question_id BIGINT UNSIGNED NOT NULL,
			answer_data LONGTEXT NOT NULL,
			is_correct TINYINT(1) NULL DEFAULT NULL,
			points_awarded INT UNSIGNED NULL DEFAULT NULL,
			answered_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_question (attempt_id, question_id),
			KEY question_id (question_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_certificates`.
	 *
	 * Each issued certificate carries a public RFC 4122 v4 UUID (used in
	 * verification URLs and on the rendered PDF) and a JSON `snapshot_data`
	 * blob — the latter freezes the rendering inputs (course title, learner
	 * name, instructor names, issued-at, score) so retroactive edits to the
	 * source course never alter a previously-issued certificate. Revocation
	 * is soft (`revoked_at`); active-per-(user,course) uniqueness is
	 * enforced in the service layer because MySQL has no partial unique
	 * index over a nullable column.
	 */
	private static function create_certificates_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::certificates_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			enrollment_id BIGINT UNSIGNED NOT NULL,
			issued_at DATETIME NOT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			final_score INT UNSIGNED NULL DEFAULT NULL,
			final_max_score INT UNSIGNED NULL DEFAULT NULL,
			snapshot_data LONGTEXT NOT NULL,
			pdf_path VARCHAR(255) NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY user_id (user_id),
			KEY user_course (user_id, course_id)
		) {$charset};";

		dbDelta( $sql );
	}
}
