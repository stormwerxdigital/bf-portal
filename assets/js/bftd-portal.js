/* global BFTD */
( function () {
	'use strict';

	var MAX_FILES = 5;
	var staged    = new WeakMap(); // composer element -> File[]

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function fmtSize( n ) {
		if ( n < 1024 ) return n + ' B';
		if ( n < 1048576 ) return Math.round( n / 1024 ) + ' KB';
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}

	function extLabel( name, type ) {
		if ( ( type || '' ).indexOf( 'image' ) === 0 ) return 'IMG';
		var m = /\.([a-z0-9]+)$/i.exec( name || '' );
		return m ? m[1].toUpperCase().slice( 0, 4 ) : 'FILE';
	}

	/* ----------------------------------------------------------------
	 * Formatting. execCommand is deprecated but is still the only thing
	 * every browser implements for a contenteditable box, and the value is
	 * filtered server side anyway, so nothing here is trusted on arrival.
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.bf-rt-bar button[data-cmd]' );
		if ( ! btn ) return;
		e.preventDefault();
		var box = $( '.bf-rt-in', btn.closest( '.bf-rt' ) );
		if ( box ) box.focus();
		document.execCommand( btn.dataset.cmd, false, null );
	} );

	/* ----------------------------------------------------------------
	 * Attachments: the attach button, a pasted screenshot, a dropped file
	 * ---------------------------------------------------------------- */

	function list( rt ) {
		if ( ! staged.has( rt ) ) staged.set( rt, [] );
		return staged.get( rt );
	}

	function drawTray( rt ) {
		var tray  = $( '.bf-rt-files', rt );
		var files = list( rt );
		tray.innerHTML = '';
		tray.hidden = files.length === 0;

		files.forEach( function ( f, i ) {
			var chip = document.createElement( 'span' );
			chip.className = 'bf-att';
			var isImg = ( f.type || '' ).indexOf( 'image' ) === 0;
			chip.innerHTML =
				( isImg ? '<img alt="">' : '<span class="bf-att-ic">' + extLabel( f.name, f.type ) + '</span>' ) +
				'<span class="bf-att-n"></span><span class="bf-att-s">' + fmtSize( f.size ) + '</span>' +
				'<button class="bf-att-x" type="button" data-i="' + i + '" aria-label="Remove attachment">&times;</button>';
			$( '.bf-att-n', chip ).textContent = f.name || 'pasted-screenshot.png';
			if ( isImg ) {
				var r = new FileReader();
				r.onload = function ( ev ) {
					var im = $( 'img', chip );
					if ( im ) im.src = ev.target.result;
				};
				r.readAsDataURL( f );
			}
			tray.appendChild( chip );
		} );

		var hint = $( '.bf-rt-hint', rt );
		if ( hint ) {
			hint.textContent = files.length >= MAX_FILES
				? 'That is the limit of ' + MAX_FILES + ' files for one message.'
				: 'Paste a screenshot, drag files in, or attach. Up to ' + MAX_FILES + '.';
		}
	}

	function addFiles( rt, files ) {
		if ( ! rt || ! files ) return;
		var current = list( rt );
		Array.prototype.slice.call( files ).forEach( function ( f ) {
			if ( current.length >= MAX_FILES ) return;
			current.push( f );
		} );
		drawTray( rt );
	}

	document.addEventListener( 'click', function ( e ) {
		var attach = e.target.closest( '.bf-rt-attach' );
		if ( attach ) {
			e.preventDefault();
			$( '.bf-rt-file', attach.closest( '.bf-rt' ) ).click();
			return;
		}
		var x = e.target.closest( '.bf-att-x' );
		if ( x ) {
			e.preventDefault();
			var rt = x.closest( '.bf-rt' );
			list( rt ).splice( parseInt( x.dataset.i, 10 ), 1 );
			drawTray( rt );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var input = e.target.closest( '.bf-rt-file' );
		if ( ! input ) return;
		addFiles( input.closest( '.bf-rt' ), input.files );
		input.value = '';
	} );

	document.addEventListener( 'paste', function ( e ) {
		var box = e.target.closest && e.target.closest( '.bf-rt-in' );
		if ( ! box ) return;
		var items = ( e.clipboardData || {} ).items || [];
		var files = [];
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ].kind === 'file' ) {
				var f = items[ i ].getAsFile();
				if ( f ) files.push( f );
			}
		}
		if ( files.length ) {
			e.preventDefault();
			addFiles( box.closest( '.bf-rt' ), files );
		}
	} );

	[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
		document.addEventListener( ev, function ( e ) {
			var rt = e.target.closest && e.target.closest( '.bf-rt' );
			if ( ! rt ) return;
			e.preventDefault();
			rt.classList.add( 'is-drop' );
		} );
	} );

	[ 'dragleave', 'drop' ].forEach( function ( ev ) {
		document.addEventListener( ev, function ( e ) {
			var rt = e.target.closest && e.target.closest( '.bf-rt' );
			if ( ! rt ) return;
			if ( ev === 'drop' ) {
				e.preventDefault();
				addFiles( rt, ( e.dataTransfer || {} ).files );
			}
			rt.classList.remove( 'is-drop' );
		} );
	} );

	/* ----------------------------------------------------------------
	 * Posting
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.bf-rt-post' );
		if ( ! btn ) return;
		e.preventDefault();

		var rt     = btn.closest( '.bf-rt' );
		var thread = btn.closest( '.bf-thread' );
		var box    = $( '.bf-rt-in', rt );
		var body   = box.innerHTML.trim();
		var files  = list( rt );

		if ( ! body.replace( /<[^>]*>/g, '' ).trim() && ! files.length ) {
			box.focus();
			return;
		}

		var data = new FormData();
		data.append( 'action', 'bftd_post_message' );
		data.append( 'nonce', BFTD.nonce );
		data.append( 'post_id', thread.dataset.post );
		data.append( 'section', thread.dataset.section );
		data.append( 'body', body );
		files.forEach( function ( f ) { data.append( 'files[]', f ); } );

		btn.disabled = true;
		btn.textContent = 'Posting…';

		fetch( BFTD.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				btn.disabled = false;
				btn.textContent = 'Post';
				if ( ! res.success ) {
					window.alert( ( res.data && res.data.message ) || 'That did not post.' );
					return;
				}
				appendMessage( thread, res.data );
				box.innerHTML = '';
				staged.set( rt, [] );
				drawTray( rt );
			} )
			.catch( function () {
				btn.disabled = false;
				btn.textContent = 'Post';
				window.alert( 'That did not post. Check your connection and try again.' );
			} );
	} );

	function appendMessage( thread, data ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'bf-msg is-mine';

		var files = '';
		( data.files || [] ).forEach( function ( f ) {
			files += '<a class="bf-att" href="' + f.url + '" target="_blank" rel="noopener">' +
				( f.thumb ? '<img alt="" src="' + f.thumb + '">' : '<span class="bf-att-ic">' + extLabel( f.name, f.mime ) + '</span>' ) +
				'<span class="bf-att-n"></span><span class="bf-att-s">' + f.size + '</span></a>';
		} );

		wrap.innerHTML =
			'<span class="bf-avatar"></span><div class="bf-msg-b">' +
			'<p class="bf-msg-h"><b></b><time>just now</time></p>' +
			'<div class="bf-msg-t">' + data.html + '</div>' +
			( files ? '<div class="bf-msg-att">' + files + '</div>' : '' ) +
			'</div>';

		// Names and file names are set as text, never interpolated into the
		// markup above, so a display name containing markup stays inert.
		$( '.bf-msg-h b', wrap ).textContent = data.who || 'You';
		$( '.bf-avatar', wrap ).textContent = ( data.who || '?' ).split( /\s+/ ).slice( 0, 2 )
			.map( function ( p ) { return p.charAt( 0 ).toUpperCase(); } ).join( '' );
		$$( '.bf-att-n', wrap ).forEach( function ( el, i ) {
			el.textContent = ( data.files && data.files[ i ] ) ? data.files[ i ].name : '';
		} );

		var empty = $( '.bf-thread-empty', thread );
		if ( empty ) empty.remove();
		thread.insertBefore( wrap, $( '.bf-rt', thread ) );
	}

	/* Deep links from an email or a notification open on the right section. */
	window.addEventListener( 'load', function () {
		if ( ! window.location.hash ) return;
		var el = document.getElementById( window.location.hash.slice( 1 ) );
		if ( el ) el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

} )();

