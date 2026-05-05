<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Analytics;

use VL\LMS\Database\SchemaManager;

/**
 * Phase 9.3 — wp-admin "Аналітика" page.
 *
 * Reads the last 30 calendar days from `vl_user_activity_daily`, sums
 * across courses for the summary cards and chart, and renders a Chart.js
 * line chart loaded from a CDN (kept out of the plugin bundle — there is
 * no other client-heavy admin screen that would justify a local copy).
 *
 * Not declared `final` — unit tests subclass to bypass `$wpdb`.
 *
 * @author Tymofii Synianskyi
 */
class AnalyticsPage {

	public const string CHART_HANDLE = 'vl-lms-chartjs';
	public const string CHART_CDN    = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';

	public function enqueue_assets( string $hook ): void {
		// Hook suffix WP gives us for "Green Paws LMS → Аналітика" follows
		// the pattern `{parent}_page_{slug}` for sub-pages. Match the slug
		// suffix so we don't load Chart.js on unrelated screens.
		if ( false === strpos( $hook, 'vl-lms-analytics' ) ) {
			return;
		}

		wp_enqueue_script(
			self::CHART_HANDLE,
			self::CHART_CDN,
			[],
			'4.4.1',
			[ 'in_footer' => true ]
		);
	}

	public function render(): void {
		$rows = $this->fetch_last_30_days();

		echo '<div class="wrap">';
		echo '<h1>Аналітика</h1>';

		if ( [] === $rows ) {
			echo '<p>Ще немає аналітичних даних. Перший звіт з\'явиться наступного ранку.</p>';
			echo '</div>';
			return;
		}

		$totals = [
			'new_enrollments' => 0,
			'active_users'    => 0,
			'completions'     => 0,
		];
		foreach ( $rows as $row ) {
			$totals['new_enrollments'] += (int) $row['new_enrollments'];
			$totals['active_users']    += (int) $row['active_users'];
			$totals['completions']     += (int) $row['completions'];
		}

		echo '<div class="vl-lms-analytics-cards" style="display:flex;gap:16px;margin:16px 0;">';
		$this->render_card( 'Нові записи', $totals['new_enrollments'] );
		$this->render_card( 'Активні користувачі', $totals['active_users'] );
		$this->render_card( 'Завершення', $totals['completions'] );
		echo '</div>';

		$this->render_chart( $rows );

		$this->render_table( $rows );

		echo '</div>';
	}

	private function render_card( string $label, int $value ): void {
		echo '<div class="vl-lms-analytics-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;min-width:160px;">';
		echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;">' . esc_html( (string) $value ) . '</div>';
		echo '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_chart( array $rows ): void {
		$labels      = [];
		$enrollments = [];
		$active      = [];
		$completions = [];
		foreach ( $rows as $row ) {
			$labels[]      = (string) $row['activity_date'];
			$enrollments[] = (int) $row['new_enrollments'];
			$active[]      = (int) $row['active_users'];
			$completions[] = (int) $row['completions'];
		}

		$payload = [
			'labels'      => $labels,
			'enrollments' => $enrollments,
			'active'      => $active,
			'completions' => $completions,
		];

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-bottom:16px;">';
		echo '<canvas id="vl-lms-analytics-chart" height="100"></canvas>';
		echo '</div>';

		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return;
		}

		$script  = "(function(){";
		$script .= "var data=" . $json . ";";
		$script .= "function render(){";
		$script .= "var el=document.getElementById('vl-lms-analytics-chart');";
		$script .= "if(!el||typeof Chart==='undefined')return;";
		$script .= "new Chart(el,{type:'line',data:{labels:data.labels,datasets:[";
		$script .= "{label:'Нові записи',data:data.enrollments,borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,0.15)',tension:0.2},";
		$script .= "{label:'Активні',data:data.active,borderColor:'#00a32a',backgroundColor:'rgba(0,163,42,0.15)',tension:0.2},";
		$script .= "{label:'Завершення',data:data.completions,borderColor:'#dba617',backgroundColor:'rgba(219,166,23,0.15)',tension:0.2}";
		$script .= "]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false}}});";
		$script .= "}";
		$script .= "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',render);}else{render();}";
		$script .= "})();";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $script is a static template; only $json (escaped via wp_json_encode) is interpolated.
		echo '<script>' . $script . '</script>';
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function render_table( array $rows ): void {
		$desc = array_reverse( $rows );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Дата</th>';
		echo '<th>Нові записи</th>';
		echo '<th>Активні</th>';
		echo '<th>Завершення</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		foreach ( $desc as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['activity_date'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['new_enrollments'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['active_users'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['completions'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function fetch_last_30_days(): array {
		$wpdb  = $this->wpdb();
		$table = SchemaManager::user_activity_daily_table();

		$sql = "SELECT activity_date,
				SUM(new_enrollments) AS new_enrollments,
				SUM(active_users) AS active_users,
				SUM(completions) AS completions
			FROM {$table}
			WHERE activity_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			GROUP BY activity_date
			ORDER BY activity_date ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @return \wpdb
	 */
	protected function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
