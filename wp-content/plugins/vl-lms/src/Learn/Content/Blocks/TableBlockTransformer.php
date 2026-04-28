<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use DOMDocument;
use DOMElement;
use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/table` block into a structured cell matrix.
 *
 * Parses the rendered HTML using `\DOMDocument` with libxml internal-error
 * suppression so a stray malformed tag does not flood PHP's error log.
 * If the markup is fundamentally malformed (no `<table>` root), the
 * transformer falls back to the generic HTML shape — that's the same
 * shape `HtmlFallbackBlockTransformer` produces, so the frontend renderer
 * never sees a half-parsed table.
 *
 * Several DOM property accesses below (`childNodes`, `nodeName`,
 * `ownerDocument`) are camelCase — that is the PHP standard library's
 * naming, not something this codebase controls. WPCS warnings on those
 * specific properties are suppressed inline.
 *
 * @author Tymofii Synianskyi
 */
final class TableBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/table' === $block_name;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( ParsedBlock $block ): array {
		$table = $this->find_table( $block->inner_html );
		if ( null === $table ) {
			return [
				'type' => 'html',
				'html' => wp_kses_post( $block->inner_html ),
			];
		}

		$head = $this->collect_rows_by_section( $table, 'thead' );
		$body = $this->collect_rows_by_section( $table, 'tbody' );
		$foot = $this->collect_rows_by_section( $table, 'tfoot' );

		// If the table had no explicit `<tbody>`, treat the loose `<tr>`
		// children as the body so editor-flavour tables still parse.
		if ( [] === $body ) {
			$body = $this->collect_loose_rows( $table );
		}

		return [
			'type'       => 'table',
			'has_header' => [] !== $head,
			'has_footer' => [] !== $foot,
			'head'       => $head,
			'body'       => $body,
			'foot'       => $foot,
		];
	}

	private function find_table( string $html ): ?DOMElement {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$prev_state = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$ok  = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_state );

		if ( false === $ok ) {
			return null;
		}

		$tables = $dom->getElementsByTagName( 'table' );
		if ( 0 === $tables->length ) {
			return null;
		}
		$table = $tables->item( 0 );
		return $table instanceof DOMElement ? $table : null;
	}

	/**
	 * @return list<list<string>>
	 */
	private function collect_rows_by_section( DOMElement $table, string $section ): array {
		$rows = [];
		foreach ( $table->getElementsByTagName( $section ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			foreach ( $node->getElementsByTagName( 'tr' ) as $row_node ) {
				if ( $row_node instanceof DOMElement ) {
					$rows[] = $this->extract_cells( $row_node );
				}
			}
		}
		return $rows;
	}

	/**
	 * @return list<list<string>>
	 */
	private function collect_loose_rows( DOMElement $table ): array {
		$rows = [];
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
		foreach ( $table->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			if ( $child instanceof DOMElement && 'tr' === strtolower( $child->nodeName ) ) {
				$rows[] = $this->extract_cells( $child );
			}
		}
		return $rows;
	}

	/**
	 * @return list<string>
	 */
	private function extract_cells( DOMElement $row ): array {
		$cells = [];
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
		foreach ( $row->childNodes as $cell ) {
			if ( ! $cell instanceof DOMElement ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			$tag = strtolower( $cell->nodeName );
			if ( 'td' !== $tag && 'th' !== $tag ) {
				continue;
			}
			$inner = '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			foreach ( $cell->childNodes as $piece ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
				$inner .= $cell->ownerDocument?->saveHTML( $piece ) ?? '';
			}
			$cells[] = wp_kses_post( $inner );
		}
		return $cells;
	}
}
