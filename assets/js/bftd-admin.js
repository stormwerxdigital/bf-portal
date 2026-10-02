/**
 * Trim, without jQuery's helper.
 *
 * $.trim was removed in jQuery 4. WordPress still ships 3.x, where it exists
 * but is deprecated, so every use of it in this file worked right up until it
 * did not, and the failure is silent: the handler throws, the feature does
 * nothing, and nothing on the screen says so. This is null-safe the way
 * $.trim was, which is the whole reason it was being used.
 */
function bftdTrim( v ) {
	return ( null === v || undefined === v ) ? '' : String( v ).trim();
}

/* global jQuery, wp, BFTD */
( function ( $ ) {
	'use strict';

	/* ----------------------------------------------------------------
	 * Repeatable rows
	 * A row set added by the schema is reorderable and removable, and the
	 * indexes are rewritten on every change so a removed row never leaves a
	 * gap that PHP would read back as an empty record.
	 * ---------------------------------------------------------------- */

	function reindex( $wrap ) {
		var section = $wrap.data( 'section' );
		var field   = $wrap.data( 'field' );
		$wrap.find( '.bftd-row' ).each( function ( i ) {
			$( this ).find( 'input, select, textarea' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( ! name ) return;
				$( this ).attr( 'name', name.replace(
					/bftd_rows\[[^\]]*\]\[[^\]]*\]\[[^\]]*\]/,
					'bftd_rows[' + section + '][' + field + '][' + i + ']'
				) );
			} );
		} );
	}

	// Every id a new row needs, filled from a counter that only ever goes up.
	// Reusing one a row before it had would hand the new row an editor that
	// TinyMCE still believes belongs to the old one.
	var uid = 0;
	function stamp( tpl, i ) {
		// One number per PLACEHOLDER NAME, not one per row and not one per
		// occurrence. A row holds more than one thing needing an id of its
		// own, so they cannot share; and a gallery writes its own id twice,
		// on the hidden input and on the wrapper that finds it, so those two
		// cannot differ. Each distinct __uidXXXX__ therefore maps to one new
		// number, wherever it appears.
		var seen = {};
		return tpl.split( '__i__' ).join( i ).replace( /__uid([a-z0-9]+)__/g, function ( all, tag ) {
			if ( ! seen[ tag ] ) seen[ tag ] = 'n' + ( ++uid );
			return seen[ tag ];
		} );
	}

	$( document ).on( 'click', '.bftd-row-add', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-rows' );
		var i     = $wrap.find( '.bftd-row' ).length;
		$wrap.find( '.bftd-rows-body' ).first().append( stamp( $wrap.find( '.bftd-row-tpl' ).html(), i ) );
		reindex( $wrap );
	} );

	$( document ).on( 'click', '.bftd-row-rm', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-rows' );
		var $body = $wrap.find( '.bftd-rows-body' ).first();
		$( this ).closest( '.bftd-row' ).remove();
		if ( ! $body.find( '.bftd-row' ).length ) {
			$body.append( stamp( $wrap.find( '.bftd-row-tpl' ).html(), 0 ) );
		}
		reindex( $wrap );
	} );

	$( function () {
		$( '.bftd-rows-body' ).sortable( {
			handle: '.bftd-drag',
			axis: 'y',
			update: function () {
				reindex( $( this ).closest( '.bftd-rows' ) );
			}
		} );
	} );

	/* ----------------------------------------------------------------
	 * Media pickers
	 * ---------------------------------------------------------------- */

	function pick( multiple, done ) {
		var frame = wp.media( {
			title: multiple ? 'Choose work samples' : 'Choose a file',
			multiple: multiple ? 'add' : false,
			library: { type: 'image' },
			button: { text: 'Use this' }
		} );
		frame.on( 'select', function () {
			done( frame.state().get( 'selection' ).toJSON() );
		} );
		frame.open();
	}

	$( document ).on( 'click', '.bftd-media-pick', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.bftd-media' );
		pick( false, function ( items ) {
			if ( ! items.length ) return;
			$box.find( 'input[type=hidden]' ).val( items[0].id );
			$box.find( '.bftd-media-prev' ).html( '<img alt="" src="' + items[0].url + '">' );
		} );
	} );

	$( document ).on( 'click', '.bftd-media-clear', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.bftd-media' );
		$box.find( 'input[type=hidden]' ).val( '' );
		$box.find( '.bftd-media-prev' ).empty();
	} );

	$( document ).on( 'click', '.bftd-gallery-pick', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.bftd-gallery' );
		pick( true, function ( items ) {
			var $input = $box.find( 'input[type=hidden]' );
			var ids    = ( $input.val() || '' ).split( ',' ).filter( Boolean );
			items.forEach( function ( it ) {
				if ( ids.indexOf( String( it.id ) ) === -1 ) {
					ids.push( String( it.id ) );
					$box.find( '.bftd-gallery-prev' ).append(
						'<span class="bftd-thumb"><img alt="" src="' + ( it.sizes && it.sizes.thumbnail ? it.sizes.thumbnail.url : it.url ) +
						'"><button type="button" class="bftd-thumb-x" data-id="' + it.id + '" aria-label="Remove">&times;</button></span>'
					);
				}
			} );
			$input.val( ids.join( ',' ) );
		} );
	} );

	$( document ).on( 'click', '.bftd-thumb-x', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.bftd-gallery' );
		var id   = String( $( this ).data( 'id' ) );
		var $in  = $box.find( 'input[type=hidden]' );
		$in.val( ( $in.val() || '' ).split( ',' ).filter( function ( v ) { return v && v !== id; } ).join( ',' ) );
		$( this ).closest( '.bftd-thumb' ).remove();
	} );

	/* ----------------------------------------------------------------
	 * The review bar
	 * Nothing here sends on its own. Every send is a click, on copy the
	 * tutor has just read.
	 * ---------------------------------------------------------------- */

	var pending = { post: 0, type: '' };

	function post( action, data ) {
		return $.post( BFTD.ajax_url, $.extend( { action: action, nonce: BFTD.nonce }, data ) );
	}

	$( document ).on( 'click', '.bftd-change-review', function () {
		var $pill = $( this ).closest( '.bftd-change-pill' );
		pending.post = $( this ).closest( '.bftd-change-bar' ).data( 'post' );
		pending.type = $pill.data( 'type' );

		post( 'bftd_change_preview', { post_id: pending.post, type: pending.type } )
			.done( function ( res ) {
				if ( ! res.success ) { window.alert( res.data.message ); return; }
				$( '#bftd-modal-subject' ).val( res.data.subject );
				$( '#bftd-modal-preview' ).val( res.data.preview );
				$( '#bftd-modal-message' ).val( res.data.message );
				$( '.bftd-modal-to' ).text( res.data.to );
				$( '.bftd-modal-status' ).text( '' );
				document.getElementById( 'bftd-change-modal' ).hidden = false;
			} )
			.fail( function ( xhr ) {
				window.alert( ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) || 'That did not work.' );
			} );
	} );

	$( document ).on( 'click', '.bftd-modal-cancel', function () {
		document.getElementById( 'bftd-change-modal' ).hidden = true;
	} );

	$( document ).on( 'click', '.bftd-modal-send', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		$( '.bftd-modal-status' ).text( 'Sending…' );

		post( 'bftd_change_send', {
			post_id: pending.post,
			type: pending.type,
			subject: $( '#bftd-modal-subject' ).val(),
			preview: $( '#bftd-modal-preview' ).val(),
			message: $( '#bftd-modal-message' ).val()
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} ).done( function ( res ) {
			if ( ! res.success ) { $( '.bftd-modal-status' ).text( res.data.message ); return; }
			$( '.bftd-modal-status' ).text( res.data.message );
			$( '.bftd-change-pill[data-type="' + pending.type + '"]' ).remove();
			if ( ! $( '.bftd-change-pill' ).length ) $( '.bftd-change-bar' ).remove();
			window.setTimeout( function () {
				document.getElementById( 'bftd-change-modal' ).hidden = true;
			}, 900 );
		} );
	} );

	$( document ).on( 'click', '.bftd-change-dismiss', function () {
		var $pill = $( this ).closest( '.bftd-change-pill' );
		post( 'bftd_change_dismiss', {
			post_id: $( this ).closest( '.bftd-change-bar' ).data( 'post' ),
			type: $pill.data( 'type' )
		} ).done( function () {
			$pill.remove();
			if ( ! $( '.bftd-change-pill' ).length ) $( '.bftd-change-bar' ).remove();
		} );
	} );

	/* Smooth jump within a long report builder. */
	$( document ).on( 'click', '.bftd-jump a', function ( e ) {
		var el = document.querySelector( $( this ).attr( 'href' ) );
		if ( ! el ) return;
		e.preventDefault();
		el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

} )( jQuery );

/* ==========================================================================
   Priority items and For review, in the editor
   ========================================================================== */
( function ( $ ) {
	'use strict';

	function reindexItems( $wrap ) {
		var list = $wrap.data( 'list' );
		$wrap.find( '.bftd-item-row' ).each( function ( i ) {
			$( this ).find( 'input, select, textarea' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( ! name ) return;
				$( this ).attr( 'name', name.replace(
					/bftd_items\[[^\]]*\]\[[^\]]*\]/,
					'bftd_items[' + list + '][' + i + ']'
				) );
			} );
		} );
	}

	$( document ).on( 'click', '.bftd-item-add', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-items' );
		var tpl   = $wrap.find( '.bftd-item-tpl' ).html();
		var i     = $wrap.find( '.bftd-item-row' ).length;
		$wrap.find( '.bftd-item-rows' ).append( tpl.split( '__i__' ).join( i ) );
		reindexItems( $wrap );
	} );

	$( document ).on( 'click', '.bftd-item-rm', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-items' );
		$( this ).closest( '.bftd-item-row' ).remove();
		reindexItems( $wrap );
	} );

	$( function () {
		$( '.bftd-item-rows' ).sortable( {
			handle: '.bftd-drag',
			axis: 'y',
			update: function () { reindexItems( $( this ).closest( '.bftd-items' ) ); }
		} );
	} );

	/* Confirming retires an item from the family's view. It saves on click
	 * rather than waiting for the post to be saved, because it is a decision
	 * about what a family currently sees, not a draft edit. */
	$( document ).on( 'click', '.bftd-item-confirm', function ( e ) {
		e.preventDefault();
		var $btn  = $( this );
		var $row  = $btn.closest( '.bftd-item-row' );
		var $wrap = $btn.closest( '.bftd-items' );
		var want  = $btn.data( 'confirm' ) ? 1 : 0;

		$btn.prop( 'disabled', true );
		$.post( BFTD.ajax_url, {
			action: 'bftd_confirm_item',
			nonce: BFTD.nonce,
			student_id: $wrap.data( 'student' ),
			list: $wrap.data( 'list' ),
			item_id: $row.data( 'item' ),
			confirm: want
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} ).done( function ( res ) {
			if ( ! res.success ) {
				window.alert( ( res.data && res.data.message ) || 'That did not save.' );
				return;
			}
			$row.toggleClass( 'is-confirmed', !! res.data.confirmed );
			$row.find( 'input[name$="[confirmed]"]' ).val( res.data.confirmed ? 1 : 0 );
			$btn.data( 'confirm', res.data.confirmed ? 0 : 1 )
				.text( res.data.confirmed ? 'Reopen' : 'Mark complete and retire it' );
		} );
	} );

} )( jQuery );

/* ==========================================================================
   Assigning tutors to a report
   A chip list plus a search box over a hidden multi-select. The select is
   still what posts, so the form works with JavaScript off and nothing about
   saving depends on this widget — it only ever reflects and toggles the
   options that are already there.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	function paint( $wrap ) {
		var $select = $wrap.find( '.bftd-assign-select' );
		var $chips  = $wrap.find( '.bftd-chips' );
		var can     = '1' === String( $wrap.data( 'can-assign' ) );

		$chips.empty();

		var $chosen = $select.find( 'option:selected' );
		if ( ! $chosen.length ) {
			// The wording belongs to the field, not to this file.
			$chips.append( $( '<span class="bftd-chip-none description"></span>' )
				.text( $wrap.data( 'empty' ) || 'Nobody yet.' ) );
			return;
		}

		$chosen.each( function () {
			var $o = $( this );
			var $chip = $( '<span class="bftd-chip"></span>' ).attr( 'data-value', $o.val() );
			$chip.append( $( '<span class="bftd-chip-name"></span>' ).text( $o.text() ) );
			$chip.append( $( '<span class="bftd-chip-role"></span>' ).text( $o.data( 'role' ) || '' ) );
			if ( can ) {
				$chip.append( $( '<button type="button" class="bftd-chip-x" aria-label="Remove"><span aria-hidden="true">&times;</span></button>' ) );
			}
			$chips.append( $chip );
		} );
	}

	function results( $wrap, term ) {
		var $select = $wrap.find( '.bftd-assign-select' );
		var $out    = $wrap.find( '.bftd-assign-results' );
		term = bftdTrim( term ).toLowerCase();

		$out.empty();
		if ( ! term ) { $out.attr( 'hidden', true ); return; }

		var hits = 0;
		$select.find( 'option' ).each( function () {
			var $o = $( this );
			if ( $o.is( ':selected' ) ) return;                       // already on
			if ( ( $o.data( 'search' ) || '' ).indexOf( term ) === -1 ) return;
			if ( hits >= 8 ) return;
			hits++;

			var $row = $( '<button type="button" class="bftd-assign-hit"></button>' ).attr( 'data-value', $o.val() );
			$row.append( $( '<span class="bftd-hit-name"></span>' ).text( $o.text() ) );
			$row.append( $( '<span class="bftd-hit-meta"></span>' )
				.text( ( $o.data( 'role' ) || '' ) + ' · ' + ( $o.data( 'email' ) || '' ) ) );
			$out.append( $row );
		} );

		if ( ! hits ) {
			$out.append( $( '<p class="bftd-assign-none description"></p>' ).text( 'Nobody matches that.' ) );
		}
		$out.removeAttr( 'hidden' );
	}

	$( document ).on( 'input', '.bftd-assign-in', function () {
		results( $( this ).closest( '.bftd-assign' ), this.value );
	} );

	$( document ).on( 'click', '.bftd-assign-hit', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-assign' );

		// A field that holds one person replaces rather than adds. Without
		// this the client picker silently becomes a second caregiver list:
		// nothing looks wrong, and the save takes whichever is first.
		if ( 1 === parseInt( $wrap.data( 'max' ), 10 ) ) {
			$wrap.find( '.bftd-assign-select option' ).prop( 'selected', false );
		}

		$wrap.find( '.bftd-assign-select option[value="' + $( this ).data( 'value' ) + '"]' ).prop( 'selected', true );
		$wrap.find( '.bftd-assign-in' ).val( '' );
		$wrap.find( '.bftd-assign-results' ).empty().attr( 'hidden', true );
		paint( $wrap );
		announce( $wrap );
	} );

	$( document ).on( 'click', '.bftd-chip-x', function ( e ) {
		e.preventDefault();
		var $chip = $( this ).closest( '.bftd-chip' );
		var $wrap = $chip.closest( '.bftd-assign' );
		$wrap.find( '.bftd-assign-select option[value="' + $chip.data( 'value' ) + '"]' ).prop( 'selected', false );
		paint( $wrap );
		announce( $wrap );
	} );

	/* Setting an option from script does not fire change, so anything that
	   depends on who is assigned would never hear about it. Painted first,
	   then announced, so listeners see the finished state. */
	function announce( $wrap ) {
		$wrap.find( '.bftd-assign-select' ).trigger( 'change' );
	}

	// Changing the select directly keeps the chips honest.
	$( document ).on( 'change', '.bftd-assign-select', function () {
		paint( $( this ).closest( '.bftd-assign' ) );
	} );

	$( function () {
		$( '.bftd-assign' ).each( function () {
			var $wrap = $( this );

			// PHP already rendered the chips and already hid the select, so
			// there is nothing to do here and nothing to redraw. Repainting
			// anyway would replace identical markup a frame after the page
			// had been shown, which is exactly the flicker this avoids.
			// The repaint is kept only as a repair for markup that predates
			// this, or that arrived from a cache.
			if ( ! $wrap.find( '.bftd-chips' ).children().length ) paint( $wrap );
			$wrap.find( '.bftd-assign-select' ).addClass( 'bftd-assign-hidden' );
		} );
	} );

} )( jQuery );

