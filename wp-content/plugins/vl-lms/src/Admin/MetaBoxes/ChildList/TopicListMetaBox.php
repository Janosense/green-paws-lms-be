<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable topic list rendered on the `vl_lesson` edit screen.
 *
 * Topics are hidden from the wp-admin menu (`vl_topic` has
 * `show_in_menu: false`), so the add / unlink picker from
 * {@see AbstractPickerListMetaBox} is the primary way to build and manage
 * a lesson's topics: a "Додати тему" button, an existing-topic search
 * that re-parents an unattached topic to this lesson, and a per-row
 * "Відкріпити" detach ({@see \VL\LMS\Admin\Topics\TopicPickerAjaxHandler}).
 *
 * @author Tymofii Synianskyi
 */
class TopicListMetaBox extends AbstractPickerListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_lesson', 'vl_topic', 'topic' );
	}

	public function id(): string {
		return 'vl_lms_topic_list';
	}

	public function title(): string {
		return 'Теми';
	}

	protected function add_new_label(): string {
		return (string) __( 'Додати тему', 'vl-lms' );
	}

	protected function search_label(): string {
		return (string) __( 'Або обрати існуючу тему', 'vl-lms' );
	}

	protected function search_placeholder(): string {
		return (string) __( 'Назва теми', 'vl-lms' );
	}
}
