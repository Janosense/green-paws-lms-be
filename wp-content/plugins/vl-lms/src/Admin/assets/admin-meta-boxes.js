/* global jQuery, wp, VL_LMS_ADMIN */
( function ( $ ) {
	'use strict';

	function uuidv4() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = Math.random() * 16 | 0;
			var v = c === 'x' ? r : ( r & 0x3 | 0x8 );
			return v.toString( 16 );
		} );
	}

	function bytesHuman( size ) {
		size = parseInt( size, 10 ) || 0;
		if ( size < 1024 ) {
			return size + ' B';
		}
		if ( size < 1024 * 1024 ) {
			return ( size / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( size / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	// --- Cover image picker ---
	$( document ).on( 'click', '.vl-lms-media-pick', function ( ev ) {
		ev.preventDefault();
		var $btn = $( this );
		var $control = $btn.closest( '.vl-lms-media-control' );
		var targetId = $control.attr( 'data-target' );
		var frame = wp.media( {
			title: 'Обрати зображення',
			button: { text: 'Обрати' },
			multiple: false,
			library: { type: 'image' }
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + targetId ).val( att.id );
			var thumbUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$control.find( '.vl-lms-media-thumb' ).html(
				$( '<img/>' ).attr( 'src', thumbUrl ).addClass( 'vl-lms-cover-thumb' )
			);
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.vl-lms-media-clear', function ( ev ) {
		ev.preventDefault();
		var $control = $( this ).closest( '.vl-lms-media-control' );
		$control.find( 'input[type="hidden"]' ).val( 0 );
		$control.find( '.vl-lms-media-thumb' ).empty();
	} );

	// --- Attachment list widget ---
	function readAttachmentItems( $widget ) {
		var items = [];
		$widget.find( '.vl-lms-attachment-item' ).each( function () {
			var $li = $( this );
			items.push( {
				url: $li.attr( 'data-url' ) || '',
				name: $li.attr( 'data-name' ) || '',
				size: parseInt( $li.attr( 'data-size' ), 10 ) || 0
			} );
		} );
		return items;
	}

	function syncAttachmentJson( $widget ) {
		var items = readAttachmentItems( $widget );
		$widget.find( 'input[type="hidden"]' ).val( JSON.stringify( items ) );
	}

	function renderAttachmentRow( item ) {
		return $(
			'<li class="vl-lms-attachment-item">'
			+ '<span class="vl-lms-attachment-name"></span>'
			+ '<span class="vl-lms-attachment-size"></span>'
			+ '<button type="button" class="button vl-lms-attachment-pick">Обрати файл</button>'
			+ '<button type="button" class="button-link vl-lms-attachment-remove">Видалити</button>'
			+ '</li>'
		).attr( {
			'data-url': item.url || '',
			'data-name': item.name || '',
			'data-size': item.size || 0
		} ).find( '.vl-lms-attachment-name' ).text( item.name || '(без назви)' ).end()
			.find( '.vl-lms-attachment-size' ).text( bytesHuman( item.size || 0 ) ).end();
	}

	$( document ).on( 'click', '.vl-lms-attachment-add', function ( ev ) {
		ev.preventDefault();
		var $widget = $( this ).closest( '.vl-lms-attachments' );
		$widget.find( '.vl-lms-attachment-list' ).append( renderAttachmentRow( {} ) );
		syncAttachmentJson( $widget );
	} );

	$( document ).on( 'click', '.vl-lms-attachment-remove', function ( ev ) {
		ev.preventDefault();
		var $widget = $( this ).closest( '.vl-lms-attachments' );
		$( this ).closest( '.vl-lms-attachment-item' ).remove();
		syncAttachmentJson( $widget );
	} );

	$( document ).on( 'click', '.vl-lms-attachment-pick', function ( ev ) {
		ev.preventDefault();
		var $btn = $( this );
		var $li = $btn.closest( '.vl-lms-attachment-item' );
		var $widget = $btn.closest( '.vl-lms-attachments' );
		var frame = wp.media( {
			title: 'Обрати файл',
			button: { text: 'Обрати' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$li.attr( 'data-url', att.url );
			$li.attr( 'data-name', att.filename || att.name || att.title );
			$li.attr( 'data-size', att.filesizeInBytes || 0 );
			$li.find( '.vl-lms-attachment-name' ).text( att.filename || att.name || att.title );
			$li.find( '.vl-lms-attachment-size' ).text( bytesHuman( att.filesizeInBytes || 0 ) );
			syncAttachmentJson( $widget );
		} );
		frame.open();
	} );

	// --- Answers builder ---
	function readAnswers( $widget ) {
		var rows = [];
		$widget.find( '.vl-lms-answer-item' ).each( function () {
			var $li = $( this );
			rows.push( {
				id: $li.attr( 'data-answer-id' ) || uuidv4(),
				text: $li.find( '.vl-lms-answer-text' ).val() || '',
				is_correct: $li.find( '.vl-lms-answer-correct input[type="checkbox"]' ).is( ':checked' ),
				explanation: $li.find( '.vl-lms-answer-explanation' ).val() || ''
			} );
		} );
		return rows;
	}

	function syncAnswersJson( $widget ) {
		$widget.find( 'input[name="_vl_question_answers_json"]' ).val(
			JSON.stringify( readAnswers( $widget ) )
		);
	}

	$( document ).on( 'click', '.vl-lms-answer-add', function ( ev ) {
		ev.preventDefault();
		var $widget = $( this ).closest( '.vl-lms-answers' );
		var $row = $(
			'<li class="vl-lms-answer-item">'
			+ '<input type="text" class="vl-lms-answer-text" placeholder="Текст відповіді" />'
			+ '<label class="vl-lms-answer-correct"><input type="checkbox" /> Правильна</label>'
			+ '<input type="text" class="vl-lms-answer-explanation" placeholder="Пояснення" />'
			+ '<button type="button" class="button-link vl-lms-answer-remove">Видалити</button>'
			+ '</li>'
		).attr( 'data-answer-id', uuidv4() );
		$widget.find( '.vl-lms-answers-list' ).append( $row );
		syncAnswersJson( $widget );
	} );

	$( document ).on( 'click', '.vl-lms-answer-remove', function ( ev ) {
		ev.preventDefault();
		var $widget = $( this ).closest( '.vl-lms-answers' );
		$( this ).closest( '.vl-lms-answer-item' ).remove();
		syncAnswersJson( $widget );
	} );

	$( document ).on( 'input change', '.vl-lms-answer-item input', function () {
		syncAnswersJson( $( this ).closest( '.vl-lms-answers' ) );
	} );

	function applyQuestionTypeVisibility() {
		var $select = $( '#_vl_question_type' );
		if ( ! $select.length ) {
			return;
		}
		var type = $select.val();
		var isText = type === 'text';
		$( 'label[for="_vl_question_match_mode"]' ).closest( '.vl-lms-row' ).toggle( isText );
		$( 'label[for="_vl_question_correct_text"]' ).closest( '.vl-lms-row' ).toggle( isText );
		$( '.vl-lms-answers' ).attr( 'data-question-type', type );
	}

	$( document ).on( 'change', '#_vl_question_type', applyQuestionTypeVisibility );
	$( applyQuestionTypeVisibility );

	// --- Co-instructor search ---
	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this;
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}

	function renderInstructorResults( $list, results ) {
		$list.empty();
		if ( ! results.length ) {
			$list.attr( 'hidden', true );
			return;
		}
		$.each( results, function ( _i, user ) {
			var $li = $( '<li/>' ).attr( 'data-user-id', user.id ).attr( 'data-name', user.name );
			$li.text( user.name + ' (' + user.email + ')' );
			$list.append( $li );
		} );
		$list.removeAttr( 'hidden' );
	}

	$( document ).on( 'input', '#vl-lms-co-search-input', debounce( function () {
		var query = $( this ).val();
		var $list = $( '.vl-lms-co-search-results' );
		if ( ! query || query.length < 2 ) {
			$list.empty().attr( 'hidden', true );
			return;
		}
		if ( typeof VL_LMS_ADMIN === 'undefined' ) {
			return;
		}
		$.ajax( {
			url: VL_LMS_ADMIN.ajaxUrl,
			method: 'GET',
			data: {
				action: VL_LMS_ADMIN.action,
				nonce: VL_LMS_ADMIN.nonce,
				q: query
			}
		} ).done( function ( resp ) {
			if ( resp && resp.success && Array.isArray( resp.data ) ) {
				renderInstructorResults( $list, resp.data );
			}
		} );
	}, 250 ) );

	$( document ).on( 'click', '.vl-lms-co-search-results li', function ( ev ) {
		ev.preventDefault();
		var $li = $( this );
		var userId = parseInt( $li.attr( 'data-user-id' ), 10 );
		var name = $li.attr( 'data-name' );
		if ( ! userId ) {
			return;
		}
		if ( $( '.vl-lms-co-item[data-user-id="' + userId + '"]' ).length ) {
			return;
		}
		var $row = $(
			'<li class="vl-lms-co-item">'
			+ '<input type="hidden" name="vl_co_instructor_ids[]" />'
			+ '<span class="vl-lms-co-name"></span>'
			+ '<span class="vl-lms-co-badge">Ко-інструктор</span>'
			+ '<button type="button" class="button-link vl-lms-co-remove">Видалити</button>'
			+ '</li>'
		).attr( 'data-user-id', userId );
		$row.find( 'input' ).val( userId );
		$row.find( '.vl-lms-co-name' ).text( name );
		$( '.vl-lms-co-list' ).append( $row );
		$( '#vl-lms-co-search-input' ).val( '' );
		$( '.vl-lms-co-search-results' ).empty().attr( 'hidden', true );
	} );

	$( document ).on( 'click', '.vl-lms-co-remove', function ( ev ) {
		ev.preventDefault();
		$( this ).closest( '.vl-lms-co-item' ).remove();
	} );

	// --- Phase 9.1 — drag-drop reorder ---
	function postReorder( $list ) {
		var ids = $list.children( 'li' ).map( function () {
			return parseInt( $( this ).attr( 'data-id' ), 10 );
		} ).get().filter( function ( id ) { return id > 0; } );

		if ( typeof VL_LMS_ADMIN === 'undefined' ) {
			return;
		}

		$.ajax( {
			url: VL_LMS_ADMIN.ajaxUrl,
			method: 'POST',
			data: {
				action: 'vl_lms_reorder',
				nonce: $list.attr( 'data-nonce' ),
				ids: ids
			}
		} ).fail( function ( xhr ) {
			var msg = 'reorder failed';
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
			// eslint-disable-next-line no-console
			console.error( 'vl-lms reorder:', msg );
		} );
	}

	$( function () {
		if ( ! $.fn || ! $.fn.sortable ) {
			return;
		}
		$( 'ul.vl-sortable-list' ).sortable( {
			axis: 'y',
			handle: 'li',
			placeholder: 'vl-sortable-placeholder',
			forcePlaceholderSize: true,
			stop: function () {
				postReorder( $( this ) );
			}
		} );
	} );
} )( jQuery );