/* ==========================================================================
   The lesson schedule
   Rows describe a weekly pattern; the dates underneath are generated from
   them and refresh as you type, so you see what you are actually booking
   before you save.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	var timer = null;

	function rules( $wrap ) {
		var out = [];
		$wrap.find( '.bftd-sched-row' ).each( function () {
			var $r = $( this );
			out.push( {
				day:     $r.find( '.bftd-s-day' ).val(),
				time:    $r.find( '.bftd-s-time' ).val(),
				minutes: $r.find( '.bftd-s-min' ).val(),
				from:    $r.find( '.bftd-s-from' ).val(),
				tutor:   $r.find( '.bftd-s-tutor' ).val()
			} );
		} );
		return out;
	}

	function reindex( $wrap ) {
		$wrap.find( '.bftd-sched-row' ).each( function ( i ) {
			$( this ).find( 'input, select' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( ! name ) return;
				$( this ).attr( 'name', name.replace( /bftd_sched\[[^\]]*\]/, 'bftd_sched[' + i + ']' ) );
			} );
		} );
	}

	/* The lessons purchased box lives in the Student details panel, a
	   different metabox entirely, so it is reached from the document rather
	   than from inside the schedule. */
	function $purchased() { return $( '#bftd_student_lessons_bank' ); }

	function tile( $wrap, key ) { return $wrap.find( '.bftd-bank-tile[data-bank="' + key + '"] .bftd-bank-n' ); }

	function num( $el ) { var n = parseInt( $el.text(), 10 ); return isNaN( n ) ? 0 : n; }

	function paintBank( $wrap, bank ) {
		if ( ! bank ) return;
		[ 'purchased', 'used', 'remaining', 'booked' ].forEach( function ( k ) {
			if ( bank[ k ] !== undefined ) tile( $wrap, k ).text( bank[ k ] );
		} );
		overNotice( $wrap, bank );
		// Whatever the server said about purchased, the box on screen wins:
		// somebody may be part way through typing a new figure.
		syncBank( $wrap );
	}

	/* Paid for but with no slot to fall on. Overbooking cannot happen any
	   more, because the pattern stops when the lessons run out; the opposite
	   can, when somebody buys lessons and has not set a weekly slot. */
	function overNotice( $wrap, bank ) {
		var $n = $wrap.find( '.bftd-bank-unbooked' );
		if ( $n.length ) {
			var spare = Math.max( 0, ( parseInt( bank.remaining, 10 ) || 0 ) - ( parseInt( bank.booked, 10 ) || 0 ) );
			$n.find( '.bftd-unbooked-n' ).text( spare );
			if ( spare > 0 ) $n.removeAttr( 'hidden' ); else $n.attr( 'hidden', true );
		}

		var $r = $wrap.find( '.bftd-bank-note' );
		if ( $r.length ) {
			if ( bank.last_label ) {
				$r.find( '.bftd-runs-out' ).text( bank.last_label );
				$r.removeAttr( 'hidden' );
			} else {
				$r.attr( 'hidden', true );
			}
		}
	}

	/**
	 * Re-derive the tiles that depend on the purchased figure, from the
	 * figure as it currently reads on screen.
	 *
	 * Used and booked are facts about lessons taught and lessons scheduled,
	 * so they are left exactly as the server sent them. Only purchased, and
	 * the two numbers that are arithmetic on it, move while somebody types.
	 */
	function syncBank( $wrap ) {
		var $in = $purchased();
		if ( ! $in.length ) return;

		var raw = bftdTrim( $in.val() );
		if ( '' === raw ) return;                       // an empty box is not a zero

		var purchased = Math.max( 0, parseInt( raw, 10 ) || 0 );
		var used      = num( tile( $wrap, 'used' ) );
		var remaining = Math.max( 0, purchased - used );

		tile( $wrap, 'purchased' ).text( purchased );
		tile( $wrap, 'remaining' ).text( remaining );

		// Booked can never exceed remaining now that the schedule stops at
		// the paid for boundary, so clamping it is stating an invariant
		// rather than guessing. Without this the tiles contradict each other
		// for the half second before the server answers.
		var booked = num( tile( $wrap, 'booked' ) );
		if ( booked > remaining ) tile( $wrap, 'booked' ).text( remaining );

		// How many actually land on a slot, and the date the bank runs dry,
		// depend on the weekly pattern, which only the server knows. The
		// tiles above answer instantly; these catch up a moment later.
		refresh( $wrap );
	}

	$( document ).on( 'input change', '#bftd_student_lessons_bank', function () {
		$( '.bftd-sched' ).each( function () { syncBank( $( this ) ); } );
	} );

	// And once on load, so a box and a tile that disagree — a figure typed
	// before a reload, or an older draft — agree from the first moment
	// rather than only after the next keystroke.
	$( function () {
		$( '.bftd-sched' ).each( function () { syncBank( $( this ) ); } );
	} );

	function refresh( $wrap ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			$.post( BFTD.ajax_url, {
				action: 'bftd_schedule_preview',
				purchased: $purchased().length ? $purchased().val() : '',
				nonce: BFTD.nonce,
				student_id: $wrap.data( 'student' ),
				rules: JSON.stringify( rules( $wrap ) )
			} ).done( function ( res ) {
				if ( ! res.success ) return;
				$wrap.find( '.bftd-occ-wrap' ).html( res.data.html );
				paintBank( $wrap, res.data.bank );
			} );
		}, 350 );
	}

	$( document ).on( 'change input', '.bftd-sched-rules input, .bftd-sched-rules select', function () {
		refresh( $( this ).closest( '.bftd-sched' ) );
	} );

	$( document ).on( 'click', '.bftd-sched-add', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-sched' );
		var tpl   = $wrap.find( '.bftd-sched-tpl' ).html();
		var i     = $wrap.find( '.bftd-sched-row' ).length;
		$wrap.find( '.bftd-sched-rules tbody' ).append( tpl.split( '__i__' ).join( i ) );
		reindex( $wrap );
		refresh( $wrap );
	} );

	$( document ).on( 'click', '.bftd-sched-rm', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-sched' );
		$( this ).closest( 'tr' ).remove();
		reindex( $wrap );
		refresh( $wrap );
	} );

	/* One occurrence: cancel, move, or undo either. */
	/**
	 * Returns a promise, because the server can refuse: a move onto a day the
	 * tutor has already filled comes back 409, and the dialog has to stay
	 * open and say so rather than close as though it worked.
	 */
	function except( $wrap, key, what, extra ) {
		return $.post( BFTD.ajax_url, $.extend( {
			action: 'bftd_schedule_except',
			nonce: BFTD.nonce,
			student_id: $wrap.data( 'student' ),
			purchased: $purchased().length ? $purchased().val() : '',
			key: key,
			what: what
		}, extra || {} ) ).done( function ( res ) {
			if ( ! res || ! res.success ) return;
			$wrap.find( '.bftd-occ-wrap' ).html( res.data.html );
			paintBank( $wrap, res.data.bank );
		} );
	}

	/** Whatever the server said went wrong, in words. */
	function refusal( xhr ) {
		var msg = 'That did not save.';
		try {
			if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
		} catch ( e ) {}
		return msg;
	}

	/* ----------------------------------------------------------------
	 * Rescheduling, with a month grid.
	 *
	 * The dialog is filled when it opens and cleared when it closes, so one
	 * copy serves every row and nothing carries over from the last time.
	 * ---------------------------------------------------------------- */

	var resched = { wrap: null, key: '', ym: '', picked: '', cell: null, mins: 50 };

	function $modal() { return $( '.bftd-resched' ); }

	function drawMonth( data ) {
		var $m = $modal();
		$m.find( '.bftd-cal-label' ).text( data.label );
		resched.ym = data.ym;
		$m.find( '.bftd-cal-prev' ).data( 'ym', data.prev );
		$m.find( '.bftd-cal-next' ).data( 'ym', data.next );

		var html = '';
		[ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ].forEach( function ( d ) {
			html += '<span class="bftd-cal-dow">' + d + '</span>';
		} );

		data.cells.forEach( function ( c ) {
			if ( ! c ) { html += '<span class="bftd-cal-pad"></span>'; return; }

			var cls = 'bftd-cal-day';
			if ( c.status ) cls += ' is-' + c.status;
			if ( c.past )  cls += ' is-past';
			if ( c.today ) cls += ' is-today';
			if ( c.full )  cls += ' is-full';
			if ( c.date === resched.picked ) cls += ' is-pick';

			var bits = [];
			if ( c.times.length ) bits.push( c.times.join( ', ' ) );
			if ( c.full ) bits.push( 'The tutor already has ' + c.busy + ' sessions that day, which is a full day.' );

			var title = bits.join( '. ' );
			html += '<button type="button" class="' + cls + '" data-date="' + c.date + '"' +
				( c.full ? ' disabled aria-disabled="true"' : '' ) +
				( title ? ' title="' + title + '"' : '' ) + '>' +
				'<span class="bftd-cal-n">' + c.day + '</span>' +
				( c.times.length ? '<span class="bftd-cal-tick"></span>' : '' ) +
				'</button>';
		} );

		$m.find( '.bftd-cal-grid' ).html( html );
		warnIfBusy( data );
	}

	function mins( hhmm ) {
		var m = /^(\d{1,2}):(\d{2})$/.exec( String( hhmm || '' ) );
		return m ? ( parseInt( m[1], 10 ) * 60 + parseInt( m[2], 10 ) ) : null;
	}

	/* The lesson the picked time would run over, if any. Back to back is not
	   a clash, so both comparisons are strict. */
	function clashAt( cell, hhmm, length ) {
		var start = mins( hhmm );
		if ( null === start || ! cell || ! cell.taken ) return null;
		var end = start + ( length || 50 );
		for ( var i = 0; i < cell.taken.length; i++ ) {
			var t = cell.taken[ i ];
			if ( start < t.end && t.start < end ) return t;
		}
		return null;
	}

	function warnIfBusy( data ) {
		var $m    = $modal();
		var $warn = $m.find( '.bftd-resched-warn' );
		if ( ! resched.picked ) { $warn.attr( 'hidden', true ); return; }

		var hit = null, cell = null;
		data.cells.forEach( function ( c ) {
			if ( ! c || c.date !== resched.picked ) return;
			cell = c;
			if ( c.times.length ) hit = c;
		} );

		resched.cell = cell;
		resched.mins = data.mins || 50;

		// Two places at once is impossible, so it is said first and it is
		// said even when the day is nowhere near full.
		var over = clashAt( cell, $m.find( '.bftd-resched-time' ).val(), resched.mins );
		if ( over ) {
			$warn.text( 'The tutor is already teaching at ' + over.time +
				' that day, and cannot take two sessions at once. Pick another time.' )
				.removeAttr( 'hidden' );
		} else if ( cell && cell.full ) {
			$warn.text( 'That day is full: the tutor already has ' + cell.busy +
				' sessions booked, which is the most anyone teaches in a day. Pick another date.' )
				.removeAttr( 'hidden' );
		} else if ( hit ) {
			$warn.text( 'There is already a session that day at ' + hit.times.join( ', ' ) +
				'. That may be fine, but it is worth a look.' ).removeAttr( 'hidden' );
		} else {
			$warn.attr( 'hidden', true );
		}
	}

	function loadMonth( ym ) {
		$.post( BFTD.ajax_url, {
			action: 'bftd_schedule_month',
			nonce: BFTD.nonce,
			student_id: resched.wrap.data( 'student' ),
			ym: ym || ''
		} ).done( function ( res ) {
			if ( res.success ) drawMonth( res.data );
		} );
	}

	function openResched( $row ) {
		resched.wrap   = $row.closest( '.bftd-sched' );
		resched.key    = String( $row.data( 'key' ) );
		resched.picked = '';

		var date = resched.key.slice( 0, 10 );
		var time = resched.key.slice( 11 );

		var $m = $modal();
		$m.find( '.bftd-resched-from' ).text(
			'Currently ' + $row.find( '.bftd-occ-date' ).text() + ' at ' + $row.find( '.bftd-occ-time' ).text() + '.'
		);
		$m.find( '.bftd-resched-date' ).val( '' );
		$m.find( '.bftd-resched-time' ).val( time );
		$m.find( '.bftd-resched-reason' ).val( '' );
		$m.find( '.bftd-resched-warn' ).attr( 'hidden', true );
		$m[0].hidden = false;

		loadMonth( date.slice( 0, 7 ) );
		$m.find( '.bftd-resched-date' ).trigger( 'focus' );
	}

	function closeResched() {
		$modal()[0].hidden = true;
		resched = { wrap: null, key: '', ym: '', picked: '' };
	}

	$( document ).on( 'click', '.bftd-occ-move', function () {
		openResched( $( this ).closest( '.bftd-occ-row' ) );
	} );

	$( document ).on( 'click', '.bftd-cal-day', function () {
		resched.picked = String( $( this ).data( 'date' ) );
		$modal().find( '.bftd-resched-date' ).val( resched.picked );
		$modal().find( '.bftd-cal-day' ).removeClass( 'is-pick' );
		$( this ).addClass( 'is-pick' );
		loadMonth( resched.ym );   // redraw so the warning re-evaluates
	} );

	/* The clash depends on the time as much as on the day. */
	$( document ).on( 'input change', '.bftd-resched-time', function () {
		var $m    = $modal();
		var $warn = $m.find( '.bftd-resched-warn' );
		var over  = clashAt( resched.cell, this.value, resched.mins );
		if ( over ) {
			$warn.text( 'The tutor is already teaching at ' + over.time +
				' that day, and cannot take two sessions at once. Pick another time.' )
				.removeAttr( 'hidden' );
		} else if ( resched.cell && resched.cell.full ) {
			$warn.text( 'That day is full: the tutor already has ' + resched.cell.busy +
				' sessions booked, which is the most anyone teaches in a day. Pick another date.' )
				.removeAttr( 'hidden' );
		} else {
			$warn.attr( 'hidden', true );
		}
	} );

	/* Typing a date directly is still allowed; the grid follows it. */
	$( document ).on( 'change', '.bftd-resched-date', function () {
		var v = this.value;
		if ( ! v ) return;
		resched.picked = v;
		loadMonth( v.slice( 0, 7 ) );
	} );

	$( document ).on( 'click', '.bftd-cal-prev, .bftd-cal-next', function () {
		loadMonth( $( this ).data( 'ym' ) );
	} );

	$( document ).on( 'click', '.bftd-resched-save', function () {
		var $m   = $modal();
		var date = $m.find( '.bftd-resched-date' ).val();
		var time = $m.find( '.bftd-resched-time' ).val();

		if ( ! date || ! time ) {
			$m.find( '.bftd-resched-warn' ).text( 'Pick a date and a time first.' ).removeAttr( 'hidden' );
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );
		except( resched.wrap, resched.key, 'move', {
			date: date, time: time, reason: $m.find( '.bftd-resched-reason' ).val()
		} ).done( function () {
			closeResched();
		} ).fail( function ( xhr ) {
			$m.find( '.bftd-resched-warn' ).text( refusal( xhr ) ).removeAttr( 'hidden' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '.bftd-resched-cancel-lesson', function () {
		except( resched.wrap, resched.key, 'cancel', {
			reason: $modal().find( '.bftd-resched-reason' ).val()
		} ).fail( function ( xhr ) {
			resched.wrap.find( '.bftd-occ-wrap' ).prepend(
				$( '<p class="bftd-occ-warn"></p>' ).text( refusal( xhr ) ) );
		} );
		closeResched();
	} );

	$( document ).on( 'click', '.bftd-resched-close', closeResched );

	$( document ).on( 'click', '.bftd-resched', function ( e ) {
		if ( e.target === this ) closeResched();          // click the backdrop
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && $modal().length && ! $modal()[0].hidden ) closeResched();
	} );

	$( document ).on( 'click', '.bftd-occ-cancel', function () {
		var $row = $( this ).closest( '.bftd-occ-row' );
		openResched( $row );
		// Same dialog, opened on the cancel path: the reason field is the
		// thing that matters, and cancelling is one click from here.
		$modal().find( '.bftd-resched-reason' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '.bftd-occ-undo', function () {
		var $row = $( this ).closest( '.bftd-occ-row' );
		var $w = $( this ).closest( '.bftd-sched' );
		except( $w, $row.data( 'key' ), 'clear' ).fail( function ( xhr ) {
			$w.find( '.bftd-occ-wrap' ).prepend( $( '<p class="bftd-occ-warn"></p>' ).text( refusal( xhr ) ) );
		} );
	} );

} )( jQuery );

/* ==========================================================================
   Picking a tutor for one slot
   A search box over a fixed list. The hidden input is what posts, so the form
   works with scripts off and nothing about saving depends on this; the widget
   only ever reads and writes that one value.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	function $val( $p )  { return $p.find( '.bftd-pick-v' ); }
	function $text( $p ) { return $p.find( '.bftd-pick-in' ); }

	/* The label for whatever is currently chosen. Recomputed rather than
	   remembered, so it stays right when the student is reassigned. */
	function labelFor( $p, value ) {
		// attr, not data: jQuery caches data() from the attribute the first
		// time it is read and then ignores later attribute changes, so a
		// relabelled default would keep showing the old tutor's name.
		var $opt = $p.find( '.bftd-pick-opt[data-value="' + value + '"]' );
		if ( $opt.length ) return $opt.attr( 'data-label' );
		return $p.attr( 'data-default' );
	}

	function show( $p, term ) {
		var $list = $p.find( '.bftd-pick-list' );
		var $none = $list.find( '.bftd-pick-none' );
		term = bftdTrim( term || '' ).toLowerCase();

		// Counted separately from the default, which is always shown and so
		// would otherwise look like a match. Without that, a field with no
		// default at all reported "nobody matches" next to the one person who
		// did.
		var hits = 0;
		$list.find( '.bftd-pick-opt' ).each( function () {
			var $o        = $( this );
			var isDefault = ( '0' === String( $o.attr( 'data-value' ) ) );
			var matches   = ! term || ( String( $o.attr( 'data-search' ) || '' ).indexOf( term ) !== -1 );

			// The default is always offered: it is how an override is undone.
			$o.toggle( isDefault || matches ).removeClass( 'is-on' );
			if ( ! isDefault && matches ) hits++;
		} );

		$none.prop( 'hidden', hits > 0 || ! term );
		$list.removeAttr( 'hidden' );
		$text( $p ).attr( 'aria-expanded', 'true' );
	}

	function close( $p ) {
		$p.find( '.bftd-pick-list' ).attr( 'hidden', true );
		$text( $p ).attr( 'aria-expanded', 'false' );
		// A half typed search must not be left sitting there looking chosen.
		paintPick( $p );
	}

	function paintPick( $p ) {
		var value = String( $val( $p ).val() || '0' );
		$text( $p ).val( labelFor( $p, value ) ).toggleClass( 'is-set', '0' !== value );
		$p.find( '.bftd-pick-x' ).prop( 'hidden', '0' === value );
	}

	function choose( $p, value ) {
		$val( $p ).val( value );
		paintPick( $p );
		close( $p );
		// The schedule preview listens for this, and a value set by script
		// does not announce itself.
		$val( $p ).trigger( 'change' );
	}

	$( document ).on( 'focus click', '.bftd-pick-in', function () {
		var $p = $( this ).closest( '.bftd-pick' );
		this.select();
		show( $p, '' );
	} );

	$( document ).on( 'input', '.bftd-pick-in', function () {
		show( $( this ).closest( '.bftd-pick' ), this.value );
	} );

	$( document ).on( 'mousedown', '.bftd-pick-opt', function ( e ) {
		e.preventDefault();                       // beat the blur
		var $p = $( this ).closest( '.bftd-pick' );
		choose( $p, String( $( this ).data( 'value' ) ) );
	} );

	$( document ).on( 'click', '.bftd-pick-x', function ( e ) {
		e.preventDefault();
		choose( $( this ).closest( '.bftd-pick' ), '0' );
	} );

	$( document ).on( 'keydown', '.bftd-pick-in', function ( e ) {
		var $p    = $( this ).closest( '.bftd-pick' );
		var $opts = $p.find( '.bftd-pick-opt:visible' );
		var i     = $opts.index( $opts.filter( '.is-on' ) );

		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			e.preventDefault();
			if ( ! $opts.length ) return;
			i = ( 'ArrowDown' === e.key ) ? i + 1 : i - 1;
			if ( i < 0 ) i = $opts.length - 1;
			if ( i >= $opts.length ) i = 0;
			$opts.removeClass( 'is-on' ).eq( i ).addClass( 'is-on' )[0].scrollIntoView( { block: 'nearest' } );
			return;
		}

		if ( 'Enter' === e.key ) {
			var $pick = i >= 0 ? $opts.eq( i ) : $opts.first();
			if ( $pick.length ) { e.preventDefault(); choose( $p, String( $pick.data( 'value' ) ) ); }
			return;
		}

		if ( 'Escape' === e.key ) { e.preventDefault(); close( $p ); this.blur(); }
	} );

	$( document ).on( 'blur', '.bftd-pick-in', function () {
		var $p = $( this ).closest( '.bftd-pick' );
		window.setTimeout( function () { close( $p ); }, 120 );
	} );

	/* Reassigning the student rewrites what the default means, and every slot
	   still on the default should say so without a page reload. */
	function refreshDefaults() {
		// Selected by class rather than by name: jQuery cannot parse the
		// square brackets in name="bftd_staff[]" inside an attribute
		// selector, and fails quietly rather than loudly.
		var $sel = $( '.bftd-assign-select' ).filter( function () {
			return 0 === String( this.name || '' ).indexOf( 'bftd_staff' );
		} );
		if ( ! $sel.length ) return;

		var $first = $sel.find( 'option:selected' ).first();
		var label  = $first.length ? 'Assigned (' + $first.text() + ')' : 'Assigned';

		$( '.bftd-pick' ).each( function () {
			var $p = $( this );
			$p.attr( 'data-default', label );
			$p.find( '.bftd-pick-opt[data-value="0"]' )
				.attr( 'data-label', label )
				.find( '.bftd-pick-name' ).text( label );
			paintPick( $p );
		} );
	}

	$( document ).on( 'change', '.bftd-assign-select', refreshDefaults );

	/* So other code can set a picker without knowing how it is built. */
	$.fn.bftdSetPerson = function ( value ) {
		return this.each( function () { choose( $( this ), String( value ) ); } );
	};

	$( function () {
		$( '.bftd-pick' ).each( function () { paintPick( $( this ) ); } );
	} );

} )( jQuery );

