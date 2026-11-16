/**
 * Translations without WP-CLI or gettext.
 *
 *   node tools/i18n.js pot      Write languages/lanyard.pot from the PHP sources.
 *   node tools/i18n.js compile  Compile every languages/lanyard-<locale>.po to
 *                               .mo and .l10n.php (WordPress 6.5+ loads the
 *                               PHP file first, WPML and older tools the .mo).
 *   node tools/i18n.js          Both.
 *
 * Only the PHP strings (front end, REST labels, pattern, privacy text) are
 * covered. Editor (JavaScript) strings need wp i18n make-json and are not
 * handled here. No dependencies.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const LANG = path.join( ROOT, 'languages' );
const DOMAIN = 'lanyard';
const SOURCES = [ 'lanyard.php', 'includes', path.join( 'src', 'card', 'render.php' ) ];

function phpFiles( rel ) {
	const abs = path.join( ROOT, rel );
	if ( ! fs.existsSync( abs ) ) {
		return [];
	}
	if ( fs.statSync( abs ).isDirectory() ) {
		return fs
			.readdirSync( abs )
			.filter( ( f ) => f.endsWith( '.php' ) )
			.sort()
			.map( ( f ) => path.join( rel, f ) );
	}
	return [ rel ];
}

function unescapeSingle( s ) {
	return s.replace( /\\([\\'])/g, '$1' );
}

/**
 * Find __( 'text', 'lanyard' ) and friends (single-quoted literals only).
 *
 * @return {Map<string,{refs:string[],comment:string}>}
 */
function extract() {
	const re = /\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'([a-z0-9-]+)'\s*\)/g;
	const found = new Map();

	for ( const rel of SOURCES.flatMap( phpFiles ) ) {
		const src = fs.readFileSync( path.join( ROOT, rel ), 'utf8' );
		const lines = src.split( '\n' );
		let m;
		while ( ( m = re.exec( src ) ) !== null ) {
			if ( m[ 2 ] !== DOMAIN ) {
				continue;
			}
			const line = src.slice( 0, m.index ).split( '\n' ).length;
			const msgid = unescapeSingle( m[ 1 ] );
			const near = lines.slice( Math.max( 0, line - 3 ), line ).join( '\n' );
			const tc = near.match( /translators:\s*(.*?)\s*\*\// );
			const entry = found.get( msgid ) || { refs: [], comment: '' };
			entry.refs.push( rel.replace( /\\/g, '/' ) + ':' + line );
			if ( tc && ! entry.comment ) {
				entry.comment = 'translators: ' + tc[ 1 ];
			}
			found.set( msgid, entry );
		}
	}
	return found;
}

function poQuote( s ) {
	return '"' + s.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' ).replace( /\t/g, '\\t' ) + '"';
}

function version() {
	const m = fs.readFileSync( path.join( ROOT, 'lanyard.php' ), 'utf8' ).match( /Version:\s*([0-9.]+)/ );
	return m ? m[ 1 ] : '0';
}

function header( fields ) {
	return Object.entries( fields )
		.map( ( [ k, v ] ) => k + ': ' + v + '\n' )
		.join( '' );
}

/**
 * Serialise entries to .po / .pot text.
 *
 * @param {Object} head    Header fields.
 * @param {Map}    strings msgid => {refs, comment}.
 * @param {Object} [tr]    msgid => msgstr ('' for a template).
 */
function writePo( file, head, strings, tr ) {
	let out = 'msgid ""\nmsgstr ""\n' + header( head ).split( '\n' ).filter( Boolean ).map( ( l ) => poQuote( l + '\n' ) ).join( '\n' ) + '\n';
	for ( const [ msgid, info ] of strings ) {
		out += '\n';
		if ( info.comment ) {
			out += '#. ' + info.comment + '\n';
		}
		out += '#: ' + info.refs.join( ' ' ) + '\n';
		if ( /%[sd]/.test( msgid ) ) {
			out += '#, php-format\n';
		}
		out += 'msgid ' + poQuote( msgid ) + '\nmsgstr ' + poQuote( tr ? tr[ msgid ] || '' : '' ) + '\n';
	}
	fs.writeFileSync( file, out, 'utf8' );
}

function poUnquote( s ) {
	return JSON.parse( s.replace( /\\t/g, '\\t' ) );
}

/**
 * Minimal .po parser: msgid/msgstr with continuation lines, no plurals.
 *
 * @return {{head:string, messages:Object}}
 */
function readPo( file ) {
	const messages = {};
	let head = '';
	let cur = null;
	let field = null;
	const flush = () => {
		if ( cur && cur.msgid !== undefined ) {
			if ( cur.msgid === '' ) {
				head = cur.msgstr || '';
			} else if ( cur.msgstr ) {
				messages[ cur.msgid ] = cur.msgstr;
			}
		}
	};
	for ( const raw of fs.readFileSync( file, 'utf8' ).split( /\r?\n/ ) ) {
		const line = raw.trim();
		if ( line.startsWith( 'msgid ' ) ) {
			flush();
			cur = { msgid: poUnquote( line.slice( 6 ) ) };
			field = 'msgid';
		} else if ( line.startsWith( 'msgstr ' ) && cur ) {
			cur.msgstr = poUnquote( line.slice( 7 ) );
			field = 'msgstr';
		} else if ( line.startsWith( '"' ) && cur && field ) {
			cur[ field ] += poUnquote( line );
		} else if ( line.startsWith( 'msgid_plural' ) || line.startsWith( 'msgctxt' ) ) {
			throw new Error( file + ': plurals and contexts are not supported by this tool' );
		}
	}
	flush();
	return { head, messages };
}