/* ==========================================================================
   Priority items and For review
   Any control nested inside a clickable row calls stopPropagation on both
   click and keydown, separately. Handling only click leaves a keyboard user
   toggling the row when they meant to open the details.
   ========================================================================== */
( function () {
	'use strict';

	function post( action, data ) {
		var body = new URLSearchParams( Object.assign( { action: action, nonce: BFTD.nonce }, data ) );
		return fetch( BFTD.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).then( function ( r ) { return r.json(); } );
	}

	function stop( e ) { e.stopPropagation(); }

	document.addEventListener( 'click', function ( e ) {

		var head = e.target.closest( '.bf-items-head' );
		if ( head ) {
			var card = head.closest( '.bf-items' );
			var open = card.classList.toggle( 'is-open' );
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			return;
		}

		var det = e.target.closest( '.bf-item-det' );
		if ( det ) {
			stop( e );
			det.closest( '.bf-item' ).classList.toggle( 'is-det-open' );
			return;
		}

		var talk = e.target.closest( '.bf-item-talk' );
		if ( talk ) {
			stop( e );
			talk.closest( '.bf-item' ).classList.toggle( 'is-talk-open' );
			return;
		}

		// A link inside the row is a link, not a toggle.
		if ( e.target.closest( '.bf-item-link' ) || e.target.closest( '.bf-item-thread' ) ) {
			stop( e );
			return;
		}

		var row = e.target.closest( '.bf-item-row' );
		if ( row ) {
			toggleItem( row );
			return;
		}

		var x = e.target.closest( '.bf-banner-x' );
		if ( x ) {
			var banner = x.closest( '.bf-banner' );
			banner.remove();
			post( 'bftd_dismiss_banner', {} );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) return;

		// The same nesting problem, on the keyboard path. These have to be
		// stopped separately from the click handler above, not instead of it.
		if ( e.target.closest( '.bf-item-det' ) || e.target.closest( '.bf-item-talk' ) ||
			e.target.closest( '.bf-item-link' ) || e.target.closest( '.bf-item-thread' ) ) {
			stop( e );
			return;
		}

		var row = e.target.closest( '.bf-item-row' );
		if ( ! row ) return;
		e.preventDefault();
		toggleItem( row );
	} );

	function toggleItem( row ) {
		var item = row.closest( '.bf-item' );
		var card = row.closest( '.bf-items' );
		if ( item.dataset.busy ) return;
		item.dataset.busy = '1';

		// Flip it now and put it back if the server disagrees. Waiting on a
		// round trip to tick a checkbox reads as broken on a phone.
		var wasDone = item.classList.contains( 'is-done' );
		item.classList.toggle( 'is-done' );
		row.setAttribute( 'aria-pressed', wasDone ? 'false' : 'true' );

		post( 'bftd_toggle_item', {
			student_id: card.dataset.student,
			list: card.dataset.list,
			item_id: item.dataset.item
		} ).then( function ( res ) {
			delete item.dataset.busy;
			if ( ! res.success ) {
				item.classList.toggle( 'is-done', wasDone );
				row.setAttribute( 'aria-pressed', wasDone ? 'true' : 'false' );
				return;
			}
			var meta = item.querySelector( '.bf-item-meta' );
			if ( meta ) meta.innerHTML = res.data.meta || '';
			recount( card );
		} ).catch( function () {
			delete item.dataset.busy;
			item.classList.toggle( 'is-done', wasDone );
			row.setAttribute( 'aria-pressed', wasDone ? 'true' : 'false' );
		} );
	}

	function recount( card ) {
		var items = card.querySelectorAll( '.bf-item' );
		var done  = card.querySelectorAll( '.bf-item.is-done' ).length;
		var todo  = items.length - done;

		var badge = card.querySelector( '.bf-items-badge' );
		var count = card.querySelector( '.bf-items-count' );
		if ( count ) count.textContent = done + ' / ' + items.length;
		if ( ! badge ) return;

		if ( todo ) {
			badge.classList.remove( 'is-clear' );
			badge.innerHTML = '<b>' + todo + '</b> to do';
		} else {
			badge.classList.add( 'is-clear' );
			badge.textContent = 'All done';
		}
	}

} )();

