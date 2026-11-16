=== Lanyard ===
Contributors: jordidriesen
Tags: business card, vcard, acf, block, staff
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A digital business card block for people and teams. Fill any field with dynamic data (this post, another post, the site, a user or ACF); the card takes on your theme's colours and fonts.

== Credits ==

This plugin is a modified version of "Business Card Block" 2.1.7 by bPlugins
(https://wordpress.org/plugins/business-card-block/), licensed GPLv3 or later.
The QR encoder (includes/class-qr.php) is copied from that plugin unchanged in
logic; the vCard builder (includes/class-vcard.php) is adapted from it. The
block, editor UI, ACF integration and renderer are new.

The card's look, Profile (1.3.0; the only look since 2.0.0), is new and
not based on the original. Versions 1.2.0 to 1.4.1 also had four layouts
(Stacked, Split, Two-tone panel, Centered with QR) that descended from the
original's free designs by bPlugins; 2.0.0 removed them.

The card uses outline icons from
Tabler Icons (https://github.com/tabler/tabler-icons): mail, phone, device-mobile, world,
brand-linkedin, map-pin, qrcode, plus and x. Their path data is inlined in
includes/class-icons.php (nothing is loaded remotely). Tabler Icons are
licensed under the MIT License:

  MIT License

  Copyright (c) 2020-2026 Paweł Kuna

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.

It is a separate plugin (slug lanyard, prefix lnyd, block lanyard/card,
Update URI: false) and is never updated over by the original.

== Description ==

* Dynamic block (render.php): no front-end JavaScript, no remote fonts or assets.
  The QR overlay uses the HTML Popover API, which needs no script.
* Each content field (name, job title, company, tagline, phone, mobile, email,
  website, LinkedIn, address) is a normal text field. A small database icon
  next to it inserts an inline tag such as {{lnyd source:post|field:title}};
  static text and tags can be mixed ("Call {{lnyd ...}} or {{lnyd ...}}").
  The photo takes one tag (image fields only); the chosen photo is the fallback.
* Sources: this post, another post (a published post of a chosen type), the
  site, a user (the post author or a chosen user) and the ACF options page
  (ACF Pro). Fields: a fixed list of core WordPress fields, plus ACF fields.
* Link fields (phone, mobile, email, website, LinkedIn) take a single tag, so
  the link always comes from one value. Mixed text is shown without a link.
* ACF is optional. Without ACF the core WordPress fields still work.
* Colours and fonts come from theme.json presets and are stored as slugs, so
  the card follows theme changes. Block supports cover background, text and
  link colour, typography, spacing, border and shadow.
* One look, Profile (since 2.0.0 the only one; no block styles): a digital
  business card on the page background (no box, no border), with a large
  photo that fades into the background, a QR icon button that opens the QR
  code in an overlay, an "Add to contacts" button and contact rows with
  outline icons. Browsers without the Popover API show the QR code below the
  contacts instead. One block pattern, "Lanyard: Profile", in the "Lanyard"
  category.
* Card width: Auto (30em), Narrow (22.5em), Medium (35em), Wide (45em) or
  Full. The card fills its container up to that width and
  never grows past the theme's content width; Wide and Full alignment work.
* Theming without code changes: the card reads theme presets and --lnyd-*
  custom properties (accent, text, surface of the QR overlay, button,
  focus). A site can restyle every card from theme.json or Additional CSS.
  Nothing is hard-coded light except the QR code's white plate, so a theme
  that switches its preset colours for dark mode switches the card too.
* Colours chosen in the block settings always win: the four card colours
  (accent, text on accent, name, job title/company/tagline) and the core
  text, background and link colours beat theme, skin and plugin defaults in
  light and dark mode. The job title colour setting also overrides the
  accent-coloured job title.
* "Add to contacts" vCard (inline data: link, no endpoint), QR code (server-side
  SVG), schema.org JSON-LD.

== Inline tags ==

Syntax: {{lnyd key:value|key:value|...}}. Keys:

* source: post, post_ref, site, user or option (required).
* field: a core field slug, or acf: an ACF field key or name (exactly one).
  The picker always stores the field key (field_...). A typed name only
  resolves when exactly one top-level ACF field uses it.
* type and id: post type and post ID (post_ref only, both required).
* user: author (default) or a user ID (user only).
* fallback: plain text shown when the value is empty (max 120 characters).
* format: site, iso, dmy, mdy or long (date fields only).
* size: a registered image size or full (photo only).

Values cannot contain "|", "{", "}" or line breaks. Tags are resolved on the
server only, once (resolved values are never parsed again), at most 20 per
field, and the result is escaped for where it ends up. Anything that is not a
valid tag is removed or shown as plain text.

Core fields: post and post_ref: title, excerpt, permalink, date, modified,
post_type_label, featured_image (photo only). site: name, tagline, home_url,
custom_logo (photo only). user: display_name, first_name, last_name, nickname,
bio, user_url, avatar (photo only) and user_email.

* user_email is only resolved when the user is the author of the post being
  rendered, or when the filter lnyd_allow_user_email returns true. In the
  editor preview of a "Preview with" post, it is only shown to someone who
  may edit that post, or to the author themselves.
* A chosen user (not the post author) is only used when that user has
  published posts (filter lnyd_user_is_public), for core fields and, since
  2.2.1, for ACF user fields too.
* The avatar comes from get_avatar_url(). Gravatar and other external URLs are
  dropped unless their host is added with lnyd_allowed_image_hosts.

== ACF fields: what an editor can read ==

A block can only read an ACF field when all of these are true:

1. It is a registered, top-level ACF field of a supported type (text,
   textarea, email, url, number, select, radio, button group, link; image
   for the photo). Sub-fields of repeater, group and flexible content fields
   are not supported.
2. The field has "Allow Access to Value in Editor UI" switched on (ACF 6.3.6+,
   field settings, Presentation tab). Same rule as ACF itself: a field is
   refused only when the setting is saved as off. Older fields that never
   saved the setting show it as on in ACF and are allowed.
3. The source is the post being rendered or viewed, one referenced post, the
   options page, or a user.
4. For "another post" (ACF and core fields): the post exists, is published,
   has no password, its post type equals the tag's type, and that type is
   public or publicly queryable, shown in REST and not attachment.

Since 2.1.0 a field group's location rules never decide whether a field can
be used: location rules control where ACF shows the edit screen, not who may
read a value. The per-field "Allow Access to Value in Editor UI" setting is
the gate. Every field group is listed in the picker; groups whose location
rules match the source come first ("matches this <post type>"), all other
groups follow under "Other field groups".

Fields of a supported type without the setting are listed in the picker as
disabled, with a hint; they never resolve.

Location rules used for that ordering: post_type (== and !=), page, page_type,
page_parent and page_template (imply "page"), post (a post ID: that post's
type), post_format (types with post-formats support), post_category and
post_taxonomy (types the taxonomy is registered for). post_template,
post_status, current_user, current_user_role and unknown third-party rules
never exclude a type.

Anyone who can edit posts can place a card that shows an allowed field of any
user or of any published post of an eligible type. Only switch the setting on
for fields that may be published.

Filters: lnyd_acf_field_types, lnyd_acf_field_allowed, lnyd_acf_options_id,
lnyd_acf_group_matches_post_type (ordering and labels of the picker only,
since 2.1.0), lnyd_card_data, lnyd_allowed_image_hosts,
lnyd_enable_legacy_renderer, lnyd_allow_user_email, lnyd_user_is_public,
lnyd_container_post_types and lnyd_current_post_id (2.1.0).

== This post, Blocksy Content Blocks and other containers ==

"This post" is the post being rendered or viewed, never an ID from a tag:

1. the block context postId (for example the item of a Query Loop), unless
   it is a container type;
2. otherwise get_the_ID(), unless it is a container type;
3. otherwise, on a single post or page, the post being viewed;
4. otherwise nothing (tags show their fallbacks).

Container types (filter lnyd_container_post_types): ct_content_block
(Blocksy Content Blocks), wp_block, wp_template, wp_template_part,
wp_navigation and elementor_library. So a card in a Blocksy Content Block
shown on single "Team member" pages reads the team member being viewed. On
the front end a password-protected post that the visitor has not unlocked
gives nothing. The final result can be changed with lnyd_current_post_id.

When you edit a container, the block settings show a "Preview with" panel:
pick a post type and a published post to see its values in the editor
preview. It is editor-only: the server uses it only inside the block
renderer REST request, with the same checks as "another post".

Block Bindings: on WordPress 6.9+ the text attributes and avatarUrl are
registered as bindable (block_bindings_supported_attributes_lanyard/card),
so the core `acf/field` (ACF 6.3+) or `core/post-meta` sources can also fill
them. A bound attribute is never parsed for tags.

== Performance: what loads where ==

* Front end: no JavaScript. One stylesheet (build/card/style-index.css),
  loaded only on pages that show a card. In classic themes that load every
  block stylesheet on every page, Lanyard removes it again unless the post
  content holds a card; a card rendered elsewhere (a Blocksy Content Block,
  a widget, a hook) prints the stylesheet once, right before the card. Filter
  lnyd_style_in_head forces it into the head (true) or leaves it to render
  time (false).
* PHP: the card classes (renderer, data sources, QR encoder, vCard) load on
  first use, not on every request.
* Editor only: build/card/index.js and index.css (the block settings and
  dynamic-data picker), built with @wordpress/scripts (webpack); WordPress
  packages are not bundled but taken from core.

== Multilingual sites (WPML) ==

* Card text: wpml-config.xml in the plugin folder tells WPML which card
  fields to send to translation: name, job title, company, tagline, address,
  website, LinkedIn, the Add to contacts label, the QR caption and the photo
  alt text. Phone, mobile and email are left out on purpose. Leave any
  {{lnyd ...}} tags inside a field unchanged when translating.
* Tags for this post (and cards in Blocksy Content Blocks) read the post
  that is shown, so they follow the translated post. Translate ACF fields
  with ACF Multilingual as usual.
* Tags for "another post" show that post's translation in the current
  language (wpml_object_id), or the original when there is none.
* Built-in labels such as "Add to contacts" and "Connect on LinkedIn" ship
  translated in Dutch (nl_NL, nl_BE), French (fr_FR, fr_BE), German (de_DE)
  and Spanish (es_ES). A translation entered in WPML String Translation
  (text domain lanyard) takes priority over the shipped one.
* The "Add to contacts" button label and the QR caption on a card are card
  text, not built-in labels: leave them empty to use the translated default
  "Add to contacts" (and no caption), or translate them per page as above.

== Migrating from Business Card Block ==

Blocks cannot be migrated with `deprecated` because the block name changes.

* While the original plugin is active: select an old card and use
  "Transform to > Lanyard".
* After the original plugin is deactivated: old `business/card` blocks keep
  rendering on the front end through a compatibility renderer that maps the old
  attributes (old hex colours become custom colours). In the editor they show
  as an unsupported block until replaced.
* Every old design (default, theme1, theme2, theme3, minimal_qr and the Pro
  designs theme4 to theme8) renders as Profile. Per-element typography, shadows, animations,
  custom icons, extra contact types (WhatsApp, Telegram, Skype, IMO, WeChat,
  "others") and social icons other than LinkedIn are not carried over.

== Entry points ==

* Block lanyard/card (render.php). Attributes are untrusted
  (contributors can write them): validated and escaped in includes/.
* REST GET /wp-json/lanyard/v1/fields: edit_posts; source/kind enums,
  post_type must be a registered post type; returns field definitions only
  (core allow-list and every ACF field group, matching groups first), never
  values.
* REST GET /wp-json/lanyard/v1/post-types: edit_posts; returns eligible
  post type slugs, labels and REST bases, and the container post types.
* "Preview with" attributes (previewPostType, previewPostId): read only in
  the block renderer REST request, validated like "another post".
* Post search for "another post" uses core REST (/wp/v2/<rest_base>,
  status=publish) with core's own permission checks.
* Core block renderer (/wp/v2/block-renderer) used by the editor preview:
  core permission check (edit_posts / edit_post).
* Compatibility renderer for business/card (front end only).
* One block pattern (static content), "Lanyard: Profile", in the "Lanyard"
  category.

== Privacy ==

The plugin stores no options, meta, tables, transients or cron events. Card
content is block markup in post content; ACF values stay in ACF. QR images are
cached in the object cache only. Personal data export and erasure are therefore
covered by WordPress core (posts) and ACF/user meta, not by this plugin. Staff
contact details shown on a card are published deliberately; get the staff
member's agreement and remove the card when they leave. The address map link
(OpenStreetMap) is off by default and is a plain link: nothing is loaded from
third parties.

== Development ==

npm install
npm run build

Translations: `npm run i18n` regenerates languages/lanyard.pot from the PHP
sources and compiles every languages/lanyard-<locale>.po to .mo and
.l10n.php (no WP-CLI or gettext needed). Edit the .po files, then run it
again. Editor (JavaScript) strings are not covered yet.

== Upgrade Notice ==

= 2.3.0 =
The built-in labels ("Add to contacts", "Connect on LinkedIn", contact row
names and more) now ship in Dutch, French, German and Spanish. No change
needed to existing cards.

= 2.2.2 =
WPML support: card text can be translated in the WPML translation editor,
and "another post" tags show the post in the current language. Existing
pages need to be sent to translation again to pick up the card fields.

= 2.2.1 =
Security hardening: the editor preview can no longer reveal another author's
email address, and ACF user fields follow the same "public user" rule as core
profile fields. Update recommended.

= 2.2.0 =
The card stylesheet now loads only on pages that show a card, also in
classic themes such as Blocksy. No change needed to existing cards.

= 2.1.3 =
The LinkedIn row now reads "Connect on LinkedIn".

= 2.1.2 =
Choosing a photo (dynamic or from the media library) now also turns on
"Show photo". Existing cards: turn on "Show photo" in the Photo panel.

= 2.1.1 =
Fixes ACF fields showing "turn on Allow Access to Value in Editor UI" although
the setting is on in ACF.

= 2.1.0 =
Any ACF field with "Allow Access to Value in Editor UI" can now be used,
whatever its field group's location rules, and cards in Blocksy Content
Blocks read the page they are shown on. No change needed to existing cards.

= 2.0.0 =
Breaking: the card has one look, Profile. Cards in Stacked, Split, Two-tone
panel or Centered with QR now render as Profile automatically; nothing has to
be re-saved.

== Changelog ==

= 2.3.0 =
* Changed: the default button label (used when Button label is empty) is
  now "Add to contacts" instead of "Save contact". The Button label field
  shows the default as its placeholder.
* New: Dutch (nl_NL, nl_BE), French (fr_FR, fr_BE), German (de_DE) and
  Spanish (es_ES) translations of the front-end labels, block pattern,
  REST field labels and privacy text, as .mo and .l10n.php files.
* New: tools/i18n.js (npm run i18n) builds the POT and compiles the .po
  files without WP-CLI.

= 2.2.2 =
* New: wpml-config.xml, so WPML sends the card's text fields (name, job
  title, company, tagline, address, website, LinkedIn, Add to contacts
  label, QR caption, photo alt text) to translation.
* New: "another post" tags (post_ref) resolve to the post's translation in
  the current language through the wpml_object_id filter, falling back to
  the original. The published and password checks run on the post shown.

= 2.2.1 =
* Security: in the editor preview with a "Preview with" post, user_email of
  that post's author was shown to any user who can edit posts. It is now only
  shown to users who may edit that post, or to the author themselves.
* Security: ACF user fields (source user) for a chosen user ID now require
  that user to be the post author or to have published content (filter
  lnyd_user_is_public), like core profile fields. Before, any account's
  opted-in ACF user fields could be read.

= 2.2.0 =
* Performance: in classic themes the card stylesheet is no longer printed on
  every page; it loads in the head when the post content holds a card, and
  otherwise once, right before a card rendered outside the content (Blocksy
  Content Blocks, widgets, hooks). New filter lnyd_style_in_head.
* Performance: the card classes load on first use (autoloader) instead of on
  every request.

= 2.1.3 =
* Change: the LinkedIn row's link text is "Connect on LinkedIn" (was
  "LinkedIn").

