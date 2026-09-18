function bsmCalculateTaskProgress( checks, doneMap ) {
	'use strict';
	var progress = { total: 0, done: 0, percent: 0, points: 0, groups: {} };
	checks.forEach( function ( check ) {
		var affected = Math.max( 0, Number( check.affected || 0 ) );
		var complete = Math.min( affected, Math.max( 0, Number( doneMap[ check.slug ] || 0 ) ) );
		progress.total += affected;
		progress.done += complete;
		progress.points += affected ? Number( check.points || 0 ) * complete / affected : 0;
		progress.groups[ check.slug ] = {
			affected: affected,
			done: complete,
			percent: affected ? Math.round( 100 * complete / affected ) : 0
		};
	} );
	progress.percent = progress.total ? Math.round( 100 * progress.done / progress.total ) : 0;
	progress.points = Math.round( progress.points * 10 ) / 10;
	return progress;
}

function bsmRestoreReportTypes( valid, stored ) {
	'use strict';
	try {
		var saved = JSON.parse( stored || '[]' ).filter( function ( key ) {
			return valid.indexOf( key ) !== -1;
		} );
		return saved.length ? saved : valid;
	} catch ( error ) {
		return valid;
	}
}

function bsmDeriveReportSnapshot( snapshot, selected ) {
	'use strict';
	if ( ! snapshot || ! Array.isArray( snapshot.slices ) || ! snapshot.slices.length ) {
		return snapshot;
	}
	var selectedMap = {};
	selected.forEach( function ( key ) {
		selectedMap[ key ] = true;
	} );
	var slices = snapshot.slices.filter( function ( slice ) {
		return !! selectedMap[ slice.key ];
	} );
	if ( ! slices.length ) {
		return null;
	}

	var total = 0;
	var sum = 0;
	var issues = 0;
	var buckets = [ 0, 0, 0, 0 ];
	var urlN = 0;
	var urlSum = 0;
	var urlBands = [ 0, 0, 0, 0 ];
	var checksBySlug = {};
	var worst = [];
	var longest = [];
	var types = [];
	var coreN = 0;
	var coreSum = 0;

	slices.forEach( function ( slice ) {
		total += Number( slice.n || 0 );
		sum += Number( slice.sum || 0 );
		issues += Number( slice.issues || 0 );
		if ( slice.core ) {
			coreN += Number( slice.n || 0 );
			coreSum += Number( slice.sum || 0 );
		}
		( slice.buckets || [] ).forEach( function ( value, index ) {
			buckets[ index ] += Number( value || 0 );
		} );
		urlN += Number( slice.url_n || 0 );
		urlSum += Number( slice.url_sum || 0 );
		( slice.url_bands || [] ).forEach( function ( value, index ) {
			urlBands[ index ] += Number( value || 0 );
		} );
		( slice.checks || [] ).forEach( function ( check ) {
			var combined = checksBySlug[ check.slug ] || {
				label: check.label,
				slug: check.slug,
				fail: 0,
				warn: 0,
				good: 0,
				gain: 0
			};
			combined.fail += Number( check.fail || 0 );
			combined.warn += Number( check.warn || 0 );
			combined.good += Number( check.good || 0 );
			combined.gain += Number( check.gain || 0 );
			checksBySlug[ check.slug ] = combined;
		} );
		worst = worst.concat( slice.worst || [] );
		longest = longest.concat( slice.url_long || [] );
		types.push( {
			label: slice.label,
			n: Number( slice.n || 0 ),
			avg: Number( slice.n || 0 ) ? Math.round( Number( slice.sum || 0 ) / Number( slice.n ) * 10 ) / 10 : 0
		} );
	} );

	var listed = {};
	( snapshot.checks || [] ).forEach( function ( check ) {
		listed[ check.slug ] = check.listed;
	} );
	var score = total ? Math.round( sum / total * 10 ) / 10 : 0;
	var checks = Object.keys( checksBySlug ).map( function ( slug, index ) {
		var check = checksBySlug[ slug ];
		var rawTenths = total ? check.gain / total * 10 : 0;
		return {
			label: check.label,
			slug: check.slug,
			fail: check.fail,
			warn: check.warn,
			good: check.good,
			affected: check.fail + check.warn,
			listed: Number( listed[ check.slug ] || 0 ),
			index: index,
			tenths: Math.floor( rawTenths + 0.0000001 ),
			remainder: rawTenths - Math.floor( rawTenths + 0.0000001 )
		};
	} ).filter( function ( check ) {
		return check.affected > 0;
	} );
	var targetTenths = Math.round( ( 100 - score ) * 10 );
	var givenTenths = checks.reduce( function ( value, check ) {
		return value + check.tenths;
	}, 0 );
	var remainders = checks.slice().sort( function ( left, right ) {
		return right.remainder - left.remainder || left.index - right.index;
	} );
	for ( var remainderIndex = 0; remainderIndex < targetTenths - givenTenths && remainders.length; remainderIndex++ ) {
		remainders[ remainderIndex % remainders.length ].tenths++;
	}
	checks.forEach( function ( check ) {
		check.points = check.tenths / 10;
		delete check.index;
		delete check.tenths;
		delete check.remainder;
	} );
	checks.sort( function ( left, right ) {
		return right.points - left.points || left.label.localeCompare( right.label );
	} );
	worst.sort( function ( left, right ) {
		return left.score - right.score;
	} );
	longest.sort( function ( left, right ) {
		return right.len - left.len;
	} );
	types.sort( function ( left, right ) {
		return right.n - left.n;
	} );

	return {
		generated: snapshot.generated,
		stale: snapshot.stale,
		tasks_ready: snapshot.tasks_ready,
		date: snapshot.date,
		site: snapshot.site,
		host: snapshot.host,
		total: total,
		score: score,
		core_n: coreN,
		core: coreN ? Math.round( coreSum / coreN * 10 ) / 10 : 0,
		top_three: Math.min( 100, score +
			checks.slice( 0, 3 ).reduce( function ( value, check ) { return value + check.points; }, 0 ) ),
		issues: total ? Math.round( issues / total * 10 ) / 10 : 0,
		buckets: buckets,
		checks: checks,
		types: types,
		worst: worst.slice( 0, 12 ),
		scope: slices.length === snapshot.slices.length ? 'Everything' :
			slices.map( function ( slice ) { return slice.label; } ).join( ', ' ),
		urls: {
			n: urlN,
			avg: urlN ? Math.round( urlSum / urlN ) : 0,
			bands: urlBands,
			ok: snapshot.urls.ok,
			max: snapshot.urls.max,
			long: longest.slice( 0, 12 )
		}
	};
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = bsmCalculateTaskProgress;
	module.exports.derive = bsmDeriveReportSnapshot;
	module.exports.restoreTypes = bsmRestoreReportTypes;
}