/* ==========================================================================
   Staff family picker
   A combobox: type to search by name, username or email, arrow keys to move,
   Enter to open. The list is fetched once with no term so it is useful before
   the first keystroke, and every later request is debounced and sequenced —
   a slow early response can never overwrite a newer one.
   ========================================================================== */
( function () {
	'use strict';

	var input = document.querySelector( '.bf-find-in' );
	if ( ! input ) return;

	var wrap  = input.closest( '.bf-find' );
	var list  = wrap.querySelector( '.bf-find-list' );
	var none  = wrap.querySelector( '.bf-find-none' );
	var spin  = wrap.querySelector( '.bf-find-spin' );
	var timer = null;
	var seq   = 0;
	var index = -1;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function render( results ) {
		list.innerHTML = '';
		index = -1;

		if ( ! results.length ) {
			none.hidden = false;
			input.setAttribute( 'aria-expanded', 'false' );
			return;
		}
		none.hidden = true;

		results.forEach( function ( r, i ) {
			var kids = r.students.map( function ( s ) {
				return '<span class="bf-find-kid">' + esc( s.name ) + '</span>';
			} ).join( '' );

			var li = document.createElement( 'li' );
			li.setAttribute( 'role', 'option' );
			li.id = 'bf-find-opt-' + i;
			li.setAttribute( 'aria-selected', 'false' );
			li.innerHTML =
				'<button type="button" class="bf-find-item" data-url="' + esc( r.url ) + '">' +
				'<span class="bf-find-name">' + esc( r.name ) + '</span>' +
				'<span class="bf-find-meta">' + esc( r.relation ) + ' · ' + esc( r.email ) +
				( r.login && r.login !== r.name ? ' · ' + esc( r.login ) : '' ) + '</span>' +
				( kids ? '<span class="bf-find-kids">' + kids + '</span>' : '' ) +
				'</button>';
			list.appendChild( li );
		} );

		input.setAttribute( 'aria-expanded', 'true' );
	}

	function search( term ) {
		var mine = ++seq;
		spin.hidden = false;

		var body = new URLSearchParams( {
			action: 'bftd_find_family',
			nonce: BFTD.nonce,
			term: term
		} );

		fetch( BFTD.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				// A stale response must never replace a newer one.
				if ( mine !== seq ) return;
				spin.hidden = true;
				render( res.success ? res.data.results : [] );
			} )
			.catch( function () {
				if ( mine !== seq ) return;
				spin.hidden = true;
				none.hidden = false;
				none.textContent = 'The search did not respond. Try again.';
			} );
	}

	input.addEventListener( 'input', function () {
		window.clearTimeout( timer );
		var term = input.value.trim();
		timer = window.setTimeout( function () { search( term ); }, 220 );
	} );

	function move( step ) {
		var items = list.querySelectorAll( '.bf-find-item' );
		if ( ! items.length ) return;

		if ( index >= 0 ) {
			items[ index ].classList.remove( 'is-active' );
			items[ index ].closest( 'li' ).setAttribute( 'aria-selected', 'false' );
		}
		index = ( index + step + items.length ) % items.length;
		items[ index ].classList.add( 'is-active' );

		var li = items[ index ].closest( 'li' );
		li.setAttribute( 'aria-selected', 'true' );
		input.setAttribute( 'aria-activedescendant', li.id );
		li.scrollIntoView( { block: 'nearest' } );
	}

	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'ArrowDown' ) { e.preventDefault(); move( 1 ); return; }
		if ( e.key === 'ArrowUp' )   { e.preventDefault(); move( -1 ); return; }
		if ( e.key === 'Escape' )    { list.innerHTML = ''; input.setAttribute( 'aria-expanded', 'false' ); return; }
		if ( e.key === 'Enter' ) {
			var items = list.querySelectorAll( '.bf-find-item' );
			var pick  = index >= 0 ? items[ index ] : items[0];
			if ( pick ) { e.preventDefault(); window.location = pick.dataset.url; }
		}
	} );

	list.addEventListener( 'click', function ( e ) {
		var item = e.target.closest( '.bf-find-item' );
		if ( item ) window.location = item.dataset.url;
	} );

	// Useful before a single keystroke.
	search( '' );

} )();

