/**
 * Bhranti — Rendered page checks.
 *
 * Editable runtime source; no compilation is required.
 *
 * @license GPL-2.0-or-later
 */
/* Actual same-origin browser observations. No generated code or visitor actions. */
( function () {
	'use strict';
	const VERSION = '4.14.0';
	const INTEGRITY = 'sha256-quC4s2J4CJliEFsjUepk9Np9PUQLVOFi4hYW1ZeqDW8=';
	const VIEWPORTS = Object.freeze( [
		{ width: 1280, height: 900 },
		{ width: 390, height: 844 },
		{ width: 320, height: 844 },
	] );
	const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
	const UUID =
		/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
	const SHA = /^[a-f0-9]{64}$/;
	const __ = ( text ) =>
		window.wp && window.wp.i18n
			? window.wp.i18n.__( text, 'bhranti' )
			: text;
	function failure( code ) {
		const error = new Error( code );
		error.code = code;
		return error;
	}
	function aborted() {
		const error = new Error( 'Aborted' );
		error.name = 'AbortError';
		return error;
	}
	function checkAbort( signal ) {
		if ( signal && signal.aborted ) throw aborted();
	}
	function plain( value, length ) {
		return Array.from(
			String( value == null ? '' : value )
				.replace( /[\u0000-\u001f\u007f]/g, ' ' )
				.replace( /\s+/g, ' ' )
				.trim()
		)
			.slice( 0, length )
			.join( '' );
	}
	function bytes( value ) {
		return new TextEncoder().encode( JSON.stringify( value ) ).length;
	}
	function finite( value, maximum = 10000000 ) {
		return Number.isFinite( value )
			? Math.max( 0, Math.min( maximum, Math.round( value ) ) )
			: 0;
	}
	function limited( profile, name ) {
		if (
			! profile.limitations.includes( name ) &&
			profile.limitations.length < 10
		)
			profile.limitations.push( name );
	}
	function validateJob(
		job,
		axeUrl,
		trusted = window.bhrantiSettings && window.bhrantiSettings.renderCheck
	) {
		if (
			! job ||
			typeof job !== 'object' ||
			! UUID.test( job.id ) ||
			! UUID.test( job.run_id ) ||
			! Number.isInteger( job.step ) ||
			job.step < 1 ||
			job.step > 1000 ||
			! Number.isInteger( job.post_id ) ||
			job.post_id < 1 ||
			( job.path !== '/wp/v2/pages/' + job.post_id &&
				job.path !== '/wp/v2/posts/' + job.post_id ) ||
			! SHA.test( job.revision ) ||
			! SHA.test( job.environment_revision ) ||
			! SHA.test( job.marker ) ||
			! Number.isInteger( job.expires ) ||
			job.expires * 1000 <= Date.now() ||
			job.expires * 1000 > Date.now() + 660000 ||
			job.engine !== 'axe-core' ||
			job.version !== VERSION ||
			! Array.isArray( job.viewports ) ||
			JSON.stringify( job.viewports ) !== JSON.stringify( VIEWPORTS )
		)
			throw failure( 'load' );
		let url, asset;
		try {
			url = new URL( job.url );
			asset = new URL( axeUrl );
		} catch ( _ ) {
			throw failure( 'origin' );
		}
		if (
			! [ 'https:', 'http:' ].includes( url.protocol ) ||
			url.origin !== window.location.origin ||
			url.username ||
			url.password ||
			url.hash ||
			/\/(?:wp-admin(?:\/|$)|wp-login\.php(?:\/|$)|wp-json(?:\/|$))/.test(
				url.pathname
			) ||
			url.searchParams.getAll( 'bhranti_render' ).length !== 1 ||
			! /^[1-9][0-9]*$/.test(
				url.searchParams.get( 'bhranti_render' ) || ''
			) ||
			url.searchParams.getAll( 'bhranti_render_job' ).length !== 1 ||
			url.searchParams.get( 'bhranti_render_job' ) !== job.id
		)
			throw failure( 'origin' );
		const allowed = [
			'bhranti_render',
			'bhranti_render_job',
			'preview',
			'preview_id',
			'preview_nonce',
			'p',
			'page_id',
		];
		if (
			Array.from( url.searchParams.keys() ).some(
				( key ) => ! allowed.includes( key )
			)
		)
			throw failure( 'origin' );
		if (
			! trusted ||
			trusted.version !== VERSION ||
			axeUrl !== trusted.axeUrl ||
			asset.origin !== window.location.origin ||
			asset.username ||
			asset.password ||
			asset.hash ||
			! asset.pathname.endsWith( '/assets/vendor/axe-core/axe.js' ) ||
			asset.pathname.includes( '%' ) ||
			Array.from( asset.searchParams.keys() ).some(
				( key ) => key !== 'ver'
			) ||
			asset.searchParams.getAll( 'ver' ).length > 1
		)
			throw failure( 'engine' );
		return url;
	}
	function bounded( promise, duration, code, signal ) {
		return new Promise( ( resolve, reject ) => {
			let done = false;
			const finish = ( callback, value ) => {
				if ( done ) return;
				done = true;
				window.clearTimeout( timer );
				if ( signal ) signal.removeEventListener( 'abort', cancel );
				callback( value );
			};
			const cancel = () => finish( reject, aborted() );
			const timer = window.setTimeout(
				() => finish( reject, failure( code ) ),
				duration
			);
			if ( signal )
				signal.addEventListener( 'abort', cancel, { once: true } );
			if ( signal && signal.aborted ) cancel();
			Promise.resolve( promise ).then(
				( value ) => finish( resolve, value ),
				( error ) => finish( reject, error )
			);
		} );
	}
	function pause( duration, signal ) {
		return bounded(
			new Promise( ( resolve ) =>
				window.setTimeout( resolve, duration )
			),
			duration + 500,
			'timeout',
			signal
		);
	}
	function documentIdentity( doc, url, marker ) {
		try {
			const current = new URL( doc.defaultView.location.href );
			if ( current.href !== url.href ) throw failure( 'origin' );
			if (
				! doc.documentElement ||
				! doc.body ||
				doc.querySelectorAll( 'meta[name="bhranti-render-check"]' )
					.length !== 1 ||
				doc
					.querySelector( 'meta[name="bhranti-render-check"]' )
					.getAttribute( 'content' ) !== marker
			)
				throw failure( 'marker' );
			return doc;
		} catch ( error ) {
			throw error && [ 'origin', 'marker' ].includes( error.code )
				? error
				: failure( 'origin' );
		}
	}

	function selector( element, doc ) {
		if (
			! element ||
			element.nodeType !== 1 ||
			element.ownerDocument !== doc
		)
			return '';
		const parts = [];
		for (
			let current = element;
			current && current.nodeType === 1 && parts.length < 12;
			current = current.parentElement
		) {
			const tag = current.localName;
			if ( ! /^[a-z][a-z0-9-]*$/.test( tag ) ) return '';
			let index = 1;
			for (
				let prior = current.previousElementSibling;
				prior;
				prior = prior.previousElementSibling
			)
				if ( prior.localName === tag ) index++;
			parts.unshift( tag + ':nth-of-type(' + index + ')' );
			if ( current === doc.documentElement )
				return plain( parts.join( ' > ' ), 300 );
		}
		return '';
	}
	function visible( element, view ) {
		const style = view.getComputedStyle( element ),
			rect = element.getBoundingClientRect();
		return (
			style.display !== 'none' &&
			style.visibility !== 'hidden' &&
			style.visibility !== 'collapse' &&
			rect.width > 0 &&
			rect.height > 0
		);
	}
	function sameDocument( url, current ) {
		const clean = ( value ) => {
			const copy = new URL( value );
			copy.hash = '';
			copy.searchParams.delete( 'bhranti_render' );
			copy.searchParams.delete( 'bhranti_render_job' );
			return copy.href;
		};
		return clean( url ) === clean( current );
	}
	function observations( doc, profile ) {
		const view = doc.defaultView;
		const all = Array.from( doc.querySelectorAll( '*' ) );
		if ( all.length > 5000 ) throw failure( 'limit' );
		const width = doc.documentElement.clientWidth;
		profile.layout = {
			document_width: finite(
				Math.max(
					doc.documentElement.scrollWidth,
					doc.body.scrollWidth
				)
			),
			viewport_width: finite( width ),
			overflow: [],
			overflow_count: 0,
		};
		for ( const element of all ) {
			if ( profile.layout.document_width <= width + 1 ) break;
			if ( ! visible( element, view ) ) continue;
			const rect = element.getBoundingClientRect();
			if ( rect.right > width + 1 && rect.left >= -1 ) {
				profile.layout.overflow_count++;
				if ( profile.layout.overflow.length < 20 )
					profile.layout.overflow.push( selector( element, doc ) );
			}
		}
		profile.images = { checked: 0, broken: [], broken_count: 0 };
		for ( const image of Array.from( doc.images ) ) {
			if ( ! visible( image, view ) ) continue;
			profile.images.checked++;
			if ( image.complete && ! image.naturalWidth ) {
				profile.images.broken_count++;
				if ( profile.images.broken.length < 20 )
					profile.images.broken.push( selector( image, doc ) );
			}
		}
		profile.anchors = {
			checked: 0,
			missing: [],
			missing_count: 0,
			duplicates: [],
			duplicate_count: 0,
		};
		const ids = new Map();
		for ( const element of all ) {
			const id = element.getAttribute( 'id' );
			if ( id ) {
				if ( ! ids.has( id ) ) ids.set( id, [] );
				ids.get( id ).push( element );
			}
		}
		for ( const elements of ids.values() )
			if ( elements.length > 1 ) {
				profile.anchors.duplicate_count++;
				if ( profile.anchors.duplicates.length < 20 )
					profile.anchors.duplicates.push(
						selector( elements[ 1 ], doc )
					);
			}
		for ( const link of Array.from( doc.querySelectorAll( 'a[href]' ) ) ) {
			if ( ! visible( link, view ) ) continue;
			profile.anchors.checked++;
			let destination;
			try {
				destination = new URL(
					link.getAttribute( 'href' ),
					doc.baseURI
				);
			} catch ( _ ) {
				continue;
			}
			if (
				! destination.hash ||
				! sameDocument( destination, view.location.href )
			)
				continue;
			let fragment = destination.hash.slice( 1 );
			try {
				fragment = decodeURIComponent( fragment );
			} catch ( _ ) {
				/* Browser treats malformed escaping as a literal fragment. */
			}
			if (
				! fragment ||
				fragment.toLowerCase() === 'top' ||
				doc.getElementById( fragment ) ||
				doc.getElementsByName( fragment ).length
			)
				continue;
			profile.anchors.missing_count++;
			if ( profile.anchors.missing.length < 20 )
				profile.anchors.missing.push( selector( link, doc ) );
		}
		const headings = Array.from(
			doc.querySelectorAll( 'h1,h2,h3,h4,h5,h6' )
		).filter( ( element ) => visible( element, view ) );
		if ( headings.length > 40 ) limited( profile, 'findings_limit' );
		profile.headings = headings.slice( 0, 40 ).map( ( element ) => ( {
			level: Number( element.localName.slice( 1 ) ),
			text: plain( element.textContent, 120 ),
		} ) );
		if ( doc.querySelector( 'iframe,frame' ) )
			limited( profile, 'nested_frames' );
	}
	async function assets( doc, profile, signal ) {
		const view = doc.defaultView;
		// Instrument only this disposable preview: load existing URLs despite offscreen lazy-image throttling.
		Array.from( doc.images ).forEach( ( image ) =>
			image.setAttribute( 'loading', 'eager' )
		);
		if ( doc.fonts && doc.fonts.ready ) {
			try {
				await bounded( doc.fonts.ready, 2000, 'timeout', signal );
			} catch ( error ) {
				if ( error.name === 'AbortError' ) throw error;
				limited( profile, 'fonts_timeout' );
			}
		}
		const x = view.scrollX,
			y = view.scrollY,
			start = Date.now();
		try {
			let offset = 0,
				moves = 0;
			while (
				Array.from( doc.images ).some(
					( image ) => ! image.complete
				) &&
				offset <
					Math.max(
						doc.documentElement.scrollHeight,
						doc.body.scrollHeight
					)
			) {
				checkAbort( signal );
				if (
					moves >= 20 ||
					offset > 20000 ||
					Date.now() - start > 4000
				) {
					limited( profile, 'lazy_limit' );
					break;
				}
				view.scrollTo( 0, offset );
				await pause( 90, signal );
				offset += Math.max( 300, profile.height - 100 );
				moves++;
			}
		} finally {
			view.scrollTo( x, y );
		}
		const pending = Array.from( doc.images ).filter(
			( image ) => visible( image, view ) && ! image.complete
		);
		if ( pending.length ) {
			const listeners = [];
			try {
				await bounded(
					Promise.all(
						pending.map(
							( image ) =>
								new Promise( ( resolve ) => {
									const done = () => resolve();
									image.addEventListener( 'load', done, {
										once: true,
									} );
									image.addEventListener( 'error', done, {
										once: true,
									} );
									listeners.push( [ image, done ] );
									if ( image.complete ) resolve();
								} )
						)
					),
					2500,
					'timeout',
					signal
				);
			} catch ( error ) {
				if ( error.name === 'AbortError' ) throw error;
				limited( profile, 'images_timeout' );
			} finally {
				listeners.forEach( ( [ image, done ] ) => {
					image.removeEventListener( 'load', done );
					image.removeEventListener( 'error', done );
				} );
			}
		}
		await pause( 80, signal );
	}
	function nodeSummary( node ) {
		const element = node.element;
		const rect =
			element && element.getBoundingClientRect
				? element.getBoundingClientRect()
				: null;
		let text = rect
			? __( 'Computed target box:' ) +
			  ' ' +
			  finite( rect.width ) +
			  ' × ' +
			  finite( rect.height ) +
			  ' CSS px.'
			: __( 'Observed element.' );
		for ( const check of [].concat(
			node.any || [],
			node.all || [],
			node.none || []
		) ) {
			const data = check && check.data;
			if ( ! data || typeof data !== 'object' ) continue;
			if (
				typeof data.contrastRatio === 'number' &&
				Number.isFinite( data.contrastRatio )
			)
				text +=
					' ' +
					__( 'Contrast ratio:' ) +
					' ' +
					Math.max( 0, Math.min( 100, data.contrastRatio ) ) +
					'.';
			for ( const key of [ 'fgColor', 'bgColor' ] )
				if (
					typeof data[ key ] === 'string' &&
					/^#[a-f0-9]{3,8}$/i.test( data[ key ] )
				)
					text += ' ' + key + ': ' + data[ key ] + '.';
		}
		return plain( text, 220 );
	}
	function findings( rules, doc, profile ) {
		if ( ! Array.isArray( rules ) ) throw failure( 'engine' );
		if ( rules.length > 24 ) limited( profile, 'findings_limit' );
		return rules.slice( 0, 24 ).map( ( rule ) => {
			const nodes = Array.isArray( rule.nodes ) ? rule.nodes : [];
			if ( nodes.length > 3 ) limited( profile, 'findings_limit' );
			return {
				id: plain( rule.id, 100 ),
				impact: [ 'minor', 'moderate', 'serious', 'critical' ].includes(
					rule.impact
				)
					? rule.impact
					: null,
				description: plain( rule.description, 240 ),
				nodes: nodes.slice( 0, 3 ).map( ( node ) => ( {
					selector: selector( node.element, doc ),
					summary: nodeSummary( node ),
				} ) ),
			};
		} );
	}
	function waitDocument( doc, url, marker, signal ) {
		let cleanup = () => {};
		const loaded = new Promise( ( resolve, reject ) => {
			let domReady = false;
			const ready = () => {
				domReady = true;
				check();
			};
			const check = () => {
				if ( doc.readyState === 'loading' ) return;
				let parsed = domReady || doc.readyState === 'complete';
				try {
					const navigation =
						doc.defaultView.performance.getEntriesByType(
							'navigation'
						)[ 0 ];
					parsed =
						parsed ||
						!! (
							navigation &&
							navigation.domContentLoadedEventEnd > 0
						);
				} catch ( _ ) {
					/* Native DOMContentLoaded remains available without Navigation Timing. */
				}
				if ( ! parsed ) return;
				try {
					documentIdentity( doc, url, marker );
					if (
						Array.from(
							doc.querySelectorAll( 'link[rel~="stylesheet"]' )
						).some( ( link ) => {
							const href = link.getAttribute( 'href' );
							if ( ! href || ! href.trim() || link.disabled )
								return false;
							try {
								if (
									link.media &&
									typeof doc.defaultView.matchMedia ===
										'function' &&
									doc.defaultView.matchMedia( link.media )
										.matches === false
								)
									return false;
							} catch ( _ ) {
								/* Unknown media readiness waits conservatively. */
							}
							return ! link.sheet;
						} )
					)
						return;
					resolve();
				} catch ( error ) {
					reject( error );
				}
			};
			doc.addEventListener( 'DOMContentLoaded', ready );
			const timer = window.setInterval( check, 50 );
			cleanup = () => {
				window.clearInterval( timer );
				doc.removeEventListener( 'DOMContentLoaded', ready );
			};
			check();
		} );
		return bounded( loaded, 10000, 'load', signal ).finally( cleanup );
	}

	async function engine( doc, axeUrl, signal ) {
		const script = doc.createElement( 'script' );
		script.src = axeUrl;
		script.integrity = INTEGRITY;
		script.crossOrigin = 'anonymous';
		let cleanup = () => {};
		const loaded = new Promise( ( resolve, reject ) => {
			const load = () => resolve();
			const error = () => reject( failure( 'engine' ) );
			script.addEventListener( 'load', load );
			script.addEventListener( 'error', error );
			cleanup = () => {
				script.removeEventListener( 'load', load );
				script.removeEventListener( 'error', error );
			};
			doc.head.appendChild( script );
		} );
		try {
			await bounded( loaded, 5000, 'engine', signal );
		} finally {
			cleanup();
			script.remove();
		}
		const axe = doc.defaultView.axe;
		if (
			! axe ||
			axe.version !== VERSION ||
			typeof axe.run !== 'function' ||
			typeof axe.configure !== 'function'
		)
			throw failure( 'engine' );
		axe.configure( { noHtml: true, allowedOrigins: [] } );
		checkAbort( signal );
		return bounded(
			axe.run( doc, {
				runOnly: { type: 'tag', values: TAGS },
				iframes: false,
				preload: false,
				elementRef: true,
				selectors: false,
				resultTypes: [
					'violations',
					'incomplete',
					'passes',
					'inapplicable',
				],
			} ),
			8000,
			'timeout',
			signal
		);
	}
	async function measure(
		doc,
		url,
		job,
		viewport,
		axeUrl,
		signal,
		progress
	) {
		await waitDocument( doc, url, job.marker, signal );
		const view = doc.defaultView;
		if (
			Math.abs( view.innerWidth - viewport.width ) > 1 ||
			Math.abs( view.innerHeight - viewport.height ) > 1
		)
			throw failure( 'limit' );
		if ( doc.querySelectorAll( '*' ).length > 5000 )
			throw failure( 'limit' );
		const profile = {
			width: viewport.width,
			height: viewport.height,
			violations: [],
			incomplete: [],
			passes: 0,
			inapplicable: 0,
			layout: {},
			images: {},
			anchors: {},
			headings: [],
			limitations: [
				'automated_only',
				'no_interaction',
				'focus_not_tested',
			],
		};
		progress( 'assets' );
		await assets( doc, profile, signal );
		documentIdentity( doc, url, job.marker );
		observations( doc, profile );
		progress( 'checking' );
		const result = await engine( doc, axeUrl, signal );
		documentIdentity( doc, url, job.marker );
		profile.violations = findings( result.violations, doc, profile );
		profile.incomplete = findings( result.incomplete, doc, profile );
		profile.passes = finite(
			Array.isArray( result.passes ) ? result.passes.length : 0,
			1000
		);
		profile.inapplicable = finite(
			Array.isArray( result.inapplicable )
				? result.inapplicable.length
				: 0,
			1000
		);
		if ( profile.incomplete.length ) limited( profile, 'axe_incomplete' );
		return profile;
	}
	const PROTOCOL = 'bhranti-render-1';
	const CODES = [ 'load', 'marker', 'origin', 'timeout', 'limit', 'engine' ];
	const exact = ( value, keys ) =>
		!! value &&
		typeof value === 'object' &&
		! Array.isArray( value ) &&
		Object.keys( value ).length === keys.length &&
		keys.every( ( key ) =>
			Object.prototype.hasOwnProperty.call( value, key )
		);
	const integer = ( value, maximum ) =>
		Number.isInteger( value ) && value >= 0 && value <= maximum;
	const text = ( value, maximum ) =>
		typeof value === 'string' && Array.from( value ).length <= maximum;
	const list = ( value, maximum, valid ) =>
		Array.isArray( value ) &&
		value.length <= maximum &&
		value.every( valid );
	function validProfile( value, viewport ) {
		const strings = ( value ) =>
			list( value, 20, ( item ) => text( item, 300 ) );
		const issues = ( value ) =>
			list(
				value,
				24,
				( issue ) =>
					exact( issue, [
						'id',
						'impact',
						'description',
						'nodes',
					] ) &&
					text( issue.id, 100 ) &&
					[
						null,
						'minor',
						'moderate',
						'serious',
						'critical',
					].includes( issue.impact ) &&
					text( issue.description, 240 ) &&
					list(
						issue.nodes,
						3,
						( node ) =>
							exact( node, [ 'selector', 'summary' ] ) &&
							text( node.selector, 300 ) &&
							text( node.summary, 220 )
					)
			);
		if (
			! exact( value, [
				'width',
				'height',
				'violations',
				'incomplete',
				'passes',
				'inapplicable',
				'layout',
				'images',
				'anchors',
				'headings',
				'limitations',
			] ) ||
			value.width !== viewport.width ||
			value.height !== viewport.height ||
			! issues( value.violations ) ||
			! issues( value.incomplete ) ||
			! integer( value.passes, 1000 ) ||
			! integer( value.inapplicable, 1000 )
		)
			return false;
		const { layout, images, anchors } = value;
		return (
			exact( layout, [
				'document_width',
				'viewport_width',
				'overflow',
				'overflow_count',
			] ) &&
			integer( layout.document_width, 5000000 ) &&
			integer( layout.viewport_width, 1280 ) &&
			strings( layout.overflow ) &&
			integer( layout.overflow_count, 5000 ) &&
			exact( images, [ 'checked', 'broken', 'broken_count' ] ) &&
			integer( images.checked, 5000 ) &&
			strings( images.broken ) &&
			integer( images.broken_count, 5000 ) &&
			exact( anchors, [
				'checked',
				'missing',
				'missing_count',
				'duplicates',
				'duplicate_count',
			] ) &&
			integer( anchors.checked, 5000 ) &&
			strings( anchors.missing ) &&
			integer( anchors.missing_count, 5000 ) &&
			strings( anchors.duplicates ) &&
			integer( anchors.duplicate_count, 5000 ) &&
			list(
				value.headings,
				40,
				( heading ) =>
					exact( heading, [ 'level', 'text' ] ) &&
					Number.isInteger( heading.level ) &&
					heading.level >= 1 &&
					heading.level <= 6 &&
					text( heading.text, 120 )
			) &&
			list( value.limitations, 10, ( item ) =>
				[
					'automated_only',
					'no_interaction',
					'focus_not_tested',
					'findings_limit',
					'nested_frames',
					'fonts_timeout',
					'images_timeout',
					'lazy_limit',
					'axe_incomplete',
				].includes( item )
			)
		);
	}
	function binding( job, viewport ) {
		return {
			job_id: job.id,
			run_id: job.run_id,
			step: job.step,
			marker: job.marker,
			revision: job.revision,
			environment_revision: job.environment_revision,
			width: viewport.width,
			height: viewport.height,
		};
	}
	function envelope( data, expected ) {
		return (
			exact( data, [
				'protocol',
				'kind',
				'binding',
				'channel',
				'payload',
			] ) &&
			data.protocol === PROTOCOL &&
			[ 'ready', 'start', 'progress', 'result', 'cancel' ].includes(
				data.kind
			) &&
			exact( data.binding, Object.keys( expected ) ) &&
			Object.keys( expected ).every(
				( key ) => data.binding[ key ] === expected[ key ]
			)
		);
	}
	function preview( frame, url, job, viewport, signal, progress ) {
		return new Promise( ( resolve, reject ) => {
			const expected = binding( job, viewport ),
				channel = window.crypto.randomUUID
					? window.crypto.randomUUID()
					: '10000000-1000-4000-8000-100000000000'.replace(
							/[018]/g,
							( value ) =>
								(
									Number( value ) ^
									( window.crypto.getRandomValues(
										new Uint8Array( 1 )
									)[ 0 ] &
										( 15 >> ( Number( value ) / 4 ) ) )
								).toString( 16 )
					  );
			let done = false,
				started = false,
				phase = 0;
			const send = ( kind ) =>
				frame.contentWindow.postMessage(
					{
						protocol: PROTOCOL,
						kind,
						binding: expected,
						channel,
						payload: null,
					},
					url.origin
				);
			const cleanup = () => {
				window.clearTimeout( timer );
				window.removeEventListener( 'message', receive );
				frame.removeEventListener( 'error', error );
				signal.removeEventListener( 'abort', cancel );
			};
			const finish = ( callback, value ) => {
				if ( done ) return;
				done = true;
				cleanup();
				callback( value );
			};
			const cancel = () => {
				if ( started ) {
					try {
						send( 'cancel' );
					} catch ( _ ) {
						/* Removing the owned frame also destroys its work. */
					}
				}
				finish( reject, aborted() );
			};
			const error = () => finish( reject, failure( 'load' ) );
			const receive = ( event ) => {
				if (
					done ||
					event.origin !== url.origin ||
					event.source !== frame.contentWindow ||
					! envelope( event.data, expected )
				)
					return;
				const data = event.data;
				try {
					if ( bytes( data ) > 145000 )
						return finish( reject, failure( 'limit' ) );
				} catch ( _ ) {
					return finish( reject, failure( 'limit' ) );
				}
				if (
					data.kind === 'ready' &&
					! started &&
					data.channel === '' &&
					data.payload === null
				) {
					started = true;
					try {
						send( 'start' );
					} catch ( _ ) {
						finish( reject, failure( 'origin' ) );
					}
					return;
				}
				if ( ! started || data.channel !== channel ) return;
				if (
					data.kind === 'progress' &&
					( ( data.payload === 'assets' && phase === 0 ) ||
						( data.payload === 'checking' && phase === 1 ) )
				) {
					phase++;
					if ( phase === 1 ) window.clearTimeout( timer );
					progress( data.payload );
					return;
				}
				if ( data.kind !== 'result' ) return;
				const result = data.payload;
				if (
					exact( result, [ 'status', 'code' ] ) &&
					result.status === 'failed' &&
					CODES.includes( result.code )
				)
					return finish( reject, failure( result.code ) );
				if (
					phase !== 2 ||
					! exact( result, [ 'status', 'profile' ] ) ||
					result.status !== 'complete' ||
					! validProfile( result.profile, viewport )
				)
					return finish( reject, failure( 'engine' ) );
				finish( resolve, result.profile );
			};
			const timer = window.setTimeout( error, 10000 );
			window.addEventListener( 'message', receive );
			frame.addEventListener( 'error', error );
			signal.addEventListener( 'abort', cancel, { once: true } );
			if ( signal.aborted ) {
				cancel();
				return;
			}
			frame.src = url.href;
			document.body.appendChild( frame );
		} );
	}
	async function serveFrame( config ) {
		if (
			window.parent === window ||
			! exact( config, [ 'job', 'renderCheck' ] )
		)
			return;
		const controller = new AbortController();
		let timer, receive;
		const cleanup = () => {
			window.clearTimeout( timer );
			if ( receive ) window.removeEventListener( 'message', receive );
			window.removeEventListener( 'pagehide', cancel );
		};
		const cancel = () => {
			controller.abort();
			cleanup();
		};
		window.addEventListener( 'pagehide', cancel, { once: true } );
		try {
			const job = config.job,
				axeUrl = config.renderCheck && config.renderCheck.axeUrl,
				url = validateJob( job, axeUrl, config.renderCheck );
			const viewport = VIEWPORTS.find(
				( item ) =>
					Math.abs( window.innerWidth - item.width ) <= 1 &&
					Math.abs( window.innerHeight - item.height ) <= 1
			);
			if ( ! viewport ) throw failure( 'limit' );
			const expected = binding( job, viewport );
			let started = false,
				channel = '';
			const send = ( kind, payload ) => {
				const data = {
					protocol: PROTOCOL,
					kind,
					binding: expected,
					channel,
					payload,
				};
				if ( bytes( data ) > 145000 ) throw failure( 'limit' );
				window.parent.postMessage( data, url.origin );
			};
			receive = async ( event ) => {
				if (
					event.origin !== url.origin ||
					event.source !== window.parent ||
					! envelope( event.data, expected ) ||
					event.data.payload !== null ||
					! UUID.test( event.data.channel )
				)
					return;
				const data = event.data;
				if (
					started &&
					data.kind === 'cancel' &&
					data.channel === channel
				) {
					cancel();
					return;
				}
				if ( started || data.kind !== 'start' ) return;
				started = true;
				channel = data.channel;
				window.clearTimeout( timer );
				try {
					validateJob( job, axeUrl, config.renderCheck );
					checkAbort( controller.signal );
					const profile = await measure(
						document,
						url,
						job,
						viewport,
						axeUrl,
						controller.signal,
						( phase ) => send( 'progress', phase )
					);
					checkAbort( controller.signal );
					send( 'result', { status: 'complete', profile } );
				} catch ( error ) {
					if ( ! controller.signal.aborted ) {
						try {
							send( 'result', {
								status: 'failed',
								code:
									error && CODES.includes( error.code )
										? error.code
										: 'engine',
							} );
						} catch ( _ ) {
							/* Parent keeps its original bounded wait. */
						}
					}
				} finally {
					cleanup();
				}
			};
			window.addEventListener( 'message', receive );
			timer = window.setTimeout( cancel, 10000 );
			send( 'ready', null );
		} catch ( _ ) {
			cancel();
		}
	}
	async function run( job, options = {} ) {
		if ( ! options || typeof options !== 'object' )
			return { status: 'failed', code: 'engine' };
		if (
			options.signal &&
			( typeof options.signal.aborted !== 'boolean' ||
				typeof options.signal.addEventListener !== 'function' ||
				typeof options.signal.removeEventListener !== 'function' )
		)
			return { status: 'failed', code: 'engine' };
		const controller = new AbortController();
		const cancel = () => controller.abort();
		let deadline = false,
			frame,
			focused = document.activeElement;
		const rememberFocus = ( event ) => {
			if (
				event.target !== frame &&
				event.target.ownerDocument === document
			)
				focused = event.target;
		};
		const restoreFocus = () => {
			if (
				frame &&
				document.activeElement === frame &&
				focused &&
				focused.isConnected &&
				typeof focused.focus === 'function'
			) {
				try {
					focused.focus( { preventScroll: true } );
				} catch ( _ ) {
					/* Do not replace a removed native control. */
				}
			}
		};
		document.addEventListener( 'focusin', rememberFocus, true );
		window.addEventListener( 'pagehide', cancel, { once: true } );
		const timer = window.setTimeout( () => {
			deadline = true;
			controller.abort();
		}, 85000 );
		if ( options.signal )
			options.signal.addEventListener( 'abort', cancel, { once: true } );
		try {
			checkAbort( options.signal );
			const axeUrl = options.axeUrl,
				url = validateJob( job, axeUrl );
			const report = {
				status: 'complete',
				engine: 'axe-core',
				version: VERSION,
				profiles: [],
			};
			for ( const viewport of VIEWPORTS ) {
				checkAbort( controller.signal );
				const progress = ( phase ) => {
					restoreFocus();
					if ( typeof options.onProgress === 'function' ) {
						try {
							options.onProgress( {
								phase,
								width: viewport.width,
								height: viewport.height,
								message: __(
									phase === 'loading'
										? 'Loading the saved page for rendered checks.'
										: phase === 'assets'
										? 'Waiting for page fonts and images.'
										: 'Checking rendered layout and automated WCAG rules.'
								),
							} );
						} catch ( _ ) {
							/* Progress display never changes a measurement. */
						}
					}
				};
				progress( 'loading' );
				frame = document.createElement( 'iframe' );
				frame.title = __( 'Bhranti rendered page check' );
				frame.tabIndex = -1;
				frame.setAttribute( 'aria-hidden', 'true' );
				// Same-origin installed page scripts execute normally. This is not a security isolation boundary.
				frame.setAttribute(
					'sandbox',
					'allow-same-origin allow-scripts'
				);
				frame.referrerPolicy = 'same-origin';
				Object.assign( frame.style, {
					position: 'fixed',
					left: '-20000px',
					top: '0',
					width: viewport.width + 'px',
					height: viewport.height + 'px',
					border: '0',
					margin: '0',
					padding: '0',
					pointerEvents: 'none',
					display: 'block',
				} );
				const profile = await preview(
					frame,
					url,
					job,
					viewport,
					controller.signal,
					progress
				);
				report.profiles.push( profile );
				frame.remove();
				frame = null;
			}
			if ( bytes( report ) > 145000 ) throw failure( 'limit' );
			return report;
		} catch ( error ) {
			if ( error && error.name === 'AbortError' && ! deadline )
				throw error;
			return {
				status: 'failed',
				code: deadline
					? 'timeout'
					: error && CODES.includes( error.code )
					? error.code
					: 'engine',
			};
		} finally {
			window.clearTimeout( timer );
			document.removeEventListener( 'focusin', rememberFocus, true );
			window.removeEventListener( 'pagehide', cancel );
			if ( options.signal )
				options.signal.removeEventListener( 'abort', cancel );
			restoreFocus();
			if ( frame ) frame.remove();
		}
	}

	window.BhrantiRender = Object.freeze( { run } );
	if ( window.bhrantiRenderFrame ) serveFrame( window.bhrantiRenderFrame );
} )();