function writeMo( file, head, messages ) {
	const entries = [ [ '', head ], ...Object.entries( messages ) ]
		.map( ( [ k, v ] ) => [ Buffer.from( k, 'utf8' ), Buffer.from( v, 'utf8' ) ] )
		.sort( ( a, b ) => Buffer.compare( a[ 0 ], b[ 0 ] ) );
	const n = entries.length;
	const origTable = 28;
	const transTable = origTable + n * 8;
	let offset = transTable + n * 8;
	const head28 = Buffer.alloc( 28 );
	head28.writeUInt32LE( 0x950412de, 0 );
	head28.writeUInt32LE( 0, 4 );
	head28.writeUInt32LE( n, 8 );
	head28.writeUInt32LE( origTable, 12 );
	head28.writeUInt32LE( transTable, 16 );
	head28.writeUInt32LE( 0, 20 );
	head28.writeUInt32LE( offset, 24 );
	const tables = Buffer.alloc( n * 16 );
	const blobs = [];
	for ( const col of [ 0, 1 ] ) {
		entries.forEach( ( e, i ) => {
			tables.writeUInt32LE( e[ col ].length, col * n * 8 + i * 8 );
			tables.writeUInt32LE( offset, col * n * 8 + i * 8 + 4 );
			blobs.push( e[ col ], Buffer.from( [ 0 ] ) );
			offset += e[ col ].length + 1;
		} );
	}
	fs.writeFileSync( file, Buffer.concat( [ head28, tables, ...blobs ] ) );
}

function phpString( s ) {
	return "'" + s.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
}

function writeL10nPhp( file, head, messages ) {
	const meta = {};
	for ( const line of head.split( '\n' ) ) {
		const i = line.indexOf( ':' );
		if ( i > 0 ) {
			meta[ line.slice( 0, i ).trim().toLowerCase() ] = line.slice( i + 1 ).trim();
		}
	}
	const pick = [ 'project-id-version', 'language', 'plural-forms', 'x-domain' ];
	let out = '<?php\n// Generated by tools/i18n.js from ' + path.basename( file ).replace( '.l10n.php', '.po' ) + '. Do not edit.\nreturn array(\n';
	for ( const k of pick ) {
		if ( meta[ k ] ) {
			out += '\t' + phpString( k === 'x-domain' ? 'domain' : k ) + ' => ' + phpString( meta[ k ] ) + ',\n';
		}
	}
	out += "\t'messages' => array(\n";
	for ( const k of Object.keys( messages ).sort() ) {
		out += '\t\t' + phpString( k ) + ' => ' + phpString( messages[ k ] ) + ',\n';
	}
	out += '\t),\n);\n';
	fs.writeFileSync( file, out, 'utf8' );
}

function pot() {
	const strings = extract();
	writePo(
		path.join( LANG, DOMAIN + '.pot' ),
		{
			'Project-Id-Version': 'Lanyard ' + version(),
			'MIME-Version': '1.0',
			'Content-Type': 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding': '8bit',
			'X-Domain': DOMAIN,
		},
		strings
	);
	console.log( 'pot: ' + strings.size + ' strings' );
	return strings;
}

function compile() {
	const known = new Set( extract().keys() );
	let ok = true;
	for ( const f of fs.readdirSync( LANG ).filter( ( x ) => new RegExp( '^' + DOMAIN + '-[A-Za-z_]+\\.po$' ).test( x ) ).sort() ) {
		const { head, messages } = readPo( path.join( LANG, f ) );
		const missing = [ ...known ].filter( ( k ) => ! messages[ k ] );
		const obsolete = Object.keys( messages ).filter( ( k ) => ! known.has( k ) );
		const base = path.join( LANG, f.replace( /\.po$/, '' ) );
		writeMo( base + '.mo', head, messages );
		writeL10nPhp( base + '.l10n.php', head, messages );
		console.log( f + ': ' + Object.keys( messages ).length + ' translated, ' + missing.length + ' missing, ' + obsolete.length + ' obsolete' );
		missing.forEach( ( k ) => console.log( '  missing: ' + k ) );
		obsolete.forEach( ( k ) => console.log( '  obsolete: ' + k ) );
		if ( missing.length || obsolete.length ) {
			ok = false;
		}
	}
	return ok;
}

module.exports = { extract, writePo, readPo, version };

if ( require.main === module ) {
	const cmd = process.argv[ 2 ] || 'all';
	if ( cmd === 'pot' || cmd === 'all' ) {
		pot();
	}
	if ( cmd === 'compile' || cmd === 'all' ) {
		process.exitCode = compile() ? 0 : 1;
	}
}