= 2.1.2 =
* Fix: choosing a dynamic photo (for example the featured image) or a photo
  from the media library left "Show photo" off, so no photo appeared. Choosing
  one now turns it on, and the Photo panel says when a photo is set but hidden.

= 2.1.1 =
* Fix: ACF fields created before ACF 6.3.6 were refused with "turn on Allow
  Access to Value in Editor UI" although ACF shows the setting as on. Lanyard
  now uses ACF's own rule: a field is refused only when the setting is saved
  as off.

= 2.1.0 =
* Any ACF field can be mapped, regardless of its field group's location
  rules. The picker lists every field group: groups that match the source
  first ("✓ ACF: matches this <post type>"), then "ACF: other field
  groups"; each option reads "Label (Group)". The per-field "Allow Access to
  Value in Editor UI" setting and the field type allow-list still decide
  what resolves.
* "Another post" no longer requires the field's group to match the
  referenced post type; the post checks (exists, type, eligible, published,
  no password) are unchanged.
* ACF tags resolve top-level fields only; a hand-typed field name resolves
  only when exactly one top-level field uses it (the picker stores keys).
* "This post" inside Blocksy Content Blocks, synced patterns, templates and
  other container types reads the post being viewed (new filters
  lnyd_container_post_types and lnyd_current_post_id). Query Loop items
  still come from the block context.
