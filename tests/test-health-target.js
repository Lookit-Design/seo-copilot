'use strict';

var assert = require( 'assert' );
var targetWordValue = require( '../assets/health.js' );

assert.strictEqual( targetWordValue( false, '600', false ), '600' );
assert.strictEqual( targetWordValue( true, '600', false ), '250' );
assert.strictEqual( targetWordValue( false, '250', false ), '600' );
assert.strictEqual( targetWordValue( true, '900', true ), '900' );
assert.strictEqual( targetWordValue( false, '900', true ), '900' );
