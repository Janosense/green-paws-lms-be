<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Menu;

use VL\LMS\Admin\Analytics\AnalyticsPage;
use VL\LMS\Admin\Assignments\GradingQueuePage;
use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Admin\Orders\OrdersListPage;
use VL\LMS\Admin\Settings\SettingsPage;
use VL\LMS\Admin\Students\StudentsListPage;

/**
 * Phase 9.2 — top-level "Green Paws LMS" wp-admin menu.
 *
 * Hooked on `admin_menu` at priority 20 from {@see \VL\LMS\Admin\AdminProvider::boot()}.
 * The Phase 8.6 standalone "vl-lms-orders" top-level entry is registered
 * earlier (priority 10); we remove it and re-attach the same callback as
 * a sub-page so a single LMS section owns Dashboard + Orders without
 * losing the existing `?page=vl-lms-orders` URL.
 *
 * Not declared `final` — unit tests subclass for hook assertions.
 *
 * @author Tymofii Synianskyi
 */
class AdminMenuProvider {

	public const string PARENT_SLUG    = 'vl-lms';
	public const string ORDERS_SLUG    = 'vl-lms-orders';
	public const string ANALYTICS_SLUG = 'vl-lms-analytics';
	public const string GRADING_SLUG   = 'vl-lms-grading-queue';
	public const string SETTINGS_SLUG  = 'vl-lms-settings';
	public const string GROUPS_SLUG    = 'vl-lms-groups';
	public const string STUDENTS_SLUG  = 'vl-lms-students';
	public const string CAP            = 'edit_posts';
	public const string ORDERS_CAP     = 'vl_refund_orders';
	public const string SETTINGS_CAP   = 'manage_vl_lms_settings';
	public const string GROUPS_CAP     = 'vl_manage_groups';
	public const string STUDENTS_CAP   = 'vl_view_all_enrollments';
	public const string MENU_ICON      = 'dashicons-welcome-learn-more';
	public const int MENU_POSITION     = 3;

	public function __construct(
		private readonly InstructorDashboardPage $dashboard,
		private readonly OrdersListPage $orders_page,
		private readonly ?AnalyticsPage $analytics_page = null,
		private readonly ?GradingQueuePage $grading_page = null,
		private readonly ?SettingsPage $settings_page = null,
		private readonly ?GroupsListPage $groups_page = null,
		private readonly ?StudentsListPage $students_page = null
	) {
	}

	public function register(): void {
		// Phase 8.6 added "vl-lms-orders" as a top-level entry at admin_menu
		// priority 10. We strip the menu row but the underlying page route
		// (registered via $_registered_pages) survives, so the URL keeps
		// resolving while the menu entry moves under our parent.
		remove_menu_page( self::ORDERS_SLUG );

		add_menu_page(
			'Green Paws LMS',
			'Green Paws LMS',
			self::CAP,
			self::PARENT_SLUG,
			[ $this->dashboard, 'render' ],
			self::MENU_ICON,
			self::MENU_POSITION
		);

		add_submenu_page(
			self::PARENT_SLUG,
			'Панель інструктора',
			'Панель інструктора',
			self::CAP,
			self::PARENT_SLUG,
			[ $this->dashboard, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			'Замовлення',
			'Замовлення',
			self::ORDERS_CAP,
			self::ORDERS_SLUG,
			[ $this->orders_page, 'render' ]
		);

		if ( null !== $this->analytics_page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				'Аналітика',
				'Аналітика',
				self::CAP,
				self::ANALYTICS_SLUG,
				[ $this->analytics_page, 'render' ]
			);
		}

		if ( null !== $this->grading_page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				'Перевірка завдань',
				'Перевірка завдань',
				self::CAP,
				self::GRADING_SLUG,
				[ $this->grading_page, 'render' ]
			);
		}

		if ( null !== $this->students_page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				'Студенти',
				'Студенти',
				self::STUDENTS_CAP,
				self::STUDENTS_SLUG,
				[ $this->students_page, 'render' ]
			);
		}

		if ( null !== $this->groups_page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				'Групи',
				'Групи',
				self::GROUPS_CAP,
				self::GROUPS_SLUG,
				[ $this->groups_page, 'render' ]
			);
		}

		if ( null !== $this->settings_page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				'Налаштування',
				'Налаштування',
				self::SETTINGS_CAP,
				self::SETTINGS_SLUG,
				[ $this->settings_page, 'render' ]
			);
		}
	}
}
