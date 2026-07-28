<?php

declare(strict_types=1);

namespace VL\LMS\Database;

use VL\LMS\Support\Logger;

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
	public const string CURRENT_DB_VERSION = '10';

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

	public static function session_attendance_table(): string {
		return self::table_name( 'session_attendance' );
	}

	public static function webinar_registrations_table(): string {
		return self::table_name( 'webinar_registrations' );
	}

	public static function zoom_webhook_events_table(): string {
		return self::table_name( 'zoom_webhook_events' );
	}

	public static function orders_table(): string {
		return self::table_name( 'orders' );
	}

	public static function payments_table(): string {
		return self::table_name( 'payments' );
	}

	public static function user_activity_daily_table(): string {
		return self::table_name( 'user_activity_daily' );
	}

	public static function assignment_submissions_table(): string {
		return self::table_name( 'assignment_submissions' );
	}

	/**
	 * Installs (or migrates) the schema when the stored DB version is
	 * behind {@see self::CURRENT_DB_VERSION}. Safe to call on every
	 * activation and on every `init` (registered in `Plugin::boot()`) —
	 * it is a no-op after the first successful run.
	 *
	 * `dbDelta` is idempotent on individual `CREATE TABLE` statements, so
	 * re-running the enrollments create on a v1→v2 upgrade is harmless.
	 *
	 * The version is stamped only after {@see self::schema_landed()}
	 * confirms the migration actually reached the database. `dbDelta`
	 * swallows failed ALTERs (missing privileges, locks) silently;
	 * stamping over a failed migration would short-circuit every later
	 * `install()` while the shipped code queries columns that don't
	 * exist. Leaving the version stale instead makes the next request
	 * retry, and the log names the failure.
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
		self::create_session_attendance_table();
		self::create_webinar_registrations_table();
		self::create_zoom_webhook_events_table();
		self::create_orders_table();
		self::create_payments_table();
		self::create_user_activity_daily_table();
		self::create_assignment_submissions_table();

		if ( ! self::schema_landed() ) {
			return;
		}

		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * Forced re-install: drops the stored version so {@see self::install()}
	 * cannot short-circuit, then reruns it. The recovery lever for a
	 * version stamp that is wrong about the live schema (e.g. stamped by a
	 * pre-verification build whose `dbDelta` ALTER had silently failed) —
	 * a state the fast path can never detect, since it trusts the option.
	 * Safe to run any time: `dbDelta` is idempotent, and the version is
	 * re-stamped only after {@see self::schema_landed()} passes.
	 *
	 * Called from {@see \VL\LMS\Activator::activate()}, which makes
	 * "deactivate + reactivate the plugin" the documented manual fix.
	 */
	public static function reinstall(): void {
		delete_option( self::DB_VERSION_OPTION );
		self::install();
	}

	/**
	 * Post-`dbDelta` verification that the current migration reached the
	 * database, checked via a sentinel added by the most recent schema
	 * bump. **Update the sentinel when bumping
	 * {@see self::CURRENT_DB_VERSION}** to something that version adds.
	 *
	 * v10 sentinel: `progress_reset_at` on `vl_enrollments`.
	 */
	private static function schema_landed(): bool {
		global $wpdb;

		$table = self::enrollments_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table resolves to a SchemaManager accessor; the sentinel name binds through %s.
		$sql = $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'progress_reset_at' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$column = $wpdb->get_var( $sql );

		if ( 'progress_reset_at' === $column ) {
			return true;
		}

		( new Logger() )->error(
			'Schema migration did not land; version not stamped, will retry next request.',
			[
				'expected_version' => self::CURRENT_DB_VERSION,
				'missing_sentinel' => $table . '.progress_reset_at',
				'hint'             => 'Check the DB user\'s ALTER privilege and the MySQL error log.',
			]
		);

		return false;
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
			self::assignment_submissions_table(),
			self::user_activity_daily_table(),
			self::payments_table(),
			self::orders_table(),
			self::zoom_webhook_events_table(),
			self::webinar_registrations_table(),
			self::session_attendance_table(),
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
	 *
	 * `progress_reset_at` (v10) marks the learner's most recent self-service
	 * progress reset. Gate-feeding reads in `QuizAttemptRepository` join on
	 * `uk_user_course` and exclude attempts started before it; no dedicated
	 * index is needed.
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
			progress_reset_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_user_course (user_id, course_id),
			KEY idx_course (course_id),
			KEY idx_user_status (user_id, status),
			KEY idx_group (source_group_id),
			KEY idx_source_order (source_order_id)
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

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_session_attendance`.
	 *
	 * One row per Zoom participant per `(session, participant_uuid)`. The
	 * unique key `uniq_session_participant` is the idempotency seam used
	 * by the join handler in Phase 7.2 — receiving a duplicate
	 * `participant_joined` for an already-open row is a no-op. `user_id`
	 * is nullable because Zoom delivers an email that may not match any
	 * WP user; we still record the row for forensic reconstruction.
	 */
	private static function create_session_attendance_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::session_attendance_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			zoom_participant_uuid VARCHAR(64) NOT NULL,
			participant_email VARCHAR(191) NULL DEFAULT NULL,
			participant_name VARCHAR(191) NULL DEFAULT NULL,
			joined_at DATETIME NOT NULL,
			left_at DATETIME NULL DEFAULT NULL,
			duration_seconds INT UNSIGNED NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_session_participant (session_id, zoom_participant_uuid),
			KEY session_user (session_id, user_id),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_webinar_registrations`.
	 *
	 * One row per `(webinar, user)`. Re-registering after a cancellation
	 * flips `status` from `cancelled` back to `active` and clears
	 * `cancelled_at` rather than INSERTing a second row — mirrors the
	 * `vl_enrollments` revoke / re-enroll lifecycle. The
	 * `(webinar_id, status)` index drives the capacity counter the
	 * Phase 7.3 registration endpoint reads.
	 */
	private static function create_webinar_registrations_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::webinar_registrations_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			webinar_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL,
			source VARCHAR(20) NOT NULL,
			registered_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL DEFAULT NULL,
			attended TINYINT(1) NOT NULL DEFAULT 0,
			attended_duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_webinar_user (webinar_id, user_id),
			KEY user_status (user_id, status),
			KEY webinar_status (webinar_id, status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_zoom_webhook_events`.
	 *
	 * Receiver-side dedup table. The unique `tracking_id` index (Zoom's
	 * `x-zm-trackingid` header) is the idempotency seam — a duplicate
	 * insert short-circuits to `processing_status = ignored`. The full
	 * payload is preserved verbatim for replay/debug; Phase 7.2's
	 * dispatcher reads and advances the `processing_status` enum.
	 */
	private static function create_zoom_webhook_events_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::zoom_webhook_events_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tracking_id VARCHAR(191) NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			event_ts BIGINT UNSIGNED NOT NULL,
			object_id VARCHAR(64) NULL DEFAULT NULL,
			payload LONGTEXT NOT NULL,
			received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME NULL DEFAULT NULL,
			processing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			processing_error TEXT NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tracking_id (tracking_id),
			KEY event_type (event_type),
			KEY object_id (object_id),
			KEY received_at (received_at),
			KEY processing_status (processing_status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_orders`.
	 *
	 * Snapshot semantics: `entity_type`, `entity_id`, `entity_slug`, and
	 * `entity_title_snapshot` are frozen at order creation so price /
	 * title edits made on the source course or webinar after the order
	 * was placed never alter what the learner agreed to. `liqpay_order_id`
	 * is the reference we hand to LiqPay (populated when 8.1's
	 * `OrderService` redirects the user); MySQL allows multiple NULLs in
	 * a UNIQUE index so PENDING orders coexist before redirect. Money is
	 * stored in major units as `DECIMAL(12,2)` to match the CPT-meta price
	 * columns (`_vl_course_price`, `_vl_webinar_price`); domain code
	 * round-trips through {@see \VL\LMS\Domain\Money\Money::from_major_decimal()}
	 * so float arithmetic never reaches the business layer.
	 */
	private static function create_orders_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::orders_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL,
			payment_provider VARCHAR(32) NOT NULL,
			liqpay_order_id VARCHAR(64) NULL DEFAULT NULL,
			entity_type VARCHAR(32) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			entity_slug VARCHAR(200) NOT NULL,
			entity_title_snapshot TEXT NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			currency CHAR(3) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			paid_at DATETIME NULL DEFAULT NULL,
			cancelled_at DATETIME NULL DEFAULT NULL,
			refunded_at DATETIME NULL DEFAULT NULL,
			metadata LONGTEXT NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY liqpay_order_id (liqpay_order_id),
			KEY user_status (user_id, status),
			KEY status_expires (status, expires_at),
			KEY user_entity_status (user_id, entity_type, entity_id, status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Builds and runs `dbDelta` for `{prefix}vl_payments`.
	 *
	 * Append-only audit trail of provider callbacks for each order. The
	 * UNIQUE `idempotency_key` column is the duplicate-callback safety
	 * backstop — Phase 8.2's `CallbackHandler` constructs the key as
	 * `liqpay:{payment_id}:{action}:{status}` and re-lookups before
	 * inserting; concurrent inserts of the same key surface as a
	 * domain-level duplicate exception. `provider_status` stores the raw
	 * provider string verbatim so `PaymentStatus::OTHER` cases remain
	 * auditable; `raw_payload` is the full provider response JSON for
	 * replay/debug.
	 */
	private static function create_payments_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::payments_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(32) NOT NULL,
			provider_payment_id VARCHAR(128) NULL DEFAULT NULL,
			provider_action VARCHAR(32) NOT NULL,
			provider_status VARCHAR(32) NOT NULL,
			transaction_type VARCHAR(16) NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			currency CHAR(3) NOT NULL,
			raw_payload LONGTEXT NOT NULL,
			received_at DATETIME NOT NULL,
			idempotency_key VARCHAR(255) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY order_received (order_id, received_at),
			KEY provider_payment (provider, provider_payment_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Phase 9.3 — daily activity rollup table backing the wp-admin
	 * Analytics page.
	 *
	 * One row per `(course_id, activity_date)`; the UNIQUE key on that pair
	 * lets {@see \VL\LMS\Admin\Analytics\AnalyticsRollupService::rollup()}
	 * use `INSERT … ON DUPLICATE KEY UPDATE` for idempotent re-runs of any
	 * single calendar day. Counts are denormalized snapshots — the source
	 * of truth stays in `vl_enrollments` and `vl_lesson_views`.
	 */
	private static function create_user_activity_daily_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::user_activity_daily_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			activity_date DATE NOT NULL,
			new_enrollments INT UNSIGNED NOT NULL DEFAULT 0,
			active_users INT UNSIGNED NOT NULL DEFAULT 0,
			completions INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY course_date (course_id, activity_date)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Phase 9.4 — student-submitted assignment payloads + grader writes.
	 *
	 * One row per `(assignment_id, user_id)` enforced by the UNIQUE key, so
	 * re-submission while still in `pending` is an in-place UPDATE rather
	 * than a second row. The `(status, submitted_at)` index drives the
	 * "perевірка завдань" admin queue.
	 */
	private static function create_assignment_submissions_table(): void {
		global $wpdb;

		self::require_db_delta();

		$table   = self::assignment_submissions_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			assignment_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			submission_text LONGTEXT NULL,
			submission_file_url VARCHAR(2083) NULL,
			submission_file_name VARCHAR(255) NULL,
			score INT UNSIGNED NULL,
			feedback LONGTEXT NULL,
			graded_by BIGINT UNSIGNED NULL,
			submitted_at DATETIME NOT NULL,
			graded_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY assignment_user (assignment_id, user_id),
			KEY status_submitted (status, submitted_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
