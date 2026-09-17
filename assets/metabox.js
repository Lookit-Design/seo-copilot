/**
 * Auto SEO metabox — suggest a different focus keyphrase, one at a time.
 *
 * Thin client: the request goes to the platform webhook, same as every other
 * AI call in this plugin. Nothing is written to Yoast until the editor presses
 * "Use this", and even then it only fills the field — the post still has to be
 * saved as normal.
 */
( function () {
	'use strict';

	if ( typeof ASY_KP === 'undefined' ) {
		return;
	}

	var btn = document.querySelector( '.asy-kp-btn' );
	var out = document.querySelector( '.asy-kp-out' );
	if ( ! btn || ! out ) {
		return;
	}

	var seen    = [];
	var attempt = 0;

	/** Yoast renders its focus keyphrase field differently per editor. */
	function yoastField() {
		var ids = [
			'#focus-keyword-input-metabox',
			'#focus-keyword-input-sidebar',
			'#yoast_wpseo_focuskw'
		];
		for ( var i = 0; i < ids.length; i++ ) {
			var el = document.querySelector( ids[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	/**
	 * Yoast's field is React-controlled, so assigning .value alone is discarded
	 * on the next render. Go through the native setter, then fire the event
	 * React listens for.
	 */
	function setField( el, value ) {
		var proto  = Object.getPrototypeOf( el );
		var setter = Object.getOwnPropertyDescriptor( proto, 'value' );
		if ( setter && setter.set ) {
			setter.set.call( el, value );
		} else {
			el.value = value;
		}
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function copy( text, button ) {
		var done = function () {
			var label = button.textContent;
			button.textContent = 'Copied';
			setTimeout( function () { button.textContent = label; }, 1400 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done ).catch( done );
			return;
		}
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
		done();
	}

	function show( phrase ) {
		out.hidden = false;
		out.className = 'asy-kp-out';
		out.textContent = '';
		// Once a suggestion is on screen, "Show another" below covers the same
		// job, so the wide button above would just be a duplicate.
		btn.hidden = true;

		var head = document.createElement( 'p' );
		head.className = 'asy-kp-title';
		head.textContent = 'New focus keyphrase';
		out.appendChild( head );

		var chip = document.createElement( 'div' );
		chip.className = 'asy-kp-chip';
		chip.textContent = phrase;
		out.appendChild( chip );

		var row = document.createElement( 'div' );
		row.className = 'asy-kp-actions';

		var use = document.createElement( 'button' );
		use.type = 'button';
		use.className = 'button button-small';
		use.textContent = 'Use this';
		use.addEventListener( 'click', function () {
			var el = yoastField();
			if ( el ) {
				setField( el, phrase );
				use.textContent = 'Added to Yoast';
				use.disabled = true;
				var tip = document.createElement( 'p' );
				tip.className = 'asy-lock-hint';
				tip.textContent = 'Update the post to save it.';
				out.appendChild( tip );
			} else {
				copy( phrase, use );
			}
		} );
		row.appendChild( use );

		var skip = document.createElement( 'button' );
		skip.type = 'button';
		skip.className = 'button button-small';
		skip.textContent = 'Show another';
		skip.addEventListener( 'click', request );
		row.appendChild( skip );

		out.appendChild( row );
	}

	function fail( message ) {
		out.hidden = false;
		out.className = 'asy-kp-out asy-kp-out--err';
		out.textContent = message;
		btn.hidden = false; // leave a way to try again
	}

	function request() {
		attempt++;
		btn.disabled = true;
		var label = btn.getAttribute( 'data-label' ) || btn.textContent;
		btn.setAttribute( 'data-label', label );
		btn.textContent = 'Thinking…';

		if ( ! out.hidden ) {
			out.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
			var wait = out.querySelector( '.asy-kp-wait' );
			if ( ! wait ) {
				wait = document.createElement( 'p' );
				wait.className = 'asy-lock-hint asy-kp-wait';
				out.appendChild( wait );
			}
			wait.textContent = 'Thinking…';
		}

		var body = new FormData();
		body.append( 'action', 'asy_suggest_keyphrase' );
		body.append( 'nonce', ASY_KP.nonce );
		body.append( 'post_id', btn.getAttribute( 'data-post' ) );
		body.append( 'attempt', String( attempt ) );
		seen.slice( -8 ).forEach( function ( s ) { body.append( 'seen[]', s ); } );

		fetch( ASY_KP.ajax_url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				btn.disabled = false;
				btn.textContent = label;
				if ( j && j.success && j.data && j.data.keyphrase ) {
					seen.push( j.data.keyphrase );
					show( j.data.keyphrase );
				} else {
					fail( ( j && j.data ) ? String( j.data ) : 'Could not suggest a keyphrase.' );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				btn.textContent = label;
				fail( 'Request failed.' );
			} );
	}

	btn.addEventListener( 'click', request );
} )();