* Hardening: on the front end a password-protected post that the visitor has
  not unlocked is not read as "this post".
* New editor-only "Preview with" panel when editing a container, with the
  attributes previewPostType and previewPostId; used only in the editor
  preview. The dynamic-data popover explains that the card reads the page it
  is shown on.
* lnyd_acf_group_matches_post_type now only orders and labels the picker.

= 2.0.0 =
* Breaking change: Profile is the only look. The layouts Stacked, Split,
  Two-tone panel and Centered with QR and all block styles were removed; the
  Profile markup and styles are now the block's default (no is-style-*
  class needed; the wrapper keeps the lnyd--profile class as a hook).
* Automatic migration, nothing to re-save: on the front end every card
  renders as Profile, whatever is-style-* class it stored (default, split,
  two-tone, centered, profile, and the older mirrored, minimal, sidebar and
  banner). In the editor a new block deprecation (v141) removes those
  classes when a post is opened; v120 and v110 now do the same for 1.2.0
  and 1.1.0 cards, and the three never match the same block, so no
  migration overwrites another. The business/card compatibility renderer
  and the "Transform to" map every old design to Profile.
* Removed the showSeparator attribute and its "Separator line" setting
  (Profile has no separator); old values are ignored and dropped on the
  next save.
* Patterns: only "Lanyard: Profile" is left.
* No border anywhere: the card, the photo and the QR button never had one
  in Profile; the inline QR code (browsers without the Popover API) lost
  its hairline.
