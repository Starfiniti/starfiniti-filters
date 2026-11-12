// Prints the CHANGELOG.md section of the current version. It becomes the text of the GitHub release
// and the changelog WordPress shows under "View details".
import { readFileSync } from 'node:fs';

const version = JSON.parse( readFileSync( 'package.json', 'utf8' ) ).version;
const sections = readFileSync( 'CHANGELOG.md', 'utf8' ).split( /^## /m );
const section = sections.find( ( text ) => text.split( /\r?\n/ )[ 0 ] === version );
const notes = section?.slice( version.length ).trim();

if ( ! notes ) {
	console.error( `CHANGELOG.md has no notes for ${ version }.` );
	process.exit( 1 );
}

console.log( notes );
