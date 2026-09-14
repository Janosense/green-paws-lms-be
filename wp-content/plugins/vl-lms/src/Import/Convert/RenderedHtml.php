<?php

declare(strict_types=1);

namespace VL\LMS\Import\Convert;

/**
 * HTML rendered from course Markdown, with the `assets/…` images it references.
 *
 * @author Tymofii Synianskyi
 */
final readonly class RenderedHtml {

	/**
	 * @param string         $html   Plain HTML for the classic-editor path, never block markup.
	 * @param list<ImageRef> $images One per archive path, in order of first reference.
	 */
	public function __construct(
		public string $html,
		public array $images
	) {
	}

	/**
	 * Concatenates the parts in order; an image referenced by several parts
	 * is kept once, with its first reference.
	 */
	public static function join( self ...$parts ): self {
		$html = '';
		/** @var array<string, ImageRef> $images */
		$images = [];

		foreach ( $parts as $part ) {
			$html .= $part->html;
			foreach ( $part->images as $image ) {
				$images[ $image->path ] ??= $image;
			}
		}

		return new self( $html, array_values( $images ) );
	}
}
