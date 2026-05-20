/* global jQuery, VL_LMS_GROUPS */
( function ( $ ) {
	'use strict';

	if ( typeof VL_LMS_GROUPS === 'undefined' ) {
		return;
	}

	var DEBOUNCE_MS = 250;
	var MIN_QUERY_LEN = 2;

	function debounce( fn, wait ) {
		var t = null;
		return function () {
			var args = arguments;
			var ctx = this;
			if ( t ) {
				clearTimeout( t );
			}
			t = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	/**
	 * Bind a single picker. `$root` is the form wrapping a search input,
	 * a hidden id input, and a results span. `cfg` carries the ajax
	 * action / nonce / label/value field names.
	 */
	function bindPicker( $root, cfg ) {
		var $search = $root.find( cfg.searchSelector );
		var $hidden = $root.find( cfg.hiddenSelector );
		var $results = $root.find( cfg.resultsSelector );

		if ( ! $search.length || ! $hidden.length ) {
			return;
		}

		function clearResults() {
			$results.empty().hide();
		}

		function renderResults( items ) {
			$results.empty();
			if ( ! items || ! items.length ) {
				$results.append( $( '<div/>' ).addClass( 'vl-admin-search-empty' ).text( cfg.emptyText ) );
				$results.show();
				return;
			}
			items.forEach( function ( item ) {
				var $row = $( '<a/>', { href: '#', 'data-id': item.id } )
					.addClass( 'vl-admin-search-result' )
					.text( cfg.formatLabel( item ) );
				$results.append( $row );
			} );
			$results.show();
		}

		function fetch( query ) {
			$.ajax( {
				url: VL_LMS_GROUPS.ajaxUrl,
				method: 'GET',
				dataType: 'json',
				data: {
					action: cfg.action,
					nonce: cfg.nonce,
					q: query
				}
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						renderResults( res.data || [] );
					} else {
						clearResults();
					}
				} )
				.fail( clearResults );
		}

		var run = debounce( function () {
			var q = $.trim( $search.val() );
			if ( q.length < MIN_QUERY_LEN ) {
				clearResults();
				return;
			}
			// User is editing — invalidate any prior pick.
			$hidden.val( '' );
			fetch( q );
		}, DEBOUNCE_MS );

		$search.on( 'input', run );

		$results.on( 'click', '.vl-admin-search-result', function ( ev ) {
			ev.preventDefault();
			var $row = $( this );
			$hidden.val( $row.attr( 'data-id' ) );
			$search.val( $row.text() );
			clearResults();
		} );

		// Hide dropdown when clicking outside.
		$( document ).on( 'click', function ( ev ) {
			if ( ! $.contains( $root[ 0 ], ev.target ) ) {
				clearResults();
			}
		} );
	}

	$( function () {
		$( '.vl-admin-group-add-member' ).each( function () {
			bindPicker( $( this ), {
				action: VL_LMS_GROUPS.actions.students,
				nonce: VL_LMS_GROUPS.nonces.students,
				searchSelector: '.vl-admin-user-search',
				hiddenSelector: '.vl-admin-user-search ~ input[type="hidden"][name="user_id"]',
				resultsSelector: '.vl-admin-user-search-results',
				emptyText: VL_LMS_GROUPS.i18n.noStudents,
				formatLabel: function ( item ) {
					var name = item.name || ( '#' + item.id );
					return item.email ? ( name + ' (' + item.email + ')' ) : name;
				}
			} );
		} );

		$( '.vl-admin-group-add-course' ).each( function () {
			bindPicker( $( this ), {
				action: VL_LMS_GROUPS.actions.courses,
				nonce: VL_LMS_GROUPS.nonces.courses,
				searchSelector: '.vl-admin-course-search',
				hiddenSelector: '.vl-admin-course-search ~ input[type="hidden"][name="course_id"]',
				resultsSelector: '.vl-admin-course-search-results',
				emptyText: VL_LMS_GROUPS.i18n.noCourses,
				formatLabel: function ( item ) {
					return item.title || ( '#' + item.id );
				}
			} );
		} );
	} );
}( jQuery ) );
