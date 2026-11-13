/**
 * Editor UI for the Lanyard card block.
 *
 * - Content: every field is a normal text input. A small dynamic-data icon
 *   button next to it opens a popover (source, field, options, insert) and
 *   inserts an inline tag such as {{lnyd source:post|field:title}} at the
 *   cursor. Link slots (phone, mobile, email, website, LinkedIn) take a single
 *   tag only, so inserting replaces the value. Under a field with tags a
 *   readable summary is shown. Tags are plain text; the server resolves them.
 * - Photo: one optional tag (image fields only); the chosen photo is the
 *   fallback.
 * - Styling: colours and fonts are picked from the theme's presets
 *   (theme.json). Since 2.0.0 the card has one look (Profile) and no block
 *   styles; the card width is a preset (Auto = 30em) output as a
 *   lnyd-width-* class.
 * - Preview: ServerSideRender, so the editor shows the resolved values.
 * - Preview with (2.1.0): only when the edited post is a container type
 *   (a Blocksy Content Block, a synced pattern, a template, ...; list from
 *   lanyard/v1/post-types). "This post" then means the page the card is
 *   shown on, which the editor cannot know, so a published post can be
 *   picked for the preview. It is stored as previewPostType/previewPostId
 *   and read by the server only inside the block-renderer REST request.
 */
import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	PanelColorSettings,
	withColors,
	useSettings,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
	Placeholder,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useRef } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	DynamicDataButton,
	PostSearch,
	fieldLabels,
	useRest,
} from './dynamic-data';
import {
	describeTag,
	findTags,
	isMixed,
	isSingleTag,
	LINK_SLOTS,
} from './tags';

// Card width presets; mirrors Renderer::WIDTHS and style.scss.
const CARD_WIDTHS = [
	{ value: 'auto', label: __( 'Auto (30em)', 'lanyard' ) },
	{ value: 'narrow', label: __( 'Narrow', 'lanyard' ) },
	{ value: 'medium', label: __( 'Medium', 'lanyard' ) },
	{ value: 'wide', label: __( 'Wide', 'lanyard' ) },
	{ value: 'full', label: __( 'Full', 'lanyard' ) },
];

const TEXT_SLOTS = [
	{ slot: 'name', label: __( 'Name', 'lanyard' ) },
	{ slot: 'jobTitle', label: __( 'Job title', 'lanyard' ) },
	{ slot: 'company', label: __( 'Company', 'lanyard' ) },
	{ slot: 'tagline', label: __( 'Tagline', 'lanyard' ) },
	{ slot: 'phone', label: __( 'Phone', 'lanyard' ) },
	{ slot: 'mobile', label: __( 'Mobile', 'lanyard' ) },
	{ slot: 'email', label: __( 'Email', 'lanyard' ) },
	{ slot: 'website', label: __( 'Website', 'lanyard' ) },
	{ slot: 'linkedin', label: __( 'LinkedIn URL', 'lanyard' ) },
	{ slot: 'address', label: __( 'Address', 'lanyard' ), multiline: true },
];

/**
 * Post types the editor itself uses; for these we do not know which post
 * will be rendered, so "This post" lists all post-targeting ACF groups.
 *
 * @param {string|null|undefined} type Post type slug.
 * @return {string} The slug, or '' when unknown.
 */
function contentPostType( type ) {
	if ( ! type || typeof type !== 'string' || type.startsWith( 'wp_' ) ) {
		return '';
	}
	return type;
}

/**
 * Flatten a theme.json preset setting that may be returned per origin.
 *
 * @param {Array|Object|undefined} presets Presets.
 * @return {Array} Flat list.
 */
function flattenPresets( presets ) {
	if ( Array.isArray( presets ) ) {
		return presets;
	}
	if ( presets && typeof presets === 'object' ) {
		return [
			...( presets.custom || [] ),
			...( presets.theme || [] ),
			...( presets.default || [] ),
		];
	}
	return [];
}

/**
 * Readable list of the tags in a value.
 *
 * @param {Object}  props
 * @param {string}  props.value  Field value.
 * @param {boolean} props.isLink Whether the slot becomes a link.
 */
function TagSummary( { value, isLink } ) {
	const tags = findTags( value );
	if ( ! tags.length ) {
		return null;
	}
	return (
		<div className="lnyd-tags">
			<ul className="lnyd-tags__list">
				{ tags.map( ( tag, i ) => (
					<li
						key={ i }
						className={
							tag.args ? 'lnyd-tag' : 'lnyd-tag is-invalid'
						}
					>
						{ describeTag( tag.args, fieldLabels ) }
					</li>
				) ) }
			</ul>
			{ tags.length > 20 && (
				<p className="lnyd-help">
					{ __(
						'Only the first 20 tags are used; the rest are removed.',
						'lanyard'
					) }
				</p>
			) }
			{ isLink && isMixed( value ) && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This field mixes text and tags, so it is shown without a link. Use a single tag to keep the link.',
						'lanyard'
					) }
				</Notice>
			) }
		</div>
	);
}

