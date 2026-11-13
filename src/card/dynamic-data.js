/**
 * Dynamic-data picker: a small icon button next to each content field that
 * opens a popover (Dropdown and Popover from the components package) with four
 * steps: 1. source, 2. field, 3. options, 4. insert.
 *
 * Data comes from the permission-checked routes lanyard/v1/post-types and
 * lanyard/v1/fields (edit_posts), and from core REST through core-data
 * (published posts with context=view, users). Nothing here resolves values:
 * the server does that at render time and in the ServerSideRender preview.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ComboboxControl,
	Dropdown,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { buildTag, cleanFallback, DATE_FORMATS, MAX_FALLBACK } from './tags';
import { buildFieldSections } from './field-order';

// REST responses cached for the editor session.
const restCache = {};

// Field labels seen so far, keyed "field:<slug>" / "acf:<key>", for summaries.
export const fieldLabels = {};

/**
 * Fetch a REST path once per editor session.
 *
 * @param {string}  path    Path.
 * @param {boolean} enabled Whether to fetch.
 * @return {Object} { loading, data, error }
 */
export function useRest( path, enabled = true ) {
	const [ state, setState ] = useState(
		restCache[ path ] || { loading: false, data: null, error: null }
	);

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		if ( restCache[ path ] ) {
			setState( restCache[ path ] );
			return;
		}
		let alive = true;
		setState( { loading: true, data: null, error: null } );
		apiFetch( { path } )
			.then( ( data ) => {
				restCache[ path ] = { loading: false, data, error: null };
				( data?.groups || [] ).forEach( ( group ) =>
					( group.fields || [] ).forEach( ( f ) => {
						fieldLabels[ `${ f.arg }:${ f.value }` ] = f.label;
					} )
				);
				if ( alive ) {
					setState( restCache[ path ] );
				}
			} )
			.catch( ( error ) => {
				if ( alive ) {
					setState( { loading: false, data: null, error } );
				}
			} );
		return () => {
			alive = false;
		};
	}, [ path, enabled ] );

	return state;
}

/**
 * Plain text from a REST title (view context gives rendered HTML).
 *
 * @param {Object} record Post record.
 * @return {string} Title.
 */
function recordTitle( record ) {
	const title = record?.title;
	const raw =
		typeof title === 'string'
			? title
			: ( title?.raw ?? title?.rendered ?? '' );
	return decodeEntities( String( raw ).replace( /<[^>]*>/g, '' ) ).trim();
}

/**
 * Search over published posts of one type (same logic as 1.1.0). Also used
 * by the "Preview with" panel (2.1.0).
 *
 * @param {Object}               props
 * @param {string}               props.postType Post type slug.
 * @param {number}               props.postId   Selected ID.
 * @param {(id: number) => void} props.onChange Called with the new ID.
 */
export function PostSearch( { postType, postId, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const [ debounced, setDebounced ] = useState( '' );

	useEffect( () => {
		const timer = setTimeout( () => setDebounced( search ), 300 );
		return () => clearTimeout( timer );
	}, [ search ] );

	const { records, selected, resolving } = useSelect(
		( select ) => {
			const core = select( coreStore );
			// context 'view': contributors may read published posts but not
			// edit them, and edit context would be refused.
			const query = {
				per_page: 20,
				status: 'publish',
				context: 'view',
				_fields: 'id,title',
			};
			if ( debounced ) {
				query.search = debounced;
			}
			return {
				records: core.getEntityRecords( 'postType', postType, query ),
				selected: postId
					? core.getEntityRecord( 'postType', postType, postId, {
							context: 'view',
							_fields: 'id,title',
						} )
					: null,
				resolving: core.isResolving( 'getEntityRecords', [
					'postType',
					postType,
					query,
				] ),
			};
		},
		[ postType, postId, debounced ]
	);

	const options = ( records || [] ).map( ( r ) => ( {
		value: String( r.id ),
		label: sprintf(
			'%1$s (#%2$d)',
			recordTitle( r ) || __( '(no title)', 'lanyard' ),
			r.id
		),
	} ) );
	if ( postId && ! options.some( ( o ) => o.value === String( postId ) ) ) {
		options.unshift( {
			value: String( postId ),
			label: selected
				? sprintf(
						'%1$s (#%2$d)',
						recordTitle( selected ) ||
							__( '(no title)', 'lanyard' ),
						postId
					)
				: sprintf(
						/* translators: %d: post ID. */
						__( 'Post #%d', 'lanyard' ),
						postId
					),
		} );
	}

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ __( 'Post', 'lanyard' ) }
			help={
				resolving
					? __( 'Searching…', 'lanyard' )
					: __( 'Published posts only. Type to search.', 'lanyard' )
			}
			value={ postId ? String( postId ) : null }
			options={ options }
			onFilterValueChange={ setSearch }
			onChange={ ( value ) =>
				onChange( parseInt( value || '0', 10 ) || 0 )
			}
		/>
	);
}