/* ==========================================================================
   Filling in a report's heading
   Choosing a student answers two questions the person would otherwise have to
   type: whose report this is, and who taught it. Both are filled in, and
   neither is forced: anything already typed is left alone, because a title
   somebody wrote by hand is a deliberate act.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	var $student = $( '#bftd_student' );
	if ( ! $student.length ) return;

	var $title = $( '#title' );
	var $sub   = $( '#bftd_subtitle' );

	/* What the fields said when the page loaded. Anything matching this is
	   ours to replace; anything else was typed by a person. */
	var filled = { title: $title.val(), sub: $sub.val() };

	function ours( $el, key ) {
		var now = bftdTrim( $el.val() );
		return '' === now || now === bftdTrim( filled[ key ] );
	}

	function apply( data ) {
		if ( $title.length && ours( $title, 'title' ) ) {
			$title.val( data.title ).trigger( 'change' );
			filled.title = data.title;
			// WordPress hides its own placeholder label by hand.
			$( '#title-prompt-text' ).addClass( 'screen-reader-text' );
		}
		// The subheading is not typed, so there is nothing of anybody's to
		// protect here: the assignment is the answer and this follows it.
		// The line stays hidden until there is a name to put in it.
		if ( $sub.length ) {
			$sub.val( data.subtitle );
			filled.sub = data.subtitle;
			$( '.bftd-subtitle-line' ).text( data.subtitle );
			$( '.bftd-subtitle' ).toggleClass( 'is-empty', '' === bftdTrim( data.subtitle ) );
		}
		prefill( data.prefill );
	}

	/**
	 * Fill in the report fields the student record already answers.
	 *
	 * Only ever into an empty field. Something already written there is
	 * somebody's work, and a diagnostic is a record of a day rather than a
	 * live view of the student, so a filled in value is theirs to correct
	 * and nothing here touches it again.
	 */
	function prefill( sections ) {
		if ( ! sections ) return;
		var touched = 0;

		$.each( sections, function ( sectionId, fields ) {
			$.each( fields, function ( key, value ) {
				var id  = 'bftd_' + String( sectionId ).replace( /-/g, '_' ) + '_' + key;
				var $el = $( document.getElementById( id ) );
				if ( ! $el.length ) return;

				var $pick = $el.closest( '.bftd-pick' );
				if ( $pick.length ) {
					if ( parseInt( $pick.find( '.bftd-pick-v' ).val(), 10 ) ) return;   // already somebody
					$pick.bftdSetPerson( value );
					touched++;
					return;
				}

				if ( bftdTrim( $el.val() ) ) return;                                     // already answered
				$el.val( value ).trigger( 'change' );
				touched++;
			} );
		} );

		if ( touched ) note( touched );
	}

	/* Say what happened, once, rather than letting fields change silently. */
	function note( n ) {
		var $n = $( '.bftd-prefilled' );
		if ( ! $n.length ) {
			$n = $( '<p class="bftd-prefilled description"></p>' ).insertAfter( $( '.bftd-subtitle' ) );
		}
		$n.text( n + ( 1 === n ? ' field was' : ' fields were' ) +
			' filled in from the student record. Change any of them if the assessment found otherwise.' )
			.stop( true, true ).hide().fadeIn( 160 );
	}

	$student.on( 'change', function () {
		var id = parseInt( this.value, 10 ) || 0;
		if ( ! id ) return;

		$.post( BFTD.ajax_url, {
			action: 'bftd_student_header',
			nonce: BFTD.nonce,
			student_id: id,
			post_type: $( '#post_type' ).val() || ''
		} ).done( function ( res ) {
			if ( res && res.success ) apply( res.data );
		} );
	} );

	// A report opened from the student hub arrives with the student already
	// chosen and both fields empty, so it fills in without being touched.
	if ( ( parseInt( $student.val(), 10 ) || 0 ) && ! bftdTrim( $title.val() ) ) {
		$student.trigger( 'change' );
	}

} )( jQuery );

