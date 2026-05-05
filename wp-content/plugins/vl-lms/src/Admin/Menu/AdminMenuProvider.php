<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Menu;

use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use VL\LMS\Admin\Orders\OrdersListPage;

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
	public const string CAP            = 'edit_posts';
	public const string ORDERS_CAP     = 'vl_refund_orders';
	public const string MENU_ICON      = 'dashicons-welcome-learn-more';
	public const int MENU_POSITION     = 3;

	public function __construct(
		private readonly InstructorDashboardPage $dashboard,
		private readonly OrdersListPage $orders_page
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
	}
}
