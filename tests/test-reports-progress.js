'use strict';

var assert = require( 'assert' );
var calculate = require( '../assets/reports.js' );
var result = calculate(
	[
		{ slug: 'url-length', affected: 5000, listed: 400, points: 10 },
		{ slug: 'meta-description', affected: 10, listed: 10, points: 2 }
	],
	{ 'url-length': 400, 'meta-description': 10 }
);

assert.strictEqual( result.total, 5010 );
assert.strictEqual( result.done, 410 );
assert.strictEqual( result.percent, 8 );
assert.strictEqual( result.points, 2.8 );
assert.strictEqual( result.groups[ 'url-length' ].percent, 8 );

var snapshot = {
	generated: 1,
	stale: false,
	tasks_ready: true,
	date: 'Today',
	site: 'Site',
	host: 'example.org',
	checks: [
		{ slug: 'word-count', listed: 3 },
		{ slug: 'url-length', listed: 2 }
	],
	urls: { ok: 40, max: 70 },
	slices: [
		{
			key: 'page',
			label: 'Page',
			core: true,
			n: 2,
			sum: 150,
			issues: 3,
			buckets: [ 0, 1, 1, 0 ],
			checks: [ { label: 'Word count', slug: 'word-count', fail: 1, warn: 1, good: 0, gain: 50 } ],
			url_n: 2,
			url_sum: 60,
			url_bands: [ 1, 1, 0, 0 ],
			url_long: [],
			worst: [ { title: 'Page', score: 50 } ]
		},
		{
			key: 'product',
			label: 'Product',
			core: false,
			n: 1,
			sum: 40,
			issues: 2,
			buckets: [ 1, 0, 0, 0 ],
			checks: [ { label: 'URL length', slug: 'url-length', fail: 1, warn: 0, good: 0, gain: 60 } ],
			url_n: 1,
			url_sum: 80,
			url_bands: [ 0, 0, 0, 1 ],
			url_long: [ { title: 'Product', len: 80 } ],
			worst: [ { title: 'Product', score: 40 } ]
		}
	]
};

var everything = calculate.derive( snapshot, [ 'page', 'product' ] );
assert.strictEqual( everything.total, 3 );
assert.strictEqual( everything.score, 63.3 );
assert.deepStrictEqual( everything.buckets, [ 1, 1, 1, 0 ] );
assert.deepStrictEqual( everything.urls.bands, [ 1, 1, 0, 1 ] );
assert.strictEqual( everything.issues, 1.7 );
assert.strictEqual( everything.checks.length, 2 );
assert.strictEqual(
	everything.checks.reduce( function ( total, check ) { return total + check.points; }, 0 ),
	36.7
);
assert.strictEqual( everything.top_three, 100 );
assert.strictEqual( everything.worst[ 0 ].title, 'Product' );

var pages = calculate.derive( snapshot, [ 'page', 'stale' ] );
assert.strictEqual( pages.total, 2 );
assert.strictEqual( pages.score, 75 );
assert.strictEqual( pages.scope, 'Page' );
assert.strictEqual( pages.checks[ 0 ].points, 25 );
assert.strictEqual( pages.top_three, 100 );
assert.strictEqual( calculate.derive( snapshot, [ 'unknown' ] ), null );

var remainderSnapshot = Object.assign( {}, snapshot, {
	checks: [
		{ slug: 'one', listed: 1 },
		{ slug: 'two', listed: 1 },
		{ slug: 'three', listed: 1 }
	],
	slices: [ {
		key: 'post',
		label: 'Post',
		core: true,
		n: 1,
		sum: 96,
		issues: 3,
		buckets: [ 0, 0, 0, 1 ],
		checks: [
			{ label: 'One', slug: 'one', fail: 1, warn: 0, good: 0, gain: 4 / 3 },
			{ label: 'Two', slug: 'two', fail: 1, warn: 0, good: 0, gain: 4 / 3 },
			{ label: 'Three', slug: 'three', fail: 1, warn: 0, good: 0, gain: 4 / 3 }
		],
		url_n: 1,
		url_sum: 10,
		url_bands: [ 1, 0, 0, 0 ],
		url_long: [],
		worst: []
	} ]
} );
var remainder = calculate.derive( remainderSnapshot, [ 'post' ] );
assert.deepStrictEqual( remainder.checks.map( function ( check ) { return check.points; } ), [ 1.4, 1.3, 1.3 ] );
assert.strictEqual(
	remainder.checks.reduce( function ( total, check ) { return total + check.points; }, 0 ),
	4
);

assert.deepStrictEqual(
	calculate.restoreTypes( [ 'post', 'page', 'product' ], '["page","retired"]' ),
	[ 'page' ]
);
assert.deepStrictEqual(
	calculate.restoreTypes( [ 'post', 'page' ], '["retired"]' ),
	[ 'post', 'page' ]
);
assert.deepStrictEqual(
	calculate.restoreTypes( [ 'post', 'page' ], 'not-json' ),
	[ 'post', 'page' ]
);
