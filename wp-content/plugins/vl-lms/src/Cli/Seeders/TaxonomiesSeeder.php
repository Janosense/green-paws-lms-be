<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Seeders;

use VL\LMS\Cli\SeederContext;
use VL\LMS\Cli\SeederResult;

/**
 * Seeds `vl_category` (hierarchical), `vl_specialty`, and `vl_tag` terms.
 *
 * `vl_difficulty` is intentionally untouched — its three canonical terms
 * (`basic` / `advanced` / `expert`) are owned by `DifficultyTermsInstaller`.
 *
 * Every seeded term carries `vl_demo_seed = '1'` term meta so the reset
 * subcommand can find and remove it without disturbing admin-curated terms.
 *
 * @author Tymofii Synianskyi
 */
final class TaxonomiesSeeder {

	public const string DEMO_META_KEY = 'vl_demo_seed';

	public function run( SeederContext $context ): SeederResult {
		$result = new SeederResult();

		$result->add( $this->seed_categories( $context ) );
		$result->add( $this->seed_flat_terms( $context, 'vl_specialty', $this->specialties() ) );
		$result->add( $this->seed_flat_terms( $context, 'vl_tag', $this->tags() ) );

		return $result;
	}

	/**
	 * Map of slug → term_id for every demo category, including children.
	 *
	 * @return array<string, int>
	 */
	public function category_term_ids(): array {
		$out = [];
		foreach ( array_keys( $this->categories_with_children() ) as $top_name ) {
			$top_slug = sanitize_title( $top_name );
			$top      = get_term_by( 'slug', $top_slug, 'vl_category' );
			if ( $top instanceof \WP_Term ) {
				$out[ $top_slug ] = (int) $top->term_id;
			}
		}
		foreach ( $this->categories_with_children() as $children ) {
			foreach ( $children as $child_name ) {
				$child_slug = sanitize_title( $child_name );
				$child      = get_term_by( 'slug', $child_slug, 'vl_category' );
				if ( $child instanceof \WP_Term ) {
					$out[ $child_slug ] = (int) $child->term_id;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	public function specialty_term_ids(): array {
		return $this->resolve_term_ids( 'vl_specialty', $this->specialties() );
	}

	/**
	 * @return array<string, int>
	 */
	public function tag_term_ids(): array {
		return $this->resolve_term_ids( 'vl_tag', $this->tags() );
	}

	public function difficulty_term_id( string $slug ): int {
		$term = get_term_by( 'slug', $slug, 'vl_difficulty' );
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	private function seed_categories( SeederContext $context ): SeederResult {
		$result = new SeederResult();

		foreach ( $this->categories_with_children() as $top_name => $children ) {
			$top_id = $this->ensure_term( $top_name, 'vl_category', 0, $result );
			if ( 0 === $top_id ) {
				continue;
			}
			foreach ( $children as $child_name ) {
				$this->ensure_term( $child_name, 'vl_category', $top_id, $result );
			}
		}

		$context->log(
			sprintf(
				/* translators: 1: created count, 2: skipped count. */
				__( 'Categories: %1$d created, %2$d skipped.', 'vl-lms' ),
				$result->created,
				$result->skipped
			)
		);

		return $result;
	}

	/**
	 * @param list<string> $names
	 */
	private function seed_flat_terms( SeederContext $context, string $taxonomy, array $names ): SeederResult {
		$result = new SeederResult();
		foreach ( $names as $name ) {
			$this->ensure_term( $name, $taxonomy, 0, $result );
		}

		$context->log(
			sprintf(
				/* translators: 1: taxonomy, 2: created count, 3: skipped count. */
				__( 'Taxonomy %1$s: %2$d created, %3$d skipped.', 'vl-lms' ),
				$taxonomy,
				$result->created,
				$result->skipped
			)
		);

		return $result;
	}

	private function ensure_term( string $name, string $taxonomy, int $parent_id, SeederResult $result ): int {
		$slug     = sanitize_title( $name );
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing instanceof \WP_Term ) {
			$marker = get_term_meta( (int) $existing->term_id, self::DEMO_META_KEY, true );
			if ( '1' === (string) $marker ) {
				++$result->skipped;
				return (int) $existing->term_id;
			}
			// Existing admin-curated term with the same slug: leave it alone,
			// don't tag it as demo, but still return its ID so child posts
			// can attach to it cleanly.
			++$result->skipped;
			return (int) $existing->term_id;
		}

		$insert = wp_insert_term(
			$name,
			$taxonomy,
			[
				'slug'   => $slug,
				'parent' => $parent_id,
			]
		);

		if ( is_wp_error( $insert ) ) {
			++$result->failed;
			$result->messages[] = sprintf(
				/* translators: 1: term name, 2: error message. */
				__( 'Failed to insert term "%1$s": %2$s', 'vl-lms' ),
				$name,
				$insert->get_error_message()
			);
			return 0;
		}

		$term_id = (int) $insert['term_id'];
		update_term_meta( $term_id, self::DEMO_META_KEY, '1' );
		++$result->created;
		return $term_id;
	}

	/**
	 * @param list<string> $names
	 *
	 * @return array<string, int>
	 */
	private function resolve_term_ids( string $taxonomy, array $names ): array {
		$out = [];
		foreach ( $names as $name ) {
			$slug = sanitize_title( $name );
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$out[ $slug ] = (int) $term->term_id;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function categories_with_children(): array {
		return [
			'Кардіологія'          => [],
			'Дерматологія'         => [],
			'Внутрішні хвороби'    => [],
			'Хірургія'             => [ 'Ортопедія', 'Невідкладна хірургія' ],
			'Стоматологія'         => [],
			'Анестезіологія'       => [],
			'Невідкладна допомога' => [],
		];
	}

	/**
	 * @return list<string>
	 */
	private function specialties(): array {
		return [
			'Терапевт',
			'Хірург',
			'Анестезіолог',
			'Дерматолог',
			'Кардіолог',
			'Стоматолог',
			'Лікар невідкладної допомоги',
		];
	}

	/**
	 * @return list<string>
	 */
	private function tags(): array {
		return [
			'дрібні тварини',
			'велика рогата худоба',
			'екзотичні тварини',
			'неонатологія',
			'геріатрія',
			'рентгенографія',
			'УЗД',
			'лабораторна діагностика',
			'фармакологія',
			'реабілітація',
			'поведінкова медицина',
			'інфекційні хвороби',
		];
	}
}
