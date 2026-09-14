<?php

declare(strict_types=1);

namespace VL\LMS\Import\Write;

use WP_Post;

/**
 * Everything one import run has created, in creation order — the only way
 * a failed import undoes its writes (`docs/DECISIONS.md` 2026-09-11 — a
 * failed import rolls back by compensating deletes).
 *
 * Parents are always created before their children, so deleting in reverse
 * order removes children first. Taxonomy terms are never recorded: they are
 * shared vocabulary and survive a rollback.
 *
 * @author Tymofii Synianskyi
 */
final class ImportLedger {

	private const ATTACHMENT = 'attachment';

	/**
	 * @var list<array{type: string, id: int, title: string}>
	 */
	private array $entries = [];

	public function record_post( string $post_type, int $id, string $title ): void {
		$this->entries[] = [
			'type'  => $post_type,
			'id'    => $id,
			'title' => $title,
		];
	}

	public function record_attachment( int $id, string $title ): void {
		$this->entries[] = [
			'type'  => self::ATTACHMENT,
			'id'    => $id,
			'title' => $title,
		];
	}

	/**
	 * Force-deletes every recorded post and attachment, newest first, and
	 * empties the ledger. Attachments go through `wp_delete_attachment()` so
	 * their files are removed too.
	 *
	 * @return list<int> The ids whose delete did not succeed — whatever they are, the import left them behind.
	 */
	public function rollback(): array {
		$leftovers = [];

		foreach ( array_reverse( $this->entries ) as $entry ) {
			$deleted = self::ATTACHMENT === $entry['type']
				? wp_delete_attachment( $entry['id'], true )
				: wp_delete_post( $entry['id'], true );

			if ( ! $deleted instanceof WP_Post ) {
				$leftovers[] = $entry['id'];
			}
		}

		$this->entries = [];

		return $leftovers;
	}

	/**
	 * @return list<array{type: string, id: int, title: string}> In creation order; `type` is the post type or `attachment`.
	 */
	public function summary(): array {
		return $this->entries;
	}
}
