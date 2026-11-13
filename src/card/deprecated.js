/**
 * Block deprecations.
 *
 * The block is dynamic (save returns null), so an old block is still
 * "valid"; each entry's `isEligible` opts a block into its migration when
 * the raw stored attributes show the old shape.
 *
 * How Gutenberg runs them (packages/blocks/src/api/parser/
 * apply-block-deprecated-versions.js, wp/6.6, re-read for 2.0.0): isEligible
 * receives the RAW parsed attributes; the loop does not stop at the first
 * match but walks every entry in array order, and for each eligible entry it
 * rebuilds the attributes from the raw ones (not from the previous
 * migration) and runs that entry's migrate. So when two entries match, only
 * the last one's result survives and the other migration is lost. The three
 * entries below are therefore mutually exclusive (isV110, isV120, isV141),
 * and each migrate does the complete job for its shape: old data into the
 * 2.0.0 shape, every obsolete is-style-* class removed.
 *
 * - v141: 1.3.0 to 1.4.1 offered five block styles (is-style-default,
 *   split, two-tone, centered, profile). 2.0.0 has none: Profile is the
 *   only look. migrate removes the classes and the showSeparator attribute
 *   (no separator in Profile). Attribute set: the 1.4.1 block.json.
 * - v120: 1.2.0 "Split, mirrored" (is-style-mirrored). Same migrate.
 *   Attribute set: 1.4.1 without cardWidth (new in 1.3.0).
 * - v110: 1.1.0 stored dynamic data in `sources` ({ slot: { from, field } })
 *   plus the block-level `userId`, `refPostType` and `refPostId`, and
 *   offered minimal / sidebar / banner. migrate turns sources into inline
 *   tags and removes every obsolete style class.
 *
 * Cards without any obsolete class or 1.1.0 key (for example a 1.4.1
 * Stacked card, which had no class) match no entry: they are already valid
 * 2.0.0 blocks; an unknown stored showSeparator is ignored by the parser.
 *
 * The server mirrors this: Tags::migrate_v110() at render time, and
 * Renderer::layout_name() / layout() render every card as Profile.
 */
import metadata from './block.json';
import {
	migrateSources,
	stripStyleClasses,
	hasObsoleteStyle,
	hasOldStyle,
	hasMirroredStyle,
} from './tags';

// The 1.4.1 attribute set: 2.0.0 plus the removed showSeparator.
const v141Attributes = {
	...metadata.attributes,
	showSeparator: { type: 'boolean', default: true },
};
const { cardWidth: _cardWidth, ...v120Attributes } = v141Attributes;
const {
	avatarTag: _avatarTag,
	cardWidth: _cardWidth110,
	...v110Attributes
} = v141Attributes;

const isV110 = ( attributes ) =>
	!! attributes &&
	( 'sources' in attributes ||
		'userId' in attributes ||
		'refPostType' in attributes ||
		'refPostId' in attributes ||
		hasOldStyle( attributes.className ) );

const isV120 = ( attributes ) =>
	!! attributes &&
	! isV110( attributes ) &&
	hasMirroredStyle( attributes.className );

const isV141 = ( attributes ) =>
	!! attributes &&
	! isV110( attributes ) &&
	! isV120( attributes ) &&
	hasObsoleteStyle( attributes.className );

/**
 * Bring attributes into the 2.0.0 shape: no obsolete style classes, no
 * showSeparator.
 *
 * @param {Object} attributes Attributes.
 * @return {Object} 2.0.0 attributes.
 */
function toProfile( attributes ) {
	const { showSeparator: _sep, className, ...rest } = attributes;
	const kept = stripStyleClasses( className );
	return kept ? { ...rest, className: kept } : rest;
}

const v141 = {
	attributes: v141Attributes,
	save: () => null,
	isEligible: isV141,
	migrate: toProfile,
};

const v120 = {
	attributes: v120Attributes,
	save: () => null,
	isEligible: isV120,
	migrate: toProfile,
};

const v110 = {
	attributes: {
		...v110Attributes,
		sources: { type: 'object', default: {} },
		userId: { type: 'integer', default: 0 },
		refPostType: { type: 'string', default: '' },
		refPostId: { type: 'integer', default: 0 },
	},
	save: () => null,
	isEligible: isV110,
	migrate: ( attributes ) => toProfile( migrateSources( attributes ) ),
};

export default [ v141, v120, v110 ];