/**
 * The four-step form inside the popover.
 *
 * @param {Object}                props
 * @param {string}                props.kind            'text' or 'image'.
 * @param {string}                props.currentPostType Post type being edited ('' = unknown).
 * @param {boolean}               props.isContainer     Whether the edited post is a container type.
 * @param {string}                props.previewPostType "Preview with" post type ('' = none).
 * @param {(tag: string) => void} props.onInsert        Called with the tag text.
 */
function DynamicDataForm( {
	kind,
	currentPostType,
	isContainer,
	previewPostType,
	onInsert,
} ) {
	const [ source, setSource ] = useState( 'post' );
	const [ postType, setPostType ] = useState( '' );
	const [ postId, setPostId ] = useState( 0 );
	const [ user, setUser ] = useState( 'author' );
	const [ field, setField ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const [ format, setFormat ] = useState( 'site' );
	const [ size, setSize ] = useState( '' );

	const types = useRest( '/lanyard/v1/post-types' );
	const optionsAvailable = types.data ? !! types.data.options : true;
	const postTypes = Array.isArray( types.data?.post_types )
		? types.data.post_types
		: [];

	let fieldsPostType = '';
	if ( source === 'post' ) {
		// In a container (content block, template, ...) the post shown later
		// is unknown; the "Preview with" type orders the list instead.
		fieldsPostType = isContainer ? previewPostType : currentPostType;
	} else if ( source === 'post_ref' ) {
		fieldsPostType = postType;
	}
	const query = { source, kind };
	if ( fieldsPostType ) {
		query.post_type = fieldsPostType;
	}
	const fieldsReady = source !== 'post_ref' || !! postType;
	const fields = useRest(
		addQueryArgs( '/lanyard/v1/fields', query ),
		fieldsReady
	);

	const users = useSelect(
		( select ) =>
			source === 'user'
				? select( coreStore ).getUsers( {
						per_page: 100,
						_fields: 'id,name',
					} )
				: null,
		[ source ]
	);

	const imageSizes = useSelect(
		( select ) =>
			kind === 'image'
				? select( blockEditorStore ).getSettings()?.imageSizes || []
				: [],
		[ kind ]
	);

	const typeLabel = fields.data?.post_type_label || '';
	const groups = buildFieldSections( fields.data?.groups, {
		matches: typeLabel
			? sprintf(
					/* translators: %s: post type label, e.g. "Team member". */
					__( '✓ ACF: matches this %s', 'lanyard' ),
					typeLabel
				)
			: __( '✓ ACF: matches this source', 'lanyard' ),
		other: __( 'ACF: other field groups', 'lanyard' ),
		noMatches: __( 'ACF field groups', 'lanyard' ),
	} );
	const all = groups.flatMap( ( g ) => g.fields || [] );
	const selected = all.find( ( f ) => `${ f.arg }:${ f.value }` === field );

	const changeSource = ( value ) => {
		setSource( value );
		setField( '' );
		setPostType( '' );
		setPostId( 0 );
	};

	const ready =
		!! selected &&
		selected.enabled &&
		( source !== 'post_ref' || ( !! postType && postId > 0 ) );

	const insert = () => {
		if ( ! ready ) {
			return;
		}
		const spec = { source, [ selected.arg ]: selected.value };
		if ( source === 'post_ref' ) {
			spec.type = postType;
			spec.id = postId;
		}
		if ( source === 'user' && user !== 'author' ) {
			spec.user = user;
		}
		if ( kind === 'text' && fallback ) {
			spec.fallback = cleanFallback( fallback );
		}
		if ( selected.kind === 'date' && format !== 'site' ) {
			spec.format = format;
		}
		if ( kind === 'image' && size ) {
			spec.size = size;
		}
		onInsert( buildTag( spec ) );
	};

	const disabledSuffix = __(
		'(turn on “Allow Access to Value in Editor UI”)',
		'lanyard'
	);

	let fieldStep = null;
	if ( ! fieldsReady ) {
		fieldStep = (
			<p className="lnyd-help">
				{ __( 'Choose a post type and a post first.', 'lanyard' ) }
			</p>
		);
	} else if ( fields.loading ) {
		fieldStep = <p>{ __( 'Loading fields…', 'lanyard' ) }</p>;
	} else if ( fields.error ) {
		fieldStep = (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load fields.', 'lanyard' ) }
			</Notice>
		);
	} else if ( fields.data ) {
		fieldStep = (
			<>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( '2. Field', 'lanyard' ) }
					value={ field }
					onChange={ setField }
				>
					<option value="">
						{ __( '— Select a field —', 'lanyard' ) }
					</option>
					{ groups.map( ( group ) => (
						<optgroup key={ group.key } label={ group.title }>
							{ ( group.fields || [] ).map( ( f ) => (
								<option
									key={ `${ f.arg }:${ f.value }` }
									value={ `${ f.arg }:${ f.value }` }
									disabled={ ! f.enabled }
								>
									{ f.enabled
										? f.optionLabel
										: `${ f.optionLabel } ${ disabledSuffix }` }
								</option>
							) ) }
						</optgroup>
					) ) }
				</SelectControl>
				{ source !== 'site' &&
					fields.data.acf &&
					fields.data.acf_groups === 0 && (
						<p className="lnyd-help">
							{ fields.data.acf_total > 0
								? __(
										'No ACF field group’s location rules target this source. Fields of all groups are listed; they show a value only where one is saved.',
										'lanyard'
									)
								: __(
										'No ACF field groups found.',
										'lanyard'
									) }
						</p>
					) }
				{ selected && selected.arg === 'acf' && ! selected.matches && (
					<p className="lnyd-help">
						{ __(
							'This field’s group is not set to appear here in ACF. It still works where a value is saved.',
							'lanyard'
						) }
					</p>
				) }
			</>
		);
	}

	return (
		<div className="lnyd-dd__form">
			{ isContainer && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This card will read fields from the page it is shown on. Use “Preview with” to see real values here.',
						'lanyard'
					) }
				</Notice>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( '1. Source', 'lanyard' ) }
				value={ source }
				onChange={ changeSource }
				options={ [
					{ label: __( 'This post', 'lanyard' ), value: 'post' },
					{
						label: __( 'Another post', 'lanyard' ),
						value: 'post_ref',
					},
					{ label: __( 'Site', 'lanyard' ), value: 'site' },
					{ label: __( 'User', 'lanyard' ), value: 'user' },
					{
						label: optionsAvailable
							? __( 'ACF options page', 'lanyard' )
							: __( 'ACF options page (ACF Pro)', 'lanyard' ),
						value: 'option',
						disabled: ! optionsAvailable,
					},
				] }
			/>

			{ source === 'post_ref' && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Post type', 'lanyard' ) }
						value={ postType }
						onChange={ ( value ) => {
							setPostType( value );
							setPostId( 0 );
							setField( '' );
						} }
						options={ [
							{
								label: __(
									'— Select a post type —',
									'lanyard'
								),
								value: '',
							},
							...postTypes.map( ( t ) => ( {
								label: `${ t.label } (${ t.slug })`,
								value: t.slug,
							} ) ),
						] }
					/>
					{ postType && (
						<PostSearch
							postType={ postType }
							postId={ postId }
							onChange={ setPostId }
						/>
					) }
				</>
			) }

			{ source === 'user' && (
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'User', 'lanyard' ) }
					value={ user }
					options={ [
						{
							label: __( 'Author of the post', 'lanyard' ),
							value: 'author',
						},
						...( users || [] ).map( ( u ) => ( {
							label: sprintf( '%1$s (#%2$d)', u.name, u.id ),
							value: String( u.id ),
						} ) ),
					] }
					onChange={ ( value ) => setUser( value || 'author' ) }
				/>
			) }

			{ fieldStep }

			{ selected && (
				<fieldset className="lnyd-dd__options">
					<legend className="lnyd-field__legend">
						{ __( '3. Options', 'lanyard' ) }
					</legend>
					{ kind === 'text' && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Fallback when empty', 'lanyard' ) }
							value={ fallback }
							maxLength={ MAX_FALLBACK }
							onChange={ setFallback }
						/>
					) }
					{ selected.kind === 'date' && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Date format', 'lanyard' ) }
							value={ format }
							options={ DATE_FORMATS }
							onChange={ setFormat }
						/>
					) }
					{ kind === 'image' && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Image size', 'lanyard' ) }
							value={ size }
							options={ [
								{
									label: __(
										'Default (thumbnail)',
										'lanyard'
									),
									value: '',
								},
								...imageSizes.map( ( s ) => ( {
									label: s.name || s.slug,
									value: s.slug,
								} ) ),
							] }
							onChange={ setSize }
						/>
					) }
				</fieldset>
			) }

			<Button variant="primary" onClick={ insert } disabled={ ! ready }>
				{ __( '4. Insert', 'lanyard' ) }
			</Button>
		</div>
	);
}

