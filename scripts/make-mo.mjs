// Compiles every `languages/*.po` file into the `.mo` file WordPress loads.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = 'languages';

function unquote( text ) {
	return JSON.parse( text.trim() );
}

// Returns a map from the MO key to the MO value of every translated entry.
function parse( source ) {
	const entries = new Map();
	let entry = null;
	let field = null;

	const flush = () => {
		if ( entry && entry.msgstr.some( ( text ) => '' !== text ) ) {
			const context = null === entry.msgctxt ? '' : entry.msgctxt + '\u0004';
			const plural = null === entry.plural ? '' : '\u0000' + entry.plural;

			entries.set( context + entry.msgid + plural, entry.msgstr.join( '\u0000' ) );
		}

		entry = null;
	};

	for ( const line of source.split( /\r?\n/ ) ) {
		const match = line.match( /^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")\s*$/ );

		if ( match ) {
			if ( 'msgctxt' === match[ 1 ] || ( 'msgid' === match[ 1 ] && ( ! entry || null !== entry.msgid ) ) ) {
				flush();
			}

			entry = entry || { msgctxt: null, msgid: null, plural: null, msgstr: [] };

			if ( match[ 1 ].startsWith( 'msgstr' ) ) {
				field = [ 'msgstr', Number( match[ 2 ] || 0 ) ];
				entry.msgstr[ field[ 1 ] ] = unquote( match[ 3 ] );
			} else {
				field = [ 'msgid_plural' === match[ 1 ] ? 'plural' : match[ 1 ] ];
				entry[ field[ 0 ] ] = unquote( match[ 3 ] );
			}
		} else if ( entry && field && /^\s*".*"\s*$/.test( line ) ) {
			if ( 'msgstr' === field[ 0 ] ) {
				entry.msgstr[ field[ 1 ] ] += unquote( line );
			} else {
				entry[ field[ 0 ] ] += unquote( line );
			}
		}
	}

	flush();

	return entries;
}

function compile( entries ) {
	const rows = [ ...entries ]
		.map( ( [ key, value ] ) => [ Buffer.from( key ), Buffer.from( value ) ] )
		.sort( ( a, b ) => Buffer.compare( a[ 0 ], b[ 0 ] ) );
	const tables = 28 + rows.length * 16;
	const head = Buffer.alloc( tables );
	const strings = [];
	let offset = tables;

	head.writeUInt32LE( 0x950412de, 0 );
	head.writeUInt32LE( 0, 4 );
	head.writeUInt32LE( rows.length, 8 );
	head.writeUInt32LE( 28, 12 );
	head.writeUInt32LE( 28 + rows.length * 8, 16 );
	head.writeUInt32LE( 0, 20 );
	head.writeUInt32LE( tables, 24 );

	[ 0, 1 ].forEach( ( column ) => {
		rows.forEach( ( row, index ) => {
			const at = 28 + column * rows.length * 8 + index * 8;

			head.writeUInt32LE( row[ column ].length, at );
			head.writeUInt32LE( offset, at + 4 );
			strings.push( row[ column ], Buffer.from( [ 0 ] ) );
			offset += row[ column ].length + 1;
		} );
	} );

	return Buffer.concat( [ head, ...strings ] );
}

for ( const file of readdirSync( dir ).filter( ( name ) => name.endsWith( '.po' ) ) ) {
	const entries = parse( readFileSync( join( dir, file ), 'utf8' ) );

	writeFileSync( join( dir, file.replace( /\.po$/, '.mo' ) ), compile( entries ) );
	console.log( `${ file }: ${ entries.size - 1 } strings` );
}
