// Runs the checks against a real shop.
//
// Without arguments it starts the demo store in WordPress Playground, runs tests/checks.php in it
// and then asks the shop pages for filtered lists as a visitor would.
//
// With SFFILTERS_TEST_URL set it skips Playground and asks that store instead. Run
// `wp eval-file tests/checks.php` there first and point SFFILTERS_TEST_RESULTS at its report.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const external = process.env.SFFILTERS_TEST_URL?.replace( /\/$/, '' );
const port = Number( process.env.SFFILTERS_TEST_PORT || 9411 );
const base = external || `http://127.0.0.1:${ port }`;
const report = resolve( process.env.SFFILTERS_TEST_RESULTS || '.cache/test-results.json' );
const results = [];
let server;

function check( name, pass, detail = '' ) {
	results.push( { name, pass, detail: pass ? '' : String( detail ) } );
}

async function page( path ) {
	const response = await fetch( base + path, {
		// Playground logs visitors in unless this cookie says it already did.
		headers: { cookie: 'playground_auto_login_already_happened=1' },
	} );

	return { status: response.status, html: await response.text() };
}

// Number of products the page says it found, read from the WooCommerce result count.
function total( html ) {
	const text = html.match( /<p class="woocommerce-result-count"[^>]*>([\s\S]*?)<\/p>/ )?.[ 1 ];

	if ( text === undefined ) {
		return ( html.match( /<li class="[^"]*\btype-product\b/g ) || [] ).length;
	}

	if ( /single result/.test( text ) ) {
		return 1;
	}

	return Number( text.match( /(?:all|of) (\d+) results/ )?.[ 1 ] ?? NaN );
}

async function startPlayground() {
	const blueprint = JSON.parse( readFileSync( 'playground/blueprint.json', 'utf8' ) );

	blueprint.login = false;
	blueprint.steps.push( {
		step: 'runPHP',
		// An error that stops the checks is reported as a failed check.
		code: `<?php
require_once '/wordpress/wp-load.php';
$plugin = '/wordpress/wp-content/plugins/starfiniti-filters/';
try {
	require $plugin . 'tests/checks.php';
} catch ( Throwable $error ) {
	$failed = array( 'name' => 'tests/checks.php ran to the end', 'pass' => false, 'detail' => $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() );
	file_put_contents( $plugin . '.cache/test-results.json', json_encode( array( 'checks' => array( $failed ), 'http' => array() ) ) );
}`,
	} );

	mkdirSync( '.cache', { recursive: true } );
	rmSync( report, { force: true } );
	writeFileSync( '.cache/test-blueprint.json', JSON.stringify( blueprint ) );

	let output = '';
	let exited = false;

	server = spawn(
		process.execPath,
		[
			'node_modules/@wp-playground/cli/wp-playground.js',
			'server',
			'--blueprint=.cache/test-blueprint.json',
			'--mount-before-install=.:/wordpress/wp-content/plugins/starfiniti-filters',
			`--port=${ port }`,
		],
		{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	server.stdout.on( 'data', ( chunk ) => ( output += chunk ) );
	server.stderr.on( 'data', ( chunk ) => ( output += chunk ) );
	server.on( 'exit', () => ( exited = true ) );

	const deadline = Date.now() + 10 * 60 * 1000;

	while ( Date.now() < deadline ) {
		if ( exited ) {
			throw new Error( `WordPress Playground stopped before the shop was ready.\n${ output }` );
		}

		if ( existsSync( report ) ) {
			const ready = await page( '/shop/' ).catch( () => null );

			if ( ready?.status === 200 ) {
				return;
			}
		}

		await new Promise( ( done ) => setTimeout( done, 2000 ) );
	}

	throw new Error( `The shop did not answer within ten minutes.\n${ output }` );
}

async function shopChecks( expected ) {
	const shop = await page( '/shop/' );
	const robots = ( html ) => html.match( /<meta name=["']robots["'][^>]*>/ )?.[ 0 ] ?? '';

	check( 'shop: the page loads', shop.status === 200, shop.status );
	check( 'shop: the products are wrapped for swapping', shop.html.includes( 'data-sffilters-results' ) );
	check( 'shop: the panel is on the page', /name="sf_cat\[\]"[^>]*value="headphones"|value="headphones"[^>]*name="sf_cat\[\]"/.test( shop.html ) );
	check( 'shop: the unfiltered page is not marked noindex', ! /noindex/.test( robots( shop.html ) ), robots( shop.html ) );

	const filtered = await page( '/shop/?sf_cat=headphones' );
	const shown = Number( shop.html.match( /value="headphones"[\s\S]*?sffilters-option__count[^>]*>\s*\(?(\d+)/ )?.[ 1 ] ?? NaN );

	check( 'shop: the count next to a category is what choosing it lists', shown > 0 && shown === total( filtered.html ), `panel says ${ shown }, the list has ${ total( filtered.html ) }` );
	check( 'shop: a filtered page is marked noindex', /noindex/.test( robots( filtered.html ) ), robots( filtered.html ) );
	check( 'shop: the chosen value is ticked in the panel', /value="headphones"[^>]*\bchecked\b|\bchecked\b[^>]*value="headphones"/.test( filtered.html ) );
	check( 'shop: the chosen value can be removed above the products', /sffilters-chip/.test( filtered.html ) && ! /sffilters-chip/.test( shop.html ) );

	for ( const item of expected ) {
		const result = await page( item.path + ( item.query ? `?${ item.query }` : '' ) );
		const found = total( result.html );

		check( `shop: ${ item.name }`, result.status === 200 && found === item.total, `${ item.path }?${ item.query } answered ${ result.status } with ${ found } products, expected ${ item.total }` );
	}

	const second = await page( '/shop/page/2/?sf_stock=instock' );
	const inStock = expected.find( ( item ) => item.name === 'in stock' )?.total;

	check( 'shop: the second page keeps the filter', second.status === 200 && total( second.html ) === inStock, `answered ${ second.status } with ${ total( second.html ) } of ${ inStock }` );
}

try {
	if ( ! external ) {
		await startPlayground();
	}

	if ( ! existsSync( report ) ) {
		throw new Error( `No report from tests/checks.php at ${ report }.` );
	}

	const fromPhp = JSON.parse( readFileSync( report, 'utf8' ) );

	results.push( ...fromPhp.checks );
	await shopChecks( fromPhp.http );
} catch ( error ) {
	check( 'the test run itself', false, error.stack || error.message );
} finally {
	server?.kill();
}

const failed = results.filter( ( result ) => ! result.pass );

for ( const result of results ) {
	console.log( ( result.pass ? 'ok      ' : 'FAILED  ' ) + result.name + ( result.detail ? `\n        ${ result.detail }` : '' ) );
}

console.log( `\n${ results.length - failed.length } of ${ results.length } checks passed.` );
process.exit( failed.length ? 1 : 0 );
