<?php

declare(strict_types=1);

namespace VL\LMS\Cli\Content;

/**
 * Generates valid Gutenberg block markup for seeded lessons and topics.
 *
 * Lessons get 4–7 blocks (intro paragraph, "Що ви дізнаєтесь" list, body
 * paragraphs, optional image/quote/separator/table, closing paragraph).
 * Topics get 2–3 blocks. Block selection is fully deterministic — derived
 * from `$index` — so re-running the seeder produces byte-identical content.
 *
 * The output runs through `parse_blocks()` inside the lesson-player REST
 * pipeline (`Learn\Content\BlockParser` → `BlockTransformerRegistry`), so
 * every emitted block uses the canonical `<!-- wp:* -->` comment shape.
 *
 * @author Tymofii Synianskyi
 */
final class LessonContentBuilder {

	private const string LEVEL_BASIC    = 'basic';
	private const string LEVEL_ADVANCED = 'advanced';
	private const string LEVEL_EXPERT   = 'expert';

	public function __construct( private readonly int $inline_image_id ) {
	}

	/**
	 * Build a full lesson body. Always ends with a closing paragraph block.
	 */
	public function build_lesson( string $title, string $theme, string $level, int $index ): string {
		$blocks = [];

		$blocks[] = $this->intro_paragraph( $title, $theme, $level );
		$blocks[] = $this->learning_outcomes_block( $theme, $level );
		$blocks[] = $this->body_paragraph( $theme, $level, $index );

		if ( 0 === ( $index % 2 ) && $this->inline_image_id > 0 ) {
			$blocks[] = $this->image_block( $title );
		}

		if ( 0 === ( $index % 3 ) ) {
			$blocks[] = $this->quote_block( $theme, $level );
		}

		if ( 0 === ( $index % 4 ) ) {
			$blocks[] = $this->procedure_block( $theme, $level );
		}

		if ( 0 === ( $index % 7 ) ) {
			$blocks[] = $this->differential_table_block();
		}

		$blocks[] = $this->separator_block();
		$blocks[] = $this->closing_paragraph( $theme, $level );

		return implode( "\n\n", $blocks );
	}

	/**
	 * Build a shorter topic body — 2–3 blocks.
	 */
	public function build_topic( string $title, string $theme, string $level, int $index ): string {
		$blocks   = [];
		$blocks[] = $this->intro_paragraph( $title, $theme, $level );

		if ( 0 === ( $index % 2 ) ) {
			$blocks[] = $this->body_paragraph( $theme, $level, $index );
		} else {
			$blocks[] = $this->quote_block( $theme, $level );
		}

		return implode( "\n\n", $blocks );
	}

	private function intro_paragraph( string $title, string $theme, string $level ): string {
		$detail = match ( $level ) {
			self::LEVEL_EXPERT   => sprintf(
				'У цьому уроці розглянемо тонкощі теми «%1$s» у контексті %2$s — від патофізіології до клінічних рішень в умовах невизначеності.',
				$title,
				$theme
			),
			self::LEVEL_ADVANCED => sprintf(
				'У цьому уроці зосередимось на ключових клінічних аспектах теми «%1$s» у межах %2$s — від диференційної діагностики до плану ведення.',
				$title,
				$theme
			),
			default              => sprintf(
				'У цьому уроці ви познайомитесь із темою «%1$s» у контексті %2$s — починаючи з базових понять і поступово рухаючись до клінічного застосування.',
				$title,
				$theme
			),
		};

		return $this->paragraph_block( $detail );
	}

	private function body_paragraph( string $theme, string $level, int $index ): string {
		$candidates = [
			sprintf(
				'Анамнез і фізикальне обстеження у %s залишаються наріжним каменем діагностики. Систематичний підхід зменшує кількість непотрібних додаткових досліджень і дозволяє швидше досягти робочого діагнозу.',
				$theme
			),
			sprintf(
				'Інтерпретація результатів додаткових досліджень у %s повинна враховувати чутливість і специфічність кожного тесту в реальній клінічній популяції, а не лише технічні характеристики.',
				$theme
			),
			sprintf(
				'Комунікація з власником тварини у %s — окрема професійна навичка. Коректно сформульовані очікування знижують ризик непорозумінь щодо прогнозу та вартості лікування.',
				$theme
			),
		];

		$paragraph = $candidates[ $index % count( $candidates ) ];

		if ( self::LEVEL_EXPERT === $level ) {
			$paragraph .= ' На експертному рівні важливо виходити за межі стандартних протоколів, оцінюючи індивідуальні фактори ризику та вибір препаратів з огляду на фармакокінетику в кожній конкретній ситуації.';
		} elseif ( self::LEVEL_ADVANCED === $level ) {
			$paragraph .= ' Слід пам\'ятати про сумісність препаратів і потенційні взаємодії при поліморбідних пацієнтах.';
		}

		return $this->paragraph_block( $paragraph );
	}

