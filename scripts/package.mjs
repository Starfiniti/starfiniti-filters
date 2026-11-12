// Builds the release ZIP and its SHA-256 checksum in dist/, from the files of the last commit.
//
// Only the files a store needs go in. The version must be the same in package.json, in the
// plugin header and in the SFFILTERS_VERSION constant, and CHANGELOG.md must describe it.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const contents = [ 'starfiniti-filters.php', 'uninstall.php', 'LICENSE', 'README.md', 'CHANGELOG.md', 'src', 'assets', 'languages' ];

const version = JSON.parse( readFileSync( 'package.json', 'utf8' ) ).version;
const plugin = readFileSync( 'starfiniti-filters.php', 'utf8' );
const header = plugin.match( /^ \* Version:\s*(\S+)/m )?.[ 1 ];
const constant = plugin.match( /define\( 'SFFILTERS_VERSION', '([^']+)' \)/ )?.[ 1 ];

function stop( message ) {
	console.error( message );
	process.exit( 1 );
}

if ( header !== version || constant !== version ) {
	stop( `Versions differ: package.json ${ version }, plugin header ${ header }, SFFILTERS_VERSION ${ constant }.` );
}

if ( ! new RegExp( `^## ${ version.replaceAll( '.', '\\.' ) }$`, 'm' ).test( readFileSync( 'CHANGELOG.md', 'utf8' ) ) ) {
	stop( `CHANGELOG.md has no "## ${ version }" section.` );
}

const uncommitted = execFileSync( 'git', [ 'status', '--porcelain', '--', ...contents ], { encoding: 'utf8' } ).trim();

if ( uncommitted ) {
	stop( `Commit these changes first, the ZIP is built from the last commit:\n${ uncommitted }` );
}

const name = `starfiniti-filters-${ version }.zip`;
const path = join( 'dist', name );

mkdirSync( 'dist', { recursive: true } );
rmSync( path, { force: true } );

// The folder inside the ZIP is the folder WordPress installs the plugin into.
execFileSync( 'git', [ 'archive', '--format=zip', '-9', '--prefix=starfiniti-filters/', `--output=${ path }`, 'HEAD', ...contents ] );

const checksum = createHash( 'sha256' ).update( readFileSync( path ) ).digest( 'hex' );

writeFileSync( `${ path }.sha256`, `${ checksum }  ${ name }\n` );
console.log( `${ path }\n${ path }.sha256\n${ checksum }` );
