/**
 * Inline dynamic-data tags: client-side helpers.
 *
 * Syntax: {{lnyd source:<source>|field:<slug>|...}} or {{lnyd source:<source>|acf:<key>|...}}
 * See includes/class-tags.php. The server is the only place tags are
 * resolved; these helpers only build tags and describe them in the editor.
 */
import { __, sprintf } from '@wordpress/i18n';

// Same shape as Tags::PATTERN in PHP (global flag for scanning).
export const TAG_PATTERN =
	/\{\{lnyd ([a-z]{2,8}:[^|{}\r\n]{0,400}(?:\|[a-z]{2,8}:[^|{}\r\n]{0,400}){0,8})\}\}/g;

export const MAX_FALLBACK = 120;

export const KEYS = [
	'source',
	'field',
	'acf',
	'type',
	'id',
	'user',
	'fallback',
	'format',
	'size',
];

// Slots whose href must come from exactly one value: single tag only.
export const LINK_SLOTS = [ 'phone', 'mobile', 'email', 'website', 'linkedin' ];

export const DATE_FORMATS = [
	{ value: 'site', label: __( 'Site date format', 'lanyard' ) },
	{ value: 'iso', label: '2026-10-22' },
	{ value: 'dmy', label: '22/10/2026' },
	{ value: 'mdy', label: '10/22/2026' },
	{ value: 'long', label: __( '22 October 2026', 'lanyard' ) },
];

/**
 * Strip characters a tag value may not contain, and cap the length.
 *
 * @param {string} value Raw text.
 * @return {string} Clean text.
 */
export function cleanFallback( value ) {
	return String( value || '' )
		.replace( /[|{}\r\n\t]+/g, ' ' )
		.trim()
		.slice( 0, MAX_FALLBACK );
}

/**
 * Build a tag from a spec.
 *
 * @param {Object} spec { source, field|acf, type, id, user, fallback, format, size }.
 * @return {string} Tag text.
 */
export function buildTag( spec ) {
	const parts = [];
	KEYS.forEach( ( key ) => {
		let value = spec[ key ];
		if ( value === undefined || value === null || value === '' ) {
			return;
		}
		value = key === 'fallback' ? cleanFallback( value ) : String( value );
		if ( value !== '' && ! /[|{}\r\n]/.test( value ) ) {
			parts.push( `${ key }:${ value }` );
		}
	} );
	return `{{lnyd ${ parts.join( '|' ) }}}`;
}

/**
 * Parse a tag's argument string (no validation beyond shape).
 *
 * @param {string} raw Arguments.
 * @return {Object|null} Args, or null when malformed.
 */
export function parseArgs( raw ) {
	const out = {};
	for ( const pair of String( raw ).split( '|' ) ) {
		const i = pair.indexOf( ':' );
		if ( i < 1 ) {
			return null;
		}
		const key = pair.slice( 0, i );
		if ( ! KEYS.includes( key ) || key in out ) {
			return null;
		}
		out[ key ] = pair.slice( i + 1 );
	}
	return out.source && ( out.field || out.acf ) ? out : null;
}

/**
 * Every tag in a string.
 *
 * @param {string} value Field value.
 * @return {Array} [ { text, args } ]
 */
export function findTags( value ) {
	const out = [];
	if ( typeof value !== 'string' || ! value.includes( '{{lnyd ' ) ) {
		return out;
	}
	for ( const m of value.matchAll( TAG_PATTERN ) ) {
		out.push( { text: m[ 0 ], args: parseArgs( m[ 1 ] ) } );
	}
	return out;
}

/**
 * Whether the whole (trimmed) value is exactly one tag.
 *
 * @param {string} value Field value.
 * @return {boolean} True for a single tag.
 */
export function isSingleTag( value ) {
	const tags = findTags( value );
	return tags.length === 1 && tags[ 0 ].text === String( value ).trim();
}

/**
 * Whether a link slot mixes text and tags (which renders without a link).
 *
 * @param {string} value Field value.
 * @return {boolean} True when mixed.
 */
export function isMixed( value ) {
	return findTags( value ).length > 0 && ! isSingleTag( value );
}

/**
 * Readable description of one tag.
 *
 * @param {Object|null} args   Parsed args.
 * @param {Object}      labels Field labels by "field:x" / "acf:x".
 * @return {string} Summary.
 */