/**
 * One content field: a normal input plus the dynamic-data button.
 *
 * @param {Object}                  props
 * @param {string}                  props.slot            Attribute name.
 * @param {string}                  props.label           Label.
 * @param {boolean}                 props.multiline       Textarea instead of input.
 * @param {string}                  props.value           Value.
 * @param {(value: string) => void} props.onChange        Setter.
 * @param {string}                  props.currentPostType Post type being edited.
 * @param {boolean}                 props.isContainer     Whether the edited post is a container type.
 * @param {string}                  props.previewPostType "Preview with" post type.
 */
function ContentField( {
	slot,
	label,
	multiline,
	value,
	onChange,
	currentPostType,
	isContainer,
	previewPostType,
} ) {
	const Control = multiline ? TextareaControl : TextControl;
	// TextareaControl has no 40px size variant; do not pass the flag to it.
	const sizeProps = multiline ? {} : { __next40pxDefaultSize: true };
	const isLink = LINK_SLOTS.includes( slot );
	// Last known caret position; read from the input's selection events.
	const caret = useRef( null );

	const remember = ( event ) => {
		const el = event?.target;
		if ( el && typeof el.selectionStart === 'number' ) {
			caret.current = [ el.selectionStart, el.selectionEnd ];
		}
	};

	const insert = ( tag ) => {
		if ( isLink ) {
			// Link slots: the href must come from exactly one value.
			onChange( tag );
			return;
		}
		const text = value || '';
		const [ start, end ] = caret.current || [ text.length, text.length ];
		const before = text.slice( 0, start );
		const after = text.slice( end );
		onChange( before + tag + after );
		caret.current = [
			before.length + tag.length,
			before.length + tag.length,
		];
	};

	return (
		<div className="lnyd-field">
			<div className="lnyd-field__head">
				<span className="lnyd-field__legend" aria-hidden="true">
					{ label }
				</span>
				<DynamicDataButton
					label={ label }
					currentPostType={ currentPostType }
					isContainer={ isContainer }
					previewPostType={ previewPostType }
					onInsert={ insert }
				/>
			</div>
			<Control
				__nextHasNoMarginBottom
				{ ...sizeProps }
				label={ label }
				hideLabelFromVision
				value={ value || '' }
				onChange={ onChange }
				onSelect={ remember }
				onKeyUp={ remember }
				onClick={ remember }
				onBlur={ remember }
				help={
					isLink && isSingleTag( value )
						? __( 'Dynamic link (single tag).', 'lanyard' )
						: undefined
				}
			/>
			<TagSummary value={ value } isLink={ isLink } />
		</div>
	);
}

