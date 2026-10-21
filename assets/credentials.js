/**
 * Bhranti — Credential checks.
 *
 * Editable runtime source; no compilation is required.
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';
	const rules = window.bhrantiCredentialRules || {
		patterns: [],
		binding: '(?!)',
		safeValues: [],
	};
	const patterns = rules.patterns.map(
		( pattern ) => new RegExp( pattern, 'giu' )
	);
	const binding = new RegExp( rules.binding, 'giu' );
	const safeValues = new Set( rules.safeValues );
	const entities = {
		amp: '&',
		AMP: '&',
		lt: '<',
		LT: '<',
		gt: '>',
		GT: '>',
		quot: '"',
		QUOT: '"',
		apos: "'",
		Tab: '\t',
		NewLine: '\n',
		nbsp: '\u00a0',
		NonBreakingSpace: '\u00a0',
		ensp: '\u2002',
		emsp: '\u2003',
		emsp13: '\u2004',
		emsp14: '\u2005',
		numsp: '\u2007',
		puncsp: '\u2008',
		thinsp: '\u2009',
		ThinSpace: '\u2009',
		hairsp: '\u200a',
		VeryThinSpace: '\u200a',
		MediumSpace: '\u205f',
		ThickSpace: '\u205f\u200a',
		excl: '!',
		num: '#',
		dollar: '$',
		percnt: '%',
		lpar: '(',
		rpar: ')',
		ast: '*',
		midast: '*',
		plus: '+',
		comma: ',',
		period: '.',
		sol: '/',
		colon: ':',
		semi: ';',
		equals: '=',
		quest: '?',
		commat: '@',
		lsqb: '[',
		lbrack: '[',
		bsol: '\\',
		rsqb: ']',
		rbrack: ']',
		Hat: '^',
		lowbar: '_',
		UnderBar: '_',
		grave: '`',
		DiacriticalGrave: '`',
		lcub: '{',
		lbrace: '{',
		vert: '|',
		VerticalLine: '|',
		rcub: '}',
		rbrace: '}',
		ldquo: '“',
		rdquo: '”',
		lsquo: '‘',
		rsquo: '’',
		ZeroWidthSpace: '\u200b',
		zwnj: '\u200c',
		zwj: '\u200d',
		lrm: '\u200e',
		rlm: '\u200f',
		NoBreak: '\u2060',
		NegativeVeryThinSpace: '\u200b',
		NegativeThinSpace: '\u200b',
		NegativeMediumSpace: '\u200b',
		NegativeThickSpace: '\u200b',
	};
	function character( value, original ) {
		return ( value === 9 || value === 10 || value === 12 || value >= 32 ) &&
			value <= 0x10ffff &&
			! (
				( value >= 0x7f && value <= 0x9f ) ||
				( value >= 0xd800 && value <= 0xdfff ) ||
				( value >= 0xfdd0 && value <= 0xfdef ) ||
				( value & 0xffff ) >= 0xfffe
			)
			? String.fromCodePoint( value )
			: original;
	}
	function normalize( value ) {
		for ( let pass = 0; pass < 2; pass++ ) {
			value = value.replace(
				/&(#x[a-f0-9]+|#\d+|[a-z][a-z0-9]+);/giu,
				( original, entity ) =>
					entity[ 0 ] === '#'
						? character(
								parseInt(
									entity.slice(
										entity[ 1 ].toLowerCase() === 'x'
											? 2
											: 1
									),
									entity[ 1 ].toLowerCase() === 'x' ? 16 : 10
								),
								original
						  )
						: entities[ entity ] || original
			);
			value = value.replace(
				/\\u([a-f0-9]{4})/giu,
				( original, hexadecimal ) =>
					character(
						parseInt( hexadecimal, 16 ),
						'&#x' + hexadecimal + ';'
					)
			);
			value = value.replace( /\\(["'/])/gu, '$1' );
			const plain = value.replace(
				/(?:%[a-f0-9]{2})+/giu,
				( encoded ) => {
					try {
						return decodeURIComponent( encoded );
					} catch ( error ) {
						return encoded.replace(
							/%([a-f0-9]{2})/giu,
							( original, hex ) =>
								parseInt( hex, 16 ) < 128
									? String.fromCodePoint(
											parseInt( hex, 16 )
									  )
									: ''
						);
					}
				}
			);
			value = plain;
		}
		return value
			.replace( /[“”]/gu, '"' )
			.replace( /[‘’]/gu, "'" )
			.replace( /[\u200b-\u200f\u2060\ufeff]/gu, '' );
	}
	function containsText( value ) {
		const normalized = normalize( value );
		// Match the server's adjacent-element label rule without treating a prose
		// paragraph that merely mentions passwords as a credential binding.
		const adjacent = normalized.replace(
			/(?:<[a-z][a-z0-9:-]*\b[^>]*>\s*){1,4}([^<>]{1,80})(?:\s*<\/[a-z][a-z0-9:-]*\s*>\s*){1,4}(?=<[a-z][a-z0-9:-]*\b[^>]*>)/giu,
			( whole, label ) => {
				const probe = label.trim() + '=bhranti_probe';
				binding.lastIndex = 0;
				const match = binding.exec( probe );
				return match &&
					match.index === 0 &&
					match[ 0 ] === probe &&
					match[ 1 ] === 'bhranti_probe'
					? label + ': '
					: whole;
			}
		);
		for ( const text of new Set( [
			normalized,
			normalized.replace( /<[^>]*>/gsu, '' ),
			adjacent.replace( /<[^>]*>/gsu, '' ),
		] ) ) {
			for ( const pattern of patterns ) {
				pattern.lastIndex = 0;
				if ( pattern.test( text ) ) return true;
			}
			binding.lastIndex = 0;
			let match;
			while ( ( match = binding.exec( text ) ) ) {
				const matched = match[ 1 ]
					.replace( /^[\s"'`]+|[\s"'`]+$/gu, '' )
					.toLowerCase();
				if (
					matched &&
					! safeValues.has( matched.replace( /[.!?]+$/gu, '' ) ) &&
					! /^(?:[\[<{](?:YOUR(?:[_ -][a-z0-9_-]+)*|EXAMPLE(?:[_ -][a-z0-9_-]+)*|REDACTED|TOKEN|PASSWORD|API[_ -]?KEY|SENSITIVE TEXT WITHHELD)[\]}>]|(?:YOUR|EXAMPLE|REDACTED)(?:[_ -][a-z0-9_-]+)*|\*{2,}|x{3,}|\.{3,}|[_-]{2,})$/iu.test(
						matched
					)
				)
					return true;
			}
		}
		return false;
	}
	function contains( value, depth = 0 ) {
		if ( depth > 32 ) return true;
		if ( value && typeof value === 'object' )
			return Object.entries( value ).some(
				( [ key, item ] ) =>
					contains( item, depth + 1 ) ||
					( ! Array.isArray( value ) &&
						containsText( key + ' = bhranti_probe' ) &&
						boundValue( key, item, depth + 1 ) )
			);
		return (
			typeof value === 'string' && value !== '' && containsText( value )
		);
	}
	function boundValue( key, value, depth ) {
		if ( depth > 32 ) return true;
		if ( value && typeof value === 'object' )
			return Object.values( value ).some( ( item ) =>
				boundValue( key, item, depth + 1 )
			);
		return (
			( typeof value === 'string' ||
				typeof value === 'number' ||
				typeof value === 'boolean' ) &&
			containsText(
				key +
					' = ' +
					( typeof value === 'boolean'
						? value
							? '1'
							: ''
						: String( value ) )
			)
		);
	}
	window.bhrantiCredentials = { contains };
} )();