* Removed custom properties of the old layouts: --lnyd-border-color,
  --lnyd-padding, --lnyd-shadow, --lnyd-max-wide, --lnyd-avatar-radius,
  --lnyd-icon-bg, --lnyd-icon-radius, --lnyd-panel, --lnyd-panel-contrast,
  --lnyd-panel-meta, --lnyd-aside.
* Kept: Card width (Auto 30em, Narrow, Medium, Wide, Full), the four card
  colours, every content, photo, QR, vCard and schema setting, and the
  block supports.


= 1.4.1 =
* Fonts: the card always uses the theme's fonts. The QR and close buttons
  of Profile and the contact links now inherit font and letter-spacing
  (browser button styles and theme rules on `a`, such as "html, button, a"
  in classic themes, no longer override the card font); the "Save contact"
  button and the name inherit the card's letter-spacing instead of resetting
  it to normal. The name keeps the card font also when it is an h2 to h4.
  The block's typography settings and the Name typography presets still
  override all of this. No font family is named anywhere in the plugin.

= 1.4.0 =
* Tabler outline icons in all five layouts. The filled contact glyphs were
  removed. Icons that render below 24px use a 1.75 stroke, larger ones
  Tabler's 2; Stacked, Split and Centered keep the tinted icon tile.
* Explicit colours win: a card colour chosen in the block settings now also
  resets the derived button, icon and job title properties a theme or skin
  may set, so they follow the chosen colour. The "Job title, company,
  tagline" colour overrides the accent-coloured Profile job title. With a
  core text colour, the meta text derives from it instead of a skin default.
  A core link colour now reaches the contact links.
