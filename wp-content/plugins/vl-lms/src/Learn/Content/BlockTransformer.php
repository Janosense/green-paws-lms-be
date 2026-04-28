<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content;

/**
 * Strategy contract for turning one {@see ParsedBlock} into the JSON shape
 * the frontend block renderer (5.4) expects.
 *
 * Each implementation owns exactly one block name (or, in the case of
 * {@see Blocks\HtmlFallbackBlockTransformer}, every name not claimed
 * upstream). Transformers are pure: no DB calls, no `wp_remote_*`, no
 * hooks. Sanitization is the implementer's responsibility — every HTML
 * fragment emitted MUST round-trip through `wp_kses_post`.
 *
 * @author Tymofii Synianskyi
 */
interface BlockTransformer {

	public function supports( string $block_name ): bool;

	/**
	 * @return array<string, mixed>
	 */
	public function transform( ParsedBlock $block ): array;
}
