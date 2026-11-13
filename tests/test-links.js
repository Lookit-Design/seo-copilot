'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

async function checkIgnore( response, rejects ) {
	let click;
	let settled = false;
	const errors = [];
	const opp = { src: 1, dst: 2, phrase: 'sample phrase' };
	const row = {
		dataset: { opp: '0' },
		classList: { add() { settled = true; } },
		querySelector() { return {}; }
	};
	const button = {
		dataset: { i: '0' },
		closest() { return row; },
		addEventListener( name, handler ) { click = handler; },
		parentNode: { appendChild( node ) { errors.push( node.textContent ); } }
	};
	const context = {
		BSM_LINKS: { links: { opps: [ opp ] } },
		document: {
			getElementById() { return {}; },
			createElement() { return { setAttribute() {}, get innerHTML() { return String( this.textContent || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); } }; }
		},
		FormData: class { append() {} },
		fetch() {
			return rejects ? Promise.reject( new Error( 'Network unavailable' ) ) : Promise.resolve( { text: () => Promise.resolve( JSON.stringify( response ) ) } );
		}
	};
	const source = fs.readFileSync( require.resolve( '../assets/links.js' ), 'utf8' );
	vm.runInNewContext( source.replace( /\n\trenderLinks\(\);\n/, '\n globalThis.wireRows = wireLinkRows;\n' ), context );
	context.wireRows( { querySelectorAll: selector => selector === '.bsm-rep-ign' ? [ button ] : [] } );
	click();
	assert.equal( settled, false, 'Wait for the server before marking ignored' );
	await new Promise( resolve => setImmediate( resolve ) );
	if ( response && response.success ) {
		assert.equal( settled, true );
		assert.equal( opp._done, 'Ignored' );
		assert.equal( errors.length, 0 );
	} else {
		assert.equal( settled, false );
		assert.equal( opp._done, undefined );
		assert.equal( button.disabled, false );
		assert.equal( errors.length, 1 );
	}
}

( async () => {
	await checkIgnore( { success: true } );
	await checkIgnore( { success: false, data: { message: 'Permission denied' } } );
	await checkIgnore( null, true );
	console.log( 'Links ignore success, server failure, and network failure checks passed.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
