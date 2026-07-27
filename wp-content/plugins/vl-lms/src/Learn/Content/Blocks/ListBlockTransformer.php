<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use DOMDocument;
use DOMElement;
use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/list` block into `{type: list, ordered, items}`.
 *
 * The preferred source is the block's `core/list-item` children — that is
 * how the block editor has stored lists since WP 6.0. Lists saved before
 * that, and any `core/list` written by hand (the demo seeder among them),
 * carry no inner blocks at all: their `<li>` elements sit directly in
 * `inner_html`. Reading only the inner blocks would hand the frontend an
 * empty `items` array, and the list would render as a bulletless void — so
 * when there are no `core/list-item` children the `<li>` elements are read
 * off the markup instead, mirroring the `\DOMDocument` approach in
 * {@see TableBlockTransformer}.
 *
 * Only top-level items are walked in either mode. A nested list inside an
 * item survives as inline `<ul>` / `<ol>` markup within the item HTML — no
 * recursive structuring in v1.
 *
 * `ordered` comes from the block attribute when present. Markup-only lists
 * often omit it, so in that case the parsed root tag decides.
 *
 * Several DOM property accesses below (`childNodes`, `nodeName`,
 * `ownerDocument`) are camelCase — that is the PHP standard library's
 * naming, not something this codebase controls. WPCS warnings on those
 * specific properties are suppressed inline.
 *
 * @author Tymofii Synianskyi
 */
final class ListBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/list' === $block_name;
	}

	/**
	 * @return array{type:string,ordered:bool,items:list<string>}
	 */
	public function transform( ParsedBlock $block ): array {
		$items = [];
		foreach ( $block->inner_blocks as $child ) {
			if ( 'core/list-item' !== $child->name ) {
				continue;
			}
			$items[] = wp_kses_post( $child->inner_html );
		}

		$root = [] === $items ? $this->find_list_root( $block->inner_html ) : null;
		if ( null !== $root ) {
			$items = $this->items_from_markup( $root );
		}

		return [
			'type'    => 'list',
			'ordered' => $this->resolve_ordered( $block->attrs, $root ),
			'items'   => $items,
		];
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private function resolve_ordered( array $attrs, ?DOMElement $root ): bool {
		if ( array_key_exists( 'ordered', $attrs ) ) {
			return (bool) $attrs['ordered'];
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
		return null !== $root && 'ol' === strtolower( $root->nodeName );
	}

	/**
	 * Locate the outermost `<ul>` / `<ol>` in the block's rendered markup.
	 *
	 * Walks the wrapper's direct children rather than calling
	 * `getElementsByTagName()` per tag — that would return the first `<ul>`
	 * in document order, which for an `<ol>` holding a nested `<ul>` is the
	 * nested one.
	 */
	private function find_list_root( string $html ): ?DOMElement {
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

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property name is fixed by PHP.
		$wrapper = $dom->documentElement;
		if ( ! $wrapper instanceof DOMElement ) {
			return null;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
		foreach ( $wrapper->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			$tag = strtolower( $child->nodeName );
			if ( 'ul' === $tag || 'ol' === $tag ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function items_from_markup( DOMElement $root ): array {
		$items = [];

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
		foreach ( $root->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$inner = '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
			foreach ( $child->childNodes as $piece ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name is fixed by PHP.
				$inner .= $child->ownerDocument?->saveHTML( $piece ) ?? '';
			}

			$items[] = wp_kses_post( trim( $inner ) );
		}

		return $items;
	}
}