/* ==========================================================================
   Dropping files into a report

   A tutor writing up an assessment has the child's work on their desktop: a
   photo of the handwriting page, a scan of the spelling test. Asking them to
   go through Add Media, upload, find it in the grid and insert it is four
   steps for something they already have in their hand.

   WordPress gives the main post editor drag and drop for free. It does not
   give it to an editor inside a meta box, which is every editor on these
   screens, so this wires it up: drop anywhere over the editor, the file
   uploads to the media library the same way Add Media would, and lands at
   the caret. Images go in as images; anything else goes in as a link, named
   after the file, because a PDF of a report card is worth attaching even
   though it cannot be shown inline.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	// Everything here needs the media library's own uploader nonce, which is
	// only on the page once wp_enqueue_media() has run. Without it there is
	// no safe way to upload, so the feature simply is not offered.
	function uploadParams() {
		var s = window._wpPluploadSettings;
		if ( s && s.defaults && s.defaults.multipart_params ) return s.defaults.multipart_params;
		return null;
	}

	if ( ! uploadParams() || ! window.FormData || ! window.fetch ) return;

	var MAX_AT_ONCE = 10;

	function isFileDrag( e ) {
		var dt = e.originalEvent && e.originalEvent.dataTransfer;
		if ( ! dt ) return false;
		var types = dt.types;
		if ( ! types ) return false;
		for ( var i = 0; i < types.length; i++ ) {
			if ( 'Files' === types[ i ] ) return true;
		}
		return false;
	}

	/* ---- the overlay that says where the file will land ---- */

	function wrapFor( editorId ) {
		return $( document.getElementById( 'wp-' + editorId + '-wrap' ) );
	}

	function overlayFor( $wrap ) {
		var $o = $wrap.find( '.bftd-drop' );
		if ( ! $o.length ) {
			$o = $( '<div class="bftd-drop" aria-hidden="true">' +
				'<div class="bftd-drop-in"><b>Drop to add</b>' +
				'<span>Images go in where the cursor is. Anything else goes in as a link.</span></div></div>' )
				.appendTo( $wrap );
		}
		return $o;
	}

	/* ---- saying what is happening, because an upload is not instant ---- */

	function noteFor( $wrap ) {
		var $n = $wrap.find( '.bftd-drop-note' );
		if ( ! $n.length ) $n = $( '<div class="bftd-drop-note" role="status"></div>' ).appendTo( $wrap );
		return $n;
	}

	function say( $wrap, text, kind ) {
		var $n = noteFor( $wrap );
		$n.attr( 'class', 'bftd-drop-note' + ( kind ? ' is-' + kind : '' ) ).text( text ).show();
		if ( 'working' !== kind ) {
			clearTimeout( $n.data( 't' ) );
			$n.data( 't', setTimeout( function () { $n.fadeOut( 200 ); }, 'bad' === kind ? 9000 : 3500 ) );
		}
	}

	/* ---- the upload itself ---- */

	function upload( file ) {
		var params = uploadParams();
		var fd = new FormData();
		// The media library's own parameters first, nonce included. They
		// already name the action, so naming it again would send it twice.
		$.each( params, function ( k, v ) { fd.append( k, v ); } );
		if ( ! params.action ) fd.append( 'action', 'upload-attachment' );
		fd.append( 'name', file.name );
		fd.append( 'async-upload', file );

		return window.fetch( window.ajaxurl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			// WordPress answers 200 with success:false for a rejected file
			// type or a file over the server's limit, so the status code on
			// its own is not the answer.
			if ( ! res || ! res.success || ! res.data ) {
				var msg = res && res.data && res.data.message ? res.data.message : 'the server would not take it';
				throw new Error( msg );
			}
			return res.data;
		} );
	}

	/** An attachment as it should read inside the report. */
	function htmlFor( att ) {
		var url = att.url;
		if ( att.sizes && att.sizes.large && att.sizes.large.url ) url = att.sizes.large.url;
		else if ( att.sizes && att.sizes.medium_large && att.sizes.medium_large.url ) url = att.sizes.medium_large.url;

		if ( 'image' === att.type ) {
			// The media library's title is only ever the filename with the
			// extension taken off, which for a pasted screenshot is a
			// timestamp. Alt text a family hears read aloud should say what
			// the picture is, so a plain description beats that every time.
			var alt = att.alt || 'Work sample';
			return '<img src="' + url + '" alt="' + $( '<i>' ).text( alt ).html() + '" class="wp-image-' + att.id + '" />';
		}
		var label = att.filename || att.title || 'Download';
		return '<a href="' + att.url + '" target="_blank" rel="noopener">' + $( '<i>' ).text( label ).html() + '</a>';
	}

	/**
	 * Put it where the person was typing.
	 *
	 * wp.media.editor.insert respects the caret in both the visual and the
	 * code view and knows how to reach inside the TinyMCE iframe, which
	 * writing into the textarea by hand does not. It goes to whichever editor
	 * is active, so the active one has to be set first, or a drop on one
	 * report section can land in another.
	 */
	function insert( editorId, html ) {
		window.wpActiveEditor = editorId;
		if ( window.wp && wp.media && wp.media.editor ) {
			wp.media.editor.insert( html );
			return;
		}
		var ed = window.tinymce && tinymce.get( editorId );
		if ( ed && ! ed.isHidden() ) ed.execCommand( 'mceInsertContent', false, html );
		else $( document.getElementById( editorId ) ).val( function ( i, v ) { return v + '\n' + html; } );
	}

	function handleFiles( editorId, files ) {
		var $wrap = wrapFor( editorId );
		var list  = Array.prototype.slice.call( files, 0, MAX_AT_ONCE );
		var extra = files.length - list.length;
		if ( ! list.length ) return;

		var done = 0, failed = [];
		say( $wrap, list.length === 1 ? 'Uploading ' + list[ 0 ].name + '…'
			: 'Uploading ' + list.length + ' files…', 'working' );

		// One after another rather than all at once. A tutor dropping six
		// photos from a phone would otherwise fire six large uploads at a
		// shared host at the same moment, and they arrive in a jumbled order.
		list.reduce( function ( chain, file ) {
			return chain.then( function () {
				return upload( file ).then( function ( att ) {
					insert( editorId, htmlFor( att ) );
					done++;
					if ( list.length > 1 ) say( $wrap, 'Added ' + done + ' of ' + list.length + '…', 'working' );
				} ).catch( function ( err ) {
					failed.push( file.name + ' (' + err.message + ')' );
				} );
			} );
		}, Promise.resolve() ).then( function () {
			if ( failed.length ) {
				say( $wrap, 'Could not add ' + failed.join( ', ' ) + '. Everything else went in.', 'bad' );
			} else if ( extra > 0 ) {
				say( $wrap, 'Added ' + done + '. Only ' + MAX_AT_ONCE + ' at a time, so ' + extra +
					( 1 === extra ? ' file was' : ' files were' ) + ' left out.', 'bad' );
			} else {
				say( $wrap, 1 === done ? 'Added.' : 'Added ' + done + ' files.', 'good' );
			}
		} );
	}

	/* ---- pasting ---- */

	/**
	 * A snip on the clipboard is a file with no name.
	 *
	 * Windows Snipping Tool, macOS Shift-Cmd-4, and every screenshot tool in
	 * between put an image on the clipboard and nothing on disk. A tutor's
	 * most common attachment is exactly that, and asking them to save it
	 * somewhere first, then find it again, is the step worth removing.
	 *
	 * The browser hands these over as clipboard items with no filename, so
	 * one gets made: the section and the date, which is more use in a media
	 * library than "image.png" repeated forty times.
	 */
	function pastedImages( e, label ) {
		var cd = e.originalEvent && ( e.originalEvent.clipboardData || window.clipboardData );
		if ( ! cd ) return [];

		var raw = [];

		// Items first. A clipboard image appears in both lists, and only the
		// item list distinguishes a real file from a screenshot with no name.
		var items = cd.items || [];
		for ( var i = 0; i < items.length; i++ ) {
			if ( 'file' !== items[ i ].kind ) continue;
			var got = items[ i ].getAsFile();
			if ( got ) raw.push( got );
		}
		if ( ! raw.length && cd.files ) {
			for ( var f = 0; f < cd.files.length; f++ ) raw.push( cd.files[ f ] );
		}

		// A screenshot arrives either unnamed or as image.png, over and over.
		// Forty files called image.png in a media library is not a library.
		return raw.map( function ( file ) {
			if ( ! file.name || /^(image|screenshot|clipboard)\b/i.test( file.name ) ) {
				return renamed( file, label );
			}
			return file;
		} );
	}

	function renamed( file, label ) {
		var d    = new Date();
		var pad  = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };
		var stem = ( label || 'pasted' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
		var ext  = ( file.type && file.type.split( '/' )[ 1 ] ) || 'png';
		var name = stem + '-' + d.getFullYear() + pad( d.getMonth() + 1 ) + pad( d.getDate() ) +
			'-' + pad( d.getHours() ) + pad( d.getMinutes() ) + pad( d.getSeconds() ) + '.' + ext;

		// A File cannot be renamed, so it is rebuilt. Older browsers have no
		// File constructor, in which case the original goes through with
		// whatever name it came with.
		try {
			return new File( [ file ], name, { type: file.type, lastModified: file.lastModified || Date.now() } );
		} catch ( err ) {
			return file;
		}
	}

	/**
	 * Pasting text must still paste text.
	 *
	 * A clipboard can carry an image and its own HTML at the same time, which
	 * is what copying a picture out of a web page or a Word document gives
	 * you. Taking over the paste in that case would throw away the formatting
	 * somebody meant to keep, so this only intervenes when the clipboard has
	 * a file and no text worth pasting.
	 */
	function pasteIsFileOnly( e ) {
		var cd = e.originalEvent && ( e.originalEvent.clipboardData || window.clipboardData );
		if ( ! cd ) return false;
		var text = '';
		try { text = cd.getData( 'text/plain' ) || ''; } catch ( err ) {}
		var html = '';
		try { html = cd.getData( 'text/html' ) || ''; } catch ( err ) {}
		// An image copied from a web page brings its own <img> markup, which
		// points at a URL the family may not be able to open. That one we do
		// take over, so the picture ends up in this site's media library.
		if ( html && ! /<img\b/i.test( html ) ) return false;
		if ( bftdTrim( text ) ) return false;
		return true;
	}

	/* ---- binding, including inside the visual editor's iframe ---- */

	var over = 0;

	function bind( editorId ) {
		var $wrap = wrapFor( editorId );
		if ( ! $wrap.length || $wrap.data( 'bftd-drop' ) ) return;
		$wrap.data( 'bftd-drop', true );

		var $overlay = overlayFor( $wrap );

		function show() { $wrap.addClass( 'is-dropping' ); }
		function hide() { over = 0; $wrap.removeClass( 'is-dropping' ); }

		$wrap.on( 'dragenter.bftd dragover.bftd', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'copy';
			show();
		} );

		// Leaving a child element fires dragleave on the parent, so a plain
		// dragleave handler flickers the overlay off while the pointer is
		// still inside it. Counting enters and leaves is what stops that.
		$wrap.on( 'dragenter.bftdc', function ( e ) { if ( isFileDrag( e ) ) over++; } );
		$wrap.on( 'dragleave.bftdc', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			over--;
			if ( over <= 0 ) hide();
		} );

		$wrap.on( 'drop.bftd', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			e.stopPropagation();
			hide();
			handleFiles( editorId, e.originalEvent.dataTransfer.files );
		} );

		$overlay.on( 'dragover drop', function ( e ) { e.preventDefault(); } );

		// Code view is a plain textarea, so its paste is caught here. Visual
		// view is an iframe and is caught in bindInsideEditor.
		$wrap.on( 'paste.bftd', 'textarea', function ( e ) {
			var files = pastedImages( e, labelFor( $wrap ) );
			if ( ! files.length || ! pasteIsFileOnly( e ) ) return;
			e.preventDefault();
			handleFiles( editorId, files );
		} );
	}

	/** The field's own label, so a pasted snip is named after the section. */
	function labelFor( $wrap ) {
		var $f = $wrap.closest( '.bftd-field' );
		var t  = $f.find( '.bftd-label' ).first().text();
		return bftdTrim( t ) || 'pasted';
	}

	/**
	 * The visual editor is an iframe, and an iframe swallows its own drops.
	 * So the same handlers go on the document inside it, and the drop is
	 * reported back out to the wrapper.
	 */
	function bindInsideEditor( ed ) {
		if ( ! ed || ed.bftdDrop ) return;
		var doc = ed.getDoc();
		if ( ! doc ) return;
		ed.bftdDrop = true;

		var $wrap = wrapFor( ed.id );
		var $doc  = $( doc );

		$doc.on( 'dragenter dragover', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			$wrap.addClass( 'is-dropping' );
		} );
		$doc.on( 'dragleave', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			// Inside the iframe there is nowhere else for the pointer to be,
			// so leaving the document really is leaving.
			if ( ! e.originalEvent.relatedTarget ) $wrap.removeClass( 'is-dropping' );
		} );
		$doc.on( 'drop', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			e.stopPropagation();
			$wrap.removeClass( 'is-dropping' );
			handleFiles( ed.id, e.originalEvent.dataTransfer.files );
		} );

		// Without this TinyMCE puts the screenshot in as a base64 data URI,
		// which looks like it worked, bloats the row in the database, and
		// then does not survive being filtered on the way out to a family.
		$doc.on( 'paste', function ( e ) {
			var files = pastedImages( e, labelFor( $wrap ) );
			if ( ! files.length || ! pasteIsFileOnly( e ) ) return;
			e.preventDefault();
			e.stopPropagation();
			handleFiles( ed.id, files );
		} );
	}

	/* A file dropped anywhere else on the page opens in the browser and the
	   half-written report is gone, which is a rough way to learn that the
	   drop zone was two inches to the left. */
	$( document ).on( 'dragover drop', function ( e ) {
		if ( ! isFileDrag( e ) ) return;
		// Anywhere that already knows what to do with a file is left alone:
		// our two drop targets, and WordPress's own.
		if ( $( e.target ).closest( '.wp-editor-wrap, .bftd-gallery, .media-modal, .uploader-window, .drag-drop, #drag-drop-area, .uploader-editor' ).length ) return;
		e.preventDefault();
	} );

	/* Nobody drags a file at a box that has not said it accepts one. The hint
	   is written by the script rather than by PHP, so it can only appear once
	   the uploader it describes is actually there. */
	function hint( $wrap ) {
		if ( $wrap.find( '.bftd-drop-hint' ).length ) return;
		$( '<p class="bftd-drop-hint">Drag a photo or a file straight into the box, or paste a screenshot, to add it.</p>' )
			.appendTo( $wrap );
	}

	function bindAll() {
		$( '.bftd-field-rich .wp-editor-wrap' ).each( function () {
			var id = ( this.id || '' ).replace( /^wp-/, '' ).replace( /-wrap$/, '' );
			if ( ! id ) return;
			bind( id );
			hint( $( this ) );
		} );
	}

	/* ---- the work samples box ---- */

	/**
	 * The same upload, landing somewhere else.
	 *
	 * A work sample is a record rather than a piece of the document, so it
	 * goes into the field's own list as a thumbnail instead of into the
	 * writing at the caret. Everything before that is identical, which is why
	 * both live here rather than as two half-copies of an uploader.
	 */
	function galleryAdd( $box, att ) {
		var $input = $box.find( 'input[type=hidden]' );
		var ids    = ( $input.val() || '' ).split( ',' ).filter( Boolean );
		if ( ids.indexOf( String( att.id ) ) !== -1 ) return;

		var thumb = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
		ids.push( String( att.id ) );
		$input.val( ids.join( ',' ) );
		$box.find( '.bftd-gallery-prev' ).append(
			'<span class="bftd-thumb"><img alt="" src="' + thumb + '">' +
			'<button type="button" class="bftd-thumb-x" data-id="' + att.id + '" aria-label="Remove">&times;</button></span>'
		);
	}

	function galleryFiles( $box, files ) {
		var list  = Array.prototype.slice.call( files, 0, MAX_AT_ONCE );
		var extra = files.length - list.length;
		if ( ! list.length ) return;

		var $note = $box.find( '.bftd-gallery-note' );
		function tell( text, kind ) {
			$note.attr( 'class', 'bftd-gallery-note' + ( kind ? ' is-' + kind : '' ) ).text( text ).show();
			if ( 'working' !== kind ) {
				clearTimeout( $note.data( 't' ) );
				$note.data( 't', setTimeout( function () { $note.fadeOut( 200 ); }, 'bad' === kind ? 9000 : 3500 ) );
			}
		}

		var done = 0, failed = [];
		tell( list.length === 1 ? 'Uploading ' + list[ 0 ].name + '\u2026'
			: 'Uploading ' + list.length + ' files\u2026', 'working' );

		list.reduce( function ( chain, file ) {
			return chain.then( function () {
				return upload( file ).then( function ( att ) {
					galleryAdd( $box, att );
					done++;
					if ( list.length > 1 ) tell( 'Added ' + done + ' of ' + list.length + '\u2026', 'working' );
				} ).catch( function ( err ) {
					failed.push( file.name + ' (' + err.message + ')' );
				} );
			} );
		}, Promise.resolve() ).then( function () {
			if ( failed.length ) {
				tell( 'Could not add ' + failed.join( ', ' ) + '. Everything else went in.', 'bad' );
			} else if ( extra > 0 ) {
				tell( 'Added ' + done + '. Only ' + MAX_AT_ONCE + ' at a time, so ' + extra +
					( 1 === extra ? ' file was' : ' files were' ) + ' left out.', 'bad' );
			} else {
				tell( 1 === done ? 'Added.' : 'Added ' + done + ' files.', 'good' );
			}
		} );
	}

	// Delegated, because a section's fields can be drawn after this runs.
	var galleryOver = 0;

	$( document )
		.on( 'dragenter dragover', '.bftd-gallery', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'copy';
			$( this ).addClass( 'is-dropping' );
		} )
		.on( 'dragenter', '.bftd-gallery', function ( e ) { if ( isFileDrag( e ) ) galleryOver++; } )
		.on( 'dragleave', '.bftd-gallery', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			galleryOver--;
			if ( galleryOver <= 0 ) $( this ).removeClass( 'is-dropping' );
		} )
		.on( 'drop', '.bftd-gallery', function ( e ) {
			if ( ! isFileDrag( e ) ) return;
			e.preventDefault();
			e.stopPropagation();
			galleryOver = 0;
			$( this ).removeClass( 'is-dropping' );
			galleryFiles( $( this ), e.originalEvent.dataTransfer.files );
		} );

	/**
	 * Pasting into the work samples box.
	 *
	 * The box is focusable, so tabbing to it and pressing paste works without
	 * a mouse. A paste while the cursor is simply over it works too, because
	 * the common case is a tutor who has just taken a screenshot and is
	 * pointing at the box rather than clicking into it first.
	 */
	var $hovered = null;

	$( document )
		.on( 'mouseenter', '.bftd-gallery', function () { $hovered = $( this ); } )
		.on( 'mouseleave', '.bftd-gallery', function () { $hovered = null; } );

	$( document ).on( 'paste', function ( e ) {
		// An editor, a text box or the media modal answers its own paste.
		var $t = $( e.target );
		if ( $t.closest( '.wp-editor-wrap, .media-modal' ).length ) return;
		if ( $t.is( 'input, textarea' ) || $t.attr( 'contenteditable' ) === 'true' ) return;

		var $box = $t.closest( '.bftd-gallery' );
		if ( ! $box.length ) $box = $hovered;
		if ( ! $box || ! $box.length ) return;

		var files = pastedImages( e, galleryLabel( $box ) );
		if ( ! files.length ) return;
		e.preventDefault();
		galleryFiles( $box, files );
	} );

	function galleryLabel( $box ) {
		var t = $box.closest( '.bftd-field' ).find( '.bftd-label' ).first().text();
		return bftdTrim( t ) || 'work sample';
	}

	/**
	 * Pasting into the media window.
	 *
	 * WordPress's uploader takes a drop and a file picker and nothing else,
	 * so a screenshot on the clipboard has nowhere to go on the one screen
	 * that is entirely about adding files. The upload is ours; handing the
	 * result back to the window is what makes it behave like any other file
	 * the person just added.
	 */
	$( document ).on( 'paste', '.media-modal', function ( e ) {
		var files = pastedImages( e, 'pasted' );
		if ( ! files.length ) return;
		var frame = window.wp && wp.media && wp.media.frame;
		if ( ! frame ) return;
		e.preventDefault();
		e.stopPropagation();

		var $status = modalStatus();
		$status.text( files.length === 1 ? 'Uploading what you pasted\u2026'
			: 'Uploading ' + files.length + ' pasted images\u2026' ).show();

		var added = 0, failed = 0;
		Array.prototype.slice.call( files, 0, MAX_AT_ONCE ).reduce( function ( chain, file ) {
			return chain.then( function () {
				return upload( file ).then( function ( att ) {
					added++;
					intoFrame( frame, att.id );
				} ).catch( function () { failed++; } );
			} );
		}, Promise.resolve() ).then( function () {
			$status.text( failed
				? 'Could not add ' + failed + ' of them. ' + added + ' went in.'
				: ( added === 1 ? 'Added, and selected for you.' : 'Added ' + added + ', and selected for you.' ) );
			setTimeout( function () { $status.fadeOut( 250 ); }, 4000 );
		} );
	} );

	function modalStatus() {
		var $s = $( '.media-modal .bftd-paste-status' );
		if ( ! $s.length ) {
			$s = $( '<div class="bftd-paste-status" role="status"></div>' )
				.appendTo( $( '.media-modal .media-frame-content' ).first() );
		}
		return $s.show();
	}

	/** Put a freshly uploaded attachment into the window and select it. */
	function intoFrame( frame, id ) {
		var att = wp.media.attachment( id );
		return att.fetch().done( function () {
			var state = frame.state();
			var sel   = state && state.get ? state.get( 'selection' ) : null;
			if ( sel ) sel.add( att );

			// The grid is a cached query, so it does not know about a file it
			// did not upload itself. Touching the props re-runs it.
			try {
				var content = frame.content.get();
				if ( content && content.collection && content.collection.props ) {
					content.collection.props.set( { ignore: ( + new Date() ) } );
				}
			} catch ( err ) {}
		} );
	}

	$( bindAll );
	$( document ).on( 'bftd:fields-changed', bindAll );

	if ( window.tinymce ) {
		tinymce.on( 'AddEditor', function ( e ) {
			e.editor.on( 'init', function () {
				bind( e.editor.id );
				bindInsideEditor( e.editor );
			} );
		} );
		$( function () {
			$.each( tinymce.editors || [], function ( i, ed ) {
				if ( ed.initialized ) { bind( ed.id ); bindInsideEditor( ed ); }
			} );
		} );
	}

} )( jQuery );

