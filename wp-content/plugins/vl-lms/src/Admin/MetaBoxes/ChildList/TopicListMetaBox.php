<?php

declare(strict_types=1);

namespace VL\LMS\Admin\MetaBoxes\ChildList;

/**
 * Sortable topic list rendered on the `vl_lesson` edit screen.
 *
 * @author Tymofii Synianskyi
 */
class TopicListMetaBox extends AbstractChildListMetaBox {

	public function __construct() {
		parent::__construct( 'vl_lesson', 'vl_topic' );
	}

	public function id(): string {
		return 'vl_lms_topic_list';
	}

	public function title(): string {
		return 'Теми';
	}
}
