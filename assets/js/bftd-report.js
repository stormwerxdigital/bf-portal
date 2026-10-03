/* ==========================================================================
   Report interactions, as the prototype behaves.

   Collapsible document sections, expanding skill cards, the to-do cards, and
   the hover readout on the fluency chart. The markup ships open so the report
   is complete without scripts and correct on paper; this closes what should
   start closed and then toggles.
   ========================================================================== */
( function () {
	'use strict';

	/** Anybody who has asked their system not to animate things means it. */
	function still() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function init() {
		var root = document.querySelector( '.bf-report' );
		if ( ! root ) return;

		root.classList.add( 'has-js' );

		/* ---- the two charts, behind tabs ----

		   Both panels ship visible so a report with scripts blocked, or on
		   paper, is both charts down the page rather than one chart and a row
		   of dead buttons. Closing the rest is the first thing this does. */
		var strips = root.querySelectorAll( '.chart-tabs' );
		Array.prototype.forEach.call( strips, function ( card ) {
			var btns = card.querySelectorAll( '.tabbtn' );
			var show = function ( anchor ) {
				Array.prototype.forEach.call( card.querySelectorAll( '.tabpanel' ), function ( p ) {
					var on = ( p.id === 'panel-' + anchor );
					p.hidden = ! on;
				} );
				Array.prototype.forEach.call( btns, function ( b ) {
					var on = ( b.id === 'tab-' + anchor );
					b.classList.toggle( 'is-on', on );
					b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					// Only the selected tab is in the tab order; the arrow
					// keys move between them, which is how a tablist works.
					b.setAttribute( 'tabindex', on ? '0' : '-1' );
				} );
			};

			card.classList.add( 'is-tabbed' );
			if ( btns.length ) show( btns[0].id.replace( /^tab-/, '' ) );

			card.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '.tabbtn' );
				if ( ! b ) return;
				show( b.id.replace( /^tab-/, '' ) );
			} );

			card.addEventListener( 'keydown', function ( e ) {
				if ( 'ArrowLeft' !== e.key && 'ArrowRight' !== e.key ) return;
				var b = e.target.closest( '.tabbtn' );
				if ( ! b ) return;
				e.preventDefault();
				var list = Array.prototype.slice.call( btns );
				var i    = list.indexOf( b ) + ( 'ArrowRight' === e.key ? 1 : -1 );
				var next = list[ ( i + list.length ) % list.length ];
				show( next.id.replace( /^tab-/, '' ) );
				next.focus();
			} );
		} );

		/* ---- the skills list, folded ----

		   Every row ships visible so a printed report, or one with scripts
		   blocked, is the whole library rather than ten rows and a button
		   that does nothing. Folding the tail away is the first thing this
		   does, and the button only appears because .has-js is on the root. */
		Array.prototype.forEach.call( root.querySelectorAll( '.skl-all' ), function ( btn ) {
			var list = btn.previousElementSibling;
			while ( list && ! list.classList.contains( 'skl' ) ) list = list.previousElementSibling;
			if ( ! list ) return;

			var more = list.querySelectorAll( '.skl-more' );
			var fold = function ( shut ) {
				Array.prototype.forEach.call( more, function ( r ) { r.hidden = shut; } );
				btn.setAttribute( 'aria-expanded', shut ? 'false' : 'true' );
				btn.textContent = shut ? btn.getAttribute( 'data-more' ) : btn.getAttribute( 'data-less' );
			};

			fold( true );
			btn.addEventListener( 'click', function () {
				fold( 'true' === btn.getAttribute( 'aria-expanded' ) );
			} );
		} );

		/* ---- anything that opens ---- */
		root.addEventListener( 'click', function ( e ) {
			/* A summary card promises "tap to see what it measures, how your
			   child did, and what we will do about it", and that is the full
			   section further down the page. The card's own panel is not
			   drawn at all, so without this the card is a control that looks
			   pressable and does nothing. */
			var card = e.target.closest( '.skill-head' );
			if ( card ) {
				e.preventDefault();
				var sid = card.closest( '.skill' );
				sid = sid && sid.getAttribute( 'data-section' );
				var sec = sid && document.getElementById( sid );
				if ( ! sec ) return;
				sec.classList.add( 'is-open' );
				var sh = sec.querySelector( '.docsec-h' );
				if ( sh ) sh.setAttribute( 'aria-expanded', 'true' );
				sec.scrollIntoView( { behavior: still() ? 'auto' : 'smooth', block: 'start' } );
				// The page moves; the keyboard has to move with it, or the
				// next Tab carries on from the card that was just left.
				if ( sh ) { sh.setAttribute( 'tabindex', '-1' ); sh.focus( { preventScroll: true } ); }
				return;
			}

			var head = e.target.closest( '.docsec-h, .pi-head, .session-head' );
			if ( head ) {
				var box = head.closest( '.docsec, .pi-card, .session' );
				if ( box ) {
					var open = box.classList.toggle( 'is-open' );
					head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}
				e.preventDefault();
				return;
			}

			// A point's detail is its own toggle, sitting inside a card that is
			// itself a toggle, so this must not bubble up and close the card.
			var det = e.target.closest( '.pi-det' );
			if ( det ) {
				e.stopPropagation();
				e.preventDefault();
				var dopen = det.classList.toggle( 'is-open' );
				det.setAttribute( 'aria-expanded', dopen ? 'true' : 'false' );
				return;
			}

			// A card links down to its full section; that section has to be
			// open before the jump, or the page lands on a closed box.
			var a = e.target.closest( 'a[href^="#"], .jump button[data-jump]' );
			if ( ! a ) return;
			var id = a.dataset && a.dataset.jump ? a.dataset.jump : a.getAttribute( 'href' ).slice( 1 );
			var target = document.getElementById( id );
			if ( ! target ) return;
			if ( target.classList.contains( 'docsec' ) || target.classList.contains( 'skill' ) ) {
				target.classList.add( 'is-open' );
				var h = target.querySelector( '.docsec-h, .skill-head' );
				if ( h ) h.setAttribute( 'aria-expanded', 'true' );
			}
			if ( a.dataset && a.dataset.jump ) {
				e.preventDefault();
				target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} );

		/* ---- the reading speed readout ---- */
		Array.prototype.forEach.call( root.querySelectorAll( '.chartwrap' ), function ( wrap ) {
			var tip = wrap.querySelector( '.tip' );
			if ( ! tip ) return;

			Array.prototype.forEach.call( wrap.querySelectorAll( '.pts circle' ), function ( pt ) {
				pt.setAttribute( 'tabindex', '0' );
				pt.setAttribute( 'role', 'img' );
				pt.setAttribute( 'aria-label',
					( pt.getAttribute( 'data-lab' ) || '' ) + ', ' + ( pt.getAttribute( 'data-val' ) || '' ) );

				function show() {
					var svg = pt.ownerSVGElement;
					if ( ! svg || ! svg.viewBox.baseVal.width ) return;
					var r = svg.getBoundingClientRect();
					var w = wrap.getBoundingClientRect();
					var vb = svg.viewBox.baseVal;
					tip.innerHTML = '';
					var b = document.createElement( 'b' );
					b.textContent = pt.getAttribute( 'data-lab' ) || '';
					tip.appendChild( b );
					tip.appendChild( document.createElement( 'br' ) );
					tip.appendChild( document.createTextNode( pt.getAttribute( 'data-val' ) || '' ) );
					tip.style.left = ( r.left - w.left + parseFloat( pt.getAttribute( 'cx' ) ) * ( r.width / vb.width ) ) + 'px';
					tip.style.top  = ( r.top  - w.top  + parseFloat( pt.getAttribute( 'cy' ) ) * ( r.height / vb.height ) ) + 'px';
					tip.style.opacity = '1';
				}
				function hide() { tip.style.opacity = '0'; }

				pt.addEventListener( 'mouseenter', show );
				pt.addEventListener( 'focus', show );
				pt.addEventListener( 'mouseleave', hide );
				pt.addEventListener( 'blur', hide );
				pt.addEventListener( 'touchstart', function ( e ) { e.preventDefault(); show(); }, { passive: false } );
			} );
		} );

		/* ---- back to the top ---- */
		/* A diagnostic opened out is several thousand pixels of reading, and
		   the top is where the summary cards and the print button are. It
		   appears only once there is somewhere to go back to. */
		var top = document.createElement( 'button' );
		top.type = 'button';
		top.className = 'to-top';
		top.setAttribute( 'hidden', 'hidden' );
		top.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 12.5V4M4 7.5L8 3.5l4 4"/></svg>' +
			'<span>Back to top</span>';
		root.appendChild( top );

		var showing = false, queued = false;

		function place() {
			var should = ( window.pageYOffset || document.documentElement.scrollTop || 0 ) > 700;
			if ( should === showing ) return;
			showing = should;
			if ( should ) {
				top.hidden = false;
				window.requestAnimationFrame( function () { top.classList.add( 'is-in' ); } );
			} else {
				top.classList.remove( 'is-in' );
				setTimeout( function () { if ( ! showing ) top.hidden = true; }, 220 );
			}
		}

		window.addEventListener( 'scroll', function () {
			if ( queued ) return;
			queued = true;
			window.requestAnimationFrame( function () { queued = false; place(); } );
		}, { passive: true } );
		place();

		top.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: still() ? 'auto' : 'smooth' } );

			// The page moves but the keyboard does not, so the next Tab would
			// otherwise carry on from the bottom of the report.
			var first = root.querySelector( 'h1, .pi-head, .docsec-h' );
			if ( first ) { first.setAttribute( 'tabindex', '-1' ); first.focus( { preventScroll: true } ); }
		} );

		/* ---- printing wants everything open ---- */
		window.addEventListener( 'beforeprint', function () {
			Array.prototype.forEach.call( root.querySelectorAll( '.docsec, .skill, .pi-card, .session' ), function ( el ) {
				el.classList.add( 'is-open' );
			} );
			// Folded activity descriptions print in full.
			Array.prototype.forEach.call( root.querySelectorAll( 'details.about-fold' ), function ( el ) {
				el.open = true;
			} );
		} );
	}

	if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', init );
	else init();
} )();