( function () {
	'use strict';

	if ( typeof BSM_REPORT === 'undefined' ) {
		return;
	}

	var snap = BSM_REPORT.snapshot && BSM_REPORT.snapshot.generated ? BSM_REPORT.snapshot : null;
	var repEl = document.getElementById( 'bsm-rep-report' );
	var simEl = document.getElementById( 'bsm-rep-sim' );
	var taskEl = document.getElementById( 'bsm-rep-tasks' );
	var trendEl = document.getElementById( 'bsm-rep-trends' );
	var runBtn = document.getElementById( 'bsm-rep-run' );
	var progressEl = document.getElementById( 'bsm-rep-progress' );
	var fillEl = document.getElementById( 'bsm-rep-fill' );
	var countEl = document.getElementById( 'bsm-rep-count' );
	var emptyEl = document.getElementById( 'bsm-rep-empty' );
	var taskState = BSM_REPORT.tasks || { checks: [], done: {} };
	var taskPicks = ( taskState.checks || [] ).slice();
	var taskDone = taskState.done || {};
	var taskProgress = taskState.progress || { total: 0, done: 0, percent: 0, points: 0, groups: {} };
	var history = BSM_REPORT.history || {};
	var slices = snap && Array.isArray( snap.slices ) ? snap.slices : [];
	var typeStorageKey = 'bsm_rep_types';
	var selectedTypes = restoreTypes();

	function typeKeys() {
		return slices.map( function ( slice ) {
			return slice.key;
		} );
	}

	function restoreTypes() {
		var valid = typeKeys();
		try {
			return bsmRestoreReportTypes( valid, window.localStorage.getItem( typeStorageKey ) );
		} catch ( error ) {
			return valid;
		}
	}

	function storeTypes() {
		try {
			window.localStorage.setItem( typeStorageKey, JSON.stringify( selectedTypes ) );
		} catch ( error ) {
			void error;
		}
	}

	function derivedSnapshot() {
		return bsmDeriveReportSnapshot( snap, selectedTypes );
	}

	function filterBar() {
		if ( ! snap || ! slices.length ) {
			return '';
		}
		var html = '<div class="bsm-rep-typebar" data-typebar><div class="bsm-rep-typehead">' +
			'<span class="bsm-rep-typelbl">Content included</span><span class="bsm-rep-typeacts">' +
			'<button type="button" class="bsm-rep-typebtn" data-all>Everything</button>' +
			'<button type="button" class="bsm-rep-typebtn" data-core>Pages &amp; posts only</button></span></div>' +
			'<div class="bsm-rep-typechips">';
		slices.forEach( function ( slice ) {
			var selected = selectedTypes.indexOf( slice.key ) !== -1;
			html += '<label class="bsm-rep-chip' + ( selected ? ' on' : '' ) + '">' +
				'<input type="checkbox" data-type="' + esc( slice.key ) + '"' + ( selected ? ' checked' : '' ) + '>' +
				'<span class="t">' + esc( slice.label ) + '</span><span class="c">' + number( slice.n ) + '</span></label>';
		} );
		return html + '</div></div>';
	}

	function wireFilter( root ) {
		var bar = root.querySelector( '[data-typebar]' );
		if ( ! bar ) {
			return;
		}
		function apply( keys ) {
			selectedTypes = keys;
			storeTypes();
			renderReport();
			renderSimulator();
		}
		bar.querySelectorAll( 'input[data-type]' ).forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				var keys = [];
				bar.querySelectorAll( 'input[data-type]' ).forEach( function ( item ) {
					if ( item.checked ) {
						keys.push( item.dataset.type );
					}
				} );
				apply( keys );
			} );
		} );
		bar.querySelector( '[data-all]' ).addEventListener( 'click', function () {
			apply( typeKeys() );
		} );
		bar.querySelector( '[data-core]' ).addEventListener( 'click', function () {
			var core = slices.filter( function ( slice ) {
				return slice.core;
			} ).map( function ( slice ) {
				return slice.key;
			} );
			apply( core.length ? core : typeKeys() );
		} );
	}

	function esc( value ) {
		var element = document.createElement( 'div' );
		element.textContent = value === null || value === undefined ? '' : String( value );
		return element.innerHTML;
	}

	function number( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	function grade( score ) {
		if ( score >= 90 ) {
			return { text: 'Strong', className: 'lo' };
		}
		if ( score >= 70 ) {
			return { text: 'Good', className: 'lo' };
		}
		if ( score >= 50 ) {
			return { text: 'Needs work', className: 'md' };
		}
		return { text: 'Poor', className: 'hi' };
	}

	function scoreColour( score ) {
		return score >= 70 ? '#028673' : score >= 50 ? '#c98a12' : '#b3453f';
	}

	function gauge( score ) {
		var length = 220;
		var offset = length - ( length * Math.max( 0, Math.min( 100, score ) ) / 100 );
		return '<svg viewBox="0 0 180 118" width="100%" height="132" role="img" aria-label="Score ' + score + ' out of 100">' +
			'<path d="M20 105 A70 70 0 0 1 160 105" fill="none" stroke="#eceff4" stroke-width="16" stroke-linecap="round"/>' +
			'<path d="M20 105 A70 70 0 0 1 160 105" fill="none" stroke="' + scoreColour( score ) + '" stroke-width="16" stroke-linecap="round" stroke-dasharray="' +
			length + '" stroke-dashoffset="' + offset + '"/>' +
			'<text x="90" y="92" text-anchor="middle" font-size="40" font-weight="600" fill="#11151f">' + score + '</text></svg>';
	}

	function renderReport() {
		var snap = derivedSnapshot();
		if ( ! snap ) {
			repEl.innerHTML = filterBar() + '<div class="bsm-rep-empty"><h2>No content types selected</h2>' +
				'<p>Select at least one content type to build the report.</p></div>';
			wireFilter( repEl );
			return;
		}
		if ( ! snap.total ) {
			repEl.innerHTML = '<div class="bsm-rep-empty"><h2>No published content to audit</h2><p>The report will populate after a supported public post type has published items.</p></div>';
			return;
		}

		var band = grade( snap.score );
		var maxAffected = 1;
		snap.checks.forEach( function ( check ) {
			maxAffected = Math.max( maxAffected, check.affected );
		} );
		var top = snap.checks.slice( 0, 3 );
		var projected = snap.top_three;
		var html = filterBar() + '<div class="bsm-rep-head"><div><div class="bsm-rep-eyebrow">SEO Health report</div><h2>' +
			esc( snap.site ) + '</h2><div class="bsm-rep-sub">' + esc( snap.host ) + ' · ' + number( snap.total ) +
			' items audited · ' + esc( snap.date ) + ' · Included: ' + esc( snap.scope || 'Everything' ) +
			'</div></div><div class="bsm-rep-brand"><b>Lookit Design</b>Prepared with SEO Copilot</div></div>';

		if ( snap.stale ) {
			html += '<div class="bsm-rep-stale">This report was generated by an older audit schema. Re-run it before relying on projections.</div>';
		}

		html += '<div class="bsm-rep-grid bsm-rep-hero"><div class="bsm-rep-box bsm-rep-centre">' + gauge( snap.score ) +
			'<div class="bsm-rep-cap">Site score</div><div><span class="bsm-rep-pill ' + band.className + '">' + band.text +
			'</span></div></div><div class="bsm-rep-box"><h3>Where the score sits</h3><p class="bsm-rep-lede">Every audited item, grouped by score band.</p><div class="bsm-rep-dist">';

		var names = [ 'Under 50', '50–69', '70–89', '90–100' ];
		var colours = [ '#b3453f', '#c98a12', '#028673', '#016155' ];
		var maxBucket = Math.max.apply( null, snap.buckets ) || 1;
		snap.buckets.forEach( function ( value, index ) {
			var height = Math.round( value / maxBucket * 100 );
			html += '<div class="bsm-rep-distcol"><span class="bsm-rep-distn">' + number( value ) +
				'</span><div class="bsm-rep-distbar" style="height:' + Math.max( height, 2 ) + '%;background:' +
				colours[ index ] + '"></div><span class="bsm-rep-distlbl">' + names[ index ] + '</span></div>';
		} );
		html += '</div></div></div>';

		html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>What is holding the site back</h3>' +
			'<p class="bsm-rep-lede">Every failing or flagged check, ranked by its exact points cost.</p>';
		snap.checks.forEach( function ( check ) {
			var width = check.affected / maxAffected * 100;
			var colour = check.affected / snap.total > 0.6 ? '#b3453f' : check.affected / snap.total > 0.1 ? '#c98a12' : '#028673';
			html += '<div class="bsm-rep-bar"><div class="bsm-rep-barlbl">' + esc( check.label ) +
				'</div><div class="bsm-rep-bartrack"><div class="bsm-rep-barval" style="width:' + Math.max( width, 0.8 ) +
				'%;background:' + colour + '"></div></div><div class="bsm-rep-barn">' + number( check.affected ) +
				'</div><div class="bsm-rep-barpts">+' + check.points + '</div></div>';
		} );
		html += '<div class="bsm-rep-legend">The bar shows affected items. Points show the site-score gain if the check passed everywhere.</div></div></div>';

		html += '<div class="bsm-rep-grid bsm-rep-2"><div class="bsm-rep-box bsm-rep-wins"><h3>Biggest wins first</h3>' +
			'<p class="bsm-rep-lede">Calculated from the auditor’s check weights.</p><table class="bsm-rep-t"><thead><tr><th>Fix</th><th class="r">Items</th><th class="r">Score</th></tr></thead><tbody>';
		snap.checks.slice( 0, 6 ).forEach( function ( check ) {
			html += '<tr><td>' + esc( check.label ) + '</td><td class="r">' + number( check.affected ) +
				'</td><td class="r"><b>+' + check.points + '</b></td></tr>';
		} );
		html += '</tbody></table></div><div class="bsm-rep-box"><h3>Where this gets you</h3>' +
			'<p class="bsm-rep-lede">Today compared with the three highest-impact fixes.</p><div class="bsm-rep-proj">' +
			'<div class="bsm-rep-projcol"><span class="bsm-rep-projn">' + snap.score +
			'</span><div class="bsm-rep-projbar" style="height:' + Math.max( snap.score, 3 ) + '%;background:' +
			scoreColour( snap.score ) + '"></div><span class="bsm-rep-projlbl">Today</span></div>' +
			'<div class="bsm-rep-projcol"><span class="bsm-rep-projn">' + projected +
			'</span><div class="bsm-rep-projbar" style="height:' + Math.max( projected, 3 ) +
			'%;background:#028673"></div><span class="bsm-rep-projlbl">After top 3</span></div></div><p class="bsm-rep-foot">' +
			esc( top.map( function ( check ) {
				return check.label;
			} ).join( ', ' ) ) + '</p></div></div>';

		html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>By content type</h3>' +
			'<table class="bsm-rep-t"><thead><tr><th>Type</th><th class="r">Items</th><th class="r">Average score</th><th></th></tr></thead><tbody>';
		snap.types.forEach( function ( type ) {
			html += '<tr><td>' + esc( type.label ) + '</td><td class="r">' + number( type.n ) + '</td><td class="r">' +
				type.avg + '</td><td style="width:34%"><div class="bsm-rep-bartrack"><div class="bsm-rep-barval" style="width:' +
				type.avg + '%;background:' + scoreColour( type.avg ) + '"></div></div></td></tr>';
		} );
		html += '</tbody></table></div></div>';

		if ( snap.urls && snap.urls.n ) {
			var urls = snap.urls;
			var maxUrlBand = Math.max.apply( null, urls.bands ) || 1;
			var urlNames = [ 'Under 30', '30–' + urls.ok, ( urls.ok + 1 ) + '–' + urls.max, 'Over ' + urls.max ];
			var urlColours = [ '#016155', '#028673', '#c98a12', '#b3453f' ];
			html += '<div class="bsm-rep-grid bsm-rep-2"><div class="bsm-rep-box"><h3>How long your URLs are</h3>' +
				'<p class="bsm-rep-lede">Measured on the editable slug.</p><div class="bsm-rep-dist">';
			urls.bands.forEach( function ( value, index ) {
				html += '<div class="bsm-rep-distcol"><span class="bsm-rep-distn">' + number( value ) + '</span>' +
					'<div class="bsm-rep-distbar" style="height:' + Math.max( Math.round( value / maxUrlBand * 100 ), 2 ) +
					'%;background:' + urlColours[ index ] + '"></div><span class="bsm-rep-distlbl">' + urlNames[ index ] + '</span></div>';
			} );
			html += '</div><p class="bsm-rep-foot">Average ' + urls.avg + ' characters across ' + number( urls.n ) + ' addresses.</p></div>' +
				'<div class="bsm-rep-box"><h3>Longest addresses</h3><table class="bsm-rep-t"><thead><tr><th>Page</th><th class="r">Chars</th></tr></thead><tbody>';
			urls.long.forEach( function ( item ) {
				html += '<tr><td><a href="' + esc( item.edit ) + '">' + esc( item.title || '(no title)' ) +
					'</a><div class="bsm-rep-slug">/' + esc( item.slug ) + '/</div></td><td class="r">' + item.len + '</td></tr>';
			} );
			html += '</tbody></table></div></div>';
		}

		if ( snap.worst.length ) {
			html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Pages needing attention first</h3>' +
				'<p class="bsm-rep-lede">The lowest-scoring editable published items.</p><table class="bsm-rep-t"><thead><tr><th>Page</th><th>Type</th><th class="r">Score</th><th class="r">Issues</th></tr></thead><tbody>';
			snap.worst.forEach( function ( item ) {
				html += '<tr><td><a href="' + esc( item.edit ) + '">' + esc( item.title || '(no title)' ) +
					'</a></td><td><span class="bsm-rep-type">' + esc( item.type ) + '</span></td><td class="r">' +
					item.score + '</td><td class="r">' + item.issues + '</td></tr>';
			} );
			html += '</tbody></table></div></div>';
		}

		html += '<div class="bsm-rep-actions"><button type="button" class="button button-primary" id="bsm-rep-print">Print or save as PDF</button>' +
			'<span class="bsm-rep-note">Turn off browser headers and footers in the print dialog.</span></div>';
		repEl.innerHTML = html;
		wireFilter( repEl );
		document.getElementById( 'bsm-rep-print' ).addEventListener( 'click', function () {
			window.print();
		} );
	}

	function effortFor( label ) {
		if ( /meta description|keyphrase set|SEO title length/i.test( label ) ) {
			return 'Bulk fill';
		}
		if ( /word count|internal links/i.test( label ) ) {
			return 'Writing';
		}
		return 'Editor';
	}

	function renderSimulator() {
		var snap = derivedSnapshot();
		if ( ! snap ) {
			simEl.innerHTML = filterBar() + '<div class="bsm-rep-empty"><h2>No content types selected</h2>' +
				'<p>Select at least one content type to use the simulator.</p></div>';
			wireFilter( simEl );
			return;
		}
		if ( ! snap.total ) {
			simEl.innerHTML = '';
			return;
		}
		var html = filterBar() + '<div class="bsm-rep-head"><div><div class="bsm-rep-eyebrow">Impact simulator</div>' +
			'<h2>What would fixing this be worth?</h2><div class="bsm-rep-sub">Select prospective fixes to update the projection.</div></div></div>' +
			'<div class="bsm-rep-grid bsm-rep-hero bsm-rep-simgrid"><div class="bsm-rep-box bsm-rep-simpanel">' +
			'<div class="bsm-rep-simscore"><div class="bsm-rep-cap">Projected site score</div><div class="bsm-rep-simrow">' +
			'<span class="bsm-rep-simnow">' + snap.score + '</span><span class="bsm-rep-simarrow">→</span>' +
			'<span class="bsm-rep-simnew" id="bsm-sim-score">' + snap.score + '</span></div>' +
			'<div class="bsm-rep-delta" id="bsm-sim-delta">no change yet</div></div>' +
			'<div class="bsm-rep-simstat"><span>Items touched</span><b id="bsm-sim-items">0</b></div>' +
			'<div class="bsm-rep-simstat"><span>Rough effort</span><b id="bsm-sim-effort">—</b></div>' +
			'<div class="bsm-rep-simstat"><span>Grade</span><b id="bsm-sim-grade">' + grade( snap.score ).text +
			'</b></div><button type="button" class="button button-primary bsm-rep-start" id="bsm-sim-start" disabled>Start tasks</button>' +
			'<p class="bsm-rep-foot">Points project the on-page score, not traffic.</p></div><div class="bsm-rep-simlist">';
		snap.checks.forEach( function ( check, index ) {
			var selected = check.slug && taskPicks.indexOf( check.slug ) > -1;
			html += '<label class="bsm-rep-fix' + ( selected ? ' on' : '' ) + '"><input type="checkbox" data-index="' + index + '"' + ( selected ? ' checked' : '' ) + '>' +
				'<span class="bsm-rep-fixmain"><span class="t">' + esc( check.label ) + '</span><span class="d">' +
				number( check.affected ) + ' item' + ( check.affected === 1 ? '' : 's' ) + ' affected · ' +
				number( check.fail ) + ' failing, ' + number( check.warn ) + ' flagged</span></span>' +
				'<span class="bsm-rep-fixpts"><b>+' + check.points + '</b><span>' + effortFor( check.label ) +
				'</span></span></label>';
		} );
		simEl.innerHTML = html + '</div></div>';
		wireFilter( simEl );

		var boxes = simEl.querySelectorAll( '.bsm-rep-fix input' );
		function recalculate() {
			var points = 0;
			var items = 0;
			var efforts = [];
			var chosen = [];
			boxes.forEach( function ( checkbox ) {
				var check = snap.checks[ Number( checkbox.dataset.index ) ];
				checkbox.closest( '.bsm-rep-fix' ).classList.toggle( 'on', checkbox.checked );
				if ( checkbox.checked ) {
					points += Number( check.points );
					items += Number( check.affected );
					efforts.push( effortFor( check.label ) );
					if ( check.slug ) {
						chosen.push( check.slug );
					}
				}
			} );
			var score = Math.min( 100, Math.round( ( Number( snap.score ) + points ) * 10 ) / 10 );
			document.getElementById( 'bsm-sim-score' ).textContent = score;
			document.getElementById( 'bsm-sim-items' ).textContent = number( items );
			document.getElementById( 'bsm-sim-delta' ).textContent = points ? '+' + Math.round( points * 10 ) / 10 + ' points' : 'no change yet';
			document.getElementById( 'bsm-sim-effort' ).textContent = ! efforts.length ? '—' :
				efforts.indexOf( 'Writing' ) > -1 ? 'Weeks' : efforts.indexOf( 'Editor' ) > -1 ? 'Days' : 'An afternoon';
			document.getElementById( 'bsm-sim-grade' ).textContent = grade( score ).text;
			var start = document.getElementById( 'bsm-sim-start' );
			start.disabled = ! chosen.length;
			start.dataset.checks = chosen.join( ',' );
		}
		boxes.forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', recalculate );
		} );
		document.getElementById( 'bsm-sim-start' ).addEventListener( 'click', function ( event ) {
			var start = event.currentTarget;
			start.disabled = true;
			postTask( 'bsm_task_set', BSM_REPORT.set_nonce, { checks: start.dataset.checks || '' } )
				.then( function ( result ) {
					if ( ! result || ! result.success ) {
						throw new Error( 'Task list failed.' );
					}
					window.location.href = BSM_REPORT.tasks_url;
				} )
				.catch( function () {
					start.disabled = false;
				} );
		} );
		recalculate();
	}

	function postTask( action, nonce, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return fetch( BSM_REPORT.ajax_url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } );
	}

	function renderTasks() {
		if ( ! taskEl || ! snap ) {
			return;
		}
		var checks = snap.checks.filter( function ( check ) {
			return check.slug && taskPicks.indexOf( check.slug ) > -1;
		} );
		var html = '<div class="bsm-rep-head"><div><div class="bsm-rep-eyebrow">Task manager</div><h2>Your task list</h2>' +
			'<div class="bsm-rep-sub">Selected fixes grouped by check. Progress is stored only for your user.</div></div></div>';
		if ( ! snap.tasks_ready ) {
			taskEl.innerHTML = html + '<div class="bsm-rep-empty"><h2>Re-run the website audit</h2>' +
				'<p>This report predates Task Manager and does not contain the bounded page list needed to build tasks.</p></div>';
			return;
		}
		if ( ! checks.length ) {
			taskEl.innerHTML = html + '<div class="bsm-rep-empty"><h2>Nothing on the list yet</h2>' +
				'<p>Select fixes in the Impact Simulator to build a task list.</p></div>';
			return;
		}
		taskProgress = bsmCalculateTaskProgress( checks, taskDone );
		var total = Number( taskProgress.total || 0 );
		var done = Number( taskProgress.done || 0 );
		var points = Number( taskProgress.points || 0 );
		var percent = Number( taskProgress.percent || 0 );
		html += '<div class="bsm-rep-grid bsm-rep-hero bsm-rep-simgrid"><div class="bsm-rep-box bsm-rep-simpanel">' +
			'<div class="bsm-rep-ring"><b>' + percent + '%</b><span> complete</span></div>' +
			'<div class="bsm-rep-simstat"><span>Items on list</span><b>' + number( total ) + '</b></div>' +
			'<div class="bsm-rep-simstat"><span>Finished</span><b>' + number( done ) + '</b></div>' +
			'<div class="bsm-rep-simstat"><span>Points banked</span><b>+' + ( Math.round( points * 10 ) / 10 ) + '</b></div>' +
			'<button type="button" class="button bsm-task-reset">Clear finished ticks</button></div><div class="bsm-rep-tasklist">';
		checks.forEach( function ( check, index ) {
			var groupProgress = taskProgress.groups[ check.slug ] || { done: 0, percent: 0 };
			var complete = Number( groupProgress.done || 0 );
			var percentDone = Number( groupProgress.percent || 0 );
			var capNote = Number( check.listed || 0 ) < Number( check.affected || 0 )
				? '<span class="bsm-rep-capnote">Showing ' + number( check.listed ) + ' of ' + number( check.affected ) + ' affected pages. Re-run the audit after this batch.</span>'
				: '';
			html += '<details class="bsm-rep-task" data-check="' + esc( check.slug ) + '"' + ( index === 0 ? ' open' : '' ) + '>' +
				'<summary><b>' + esc( check.label ) + '</b><span>' + complete + ' of ' + number( check.affected ) + ' done</span>' +
				'<i style="width:' + percentDone + '%"></i></summary><div class="bsm-rep-taskrows"></div>' +
				capNote + '<button type="button" class="button bsm-rep-more" hidden>Show 25 more</button></details>';
		} );
		taskEl.innerHTML = html + '</div></div>';
		taskEl.querySelectorAll( '.bsm-rep-task' ).forEach( function ( group ) {
			function load( offset ) {
				postTask( 'bsm_task_items', BSM_REPORT.items_nonce, { check: group.dataset.check, offset: offset } )
					.then( function ( result ) {
						if ( ! result || ! result.success ) { return; }
						var rows = group.querySelector( '.bsm-rep-taskrows' );
						result.data.items.forEach( function ( item ) {
							var row = document.createElement( 'label' );
							row.className = 'bsm-rep-item' + ( item.done ? ' is-done' : '' );
							row.innerHTML = '<input type="checkbox"' + ( item.done ? ' checked' : '' ) + '><span>' +
								esc( item.title || '(no title)' ) + '</span><a href="' + esc( item.url ) +
								'" target="_blank" rel="noopener">Open in SEO Health</a>';
							row.querySelector( 'input' ).addEventListener( 'change', function ( event ) {
								var checked = event.currentTarget.checked;
								postTask( 'bsm_task_toggle', BSM_REPORT.toggle_nonce, {
									check: group.dataset.check,
									post: item.id,
									done: checked ? 1 : 0
								} ).then( function ( toggled ) {
									if ( toggled && toggled.success ) {
										taskDone = toggled.data.done || {};
										taskProgress = toggled.data.progress || taskProgress;
										renderTasks();
									}
								} );
							} );
							rows.appendChild( row );
						} );
						var more = group.querySelector( '.bsm-rep-more' );
						more.hidden = ! result.data.more;
						more.dataset.offset = result.data.next;
					} );
			}
			group.querySelector( '.bsm-rep-more' ).addEventListener( 'click', function ( event ) {
				load( Number( event.currentTarget.dataset.offset || 0 ) );
			} );
			if ( group.open ) {
				group.dataset.loaded = '1';
				load( 0 );
			}
			group.addEventListener( 'toggle', function () {
				if ( group.open && ! group.dataset.loaded ) {
					group.dataset.loaded = '1';
					load( 0 );
				}
			} );
		} );
		taskEl.querySelector( '.bsm-task-reset' ).addEventListener( 'click', function () {
			postTask( 'bsm_task_reset', BSM_REPORT.reset_nonce, {} ).then( function ( result ) {
				if ( result && result.success ) {
					taskDone = result.data.done || {};
					taskProgress = result.data.progress || taskProgress;
					renderTasks();
				}
			} );
		} );
	}

	function renderTrends() {
		if ( ! trendEl ) {
			return;
		}
		var runs = Array.isArray( history.runs ) ? history.runs : [];
		var html = '<div class="bsm-rep-head"><div><div class="bsm-rep-eyebrow">Trends</div>' +
			'<h2>How the site has moved</h2><div class="bsm-rep-sub">One point per completed audit.</div></div></div>';
		if ( ! runs.length ) {
			trendEl.innerHTML = html + '<div class="bsm-rep-empty"><h2>No history yet</h2>' +
				'<p>The first completed audit starts history. Comparisons appear after the second audit.</p></div>';
			return;
		}

		var width = 620;
		var height = 190;
		var shown = runs.slice( -12 );
		var x = function ( index ) {
			return 30 + ( shown.length < 2 ? 280 : index * 560 / ( shown.length - 1 ) );
		};
		var y = function ( score ) {
			return 165 - Math.max( 0, Math.min( 100, score ) ) * 1.4;
		};
		var path = shown.map( function ( run, index ) {
			return ( index ? 'L' : 'M' ) + x( index ) + ' ' + y( run.score );
		} ).join( ' ' );
		var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="SEO score over time">' +
			'<path d="' + path + '" fill="none" stroke="#028673" stroke-width="3"/>';
		shown.forEach( function ( run, index ) {
			svg += '<circle cx="' + x( index ) + '" cy="' + y( run.score ) + '" r="5" fill="#fff" stroke="#028673" stroke-width="3">' +
				'<title>' + esc( run.date ) + ': ' + run.score + '</title></circle>';
		} );
		svg += '</svg>';
		var latest = runs[ runs.length - 1 ];
		var previous = history.prev;
		html += '<div class="bsm-rep-grid bsm-rep-2"><div class="bsm-rep-box"><h3>Site score by audit</h3>' +
			svg + '<p class="bsm-rep-foot">' + number( runs.length ) + ' completed audit' +
			( runs.length === 1 ? '' : 's' ) + ', up to 50 retained.</p></div>' +
			'<div class="bsm-rep-box"><h3>Latest audit</h3><div class="bsm-rep-trendscore"><span class="bsm-rep-trendn">' +
			latest.score + '</span>' + ( previous ? trendDelta( latest.score - previous.score, true ) : '<span>First run</span>' ) +
			'</div><div class="bsm-rep-simstat"><span>Items audited</span><b>' + number( latest.total ) + '</b></div>' +
			'<div class="bsm-rep-simstat"><span>Pages &amp; posts average</span><b>' + latest.core + '</b></div>' +
			'<div class="bsm-rep-simstat"><span>Issues per item</span><b>' + latest.issues + '</b></div></div></div>';

		if ( Array.isArray( history.moved ) && history.moved.length ) {
			html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Check movement</h3>' +
				'<table class="bsm-rep-t"><thead><tr><th>Check</th><th class="r">Previous</th><th class="r">Now</th><th class="r">Change</th></tr></thead><tbody>';
			history.moved.forEach( function ( movement ) {
				html += '<tr><td>' + esc( movement.label ) + '</td><td class="r">' + number( movement.was ) +
					'</td><td class="r">' + number( movement.now ) + '</td><td class="r">' +
					trendDelta( movement.now - movement.was, false ) + '</td></tr>';
			} );
			html += '</tbody></table></div></div>';
		}

		html += '<div class="bsm-rep-grid"><div class="bsm-rep-box"><h3>Pages that scored lower</h3>';
		if ( Array.isArray( history.regressed ) && history.regressed.length ) {
			html += '<table class="bsm-rep-t"><thead><tr><th>Page</th><th class="r">Was</th><th class="r">Now</th></tr></thead><tbody>';
			history.regressed.forEach( function ( page ) {
				html += '<tr><td><a href="' + esc( page.edit ) + '">' + esc( page.title || '(no title)' ) +
					'</a></td><td class="r">' + page.was + '</td><td class="r">' + page.now + '</td></tr>';
			} );
			html += '</tbody></table>';
		} else {
			html += '<p class="bsm-rep-foot">' + ( previous ? 'No editable published pages scored lower.' :
				'Available after the second audit.' ) + '</p>';
		}
		trendEl.innerHTML = html + '</div></div>';
	}

	function trendDelta( value, increaseIsGood ) {
		var good = increaseIsGood ? value > 0 : value < 0;
		var className = value === 0 ? 'flat' : ( good ? 'up' : 'down' );
		var prefix = value > 0 ? '+' : '';
		return '<span class="bsm-rep-trendpill ' + className + '">' + prefix + Math.round( value * 10 ) / 10 + '</span>';
	}

	function scan( phase, offset, scanId ) {
		var body = new FormData();
		body.append( 'action', 'bsm_report_scan' );
		body.append( 'nonce', BSM_REPORT.nonce );
		body.append( 'phase', phase );
		body.append( 'offset', String( offset ) );
		body.append( 'scan_id', scanId );
		fetch( BSM_REPORT.ajax_url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					countEl.textContent = result && result.data && result.data.message ? result.data.message : 'Audit failed.';
					runBtn.disabled = false;
					runBtn.textContent = 'Run audit';
					return;
				}
				var data = result.data;
				var percent = data.total ? Math.round( data.done / data.total * 100 ) : 100;
				fillEl.style.width = percent + '%';
				countEl.textContent = number( data.done ) + ' of ' + number( data.total ) + ' scan steps';
				if ( data.complete ) {
					snap = data.snapshot;
					slices = snap.slices || [];
					selectedTypes = restoreTypes();
					history = data.history || history;
					runBtn.disabled = false;
					runBtn.textContent = 'Re-run audit';
					countEl.textContent = 'Done. ' + number( snap.total ) + ' items audited.';
					window.setTimeout( function () {
						progressEl.hidden = true;
						countEl.textContent = '';
					}, 1600 );
					if ( emptyEl ) {
						emptyEl.remove();
					}
					document.getElementById( 'bsm-rep-stamp' ).textContent = 'Last run just now';
					renderReport();
					renderSimulator();
					renderTasks();
					renderTrends();
					return;
				}
				scan( data.phase, data.offset, data.scan_id );
			} )
			.catch( function () {
				countEl.textContent = 'Request failed. You can start a new audit.';
				runBtn.disabled = false;
				runBtn.textContent = 'Run audit';
			} );
	}

	if ( runBtn ) {
		runBtn.addEventListener( 'click', function () {
			runBtn.disabled = true;
			runBtn.textContent = 'Auditing…';
			progressEl.hidden = false;
			fillEl.style.width = '0%';
			countEl.textContent = 'Starting…';
			scan( 'start', 0, '' );
		} );
	}

	renderReport();
	renderSimulator();
	renderTasks();
	renderTrends();
} )();
