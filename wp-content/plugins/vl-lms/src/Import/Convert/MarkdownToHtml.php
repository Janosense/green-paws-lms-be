<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * The only `league/commonmark` call site of the importer: turns the raw
 * Markdown of one course section into plain HTML
 * (`docs/DECISIONS.md` 2026-09-11 — structural parser in-plugin, lessons as
 * plain HTML).
 *
 * CommonMark core + GFM tables. Raw HTML is stripped and unsafe links lose
 * their target; {@see self::raw_html_lines()} says where raw HTML was, so it
 * can be reported instead of silently dropped. The output is shaped for the
 * classic-editor path:
 *
 * - Headings render one level below the section's `<h2>`: `####` → `<h3>`,
 *   `#####` → `<h4>`, never above `<h3>`.
 * - A soft line break renders as a space. The learn pipeline runs
 *   `wpautop()` on classic-editor HTML (`HtmlFallbackBlockTransformer`),
 *   which would turn a `\n` inside a paragraph into `<br />`.
 *
 * @author Tymofii Synianskyi
 */
final class MarkdownToHtml {

	private const ASSETS_PREFIX = 'assets/';

	private readonly MarkdownParser $parser;

	private readonly HtmlRenderer $renderer;

	public function __construct() {
		$environment = new Environment(
			[
				'html_input'         => 'strip',
				'allow_unsafe_links' => false,
				'renderer'           => [
					'soft_break' => ' ',
				],
			]
		);
		$environment->addExtension( new CommonMarkCoreExtension() );
		$environment->addExtension( new TableExtension() );

		$this->parser   = new MarkdownParser( $environment );
		$this->renderer = new HtmlRenderer( $environment );
	}

	/**
	 * @param string $markdown   One section body.
	 * @param int    $first_line 1-based file line of the body's first line.
	 */
	public function render( string $markdown, int $first_line ): RenderedHtml {
		$document = $this->parser->parse( $markdown );
		/** @var array<string, ImageRef> $images */
		$images = [];

		foreach ( $document->iterator() as $node ) {
			if ( $node instanceof Heading ) {
				$node->setLevel( max( 3, $node->getLevel() - 1 ) );
				continue;
			}

			if ( $node instanceof Image && str_starts_with( $node->getUrl(), self::ASSETS_PREFIX ) ) {
				$path = rawurldecode( $node->getUrl() );
				if ( ! isset( $images[ $path ] ) ) {
					$images[ $path ] = new ImageRef( $node->getUrl(), $path, $this->plain_text( $node ), $this->file_line( $node, $first_line ) );
				}
			}
		}

		return new RenderedHtml( $this->renderer->renderDocument( $document )->getContent(), array_values( $images ) );
	}

	/**
	 * File lines holding what CommonMark parses as raw HTML — an HTML block or
	 * an inline tag. An inline tag reports the first line of its paragraph.
	 * HTML comments never reach here: the document parser blanks them.
	 *
	 * @param string $markdown   One section body.
	 * @param int    $first_line 1-based file line of the body's first line.
	 *
	 * @return list<int> Ascending, without repeats.
	 */
	public function raw_html_lines( string $markdown, int $first_line ): array {
		$lines = [];

		foreach ( $this->parser->parse( $markdown )->iterator() as $node ) {
			if ( $node instanceof HtmlBlock || $node instanceof HtmlInline ) {
				$lines[] = $this->file_line( $node, $first_line );
			}
		}

		$lines = array_values( array_unique( $lines ) );
		sort( $lines );

		return $lines;
	}

	/**
	 * The file line of the nearest enclosing block that knows its start line.
	 */
	private function file_line( Node $node, int $first_line ): int {
		for ( $current = $node; null !== $current; $current = $current->parent() ) {
			if ( $current instanceof AbstractBlock && null !== $current->getStartLine() ) {
				return $first_line + $current->getStartLine() - 1;
			}
		}

		return $first_line;
	}

	/**
	 * The text of an image description, as CommonMark builds the `alt` attribute.
	 */
	private function plain_text( Image $image ): string {
		$text = '';

		foreach ( $image->iterator() as $node ) {
			if ( $node instanceof StringContainerInterface ) {
				$text .= $node->getLiteral();
			} elseif ( $node instanceof Newline ) {
				$text .= ' ';
			}
		}

		return $text;
	}
}