* Two-tone panel: the core text and background colours apply to the light
  aside; the panel keeps its own panel and panel text colours. The company
  name in the aside uses the meta colour, and a chosen meta colour also
  replaces the panel's secondary text colour.
* Profile: the default end of the button gradient is now slightly darker
  than the accent (was lighter), so white text never loses contrast across
  the button.
* Editor preview: a text colour chosen in the block settings now shows in
  the preview.
* Dark mode: when a theme has no primary or accent preset, the accent now
  falls back to the contrast preset (with base text on it) instead of a fixed
  blue, so a theme that swaps base and contrast for dark mode keeps a
  readable button and visible icons.
* Layout defaults set their custom properties at zero specificity (:where).
* New properties for themes and skins: --lnyd-button-size (button label
  size, default 1em) and --lnyd-title-weight (job title weight, default 400).

= 1.3.0 =
* Layouts refined: em-based sizes (immune to a 62.5% root font size), a
  consistent spacing scale, a clear name and job title hierarchy, 16px contact
  rows with accent icon tiles and a 44px hit area, a neutral QR panel with a
  white plate, and a normal single-line Save contact button with hover and
  focus-visible states. The tagline is no longer italic.
* Two-tone panel: a dark panel (contrast preset) for the person and contacts
  beside a light aside for company, QR and button.
