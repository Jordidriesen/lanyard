/**
 * Field list ordering for the dynamic-data picker (2.1.0).
 *
 * Pure helper without imports, so it can be unit tested with Node
 * (tools/field-order.test.mjs). The server (lanyard/v1/fields) lists every
 * ACF field group and marks those whose location rules match the source with
 * `matches: true`; this helper turns that into the select's sections:
 *
 *   1. non-ACF groups (WordPress core fields), unchanged;
 *   2. "Matches this <post type>": fields of matching ACF groups;
 *   3. "Other field groups": fields of every other ACF group.
 *
 * Within a section the server's group and field order is kept. ACF options
 * read "Label (Group)". The match is a hint only: it never hides a field.
 */

/**
 * Build the picker sections.
 *
 * @param {Array}  groups             Groups from lanyard/v1/fields.
 * @param {Object} titles             Section titles (translated by the caller).
 * @param {string} titles.matches     Title of the matching section.
 * @param {string} titles.other       Title of the other section.
 * @param {string} [titles.noMatches] Title of the only ACF section when no group matches.
 * @return {Array} [ { key, title, acf, fields: [ { ...field, optionLabel, group, matches } ] } ]
 */
export function buildFieldSections( groups, titles ) {
	const list = Array.isArray( groups ) ? groups : [];
	const core = [];
	const matching = [];
	const other = [];

	list.forEach( ( group ) => {
		if ( ! group || typeof group !== 'object' ) {
			return;
		}
		const fields = Array.isArray( group.fields ) ? group.fields : [];
		if ( ! group.acf ) {
			core.push( {
				key: String( group.key ),
				title: String( group.title || '' ),
				acf: false,
				fields: fields.map( ( f ) => ( {
					...f,
					optionLabel: String( f.label || f.value || '' ),
					group: '',
					matches: true,
				} ) ),
			} );
			return;
		}
		const name = String( group.group || group.title || '' );
		const target = group.matches ? matching : other;
		fields.forEach( ( f ) => {
			const label = String( f.label || f.value || '' );
			target.push( {
				...f,
				optionLabel: name ? `${ label } (${ name })` : label,
				group: name,
				matches: !! group.matches,
			} );
		} );
	} );

	const sections = [ ...core ];
	if ( matching.length ) {
		sections.push( {
			key: 'acf-matches',
			title: titles.matches,
			acf: true,
			fields: matching,
		} );
	}
	if ( other.length ) {
		sections.push( {
			key: 'acf-other',
			title:
				! matching.length && titles.noMatches
					? titles.noMatches
					: titles.other,
			acf: true,
			fields: other,
		} );
	}
	return sections;
}
