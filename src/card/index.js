/**
 * Lanyard card block: registration.
 *
 * Dynamic block: save() returns null and render.php produces the markup, so
 * ACF values are read live on every page view.
 */
import { registerBlockType, createBlock } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import { mapLegacyAttributes } from './legacy';
import deprecated from './deprecated';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
	deprecated,
	transforms: {
		from: [
			{
				// Only offered while the original Business Card Block plugin
				// is still active (its block must be registered to transform).
				type: 'block',
				blocks: [ 'business/card' ],
				// Every old design becomes Profile (2.0.0): no style class.
				transform: ( attributes ) =>
					createBlock(
						metadata.name,
						mapLegacyAttributes( attributes )
					),
			},
		],
	},
} );
