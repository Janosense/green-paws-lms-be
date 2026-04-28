<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content;

/**
 * Dispatches a {@see ParsedBlock} to the first {@see BlockTransformer} that
 * declares support for its name.
 *
 * Constructed once with an ordered list of strategies — the last entry is
 * expected to be {@see Blocks\HtmlFallbackBlockTransformer}, whose
 * `supports()` always returns `true`. The registry never recurses into
 * `inner_blocks`; per-block transformers walk children themselves when
 * the JSON shape demands it (only `core/list` does so in 5.1a).
 *
 * The transformer list is a closed contract — there is no extensibility
 * filter in 5.1a; that is deliberate per the phase spec.
 *
 * @author Tymofii Synianskyi
 */
final class BlockTransformerRegistry {

	/** @var list<BlockTransformer> */
	private array $transformers;

	/**
	 * @param list<BlockTransformer> $transformers Ordered by priority — first match wins.
	 */
	public function __construct( array $transformers ) {
		$this->transformers = array_values( $transformers );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( ParsedBlock $block ): array {
		foreach ( $this->transformers as $transformer ) {
			if ( $transformer->supports( $block->name ) ) {
				return $transformer->transform( $block );
			}
		}

		// Defensive — if a caller forgets the fallback, return a typed
		// payload rather than throwing. Production wiring (5.1b) always
		// includes HtmlFallbackBlockTransformer last.
		return [
			'type' => 'html',
			'html' => '',
		];
	}
}