/* ---- a piece of work, full size ----------------------------------------
 *
 * The link opens the image on its own, so this only upgrades it. A parent
 * squinting at their child's handwriting in a 300px thumbnail is the whole
 * reason it exists.
 *
 * Here, in plain script, because this file loads on the portal and on the
 * staff preview alike. It was written with jQuery in bftd-portal.js, which the
 * preview never loads and the portal never declared, so on those pages the
 * link went straight to the image with no way back but the browser's.
 *
 * Closed by the Close button, Escape, or a click anywhere off the picture.
 * Focus goes to Close when it opens and back to the thumbnail after.
 */
( function () {
	'use strict';

	var box  = null;
	var from = null;

	/*
	 * The dialog's look, added to the page the first time a picture opens.
	 * The dialog hangs off the page body rather than inside the report, so a
	 * theme element with a transform above the report cannot pin it, while
	 * bftd-report.css keeps every rule scoped under .bf-report. So the rules
	 * travel with the script that draws the dialog, on the portal and the
	 * preview alike. Every name is prefixed bftd-shotbox.
	 */
	var CSS = '.bftd-shotbox { position: fixed; inset: 0; z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 64px 28px 28px; background: rgba(22, 19, 26, 0.9); cursor: zoom-out; } .bftd-shotbox img { max-width: 100%; max-height: 100%; width: auto; height: auto; border-radius: 4px; background: #fff; cursor: default; } .bftd-shotbox-x { position: absolute; top: 14px; right: 16px; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 18px; border: 0; border-radius: 999px; background: #fff; color: #2b2632; font: 600 16px/1 system-ui, sans-serif; cursor: pointer; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35); } .bftd-shotbox-x span { font-size: 22px; line-height: 1; } .bftd-shotbox-x:hover { background: #f1edf5; } .bftd-shotbox-x:focus-visible { outline: 3px solid #fff; outline-offset: 3px; } @media (max-width: 782px) { .bftd-shotbox { padding: 64px 10px 12px; } .bftd-shotbox-x { top: 10px; right: 10px; } }';

	function style() {
		if ( document.getElementById( 'bftd-shotbox-css' ) ) return;
		var el = document.createElement( 'style' );
		el.id = 'bftd-shotbox-css';
		el.textContent = CSS;
		document.head.appendChild( el );
	}

	function close() {
		if ( ! box ) return;
		box.parentNode.removeChild( box );
		box = null;
		document.removeEventListener( 'keydown', onKey );
		document.documentElement.style.overflow = '';
		if ( from ) { from.focus(); from = null; }
	}

	function onKey( e ) {
		if ( 'Escape' === e.key || 27 === e.keyCode ) { e.preventDefault(); close(); }
		// Close is the only control, so Tab stays on it.
		if ( 'Tab' === e.key && box ) { e.preventDefault(); box.querySelector( '.bftd-shotbox-x' ).focus(); }
	}

	document.addEventListener( 'click', function ( e ) {
		var a = e.target && e.target.closest ? e.target.closest( 'a.shot-open' ) : null;
		if ( ! a ) return;
		// A modified or middle click is somebody deliberately opening a tab.
		if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || ( e.button && 0 !== e.button ) ) return;
		e.preventDefault();
		if ( box ) close();
		style();

		from = a;
		var thumb = a.querySelector( 'img' );
		var alt   = ( thumb && thumb.getAttribute( 'alt' ) ) || 'A piece of work';

		box = document.createElement( 'div' );
		box.className = 'bftd-shotbox';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', alt );

		var x = document.createElement( 'button' );
		x.type = 'button';
		x.className = 'bftd-shotbox-x';
		x.innerHTML = '<span aria-hidden="true">&times;</span> Close';

		var img = document.createElement( 'img' );
		img.src = a.getAttribute( 'href' );
		img.alt = alt;

		box.appendChild( x );
		box.appendChild( img );
		document.body.appendChild( box );
		document.documentElement.style.overflow = 'hidden';
		x.focus();

		box.addEventListener( 'click', function ( ev ) {
			// Anywhere but the picture itself.
			if ( ev.target !== img ) close();
		} );
		document.addEventListener( 'keydown', onKey );
	} );
}() );
