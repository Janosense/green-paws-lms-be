<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Orders;

use VL\LMS\Repositories\OrderRepository;

/**
 * Admin "Замовлення" list page-controller.
 *
 * Phase 8.6 — wraps {@see OrdersListTable} in the standard WP-admin
 * `<form method="get">` so filter dropdowns + search + pagination
 * round-trip through the URL.
 *
 * @author Tymofii Synianskyi
 */
class OrdersListPage {

	public function __construct( private readonly OrderRepository $orders ) {
	}

	public function render(): void {
		$table = new OrdersListTable( $this->orders );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>Замовлення</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="vl-lms-orders" />';
		$table->search_box( 'Пошук', 'order-search' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}