/**
 * Icon button that opens the dynamic-data popover.
 *
 * @param {Object}                props
 * @param {string}                props.label           Field label (for the button's name).
 * @param {string}                props.kind            'text' or 'image'.
 * @param {string}                props.currentPostType Post type being edited.
 * @param {boolean}               props.isContainer     Whether the edited post is a container type.
 * @param {string}                props.previewPostType "Preview with" post type.
 * @param {(tag: string) => void} props.onInsert        Called with the tag text.
 */
export function DynamicDataButton( {
	label,
	kind = 'text',
	currentPostType,
	isContainer = false,
	previewPostType = '',
	onInsert,
} ) {
	return (
		<Dropdown
			className="lnyd-dd"
			contentClassName="lnyd-dd__popover"
			popoverProps={ { placement: 'left-start', offset: 12 } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					icon="database"
					size="small"
					label={ sprintf(
						/* translators: %s: field label. */
						__( 'Insert dynamic data into %s', 'lanyard' ),
						label
					) }
					onClick={ onToggle }
					aria-expanded={ isOpen }
					isPressed={ isOpen }
				/>
			) }
			renderContent={ ( { onClose } ) => (
				<DynamicDataForm
					kind={ kind }
					currentPostType={ currentPostType }
					isContainer={ isContainer }
					previewPostType={ previewPostType }
					onInsert={ ( tag ) => {
						onInsert( tag );
						onClose();
					} }
				/>
			) }
		/>
	);
}