export function describeTag( args, labels = {} ) {
	if ( ! args ) {
		return __( 'Invalid tag (it will be removed on the page)', 'lanyard' );
	}
	const sources = {
		post: __( 'This post', 'lanyard' ),
		post_ref: sprintf(
			/* translators: 1: post type, 2: post ID. */
			__( '%1$s #%2$s', 'lanyard' ),
			args.type || '?',
			args.id || '?'
		),
		site: __( 'Site', 'lanyard' ),
		user:
			! args.user || args.user === 'author'
				? __( 'Post author', 'lanyard' )
				: sprintf(
						/* translators: %s: user ID. */
						__( 'User #%s', 'lanyard' ),
						args.user
					),
		option: __( 'ACF options page', 'lanyard' ),
	};
	const key = args.field ? `field:${ args.field }` : `acf:${ args.acf }`;
	const field =
		labels[ key ] ||
		( args.field ? args.field : sprintf( 'ACF %s', args.acf ) );
	let text = `${ sources[ args.source ] || args.source } › ${ field }`;
	if ( args.fallback ) {
		text +=
			' ' +
			sprintf(
				/* translators: %s: fallback text. */
				__( '(fallback: “%s”)', 'lanyard' ),
				args.fallback
			);
	}
	return text;
}

/**
 * Convert 1.1.0 attributes (sources, userId, refPostType, refPostId) into
 * inline tags. Mirrors Tags::migrate_v110() in PHP.
 *
 * @param {Object} attributes Old attributes.
 * @return {Object} New attributes.
 */
export function migrateSources( attributes ) {
	const {
		sources = {},
		userId = 0,
		refPostType = '',
		refPostId = 0,
		...rest
	} = attributes;
	const out = { ...rest };

	Object.entries( sources || {} ).forEach( ( [ slot, spec ] ) => {
		const from = spec?.from;
		const field = spec?.field;
		if (
			! [ 'post', 'post_ref', 'option', 'user' ].includes( from ) ||
			! /^[A-Za-z0-9_-]{1,100}$/.test( field || '' )
		) {
			return;
		}
		const tag = { source: from, acf: field };
		if ( from === 'post_ref' ) {
			if ( ! refPostType || ! refPostId ) {
				return; // 1.1.0 showed the static value here.
			}
			tag.type = refPostType;
			tag.id = refPostId;
		} else if ( from === 'user' ) {
			tag.user = userId > 0 ? String( userId ) : 'author';
		}
		if ( slot === 'avatar' ) {
			if ( ! out.avatarTag ) {
				out.avatarTag = buildTag( tag );
			}
			return;
		}
		const current = typeof out[ slot ] === 'string' ? out[ slot ] : '';
		if ( findTags( current ).length ) {
			return;
		}
		tag.fallback = current;
		out[ slot ] = buildTag( tag );
	} );

	return out;
}

/**
 * Every block style name a stored card may still carry (is-style-*): the
 * 1.1.0 names (minimal, sidebar, banner), the 1.2.0 "Split, mirrored" and
 * the 1.3.0 to 1.4.1 styles. 2.0.0 has no block styles: Profile is the
 * only look. Mirrors Renderer::OBSOLETE_STYLES in PHP.
 */
export const OBSOLETE_STYLES = [
	'default',
	'split',
	'two-tone',
	'centered',
	'profile',
	'mirrored',
	'minimal',
	'sidebar',
	'banner',
];

const OBSOLETE_CLASSES = OBSOLETE_STYLES.map( ( s ) => `is-style-${ s }` );

/**
 * Remove every obsolete is-style-* class from a className (2.0.0). Other
 * classes are kept in their order.
 *
 * @param {string|undefined} className Class names.
 * @return {string|undefined} Class names, or undefined when none are left.
 */
export function stripStyleClasses( className ) {
	if ( typeof className !== 'string' ) {
		return className;
	}
	const kept = className
		.split( /\s+/ )
		.filter( ( c ) => c && ! OBSOLETE_CLASSES.includes( c ) )
		.join( ' ' );
	return kept || undefined;
}

/**
 * Whether a className carries any obsolete block style class.
 *
 * @param {string} className Class names.
 * @return {boolean} True when it does.
 */
export function hasObsoleteStyle( className ) {
	return (
		typeof className === 'string' &&
		className.split( /\s+/ ).some( ( c ) => OBSOLETE_CLASSES.includes( c ) )
	);
}

/**
 * Whether a className still uses a 1.1.0 style name.
 *
 * @param {string} className Class names.
 * @return {boolean} True when it does.
 */
export function hasOldStyle( className ) {
	return (
		typeof className === 'string' &&
		/(?:^|\s)is-style-(?:minimal|sidebar|banner)(?:\s|$)/.test( className )
	);
}

/**
 * Whether a className uses the 1.2.0 "Split, mirrored" style.
 *
 * @param {string} className Class names.
 * @return {boolean} True when it does.
 */
export function hasMirroredStyle( className ) {
	return (
		typeof className === 'string' &&
		/(?:^|\s)is-style-mirrored(?:\s|$)/.test( className )
	);
}
