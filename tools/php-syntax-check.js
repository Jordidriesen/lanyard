/**
 * PHP syntax check without a PHP binary.
 *
 * Parses every .php file with the php-parser package (glayzzle) and reports
 * parse errors. This is NOT a replacement for `php -l` (it does not run the
 * real PHP engine), but it catches syntax errors when PHP is not installed.
 *
 * Usage: node tools/php-syntax-check.js <file-or-dir> [...]
 */
const fs = require( 'fs' );
const path = require( 'path' );
const PhpParser = require( 'php-parser' );

const parser = new PhpParser( {
	parser: { php7: true, extractDoc: false, version: '7.4' },
	ast: { withPositions: false },
} );

function collect( target, out ) {
	const stat = fs.statSync( target );
	if ( stat.isDirectory() ) {
		for ( const entry of fs.readdirSync( target ) ) {
			if ( entry === 'node_modules' || entry === 'build' ) {
				continue;
			}
			collect( path.join( target, entry ), out );
		}
	} else if ( target.endsWith( '.php' ) ) {
		out.push( target );
	}
	return out;
}

const files = process.argv
	.slice( 2 )
	.reduce( ( acc, t ) => collect( t, acc ), [] );
let failed = 0;

for ( const file of files ) {
	try {
		parser.parseCode( fs.readFileSync( file, 'utf8' ), file );
		console.log( `OK    ${ file }` );
	} catch ( e ) {
		failed++;
		console.log( `ERROR ${ file }: ${ e.message }` );
	}
}

console.log( `\n${ files.length } file(s), ${ failed } with syntax errors.` );
process.exit( failed ? 1 : 0 );
