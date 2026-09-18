import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const workflow = JSON.parse(
	readFileSync( join( here, '..', 'lookit-seo-copilot-bedrock-text.json' ), 'utf8' )
);

function codeNode( name, parameters = [] ) {
	const node = workflow.nodes.find( item => item.name === name );
	assert.ok( node, `The workflow has no "${ name }" node.` );
	return new Function( '$input', ...parameters, node.parameters.jsCode );
}

function input( json ) {
	return { first: () => ( { json } ) };
}

const auth = codeNode( 'Validate auth', [ '$env' ] );
const validate = codeNode( 'Validate input' );
const prepare = codeNode( 'Prepare input' );
const parse = codeNode( 'Parse response' );
const token = 'a'.repeat( 64 );

test( 'authentication requires a strong matching bearer token', () => {
	assert.equal(
		auth( input( { headers: { authorization: `Bearer ${ token }` } } ), { BSM_SEO_COPILOT_TOKEN: token } )[ 0 ].json.authorised,
		true
	);
	assert.equal(
		auth( input( { headers: { authorization: 'Bearer wrong' } } ), { BSM_SEO_COPILOT_TOKEN: token } )[ 0 ].json.authorised,
		false
	);
	assert.equal(
		auth( input( { headers: {} } ), { BSM_SEO_COPILOT_TOKEN: token } )[ 0 ].json.authorised,
		false
	);
	assert.equal(
		auth( input( { headers: { authorization: 'Bearer short' } } ), { BSM_SEO_COPILOT_TOKEN: 'short' } )[ 0 ].json.authorised,
		false
	);
} );

test( 'validation rejects malformed oversized and unknown tasks', () => {
	assert.equal( validate( input( { body: null } ) )[ 0 ].json.valid, false );
	assert.equal( validate( input( { body: [] } ) )[ 0 ].json.valid, false );
	assert.equal( validate( input( { body: { task: 'unknown' } } ) )[ 0 ].json.valid, false );
	assert.equal(
		validate( input( { body: { task: 'content', excerpt: 'x'.repeat( 33000 ) } } ) )[ 0 ].json.valid,
		false
	);
	assert.equal( validate( input( { body: { task: 'keyphrase' } } ) )[ 0 ].json.valid, true );
} );

test( 'array tasks request bounded JSON-array output', () => {
	for ( const task of [ 'keyphrase', 'subheadings', 'outline' ] ) {
		const result = prepare(
			input(
				{
					body: {
						task,
						title: 'Example title',
						count: 999,
						excerpt: 'x'.repeat( 5000 ),
					},
				}
			)
		)[ 0 ].json;
		const prompt = result.requestBody.messages[ 0 ].content[ 0 ].text;
		assert.match( prompt, /exactly 6/ );
		assert.match( prompt, /JSON array of strings only/ );
		assert.ok( prompt.length < 5000 );
	}
} );

test( 'text tasks preserve the current response contract', () => {
	for ( const task of [ 'metadesc', 'title', 'content' ] ) {
		const result = prepare(
			input(
				{
					body: {
						task,
						title: 'Example title',
						words: 99999,
						target: 'Selected section',
					},
				}
			)
		)[ 0 ].json;
		assert.equal( result.task, task );
		assert.equal( typeof result.requestBody.messages[ 0 ].content[ 0 ].text, 'string' );
		assert.ok( result.requestBody.inferenceConfig.maxTokens <= 4000 );
	}

	assert.deepEqual(
		parse(
			input(
				{
					output: {
						message: {
							content: [ { text: '  Generated text.  ' } ],
						},
					},
				}
			)
		)[ 0 ].json,
		{ text: 'Generated text.' }
	);
	assert.deepEqual( parse( input( {} ) )[ 0 ].json, { text: '' } );
} );

test( 'routing rejects unauthorised and malformed input before Bedrock', () => {
	assert.deepEqual( workflow.connections[ 'Authorised?' ].main[ 1 ].map( target => target.node ), [ 'Respond unauthorised' ] );
	assert.deepEqual( workflow.connections[ 'Valid input?' ].main[ 1 ].map( target => target.node ), [ 'Respond invalid' ] );
	assert.equal( workflow.nodes.find( node => node.name === 'Respond unauthorised' ).parameters.options.responseCode, 401 );
	assert.equal( workflow.nodes.find( node => node.name === 'Respond invalid' ).parameters.options.responseCode, 400 );
} );

test( 'workflow has no credentials and every connection resolves', () => {
	const serialized = JSON.stringify( workflow );
	assert.doesNotMatch( serialized, /AKIA[0-9A-Z]{16}/ );
	assert.doesNotMatch( serialized, /"credentials"\s*:/ );
	assert.doesNotMatch( serialized, /Header Auth account/ );

	const names = new Set( workflow.nodes.map( node => node.name ) );
	for ( const [ source, outputs ] of Object.entries( workflow.connections ) ) {
		assert.ok( names.has( source ) );
		for ( const branch of outputs.main ) {
			for ( const target of branch ) {
				assert.ok( names.has( target.node ), `Missing target node: ${ target.node }` );
			}
		}
	}
} );

test( 'workflow is excluded from releases and validated in CI', () => {
	const root = join( here, '..', '..' );
	const distignore = readFileSync( join( root, '.distignore' ), 'utf8' );
	const lintWorkflow = readFileSync( join( root, '.github', 'workflows', 'lint.yml' ), 'utf8' );
	assert.match( distignore, /^n8n\/$/m );
	assert.match( lintWorkflow, /lookit-seo-copilot-bedrock-text\.json/ );
	assert.match( lintWorkflow, /node --test 'n8n\/tests\/\*\.test\.mjs'/ );
} );
