/**
 * Map Business Card Block 2.1.7 (`business/card`) attributes onto this block.
 * Mirrors lnyd_map_legacy_attributes() in includes/legacy.php.
 *
 * @param {Object} a Old attributes.
 * @return {Object} New attributes. Every old design becomes Profile (2.0.0),
 *                  which needs no block style class.
 */
export function mapLegacyAttributes( a = {} ) {
	const bc = a.businessCard || {};
	const qr = a.qr || {};
	const out = {
		name: a.name ?? 'Jonathon Doe',
		jobTitle: a.title ?? 'Chief Executive Officer',
		company: bc.company || '',
		tagline: bc.tagline || '',
		showVcard: !! bc.isDownloadBtn,
		vcardLabel: bc.downloadLabel || '',
		showQr: !! qr.enable,
		qrSource: qr.source === 'url' ? 'url' : 'vcard',
		qrCaption: qr.caption || '',
		schemaEnabled: a.schemaEnabled !== false,
		schemaType: [ 'auto', 'person', 'organization' ].includes(
			a.schemaType
		)
			? a.schemaType
			: 'auto',
		showAvatar: !! a.showAvatar,
		avatarUrl: a.avatar?.url || '',
		avatarId: Number( a.avatar?.id ) || 0,
		customNameColor: a.nameColor || '#4527a4',
		customMetaColor: a.titleColor || '#828282',
		customAccentColor: a.contactIconColors?.bg || '#4527a4',
		customAccentTextColor: a.contactIconColors?.color || '#ffffff',
	};

	const contacts = Array.isArray( a.contacts ) ? a.contacts : [];
	const map = {
		phone: 'phone',
		email: 'email',
		website: 'website',
		address: 'address',
		company: 'company',
	};
	contacts.forEach( ( row ) => {
		const type = String( row?.type || '' ).toLowerCase();
		if ( map[ type ] && ! out[ map[ type ] ] && row.text ) {
			out[ map[ type ] ] = String( row.text );
		}
	} );

	const linkedin = ( Array.isArray( a.socials ) ? a.socials : [] ).find(
		( s ) => /linkedin\.com/i.test( s?.link || '' )
	);
	if ( linkedin ) {
		out.linkedin = linkedin.link;
	}

	// Every old design (default, theme1-3, minimal_qr and the Pro theme4-8)
	// renders as Profile, the only look since 2.0.0. Mirrors legacy.php.
	return out;
}