/* ==========================================================================
   Getting back to the top

   A reading diagnostic is six sections of writing, and the edit screen for
   one is several thousand pixels long. Reaching the Publish button from the
   conclusion is a scroll a tutor does dozens of times a day.

   It only appears once there is somewhere to go back to, which is what keeps
   it from being a permanent button floating over a short screen.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	// Only where the screens are long: the report and lesson editors, and the
	// student hub. Everywhere else in wp-admin is somebody else's screen.
	if ( ! $( '.bftd-field, .bftd-hub, .bftd-sections' ).length ) return;

	var $btn = $(
		'<button type="button" class="bftd-top" hidden>' +
			'<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 12.5V4M4 7.5L8 3.5l4 4"/></svg>' +
			'<span>Back to top</span>' +
		'</button>'
	).appendTo( 'body' );

	var showing = false;

	function update() {
		var should = ( window.pageYOffset || document.documentElement.scrollTop || 0 ) > 700;
		if ( should === showing ) return;
		showing = should;
		if ( should ) {
			$btn.prop( 'hidden', false );
			// A frame between unhiding and animating, or the transition has
			// nothing to transition from.
			window.requestAnimationFrame( function () { $btn.addClass( 'is-in' ); } );
		} else {
			$btn.removeClass( 'is-in' );
			setTimeout( function () { if ( ! showing ) $btn.prop( 'hidden', true ); }, 220 );
		}
	}

	// Scroll fires on every pixel; this only needs to know which side of the
	// line we are on, and only once per frame.
	var queued = false;
	$( window ).on( 'scroll.bftdtop resize.bftdtop', function () {
		if ( queued ) return;
		queued = true;
		window.requestAnimationFrame( function () { queued = false; update(); } );
	} );
	update();

	$btn.on( 'click', function () {
		// Anybody who has asked their system not to animate things means it.
		var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		window.scrollTo( { top: 0, behavior: still ? 'auto' : 'smooth' } );

		// Scrolling moves the page but not the keyboard, so focus goes back
		// to the top too, or the next Tab carries on from the footer.
		var $first = $( '#title, .bftd-field' ).first();
		if ( $first.length ) $first.attr( 'tabindex', '-1' ).trigger( 'focus' );
	} );

} )( jQuery );

/* ==========================================================================
   Autosave, for drafts only

   Core autosaves the title and the content. Every field on these screens is
   in a meta box, so until now nothing here has ever been protected.

   Nothing is written to the report itself. The form as it stands is kept
   beside the record and offered back next time the screen opens, because a
   machine writing half a sentence into a report would make the activity log
   and the review bar both start lying about what a person did.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	if ( ! window.BFTD || ! BFTD.autosave || ! BFTD.post_id ) return;

	var $form = $( 'form#post' );
	if ( ! $form.length ) return;

	var EVERY   = 25000;   // how often a change is worth keeping
	var SETTLED = 2500;    // how long after the last keystroke to bother

	var $status = $( '<span class="bftd-autosave-status" role="status"></span>' );
	var seen    = BFTD.modified || '';
	var dirty   = false;
	var busy    = false;
	var timer   = null;
	var stopped = false;

	function place() {
		var $where = $( '#minor-publishing-actions, #major-publishing-actions' ).first();
		if ( $where.length ) $status.appendTo( $where );
		else $status.insertAfter( $( 'h1.wp-heading-inline' ).first() );
	}
	$( place );

	function say( text, kind ) {
		$status.attr( 'class', 'bftd-autosave-status' + ( kind ? ' is-' + kind : '' ) ).text( text );
	}

	/**
	 * What the form is carrying, including the editors.
	 *
	 * TinyMCE keeps its content in an iframe and only writes it back to the
	 * textarea when told to, so a serialize() without this returns whatever
	 * the section said when the page loaded.
	 */
	function payload() {
		if ( window.tinymce ) {
			try { tinymce.triggerSave(); } catch ( e ) {}
		}
		var keep = /^(bftd|bftd_rows|bftd_sched|bftd_visible|bftd_items|post_title|bftd_subtitle|bftd_student)(\[|$)/;
		return $form.serializeArray().filter( function ( f ) {
			return keep.test( f.name );
		} );
	}

	/*
	 * How many of our own fields this request is carrying.
	 *
	 * Sent with the save so the server can tell a complete request from one PHP
	 * cut short at max_input_vars. PHP gives no signal when it stops reading:
	 * the tail of the form is simply absent, and a save that rewrites every
	 * field it knows about turns absent into empty. Counting is the only way to
	 * notice, and the browser is the only place that knows the real number.
	 *
	 * Only the field groups are counted, not post_title and friends, because
	 * the server counts the same groups and the two numbers have to mean the
	 * same thing.
	 */
	function ourFieldCount( data ) {
		var groups = /^(bftd|bftd_rows|bftd_sched|bftd_visible|bftd_items)\[/;
		var names  = {};
		for ( var i = 0; i < data.length; i++ ) {
			if ( groups.test( data[ i ].name ) ) names[ data[ i ].name ] = true;
		}
		/*
		 * Distinct names, not entries, because PHP counts what it ends up with
		 * rather than what was sent. Two inputs sharing a name collapse into
		 * one value there: a checkbox and the hidden partner that makes
		 * unticking mean something send that name twice and arrive as one.
		 *
		 * Counting entries here made this refuse every save on any form with a
		 * paired checkbox, which is every report. The count said forty-one sent
		 * and forty arrived, called it a truncation, and threw the save away.
		 * A guard that cannot tell a shortfall from the shape of the form is
		 * not a guard, so it counts the thing both sides agree on.
		 *
		 * A repeated name that PHP keeps rather than collapses, a multi-select
		 * posting x[] three times, counts as one here and three there. That way
		 * round is harmless: more arriving than were sent is never a truncation.
		 */
		return Object.keys( names ).length;
	}

	function save() {
		if ( busy || stopped || ! dirty ) return;
		busy = true;
		say( 'Saving a copy…', 'working' );

		var data = payload();
		data.push( { name: 'bftd_sent', value: ourFieldCount( data ) } );
		data.push( { name: 'action',   value: 'bftd_autosave' } );
		data.push( { name: 'nonce',    value: BFTD.nonce } );
		data.push( { name: 'post_id',  value: BFTD.post_id } );
		// What this tab believes the record looked like when it drew the
		// screen. The server refuses to write over somebody else's save.
		data.push( { name: 'modified', value: seen } );

		$.post( BFTD.ajax_url, $.param( data ) )
			.done( function ( res ) {
				dirty = false;
				var d = ( res && res.data ) ? res.data : {};
				if ( d.modified ) seen = d.modified;
				// Two different promises to the person, so two different
				// words. "Saved" where it was really saved, "kept" where a
				// copy is being held until they decide.
				var what = ( 'draft' === d.mode ) ? 'Draft saved' : 'Copy kept';
				say( d.at ? what + ' at ' + d.at : what, 'good' );

				// The record has changed, so the header pills are worked out
				// again and handed back. Displaying what the server said beats
				// guessing in the browser, and beats leaving them reading
				// "Not saved yet" about work that has just been saved.
				if ( d.pills ) {
					$.each( d.pills, function ( id, pill ) {
						var $p = $( '#bftd-' + id ).find( '> .bftd-section-head .bftd-section-meta .bftd-pill' );
						if ( $p.length ) $p.attr( 'class', 'bftd-pill bftd-pill-' + pill.tone ).text( pill.text );
					} );
				}
			} )
			.fail( function ( xhr ) {
				var r = xhr.responseJSON;
				if ( r && r.data && r.data.conflict ) {
					// Carrying on would overwrite whatever they did, one
					// keystroke at a time, so it stops and says so.
					stopped = true;
					say( r.data.message, 'bad' );
					return;
				}
				say( 'Could not save just now.', 'bad' );
			} )
			.always( function () { busy = false; } );
	}

	/* ---- noticing a change ---- */

	function touched() {
		if ( stopped ) return;
		dirty = true;
		clearTimeout( timer );
		timer = setTimeout( save, SETTLED );
	}

	/*
	 * And the same count on a real submit, because the Update button posts the
	 * identical form through the identical limit. A hidden field written at the
	 * moment of submitting, rather than at page load, so rows added since are
	 * counted too.
	 */
	$form.on( 'submit', function () {
		var $n = $form.find( 'input[name="bftd_sent"]' );
		if ( ! $n.length ) {
			$n = $( '<input type="hidden" name="bftd_sent" value="">' ).appendTo( $form );
		}
		$n.val( ourFieldCount( payload() ) );
	} );

	$form.on( 'input change', ':input', touched );
	$( document ).on( 'bftd:changed', touched );

	/* ---- the editors ----------------------------------------------------
	   A report is almost entirely rich text, so an autosave the editors
	   cannot trigger is an autosave that never runs. This was written as a
	   single tinymce.on( 'AddEditor' ) guarded by if ( window.tinymce ), and
	   it never bound once: WordPress prints enqueued footer scripts before it
	   prints TinyMCE's own, so window.tinymce does not exist yet when this
	   file runs. The guard was false, the branch was skipped, and forty
	   minutes of writing was kept only if the tutor happened to touch a plain
	   field as well.

	   So the binding no longer assumes an order. It covers the editor that is
	   already there, the one added later, and the case where TinyMCE itself
	   has not loaded yet, and it is safe to run repeatedly. */

	function bindEditor( ed ) {
		if ( ! ed || ed.bftdWatched ) return;
		ed.bftdWatched = true;
		// SetContent is what a pasted screenshot arrives as; ExecCommand is
		// the toolbar; the rest is typing.
		ed.on( 'input keyup change SetContent ExecCommand Undo Redo', touched );
	}

	function watchEditors() {
		if ( ! window.tinymce ) return false;
		var list = tinymce.editors || [];
		for ( var i = 0; i < list.length; i++ ) bindEditor( list[ i ] );
		if ( ! tinymce.bftdHooked ) {
			tinymce.bftdHooked = true;
			tinymce.on( 'AddEditor', function ( e ) { bindEditor( e.editor ); } );
		}
		return true;
	}

	// WordPress's own signal, which arrives whenever TinyMCE loaded.
	$( document ).on( 'tinymce-editor-init', function ( e, ed ) { bindEditor( ed ); } );

	// And for the case where this file ran first: wait for TinyMCE to appear,
	// then hook it. Ten seconds is long enough for a slow admin screen and
	// short enough that a page with no editor on it stops asking.
	if ( ! watchEditors() ) {
		var tries = 0;
		var waiting = setInterval( function () {
			if ( watchEditors() || ++tries > 100 ) clearInterval( waiting );
		}, 100 );
	}
	$( watchEditors );

	// A long stretch of typing would otherwise never settle for long enough
	// to trigger the timer above, so there is a floor as well as a ceiling.
	setInterval( function () { if ( dirty ) save(); }, EVERY );

	// The last few seconds of work are the ones worth having.
	$( window ).on( 'pagehide', function () {
		if ( ! dirty || stopped || ! navigator.sendBeacon ) return;
		var data = payload();
		var fd   = new FormData();
		data.forEach( function ( f ) { fd.append( f.name, f.value ); } );
		fd.append( 'action', 'bftd_autosave' );
		fd.append( 'nonce', BFTD.nonce );
		fd.append( 'post_id', BFTD.post_id );
		navigator.sendBeacon( BFTD.ajax_url, fd );
	} );

	// A save by a person is what the copy was standing in for.
	$form.on( 'submit', function () { stopped = true; clearTimeout( timer ); } );

	/* ---- putting a copy back ---- */

	$( document ).on( 'click', '.bftd-restore-yes, .bftd-restore-no', function () {
		var $bar = $( this ).closest( '.bftd-restore' );
		var back = $( this ).hasClass( 'bftd-restore-yes' );
		$bar.find( 'button' ).prop( 'disabled', true );
		$bar.find( '.bftd-restore-do' ).append( ' <span class="bftd-restore-busy">Working…</span>' );

		$.post( BFTD.ajax_url, {
			action:  back ? 'bftd_autosave_restore' : 'bftd_autosave_discard',
			nonce:   BFTD.nonce,
			post_id: $bar.data( 'post' )
		} ).done( function () {
			// A restore is an ordinary save on the server, so the screen has
			// to be re-read rather than patched: the fields, the editors and
			// the repeaters all come back from the record.
			if ( back ) window.location.reload();
			else $bar.slideUp( 180, function () { $bar.remove(); } );
		} ).fail( function () {
			$bar.find( '.bftd-restore-busy' ).text( 'That did not work. Reload and try again.' );
			$bar.find( 'button' ).prop( 'disabled', false );
		} );
	} );

} )( jQuery );

