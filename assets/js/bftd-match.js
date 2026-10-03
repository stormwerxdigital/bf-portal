/* --------------------------------------------------------------------------
   Searching the libraries: one rule

   Used by every picker and by the search box on the Skills and Activities
   lists. The server applies the same rule in BFTD_Library::score(), and both
   are run against tests/search-cases.json so they cannot drift apart.

   What is typed is split into pieces at anything that is not a letter or a
   digit. Every piece has to be accounted for, in any order.

   A word matches only where a word in the name starts. "read" finds "Read
   Back" and "Reading", never "Spreading". That is the looseness this replaced.

   A number matches the record's own number first, then numbers that start
   with it (typing 1 on the way to 12 still shows 12), then the same number
   standing as a whole word in the name ("Activity 1 of 4").

   Plain script, no jQuery, so a test can load it on its own.
   -------------------------------------------------------------------------- */
( function ( root ) {
	'use strict';

	function asked( term ) {
		var nums = [], words = [];
		var bits = String( term || '' ).toLowerCase().split( /[^a-z0-9]+/ );
		for ( var i = 0; i < bits.length; i++ ) {
			if ( ! bits[ i ] ) continue;
			if ( /^\d+$/.test( bits[ i ] ) ) nums.push( bits[ i ] );
			else words.push( bits[ i ] );
		}
		return { nums: nums, words: words, empty: ! nums.length && ! words.length };
	}

	function alnum( c ) { return /[a-z0-9]/.test( c ); }

	/* Where w starts a word in name, or -1. */
	function wordAt( name, w ) {
		var from = 0, at;
		while ( ( at = name.indexOf( w, from ) ) !== -1 ) {
			if ( 0 === at || ! alnum( name.charAt( at - 1 ) ) ) return at;
			from = at + 1;
		}
		return -1;
	}

	/* Does w stand as a whole word in name? */
	function wholeWord( name, w ) {
		var from = 0, at;
		while ( ( at = name.indexOf( w, from ) ) !== -1 ) {
			var before = 0 === at || ! alnum( name.charAt( at - 1 ) );
			var after  = ! alnum( name.charAt( at + w.length ) );
			if ( before && after ) return true;
			from = at + 1;
		}
		return false;
	}

	/**
	 * How well one record answers what was typed; 0 means it does not.
	 * o is { name: lower-case name without its number, num: digits or '' }.
	 */
	function score( o, ask ) {
		var name = String( o.name || '' ).toLowerCase();
		var num  = String( o.num || '' );
		var n = 0;

		for ( var d = 0; d < ask.nums.length; d++ ) {
			var q = ask.nums[ d ];
			if ( num && num === q ) n += 1000;
			else if ( num && 0 === num.indexOf( q ) ) n += 120;
			else if ( wholeWord( name, q ) ) n += 30;
			else return 0;
		}

		for ( var w = 0; w < ask.words.length; w++ ) {
			var at = wordAt( name, ask.words[ w ] );
			if ( -1 === at ) return 0;
			n += 60;
			if ( 0 === at ) n += 40;
		}

		// The words in the order typed, at the front of the name, is somebody
		// typing the name of the thing they want.
		if ( ask.words.length ) {
			var phrase = ask.words.join( ' ' );
			var flat   = name.replace( /[^a-z0-9]+/g, ' ' ).trim();
			if ( flat === phrase ) n += 600;
			else if ( 0 === flat.indexOf( phrase ) ) n += 250;
			else if ( -1 !== wordAt( flat, phrase ) ) n += 80;
		}

		return n;
	}

	/* Every record that answers, best first, ties kept in library order. */
	function search( rows, term ) {
		var ask = asked( term );
		if ( ask.empty ) return [];
		var hits = [];
		for ( var i = 0; i < rows.length; i++ ) {
			var s = score( rows[ i ], ask );
			if ( s > 0 ) hits.push( { row: rows[ i ], n: s, at: i } );
		}
		hits.sort( function ( a, b ) { return b.n - a.n || a.at - b.at; } );
		return hits.map( function ( h ) { return h.row; } );
	}

	root.BFTDMatch = { asked: asked, score: score, search: search };
}( typeof window !== 'undefined' ? window : globalThis ) );