/* ==========================================================================
   Chart hover
   A chart on a screen is expected to answer "what is that point" when you
   point at it. Touch and keyboard get the same answer: the circles are
   focusable, so the tooltip follows the focus ring as well as the pointer.
   ========================================================================== */
( function () {
	'use strict';

	function place( tip, pt ) {
		var svg  = pt.ownerSVGElement;
		if ( ! svg ) return;
		var wrap = tip.parentElement;
		var r    = svg.getBoundingClientRect();
		var wr   = wrap.getBoundingClientRect();
		var vb   = svg.viewBox.baseVal;
		if ( ! vb || ! vb.width ) return;

		var sx = r.width / vb.width;
		var sy = r.height / vb.height;

		tip.innerHTML = '';
		var b = document.createElement( 'b' );
		b.textContent = pt.getAttribute( 'data-lab' ) || '';
		tip.appendChild( b );
		tip.appendChild( document.createTextNode( pt.getAttribute( 'data-val' ) || '' ) );

		var left = ( r.left - wr.left ) + ( parseFloat( pt.getAttribute( 'cx' ) ) * sx );
		var top  = ( r.top  - wr.top  ) + ( parseFloat( pt.getAttribute( 'cy' ) ) * sy );

		tip.style.left = left + 'px';
		tip.style.top  = top + 'px';
		tip.hidden = false;
		tip.classList.add( 'is-on' );

		// Keep it inside the card rather than off the edge of the page.
		var tr = tip.getBoundingClientRect();
		if ( tr.left < wr.left ) tip.style.left = ( left + ( wr.left - tr.left ) + 4 ) + 'px';
		if ( tr.right > wr.right ) tip.style.left = ( left - ( tr.right - wr.right ) - 4 ) + 'px';
	}

	function wire( wrap ) {
		var tip = wrap.querySelector( '.bf-tip' );
		if ( ! tip ) return;

		var pts = wrap.querySelectorAll( '.bf-pts circle' );
		Array.prototype.forEach.call( pts, function ( pt ) {
			// Reachable without a mouse.
			pt.setAttribute( 'tabindex', '0' );
			pt.setAttribute( 'role', 'img' );
			pt.setAttribute( 'aria-label',
				( pt.getAttribute( 'data-lab' ) || '' ) + ', ' + ( pt.getAttribute( 'data-val' ) || '' ) );

			var on  = function () { place( tip, pt ); };
			var off = function () { tip.classList.remove( 'is-on' ); };

			pt.addEventListener( 'mouseenter', on );
			pt.addEventListener( 'focus', on );
			pt.addEventListener( 'mouseleave', off );
			pt.addEventListener( 'blur', off );
			pt.addEventListener( 'touchstart', function ( e ) { e.preventDefault(); on(); }, { passive: false } );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) tip.classList.remove( 'is-on' );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.bf-chartwrap' ), wire );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/* ==========================================================================
   Sections and cards that open
   The markup is open by default so the report is complete without scripts and
   correct on paper. This closes what should start closed, then toggles.
   ========================================================================== */
( function () {
	'use strict';

	function init() {
		var secs = document.querySelectorAll( '.bf-docsec' );
		if ( ! secs.length && ! document.querySelector( '.bf-skill' ) ) return;

		document.documentElement.classList.remove( 'no-js' );

		function toggle( el, head ) {
			var open = el.classList.toggle( 'is-open' );
			if ( head ) head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			return open;
		}

		document.addEventListener( 'click', function ( e ) {
			var head = e.target.closest( '.bf-docsec > .bf-sec-h, .bf-skill-head' );
			if ( ! head ) return;
			e.preventDefault();
			toggle( head.closest( '.bf-docsec, .bf-skill' ), head );
		} );

		// A link from a card to its full section opens that section first,
		// otherwise the page jumps to a closed box and looks broken.
		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a[href^="#"]' );
			if ( ! a ) return;
			var target = document.getElementById( a.getAttribute( 'href' ).slice( 1 ) );
			if ( ! target || ! target.classList.contains( 'bf-docsec' ) ) return;
			target.classList.add( 'is-open' );
			var h = target.querySelector( '.bf-sec-h' );
			if ( h ) h.setAttribute( 'aria-expanded', 'true' );
		} );

		// Printing wants everything open, and browsers fire this in time.
		window.addEventListener( 'beforeprint', function () {
			Array.prototype.forEach.call( document.querySelectorAll( '.bf-docsec, .bf-skill' ), function ( el ) {
				el.classList.add( 'is-open' );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', init );
	else init();
} )();

/* ---- a piece of work, full size ----------------------------------------
 *
 * The link already opens the image on its own, so this only upgrades it. A
 * parent squinting at their child's handwriting in a 300px thumbnail is the
 * whole reason it exists.
 *
 * Escape closes it, the backdrop closes it, focus goes to the dialog and comes
 * back to the thumbnail afterwards. Nothing is preloaded: the full size is
 * fetched when somebody asks for it.
 */
jQuery( function ( $ ) {
	var $box = null;
	var $from = null;

	function close() {
		if ( ! $box ) return;
		$box.remove();
		$box = null;
		$( document ).off( 'keydown.bftdshot' );
		if ( $from ) { $from.trigger( 'focus' ); $from = null; }
	}

	$( document ).on( 'click', 'a.shot-open', function ( e ) {
		// A modified click is somebody deliberately opening a tab.
		if ( e.metaKey || e.ctrlKey || e.shiftKey || 1 === e.which - 1 ) return;
		e.preventDefault();

		$from = $( this );
		var href = $( this ).attr( 'href' );
		var alt  = $( this ).find( 'img' ).attr( 'alt' ) || 'A piece of work';

		$box = $(
			'<div class="bftd-shotbox" role="dialog" aria-modal="true" aria-label="' +
			$( '<i>' ).text( alt ).html() + '">' +
			'<button type="button" class="bftd-shotbox-x" aria-label="Close">&times;</button>' +
			'<img alt="">' +
			'</div>'
		);
		$box.find( 'img' ).attr( { src: href, alt: alt } );
		$( 'body' ).append( $box );
		$box.find( '.bftd-shotbox-x' ).trigger( 'focus' );

		$box.on( 'click', function ( ev ) {
			// The backdrop, not the picture.
			if ( ev.target === $box[0] || $( ev.target ).hasClass( 'bftd-shotbox-x' ) ) close();
		} );
		$( document ).on( 'keydown.bftdshot', function ( ev ) {
			if ( 27 === ev.keyCode ) close();
		} );
	} );
} );
