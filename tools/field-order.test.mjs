/**
 * Unit test for src/card/field-order.js (no WordPress, no PHP).
 *
 * Run: npm run test:unit   (node --test "tools/*.test.mjs")
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildFieldSections } from '../src/card/field-order.js';

const titles = {
	matches: 'Matches this Team member',
	other: 'Other field groups',
	noMatches: 'ACF field groups',
};

const core = {
	key: 'wordpress',
	title: 'WordPress',
	fields: [ { arg: 'field', value: 'title', label: 'Title', enabled: true } ],
};
const team = {
	key: 'acf-group_team',
	title: 'ACF: Team',
	group: 'Team',
	acf: true,
	matches: true,
	fields: [
		{ arg: 'acf', value: 'field_phone_team', label: 'Phone', enabled: true },
		{ arg: 'acf', value: 'field_role', label: 'Role', enabled: false },
	],
};
const events = {
	key: 'acf-group_events',
	title: 'ACF: Events',
	group: 'Events',
	acf: true,
	matches: false,
	fields: [
		{ arg: 'acf', value: 'field_phone_event', label: 'Phone', enabled: true },
	],
};
const people = {
	key: 'acf-group_people',
	title: 'ACF: People',
	group: 'People',
	acf: true,
	matches: true,
	fields: [ { arg: 'acf', value: 'field_mail', label: 'Email', enabled: true } ],
};

test( 'core first, then matching groups, then the others', () => {
	// Server order deliberately mixed: a non-matching group in the middle.
	const sections = buildFieldSections( [ core, events, team, people ], titles );
	assert.deepEqual(
		sections.map( ( s ) => s.key ),
		[ 'wordpress', 'acf-matches', 'acf-other' ]
	);
	assert.equal( sections[ 1 ].title, 'Matches this Team member' );
	assert.equal( sections[ 2 ].title, 'Other field groups' );
	assert.deepEqual(
		sections[ 1 ].fields.map( ( f ) => f.value ),
		[ 'field_phone_team', 'field_role', 'field_mail' ]
	);
	assert.deepEqual(
		sections[ 2 ].fields.map( ( f ) => f.value ),
		[ 'field_phone_event' ]
	);
} );

test( 'options read "Label (Group)"; core labels unchanged', () => {
	const sections = buildFieldSections( [ core, team, events ], titles );
	assert.equal( sections[ 0 ].fields[ 0 ].optionLabel, 'Title' );
	assert.equal( sections[ 1 ].fields[ 0 ].optionLabel, 'Phone (Team)' );
	assert.equal( sections[ 2 ].fields[ 0 ].optionLabel, 'Phone (Events)' );
} );

test( 'duplicate field names stay distinct by key', () => {
	const all = buildFieldSections( [ team, events ], titles ).flatMap(
		( s ) => s.fields
	);
	const phones = all.filter( ( f ) => f.label === 'Phone' );
	assert.equal( phones.length, 2 );
	assert.notEqual( phones[ 0 ].value, phones[ 1 ].value );
	assert.ok( phones.every( ( f ) => f.value.startsWith( 'field_' ) ) );
} );

test( 'nothing is dropped, disabled fields included', () => {
	const input = [ core, events, team, people ];
	const count = input.reduce( ( n, g ) => n + g.fields.length, 0 );
	const out = buildFieldSections( input, titles ).flatMap( ( s ) => s.fields );
	assert.equal( out.length, count );
	assert.equal( out.find( ( f ) => f.value === 'field_role' ).enabled, false );
} );

test( 'no matching group: one ACF section with the neutral title', () => {
	const sections = buildFieldSections( [ core, events ], titles );
	assert.deepEqual(
		sections.map( ( s ) => s.title ),
		[ 'WordPress', 'ACF field groups' ]
	);
} );

test( 'bad input gives no sections', () => {
	assert.deepEqual( buildFieldSections( null, titles ), [] );
	assert.deepEqual( buildFieldSections( [ null, 3 ], titles ), [] );
} );