function Edit( props ) {
	const {
		attributes,
		setAttributes,
		context,
		accentColor,
		setAccentColor,
		accentTextColor,
		setAccentTextColor,
		nameColor,
		setNameColor,
		metaColor,
		setMetaColor,
	} = props;
	const { avatarTag = '' } = attributes;

	// Post type of the post being edited: block context first (site editor,
	// Query Loop), then the post editor. Unknown (templates) = ''.
	const editorPostType = useSelect( ( select ) => {
		// String store name: importing @wordpress/editor would make it a
		// script dependency, which the widgets editor does not want.
		const editor = select( 'core/editor' );
		return editor && typeof editor.getCurrentPostType === 'function'
			? editor.getCurrentPostType()
			: null;
	}, [] );
	const rawPostType = context?.postType || editorPostType || '';
	const currentPostType = contentPostType( rawPostType );

	// 2.1.0: container types come from the server (lnyd_container_post_types).
	const types = useRest( '/lanyard/v1/post-types' );
	const containers = Array.isArray( types.data?.containers )
		? types.data.containers
		: [];
	const isContainer = !! rawPostType && containers.includes( rawPostType );
	const previewTypes = (
		Array.isArray( types.data?.post_types ) ? types.data.post_types : []
	).filter( ( t ) => ! containers.includes( t.slug ) );
	const { previewPostType = '', previewPostId = 0 } = attributes;

	const [ fontFamiliesSetting, fontSizesSetting ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	);
	const fontFamilies = flattenPresets( fontFamiliesSetting );
	const fontSizes = flattenPresets( fontSizesSetting );

	const cardWidth = CARD_WIDTHS.some(
		( w ) => w.value === attributes.cardWidth
	)
		? attributes.cardWidth
		: 'auto';
	const blockProps = useBlockProps( {
		className:
			cardWidth !== 'auto' ? `lnyd-width-${ cardWidth }` : undefined,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'lanyard' ) } initialOpen>
					<p className="lnyd-help">
						{ __(
							'Type a value, or use the database icon to insert dynamic data.',
							'lanyard'
						) }
					</p>
					{ TEXT_SLOTS.map( ( { slot, label, multiline } ) => (
						<ContentField
							key={ slot }
							slot={ slot }
							label={ label }
							multiline={ multiline }
							value={ attributes[ slot ] }
							onChange={ ( value ) =>
								setAttributes( { [ slot ]: value } )
							}
							currentPostType={ currentPostType }
							isContainer={ isContainer }
							previewPostType={ previewPostType }
						/>
					) ) }
				</PanelBody>

				{ isContainer && (
					<PanelBody
						title={ __( 'Preview with', 'lanyard' ) }
						initialOpen
					>
						<p className="lnyd-help">
							{ __(
								'Editor only. On the site, “This post” is the page the card is shown on. Pick a published post to see its values in this preview.',
								'lanyard'
							) }
						</p>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Post type', 'lanyard' ) }
							value={ previewPostType }
							onChange={ ( value ) =>
								setAttributes( {
									previewPostType: value,
									previewPostId: 0,
								} )
							}
							options={ [
								{
									label: __( '— None —', 'lanyard' ),
									value: '',
								},
								...previewTypes.map( ( t ) => ( {
									label: `${ t.label } (${ t.slug })`,
									value: t.slug,
								} ) ),
							] }
						/>
						{ previewPostType && (
							<PostSearch
								postType={ previewPostType }
								postId={ previewPostId }
								onChange={ ( id ) =>
									setAttributes( { previewPostId: id } )
								}
							/>
						) }
					</PanelBody>
				) }

				<PanelBody
					title={ __( 'Photo', 'lanyard' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show photo', 'lanyard' ) }
						checked={ !! attributes.showAvatar }
						onChange={ ( value ) =>
							setAttributes( { showAvatar: value } )
						}
					/>
					{ ! attributes.showAvatar &&
						( avatarTag || attributes.avatarId ) && (
							<p className="lnyd-help">
								{ __(
									'A photo is set but hidden. Turn on “Show photo” to display it.',
									'lanyard'
								) }
							</p>
						) }
					<div className="lnyd-field">
						<div className="lnyd-field__head">
							<span className="lnyd-field__legend">
								{ __( 'Dynamic photo', 'lanyard' ) }
							</span>
							<DynamicDataButton
								label={ __( 'Photo', 'lanyard' ) }
								kind="image"
								currentPostType={ currentPostType }
								isContainer={ isContainer }
								previewPostType={ previewPostType }
								onInsert={ ( tag ) =>
									// 2.1.2: choosing a photo also shows it.
									setAttributes( {
										avatarTag: tag,
										showAvatar: true,
									} )
								}
							/>
						</div>
						{ avatarTag ? (
							<>
								<TagSummary value={ avatarTag } />
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										setAttributes( { avatarTag: '' } )
									}
								>
									{ __( 'Remove dynamic photo', 'lanyard' ) }
								</Button>
							</>
						) : (
							<p className="lnyd-help">
								{ __(
									'None. The photo below is used.',
									'lanyard'
								) }
							</p>
						) }
					</div>
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ attributes.avatarId }
							onSelect={ ( media ) =>
								setAttributes( {
									avatarId: media?.id || 0,
									avatarUrl: '',
									avatarAlt: media?.alt || '',
									showAvatar: true,
								} )
							}
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ attributes.avatarId
										? __( 'Replace photo', 'lanyard' )
										: __( 'Choose photo', 'lanyard' ) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					{ avatarTag && (
						<p className="lnyd-help">
							{ __(
								'The chosen photo is the fallback when the dynamic photo is empty.',
								'lanyard'
							) }
						</p>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Alternative text (empty = name)',
							'lanyard'
						) }
						value={ attributes.avatarAlt || '' }
						onChange={ ( value ) =>
							setAttributes( { avatarAlt: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Features', 'lanyard' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Name element', 'lanyard' ) }
						value={ attributes.nameTag }
						options={ [ 'p', 'h2', 'h3', 'h4' ].map( ( v ) => ( {
							label: v.toUpperCase(),
							value: v,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { nameTag: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Link address to OpenStreetMap',
							'lanyard'
						) }
						checked={ !! attributes.linkAddress }
						onChange={ ( value ) =>
							setAttributes( { linkAddress: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'“Save contact” button (vCard)',
							'lanyard'
						) }
						checked={ !! attributes.showVcard }
						onChange={ ( value ) =>
							setAttributes( { showVcard: value } )
						}
					/>
					{ attributes.showVcard && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Button label', 'lanyard' ) }
							value={ attributes.vcardLabel || '' }
							onChange={ ( value ) =>
								setAttributes( { vcardLabel: value } )
							}
						/>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'QR code', 'lanyard' ) }
						checked={ !! attributes.showQr }
						onChange={ ( value ) =>
							setAttributes( { showQr: value } )
						}
					/>
					{ attributes.showQr && (
						<>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'QR contains', 'lanyard' ) }
								value={ attributes.qrSource }
								options={ [
									{
										label: __(
											'Contact details (vCard)',
											'lanyard'
										),
										value: 'vcard',
									},
									{
										label: __(
											'Link to this page',
											'lanyard'
										),
										value: 'url',
									},
								] }
								onChange={ ( value ) =>
									setAttributes( { qrSource: value } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'QR caption', 'lanyard' ) }
								value={ attributes.qrCaption || '' }
								onChange={ ( value ) =>
									setAttributes( { qrCaption: value } )
								}
							/>
						</>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Structured data (JSON-LD)', 'lanyard' ) }
						checked={ !! attributes.schemaEnabled }
						onChange={ ( value ) =>
							setAttributes( { schemaEnabled: value } )
						}
					/>
					{ attributes.schemaEnabled && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Schema type', 'lanyard' ) }
							value={ attributes.schemaType }
							options={ [
								{
									label: __( 'Automatic', 'lanyard' ),
									value: 'auto',
								},
								{
									label: __( 'Person', 'lanyard' ),
									value: 'person',
								},
								{
									label: __( 'Organization', 'lanyard' ),
									value: 'organization',
								},
							] }
							onChange={ ( value ) =>
								setAttributes( { schemaType: value } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<InspectorControls group="styles">
				<PanelColorSettings
					title={ __( 'Card colours', 'lanyard' ) }
					initialOpen={ false }
					colorSettings={ [
						{
							value: accentColor?.color,
							onChange: setAccentColor,
							label: __(
								'Accent (icons, button, job title)',
								'lanyard'
							),
						},
						{
							value: accentTextColor?.color,
							onChange: setAccentTextColor,
							label: __(
								'Text on accent (button label)',
								'lanyard'
							),
						},
						{
							value: nameColor?.color,
							onChange: setNameColor,
							label: __( 'Name', 'lanyard' ),
						},
						{
							value: metaColor?.color,
							onChange: setMetaColor,
							label: __(
								'Job title, company, tagline',
								'lanyard'
							),
						},
					] }
				/>
				<PanelBody
					title={ __( 'Card width', 'lanyard' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Maximum width', 'lanyard' ) }
						help={ __(
							'Auto is 30em (480px at a 16px font). The card never grows past its container or the theme content width; Full fills them.',
							'lanyard'
						) }
						value={ cardWidth }
						options={ CARD_WIDTHS }
						onChange={ ( value ) =>
							setAttributes( { cardWidth: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Name typography', 'lanyard' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Font family (theme)', 'lanyard' ) }
						value={ attributes.nameFontFamily || '' }
						options={ [
							{ label: __( 'Inherit', 'lanyard' ), value: '' },
							...fontFamilies.map( ( f ) => ( {
								label: f.name || f.slug,
								value: f.slug,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { nameFontFamily: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Font size (theme)', 'lanyard' ) }
						value={ attributes.nameFontSize || '' }
						options={ [
							{ label: __( 'Default', 'lanyard' ), value: '' },
							...fontSizes.map( ( f ) => ( {
								label: f.name || f.slug,
								value: f.slug,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { nameFontSize: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="lanyard/card"
					attributes={ attributes }
					skipBlockSupportAttributes
					urlQueryArgs={
						context?.postId ? { post_id: context.postId } : {}
					}
					EmptyResponsePlaceholder={ () => (
						<Placeholder
							icon="id-alt"
							label={ __( 'Lanyard', 'lanyard' ) }
							instructions={ __(
								'Fill in a name, company or contact detail in the block settings, or insert dynamic data.',
								'lanyard'
							) }
						/>
					) }
				/>
			</div>
		</>
	);
}

export default withColors(
	'accentColor',
	'accentTextColor',
	'nameColor',
	'metaColor'
)( Edit );
