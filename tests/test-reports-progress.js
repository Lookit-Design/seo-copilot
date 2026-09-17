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