* New Card width setting (cardWidth: auto, narrow, medium, wide, full). The
  card fills its container up to a per-layout maximum instead of a fixed or
  content width.
* Removed the "Split, mirrored" layout. Cards with is-style-mirrored and
  old business/card theme2 cards render as Split; the editor converts the
  class through a block deprecation. Its pattern was removed.
* Patterns use generic sample content (Jane Doe, Example Company,
  example.com).
* The Save contact link no longer carries the core wp-block-button classes,
  so classic-theme button rules do not reach it.
* New fifth layout, Profile (is-style-profile): no card chrome, a full-width
  portrait (large image size with srcset) that fades into the background,
  a QR icon button that opens the server-rendered QR code in a popover
  (Popover API, no JavaScript; inline below the contacts in older browsers;
  an inert icon in the editor preview), name, job title in the accent
  colour, an "Add to contacts" button with a plus icon and an accent
  gradient, and contact rows (email first) with Tabler outline icons. New
  properties --lnyd-title-color and --lnyd-button-gradient-end. New pattern
  "Lanyard: Profile".

= 1.2.0 =
* Dynamic data UI: a database icon next to every content field opens a
  popover (source, field, options, insert) and inserts an inline tag at the
  cursor. Fields with tags show a readable summary underneath.
* Core WordPress sources without ACF: post and another post (title, excerpt,
  permalink, dates, post type label, featured image), site (name, tagline,
  home URL, logo) and user (names, bio, website, avatar, email for the post
  author only).
* Inline tags {{lnyd ...}} replace the sources/userId/refPostType/refPostId
  attributes. 1.1.0 blocks are converted by a block deprecation in the editor
  and on the fly at render time.
* Five layouts rebuilt from the original free designs replace Default,
  Minimal, Sidebar and Banner (old names keep rendering). One block pattern per
  layout.
* REST: lanyard/v1/fields and lanyard/v1/post-types replace acf-fields and
  acf-post-types.

= 1.1.0 =
* New source "ACF: another post": read fields from one published post of a
  chosen post type (block attributes refPostType and refPostId), with an
  "ACF post" panel to pick the post type and search the post.
* The field picker filters fields by post type, using the field groups'
  location rules (OR of AND groups). "ACF: this post" uses the post type
  being edited; unknown types (templates) fall back to all post groups.
* Fields that lack "Allow Access to Value in Editor UI" are shown as disabled
  options instead of being hidden. They still never render.
* Field options are grouped by ACF field group; a message is shown when a
  post type has no field groups.
* REST: acf-fields accepts post_type (validated against registered post
  types) and returns enabled flags and a group count; new acf-post-types
  route. Every argument has validate and sanitize callbacks.

= 1.0.0 =
* First release of the fork.