	private function learning_outcomes_block( string $theme, string $level ): string {
		$items = [
			sprintf( 'розуміти базові принципи %s та їх застосування у щоденній практиці', $theme ),
			'визначати показання та протипоказання до основних діагностичних і лікувальних втручань',
			'формулювати план ведення пацієнта на основі клінічних даних і доказової медицини',
		];

		if ( self::LEVEL_BASIC !== $level ) {
			$items[] = 'інтерпретувати результати додаткових досліджень з огляду на чутливість і специфічність';
		}

		if ( self::LEVEL_EXPERT === $level ) {
			$items[] = 'обирати оптимальну тактику в нестандартних клінічних ситуаціях, спираючись на патофізіологію';
		}

		$list_items = '';
		foreach ( $items as $item ) {
			$list_items .= '<li>' . $item . "</li>\n";
		}

		return "<!-- wp:heading {\"level\":2} -->\n"
			. "<h2 class=\"wp-block-heading\">Що ви дізнаєтесь</h2>\n"
			. "<!-- /wp:heading -->\n\n"
			. "<!-- wp:list -->\n"
			. "<ul class=\"wp-block-list\">\n" . $list_items . "</ul>\n"
			. '<!-- /wp:list -->';
	}

	private function quote_block( string $theme, string $level ): string {
		$quote = match ( $level ) {
			self::LEVEL_EXPERT   => sprintf(
				'Найважче в %s — не упустити очевидне за пошуком рідкісного.',
				$theme
			),
			self::LEVEL_ADVANCED => sprintf(
				'Клінічне мислення в %s — це здатність відрізнити суттєве від випадкового.',
				$theme
			),
			default              => sprintf(
				'Хороша діагностика в %s починається з уважного огляду пацієнта.',
				$theme
			),
		};
		$attribute = 'клінічні нотатки, ВетКліника';

		return "<!-- wp:quote -->\n"
			. "<blockquote class=\"wp-block-quote\">\n"
			. '<p>' . $quote . "</p>\n"
			. '<cite>' . $attribute . "</cite>\n"
			. "</blockquote>\n"
			. '<!-- /wp:quote -->';
	}

	private function procedure_block( string $theme, string $level ): string {
		$steps = [
			'зібрати анамнез і провести загальний клінічний огляд',
			'виокремити провідну скаргу та сформулювати попередній диференційний ряд',
			'призначити мінімально необхідний перелік додаткових досліджень',
			'переоцінити план з огляду на отримані результати та реакцію на лікування',
		];

		if ( self::LEVEL_EXPERT === $level ) {
			$steps[] = 'обговорити з власником альтернативні сценарії та документувати ухвалене рішення';
		}

		$item_html = '';
		foreach ( $steps as $step ) {
			$item_html .= '<li>' . $step . "</li>\n";
		}

		return "<!-- wp:heading {\"level\":3} -->\n"
			. '<h3 class="wp-block-heading">Алгоритм ведення в межах ' . $theme . "</h3>\n"
			. "<!-- /wp:heading -->\n\n"
			. "<!-- wp:list {\"ordered\":true} -->\n"
			. "<ol class=\"wp-block-list\">\n" . $item_html . "</ol>\n"
			. '<!-- /wp:list -->';
	}

	private function differential_table_block(): string {
		return "<!-- wp:table -->\n"
			. "<figure class=\"wp-block-table\"><table>\n"
			. "<thead><tr><th>Стан</th><th>Ключова ознака</th><th>Перший крок</th></tr></thead>\n"
			. "<tbody>\n"
			. "<tr><td>Гостра декомпенсація</td><td>тахіпное у спокої</td><td>киснева підтримка, стабілізація</td></tr>\n"
			. "<tr><td>Хронічний перебіг</td><td>прогресуюча непереносимість навантаження</td><td>рентгенографія грудної клітки, ЕхоКГ</td></tr>\n"
			. "<tr><td>Метаболічний компонент</td><td>зміни на біохімічній панелі</td><td>корекція електролітів, повторна оцінка</td></tr>\n"
			. "</tbody>\n"
			. "</table></figure>\n"
			. '<!-- /wp:table -->';
	}

	private function image_block( string $title ): string {
		$alt = sprintf( 'Ілюстрація до уроку «%s»', $title );

		return '<!-- wp:image {"id":' . $this->inline_image_id . ",\"sizeSlug\":\"large\"} -->\n"
			. '<figure class="wp-block-image size-large">'
			. '<img src="" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $this->inline_image_id . '"/>'
			. "</figure>\n"
			. '<!-- /wp:image -->';
	}

	private function separator_block(): string {
		return "<!-- wp:separator -->\n"
			. "<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n"
			. '<!-- /wp:separator -->';
	}

	private function closing_paragraph( string $theme, string $level ): string {
		$summary = match ( $level ) {
			self::LEVEL_EXPERT   => sprintf(
				'Підсумок: у складних випадках %s рішення приймається на перетині патофізіології, доказових даних і клінічного контексту конкретного пацієнта.',
				$theme
			),
			self::LEVEL_ADVANCED => sprintf(
				'Ключове повідомлення: системний підхід у %s економить час і знижує ризик діагностичних помилок.',
				$theme
			),
			default              => sprintf(
				'Ключове повідомлення: добре зібраний анамнез і уважний огляд — найдешевші та найкорисніші інструменти у %s.',
				$theme
			),
		};

		return $this->paragraph_block( $summary );
	}

	private function paragraph_block( string $text ): string {
		return "<!-- wp:paragraph -->\n"
			. '<p>' . $text . "</p>\n"
			. '<!-- /wp:paragraph -->';
	}
}