/* ==========================================================================
   Putting the standard wording back

   A section's explanation belongs to the practice, not to one report. It can
   be reworded for a particular child, and this is the way back. It fills the
   editor and stops there: saving stays a person's decision, so they can read
   what came back before committing to it.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.bftd-reset', function () {
		var $btn = $( this );
		var id   = $btn.data( 'editor' );
		var $src = $( '.bftd-reset-src[data-for="' + id + '"]' );
		if ( ! $src.length ) return;

		var html = bftdTrim( $src.html() );
		var ed   = window.tinymce && tinymce.get( id );

		if ( ed && ! ed.isHidden() ) {
			ed.setContent( html );
			ed.fire( 'change' );           // so the autosave notices
		} else {
			$( document.getElementById( id ) ).val( html ).trigger( 'change' );
		}

		$btn.prop( 'disabled', true );
		$btn.siblings( '.bftd-reset-note' ).text( 'Put back. Save to keep it.' );
	} );

	// Reworded again, so the way back opens again.
	function watch( ed ) {
		if ( ! ed || ed.bftdReset ) return;
		ed.bftdReset = true;
		ed.on( 'input keyup change SetContent', function () {
			var $btn = $( '.bftd-reset[data-editor="' + ed.id + '"]' );
			if ( ! $btn.length || ! $btn.prop( 'disabled' ) ) return;
			var $src = $( '.bftd-reset-src[data-for="' + ed.id + '"]' );
			if ( bftdTrim( ed.getContent() ) !== bftdTrim( $src.html() ) ) {
				$btn.prop( 'disabled', false ).siblings( '.bftd-reset-note' ).text( 'This report has been reworded.' );
			}
		} );
	}

	if ( window.tinymce ) {
		tinymce.on( 'AddEditor', function ( e ) { e.editor.on( 'init', function () { watch( e.editor ); } ); } );
		$( function () { $.each( tinymce.editors || [], function ( i, ed ) { if ( ed.initialized ) watch( ed ); } ); } );
	}

} )( jQuery );

/* ==========================================================================
   Folding a report section away

   A reading diagnostic is six panels and each one is a screenful, so getting
   from the writing section back to the overview is a long scroll. Each panel
   folds, and the screen remembers what this person folded, per report.

   Two things this must not do. It must not lose anything: the fields stay in
   the form when a panel is shut, so a save and an autosave both still carry
   them, and TinyMCE keeps its content. And it must not decide for anybody:
   every panel starts open, because a report somebody is halfway through
   writing should not open looking empty.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	var $box = $( '.bftd-box' );
	if ( ! $box.length ) return;

	var KEY = 'bftd-shut-' + ( window.BFTD && BFTD.post_id ? BFTD.post_id : 'x' );

	/* What this person folded away last time. Storage can be unavailable or
	   full, and a remembered panel is a convenience rather than data, so a
	   failure here leaves everything open rather than stopping the screen. */
	function remembered() {
		try {
			var raw = window.localStorage.getItem( KEY );
			return raw ? JSON.parse( raw ) : [];
		} catch ( e ) { return []; }
	}

	function remember( list ) {
		try { window.localStorage.setItem( KEY, JSON.stringify( list ) ); } catch ( e ) {}
	}

	function setOpen( $sec, open ) {
		$sec.toggleClass( 'is-shut', ! open );
		$sec.find( '> .bftd-section-head .bftd-section-toggle' ).attr( 'aria-expanded', open ? 'true' : 'false' );
	}

	function save() {
		var shut = [];
		$box.find( '.bftd-section.is-shut' ).each( function () {
			var id = $( this ).data( 'section' );
			if ( id ) shut.push( String( id ) );
		} );
		remember( shut );
	}

	/* ---- restore ---- */
	( function () {
		var shut = remembered();
		if ( ! shut.length ) return;
		$box.find( '.bftd-section' ).each( function () {
			var $s = $( this );
			if ( shut.indexOf( String( $s.data( 'section' ) ) ) !== -1 ) setOpen( $s, false );
		} );
	}() );

	/* ---- the header is the control ---- */
	$box.on( 'click', '.bftd-section-head', function ( e ) {
		// The right hand side carries the visibility checkbox, which decides
		// something about the family's report rather than about this screen.
		// Folding a panel because somebody reached for that would be the kind
		// of surprise that teaches people not to touch anything.
		if ( $( e.target ).closest( '.bftd-section-meta' ).length ) return;
		if ( $( e.target ).is( 'a' ) || $( e.target ).closest( 'a' ).length ) return;

		var $sec = $( this ).closest( '.bftd-section' );
		setOpen( $sec, $sec.hasClass( 'is-shut' ) );
		save();
	} );

	/* The button inside the heading fires the handler above through the
	   header, so its own click must not toggle a second time and undo it. */
	$box.on( 'click', '.bftd-section-toggle', function ( e ) { e.preventDefault(); } );

	/* ---- all of them at once ---- */
	$( document ).on( 'click', '.bftd-secall-open, .bftd-secall-shut', function () {
		var open = $( this ).hasClass( 'bftd-secall-open' );
		$box.find( '.bftd-section' ).each( function () { setOpen( $( this ), open ); } );
		save();
	} );

	/* Following a jump link to a folded panel has to open it, or the link
	   lands on a heading with nothing under it and reads as broken. */
	$( document ).on( 'click', '.bftd-jump a', function () {
		var $sec = $( $( this ).attr( 'href' ) );
		if ( $sec.length && $sec.hasClass( 'is-shut' ) ) { setOpen( $sec, true ); save(); }
	} );
}( jQuery ) );

/* ==========================================================================
   Figures the screen works out

   Correct words per minute is total words read less the errors. A tutor was
   doing that in their head between two boxes on the same screen, and a figure
   typed once does not follow a correction made to either of the two it came
   from.

   The server is what decides the value; this is the same arithmetic done again
   so the answer appears while somebody is typing rather than after a save. A
   number that only updates on reload reads as a box that is not listening.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	var $calcs = $( '.bftd-field-calc' );
	if ( ! $calcs.length ) return;

	function recalc( $calc ) {
		var section = $calc.data( 'section' );
		var keys    = String( $calc.data( 'calc' ) || '' ).split( ',' );
		if ( keys.length < 2 ) return;

		var nums = [];
		for ( var i = 0; i < keys.length; i++ ) {
			var $in = $( '[name="bftd[' + section + '][' + bftdTrim( keys[ i ] ) + ']"]' );
			var raw = bftdTrim( $in.val() || '' );
			// Blank in, blank out. Reporting a figure for a child nobody has
			// assessed yet is worse than reporting none.
			if ( '' === raw || isNaN( Number( raw ) ) ) {
				$calc.find( '.bftd-calc' ).attr( 'data-empty', '1' ).text( '—' );
				$calc.find( '.bftd-calc-warn' ).attr( 'hidden', 'hidden' );
				return;
			}
			nums.push( Number( raw ) );
		}

		var out = nums[ 0 ];
		for ( var n = 1; n < nums.length; n++ ) out -= nums[ n ];

		$calc.find( '.bftd-calc' ).removeAttr( 'data-empty' ).text( String( Math.max( 0, out ) ) );

		// More errors than words is a slip rather than a finding, so it is
		// said here rather than printed for a parent as a zero.
		var $warn = $calc.find( '.bftd-calc-warn' );
		if ( out < 0 ) {
			$warn.text( 'There are more errors than words read. Check both figures.' ).removeAttr( 'hidden' );
		} else {
			$warn.attr( 'hidden', 'hidden' );
		}
	}

	$calcs.each( function () {
		var $calc   = $( this );
		var section = $calc.data( 'section' );
		var keys    = String( $calc.data( 'calc' ) || '' ).split( ',' );
		$.each( keys, function ( i, k ) {
			$( '[name="bftd[' + section + '][' + bftdTrim( k ) + ']"]' ).on( 'input change', function () {
				recalc( $calc );
			} );
		} );
	} );
}( jQuery ) );

/* ==========================================================================
   The "shown to the family" pill, once it is out of date

   Each section header states whether the family will see it: empty, hidden, or
   live. That is worked out on the server when the page is drawn, which means
   the moment somebody types into the section it is a statement about the last
   save rather than about what is on screen.

   It read "Empty, so not shown to the family" beside a figure somebody had
   just entered, which is a confusing way to be told the work is not saved yet,
   and looks exactly like a bug in the report.

   This does not try to recompute the answer. Working out whether a section
   counts as empty means knowing which text is the practice's boilerplate and
   which a tutor wrote, and a second copy of that rule here would drift from
   the one that matters. It just stops the pill asserting something it can no
   longer know.
   ========================================================================== */
( function ( $ ) {
	'use strict';

	var $box = $( '.bftd-box' );
	if ( ! $box.length ) return;

	function stale( $section ) {
		var $pill = $section.find( '> .bftd-section-head .bftd-section-meta .bftd-pill' );
		if ( ! $pill.length || $pill.hasClass( 'is-stale' ) ) return;
		$pill.data( 'was', $pill.attr( 'class' ) )
			.attr( 'class', 'bftd-pill bftd-pill-quiet is-stale' )
			.text( 'Not saved yet' );
	}

	$box.on( 'input change', '.bftd-section :input', function () {
		// The visibility checkbox is part of the same answer, so it counts.
		stale( $( this ).closest( '.bftd-section' ) );
	} );

	$( document ).on( 'bftd:changed', function ( e, section ) {
		if ( section ) stale( $( '#bftd-' + section ) );
		else $box.find( '.bftd-section' ).each( function () { stale( $( this ) ); } );
	} );

	// The editors live in an iframe, so typing in one never reaches the form.
	function watchEditor( ed ) {
		if ( ! ed || ed.bftdPill ) return;
		ed.bftdPill = true;
		ed.on( 'input keyup change SetContent ExecCommand', function () {
			var el = ed.getElement ? ed.getElement() : null;
			if ( el ) stale( $( el ).closest( '.bftd-section' ) );
		} );
	}
	function hookEditors() {
		if ( ! window.tinymce ) return false;
		var list = tinymce.editors || [];
		for ( var i = 0; i < list.length; i++ ) watchEditor( list[ i ] );
		if ( ! tinymce.bftdPillHooked ) {
			tinymce.bftdPillHooked = true;
			tinymce.on( 'AddEditor', function ( e ) { watchEditor( e.editor ); } );
		}
		return true;
	}
	$( document ).on( 'tinymce-editor-init', function ( e, ed ) { watchEditor( ed ); } );
	if ( ! hookEditors() ) {
		var tries = 0;
		var waiting = setInterval( function () {
			if ( hookEditors() || ++tries > 100 ) clearInterval( waiting );
		}, 100 );
	}
	$( hookEditors );
}( jQuery ) );

