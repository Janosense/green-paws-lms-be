<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Content\Blocks;

use VL\LMS\Learn\Content\BlockTransformer;
use VL\LMS\Learn\Content\ParsedBlock;

/**
 * Transforms a `core/separator` block into a discriminator-only `{type: separator}` shape.
 *
 * Carries no payload — the frontend renders an `<hr>` directly.
 *
 * @author Tymofii Synianskyi
 */
final class SeparatorBlockTransformer implements BlockTransformer {

	public function supports( string $block_name ): bool {
		return 'core/separator' === $block_name;
	}

	/**
	 * @return array{type:string}
	 */
	public function transform( ParsedBlock $block ): array {
		return [ 'type' => 'separator' ];
	}
}
