/* Lookit SEO Copilot — Reports → Links (link rules, opportunities, placed links) */
( function () {
	'use strict';

	if ( typeof BSM_LINKS === 'undefined' ) {
		return;
	}

	var linkEl = document.getElementById( 'bsm-rep-links' );
	var progEl = document.getElementById( 'bsm-rep-progress' );
	var fillEl = document.getElementById( 'bsm-rep-fill' );
	var countEl = document.getElementById( 'bsm-rep-count' );
	if ( ! linkEl ) {
		return;
	}

	var links = BSM_LINKS.links || {};
	var rules = BSM_LINKS.rules || {};
	// Which of the three panels is showing. Held in JS: the panels share one
	// scan and one payload, so a reload would cost a round trip for nothing.
	var linkView = 'rules';
	var ruleEdit = null; // Rule id being edited, or 'new'.
	var ruleDraft = blankDraft();
	var sugOpen = -1;
	// Opportunities filter: '' = everything, 'auto' = not from a rule, otherwise a rule id.
	var oppKey = '';
	var oppQ = '';
	var linkTitles = !! links.titles;
	var scanning = false;

	function blankDraft() {
		return { kind: 'page', dst: 0, title: '', url: '', label: '', phrases: [], max: 2 };
	}
	function esc( value ) {
		var element = document.createElement( 'div' );
		element.textContent = value === null || value === undefined ? '' : String( value );
		return element.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}
	function n( value ) {
		return Number( value || 0 ).toLocaleString();
	}
	function errText( result, fallback ) {
		var data = result && result.data;
		if ( data && typeof data === 'object' && data.message ) {
			return String( data.message );
		}
		return typeof data === 'string' && data ? data : fallback;
	}
	function post( action, nonce, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			var value = fields[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return fetch( BSM_LINKS.ajax_url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					try {
						return JSON.parse( text );
					} catch ( e ) {
						return { success: false, data: { message: 'The server returned ' + response.status + '.' }, status: response.status };
					}
				} );
			} );
	}

	/* ── Scan ──────────────────────────────────────────────────────────── */

	function setProgress( percent, text ) {
		if ( ! progEl ) {
			return;
		}
		progEl.hidden = false;
		fillEl.style.width = percent + '%';
		countEl.textContent = text;
	}
	function endProgress( text, keep ) {
		scanning = false;
		if ( ! progEl ) {
			return;
		}
		countEl.textContent = text;
		if ( ! keep ) {
			window.setTimeout( function () {
				progEl.hidden = true;
				countEl.textContent = '';
			}, 1600 );
		}
		var button = document.getElementById( 'bsm-link-scan' );
		if ( button ) {
			button.disabled = false;
			button.textContent = links.scanned ? 'Rescan' : 'Scan for links';
		}
	}

	function linkScan( phase, offset, scanId ) {
		post( 'bsm_links_scan', BSM_LINKS.scan_nonce, {
			phase: phase,
			offset: String( offset ),
			scan_id: scanId || '',
			titles: linkTitles ? '1' : ''
		} ).then( function ( result ) {
			if ( ! result || ! result.success ) {
				var restart = result && result.data && result.data.restart;
				endProgress( errText( result, 'Link scan failed.' ) + ( restart ? ' Press Scan to start again.' : '' ), true );
				return;
			}
			var data = result.data;
			if ( data.complete ) {
				links = data.snapshot || {};
				endProgress( 'Done. ' + n( data.done ) + ' pages read.' );
				renderLinks();
				// Suggestions come from the snapshot just written, so re-read them.
				post( 'bsm_rule_update', BSM_LINKS.rule_update, { do: 'refresh' } ).then( function ( r ) {
					if ( r && r.success ) {
						rules = r.data || {};
						renderLinks();
					}
				} ).catch( function () {} );
				return;
			}
			var label = 'map' === data.phase ? 'Reading destinations' : 'Reading pages';
			var percent = data.total ? Math.round( data.offset / data.total * 100 ) : 0;
			setProgress( percent, data.total ? label + ': ' + n( data.offset ) + ' of ' + n( data.total ) : label + '…' );
			linkScan( data.phase, data.offset, data.scan_id );
		} ).catch( function () {
			endProgress( 'The scan stopped: no response from the server. Press Scan to try again.', true );
		} );
	}

	function wireLinkScan() {
		var titles = document.getElementById( 'bsm-link-titles' );
		if ( titles ) {
			titles.addEventListener( 'change', function () {
				linkTitles = titles.checked;
			} );
		}
		var button = document.getElementById( 'bsm-link-scan' );
		if ( ! button ) {
			return;
		}
		button.disabled = scanning;
		button.addEventListener( 'click', function () {
			scanning = true;
			button.disabled = true;
			button.textContent = 'Scanning…';
			setProgress( 0, 'Starting…' );
			linkScan( 'start', 0, '' );
		} );
	}

	/* ── Shell ─────────────────────────────────────────────────────────── */

	function linkHead() {
		return '<div class="bsm-rep-head"><div><div class="bsm-rep-eyebrow">Links</div>' +
			'<h2>Internal and external links</h2>' +
			'<div class="bsm-rep-sub">Set the wording that should point at a page or an external site, or let the scan find pages ' +
			'that already name another page in plain text. Nothing is written until you insert it.</div></div>' +
			'<div class="bsm-rep-headacts">' +
			'<label class="bsm-rep-opt"><input type="checkbox" id="bsm-link-titles"' + ( linkTitles ? ' checked' : '' ) + '> ' +
			'Use page titles where no keyphrase is set</label>' +
			'<button type="button" class="button button-primary" id="bsm-link-scan">' +
			( scanning ? 'Scanning…' : ( links.scanned ? 'Rescan' : 'Scan for links' ) ) + '</button></div></div>';
	}

	function renderLinks() {
		var nRules = ( rules.rules || [] ).length;
		var nOpps = ( links.opps || [] ).length;
		var nPlaced = ( rules.placed || [] ).length;
		function item( key, label, count ) {
			return '<button type="button" class="bsm-rep-linktab' + ( linkView === key ? ' -active' : '' ) +
				'" data-view="' + key + '" aria-pressed="' + ( linkView === key ? 'true' : 'false' ) + '">' + label +
				( count ? '<span class="bsm-rep-linkn">' + n( count ) + '</span>' : '' ) + '</button>';
		}
		linkEl.innerHTML = linkHead() +
			'<div class="bsm-rep-linknav">' +
			item( 'rules', 'Link rules', nRules ) +
			item( 'opps', 'Opportunities', nOpps ) +
			item( 'placed', 'Placed links', nPlaced ) +
			'</div><div id="bsm-link-body"></div>';

		linkEl.querySelectorAll( '.bsm-rep-linktab' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				linkView = button.dataset.view;
				sugOpen = -1;
				ruleEdit = null;
				renderLinks();
			} );
		} );
		wireLinkScan();
		if ( 'opps' === linkView ) {
			renderOppsView();
		} else if ( 'placed' === linkView ) {
			renderPlacedView();
		} else {
			renderRulesView();
		}
	}

	/* ── Opportunities ─────────────────────────────────────────────────── */

	function renderOppsView() {
		var bodyEl = document.getElementById( 'bsm-link-body' );
		if ( ! links.scanned ) {
			bodyEl.innerHTML = '<div class="bsm-rep-empty"><h2>Nothing scanned yet</h2>' +
				'<p>The scan reads every published item once and looks for mentions of other pages’ focus keyphrases and your link rules. ' +
				'On a large site it takes a minute.</p></div>';
			return;
		}
		var opps = links.opps || [];
		if ( ! links.dest_n ) {
			bodyEl.innerHTML = '<div class="bsm-rep-empty"><h2>Nothing to link to yet</h2>' +
				'<p>' + n( links.scanned ) + ' pages were read, but no page has a focus keyphrase of two or more words and there are no active link rules, ' +
				'so there is nothing for a mention to point at.</p>' +
				'<p>Add a link rule, set focus keyphrases and scan again, or tick <strong>Use page titles where no keyphrase is set</strong> above.</p></div>';
			return;
		}
		var html = '<div class="bsm-rep-grid bsm-rep-3">' +
			'<div class="bsm-rep-box bsm-rep-centre"><div class="bsm-rep-bign">' + n( opps.length ) + '</div>' +
			'<div class="bsm-rep-cap">Link opportunities</div></div>' +
			'<div class="bsm-rep-box bsm-rep-centre"><div class="bsm-rep-bign -bad">' + n( links.orphan_n || 0 ) + '</div>' +
			'<div class="bsm-rep-cap">Orphan pages, nothing links to them</div></div>' +
			'<div class="bsm-rep-box bsm-rep-centre"><div class="bsm-rep-bign -warn">' + n( ( links.broken || [] ).length ) + '</div>' +
			'<div class="bsm-rep-cap">Internal links pointing nowhere</div></div></div>';

		html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Unlinked mentions</h3>' +
			'<p class="bsm-rep-lede">Rule matches first, then destinations with the fewest incoming links. Insert wraps the highlighted wording in a link and saves the page as a new revision.</p>';
		if ( ! opps.length ) {
			html += '<p class="bsm-rep-foot">No unlinked mentions found. Either the linking is already good, or few pages have a focus keyphrase or rule yet.</p>';
		} else {
			html += oppFilterBar() + '<div id="bsm-opp-list">' + oppTable() + '</div>';
			html += '<div class="bsm-rep-legend"><span>Only wording the plugin can link safely is listed: body text and Elementor text widgets, ' +
				'never headings or existing links. ' + n( links.scanned ) + ' pages read against ' + n( links.dest_n ) + ' destination' +
				( 1 === links.dest_n ? '' : 's' ) + ( links.titles ? ' (page titles included)' : '' ) + '.</span></div>';
		}
		html += '</div></div>';

		html += '<div class="bsm-rep-grid bsm-rep-2">';
		html += '<details class="bsm-rep-box bsm-rep-fold"><summary><h3>Orphan pages</h3>' +
			'<span class="bsm-rep-foldn">' + n( links.orphan_n || 0 ) + '</span></summary><div class="bsm-rep-foldbody">' +
			'<p class="bsm-rep-lede">Published, with nothing on the site pointing at them.</p>';
		if ( ( links.orphans || [] ).length ) {
			html += '<table class="bsm-rep-t"><tbody>';
			links.orphans.forEach( function ( orphan ) {
				html += '<tr><td><a href="' + esc( orphan.edit ) + '">' + esc( orphan.title || '(no title)' ) + '</a></td>' +
					'<td class="r"><span class="bsm-rep-type">' + esc( orphan.type ) + '</span></td></tr>';
			} );
			html += '</tbody></table>';
			if ( links.orphan_n > links.orphans.length ) {
				html += '<p class="bsm-rep-foot">' + n( links.orphan_n ) + ' in total; the first ' + links.orphans.length + ' are listed.</p>';
			}
		} else {
			html += '<p class="bsm-rep-foot">Everything published has at least one link pointing at it.</p>';
		}
		html += '</div></details>';
		html += '<details class="bsm-rep-box bsm-rep-fold"><summary><h3>Links pointing nowhere</h3>' +
			'<span class="bsm-rep-foldn">' + n( ( links.broken || [] ).length ) + '</span></summary><div class="bsm-rep-foldbody">' +
			'<p class="bsm-rep-lede">Internal links whose address no longer resolves to a page.</p>';
		if ( ( links.broken || [] ).length ) {
			html += '<table class="bsm-rep-t"><tbody>';
			links.broken.forEach( function ( broken ) {
				html += '<tr><td><a href="' + esc( broken.edit ) + '">' + esc( broken.from ) + '</a>' +
					'<div class="bsm-rep-slug">' + esc( broken.href ) + '</div></td></tr>';
			} );
			html += '</tbody></table>';
		} else {
			html += '<p class="bsm-rep-foot">No dead internal links found.</p>';
		}
		html += '</div></details></div>';

		if ( ( links.top || [] ).length ) {
			var max = links.top[ 0 ].n || 1;
			html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Where the links go</h3>' +
				'<p class="bsm-rep-lede">Most linked destinations. If the homepage tops this list and nothing else comes close, the depth is thinner than it looks.</p>' +
				'<table class="bsm-rep-t"><tbody>';
			links.top.forEach( function ( top ) {
				html += '<tr><td>' + esc( top.title ) + '</td><td class="r">' + n( top.n ) + '</td>' +
					'<td class="bsm-rep-topbar"><div class="bsm-rep-bartrack"><div class="bsm-rep-barval" style="width:' +
					Math.max( 2, top.n / max * 100 ) + '%"></div></div></td></tr>';
			} );
			html += '</tbody></table></div></div>';
		}
		html += '<p class="bsm-rep-foot bsm-rep-scanfoot">Scanned ' + n( links.scanned ) + ' items on ' + esc( links.date ) + '.</p>';

		bodyEl.innerHTML = html;
		wireOppFilter();
		wireLinkRows( bodyEl );
	}

	function oppOfRule( opp, id ) {
		return opp.rule === id;
	}
	function oppMatch( opp ) {
		if ( 'auto' === oppKey && 'rule' === opp.via ) {
			return false;
		}
		if ( oppKey && 'auto' !== oppKey && ! oppOfRule( opp, oppKey ) ) {
			return false;
		}
		if ( oppQ ) {
			var haystack = ( opp.phrase + ' ' + opp.to + ' ' + opp.from + ' ' + opp.snippet ).toLowerCase();
			if ( -1 === haystack.indexOf( oppQ ) ) {
				return false;
			}
		}
		return true;
	}
	function oppFilterBar() {
		var opps = links.opps || [];
		function chip( value, label, count ) {
			return '<button type="button" class="bsm-rep-oppchip' + ( oppKey === value ? ' -active' : '' ) +
				'" data-key="' + esc( value ) + '" aria-pressed="' + ( oppKey === value ? 'true' : 'false' ) + '">' + esc( label ) +
				'<span class="bsm-rep-linkn">' + n( count ) + '</span></button>';
		}
		var html = '<div class="bsm-rep-oppfilter">' +
			'<label class="screen-reader-text" for="bsm-opp-q">Search mentions</label>' +
			'<input type="search" id="bsm-opp-q" placeholder="Search mentions, pages or wording…" value="' + esc( oppQ ) + '" autocomplete="off">' +
			'<div class="bsm-rep-oppchips" role="group" aria-label="Filter by rule">' + chip( '', 'All', opps.length );
		( rules.rules || [] ).forEach( function ( rule ) {
			var count = opps.filter( function ( opp ) {
				return oppOfRule( opp, rule.id );
			} ).length;
			html += chip( rule.id, rule.title + ( rule.ext ? ' ↗' : '' ), count );
		} );
		if ( ( rules.rules || [] ).length ) {
			html += chip( 'auto', 'Not from a rule', opps.filter( function ( opp ) {
				return 'rule' !== opp.via;
			} ).length );
		}
		return html + '</div></div>';
	}
	function highlight( snippet, phrase ) {
		var safe = esc( snippet );
		var needle = esc( phrase );
		var at = safe.toLowerCase().indexOf( needle.toLowerCase() );
		if ( ! needle || -1 === at ) {
			return safe;
		}
		return safe.slice( 0, at ) + '<mark>' + safe.slice( at, at + needle.length ) + '</mark>' + safe.slice( at + needle.length );
	}
	function oppTable() {
		var opps = links.opps || [];
		var filtered = !! oppQ || '' !== oppKey;
		var rows = [];
		opps.forEach( function ( opp, index ) {
			if ( oppMatch( opp ) ) {
				rows.push( index );
			}
		} );
		if ( ! rows.length ) {
			return '<p class="bsm-rep-foot">Nothing matches that. Clear the search or pick another rule.</p>';
		}
		var limit = filtered ? rows.length : 60;
		var html = filtered ? '<p class="bsm-rep-foot bsm-rep-oppcount">Showing ' + n( rows.length ) + ' of ' + n( opps.length ) + '.</p>' : '';
		html += '<table class="bsm-rep-t bsm-rep-linkt"><thead><tr><th>On this page</th><th>Link to</th>' +
			'<th class="r">Links in</th><th class="r">Action</th></tr></thead><tbody>';
		rows.slice( 0, limit ).forEach( function ( index ) {
			var opp = opps[ index ];
			html += '<tr data-opp="' + index + '"' + ( opp._done ? ' class="bsm-rep-linkdone"' : '' ) + '>' +
				'<td><a href="' + esc( opp.from_ed ) + '">' + esc( opp.from ) + '</a>' +
				'<div class="bsm-rep-snip">' + ( opp.lead ? '&hellip;' : '' ) + highlight( opp.snippet, opp.phrase ) + '&hellip;</div></td>' +
				'<td><a href="' + esc( opp.to_url ) + '" target="_blank" rel="noopener">' + esc( opp.to ) + '</a>' +
				( 'rule' === opp.via ? '<div class="bsm-rep-whys"><span class="bsm-rep-why">Rule</span></div>' : '' ) + '</td>' +
				'<td class="r">' + ( opp.ext ? '<span class="bsm-rep-why -ext">External</span>' : ( opp.in ? '<b>' + n( opp.in ) + '</b>' : '<b class="bsm-rep-zero">0</b>' ) ) + '</td>' +
				'<td class="r bsm-rep-linkact">' + ( opp._done
					? '<span class="bsm-rep-linkmsg">' + esc( opp._done ) + '</span>'
					: '<div class="bsm-rep-acts">' +
						'<button type="button" class="button button-primary bsm-rep-ins" data-i="' + index + '">Insert link</button>' +
						'<button type="button" class="button bsm-rep-ign" data-i="' + index + '">Ignore</button></div>' +
						( 'elementor' === opp.where ? '<span class="bsm-rep-store">Elementor</span>' : '' ) ) +
				'</td></tr>';
		} );
		html += '</tbody></table>';
		if ( rows.length > limit ) {
			html += '<p class="bsm-rep-foot">Showing the first ' + limit + ' of ' + n( rows.length ) + '. Search or pick a rule above to narrow it.</p>';
		}
		return html;
	}
	function redrawOpps() {
		var box = document.getElementById( 'bsm-opp-list' );
		if ( box ) {
			box.innerHTML = oppTable();
			wireLinkRows( box );
		}
	}
	function wireOppFilter() {
		var input = document.getElementById( 'bsm-opp-q' );
		if ( input ) {
			var timer = null;
			input.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					oppQ = input.value.trim().toLowerCase();
					redrawOpps();
				}, 200 );
			} );
		}
		linkEl.querySelectorAll( '.bsm-rep-oppchip' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				oppKey = button.dataset.key || '';
				linkEl.querySelectorAll( '.bsm-rep-oppchip' ).forEach( function ( other ) {
					other.classList.toggle( '-active', other === button );
					other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
				} );
				redrawOpps();
			} );
		} );
	}
	function wireLinkRows( scope ) {
		function settle( row, message ) {
			var opp = links.opps[ Number( row.dataset.opp ) ];
			if ( opp ) {
				opp._done = message;
			}
			row.classList.add( 'bsm-rep-linkdone' );
			row.querySelector( '.bsm-rep-linkact' ).innerHTML = '<span class="bsm-rep-linkmsg">' + esc( message ) + '</span>';
		}
		scope.querySelectorAll( '.bsm-rep-ins' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var opp = links.opps[ Number( button.dataset.i ) ];
				var row = button.closest( 'tr' );
				var old = row.querySelector( '.bsm-rep-linkerr' );
				if ( old ) {
					old.remove();
				}
				button.disabled = true;
				button.textContent = 'Adding…';
				post( 'bsm_links_insert', BSM_LINKS.link_nonce, { src: opp.src, dst: opp.dst, ext: opp.ext || '', phrase: opp.phrase } )
					.then( function ( result ) {
						if ( result && result.success ) {
							settle( row, 'elementor' === ( result.data && result.data.where ) ? 'Link added in Elementor' : 'Link added' );
							return;
						}
						button.disabled = false;
						button.textContent = 'Insert link';
						var message = document.createElement( 'div' );
						message.className = 'bsm-rep-linkerr';
						message.setAttribute( 'role', 'alert' );
						message.textContent = errText( result, 'That did not save.' );
						button.parentNode.appendChild( message );
					} )
					.catch( function () {
						button.disabled = false;
						button.textContent = 'Insert link';
					} );
			} );
		} );
		scope.querySelectorAll( '.bsm-rep-ign' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var opp = links.opps[ Number( button.dataset.i ) ];
				button.disabled = true;
				post( 'bsm_links_ignore', BSM_LINKS.link_nonce, { src: opp.src, dst: opp.dst, ext: opp.ext || '' } ).then( function ( result ) {
					if ( ! result || ! result.success ) {
						throw new Error( errText( result, 'Could not ignore this link. Please try again.' ) );
					}
					settle( button.closest( 'tr' ), 'Ignored' );
				} ).catch( function ( error ) {
					button.disabled = false;
					var message = document.createElement( 'div' );
					message.className = 'bsm-rep-linkerr';
					message.setAttribute( 'role', 'alert' );
					message.textContent = error.message || 'Could not ignore this link. Please try again.';
					button.parentNode.appendChild( message );
				} );
			} );
		} );
	}

	/* ── Link rules ────────────────────────────────────────────────────── */

	function rulesBack( result, button, label ) {
		if ( button ) {
			button.disabled = false;
			button.textContent = label;
		}
		if ( ! result || ! result.success ) {
			window.alert( errText( result, 'That did not save. Try again.' ) );
			return;
		}
		rules = result.data || {};
		ruleEdit = null;
		sugOpen = -1;
		renderLinks();
	}
	function phraseChips( list ) {
		return '<div class="bsm-rep-phrases">' + ( list || [] ).map( function ( phrase ) {
			return '<span class="bsm-rep-ph -harv">' + esc( phrase ) + '</span>';
		} ).join( '' ) + '</div>';
	}
	function srcClass( src ) {
		return 'harv' === src ? '-harv' : ( 'page' === src ? '-page' : '-seed' );
	}
	function maxSelect( id, cls, value ) {
		return '<select id="' + id + '" class="' + cls + '">' + [ 1, 2, 3, 5 ].map( function ( option ) {
			return '<option value="' + option + '"' + ( option === Number( value ) ? ' selected' : '' ) + '>' + option + '</option>';
		} ).join( '' ) + '<option value="0"' + ( 0 === Number( value ) ? ' selected' : '' ) + '>No limit</option></select>';
	}

	function renderRulesView() {
		var bodyEl = document.getElementById( 'bsm-link-body' );
		var mine = rules.rules || [];
		var suggestions = rules.suggest || [];
		var html = '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Your link rules</h3>' +
			'<p class="bsm-rep-lede">A destination (a page on this site or an external URL) plus the wording that should point at it. ' +
			'Rules are matched on the next scan, and always shown for approval before anything is written.</p>';
		if ( ! mine.length ) {
			html += '<p class="bsm-rep-foot">No rules yet. Add one below, or take a suggestion.</p>';
		} else {
			html += '<table class="bsm-rep-t bsm-rep-rulet"><thead><tr><th>Link to</th><th>Anchor phrases</th>' +
				'<th class="r">Max per page</th><th class="r">Status</th><th class="r">Action</th></tr></thead><tbody>';
			mine.forEach( function ( rule ) {
				html += '<tr><td><a href="' + esc( rule.url ) + '" target="_blank" rel="noopener">' + esc( rule.title ) + '</a>' +
					( rule.ext ? '<div class="bsm-rep-whys"><span class="bsm-rep-why -ext">External</span></div><div class="bsm-rep-slug">' + esc( rule.url ) + '</div>' : '' ) +
					'</td><td>' + phraseChips( rule.phrases ) + '</td>' +
					'<td class="r">' + ( rule.max ? n( rule.max ) : 'No limit' ) + '</td>' +
					'<td class="r"><span class="bsm-rep-state' + ( rule.on ? ' -on' : '' ) + '">' + ( rule.on ? 'Active' : 'Paused' ) + '</span></td>' +
					'<td class="r"><div class="bsm-rep-acts">' +
					'<button type="button" class="button bsm-rule-opps" data-id="' + esc( rule.id ) + '">Mentions</button>' +
					'<button type="button" class="button bsm-rule-edit" data-id="' + esc( rule.id ) + '">Edit</button>' +
					'<button type="button" class="button bsm-rule-toggle" data-id="' + esc( rule.id ) + '" data-label="' + ( rule.on ? 'Pause' : 'Resume' ) + '">' + ( rule.on ? 'Pause' : 'Resume' ) + '</button>' +
					'<button type="button" class="button bsm-rule-del" data-id="' + esc( rule.id ) + '">Delete</button>' +
					'</div></td></tr>';
			} );
			html += '</tbody></table>';
		}
		html += ruleForm() + '</div></div>';

		html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Suggested destinations</h3>' +
			'<p class="bsm-rep-lede">Pages the site names often and links to rarely. Every phrase says where it came from, ' +
			'so a suggestion can be judged rather than trusted.</p>';
		if ( ! links.scanned ) {
			html += '<p class="bsm-rep-foot">Run a scan first. Suggestions are worked out from what it reads.</p>';
		} else if ( ! suggestions.length ) {
			html += '<p class="bsm-rep-foot">Nothing to suggest. Either every worthwhile destination already has a rule, ' +
				'or the last scan found no page named in plain text on another page.</p>';
		} else {
			html += '<table class="bsm-rep-t bsm-rep-sugt"><thead><tr><th>Page</th><th>Suggested phrases</th>' +
				'<th class="r">Links in</th><th class="r">Score</th><th class="r">Action</th></tr></thead><tbody>';
			suggestions.forEach( function ( suggestion, index ) {
				html += '<tr><td><a href="' + esc( suggestion.url ) + '" target="_blank" rel="noopener">' + esc( suggestion.title ) + '</a>' +
					'<div class="bsm-rep-whys">' + ( suggestion.tags || [] ).map( function ( tag ) {
						return '<span class="bsm-rep-why' + ( tag[ 0 ] ? ' -' + esc( tag[ 0 ] ) : '' ) + '">' + esc( tag[ 1 ] ) + '</span>';
					} ).join( '' ) + '</div></td>' +
					'<td><div class="bsm-rep-phrases">' + ( suggestion.phrases || [] ).map( function ( phrase ) {
						return '<span class="bsm-rep-ph ' + srcClass( phrase.src ) + ( phrase.on ? '' : ' -dim' ) + '">' + esc( phrase.p ) + '</span>';
					} ).join( '' ) + '</div>' +
					( 0 === suggestion.in ? '<div class="bsm-rep-snip">Nothing links here yet, so there is no anchor text to learn from. These come from the page itself.</div>' : '' ) +
					'</td><td class="r">' + ( suggestion.in ? '<b>' + n( suggestion.in ) + '</b>' : '<b class="bsm-rep-zero">0</b>' ) + '</td>' +
					'<td class="r"><b>' + n( suggestion.score ) + '</b></td>' +
					'<td class="r"><div class="bsm-rep-acts">' +
					'<button type="button" class="button button-primary bsm-sug-open" data-i="' + index + '" aria-expanded="' + ( sugOpen === index ? 'true' : 'false' ) + '">' +
					( sugOpen === index ? 'Close' : 'Review and add' ) + '</button>' +
					'<button type="button" class="button bsm-sug-dis" data-dst="' + Number( suggestion.id ) + '">Dismiss</button>' +
					'</div></td></tr>';
				if ( sugOpen === index ) {
					html += '<tr class="bsm-rep-sugrow"><td colspan="5">' + sugPanel( suggestion, index ) + '</td></tr>';
				}
			} );
			html += '</tbody></table>';
			html += '<div class="bsm-rep-legend"><span>' +
				'<b class="bsm-rep-key -harv"></b> wording already used as link text on this site &nbsp; ' +
				'<b class="bsm-rep-key -page"></b> wording on the destination page &nbsp; ' +
				'<b class="bsm-rep-key -seed"></b> starter list. Only the first is ticked for you, because only the first has evidence behind it.' +
				'</span></div>';
		}
		html += '</div></div>';
		bodyEl.innerHTML = html;
		wireRules( bodyEl );
	}

	function sugPanel( suggestion, index ) {
		return '<div class="bsm-rep-sugpanel">' +
			'<div class="bsm-rep-sugtitle">Phrases that will link to ' + esc( suggestion.title ) + '</div>' +
			'<table class="bsm-rep-t bsm-rep-pht"><thead><tr><th class="bsm-rep-ckcol"><span class="screen-reader-text">Use</span></th><th>Phrase</th>' +
			'<th>Where it came from</th></tr></thead><tbody>' +
			( suggestion.phrases || [] ).map( function ( phrase, j ) {
				var id = 'bsm-sug-ck-' + index + '-' + j;
				return '<tr><td><input type="checkbox" class="bsm-sug-ck" id="' + id + '" data-i="' + index + '" data-j="' + j + '"' +
					( phrase.on ? ' checked' : '' ) + '></td>' +
					'<td><label for="' + id + '" class="bsm-rep-ph ' + srcClass( phrase.src ) + '">' + esc( phrase.p ) + '</label></td>' +
					'<td class="bsm-rep-ev">' + esc( phrase.ev ) + '</td></tr>';
			} ).join( '' ) +
			'</tbody></table>' +
			'<div class="bsm-rep-sugfoot">' +
			'<label for="bsm-sug-max-' + index + '">Max links per page</label> ' + maxSelect( 'bsm-sug-max-' + index, 'bsm-sug-max', 2 ) +
			'<button type="button" class="button button-primary bsm-sug-save" data-i="' + index + '">Save rule</button>' +
			'</div></div>';
	}

	function ruleForm() {
		if ( ! ruleEdit ) {
			return '<div class="bsm-rep-ruleadd"><button type="button" class="button button-primary" id="bsm-rule-new">Add a rule</button></div>';
		}
		var isUrl = 'url' === ruleDraft.kind;
		var target = isUrl
			? '<label for="bsm-rule-url" class="screen-reader-text">External address</label>' +
				'<input type="url" id="bsm-rule-url" placeholder="https://example.org/" value="' + esc( ruleDraft.url ) + '" autocomplete="off">' +
				'<label for="bsm-rule-label" class="screen-reader-text">Display name</label>' +
				'<input type="text" id="bsm-rule-label" class="bsm-rep-gap" placeholder="Name shown in this list, e.g. Partner site" value="' + esc( ruleDraft.label ) + '" autocomplete="off">' +
				'<p class="bsm-rep-hint">Full address including https://. An address on this site is saved as a page rule automatically.</p>'
			: ( ruleDraft.dst
				? '<div class="bsm-rep-picked">' + esc( ruleDraft.title ) + ' <button type="button" class="button button-small" id="bsm-rule-clear">Change</button></div>'
				: '<label for="bsm-rule-q" class="screen-reader-text">Search pages</label>' +
					'<input type="text" id="bsm-rule-q" placeholder="Start typing a page title…" autocomplete="off">' +
					'<div class="bsm-rep-results" id="bsm-rule-results"></div>' ) +
				'<p class="bsm-rep-hint">Any published item. The destination never links to itself.</p>';
		return '<div class="bsm-rep-ruleform">' +
			'<div class="bsm-rep-sugtitle">' + ( 'new' === ruleEdit ? 'New link rule' : 'Edit link rule' ) + '</div>' +
			'<div class="bsm-rep-fgrid">' +
			'<fieldset><legend>Link to</legend>' +
			'<div class="bsm-rep-kind">' +
			'<label><input type="radio" name="bsm-rule-kind" value="page"' + ( isUrl ? '' : ' checked' ) + '> A page on this site</label>' +
			'<label><input type="radio" name="bsm-rule-kind" value="url"' + ( isUrl ? ' checked' : '' ) + '> An external URL</label>' +
			'</div>' + target + '</fieldset>' +
			'<div><label for="bsm-rule-p">Anchor phrases</label>' +
			'<input type="text" id="bsm-rule-p" placeholder="Type a phrase and press Enter" autocomplete="off">' +
			'<div class="bsm-rep-phrases" id="bsm-rule-chips">' + ruleDraft.phrases.map( function ( phrase, index ) {
				return '<span class="bsm-rep-ph -harv">' + esc( phrase ) +
					'<button type="button" class="bsm-rep-phx" data-i="' + index + '" aria-label="Remove ' + esc( phrase ) + '">×</button></span>';
			} ).join( '' ) + '</div>' +
			'<p class="bsm-rep-hint">Four characters or more. A single word needs eight, so &ldquo;contact&rdquo; on its own is refused while &ldquo;contact us&rdquo; is fine.</p></div>' +
			'<div><label for="bsm-rule-max">Max links per page</label>' + maxSelect( 'bsm-rule-max', 'bsm-rule-max', ruleDraft.max ) + '</div>' +
			'</div>' +
			'<div class="bsm-rep-sugfoot">' +
			'<button type="button" class="button button-primary" id="bsm-rule-save">Save rule</button>' +
			'<button type="button" class="button" id="bsm-rule-cancel">Cancel</button>' +
			'</div></div>';
	}

	function updateRules( fields, button, label ) {
		button.disabled = true;
		post( 'bsm_rule_update', BSM_LINKS.rule_update, fields )
			.then( function ( result ) {
				rulesBack( result, button, label );
			} )
			.catch( function () {
				rulesBack( null, button, label );
			} );
	}

	function saveRule( fields, button ) {
		button.disabled = true;
		button.textContent = 'Saving…';
		post( 'bsm_rule_save', BSM_LINKS.rule_nonce, fields )
			.then( function ( result ) {
				rulesBack( result, button, 'Save rule' );
			} )
			.catch( function () {
				rulesBack( null, button, 'Save rule' );
			} );
	}

	function wireRules( body ) {
		body.querySelectorAll( '.bsm-sug-open' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var index = Number( button.dataset.i );
				sugOpen = sugOpen === index ? -1 : index;
				renderRulesView();
			} );
		} );
		body.querySelectorAll( '.bsm-sug-ck' ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				rules.suggest[ Number( box.dataset.i ) ].phrases[ Number( box.dataset.j ) ].on = box.checked ? 1 : 0;
			} );
		} );
		body.querySelectorAll( '.bsm-sug-save' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var suggestion = rules.suggest[ Number( button.dataset.i ) ];
				var picked = ( suggestion.phrases || [] ).filter( function ( phrase ) {
					return phrase.on;
				} ).map( function ( phrase ) {
					return phrase.p;
				} );
				if ( ! picked.length ) {
					window.alert( 'Tick at least one phrase.' );
					return;
				}
				var select = body.querySelector( '.bsm-sug-max' );
				saveRule( { id: '', dst: suggestion.id, max: select ? select.value : 2, phrases: picked }, button );
			} );
		} );
		body.querySelectorAll( '.bsm-sug-dis' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				updateRules( { do: 'dismiss', dst: button.dataset.dst }, button, 'Dismiss' );
			} );
		} );
		body.querySelectorAll( '.bsm-rule-opps' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				oppKey = button.dataset.id || '';
				oppQ = '';
				linkView = 'opps';
				renderLinks();
			} );
		} );
		body.querySelectorAll( '.bsm-rule-toggle' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				updateRules( { do: 'toggle', id: button.dataset.id }, button, button.dataset.label );
			} );
		} );
		body.querySelectorAll( '.bsm-rule-del' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( window.confirm( 'Delete this rule? Links already inserted stay on the pages. Only the rule goes.' ) ) {
					updateRules( { do: 'delete', id: button.dataset.id }, button, 'Delete' );
				}
			} );
		} );
		body.querySelectorAll( '.bsm-rule-edit' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var rule = ( rules.rules || [] ).filter( function ( item ) {
					return item.id === button.dataset.id;
				} )[ 0 ];
				if ( ! rule ) {
					return;
				}
				ruleEdit = rule.id;
				ruleDraft = {
					kind: rule.ext ? 'url' : 'page',
					dst: rule.ext ? 0 : rule.dst,
					title: rule.ext ? '' : rule.title,
					url: rule.ext ? rule.url : '',
					label: rule.ext ? ( rule.label || '' ) : '',
					phrases: rule.phrases.slice(),
					max: rule.max
				};
				renderRulesView();
			} );
		} );

		var add = document.getElementById( 'bsm-rule-new' );
		if ( add ) {
			add.addEventListener( 'click', function () {
				ruleEdit = 'new';
				ruleDraft = blankDraft();
				renderRulesView();
			} );
		}
		var cancel = document.getElementById( 'bsm-rule-cancel' );
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				ruleEdit = null;
				renderRulesView();
			} );
		}
		body.querySelectorAll( 'input[name="bsm-rule-kind"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				ruleDraft.kind = radio.value;
				renderRulesView();
			} );
		} );
		var urlInput = document.getElementById( 'bsm-rule-url' );
		if ( urlInput ) {
			urlInput.addEventListener( 'input', function () {
				ruleDraft.url = urlInput.value.trim();
			} );
		}
		var labelInput = document.getElementById( 'bsm-rule-label' );
		if ( labelInput ) {
			labelInput.addEventListener( 'input', function () {
				ruleDraft.label = labelInput.value;
			} );
		}
		var clear = document.getElementById( 'bsm-rule-clear' );
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				ruleDraft.dst = 0;
				ruleDraft.title = '';
				renderRulesView();
			} );
		}
		var search = document.getElementById( 'bsm-rule-q' );
		if ( search ) {
			var timer = null;
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					searchTargets( search.value );
				}, 300 );
			} );
			searchTargets( '' );
		}
		var phraseInput = document.getElementById( 'bsm-rule-p' );
		if ( phraseInput ) {
			phraseInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' !== event.key ) {
					return;
				}
				event.preventDefault();
				var value = phraseInput.value.trim().toLowerCase();
				if ( value && -1 === ruleDraft.phrases.indexOf( value ) ) {
					ruleDraft.phrases.push( value );
				}
				renderRulesView();
				var again = document.getElementById( 'bsm-rule-p' );
				if ( again ) {
					again.focus();
				}
			} );
		}
		body.querySelectorAll( '.bsm-rep-phx' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				ruleDraft.phrases.splice( Number( button.dataset.i ), 1 );
				renderRulesView();
			} );
		} );
		var maxInput = document.getElementById( 'bsm-rule-max' );
		if ( maxInput ) {
			maxInput.addEventListener( 'change', function () {
				ruleDraft.max = Number( maxInput.value );
			} );
		}
		var save = document.getElementById( 'bsm-rule-save' );
		if ( save ) {
			save.addEventListener( 'click', function () {
				var isUrl = 'url' === ruleDraft.kind;
				if ( isUrl && ! /^https?:\/\/[^\s/.]+\.[^\s]+$/i.test( ruleDraft.url ) ) {
					window.alert( 'Enter a full web address starting with https://.' );
					return;
				}
				if ( ! isUrl && ! ruleDraft.dst ) {
					window.alert( 'Choose a destination page first.' );
					return;
				}
				if ( ! ruleDraft.phrases.length ) {
					window.alert( 'Add at least one phrase.' );
					return;
				}
				var fields = {
					id: 'new' === ruleEdit ? '' : ruleEdit,
					dst: isUrl ? 0 : ruleDraft.dst,
					max: ruleDraft.max,
					phrases: ruleDraft.phrases
				};
				if ( isUrl ) {
					fields.url = ruleDraft.url;
					fields.label = ruleDraft.label || '';
				}
				saveRule( fields, save );
			} );
		}
	}

	function searchTargets( term ) {
		post( 'bsm_rule_targets', BSM_LINKS.rule_nonce, { s: term } ).then( function ( result ) {
			var box = document.getElementById( 'bsm-rule-results' );
			if ( ! box ) {
				return;
			}
			if ( ! result || ! result.success || ! result.data.items.length ) {
				box.innerHTML = '<div class="bsm-rep-ev bsm-rep-noresult">No published pages match that.</div>';
				return;
			}
			box.innerHTML = result.data.items.map( function ( item ) {
				return '<button type="button" class="bsm-rep-result" data-id="' + Number( item.id ) + '" data-t="' + esc( item.title ) + '">' + esc( item.title ) + '</button>';
			} ).join( '' );
			box.querySelectorAll( '.bsm-rep-result' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					ruleDraft.dst = Number( button.dataset.id );
					ruleDraft.title = button.dataset.t;
					renderRulesView();
				} );
			} );
		} ).catch( function () {} );
	}

	/* ── Placed links ──────────────────────────────────────────────────── */

	function renderPlacedView() {
		var bodyEl = document.getElementById( 'bsm-link-body' );
		var rows = rules.placed || [];
		var html = '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Placed links</h3>' +
			'<p class="bsm-rep-lede">Every link this plugin has inserted. Each insertion saved the page as a new revision, ' +
			'so anything here can be rolled back from the page’s own revision history.</p>';
		if ( ! rows.length ) {
			html += '<p class="bsm-rep-foot">Nothing inserted yet.</p>';
		} else {
			html += '<table class="bsm-rep-t"><thead><tr><th>On this page</th><th>Anchor text</th><th>Links to</th>' +
				'<th class="r">Added</th><th class="r">Action</th></tr></thead><tbody>';
			rows.forEach( function ( row ) {
				html += '<tr><td>' + esc( row.from ) + '</td>' +
					'<td><span class="bsm-rep-ph -harv">' + esc( row.phrase ) + '</span>' +
					( 'elementor' === row.where ? '<span class="bsm-rep-store">Elementor</span>' : '' ) + '</td>' +
					'<td>' + esc( row.to ) + '</td>' +
					'<td class="r bsm-rep-ev">' + esc( row.date ) + '</td>' +
					'<td class="r"><a class="button" href="' + esc( row.edit ) + '" target="_blank" rel="noopener">Open editor</a></td></tr>';
			} );
			html += '</tbody></table>';
			html += '<div class="bsm-rep-legend"><span>Removing a link is done in the editor for now. The plugin will not ' +
				'unwrap wording it did not re-read first, because the surrounding text may have changed since.</span></div>';
		}
		bodyEl.innerHTML = html + '</div></div>';
	}

	renderLinks();
}() );
