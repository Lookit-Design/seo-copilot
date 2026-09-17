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
			var kind = btn.getAttribute( 'data-kind' ) || 'metadesc';
			var orig = btn.getAttribute( 'data-label' ) || btn.textContent;
			if ( ! out ) { return; }
			btn.setAttribute( 'data-label', orig );

			// Track which attempt this is, and everything already shown, so the
			// platform can return something new rather than repeating itself.
			btn.bsmAttempt = ( btn.bsmAttempt || 0 ) + 1;
			btn.bsmSeen    = btn.bsmSeen || [];

			btn.disabled = true;
			btn.textContent = 'Generating…';

			var fd = new FormData();
			fd.append( 'action', 'bsm_health_suggest' );
			fd.append( 'nonce', BSM_HEALTH.nonce );
			fd.append( 'post_id', btn.getAttribute( 'data-post' ) );
			fd.append( 'kind', kind );
			fd.append( 'attempt', String( btn.bsmAttempt ) );
			fd.append( 'exclude', btn.bsmSeen.slice( -12 ).join( ', ' ) );
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
						j.data.list.forEach( function ( item ) {
							if ( btn.bsmSeen.indexOf( item ) === -1 ) { btn.bsmSeen.push( item ); }
						} );
						if ( j.data.kind === 'keyphrase' || j.data.kind === 'related' ) {
							bsmRenderKeyphrases( out, j.data.list, j.data.kind, btn );
							btn.textContent = 'Generate again';
							btn.setAttribute( 'data-label', 'Generate again' );
							return;
						}
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

	/* ── Related keyphrase chips (Edit SEO fields) ─────────────────────────── */

	// Current values, in DOM order. Read at save time, so removals and additions
	// made since page load are both picked up.
	function bsmRelatedValues() {
		var out = [];
		document.querySelectorAll( '.bsm-h-rel-list .bsm-h-rel-chip' ).forEach( function ( chip ) {
			var kw = chip.getAttribute( 'data-kw' );
			if ( kw && out.indexOf( kw ) === -1 ) { out.push( kw ); }
		} );
		return out;
	}

	function bsmRelatedSync() {
		var list = document.querySelector( '.bsm-h-rel-list' );
		if ( ! list ) { return; }
		var none = list.querySelector( '.bsm-h-rel-none' );
		var has  = list.querySelectorAll( '.bsm-h-rel-chip' ).length > 0;
		if ( none ) {
			if ( has ) { none.setAttribute( 'hidden', '' ); } else { none.removeAttribute( 'hidden' ); }
		}
	}

	function bsmRelatedRemoveBind( chip ) {
		var x = chip.querySelector( '.bsm-h-rel-x' );
		if ( ! x ) { return; }
		x.addEventListener( 'click', function () {
			chip.parentNode.removeChild( chip );
			bsmRelatedSync();
		} );
	}

	// Add a phrase to the edit-field chips (used when applying suggestions), so
	// the panel always reflects what a save would write.
	function bsmRelatedAdd( phrase ) {
		var list = document.querySelector( '.bsm-h-rel-list' );
		if ( ! list || ! phrase ) { return; }
		if ( bsmRelatedValues().indexOf( phrase ) !== -1 ) { return; }
		var chip = document.createElement( 'span' );
		chip.className = 'bsm-h-rel-chip is-new';
		chip.setAttribute( 'data-kw', phrase );
		chip.textContent = phrase;
		var x = document.createElement( 'button' );
		x.type = 'button';
		x.className = 'bsm-h-rel-x';
		x.setAttribute( 'aria-label', 'Remove ' + phrase );
		x.textContent = '\u00d7';
		chip.appendChild( x );
		var none = list.querySelector( '.bsm-h-rel-none' );
		if ( none ) { list.insertBefore( chip, none ); } else { list.appendChild( chip ); }
		bsmRelatedRemoveBind( chip );
		bsmRelatedSync();
	}

	document.querySelectorAll( '.bsm-h-rel-list .bsm-h-rel-chip' ).forEach( bsmRelatedRemoveBind );
	bsmRelatedSync();

	// Render keyphrase suggestions as pickable chips.
	// Focus keyphrase: one click drops the phrase into the Edit SEO fields input.
	// Related: multi-select, then write straight to Yoast.
	function bsmRenderKeyphrases( out, list, kind, btn ) {
		var box = document.createElement( 'div' );
		box.className = 'bsm-h-kp-chips';
		var chosen = [];

		list.forEach( function ( phrase ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'bsm-h-kp-chip';
			chip.textContent = phrase;
			chip.addEventListener( 'click', function () {
				if ( kind === 'keyphrase' ) {
					var f = document.querySelector( '.bsm-h-f-kw' );
					if ( f ) {
						f.value = phrase;
						f.focus();
						f.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
					box.querySelectorAll( '.bsm-h-kp-chip' ).forEach( function ( c ) { c.classList.remove( 'is-on' ); } );
					chip.classList.add( 'is-on' );
					return;
				}
				var at = chosen.indexOf( phrase );
				if ( at === -1 ) { chosen.push( phrase ); chip.classList.add( 'is-on' ); }
				else { chosen.splice( at, 1 ); chip.classList.remove( 'is-on' ); }
				if ( apply ) { apply.disabled = chosen.length === 0; }
			} );
			box.appendChild( chip );
		} );
		out.appendChild( box );

		var note = document.createElement( 'div' );
		note.className = 'bsm-h-kp-note';
		var apply = null;

		if ( kind === 'keyphrase' ) {
			note.textContent = 'Pick one to load it into Edit SEO fields, then Save to Yoast.';
			out.appendChild( note );
			return;
		}

		note.textContent = 'Select the ones you want, then apply.';
		out.appendChild( note );

		apply = document.createElement( 'button' );
		apply.type = 'button';
		apply.className = 'button button-small';
		apply.textContent = 'Apply to Yoast';
		apply.disabled = true;
		var msg = document.createElement( 'span' );
		msg.className = 'bsm-h-kp-msg';

		apply.addEventListener( 'click', function () {
			if ( ! chosen.length ) { return; }
			apply.disabled = true;
			apply.textContent = 'Saving…';
			var fd = new FormData();
			fd.append( 'action', 'bsm_health_apply_related' );
			fd.append( 'nonce', BSM_HEALTH.save_nonce );
			fd.append( 'post_id', btn.getAttribute( 'data-post' ) );
			chosen.forEach( function ( p ) { fd.append( 'related[]', p ); } );
			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					apply.textContent = 'Apply to Yoast';
					apply.disabled = false;
					if ( j && j.success ) {
						msg.textContent = ' Saved ✓';
						// Mirror into Edit SEO fields so the chips above stay in step
						// with what is actually stored.
						chosen.forEach( bsmRelatedAdd );
					} else {
						msg.textContent = ' ' + ( ( j && j.data ) ? String( j.data ) : 'Save failed.' );
					}
				} )
				.catch( function () {
					apply.textContent = 'Apply to Yoast';
					apply.disabled = false;
					msg.textContent = ' Request failed.';
				} );
		} );

		out.appendChild( apply );
		out.appendChild( msg );
	}

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
			fd.append( 'related_set', '1' );
			bsmRelatedValues().forEach( function ( kw ) { fd.append( 'related[]', kw ); } );

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

	/* ── SEO Health · inline image alt text (Media section) ───────────────── */

	// "View images" toggle.
	document.querySelectorAll( '.bsm-h-altview' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var panel = btn.parentNode.querySelector( '.bsm-h-altpanel' );
			if ( ! panel ) { return; }
			var open = panel.hasAttribute( 'hidden' );
			if ( open ) { panel.removeAttribute( 'hidden' ); } else { panel.setAttribute( 'hidden', '' ); }
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			btn.classList.toggle( 'is-open', open );
		} );
	} );

	function bsmAltField( row )  { return row.querySelector( '.bsm-h-altfield' ); }
	function bsmAltBadge( row )  { return row.querySelector( '.bsm-h-altbadge' ); }
	function bsmAltSaveBtn( row ){ return row.querySelector( '.bsm-h-altsave' ); }
	function bsmAltMsg( row )    { return row.querySelector( '.bsm-h-altmsg' ); }

	// Recompute the Media check (icon, detail line, group header) from the rows.
	function bsmAltRecompute() {
		var list = document.querySelector( '.bsm-h-altlist' );
		if ( ! list ) { return; }
		var rows    = list.querySelectorAll( '.bsm-h-altrow' );
		var total   = rows.length;
		var withAlt = 0;
		rows.forEach( function ( r ) {
			var b = bsmAltBadge( r );
			if ( b && b.classList.contains( 'is-ok' ) ) { withAlt++; }
		} );
		var miss = total - withAlt;

		var detail = document.querySelector( '.bsm-h-alt-detail' );
		if ( detail ) {
			detail.textContent = miss === 0
				? 'All ' + total + ' images have alt text.'
				: withAlt + ' of ' + total + ' images have alt text.';
		}
		var ico = document.querySelector( '.bsm-h-alt-ico' );
		if ( ico ) {
			var status = miss === 0 ? 'good' : ( withAlt === 0 ? 'fail' : 'warn' );
			ico.className = 'ico ' + status + ' bsm-h-alt-ico';
			ico.textContent = miss === 0 ? '✓' : ( withAlt === 0 ? '✕' : '!' );

			// Update the enclosing group header "N pass · N warn · N fail".
			var grp = ico.closest( '.bsm-h-grp' );
			var gc  = grp ? grp.querySelector( '.gc' ) : null;
			if ( gc ) {
				var pass = miss === 0 ? 1 : 0;
				var warn = ( miss !== 0 && withAlt !== 0 ) ? 1 : 0;
				var fail = withAlt === 0 && total > 0 ? 1 : 0;
				gc.textContent = pass + ' pass · ' + warn + ' warn · ' + fail + ' fail';
			}
		}
		var all = document.querySelector( '.bsm-h-altgenall' );
		if ( all ) {
			all.textContent = '✦ Generate all missing (' + miss + ')';
			all.disabled = miss === 0;
		}
	}

	// One image: POST to the vision endpoint. Resolves with the generated text.
	function bsmAltGenerate( row ) {
		return new Promise( function ( resolve, reject ) {
			if ( typeof BSM_HEALTH === 'undefined' ) { reject(); return; }
			var fd = new FormData();
			fd.append( 'action', 'bsm_health_alt_generate' );
			fd.append( 'nonce', BSM_HEALTH.alt_nonce );
			fd.append( 'id', row.getAttribute( 'data-id' ) );
			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( j && j.success && j.data && typeof j.data.alt === 'string' ) { resolve( j.data.alt ); }
					else { reject( ( j && j.data ) ? String( j.data ) : 'Generation failed.' ); }
				} )
				.catch( function () { reject( 'Request failed.' ); } );
		} );
	}

	// One image: persist the current field value to the media library.
	function bsmAltSave( row ) {
		return new Promise( function ( resolve, reject ) {
			if ( typeof BSM_HEALTH === 'undefined' ) { reject(); return; }
			var field = bsmAltField( row );
			var fd = new FormData();
			fd.append( 'action', 'bsm_health_alt_save' );
			fd.append( 'nonce', BSM_HEALTH.alt_nonce );
			fd.append( 'id', row.getAttribute( 'data-id' ) );
			fd.append( 'alt', field ? field.value : '' );
			fetch( BSM_HEALTH.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( j && j.success ) { resolve(); } else { reject( ( j && j.data ) ? String( j.data ) : 'Save failed.' ); }
				} )
				.catch( function () { reject( 'Request failed.' ); } );
		} );
	}

	// Flip a row to the saved / "alt set" state.
	function bsmAltMarkSaved( row ) {
		var badge = bsmAltBadge( row );
		var field = bsmAltField( row );
		var gen   = row.querySelector( '.bsm-h-altgen' );
		if ( badge ) { badge.className = 'bsm-h-altbadge is-ok'; badge.textContent = 'alt set'; }
		if ( field ) { field.classList.remove( 'is-empty' ); }
		if ( gen )   { gen.textContent = '✦ Regenerate'; }
		var save = bsmAltSaveBtn( row );
		if ( save ) { save.disabled = false; }
	}

	// Per-row Generate fills the editable field; Save remains explicit.
	document.querySelectorAll( '.bsm-h-altgen' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row  = btn.closest( '.bsm-h-altrow' );
			var msg  = bsmAltMsg( row );
			var orig = btn.textContent;
			btn.disabled = true; btn.textContent = 'Generating…';
			if ( msg ) { msg.hidden = true; msg.className = 'bsm-h-altmsg'; }
			bsmAltGenerate( row )
				.then( function ( alt ) {
					var field = bsmAltField( row );
					if ( field ) { field.value = alt; field.classList.remove( 'is-empty' ); }
					var save = bsmAltSaveBtn( row );
					if ( save ) { save.disabled = false; }
					btn.disabled = false;
					btn.textContent = '✦ Regenerate';
					if ( msg ) { msg.hidden = false; msg.className = 'bsm-h-altmsg'; msg.textContent = 'Review the alt text, then Save.'; }
				} )
				.catch( function ( err ) {
					btn.disabled = false; btn.textContent = orig;
					if ( msg ) { msg.hidden = false; msg.className = 'bsm-h-altmsg is-err'; msg.textContent = err || 'Failed.'; }
				} );
		} );
	} );

	// Per-row Save (for manual edits).
	document.querySelectorAll( '.bsm-h-altsave' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row  = btn.closest( '.bsm-h-altrow' );
			var msg  = bsmAltMsg( row );
			var orig = btn.textContent;
			btn.disabled = true; btn.textContent = 'Saving…';
			bsmAltSave( row )
				.then( function () {
					bsmAltMarkSaved( row ); bsmAltRecompute();
					btn.textContent = 'Saved ✓';
					setTimeout( function () { btn.textContent = 'Save'; btn.disabled = false; }, 1200 );
				} )
				.catch( function ( err ) {
					btn.disabled = false; btn.textContent = orig;
					if ( msg ) { msg.hidden = false; msg.className = 'bsm-h-altmsg is-err'; msg.textContent = err || 'Save failed.'; }
				} );
		} );
	} );

	// Enable Save as soon as a field has content (supports manual typing).
	document.querySelectorAll( '.bsm-h-altfield' ).forEach( function ( field ) {
		field.addEventListener( 'input', function () {
			var row  = field.closest( '.bsm-h-altrow' );
			var save = bsmAltSaveBtn( row );
			if ( save ) { save.disabled = field.value.trim() === ''; }
		} );
	} );

	// Generate all missing — sequential, auto-saves each.
	document.querySelectorAll( '.bsm-h-altgenall' ).forEach( function ( allBtn ) {
		allBtn.addEventListener( 'click', function () {
			var list = document.querySelector( '.bsm-h-altlist' );
			if ( ! list ) { return; }
			var rows = Array.prototype.slice.call( list.querySelectorAll( '.bsm-h-altrow' ) ).filter( function ( r ) {
				var b = bsmAltBadge( r );
				return ! ( b && b.classList.contains( 'is-ok' ) );
			} );
			if ( ! rows.length ) { return; }
			var foot = document.querySelector( '.bsm-h-altfootmsg' );
			allBtn.disabled = true;
			var done = 0;
			function step() {
				if ( ! rows.length ) {
					if ( foot ) { foot.textContent = 'Generated alt text for ' + done + ' image' + ( done === 1 ? '' : 's' ) + '.'; }
					bsmAltRecompute();
					return;
				}
				var row = rows.shift();
				if ( foot ) { foot.textContent = 'Generating ' + ( done + 1 ) + '/' + ( done + 1 + rows.length ) + '…'; }
				bsmAltGenerate( row )
					.then( function ( alt ) {
						var field = bsmAltField( row );
						if ( field ) { field.value = alt; field.classList.remove( 'is-empty' ); }
						return bsmAltSave( row );
					} )
					.then( function () { bsmAltMarkSaved( row ); done++; bsmAltRecompute(); step(); } )
					.catch( function () { step(); } ); // skip failures, keep going
			}
			step();
		} );
	} );
} )();