/* --------------------------------------------------------------------------
   Finding an activity in a list of a hundred and fifty

   The select is the field; the box above it only narrows what the select is
   showing. That order matters. Building a combobox out of divs would mean
   re-implementing keyboard support, screen reader announcements and the
   browser's own touch pickers, all of which a <select> already has and all of
   which are easy to get subtly wrong.

   The options are held in JavaScript and the list is rebuilt on each keystroke
   rather than hiding options in place, because `option { display:none }` and
   the hidden attribute on an option are honoured by some browsers and quietly
   ignored by others, which would leave a tutor scrolling a list they were told
   was filtered.

   Whatever is currently chosen always stays in the list, even when it does not
   match what has been typed. Dropping it would silently unpick the activity
   somebody had already selected.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	function options( $sel ) {
		if ( $sel.data( 'bftdAll' ) ) return $sel.data( 'bftdAll' );
		var all = [];
		$sel.find( 'option' ).each( function ( i ) {
			var name = this.getAttribute( 'data-name' );
			all.push( {
				value: this.value,
				label: this.text,
				at: i,
				// The name WITHOUT the number in front of it, where the
				// server could separate them. Searching words against the
				// whole label means "1" finds "Practice routine 61" through
				// its number and "12" finds a title with a 12 in it, and a
				// tutor cannot tell which of the two happened.
				name: ( null === name ? this.text : name ).toLowerCase(),
				// The number, as digits, or ''. A library that does not
				// number its records leaves this empty everywhere, and the
				// search then treats digits as ordinary characters.
				num: this.getAttribute( 'data-number' ) || '',
				// Which programme this one is in. Read off the option rather
				// than off its optgroup, because the optgroup carries the words
				// a person reads and this has to be the key the server wrote.
				bucket: this.getAttribute( 'data-bucket' ) || ''
			} );
		} );
		$sel.data( 'bftdAll', all );
		return all;
	}

	/* ----------------------------------------------------------------
	 * What was typed
	 *
	 * Split on spaces, and each piece is either a number or a word. Both can
	 * appear at once: "17 phoneme" is activity seventeen whose name mentions
	 * phonemes, which is how somebody half-remembering one actually types.
	 * ---------------------------------------------------------------- */

	function asked( term ) {
		var nums = [], words = [];
		var bits = bftdTrim( term.toLowerCase() ).split( /\s+/ );
		for ( var i = 0; i < bits.length; i++ ) {
			if ( ! bits[ i ] ) continue;
			if ( /^\d+$/.test( bits[ i ] ) ) nums.push( bits[ i ] );
			else words.push( bits[ i ] );
		}
		return { nums: nums, words: words, empty: ! nums.length && ! words.length };
	}

	/* Does this library number anything at all? */
	function numbered( all ) {
		for ( var i = 0; i < all.length; i++ ) if ( all[ i ].num ) return true;
		return false;
	}

	/**
	 * How well one option answers what was typed. 0 means it does not.
	 *
	 * Everything typed has to be accounted for: three words means all three
	 * are in the name, in any order, because "lines sound" and "sound lines"
	 * are the same request and only one of them used to find anything.
	 *
	 * The number is matched AS A NUMBER where the library has them. Typing 12
	 * used to return 112, 120, 121 and anything with a 12 in its title, which
	 * is six answers to a question with one. Now twelve is twelve, and 112 is
	 * offered under it rather than instead of it, because somebody typing the
	 * first digits of a longer number is also a real thing.
	 */
	function score( o, ask, hasNums ) {
		var n = 0;

		if ( ask.nums.length ) {
			if ( ! hasNums ) {
				// Nothing here is numbered, so digits are just characters.
				for ( var d = 0; d < ask.nums.length; d++ ) {
					if ( o.name.indexOf( ask.nums[ d ] ) === -1 ) return 0;
					n += 20;
				}
			} else {
				if ( ! o.num ) return 0;
				for ( var k = 0; k < ask.nums.length; k++ ) {
					if ( o.num === ask.nums[ k ] ) n += 1000;
					else if ( 0 === o.num.indexOf( ask.nums[ k ] ) ) n += 120;
					else return 0;
				}
			}
		}

		for ( var w = 0; w < ask.words.length; w++ ) {
			var at = o.name.indexOf( ask.words[ w ] );
			if ( at === -1 ) return 0;
			// A word where a word starts beats the same letters in the middle
			// of a longer one: "read" should find "Read, Read Back" before
			// "Spreading".
			var edge = ( 0 === at ) || ! /[a-z0-9]/.test( o.name.charAt( at - 1 ) );
			n += edge ? 60 : 15;
			if ( 0 === at ) n += 40;
		}

		// The whole phrase, in the order it was typed, at the front of the
		// name. This is somebody typing the name of the thing they want.
		if ( ask.words.length ) {
			var phrase = ask.words.join( ' ' );
			if ( o.name === phrase ) n += 600;
			else if ( 0 === o.name.indexOf( phrase ) ) n += 250;
			else if ( o.name.indexOf( phrase ) !== -1 ) n += 80;
		}

		return n;
	}

	/* Which bucket is switched on, or '' for all of them. */
	function bucket( $wrap ) {
		var $on = $wrap.find( '.bftd-actpick-t.is-on' );
		return $on.length ? ( $on.attr( 'data-bucket' ) || '' ) : '';
	}

	/* Does one option survive both the typed words and the chosen bucket?
	   One rule, asked by the select filter and by the suggestion list, because
	   two copies is how a list of matches comes to disagree with the list
	   underneath it. */
	function hit( o, term, buck, all ) {
		return rank( o, term, buck, all ) > 0;
	}

	/* One rule, asked by the select filter and by the suggestion list,
	   because two copies is how a list of matches comes to disagree with the
	   list underneath it. */
	function rank( o, term, buck, all ) {
		if ( ! o.value ) return 0;
		if ( buck && o.bucket !== buck ) return 0;
		var ask = asked( term || '' );
		if ( ask.empty ) return 1;
		return score( o, ask, numbered( all ) );
	}

	function filter( $wrap ) {
		var $sel = $wrap.find( '.bftd-actpick-s' );
		var $q   = $wrap.find( '.bftd-actpick-q' );
		if ( ! $sel.length ) return;

		var all  = options( $sel );
		var term = bftdTrim( ( $q.val() || '' ).toLowerCase() );
		var buck = bucket( $wrap );
		var keep = $sel.val();

		var html = '';
		var hits = 0;
		for ( var i = 0; i < all.length; i++ ) {
			var o = all[ i ];
			var match = hit( o, term, buck, all );
			if ( match ) hits++;

			// Kept in the list, but not counted as a hit: the empty choice,
			// so the field can always be cleared, and whatever is currently
			// chosen, so filtering never silently unpicks it. Counting those
			// would have said "1 match" over a search that matched nothing.
			if ( ! match && o.value && o.value !== keep ) continue;

			/*
			 * The bucket is written back on every rebuild.
			 *
			 * This loop replaces the select's whole contents, and the first
			 * version of it dropped data-bucket on the way through. Everything
			 * still looked right — the filtering worked, because it reads the
			 * cached list rather than the DOM — but the moment a tutor typed
			 * anything, the chosen activity stopped being able to say which
			 * track it was from, which is the one thing all of this exists to
			 * fix. Nothing errored. It was found by picking one in a browser.
			 */
			html += '<option value="' + o.value.replace( /"/g, '&quot;' ) + '"' +
				( o.bucket ? ' data-bucket="' + o.bucket.replace( /"/g, '&quot;' ) + '"' : '' ) +
				( o.value === keep ? ' selected' : '' ) + '>' +
				$( '<div>' ).text( o.label ).html() + '</option>';
		}
		$sel.html( html ).val( keep );

		// Searching is not choosing, however few are left.
		//
		// Narrowing to one and selecting it for the tutor reads as helpful
		// and is not: a lesson's notes and a child's work then hang off an
		// activity nobody picked, and the tutor finds out on the report. An
		// activity is taken by clicking it, or by Enter on the one they have
		// moved to, and by nothing else.

		// Say how many matched. A filter that narrows to nothing looks
		// identical to one that is broken.
		/*
		 * Inside the search box, not beside the whole picker.
		 *
		 * Appended to the picker it outlived the search: once an activity was
		 * taken the box went away and "140 matches" stayed on screen under the
		 * chosen activity, describing a search nobody was running. Part of the
		 * search means it hides when the search does.
		 */
		var $n = $wrap.find( '.bftd-actpick-n' );
		if ( ! $n.length ) {
			$n = $( '<span class="bftd-actpick-n"></span>' ).appendTo( $wrap.find( '.bftd-actpick-find' ) );
		}
		$n.text( ( term || buck )
			? ( hits ? hits + ( 1 === hits ? ' match' : ' matches' ) : 'Nothing matches' )
			: '' );
	}

	/* ----------------------------------------------------------------
	 * The list of matches, under the box, as somebody types
	 *
	 * The select underneath is still the field: it is what posts, what works
	 * with no script, and what a phone shows its own picker for. This is the
	 * short list a tutor reads while typing, so they can see "45 · Reading
	 * multisyllable words" appear after three characters instead of narrowing
	 * a list they cannot see.
	 *
	 * Capped, because a search for "s" is not a list worth reading, and the
	 * cap is stated rather than silently applied.
	 * ---------------------------------------------------------------- */

	var SHOW = 8;

	function esc( t ) { return $( '<div>' ).text( t ).html(); }

	function suggest( $wrap ) {
		var $q    = $wrap.find( '.bftd-actpick-q' );
		var $list = $wrap.find( '.bftd-actpick-r' );
		var all   = options( $wrap.find( '.bftd-actpick-s' ) );
		var term  = bftdTrim( ( $q.val() || '' ).toLowerCase() );
		var buck  = bucket( $wrap );

		if ( ! term ) return close( $wrap );

		/*
		 * Scored, then sorted, then cut to eight.
		 *
		 * The first version took the first eight in library order and
		 * stopped. On a hundred and forty activities that is eight answers
		 * chosen by where they happen to sit in the list, so a search for
		 * "12" offered 112, 120 and 121 and the activity actually numbered
		 * twelve could be pushed off the end of its own result. The eight
		 * worth showing are the best eight, which means every one of them
		 * has to be looked at before any are thrown away.
		 */
		var scored = [];
		for ( var i = 0; i < all.length; i++ ) {
			var n = rank( all[ i ], term, buck, all );
			if ( n > 0 ) scored.push( { o: all[ i ], n: n } );
		}
		if ( ! scored.length ) return close( $wrap );

		scored.sort( function ( a, b ) {
			if ( a.n !== b.n ) return b.n - a.n;
			// Same score: the order the library is in, which for activities
			// is by number and for skills is by group then name. A stable
			// tail means the list does not reshuffle as somebody types.
			return a.o.at - b.o.at;
		} );

		var hits = [];
		for ( var m = 0; m < scored.length; m++ ) hits.push( scored[ m ].o );

		var html = '';
		for ( var j = 0; j < hits.length && j < SHOW; j++ ) {
			html += '<li role="option" aria-selected="false" data-value="' + esc( hits[ j ].value ) + '">' +
				esc( hits[ j ].label ) + '</li>';
		}
		if ( hits.length > SHOW ) html += '<li class="is-more">Keep typing to narrow it further</li>';

		$list.html( html ).prop( 'hidden', false );
		$q.attr( 'aria-expanded', 'true' );
	}

	function close( $wrap ) {
		$wrap.find( '.bftd-actpick-r' ).prop( 'hidden', true ).empty();
		$wrap.find( '.bftd-actpick-q' ).attr( 'aria-expanded', 'false' );
	}

	/**
	 * Take one, and let the search stand aside.
	 *
	 * The row stops being a search box and becomes the activity, with a way
	 * back to the list. That is the difference a tutor needs to see between
	 * "I am looking at this one" and "I have taken this one", because
	 * everything else on the row — the notes, the child's work — belongs to
	 * the activity that was taken.
	 */
	/*
	 * What a chosen record is called once it is settled.
	 *
	 * The list itself leaves the track off every line, because a hundred and
	 * forty rows each beginning "Track 1 ·" push the name a tutor is reading
	 * for off the end. The one that has been TAKEN is different: it is on the
	 * lesson now, and "12 · Key word, Action, Thing" on its own does not say
	 * which of the two twelves it is. So the track goes back on here, read off
	 * the button that carries the same key the option does.
	 */
	function fullLabel( $wrap, $opt ) {
		var text = $opt.text();
		var buck = $opt.attr( 'data-bucket' ) || '';
		if ( ! buck ) return text;
		var $btn = $wrap.find( '.bftd-actpick-t[data-bucket="' + buck + '"]' );
		return $btn.length ? $btn.text() + ' · ' + text : text;
	}

	function settle( $wrap ) {
		var $sel  = $wrap.find( '.bftd-actpick-s' );
		var value = $sel.val() || '';
		var label = value ? fullLabel( $wrap, $sel.find( 'option:selected' ) ) : '';

		$wrap.toggleClass( 'is-chosen', '' !== value );
		$wrap.find( '.bftd-actpick-name' ).text( label );
		$wrap.find( '.bftd-actpick-is' ).prop( 'hidden', '' === value );
		$wrap.find( '.bftd-actpick-find' ).prop( 'hidden', '' !== value );
	}

	function choose( $wrap, value, label ) {
		$wrap.find( '.bftd-actpick-s' ).val( value ).trigger( 'change' );
		$wrap.find( '.bftd-actpick-q' ).val( '' );
		close( $wrap );
		filter( $wrap );
		settle( $wrap );
	}

	// Changing goes back to the list without letting go of what is there. A
	// tutor who opens it and changes their mind still has the activity they
	// had, rather than an empty row and a lost note.
	$( document ).on( 'click', '.bftd-actpick-change', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-actpick' );
		$wrap.find( '.bftd-actpick-find' ).prop( 'hidden', false );
		$wrap.find( '.bftd-actpick-is' ).prop( 'hidden', true );
		$wrap.find( '.bftd-actpick-q' ).val( '' ).trigger( 'focus' );
	} );

	// The select is still the field, so somebody using it directly settles
	// the row the same way.
	$( document ).on( 'change', '.bftd-actpick-s', function () {
		settle( $( this ).closest( '.bftd-actpick' ) );
	} );

	/*
	 * Switching track.
	 *
	 * One at a time, and "All" is one of them, so there is always exactly one
	 * on and never a state where every track is off and the list is empty for
	 * a reason nobody can see. The typed words are kept: a tutor who has typed
	 * "sound" and then realises they meant Track 2 and 3 should not have to
	 * type it again.
	 */
	$( document ).on( 'click', '.bftd-actpick-t', function ( e ) {
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-actpick' );
		$wrap.find( '.bftd-actpick-t' ).removeClass( 'is-on' ).attr( 'aria-pressed', 'false' );
		$( this ).addClass( 'is-on' ).attr( 'aria-pressed', 'true' );
		filter( $wrap );
		suggest( $wrap );
		$wrap.find( '.bftd-actpick-q' ).trigger( 'focus' );
	} );

	$( document ).on( 'input search', '.bftd-actpick-q', function () {
		var $wrap = $( this ).closest( '.bftd-actpick' );
		filter( $wrap );
		suggest( $wrap );
	} );

	$( document ).on( 'mousedown', '.bftd-actpick-r li[data-value]', function ( e ) {
		// mousedown, not click: blur fires first on a click and would close
		// the list out from under the pointer.
		e.preventDefault();
		var $wrap = $( this ).closest( '.bftd-actpick' );
		choose( $wrap, $( this ).data( 'value' ) + '', $( this ).text() );
	} );

	$( document ).on( 'keydown', '.bftd-actpick-q', function ( e ) {
		var $wrap = $( this ).closest( '.bftd-actpick' );
		var $list = $wrap.find( '.bftd-actpick-r' );
		if ( $list.prop( 'hidden' ) ) return;

		var $items = $list.find( 'li[data-value]' );
		var at     = $items.index( $items.filter( '.is-on' ) );

		if ( 40 === e.which || 38 === e.which ) {          // down, up
			e.preventDefault();
			at = ( 40 === e.which ) ? at + 1 : at - 1;
			if ( at < 0 ) at = $items.length - 1;
			if ( at >= $items.length ) at = 0;
			$items.removeClass( 'is-on' ).attr( 'aria-selected', 'false' );
			$items.eq( at ).addClass( 'is-on' ).attr( 'aria-selected', 'true' );
		} else if ( 13 === e.which ) {                      // enter
			if ( at < 0 ) return;
			e.preventDefault();
			choose( $wrap, $items.eq( at ).data( 'value' ) + '', $items.eq( at ).text() );
		} else if ( 27 === e.which ) {                      // escape
			e.preventDefault();
			close( $wrap );
		}
	} );

	$( document ).on( 'blur', '.bftd-actpick-q', function () {
		close( $( this ).closest( '.bftd-actpick' ) );
	} );

	// A row added after the page loaded is a clone of the template, so its
	// options cache has to start empty rather than inherit the original's.
	$( document ).on( 'click', '.bftd-row-add', function () {
		var $wrap = $( this ).closest( '.bftd-rows' );
		setTimeout( function () {
			$wrap.find( '.bftd-actpick-s' ).each( function () { $( this ).removeData( 'bftdAll' ); } );
		}, 0 );
	} );
}( jQuery ) );

