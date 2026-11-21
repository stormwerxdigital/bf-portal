/*
 * The calendar (1.193): sessions by day, week or month.
 *
 * Plain script, no library: the page asks the server for a range
 * (bftd_calendar_events), draws it, and asks again when the range, the view
 * or the tutors shown change. A session is edited, rescheduled or cancelled
 * through the same action the student's own list uses (bftd_schedule_except),
 * so the same rules apply there and GoHighLevel's copy follows. An
 * appointment made in GoHighLevel itself is moved or cancelled through
 * bftd_calendar_ghl.
 *
 * Times are wall times in the business's own time zone, as the server sends
 * them. Nothing here converts a time zone.
 */
( function () {
	'use strict';
	var C = window.BFTD_CAL;
	var root = document.querySelector( '.bftd-calv' );
	if ( ! C || ! root ) return;

	var grid   = root.querySelector( '.bftd-calv-grid' );
	var label  = root.querySelector( '.bftd-calv-label' );
	var viewEl = root.querySelector( '.bftd-calv-view' );
	var pop    = root.querySelector( '.bftd-calv-pop' );
	var veil   = root.querySelector( '.bftd-calv-veil' );
	var showGhl    = root.querySelector( '.bftd-calv-showghl' );
	var showCancel = root.querySelector( '.bftd-calv-showcancel' );
	var showMoved  = root.querySelector( '.bftd-calv-showmoved' );
	var showDraft  = root.querySelector( '.bftd-calv-showdraft' );
	var showHeld   = root.querySelector( '.bftd-calv-showheld' );
	var tutorBoxes = Array.prototype.slice.call( root.querySelectorAll( '.bftd-calv-tutor input' ) );
	var DAYS   = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
	var MONTHS = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];
	var HOUR_MIN = 96;         // the least an hour takes in the day and week views; drawTimed() makes it taller when a booking needs it (1.203.2)
	var tutorIds = Object.keys( C.tutors || {} ).map( Number );

	var state = { view: 'week', date: C.today, events: [], closed: {} };
	try {
		var saved = JSON.parse( localStorage.getItem( 'bftd_calv' ) || '{}' );
		if ( saved.view ) state.view = saved.view;
		if ( saved.off ) tutorBoxes.forEach( function ( b ) { b.checked = saved.off.indexOf( Number( b.value ) ) < 0; } );
		if ( 'ghl' in saved ) showGhl.checked = !! saved.ghl;
		if ( 'cancel' in saved ) showCancel.checked = !! saved.cancel;
		if ( 'moved' in saved ) showMoved.checked = !! saved.moved;
		if ( 'draft' in saved ) showDraft.checked = !! saved.draft;
		if ( 'held' in saved ) showHeld.checked = !! saved.held;
	} catch ( e ) {}
	viewEl.value = state.view;

	function remember() {
		try {
			localStorage.setItem( 'bftd_calv', JSON.stringify( {
				view: state.view,
				off: tutorBoxes.filter( function ( b ) { return ! b.checked; } ).map( function ( b ) { return Number( b.value ); } ),
				ghl: showGhl.checked, cancel: showCancel.checked, moved: showMoved.checked, draft: showDraft.checked, held: showHeld.checked
			} ) );
		} catch ( e ) {}
	}

	/* ---- dates, as Y-m-d strings, at midday UTC so no clock change moves them ---- */
	function d( ymd ) { return new Date( ymd + 'T12:00:00Z' ); }
	function ymd( dt ) { return dt.toISOString().slice( 0, 10 ); }
	function add( s, n ) { var x = d( s ); x.setUTCDate( x.getUTCDate() + n ); return ymd( x ); }
	function dow( s ) { return d( s ).getUTCDay(); }
	function weekStart( s ) { return add( s, -dow( s ) ); }
	function monthStart( s ) { return s.slice( 0, 8 ) + '01'; }
	function mins( hhmm ) { var p = hhmm.split( ':' ); return Number( p[0] ) * 60 + Number( p[1] ); }
	function clock( hhmm ) {
		var m = mins( hhmm ), h = Math.floor( m / 60 ), mm = m % 60;
		var ap = h < 12 ? 'am' : 'pm', h12 = h % 12 || 12;
		return h12 + ( mm ? ':' + ( mm < 10 ? '0' : '' ) + mm : '' ) + ' ' + ap;
	}
	function dayName( s ) { var x = d( s ); return DAYS[ x.getUTCDay() ] + ' ' + x.getUTCDate() + ' ' + MONTHS[ x.getUTCMonth() ].slice( 0, 3 ); }
	function esc( t ) { var e = document.createElement( 'span' ); e.textContent = t == null ? '' : String( t ); return e.innerHTML; }

	function range() {
		if ( 'day' === state.view ) return [ state.date, state.date ];
		if ( 'week' === state.view ) { var w = weekStart( state.date ); return [ w, add( w, 6 ) ]; }
		var first = weekStart( monthStart( state.date ) );
		return [ first, add( first, 41 ) ];
	}

	function heading() {
		var r = range(), a = d( r[0] ), b = d( r[1] );
		if ( 'day' === state.view ) return DAYS[ a.getUTCDay() ] + ', ' + a.getUTCDate() + ' ' + MONTHS[ a.getUTCMonth() ] + ' ' + a.getUTCFullYear();
		if ( 'week' === state.view ) {
			return a.getUTCDate() + ( a.getUTCMonth() !== b.getUTCMonth() ? ' ' + MONTHS[ a.getUTCMonth() ].slice( 0, 3 ) : '' )
				+ ' – ' + b.getUTCDate() + ' ' + MONTHS[ b.getUTCMonth() ].slice( 0, 3 ) + ' ' + b.getUTCFullYear();
		}
		var m = d( monthStart( state.date ) );
		return MONTHS[ m.getUTCMonth() ] + ' ' + m.getUTCFullYear();
	}

	function shownTutors() {
		if ( ! tutorBoxes.length ) return tutorIds;
		return tutorBoxes.filter( function ( b ) { return b.checked; } ).map( function ( b ) { return Number( b.value ); } );
	}

	/* ---- asking the server ---- */
	var asked = 0;
	function post( data ) {
		var body = new FormData();
		Object.keys( data ).forEach( function ( k ) {
			if ( Array.isArray( data[ k ] ) ) data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
			else body.append( k, data[ k ] );
		} );
		body.append( 'nonce', C.nonce );
		return fetch( C.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	}

	function load() {
		label.textContent = heading();
		var r = range(), tutors = shownTutors(), mine = ++asked;
		if ( ! tutors.length ) { state.events = []; draw(); return; }
		grid.setAttribute( 'aria-busy', 'true' );
		post( { action: 'bftd_calendar_events', from: r[0], to: r[1], tutors: tutors } ).then( function ( res ) {
			if ( mine !== asked ) return;   // a later request has taken over
			grid.removeAttribute( 'aria-busy' );
			if ( ! res || ! res.success ) { grid.innerHTML = '<p class="bftd-calv-msg">' + esc( res && res.data && res.data.message || 'The sessions could not be loaded.' ) + '</p>'; return; }
			state.events = res.data.events || [];
			state.closed = res.data.closed || {};
			draw();
		} ).catch( function () {
			if ( mine !== asked ) return;
			grid.removeAttribute( 'aria-busy' );
			grid.innerHTML = '<p class="bftd-calv-msg">The sessions could not be loaded. Check the connection and try again.</p>';
		} );
	}

	function visible() {
		return state.events.filter( function ( e ) {
			// Scheduled (1.205): everything in the CRM, whether the portal sent it
			// there or it was made there.
			if ( ( 'ghl' === e.kind || e.in_ghl ) && ! showGhl.checked ) return false;
			if ( 'cancelled' === e.status && ! showCancel.checked ) return false;
			if ( 'moved' === e.status && ! showMoved.checked ) return false;
			if ( e.draft && ! showDraft.checked ) return false;
			// Past (1.206.4, was Taught): everything before today, taught or not.
			if ( ( 'held' === e.status || e.date < C.today ) && ! showHeld.checked ) return false;   // draft students' bookings (1.203)
			return true;
		} );
	}

	/* ---- drawing ---- */
	function chip( e, timed ) {
		var cls = 'bftd-calv-ev is-' + ( 'ghl' === e.kind ? 'ghl' : e.status ) + ( e.draft ? ' is-draft' : '' ) + ( 'assessment' === e.type ? ' is-assessment' : '' );
		var who = C.tutors[ e.tutor ] || '';
		var says = e.title + ', ' + clock( e.start ) + ' to ' + clock( e.end ) + ( who ? ', ' + who : '' )
			+ ( e.type_label ? ', ' + e.type_label : '' )
			+ ( 'cancelled' === e.status ? ', cancelled' : 'held' === e.status ? ', taught' : 'moved' === e.status ? ', rescheduled to ' + e.moved_to : '' ) + ( 'ghl' === e.kind ? ', made in GoHighLevel' : '' );
		// The left border is the tutor's colour, the same as their swatch (1.206.1).
		var t = ( C.colours && C.colours[ e.tutor ] !== undefined ) ? C.colours[ e.tutor ] % 8 : 0;
		return '<button type="button" class="' + cls + '" data-t="' + t + '" data-i="' + state.events.indexOf( e ) + '"'
			+ ( timed ? ' style="' + timed + '"' : '' ) + ' aria-label="' + esc( says ) + '" title="' + esc( says ) + '">'
			// Time and type on one line, so the name has room (1.203.1).
			+ '<span class="bftd-calv-ev-t">' + esc( clock( e.start ) ) + ( e.type ? '<span class="bftd-calv-ev-y"> &middot; ' + ( 'assessment' === e.type ? 'Assessment' : 'Tutoring' ) + '</span>' : '' ) + '</span>'
			+ '<span class="bftd-calv-ev-n">' + esc( e.title ) + '</span>'
			+ ( 'moved' === e.status && e.moved_to ? '<span class="bftd-calv-ev-w">Moved to ' + esc( e.moved_to ) + '</span>' : '' )
			// The tutor only when more than one is shown: each has their own colour (1.203.1).
			+ ( C.manage && who && shownTutors().length > 1 ? '<span class="bftd-calv-ev-w">' + esc( who ) + '</span>' : '' )
			+ '</button>';
	}

	/* Side by side when they overlap: each event takes the first free lane. */
	function lanes( list ) {
		var ends = [];
		list.forEach( function ( e ) {
			var s = mins( e.start ), lane = 0;
			while ( ends[ lane ] !== undefined && ends[ lane ] > s ) lane++;
			ends[ lane ] = Math.max( mins( e.end ), s + 15 );
			e._lane = lane;
		} );
		list.forEach( function ( e ) { e._lanes = ends.length; } );
	}

	/*
	 * An hour is as tall as its bookings need (1.203.2). The text a booking
	 * holds depends on the person's text size (accessibility mode makes it
	 * larger), the column's width and the length of a name, none of which a
	 * fixed height can know. So the hours are drawn at HOUR, every booking
	 * that does not fit is measured, and the view is drawn once more with
	 * hours tall enough for the one that needed most. Never shorter than HOUR.
	 */
	function drawTimed( days, hour ) {
		var HOUR = hour || HOUR_MIN;
		var list = visible(), lo = 7 * 60, hi = 20 * 60;
		list.forEach( function ( e ) { lo = Math.min( lo, Math.floor( mins( e.start ) / 60 ) * 60 ); hi = Math.max( hi, Math.ceil( Math.max( mins( e.end ), mins( e.start ) + 15 ) / 60 ) * 60 ); } );
		var h = '<div class="bftd-calv-head" style="grid-template-columns:64px repeat(' + days.length + ',minmax(120px,1fr))"><span></span>';
		days.forEach( function ( s ) {
			var shut = state.closed[ s ];
			h += '<span class="' + ( s === C.today ? 'is-today' : '' ) + ( shut ? ' is-closed' : '' ) + '"><button type="button" class="button-link bftd-calv-goday" data-d="' + s + '">' + esc( dayName( s ) ) + '</button>'
				+ ( shut && 'Sunday' !== shut ? '<small class="bftd-calv-why">' + esc( shut ) + '</small>' : '' ) + '</span>';
		} );
		h += '</div><div class="bftd-calv-times" style="grid-template-columns:64px repeat(' + days.length + ',minmax(120px,1fr))"><div class="bftd-calv-hours">';
		for ( var m = lo; m < hi; m += 60 ) h += '<span style="height:' + HOUR + 'px">' + esc( clock( ( m / 60 < 10 ? '0' : '' ) + m / 60 + ':00' ) ) + '</span>';
		h += '</div>';
		days.forEach( function ( s ) {
			var today = list.filter( function ( e ) { return e.date === s; } );
			lanes( today );
			h += '<div class="bftd-calv-col' + ( s === C.today ? ' is-today' : '' ) + ( state.closed[ s ] ? ' is-closed' : '' ) + '" style="height:' + ( ( hi - lo ) / 60 * HOUR ) + 'px">';
			today.forEach( function ( e ) {
				var top = ( mins( e.start ) - lo ) / 60 * HOUR, len = Math.max( 22, ( Math.max( mins( e.end ), mins( e.start ) + 15 ) - mins( e.start ) ) / 60 * HOUR - 2 );
				var w = 100 / e._lanes;
				h += chip( e, 'top:' + top + 'px;height:' + len + 'px;left:calc(' + ( e._lane * w ) + '% + 2px);width:calc(' + w + '% - 4px)' );
			} );
			h += '</div>';
		} );
		h += '</div>';
		if ( ! list.length ) h += '<p class="bftd-calv-msg">No sessions ' + ( 'day' === state.view ? 'this day' : 'this week' ) + ' for the tutors shown.</p>';
		grid.innerHTML = h;
		if ( hour ) return;   // already sized to fit
		var need = 1;
		Array.prototype.forEach.call( grid.querySelectorAll( '.bftd-calv-col .bftd-calv-ev' ), function ( el ) {
			if ( el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 1 ) need = Math.max( need, ( el.scrollHeight + 4 ) / el.clientHeight );
		} );
		if ( need > 1 ) drawTimed( days, Math.min( 400, Math.ceil( HOUR * need ) ) );
	}

	function drawMonth() {
		var r = range(), list = visible(), month = state.date.slice( 0, 7 );
		var h = '<div class="bftd-calv-mhead">' + DAYS.map( function ( n ) { return '<span>' + n + '</span>'; } ).join( '' ) + '</div><div class="bftd-calv-month">';
		for ( var i = 0; i < 42; i++ ) {
			var s = add( r[0], i ), day = list.filter( function ( e ) { return e.date === s; } );
			var shut = state.closed[ s ];
			h += '<div class="bftd-calv-cell' + ( s.slice( 0, 7 ) !== month ? ' is-other' : '' ) + ( s === C.today ? ' is-today' : '' ) + ( shut ? ' is-closed' : '' ) + '">'
				+ '<button type="button" class="button-link bftd-calv-goday" data-d="' + s + '" aria-label="' + esc( dayName( s ) + ( shut ? ', ' + shut : '' ) ) + '">' + d( s ).getUTCDate() + '</button>'
				+ ( shut && 'Sunday' !== shut ? '<small class="bftd-calv-why">' + esc( shut ) + '</small>' : '' );
			day.slice( 0, 4 ).forEach( function ( e ) { h += chip( e, '' ); } );
			if ( day.length > 4 ) h += '<button type="button" class="button-link bftd-calv-goday bftd-calv-more" data-d="' + s + '">' + ( day.length - 4 ) + ' more</button>';
			h += '</div>';
		}
		grid.innerHTML = h + '</div>';
	}

	function draw() {
		closePop();
		var r = range();
		if ( 'month' === state.view ) return drawMonth();
		var days = []; for ( var s = r[0]; s <= r[1]; s = add( s, 1 ) ) days.push( s );
		drawTimed( days );
	}

	/* ---- one event: what can be done with it ---- */
	var opened = null;
	// A card in the middle of the screen over a dimmed, blurred calendar
	// (Karl, 1.209). The veil and the X both close it, and so does Escape.
	function closePop() {
		pop.hidden = true; pop.innerHTML = ''; pop.className = 'bftd-calv-pop';
		if ( veil ) veil.hidden = true;
		document.documentElement.classList.remove( 'bftd-calv-modal' );
		if ( opened ) { opened.focus(); opened = null; }
	}

	function tutorSelect( current ) {
		if ( ! C.manage ) return '';
		var o = '<label>Tutor <select name="tutor">';
		tutorIds.forEach( function ( id ) { o += '<option value="' + id + '"' + ( id === Number( current ) ? ' selected' : '' ) + '>' + esc( C.tutors[ id ] ) + '</option>'; } );
		return o + '</select></label>';
	}

	function openPop( e, btn ) {
		opened = btn;
		var who = C.tutors[ e.tutor ] || '';
		var length = mins( e.end ) - mins( e.start );
		var h = '<div class="bftd-calv-pop-head">'
			+ '<button type="button" class="bftd-calv-x bftd-calv-close" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
			+ ( e.type_label ? '<p class="bftd-calv-pop-kind">' + esc( e.type_label ) + ( e.draft ? ' &middot; Draft' : '' ) + '</p>' : '' )
			+ '<h2 id="bftd-calv-pop-title">' + esc( e.title ) + '</h2>'
			+ '<p class="bftd-calv-pop-when">' + esc( dayName( e.date ) + ', ' + clock( e.start ) + ' to ' + clock( e.end ) ) + '</p>'
			+ ( who ? '<p class="bftd-calv-pop-who">' + esc( who ) + '</p>' : '' )
			+ '</div><div class="bftd-calv-pop-body">';
		if ( 'moved' === e.status ) h += '<p class="description">Rescheduled to ' + esc( e.moved_to ) + '.</p>';
		if ( 'ghl' === e.kind ) h += '<p class="description">Made in GoHighLevel, not on a student\'s schedule.</p>';
		else if ( 'held' === e.status ) h += '<p class="description">Taught.</p>';
		else if ( 'cancelled' === e.status ) h += '<p class="description">Cancelled.</p>';
		else if ( e.in_ghl ) h += '<p class="description">In GoHighLevel.</p>';
		if ( e.can_edit ) {
			h += '<form class="bftd-calv-edit">'
				+ '<div class="bftd-calv-pop-row"><label>Date <input type="date" name="date" value="' + e.date + '" required></label>'
				+ '<label>Time <input type="time" name="time" value="' + e.start + '" step="300" required></label></div>'
				+ ( 'session' === e.kind ? '<div class="bftd-calv-pop-row"><label>Minutes <input type="number" name="minutes" value="' + length + '" min="15" step="5"></label>' + tutorSelect( e.tutor ) + '</div>' : '' )
				+ '<label>Reason <input type="text" name="reason" placeholder="For example: family away"></label>'
				+ '<p class="bftd-calv-said" role="alert"></p>'
				+ '<p class="bftd-calv-pop-do"><button type="submit" class="button button-primary">Save changes</button>'
				+ '<button type="button" class="button bftd-calv-cancel">Cancel this session</button></p>'
				+ '<p class="bftd-calv-pop-quiet"><button type="button" class="button-link bftd-calv-delete">Delete, telling nobody</button></p></form>';
		}
		h += '</div>';
		if ( e.url ) h += '<div class="bftd-calv-pop-foot"><a class="bftd-calv-pop-open" href="' + esc( e.url ) + '">Open ' + esc( e.student ) + ' &rarr;</a></div>';
		pop.innerHTML = h;
		// Its colour says what it is, the same as the booking that was tapped.
		pop.className = 'bftd-calv-pop ' + btn.className.split( ' ' ).filter( function ( c ) { return 0 === c.indexOf( 'is-' ); } ).join( ' ' );
		if ( veil ) veil.hidden = false;
		document.documentElement.classList.add( 'bftd-calv-modal' );
		pop.hidden = false;
		// The card itself takes focus, so a phone does not open its keyboard.
		pop.focus();

		var form = pop.querySelector( '.bftd-calv-edit' );
		if ( ! form ) return;
		var said = form.querySelector( '.bftd-calv-said' );
		function send( what ) {
			var f = form.elements, data;
			if ( 'ghl' === e.kind ) {
				data = { action: 'bftd_calendar_ghl', id: e.id, what: what, date: f.date.value, time: f.time.value };
			} else {
				data = { action: 'bftd_schedule_except', student_id: e.student_id, key: e.key, what: what, reason: f.reason.value };
				if ( 'move' === what ) {
					data.date = f.date.value; data.time = f.time.value;
					if ( f.minutes ) data.minutes = f.minutes.value;
					if ( f.tutor ) data.tutor = f.tutor.value;
				}
			}
			said.textContent = 'Saving…';
			post( data ).then( function ( res ) {
				if ( res && res.success ) { closePop(); load(); document.dispatchEvent( new Event( 'bftd:schedule-changed' ) ); return; }
				said.textContent = res && res.data && res.data.message ? res.data.message : 'That was not saved.';
			} ).catch( function () { said.textContent = 'That was not saved. Check the connection and try again.'; } );
		}
		form.addEventListener( 'submit', function ( ev ) { ev.preventDefault(); send( 'move' ); } );
		form.querySelector( '.bftd-calv-cancel' ).addEventListener( 'click', function () {
			if ( window.confirm( 'Cancel ' + e.title + ' on ' + dayName( e.date ) + '?' ) ) send( 'cancel' );
		} );
		// Gone with no cancellation on record and no message to anyone (1.201).
		form.querySelector( '.bftd-calv-delete' ).addEventListener( 'click', function () {
			if ( window.confirm( 'Delete ' + e.title + ' on ' + dayName( e.date ) + '? Nobody is told, and it is not recorded as cancelled.' ) ) send( 'delete' );
		} );
	}

	/* ---- wiring ---- */
	grid.addEventListener( 'click', function ( ev ) {
		var go = ev.target.closest( '.bftd-calv-goday' );
		if ( go ) { state.date = go.getAttribute( 'data-d' ); state.view = 'day'; viewEl.value = 'day'; remember(); load(); return; }
		var b = ev.target.closest( '.bftd-calv-ev' );
		if ( b ) openPop( state.events[ Number( b.getAttribute( 'data-i' ) ) ], b );
	} );
	pop.addEventListener( 'click', function ( ev ) { if ( ev.target.closest( '.bftd-calv-close' ) ) closePop(); } );
	if ( veil ) veil.addEventListener( 'click', closePop );
	// Tab stays inside the card while it is open.
	pop.addEventListener( 'keydown', function ( ev ) {
		if ( 'Tab' !== ev.key ) return;
		var f = Array.prototype.filter.call( pop.querySelectorAll( 'a[href], button, input, select, textarea' ), function ( x ) { return ! x.disabled && x.offsetParent; } );
		if ( ! f.length ) return;
		var first = f[0], last = f[ f.length - 1 ];
		if ( ev.shiftKey && ( document.activeElement === first || document.activeElement === pop ) ) { ev.preventDefault(); last.focus(); }
		else if ( ! ev.shiftKey && document.activeElement === last ) { ev.preventDefault(); first.focus(); }
	} );
	document.addEventListener( 'keydown', function ( ev ) { if ( 'Escape' === ev.key && ! pop.hidden ) closePop(); } );
	function step( n ) {
		state.date = 'day' === state.view ? add( state.date, n ) : 'week' === state.view ? add( state.date, 7 * n )
			: ( function () { var x = d( monthStart( state.date ) ); x.setUTCMonth( x.getUTCMonth() + n ); return ymd( x ); } )();
		load();
	}
	root.querySelector( '.bftd-calv-prev' ).addEventListener( 'click', function () { step( -1 ); } );
	root.querySelector( '.bftd-calv-next' ).addEventListener( 'click', function () { step( 1 ); } );
	root.querySelector( '.bftd-calv-today' ).addEventListener( 'click', function () { state.date = C.today; load(); } );
	viewEl.addEventListener( 'change', function () { state.view = viewEl.value; remember(); load(); } );
	[ showGhl, showCancel, showMoved, showDraft, showHeld ].forEach( function ( x ) { x.addEventListener( 'change', function () { remember(); draw(); } ); } );
	tutorBoxes.forEach( function ( b ) { b.addEventListener( 'change', function () { remember(); load(); } ); } );
	var all = root.querySelector( '.bftd-calv-all' ), none = root.querySelector( '.bftd-calv-none' );
	if ( all ) all.addEventListener( 'click', function () { tutorBoxes.forEach( function ( b ) { b.checked = true; } ); remember(); load(); } );
	if ( none ) none.addEventListener( 'click', function () { tutorBoxes.forEach( function ( b ) { b.checked = false; } ); remember(); load(); } );

	/* ---- adding a booking (1.203): in the side panel, covering nothing ---- */
	var addBtn = root.querySelector( '.bftd-calv-addbtn' ), addForm = root.querySelector( '.bftd-calv-add' );
	if ( addBtn && addForm ) {
		var addSaid = addForm.querySelector( '.bftd-calv-said' ), untilBox = addForm.querySelector( '.bftd-calv-add-until' );
		function openAdd( open ) {
			addForm.hidden = ! open;
			addBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				if ( ! addForm.elements.date.value ) addForm.elements.date.value = state.closed[ state.date ] ? '' : state.date;
				addForm.elements.student_id.focus();
			}
		}
		addBtn.addEventListener( 'click', function () { openAdd( addForm.hidden ); } );
		addForm.querySelector( '.bftd-calv-add-close' ).addEventListener( 'click', function () { openAdd( false ); addBtn.focus(); } );
		addForm.elements.repeat.addEventListener( 'change', function () { untilBox.hidden = 'weekly' !== addForm.elements.repeat.value; } );
		// Say at once when the date picked is closed; the server refuses it anyway.
		addForm.elements.date.addEventListener( 'change', function () {
			var v = addForm.elements.date.value;
			addSaid.textContent = v && 0 === d( v ).getUTCDay() ? 'Nothing is booked on a Sunday. Pick another date.'
				: ( v && state.closed[ v ] ? 'Nothing is booked on ' + state.closed[ v ] + '. Pick another date.' : '' );
		} );
		addForm.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var f = addForm.elements;
			var data = { action: 'bftd_calendar_book', student_id: f.student_id.value, type: f.type.value, date: f.date.value, time: f.time.value,
				minutes: f.minutes.value, repeat: f.repeat.value, until: 'weekly' === f.repeat.value ? f.until.value : '' };
			if ( f.tutor ) data.tutor = f.tutor.value;
			addSaid.textContent = 'Booking…';
			post( data ).then( function ( res ) {
				if ( res && res.success ) {
					addSaid.textContent = 'Booked. Confirm it in the banner when you are done, and the family is told.';
					state.date = data.date; load();
					document.dispatchEvent( new Event( 'bftd:schedule-changed' ) );
					return;
				}
				addSaid.textContent = res && res.data && res.data.message ? res.data.message : 'That was not booked.';
			} ).catch( function () { addSaid.textContent = 'That was not booked. Check the connection and try again.'; } );
		} );
	}

	load();
}() );
