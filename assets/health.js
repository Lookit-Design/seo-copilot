/* Lookit Bulk SEO Manager — SEO Health tab behaviours */
( function () {
	'use strict';

	// Clicking anywhere on an audited row opens its detail (the "View" link
	// remains the keyboard-accessible path).
	document.querySelectorAll( 'tr[data-href]' ).forEach( function ( row ) {
		row.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'a' ) ) {
				return; // let real links behave normally
			}
			window.location = row.getAttribute( 'data-href' );
		} );
	} );

	// Post-type filter submits its form on change.
	document.querySelectorAll( 'select[data-bsm-autosubmit]' ).forEach( function ( sel ) {
		sel.addEventListener( 'change', function () {
			if ( sel.form ) {
				sel.form.submit();
			}
		} );
	} );

	// AI meta-description suggestion (detail view) — calls the saved platform endpoint.
	document.querySelectorAll( '.bsm-h-suggest' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( typeof BSM_HEALTH === 'undefined' ) {
				return;
			}
			var wrap = btn.closest( '.bsm-h-suggest-wrap' ) || btn.parentNode;
			var out  = wrap.querySelector( '.bsm-h-suggest-out' );
			var orig = btn.textContent;
			if ( ! out ) { return; }
			btn.disabled = true;
			btn.textContent = 'Generating…';

			var fd = new FormData();
			fd.append( 'action', 'bsm_health_suggest' );
			fd.append( 'nonce', BSM_HEALTH.nonce );
			fd.append( 'post_id', btn.getAttribute( 'data-post' ) );
			fd.append( 'kind', btn.getAttribute( 'data-kind' ) || 'metadesc' );
			var wordsInput = wrap.querySelector( '.bsm-h-words' );
			if ( wordsInput ) {
				fd.append( 'words', wordsInput.value || '600' );
			}

			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					btn.disabled = false;
					btn.textContent = orig;
					out.hidden = false;
					out.textContent = '';
					if ( j && j.success && j.data && Array.isArray( j.data.list ) ) {
						out.className = 'bsm-h-suggest-out is-ok';
						var ul = document.createElement( 'ul' );
						j.data.list.forEach( function ( item ) {
							var li = document.createElement( 'li' );
							li.textContent = item; // textContent avoids any HTML injection
							ul.appendChild( li );
						} );
						out.appendChild( ul );
					} else if ( j && j.success && j.data && j.data.text ) {
						out.className = 'bsm-h-suggest-out is-ok' + ( j.data.kind === 'content' ? ' is-content' : '' );
						out.textContent = j.data.text;
						// Meta descriptions can drop into the edit field.
						if ( j.data.kind === 'metadesc' ) {
							var descField = document.querySelector( '.bsm-h-f-desc' );
							if ( descField ) {
								var use = document.createElement( 'button' );
								use.type = 'button';
								use.className = 'button button-small';
								use.style.marginTop = '8px';
								use.textContent = 'Use in Edit SEO fields ↑';
								use.addEventListener( 'click', function () {
									var d = document.querySelector( '.bsm-h-f-desc' );
									if ( d ) { d.value = j.data.text; d.focus(); d.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
									var det = document.querySelector( '.bsm-h-edit' );
									if ( det ) { det.open = true; }
								} );
								out.appendChild( document.createElement( 'br' ) );
								out.appendChild( use );
							}
						}
						// Generated content gets a Copy button.
						if ( j.data.kind === 'content' ) {
							var copy = document.createElement( 'button' );
							copy.type = 'button';
							copy.className = 'button button-small';
							copy.style.marginTop = '8px';
							copy.textContent = 'Copy content';
							copy.addEventListener( 'click', function () {
								var t = j.data.text, o = copy.textContent;
								var fin = function () { copy.textContent = 'Copied ✓'; setTimeout( function () { copy.textContent = o; }, 1200 ); };
								if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( t ).then( fin ).catch( function () { bsmFallbackCopy( t ); fin(); } ); }
								else { bsmFallbackCopy( t ); fin(); }
							} );
							out.appendChild( document.createElement( 'br' ) );
							out.appendChild( copy );
						}
					} else {
						out.className = 'bsm-h-suggest-out is-err';
						out.textContent = ( j && j.data ) ? String( j.data ) : 'Could not generate a suggestion.';
					}
				} )
				.catch( function () {
					btn.disabled = false;
					btn.textContent = orig;
					out.hidden = false;
					out.className = 'bsm-h-suggest-out is-err';
					out.textContent = 'Request failed.';
				} );
		} );
	} );

	// Copy an image file name to the clipboard (to paste into Media Master search).
	function bsmFallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
	}
	document.querySelectorAll( '.bsm-h-copy' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = btn.getAttribute( 'data-file' ) || '';
			var orig = btn.textContent;
			var done = function () {
				btn.textContent = 'Copied ✓';
				setTimeout( function () { btn.textContent = orig; }, 1200 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done ).catch( function () { bsmFallbackCopy( text ); done(); } );
			} else {
				bsmFallbackCopy( text );
				done();
			}
		} );
	} );

	// Per-field "Fill with AI" in the Edit SEO fields dropdown.
	document.querySelectorAll( '.bsm-h-fill' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( typeof BSM_HEALTH === 'undefined' ) { return; }
			var body   = document.querySelector( '.bsm-h-edit-body' );
			var target = document.querySelector( '.' + btn.getAttribute( 'data-target' ) );
			if ( ! body || ! target ) { return; }
			var orig = btn.textContent;
			btn.disabled = true;
			btn.textContent = 'Generating…';

			var fd = new FormData();
			fd.append( 'action', 'bsm_health_suggest' );
			fd.append( 'nonce', BSM_HEALTH.nonce );
			fd.append( 'post_id', body.getAttribute( 'data-post' ) );
			fd.append( 'kind', btn.getAttribute( 'data-kind' ) || 'metadesc' );

			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					btn.disabled = false;
					btn.textContent = orig;
					if ( j && j.success && j.data ) {
						if ( Array.isArray( j.data.list ) && j.data.list.length ) {
							target.value = j.data.list[ 0 ]; // keyphrase: primary is first
						} else if ( j.data.text ) {
							target.value = j.data.text;
						}
						target.focus();
					}
				} )
				.catch( function () {
					btn.disabled = false;
					btn.textContent = orig;
				} );
		} );
	} );

	// Save edited Yoast fields, then reload to re-audit.
	var saveBtn = document.querySelector( '.bsm-h-save' );
	if ( saveBtn && typeof BSM_HEALTH !== 'undefined' ) {
		saveBtn.addEventListener( 'click', function () {
			var body = document.querySelector( '.bsm-h-edit-body' );
			var msg  = document.querySelector( '.bsm-h-save-msg' );
			if ( ! body ) { return; }
			var orig = saveBtn.textContent;
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving…';
			if ( msg ) { msg.textContent = ''; msg.className = 'bsm-h-save-msg'; }

			var fd = new FormData();
			fd.append( 'action', 'bsm_health_save' );
			fd.append( 'nonce', BSM_HEALTH.save_nonce );
			fd.append( 'post_id', body.getAttribute( 'data-post' ) );
			fd.append( 'keyphrase', ( body.querySelector( '.bsm-h-f-kw' ) || {} ).value || '' );
			fd.append( 'title', ( body.querySelector( '.bsm-h-f-title' ) || {} ).value || '' );
			fd.append( 'metadesc', ( body.querySelector( '.bsm-h-f-desc' ) || {} ).value || '' );

			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( j && j.success ) {
						saveBtn.textContent = 'Saved ✓ — re-auditing…';
						window.location.reload();
					} else {
						saveBtn.disabled = false;
						saveBtn.textContent = orig;
						if ( msg ) { msg.className = 'bsm-h-save-msg is-err'; msg.textContent = ( j && j.data ) ? String( j.data ) : 'Save failed.'; }
					}
				} )
				.catch( function () {
					saveBtn.disabled = false;
					saveBtn.textContent = orig;
					if ( msg ) { msg.className = 'bsm-h-save-msg is-err'; msg.textContent = 'Request failed.'; }
				} );
		} );
	}
} )();