/* --------------------------------------------------------------------------
   Notes and work samples against one activity

   Neither a rich editor nor a media gallery can simply be drawn once per row.
   TinyMCE keeps its editors in a global registry keyed by element id, so a row
   cloned from the template would carry an id that is already spoken for: the
   new row would either steal the first one's editor or get none at all. And
   drawing one per row up front means a lesson with eight activities loads
   eight iframes before anybody has typed a word.

   So what the form posts is in the page from the start, a textarea or a hidden
   field, and the interface on top of it is built on the click that reveals it.
   A gallery needs no building; an editor does.

   Three moments beyond that, each of which loses a tutor's typing if missed:

   - REMOVING a row tears its editor down, or the registry keeps one pointed at
     an element no longer in the page.
   - SORTING moves the row's DOM, and a moved iframe is reloaded blank by the
     browser. The text is written back and the editor removed before the drag,
     so the row simply closes.
   - SUBMITTING writes every editor back first. WordPress does that for the
     editors it made at page load, not for ones added afterwards.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	var made = {};

	function canEdit() {
		return window.wp && wp.editor && 'function' === typeof wp.editor.initialize;
	}

	function build( id ) {
		if ( ! id || made[ id ] || ! canEdit() ) return;
		made[ id ] = true;
		wp.editor.initialize( id, {
			tinymce: { wpautop: true, toolbar1: 'bold,italic,bullist,numlist,link,undo,redo' },
			quicktags: true,
			mediaButtons: false
		} );
	}

	function save( id ) {
		if ( ! window.tinymce ) return;
		var ed = tinymce.get( id );
		if ( ed && ! ed.isHidden() ) ed.save();
	}

	function drop( id ) {
		if ( ! id || ! made[ id ] ) return;
		save( id );
		if ( canEdit() && 'function' === typeof wp.editor.remove ) {
			try { wp.editor.remove( id ); } catch ( e ) {}
		}
		delete made[ id ];
	}

	function shut( $panel ) {
		$panel.removeClass( 'is-open' );
		$panel.find( '.bftd-rowpanel-b' ).prop( 'hidden', true );
		$panel.find( '.bftd-rowpanel-t' ).attr( 'aria-expanded', 'false' );
	}

	// What hangs off a row appears once the row has a subject. Before that
	// the save would throw the typing away, because a row with no activity is
	// not a record of anything.
	function lead( $row ) {
		if ( ! $row.is( '[data-needs-lead]' ) ) return;
		var picked = bftdTrim( $row.find( '.bftd-actpick-s' ).first().val() || '' );
		$row.toggleClass( 'is-blank', '' === picked );
	}

	$( document ).on( 'change', '.bftd-stackrow .bftd-actpick-s', function () {
		lead( $( this ).closest( '.bftd-stackrow' ) );
	} );
	$( document ).on( 'click', '.bftd-row-add', function () {
		$( this ).closest( '.bftd-rows' ).find( '.bftd-stackrow' ).each( function () { lead( $( this ) ); } );
	} );

	$( document ).on( 'click', '.bftd-rowpanel-t', function ( e ) {
		e.preventDefault();
		var $panel = $( this ).closest( '.bftd-rowpanel' );
		var $body  = $panel.find( '.bftd-rowpanel-b' );
		var open   = $body.is( '[hidden]' );

		$body.prop( 'hidden', ! open );
		$panel.toggleClass( 'is-open', open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) build( $body.find( '.bftd-rowpanel-a' ).attr( 'id' ) );
	} );

	$( document ).on( 'click', '.bftd-row-rm', function () {
		$( this ).closest( '.bftd-row' ).find( '.bftd-rowpanel-a' ).each( function () {
			drop( $( this ).attr( 'id' ) );
		} );
	} );

	$( function () {
		$( '.bftd-rows-body' ).on( 'sortstart', function ( e, ui ) {
			$( ui.item ).find( '.bftd-rowpanel-a' ).each( function () {
				drop( $( this ).attr( 'id' ) );
				shut( $( this ).closest( '.bftd-rowpanel' ) );
			} );
		} );

		// Everything open, written back, before the form goes anywhere.
		$( '#post' ).on( 'submit', function () {
			for ( var id in made ) { if ( made.hasOwnProperty( id ) ) save( id ); }
		} );
	} );
}( jQuery ) );

/* --------------------------------------------------------------------------
   The questions only a cancelled lesson has

   Cancelled by, why, whether it counts, when it was made up. On a lesson that
   went ahead they are four boxes asking about something that did not happen,
   and a tutor reading down the screen has to work out each time that they do
   not apply. They appear when the status says the lesson did not happen.

   Server-rendered either way, so what is stored is never lost by the box
   being out of sight; this only decides whether the questions are put.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	function isMissed() {
		var v = $( '#bftd_session_status' ).val();
		return 'missed' === v || 'rescheduled' === v;
	}

	function missed() {
		var $sel = $( '#bftd_session_status' );
		if ( ! $sel.length ) return;
		var on = isMissed();
		// On the block, not on <body>. A class on the document is a global
		// anything else on the page can set or clear, and what it controls is
		// one div; PHP has already put it in the right state, so this only
		// has to follow the dropdown.
		$( '.bftd-onlymissed' ).toggleClass( 'is-on', on );
		// Announced rather than only drawn, so somebody working through the
		// form with a screen reader is told before they reach the button.
		$( '#bftd_session_missed_by, #bftd_session_missed_why' ).attr( 'aria-required', on ? 'true' : 'false' );
	}

	/* Not the native required attribute.
	 *
	 * These live in a metabox that can be collapsed, and a browser asked to
	 * report an invalid control it cannot scroll to refuses to submit the
	 * form and says nothing at all — the update button simply stops working.
	 * So the check is made here, where the box can be opened first. */
	function blanks() {
		var out = [];
		if ( ! bftdTrim( $( '#bftd_session_missed_by' ).val() || '' ) ) out.push( $( '#bftd_session_missed_by' ) );
		if ( ! bftdTrim( $( '#bftd_session_missed_why' ).val() || '' ) ) out.push( $( '#bftd_session_missed_why' ) );
		return out;
	}

	/* A draft is allowed to be half written; that is what a draft is for.
	   What must not happen is an unexplained cancellation being published to
	   a family, so the check is on the button that publishes. */
	var publishing = false;
	$( document ).on( 'click', '#publish', function () { publishing = true; } );
	$( document ).on( 'click', '#save-post, #post-preview', function () { publishing = false; } );

	$( document ).on( 'submit', '#post', function ( e ) {
		if ( ! publishing ) return;
		if ( ! $( '#bftd_session_status' ).length || ! isMissed() ) return;

		var missing = blanks();
		if ( ! missing.length ) return;

		e.preventDefault();
		publishing = false;

		var $box = $( '.bftd-onlymissed' ).closest( '.postbox' );
		if ( $box.hasClass( 'closed' ) ) $box.removeClass( 'closed' );

		$( '.bftd-missed-warn' ).remove();
		// After the heading, not before it: above, it reads as a warning
		// about the section rather than a line inside it.
		$( '.bftd-onlymissed h4' ).after(
			$( '<p class="bftd-missed-warn"></p>' ).text(
				'Say who cancelled this session and why before publishing it. Save Draft keeps what you have written.'
			)
		);

		missing[ 0 ].trigger( 'focus' );
		if ( missing[ 0 ][ 0 ] && missing[ 0 ][ 0 ].scrollIntoView ) {
			missing[ 0 ][ 0 ].scrollIntoView( { block: 'center' } );
		}

		// WordPress disables the button and shows a spinner the moment the
		// form is submitted. Stopping the submit without undoing that leaves
		// a greyed out Publish button that never comes back.
		$( '#publishing-action .spinner' ).removeClass( 'is-active' );
		$( '#publish' ).removeClass( 'disabled' ).prop( 'disabled', false );
		$( '#post' ).removeClass( 'submitting' );
	} );

	$( document ).on( 'change input', '#bftd_session_missed_by, #bftd_session_missed_why', function () {
		if ( ! blanks().length ) $( '.bftd-missed-warn' ).remove();
	} );

	$( document ).on( 'change', '#bftd_session_status', missed );
	$( missed );
}( jQuery ) );


/* ----------------------------------------------------------------------------
   A skill, written into the activity's own description

   A skill is defined once, on the skill: its name and a sentence saying what a
   child can do. An activity that builds that skill was then having the same
   sentence typed out again underneath it, in somebody's own words, and the two
   drifted the first time either was touched.

   So choosing a skill on an activity drops what that skill says about itself
   into the description below. From that moment the words belong to the
   activity: they can be rewritten, cut, or built on. That is deliberate — an
   activity explains how IT builds the skill, which is not the same sentence for
   every activity that builds it.

   Because they are a copy, they do not follow later edits to the skill. What
   follows the skill is the skills grid on a family's report, which reads the
   library directly and never this text.

   IT ONLY EVER ADDS. The description is somebody's writing, so nothing here
   replaces or clears it, and a skill whose words are already in the box is not
   put in twice — which is what re-saving a page, or picking the same skill in
   two rows, would otherwise do.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	/* The main editor, whichever of its two faces is in front. TinyMCE holds
	   its own copy while the visual tab is showing, and writing only to the
	   textarea under it would be overwritten the moment somebody switched. */
	function editor() {
		var mce = ( window.tinymce && tinymce.get( 'content' ) );
		if ( mce && ! mce.isHidden() ) {
			return {
				read: function () { return mce.getContent(); },
				/* Into the document, never over it.
				 *
				 * This used to read the whole description out, stick the new
				 * block on the end of the string, and set the lot back with
				 * setContent(). setContent() does not add to a document, it
				 * replaces one: the editor throws away every node it is
				 * holding and re-parses the string from scratch, and what
				 * comes back out is the parser's idea of that markup rather
				 * than the author's. Headings, lists and spacing a tutor had
				 * arranged came back rearranged, and picking a second skill
				 * did it to them again.
				 *
				 * Appending touches nothing that is already there. The nodes
				 * in the body are the same nodes afterwards. */
				append: function ( html ) {
					var body = mce.getBody();
					if ( ! body ) return;
					/* An empty editor is not empty: it holds one blank
					   paragraph with a placeholder in it, and appending after
					   that leaves a blank line above the first word. There is
					   nothing of anybody's to protect in an empty box, so
					   this one case is set rather than added to. */
					if ( '' === bftdTrim( body.textContent ) ) {
						mce.setContent( html );
					} else {
						body.insertAdjacentHTML( 'beforeend', html );
					}
					if ( mce.undoManager ) mce.undoManager.add();
					mce.setDirty( true );
					mce.fire( 'change' );
				}
			};
		}
		var $ta = $( '#content' );
		if ( ! $ta.length ) return null;
		return {
			read: function () { return $ta.val() || ''; },
			/* The text tab. A blank line is what separates two blocks here,
			   the same as it is in the content WordPress stores. */
			append: function ( html ) {
				var now = bftdTrim( $ta.val() );
				$ta.val( now ? now + '\n\n' + html : html ).trigger( 'change' );
			}
		};
	}

	/* Text as a reader sees it, for asking whether something is already there.
	   Comparing markup would miss the same sentence wrapped differently by the
	   editor, which is exactly what happens once somebody has edited it. */
	function plain( html ) {
		return $( '<div>' ).html( html || '' ).text().replace( /\s+/g, ' ' ).trim().toLowerCase();
	}

	/* Already in there?
	 *
	 * On the words, because the words are what is put in. The name used to be
	 * put in too and so could be asked about; now that only the description
	 * crosses over, looking for the name would answer yes to an activity that
	 * merely mentions the skill in a sentence, and the description would never
	 * arrive. The name is only worth asking about where a skill has no
	 * description to recognise it by. */
	function already( have, skill ) {
		var words = plain( skill.words );
		if ( words ) return have.indexOf( words ) !== -1;
		var name = plain( skill.name );
		return !! name && have.indexOf( name ) !== -1;
	}

	function add( skill ) {
		var ed = editor();
		if ( ! ed ) return;

		var have = plain( ed.read() );
		if ( already( have, skill ) ) return;

		/* The description, and nothing else.
		 *
		 * The skill's name went in above it as a heading, on the reasoning
		 * that it said where the words had come from. What it actually did
		 * was put a title in the middle of somebody's description that they
		 * then had to delete every time. An activity explains how it builds a
		 * skill; which skill that is, is on the picker above, not in the
		 * prose. */
		if ( ! skill.words ) return;
		ed.append( skill.words );
	}

	/* Only the skills box on an activity does this. The same picker is used for
	   activities on a lesson, and a lesson's notes are not somewhere a library
	   description belongs. */
	$( document ).on( 'change', '.bftd-skillpull .bftd-actpick-s', function () {
		var id = parseInt( $( this ).val() || '0', 10 );
		if ( ! id ) return;

		$.post( BFTD.ajax_url, {
			action:   'bftd_skill_words',
			nonce:    BFTD.nonce,
			skill_id: id
		} ).done( function ( res ) {
			if ( res && res.success && res.data ) add( res.data );
		} );
	} );
}( jQuery ) );


/* ----------------------------------------------------------------------------
   Two programmes, two headings

   The activity library is Track 1 and Tracks 2 & 3, each numbered from one to a
   hundred and forty. It used to carry a Track column, which repeated the same
   word down a hundred and forty rows to tell somebody something they already
   knew: they are reading Track 1. What is actually needed is to see where one
   programme ends and the other begins.

   The rows arrive already in track order, from SQL, so this only has to notice
   the change and put a heading in. With the script blocked the list is still
   right — two sequences, in order, just without the two lines saying so.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	function groupOf( row, prefix ) {
		var found = '';
		$.each( ( row.className || '' ).split( /\s+/ ), function ( i, c ) {
			if ( 0 === c.indexOf( prefix ) ) found = c.slice( prefix.length );
		} );
		return found;
	}

	/* Two libraries read the same way: the activity programme by track, and
	   the skills library by group. One routine, told which is which, because
	   two copies would be two places to fix the next time either changes. */
	var LISTS = [
		{ body: 'post-type-bftd_activity', prefix: 'bftd-track-', names: 'tracks' },
		{ body: 'post-type-bftd_skill',    prefix: 'bftd-group-', names: 'groups' }
	];

	$( function () {
		var $list = $( '#the-list' );
		if ( ! $list.length ) return;

		var spec = null;
		$.each( LISTS, function ( i, one ) {
			if ( $( 'body' ).hasClass( one.body ) ) spec = one;
		} );
		if ( ! spec ) return;

		var names = ( window.BFTD && BFTD[ spec.names ] ) || {};
		var cols  = $list.closest( 'table' ).find( 'thead th, thead td' ).length || 4;
		var seen  = '';

		$list.children( 'tr' ).each( function () {
			var group = groupOf( this, spec.prefix );
			if ( ! group || group === seen ) return;
			seen = group;

			$( '<tr class="bftd-trackhead"><th colspan="' + cols + '"></th></tr>' )
				.find( 'th' ).text( names[ group ] || group ).end()
				.insertBefore( this );
		} );
	} );
}( jQuery ) );

/* --------------------------------------------------------------------------
   Sessions behind a student row

   A practice of four hundred children with a hundred and forty sessions each
   is fifty-six thousand records. No screen should hold them in case somebody
   looks, so a row fetches its own when it is opened and keeps what it was
   given until the page is reloaded.

   Paging happens inside the drawer, which is why the buttons are delegated
   rather than bound: the markup they sit in was not there when this ran.
   -------------------------------------------------------------------------- */
( function ( $ ) {
	'use strict';

	function body( $row ) {
		return $( '#' + $row.find( '.bftd-ses-open' ).attr( 'aria-controls' ) ).find( '.bftd-ses-body' );
	}

	function load( $body, student, page ) {
		$body.attr( 'data-loaded', '1' );
		if ( ! $body.children().length ) {
			$body.html( $( '<p class="bftd-ses-load"></p>' ).text( 'Loading sessions…' ) );
		}

		$.post( window.BFTD.ajax_url, {
			action:  'bftd_student_sessions',
			nonce:   window.BFTD.nonce,
			student: student,
			page:    page || 1
		} ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.html ) {
				$body.html( res.data.html );
			} else {
				$body.html( $( '<p class="bftd-ses-load"></p>' ).text( 'Those sessions could not be loaded.' ) );
			}
		} ).fail( function () {
			// Said out loud rather than left spinning. A drawer that opens on
			// "Loading sessions…" forever reads as a child with no sessions.
			$body.attr( 'data-loaded', '0' );
			$body.html( $( '<p class="bftd-ses-load"></p>' ).text( 'Those sessions could not be loaded. Try opening the row again.' ) );
		} );
	}

	$( document ).on( 'click', '.bftd-ses-open', function () {
		var $btn  = $( this );
		var $row  = $btn.closest( 'tr' );
		var $draw = $( '#' + $btn.attr( 'aria-controls' ) );
		var open  = 'true' === $btn.attr( 'aria-expanded' );

		$btn.attr( 'aria-expanded', open ? 'false' : 'true' );
		$draw.prop( 'hidden', open );
		if ( open ) return;

		var $body = body( $row );
		if ( '1' !== $body.attr( 'data-loaded' ) ) load( $body, $row.data( 'student' ), 1 );
	} );

	$( document ).on( 'click', '.bftd-ses-p', function () {
		var $body = $( this ).closest( '.bftd-ses-body' );
		var $row  = $body.closest( 'tr' ).prev( '.bftd-ses-r' );
		load( $body, $row.data( 'student' ), $( this ).data( 'page' ) );
	} );

}( jQuery ) );

/* ---- the student's track, and which starting number goes with it -------
 *
 * A student has a starting number in each track they have been in, because they
 * move between them and do not always begin a track at one. Both numbers are on
 * the form so switching track needs no page load and so the number from the
 * track they came from is still there when they go back. Only the one that
 * matches the chosen track is shown.
 *
 * The server decides the state this starts in: PHP writes the chosen track onto
 * the box, and this only follows it changing. A tutor whose script did not run
 * sees both numbers, each labelled with its track, which is readable and
 * correct rather than broken.
 */
jQuery( function ( $ ) {
	var $box = $( '.bftd-track-box' );
	if ( ! $box.length ) return;

	function show( track ) {
		$box.find( '.bftd-track-start' ).each( function () {
			var mine = $( this ).hasClass( 'bftd-track-start-' + track );
			$( this ).toggle( !! track && mine );
		} );
	}

	show( $box.attr( 'data-track' ) || '' );

	$box.on( 'change', 'select[name="bftd[student][track]"]', function () {
		show( $( this ).val() || '' );
	} );
} );
