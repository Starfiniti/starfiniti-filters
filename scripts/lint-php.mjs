// Runs `php -l` on every PHP file in the plugin.
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';

const skip = new Set( [ 'node_modules', 'vendor', 'dist', '.git', '.cache' ] );

function phpFiles( dir ) {
	const found = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		if ( skip.has( entry.name ) ) {
			continue;
		}
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			found.push( ...phpFiles( path ) );
		} else if ( entry.name.endsWith( '.php' ) ) {
			found.push( path );
		}
	}
	return found;
}

const files = phpFiles( '.' );
let failed = 0;

for ( const file of files ) {
	try {
		execFileSync( 'php', [ '-l', file ], { stdio: 'pipe' } );
	} catch ( error ) {
		failed++;
		console.error( String( error.stdout || error.stderr || error.message ).trim() );
	}
}

console.log( `${ files.length - failed } of ${ files.length } PHP files passed.` );
process.exit( failed ? 1 : 0 );
