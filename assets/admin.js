/**
 * Bhranti — Chat sidebar.
 *
 * Editable runtime source; no compilation is required.
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';
	const root = document.getElementById( 'bhranti-app' );
	if ( ! root ) return;
	const settings = window.bhrantiSettings || {},
		wp = window.wp || {};
	const __ = wp.i18n ? wp.i18n.__ : ( value ) => value;
	const sprintf =
		wp.i18n && wp.i18n.sprintf
			? wp.i18n.sprintf
			: ( value, ...args ) =>
					value.replace( /%[ds]/g, () => String( args.shift() ) );
	const apiPath = ( settings.apiPath || '/bhranti/v1' ).replace( /\/+$/, '' );
	const state = {
		open: false,
		initialized: false,
		loading: false,
		busy: false,
		stepping: false,
		stopRequested: false,
		pausedForEditor: false,
		editor: null,
		editorError: '',
		progress: null,
		menu: false,
		workspace: { sessions: [], ai: {} },
		session: null,
		draft: '',
		drafts: new Map(),
		screenshots: new Map(),
		screenshot: null,
		capturing: false,
		error: '',
		credentialWarning: false,
		reconnecting: 0,
		connectionError: '',
		results: new Map(),
		notices: [],
		seen: new Set(),
		width: 350,
		attachPage: true,
		attachBlock: true,
		agentId: 'bhranti',
	};
	let renderController = null;
	let noticeSequence = 0,
		lastTrigger = null,
		contextFingerprint = '',
		progressTimer = null,
		heartbeatTimer = null,
		backgroundTimer = null,
		heartbeatRequest = null,
		heartbeatQueued = false,
		heartbeatFingerprint = '',
		backgroundReading = false,
		pageSuspended = false,
		interacting = false,
		composing = false,
		renderPending = false,
		interactionTimer = null,
		pageFormDirty = false,
		streamSession = null;
	const storageKey =
		'bhranti-session:' +
		( settings.connectorsUrl || window.location.origin );
	const openStorageKey =
		'bhranti-admin-open:' +
		( settings.connectorsUrl || window.location.origin ) +
		':' +
		( settings.userId || 0 );
	const scrollPositions = new Map(),
		disclosureStates = new Map(),
		referenceRemovals = new Map(),
		credentialDrafts = new Map();
	let renderedFingerprint = '',
		menuScroll = 0,
		chatSearch = '',
		chatListRequest = null;
	const editorSync = {
		baselines: new Map(),
		pending: null,
		timer: null,
		request: null,
		fetching: false,
		generation: 0,
		snapshot: null,
		message: '',
	};
	let editorControl = null,
		siteControl = null;
	let pendingAction = null,
		stopRunKey = '',
		blockedObservation = null,
		credentialReview = false;
	const settingsEvents = new Map();
	let triggerFingerprint = '';
	function rememberOpen( open ) {
		if ( settings.isAdmin ) {
			try {
				if ( open )
					window.sessionStorage.setItem( openStorageKey, '1' );
				else window.sessionStorage.removeItem( openStorageKey );
			} catch ( error ) {
				/* Storage can be unavailable in private browsing. */
			}
		}
	}
	if ( settings.isAdmin ) {
		try {
			settings.autoOpen =
				settings.autoOpen ||
				window.sessionStorage.getItem( openStorageKey ) === '1';
		} catch ( error ) {
			/* The explicit URL flag still works without storage. */
		}
	}

	function element( tag, className, ...children ) {
		const node = document.createElement( tag );
		if ( className ) node.className = className;
		children.flat().forEach( ( child ) => {
			if ( child !== null && child !== undefined && child !== false )
				node.appendChild(
					child instanceof Node
						? child
						: document.createTextNode( String( child ) )
				);
		} );
		return node;
	}
	function button( label, className, action, id ) {
		const node = element( 'button', className, label );
		node.type = 'button';
		if ( id ) node.id = id;
		node.addEventListener( 'click', action );
		return node;
	}
	// Bootstrap Icons (MIT); original paths and license are bundled locally.
	function icon( name ) {
		const paths = {
			history: [
				{
					d: 'M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4.414a1 1 0 0 0-.707.293L.854 15.146A.5.5 0 0 1 0 14.793zm3.5 1a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1zm0 2.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1z',
				},
			],
			plus: [
				{
					'fill-rule': 'evenodd',
					d: 'M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2',
				},
			],
			settings: [
				{
					d: 'M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.858z',
				},
			],
			close: [
				{
					d: 'M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z',
				},
			],
			send: [
				{
					'fill-rule': 'evenodd',
					d: 'M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5',
				},
			],
			context: [
				{
					d: 'M4 0h5.293A1 1 0 0 1 10 .293L13.707 4a1 1 0 0 1 .293.707V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m5.5 1.5v2a1 1 0 0 0 1 1h2z',
				},
			],
			block: [
				{
					'fill-rule': 'evenodd',
					d: 'M15.528 2.973a.75.75 0 0 1 .472.696v8.662a.75.75 0 0 1-.472.696l-7.25 2.9a.75.75 0 0 1-.557 0l-7.25-2.9A.75.75 0 0 1 0 12.331V3.669a.75.75 0 0 1 .471-.696L7.443.184l.004-.001.274-.11a.75.75 0 0 1 .558 0l.274.11.004.001zm-1.374.527L8 5.962 1.846 3.5 1 3.839v.4l6.5 2.6v7.922l.5.2.5-.2V6.84l6.5-2.6v-.4l-.846-.339Z',
				},
			],
			copy: [
				{
					'fill-rule': 'evenodd',
					d: 'M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z',
				},
			],
			camera: [
				{ d: 'M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0' },
				{
					d: 'M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4zm.5 2a.5.5 0 1 1 0-1 .5.5 0 0 1 0 1m9 2.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0',
				},
			],
			check: [
				{
					d: 'M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z',
				},
			],
			error: [
				{
					d: 'M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2',
				},
			],
			search: [
				{
					d: 'M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0',
				},
			],
			image: [
				{
					d: 'M.002 3a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-12a2 2 0 0 1-2-2zm1 9v1a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062zm5-6.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0',
				},
			],
			write: [
				{
					d: 'M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.5.5 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11z',
				},
			],
			rollback: [
				{
					d: 'M5.921 11.9 1.353 8.62a.72.72 0 0 1 0-1.238L5.921 4.1A.716.716 0 0 1 7 4.719V6c1.5 0 6 0 7 8-2.5-4.5-7-4-7-4v1.281c0 .56-.606.898-1.079.62z',
				},
			],
			chevron: [
				{
					'fill-rule': 'evenodd',
					d: 'M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708',
				},
			],
			stop: [
				{
					d: 'M5 3.5h6A1.5 1.5 0 0 1 12.5 5v6a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 11V5A1.5 1.5 0 0 1 5 3.5',
				},
			],
			sparkle: [
				{
					d: 'M7.657 6.247c.11-.33.576-.33.686 0l.645 1.937a2.89 2.89 0 0 0 1.829 1.828l1.936.645c.33.11.33.576 0 .686l-1.937.645a2.89 2.89 0 0 0-1.828 1.829l-.645 1.936a.361.361 0 0 1-.686 0l-.645-1.937a2.89 2.89 0 0 0-1.828-1.828l-1.937-.645a.361.361 0 0 1 0-.686l1.937-.645a2.89 2.89 0 0 0 1.828-1.828zM3.794 1.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387A1.73 1.73 0 0 0 4.593 5.69l-.387 1.162a.217.217 0 0 1-.412 0L3.407 5.69A1.73 1.73 0 0 0 2.31 4.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387A1.73 1.73 0 0 0 3.407 2.31zM10.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732L9.1 2.137a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z',
				},
			],
			down: [
				{
					'fill-rule': 'evenodd',
					d: 'M8 1a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L7.5 13.293V1.5A.5.5 0 0 1 8 1',
				},
			],
			document: [
				{
					d: 'M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1M4.5 9a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1zM4 10.5a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5m.5 2.5a.5.5 0 0 1 0-1h4a.5.5 0 0 1 0 1z',
				},
			],
			plugin: [
				{
					d: 'M3.112 3.645A1.5 1.5 0 0 1 4.605 2H7a.5.5 0 0 1 .5.5v.382c0 .696-.497 1.182-.872 1.469a.5.5 0 0 0-.115.118l-.012.025L6.5 4.5v.003l.003.01q.005.015.036.053a.9.9 0 0 0 .27.194C7.09 4.9 7.51 5 8 5c.492 0 .912-.1 1.19-.24a.9.9 0 0 0 .271-.194.2.2 0 0 0 .036-.054l.003-.01v-.008l-.012-.025a.5.5 0 0 0-.115-.118c-.375-.287-.872-.773-.872-1.469V2.5A.5.5 0 0 1 9 2h2.395a1.5 1.5 0 0 1 1.493 1.645L12.645 6.5h.237c.195 0 .42-.147.675-.48.21-.274.528-.52.943-.52.568 0 .947.447 1.154.862C15.877 6.807 16 7.387 16 8s-.123 1.193-.346 1.638c-.207.415-.586.862-1.154.862-.415 0-.733-.246-.943-.52-.255-.333-.48-.48-.675-.48h-.237l.243 2.855A1.5 1.5 0 0 1 11.395 14H9a.5.5 0 0 1-.5-.5v-.382c0-.696.497-1.182.872-1.469a.5.5 0 0 0 .115-.118l.012-.025.001-.006v-.003l-.003-.01a.2.2 0 0 0-.036-.053.9.9 0 0 0-.27-.194C8.91 11.1 8.49 11 8 11s-.912.1-1.19.24a.9.9 0 0 0-.271.194.2.2 0 0 0-.036.054l-.003.01v.002l.001.006.012.025c.016.027.05.068.115.118.375.287.872.773.872 1.469v.382a.5.5 0 0 1-.5.5H4.605a1.5 1.5 0 0 1-1.493-1.645L3.356 9.5h-.238c-.195 0-.42.147-.675.48-.21.274-.528.52-.943.52-.568 0-.947-.447-1.154-.862C.123 9.193 0 8.613 0 8s.123-1.193.346-1.638C.553 5.947.932 5.5 1.5 5.5c.415 0 .733.246.943.52.255.333.48.48.675.48h.238z',
				},
			],
			clock: [
				{
					d: 'M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z',
				},
			],
		};
		const svg = document.createElementNS(
			'http://www.w3.org/2000/svg',
			'svg'
		);
		Object.entries( {
			viewBox: '0 0 16 16',
			fill: 'currentColor',
			'aria-hidden': 'true',
			focusable: 'false',
		} ).forEach( ( [ key, value ] ) => svg.setAttribute( key, value ) );
		( paths[ name ] || paths.context ).forEach( ( attributes ) => {
			const path = document.createElementNS( svg.namespaceURI, 'path' );
			Object.entries( attributes ).forEach( ( [ key, value ] ) =>
				path.setAttribute( key, value )
			);
			svg.appendChild( path );
		} );
		return svg;
	}
	function iconButton( label, className, action, name, id ) {
		const node = button( '', className, action, id );
		node.appendChild( icon( name ) );
		node.setAttribute( 'aria-label', label );
		node.title = label;
		return node;
	}
	function viewId( key ) {
		return (
			'bhranti-view-' +
			encodeURIComponent(
				( ( state.session && state.session.id ) || 'new' ) + ':' + key
			).replace( /%/g, '-' )
		);
	}
	function disclosure(
		key,
		className,
		summary,
		children,
		defaultOpen = false
	) {
		const id = viewId( key ),
			node = element( 'details', className );
		node.id = id;
		summary.id = id + '-summary';
		node.open = disclosureStates.has( id )
			? disclosureStates.get( id )
			: defaultOpen;
		node.dataset.bhrantiDisclosure = id;
		node.dataset.openAtRender = String( node.open );
		let previous = node.open;
		node.addEventListener( 'toggle', () => {
			if ( node.open !== previous ) {
				disclosureStates.set( id, node.open );
				previous = node.open;
				node.dataset.openAtRender = String( node.open );
			}
		} );
		node.appendChild( summary );
		children.forEach( ( child ) => {
			if ( child ) node.appendChild( child );
		} );
		return node;
	}
	function syncChildren( parent, children ) {
		const existing = new Map(
			Array.from( parent.children )
				.filter( ( node ) => node.id )
				.map( ( node ) => [ node.id, node ] )
		);
		children.forEach( ( next, index ) => {
			const current = existing.get( next.id ),
				node = current && current.isEqualNode( next ) ? current : next;
			if ( parent.children[ index ] !== node )
				parent.insertBefore( node, parent.children[ index ] || null );
		} );
		while ( parent.children.length > children.length )
			parent.lastElementChild.remove();
	}
	function statusIcon( name, label, className = '' ) {
		const node = element( 'span', className, icon( name ) );
		node.setAttribute( 'role', 'img' );
		node.setAttribute( 'aria-label', label );
		return node;
	}
	async function request( path, method = 'GET', data ) {
		if ( ! wp.apiFetch )
			return Promise.reject(
				new Error(
					__(
						'WordPress could not load the request tools. Reload this page to try again.',
						'bhranti'
					)
				)
			);
		const options = {
			path: apiPath + path,
			method,
			...( data === undefined ? {} : { data } ),
		};
		if ( method !== 'GET' ) {
			const controller = new AbortController();
			let timer;
			try {
				// Aborting a response cannot undo the server operation. Reconcile by GET; never replay this request.
				return await Promise.race( [
					Promise.resolve().then( () =>
						wp.apiFetch( { ...options, signal: controller.signal } )
					),
					new Promise( ( resolve, reject ) => {
						timer = window.setTimeout( () => {
							reject(
								Object.assign(
									new Error(
										__(
											'The response took too long. Checking the saved task before continuing.',
											'bhranti'
										)
									),
									{ code: 'bhranti_response_timeout' }
								)
							);
							controller.abort();
						}, 240000 );
					} ),
				] );
			} finally {
				window.clearTimeout( timer );
			}
		}
		let reconnecting = false;
		try {
			for ( let attempt = 0; attempt < 3; attempt++ ) {
				const controller = new AbortController(),
					timer = window.setTimeout(
						() => controller.abort(),
						20000
					);
				try {
					const result = await wp.apiFetch( {
						...options,
						signal: controller.signal,
					} );
					state.connectionError = '';
					return result;
				} catch ( error ) {
					const status = Number(
						error && error.data && error.data.status
					);
					if ( ( status >= 400 && status < 500 ) || pageSuspended )
						throw error;
					if ( attempt === 2 ) {
						state.connectionError = __(
							'Connection interrupted. Your conversation and saved work are safe.',
							'bhranti'
						);
						throw new Error( state.connectionError );
					}
					if ( ! reconnecting ) {
						reconnecting = true;
						state.reconnecting++;
						if ( state.open ) render();
					}
				} finally {
					window.clearTimeout( timer );
				}
				await wait( ( attempt + 1 ) * 1500 );
				if ( pageSuspended )
					throw new Error(
						__( 'This page is no longer active.', 'bhranti' )
					);
			}
		} finally {
			if ( reconnecting ) {
				state.reconnecting--;
				if ( state.open ) render();
			}
		}
	}
	function updateElapsed() {
		const node = root.querySelector( '.bhranti-elapsed' );
		if ( node && state.progress ) {
			const seconds = Math.floor(
				( Date.now() - state.progress.started ) / 1000
			);
			node.textContent = sprintf( __( '%ds', 'bhranti' ), seconds );
			node.setAttribute(
				'aria-label',
				sprintf(
					__( 'Elapsed request time: %d seconds', 'bhranti' ),
					seconds
				)
			);
		}
	}
	async function runRequest( path, data, label ) {
		const action =
			/\/execute$/.test( path ) &&
			currentRun() &&
			currentRun().phase === 'prepared' &&
			data.run_id === currentRun().id &&
			data.prepared_step === currentRun().step
				? { key: resultKey(), step: data.prepared_step }
				: null;
		if ( action ) pendingAction = action;
		state.progress = { label, started: Date.now() };
		render();
		progressTimer = window.setInterval( updateElapsed, 1000 );
		try {
			const result = await request( path, 'POST', data );
			if ( pendingAction === action ) pendingAction = null;
			return result;
		} catch ( error ) {
			if (
				pendingAction === action &&
				[
					'bhranti_unsaved_changes',
					'bhranti_editor_busy',
					'bhranti_busy',
					'bhranti_run_missing',
				].includes( error.code )
			)
				pendingAction = null;
			throw error;
		} finally {
			window.clearInterval( progressTimer );
			progressTimer = null;
			state.progress = null;
		}
	}
	const wait = ( milliseconds ) =>
		new Promise( ( resolve ) =>
			window.setTimeout( resolve, milliseconds )
		);
	function errorMessage( error ) {
		return error && typeof error.message === 'string'
			? error.message
			: __( 'Something went wrong. Please try again.', 'bhranti' );
	}
	function notify( message, type = 'error' ) {
		if (
			state.notices.some(
				( notice ) => notice.message === message && notice.type === type
			)
		)
			return;
		const id = 'bhranti-' + ++noticeSequence;
		state.notices.push( { id, message, type } );
		if ( state.notices.length > 3 ) dismissNotice( state.notices[ 0 ].id );
		if ( wp.data && wp.notices )
			wp.data
				.dispatch( wp.notices.store || 'core/notices' )
				.createNotice( type, message, {
					id,
					context: 'bhranti',
					isDismissible: true,
				} );
	}
	function dismissNotice( id ) {
		state.notices = state.notices.filter( ( notice ) => notice.id !== id );
		if ( wp.data && wp.notices )
			wp.data
				.dispatch( wp.notices.store || 'core/notices' )
				.removeNotice( id, 'bhranti' );
	}
	function rememberSession( id ) {
		try {
			if ( id ) window.sessionStorage.setItem( storageKey, String( id ) );
			else window.sessionStorage.removeItem( storageKey );
		} catch ( error ) {
			/* Private browsing may disable session storage. */
		}
		const url = new URL( window.location.href );
		if (
			url.searchParams.has( 'bhranti_session' ) ||
			url.searchParams.has( 'session' )
		) {
			url.searchParams.delete( 'session' );
			if ( id ) url.searchParams.set( 'bhranti_session', id );
			else url.searchParams.delete( 'bhranti_session' );
			if ( url.href !== window.location.href )
				window.history.replaceState( null, '', url );
		}
	}
	function savedSession() {
		try {
			return window.sessionStorage.getItem( storageKey );
		} catch ( error ) {
			return null;
		}
	}
	function draftKey( id ) {
		return openStorageKey + ':draft:' + ( id || 'new' );
	}
	function rememberDraft() {
		const key = draftKey( state.session && state.session.id );
		state.drafts.set( key, state.draft );
		if ( state.screenshot ) state.screenshots.set( key, state.screenshot );
		else state.screenshots.delete( key );
		try {
			if (
				state.draft &&
				credentialDrafts.get( key ) !== state.draft &&
				! containsCredentials( state.draft )
			)
				window.sessionStorage.setItem( key, state.draft );
			else window.sessionStorage.removeItem( key );
		} catch ( error ) {
			/* In-memory drafts still survive chat switches. */
		}
	}
	function restoreDraft( id ) {
		const key = draftKey( id );
		if ( state.drafts.has( key ) ) return state.drafts.get( key );
		try {
			const draft = ( window.sessionStorage.getItem( key ) || '' ).slice(
				0,
				12000
			);
			if ( containsCredentials( draft ) ) {
				state.drafts.set( key, draft );
				try {
					window.sessionStorage.removeItem( key );
				} catch ( error ) {
					/* Keep the recovered draft in memory when storage is read-only. */
				}
			}
			return draft;
		} catch ( error ) {
			return '';
		}
	}
	function retainedReferenceImage() {
		const run = currentRun(),
			reference = run && run.reference_image;
		return run &&
			run.status === 'awaiting_input' &&
			reference &&
			typeof reference.message_id === 'string' &&
			/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
				reference.message_id
			)
			? reference
			: null;
	}
	function referenceRemovalKey( id ) {
		return draftKey( id ) + ':discard-reference';
	}
	function referenceImageRemoved() {
		const reference = retainedReferenceImage();
		if ( ! reference || ! state.session ) return false;
		const key = referenceRemovalKey( state.session.id );
		if ( ! referenceRemovals.has( key ) ) {
			try {
				referenceRemovals.set(
					key,
					window.sessionStorage.getItem( key )
				);
			} catch ( error ) {
				/* In-memory removal still survives chat switches. */
			}
		}
		return referenceRemovals.get( key ) === reference.message_id;
	}
	function removeReferenceImage() {
		if ( state.busy || state.loading || state.capturing || activeRun() )
			return;
		const reference = retainedReferenceImage();
		if ( ! reference || ! state.session ) return;
		const key = referenceRemovalKey( state.session.id );
		referenceRemovals.set( key, reference.message_id );
		try {
			window.sessionStorage.setItem( key, reference.message_id );
		} catch ( error ) {
			/* No image data is persisted in browser storage. */
		}
		render();
	}
	function clearReferenceRemoval( id ) {
		const key = referenceRemovalKey( id );
		referenceRemovals.delete( key );
		try {
			window.sessionStorage.removeItem( key );
		} catch ( error ) {
			/* The in-memory state is still reset. */
		}
	}
	function currentRun() {
		return state.session && state.session.run;
	}
	function activeRun() {
		const run = currentRun();
		return (
			run && [ 'running', 'awaiting_confirmation' ].includes( run.status )
		);
	}
	function actionInFlight( run ) {
		return !! (
			run &&
			( run.phase === 'action' ||
				( pendingAction &&
					pendingAction.key === resultKey() &&
					pendingAction.step === run.step ) )
		);
	}
	function reconcileActionControl() {
		const run = currentRun();
		if (
			renderController &&
			( ! state.open ||
				! run ||
				run.phase !== 'browser' ||
				run.status !== 'running' )
		)
			abortRender();
		if (
			pendingAction &&
			( ! run ||
				pendingAction.key !== resultKey() ||
				run.status !== 'running' ||
				run.step > pendingAction.step ||
				! [ 'prepared', 'action' ].includes( run.phase ) )
		)
			pendingAction = null;
		if (
			( stopRunKey && stopRunKey !== resultKey() ) ||
			( run && ! activeRun() )
		) {
			state.stopRequested = false;
			state.pausedForEditor = false;
			stopRunKey = '';
		}
	}
	function editorIsOpen() {
		if (
			/\/(?:post|post-new|site-editor)\.php$/.test(
				window.location.pathname
			) ||
			[ 'post', 'page', 'site-editor' ].includes(
				( settings.context || {} ).screen
			)
		)
			return true;
		try {
			const editor = wp.data && wp.data.select( 'core/editor' );
			if (
				editor &&
				editor.getCurrentPostId &&
				editor.getCurrentPostId()
			)
				return true;
			const site = wp.data && wp.data.select( 'core/edit-site' );
			return !! (
				site &&
				site.getEditedPostId &&
				site.getEditedPostId()
			);
		} catch ( error ) {
			return false;
		}
	}
	function editorState() {
		const active = editorIsOpen();
		let dirty = false,
			native = false;
		if ( active ) {
			try {
				const editor = wp.data && wp.data.select( 'core/editor' );
				dirty = !! (
					editor &&
					( ( editor.isEditedPostDirty &&
						editor.isEditedPostDirty() ) ||
						( editor.isSavingPost && editor.isSavingPost() ) ||
						( editor.isAutosavingPost &&
							editor.isAutosavingPost() ) )
				);
				const site = wp.data && wp.data.select( 'core/edit-site' ),
					core = wp.data && wp.data.select( 'core' );
				const id =
					( editor &&
						editor.getCurrentPostId &&
						editor.getCurrentPostId() ) ||
					( site && site.getEditedPostId && site.getEditedPostId() );
				const type =
					( editor &&
						editor.getCurrentPostType &&
						editor.getCurrentPostType() ) ||
					( site &&
						site.getEditedPostType &&
						site.getEditedPostType() );
				if ( core && core.hasEditsForEntityRecord && id && type ) {
					native = true;
					dirty =
						dirty ||
						core.hasEditsForEntityRecord( 'postType', type, id );
				}
				if ( core && core.isSavingEntityRecord && id && type )
					dirty =
						dirty ||
						core.isSavingEntityRecord( 'postType', type, id );
				if (
					core &&
					typeof core.__experimentalGetDirtyEntityRecords ===
						'function'
				)
					dirty =
						dirty ||
						core.__experimentalGetDirtyEntityRecords().length > 0;
			} catch ( error ) {
				/* Classic editors use the native autosave change detector below. */
			}
			try {
				if (
					! native &&
					wp.autosave &&
					wp.autosave.server &&
					wp.autosave.server.postChanged
				)
					dirty = dirty || wp.autosave.server.postChanged();
			} catch ( error ) {
				/* The autosave module may not be initialized yet. */
			}
		}
		const form = document.getElementById( 'bhranti-settings-app' );
		const formBusy = !! form && form.dataset.bhrantiBusy !== 'false';
		return {
			editor_active: active,
			unsaved_changes: !! (
				dirty ||
				pageFormDirty ||
				formBusy ||
				( form && form.dataset.bhrantiDirty === 'true' )
			),
		};
	}
	function nativeEditorSnapshot() {
		try {
			if ( ! wp.data || ! wp.apiFetch || window._wpCollaborationEnabled )
				return null;
			const editor = wp.data.select( 'core/editor' ),
				core = wp.data.select( 'core' ),
				blocks = wp.data.select( 'core/block-editor' ),
				dispatch = wp.data.dispatch( 'core' );
			if (
				! editor ||
				! core ||
				! dispatch ||
				! [
					'getCurrentPostId',
					'getCurrentPostType',
					'isEditedPostDirty',
					'isSavingPost',
					'isAutosavingPost',
				].every( ( key ) => typeof editor[ key ] === 'function' ) ||
				! [
					'getRawEntityRecord',
					'getEntityRecordEdits',
					'hasEditsForEntityRecord',
					'isSavingEntityRecord',
				].every( ( key ) => typeof core[ key ] === 'function' ) ||
				typeof dispatch.receiveEntityRecords !== 'function'
			)
				return null;
			const id = Number( editor.getCurrentPostId() ),
				type = editor.getCurrentPostType();
			if (
				! Number.isSafeInteger( id ) ||
				id < 1 ||
				! [ 'post', 'page' ].includes( type )
			)
				return null;
			const record = core.getRawEntityRecord( 'postType', type, id ),
				edits = core.getEntityRecordEdits( 'postType', type, id );
			if ( ! record ) return null;
			const focused = document.activeElement;
			const editing = !! (
				( blocks &&
					( ( typeof blocks.isDraggingBlocks === 'function' &&
						blocks.isDraggingBlocks() ) ||
						( typeof blocks.isMultiSelecting === 'function' &&
							blocks.isMultiSelecting() ) ) ) ||
				( focused &&
					! root.contains( focused ) &&
					( focused.tagName === 'IFRAME' ||
						( focused.closest &&
							focused.closest(
								'[contenteditable="true"], textarea, input:not([type="button"]):not([type="submit"]):not([type="checkbox"]):not([type="radio"]):not([type="hidden"])'
							) ) ) )
			);
			return {
				id,
				type,
				record,
				edits,
				dirty: !! (
					editor.isEditedPostDirty() ||
					core.hasEditsForEntityRecord( 'postType', type, id ) ||
					editorState().unsaved_changes
				),
				saving: !! (
					editor.isSavingPost() ||
					editor.isAutosavingPost() ||
					core.isSavingEntityRecord( 'postType', type, id )
				),
				editing,
			};
		} catch ( error ) {
			return null;
		}
	}
	function releaseEditorControl() {
		if ( ! editorControl ) return;
		const control = editorControl;
		editorControl = null;
		if ( control.observer ) control.observer.disconnect();
		window.removeEventListener( 'resize', positionEditorControl );
		[ 'inert', 'aria-busy' ].forEach( ( name ) => {
			if ( control.attributes[ name ] === null )
				control.editor.removeAttribute( name );
			else
				control.editor.setAttribute( name, control.attributes[ name ] );
		} );
		control.overlay.remove();
		syncTrigger();
	}
	function positionEditorControl() {
		if ( ! editorControl ) return;
		const rect = editorControl.frame.getBoundingClientRect();
		Object.assign( editorControl.overlay.style, {
			top: rect.top + 'px',
			left: rect.left + 'px',
			width: rect.width + 'px',
			height: rect.height + 'px',
		} );
		document.dispatchEvent(
			new CustomEvent( 'bhranti:editor-launcher-position' )
		);
	}
	function syncPostControl() {
		const run = currentRun(),
			snapshot = nativeEditorSnapshot(),
			editor = document.querySelector(
				'#editor.block-editor__container'
			),
			frame =
				editor &&
				editor.querySelector( '.interface-interface-skeleton' );
		const key =
			snapshot && resultKey() + ':' + snapshot.type + ':' + snapshot.id;
		const inFlight =
			actionInFlight( run ) && editorControl && editorControl.key === key;
		if (
			pageSuspended ||
			! run ||
			run.status !== 'running' ||
			run.interrupted ||
			( ( state.error || state.editorError ) && ! inFlight ) ||
			state.pausedForEditor ||
			( ! state.open && ! inFlight ) ||
			! snapshot ||
			snapshot.dirty ||
			snapshot.saving ||
			! editor ||
			! frame
		) {
			releaseEditorControl();
			return;
		}
		const focused = document.activeElement,
			blocks = wp.data.select( 'core/block-editor' );
		const gesturing =
			blocks &&
			( ( typeof blocks.isDraggingBlocks === 'function' &&
				blocks.isDraggingBlocks() ) ||
				( typeof blocks.isMultiSelecting === 'function' &&
					blocks.isMultiSelecting() ) );
		if (
			gesturing ||
			( snapshot.editing &&
				( ! focused ||
					focused.tagName !== 'IFRAME' ||
					! editor.contains( focused ) ) )
		) {
			releaseEditorControl();
			return;
		}
		const matches = ( target ) =>
			target &&
			Number( target.id ) === snapshot.id &&
			target.type === snapshot.type &&
			safeTarget( target );
		const action = run.next_action,
			path =
				'/wp/v2/' +
				( snapshot.type === 'page' ? 'pages' : 'posts' ) +
				'/' +
				snapshot.id;
		const prepared =
			action &&
			action.site_action !== false &&
			( ( action.tool === 'wp_request' &&
				( action.write === true || action.method === 'GET' ) ) ||
				( [ 'open_item', 'bhranti_inspect' ].includes( action.tool ) &&
					action.method === 'GET' ) ) &&
			action.path === path &&
			matches( action.target );
		const committed = ( state.session.events || [] ).some(
			( event ) =>
				event.type === 'action' &&
				event.metadata_only !== true &&
				event.success === true &&
				event.run_id === run.id &&
				[ 'read', 'write', 'rollback' ].includes( event.operation ) &&
				matches( event.target )
		);
		if ( ! prepared && ! committed ) {
			releaseEditorControl();
			return;
		}
		// Native dialogs can be portalled outside the editor; leave their human interaction intact.
		if (
			Array.from(
				document.querySelectorAll(
					'.components-modal__screen-overlay, .media-modal, .components-popover [role="dialog"]'
				)
			).some(
				( node ) =>
					! root.contains( node ) &&
					! node.hidden &&
					node.getClientRects().length
			)
		) {
			releaseEditorControl();
			return;
		}
		if (
			editorControl &&
			editorControl.key === key &&
			editorControl.editor === editor &&
			editorControl.frame === frame
		) {
			positionEditorControl();
			return;
		}
		releaseEditorControl();
		const heading = element(
			'h2',
			'',
			snapshot.type === 'page'
				? __( 'Page is under Bhranti’s control', 'bhranti' )
				: __( 'Post is under Bhranti’s control', 'bhranti' )
		);
		const overlay = element( 'div', 'bhranti-editor-control', heading );
		overlay.setAttribute( 'role', 'status' );
		overlay.setAttribute( 'aria-live', 'polite' );
		overlay.tabIndex = -1;
		editorControl = {
			key,
			editor,
			frame,
			overlay,
			attributes: {
				inert: editor.getAttribute( 'inert' ),
				'aria-busy': editor.getAttribute( 'aria-busy' ),
			},
			observer: null,
		};
		document.body.appendChild( overlay );
		// Move clean canvas focus out before inert; otherwise its iframe can defer saved updates.
		if ( editor.contains( document.activeElement ) )
			overlay.focus( { preventScroll: true } );
		editor.setAttribute( 'inert', '' );
		editor.setAttribute( 'aria-busy', 'true' );
		if ( window.ResizeObserver ) {
			editorControl.observer = new window.ResizeObserver(
				positionEditorControl
			);
			editorControl.observer.observe( frame );
		}
		window.addEventListener( 'resize', positionEditorControl );
		positionEditorControl();
		syncTrigger();
	}
	function releaseSiteControl() {
		if ( ! siteControl ) return;
		const control = siteControl;
		siteControl = null;
		if ( control.observer ) control.observer.disconnect();
		window.removeEventListener( 'resize', positionSiteControl );
		window.removeEventListener( 'scroll', positionSiteControl, true );
		control.nodes.forEach( ( { node, attributes } ) => {
			[ 'inert', 'aria-busy' ].forEach( ( name ) => {
				if ( attributes[ name ] === null ) node.removeAttribute( name );
				else node.setAttribute( name, attributes[ name ] );
			} );
		} );
		control.overlay.remove();
		if ( control.opener ) control.opener.remove();
		document.body.classList.remove( 'bhranti-site-controlled' );
		syncTrigger();
	}
	function positionSiteControl() {
		if ( ! siteControl ) return;
		if ( siteControl.admin ) {
			siteControl.opener.hidden = state.open;
			const width = window.innerWidth,
				right = state.open
					? Math.max(
							0,
							Math.min( width, root.getBoundingClientRect().left )
					  )
					: width;
			Object.assign( siteControl.overlay.style, {
				top: '0px',
				left: '0px',
				width: width + 'px',
				height: window.innerHeight + 'px',
				paddingRight: Math.min( width - 24, width - right + 24 ) + 'px',
			} );
			siteControl.overlay.firstElementChild.hidden = right < 120;
			const trigger = document.querySelector(
					'#wp-admin-bar-bhranti > a'
				),
				rect = trigger && trigger.getClientRects()[ 0 ];
			Object.assign( siteControl.opener.style, {
				top: rect ? Math.max( 0, rect.top ) + 'px' : '8px',
				left: rect
					? Math.max( 0, Math.min( width - 90, rect.left ) ) + 'px'
					: 'auto',
				right: rect ? 'auto' : '12px',
			} );
			return;
		}
		const rect = siteControl.frame.getBoundingClientRect(),
			bar = document.getElementById( 'wpadminbar' );
		const top = Math.max(
				0,
				rect.top,
				bar && triggerAvailable( bar )
					? bar.getBoundingClientRect().bottom
					: 0
			),
			left = Math.max( 0, rect.left );
		const right = Math.min(
				window.innerWidth,
				rect.right,
				state.open
					? root.getBoundingClientRect().left
					: window.innerWidth
			),
			bottom = Math.min( window.innerHeight, rect.bottom );
		Object.assign( siteControl.overlay.style, {
			top: top + 'px',
			left: left + 'px',
			width: Math.max( 0, right - left ) + 'px',
			height: Math.max( 0, bottom - top ) + 'px',
		} );
	}
	function syncSiteControl() {
		const run = currentRun(),
			key = resultKey(),
			inFlight =
				actionInFlight( run ) && siteControl && siteControl.key === key;
		const postType = ( settings.context || {} ).post_type;
		const postEditor =
			nativeEditorSnapshot() ||
			( /\/(?:post|post-new)\.php$/.test( window.location.pathname ) &&
				( ! postType || [ 'post', 'page' ].includes( postType ) ) );
		const proven =
			run &&
			( ( run.next_action && run.next_action.site_action !== false ) ||
				( ! run.next_action &&
					[ 'action', 'tool' ].includes( run.phase ) ) ||
				( state.session.events || [] ).some(
					( event ) =>
						event.type === 'action' &&
						event.metadata_only !== true &&
						event.success === true &&
						event.run_id === run.id
				) );
		if (
			pageSuspended ||
			editorControl ||
			postEditor ||
			! run ||
			run.status !== 'running' ||
			run.interrupted ||
			! proven ||
			( ( state.error || state.editorError || state.stopRequested ) &&
				! inFlight ) ||
			state.pausedForEditor ||
			( ! state.open && ! inFlight ) ||
			editorState().unsaved_changes
		) {
			releaseSiteControl();
			return;
		}
		const frame = settings.isAdmin
			? document.querySelector( '.edit-site-layout' ) ||
			  document.querySelector( '#editor.block-editor__container' ) ||
			  document.querySelector( '#wpbody-content' )
			: Array.from(
					document.querySelectorAll(
						'.wp-site-blocks, #page, main, [role="main"]'
					)
			  ).find(
					( node ) =>
						! node.contains( root ) &&
						! node.querySelector( '#wpadminbar' )
			  );
		const nodes = [
			...new Set( [
				frame,
				...( settings.isAdmin
					? document.querySelectorAll(
							'#wpcontent, #adminmenumain, #adminmenu, #wpadminbar, #wpfooter'
					  )
					: [] ),
			] ),
		].filter(
			( node ) =>
				node && ! node.contains( root ) && ! root.contains( node )
		);
		const focused = document.activeElement;
		let gesturing = false;
		try {
			const blocks = wp.data && wp.data.select( 'core/block-editor' );
			gesturing = !! (
				blocks &&
				( ( blocks.isDraggingBlocks && blocks.isDraggingBlocks() ) ||
					( blocks.isMultiSelecting && blocks.isMultiSelecting() ) )
			);
		} catch ( error ) {
			/* Ordinary admin pages have no block editor. */
		}
		if (
			! frame ||
			gesturing ||
			window._wpCollaborationEnabled ||
			frame.contains( root ) ||
			frame.querySelector( '#wpadminbar' ) ||
			( focused &&
				nodes.some( ( node ) => node.contains( focused ) ) &&
				focused.closest(
					'input, textarea, select, [contenteditable="true"], iframe'
				) ) ||
			Array.from(
				document.querySelectorAll(
					'.components-modal__screen-overlay, .media-modal, .components-popover [role="dialog"]'
				)
			).some(
				( node ) =>
					! root.contains( node ) &&
					! node.hidden &&
					node.getClientRects().length
			)
		) {
			releaseSiteControl();
			return;
		}
		if (
			siteControl &&
			siteControl.key === key &&
			siteControl.frame === frame
		) {
			positionSiteControl();
			return;
		}
		releaseSiteControl();
		const overlay = element(
			'div',
			'bhranti-site-control' + ( settings.isAdmin ? ' is-admin' : '' ),
			element(
				'h2',
				'',
				__( 'Site is under Bhranti’s control', 'bhranti' )
			)
		);
		overlay.setAttribute( 'role', 'status' );
		overlay.setAttribute( 'aria-live', 'polite' );
		overlay.tabIndex = -1;
		const opener = settings.isAdmin
			? element(
					'button',
					'bhranti-site-opener',
					__( 'Bhranti', 'bhranti' )
			  )
			: null;
		if ( opener ) {
			opener.type = 'button';
			opener.id = 'bhranti-site-opener';
			opener.setAttribute( 'data-bhranti-open', '' );
			document.body.appendChild( opener );
			document.body.classList.add( 'bhranti-site-controlled' );
		}
		siteControl = {
			key,
			frame,
			overlay,
			opener,
			admin: !! settings.isAdmin,
			nodes: nodes.map( ( node ) => ( {
				node,
				attributes: {
					inert: node.getAttribute( 'inert' ),
					'aria-busy': node.getAttribute( 'aria-busy' ),
				},
			} ) ),
			observer: null,
		};
		document.body.appendChild( overlay );
		if ( nodes.some( ( node ) => node.contains( focused ) ) )
			overlay.focus( { preventScroll: true } );
		nodes.forEach( ( node ) => {
			node.setAttribute( 'inert', '' );
			node.setAttribute( 'aria-busy', 'true' );
		} );
		if ( window.ResizeObserver ) {
			siteControl.observer = new window.ResizeObserver(
				positionSiteControl
			);
			siteControl.observer.observe( frame );
		}
		window.addEventListener( 'resize', positionSiteControl );
		window.addEventListener( 'scroll', positionSiteControl, true );
		positionSiteControl();
		syncTrigger();
	}
	function syncEditorControl() {
		syncPostControl();
		syncSiteControl();
	}
	function nativeEditorChanged( schedule = true ) {
		syncEditorControl();
		const next = nativeEditorSnapshot(),
			previous = editorSync.snapshot;
		if (
			( next &&
				previous &&
				[
					'id',
					'type',
					'record',
					'edits',
					'dirty',
					'saving',
					'editing',
				].every( ( key ) => next[ key ] === previous[ key ] ) ) ||
			( ! next && ! previous )
		)
			return;
		editorSync.snapshot = next;
		editorSync.generation++;
		if ( editorSync.pending ) {
			editorSync.pending.attempts = 0;
			if ( schedule ) scheduleEditorChanges();
		}
	}
	function scheduleEditorChanges( delay = 0 ) {
		if (
			pageSuspended ||
			editorSync.fetching ||
			editorSync.timer !== null ||
			! editorSync.pending ||
			editorSync.pending.attempts >= 2
		)
			return;
		editorSync.timer = window.setTimeout( () => {
			editorSync.timer = null;
			flushEditorChanges();
		}, delay );
	}
	function observeEditorChanges() {
		const run = currentRun(),
			key = resultKey(),
			snapshot = nativeEditorSnapshot();
		if (
			editorSync.pending &&
			( ! snapshot ||
				editorSync.pending.session !==
					String( ( state.session && state.session.id ) || '' ) ||
				editorSync.pending.id !== snapshot.id ||
				editorSync.pending.type !== snapshot.type )
		) {
			editorSync.pending = null;
			editorSync.message = '';
		}
		if ( editorSync.pending && editorSync.pending.observedRun !== key ) {
			editorSync.pending.observedRun = key;
			editorSync.pending.attempts = 0;
			scheduleEditorChanges();
		}
		if ( ! run || ! key ) return;
		const events = state.session.events || [],
			seen = editorSync.baselines.get( key );
		if ( ! seen ) {
			editorSync.baselines.set(
				key,
				new Set( events.map( ( event ) => String( event.id ) ) )
			);
			return;
		}
		events.forEach( ( event ) => {
			const id = String( event.id || '' );
			if ( ! id || seen.has( id ) ) return;
			seen.add( id );
			if (
				! snapshot ||
				event.type !== 'action' ||
				event.success !== true ||
				event.run_id !== run.id ||
				! [ 'write', 'rollback' ].includes( event.operation ) ||
				! event.target ||
				event.target.type !== snapshot.type ||
				Number( event.target.id ) !== snapshot.id ||
				! safeTarget( event.target )
			)
				return;
			editorSync.pending = {
				key,
				observedRun: key,
				session: String( state.session.id ),
				id: snapshot.id,
				type: snapshot.type,
				event: id,
				attempts: 0,
			};
			editorSync.message = '';
			scheduleEditorChanges();
		} );
	}
	function flushEditorChanges() {
		if ( editorSync.request ) return editorSync.request;
		if ( editorSync.timer !== null ) {
			window.clearTimeout( editorSync.timer );
			editorSync.timer = null;
		}
		const request = ( async () => {
			let pending, generation, key;
			do {
				nativeEditorChanged( false );
				pending = editorSync.pending;
				generation = editorSync.generation;
				key = resultKey();
				await performEditorChanges();
			} while (
				! pageSuspended &&
				editorSync.pending &&
				editorSync.pending.attempts < 2 &&
				( editorSync.pending !== pending ||
					editorSync.generation !== generation ||
					resultKey() !== key )
			);
		} )().finally( () => {
			if ( editorSync.request === request ) editorSync.request = null;
		} );
		editorSync.request = request;
		return request;
	}
	async function performEditorChanges() {
		const pending = editorSync.pending;
		if (
			! pending ||
			pending.attempts >= 2 ||
			editorSync.fetching ||
			pageSuspended
		)
			return;
		nativeEditorChanged( false );
		const snapshot = nativeEditorSnapshot(),
			generation = editorSync.generation,
			runKey = resultKey();
		if (
			! snapshot ||
			pending.session !==
				String( ( state.session && state.session.id ) || '' ) ||
			pending.id !== snapshot.id ||
			pending.type !== snapshot.type
		) {
			editorSync.pending = null;
			editorSync.message = '';
			return;
		}
		if ( snapshot.dirty || snapshot.saving || snapshot.editing ) {
			editorSync.message = __(
				'Saved updates will appear after you finish editing and save or discard your local changes.',
				'bhranti'
			);
			if ( state.open ) render();
			return;
		}
		editorSync.fetching = true;
		pending.attempts++;
		let retry = false,
			controller = null,
			timer = null;
		const stillCurrent = () => {
			const current = nativeEditorSnapshot();
			return (
				! pageSuspended &&
				editorSync.pending === pending &&
				runKey === resultKey() &&
				generation === editorSync.generation &&
				current &&
				[
					'id',
					'type',
					'record',
					'edits',
					'dirty',
					'saving',
					'editing',
				].every( ( key ) => current[ key ] === snapshot[ key ] )
			);
		};
		try {
			// Receiving saved data changes only this clean editor; other tabs keep their own edits.
			controller = new AbortController();
			timer = window.setTimeout( () => controller.abort(), 20000 );
			const record = await wp.apiFetch( {
				path:
					'/wp/v2/' +
					( pending.type === 'page' ? 'pages' : 'posts' ) +
					'/' +
					pending.id +
					'?context=edit',
				method: 'GET',
				signal: controller.signal,
			} );
			if ( ! stillCurrent() ) return;
			if (
				! record ||
				Number( record.id ) !== pending.id ||
				record.type !== pending.type ||
				! record.content ||
				typeof record.content.raw !== 'string' ||
				! record.title ||
				typeof record.title.raw !== 'string' ||
				record.status === 'trash'
			)
				throw new Error( 'Saved editor record is unavailable.' );
			// Receive persisted data, clearing only the unchanged edits from this clean snapshot.
			// Native core-data then reparses saved blocks without creating an unsaved edit.
			wp.data
				.dispatch( 'core' )
				.receiveEntityRecords(
					'postType',
					pending.type,
					record,
					undefined,
					true,
					snapshot.edits
				);
			editorSync.pending = null;
			editorSync.message = '';
		} catch ( error ) {
			if ( stillCurrent() ) {
				retry =
					! error.data ||
					! [ 401, 403, 404 ].includes( Number( error.data.status ) );
				editorSync.message = __(
					'The editor could not load the latest saved version yet.',
					'bhranti'
				);
				if ( ! retry ) pending.attempts = 2;
			}
		} finally {
			if ( timer !== null ) window.clearTimeout( timer );
			editorSync.fetching = false;
			if ( state.open ) render();
			if (
				editorSync.pending &&
				( editorSync.pending !== pending ||
					editorSync.generation !== generation ||
					resultKey() !== runKey ||
					retry )
			)
				scheduleEditorChanges( retry ? 2000 : 0 );
		}
	}
	function editorToken() {
		const key = openStorageKey + ':editor-token';
		try {
			const saved = window.sessionStorage.getItem( key );
			if (
				saved &&
				/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(
					saved
				)
			)
				return saved;
		} catch ( error ) {
			/* A memory-only token still protects this tab. */
		}
		const token = window.crypto.randomUUID
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
		try {
			window.sessionStorage.setItem( key, token );
		} catch ( error ) {
			/* Storage can be unavailable. */
		}
		return token;
	}
	const heartbeatToken =
		settings.isAdmin && settings.backgroundEnabled ? editorToken() : null;
	function editorSummary() {
		return state.editor || ( currentRun() && currentRun().editor ) || {};
	}
	async function reportEditor(
		releaseStale = false,
		fresh = false,
		retryPending = true
	) {
		if ( ! heartbeatToken || pageSuspended ) return editorSummary();
		const local = editorState();
		if ( releaseStale && local.unsaved_changes ) return editorSummary();
		if ( heartbeatRequest ) {
			if ( fresh || releaseStale ) {
				try {
					await heartbeatRequest;
				} catch ( error ) {
					if (
						! fresh ||
						releaseStale ||
						! retryPending ||
						pageSuspended
					)
						throw error;
				}
				// A failed earlier heartbeat cannot replace this write's fresh safety check.
				return reportEditor( releaseStale, true, false );
			}
			if ( heartbeatFingerprint !== JSON.stringify( local ) )
				heartbeatQueued = true;
			return heartbeatRequest;
		}
		heartbeatFingerprint = JSON.stringify( local );
		heartbeatRequest = ( async () => {
			const controller = new AbortController(),
				timer = window.setTimeout( () => controller.abort(), 20000 );
			try {
				const result = await wp.apiFetch( {
					path: apiPath + '/editor-state',
					method: 'POST',
					data: {
						token: heartbeatToken,
						...local,
						...( releaseStale ? { release_stale: true } : {} ),
					},
					signal: controller.signal,
				} );
				const previous = JSON.stringify( editorSummary() ),
					previousDirty = !! editorSummary().dirty,
					previousError = state.editorError;
				state.editor = {
					write_blocked: !! result.write_blocked,
					active: !! result.active,
					dirty: !! result.dirty,
					stale_dirty: !! result.stale_dirty,
				};
				state.editorError = '';
				if ( previousError || previousDirty !== state.editor.dirty )
					editorSync.generation++;
				if (
					( previousError ||
						previous !== JSON.stringify( state.editor ) ) &&
					! state.editor.dirty &&
					editorSync.pending
				) {
					editorSync.pending.attempts = 0;
					scheduleEditorChanges();
				}
				if ( state.open && previous !== JSON.stringify( state.editor ) )
					render();
				else syncTrigger();
				return state.editor;
			} catch ( error ) {
				if ( ! state.editorError ) editorSync.generation++;
				state.editorError = __(
					'Editor safety could not be checked. Try Resume before continuing a site change.',
					'bhranti'
				);
				if ( state.open ) render();
				throw error;
			} finally {
				window.clearTimeout( timer );
				heartbeatRequest = null;
				if ( heartbeatQueued && ! pageSuspended ) {
					heartbeatQueued = false;
					reportEditor().catch( () => {} );
				}
			}
		} )();
		return heartbeatRequest;
	}
	function startHeartbeat() {
		if ( ! heartbeatToken || heartbeatTimer || pageSuspended ) return;
		reportEditor().catch( () => {} );
		heartbeatTimer = window.setInterval(
			() => reportEditor().catch( () => {} ),
			15000
		);
	}
	function scheduleBackgroundObservation( initial = false ) {
		window.clearTimeout( backgroundTimer );
		backgroundTimer = null;
		const run = currentRun();
		if (
			blockedObservation &&
			( ! run ||
				blockedObservation.key !== resultKey() ||
				run.status !== 'running' )
		)
			blockedObservation = null;
		if (
			state.open &&
			state.pausedForEditor &&
			run &&
			run.status === 'running' &&
			! blockedObservation
		)
			blockedObservation = {
				key: resultKey(),
				runId: run.id,
				step: run.step,
				started: Date.now(),
				stopped: false,
			};
		if (
			blockedObservation &&
			! blockedObservation.stopped &&
			Date.now() - blockedObservation.started >= 600000
		) {
			blockedObservation.stopped = true;
			state.connectionError = __(
				'Latest task status is unavailable. Reconnect to check the saved progress.',
				'bhranti'
			);
			if ( state.open ) render();
		}
		if (
			! settings.backgroundEnabled ||
			pageSuspended ||
			( state.open &&
				( ! blockedObservation || blockedObservation.stopped ) ) ||
			( ! initial &&
				( ! run || run.status !== 'running' || ! run.background ) )
		)
			return;
		backgroundTimer = window.setTimeout(
			observeBackground,
			initial ? 0 : 4000
		);
	}
	async function observeBackground() {
		backgroundTimer = null;
		const id = ( state.session && state.session.id ) || savedSession(),
			observation = state.open ? blockedObservation : null;
		if (
			( state.open &&
				( ! observation ||
					observation.stopped ||
					observation.key !== resultKey() ) ) ||
			pageSuspended ||
			backgroundReading ||
			! id ||
			! /^[1-9]\d*$/.test( String( id ) )
		)
			return;
		if ( state.busy || state.stepping ) {
			scheduleBackgroundObservation();
			return;
		}
		backgroundReading = true;
		let continueBrowser = false;
		try {
			const session = await request(
				'/sessions/' + encodeURIComponent( id )
			);
			if (
				pageSuspended ||
				String(
					( state.session && state.session.id ) || savedSession()
				) !== String( id )
			)
				return;
			if ( observation ) {
				if (
					! state.open ||
					state.busy ||
					state.stepping ||
					blockedObservation !== observation ||
					observation.key !== resultKey()
				)
					return;
				const run = session && session.run;
				if (
					! session ||
					String( session.id ) !== String( id ) ||
					! run ||
					run.id !== observation.runId ||
					! Number.isSafeInteger( run.step ) ||
					run.step < currentRun().step ||
					! [
						'running',
						'complete',
						'incomplete',
						'failed',
						'cancelled',
						'awaiting_input',
						'awaiting_confirmation',
					].includes( run.status )
				) {
					observation.stopped = true;
					state.connectionError = __(
						'The saved task changed. Reconnect to review it.',
						'bhranti'
					);
					render();
					return;
				}
				const job = run.render_job,
					browserReady =
						run.status === 'running' &&
						run.phase === 'browser' &&
						job &&
						typeof job.id === 'string' &&
						/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
							job.id
						) &&
						job.run_id === run.id &&
						job.step === run.step;
				const advanced =
					run.step > observation.step ||
					run.status !== 'running' ||
					browserReady;
				// A same-step prepared response cannot prove handover of an observed native write.
				if (
					! advanced &&
					currentRun().phase === 'action' &&
					run.phase === 'prepared'
				)
					return;
				state.session = session;
				if ( advanced ) {
					state.pausedForEditor = false;
					state.editorError = '';
					editorSync.generation++;
					observation.step = run.step;
					observation.started = Date.now();
				}
				markExistingEvents();
				render();
				revealNewTarget( new Set(), observation.runId );
				continueBrowser = browserReady;
			} else if ( ! state.open ) {
				state.session = session;
				markExistingEvents();
				syncTrigger();
			}
		} catch ( error ) {
			if (
				observation &&
				( pageSuspended ||
					blockedObservation !== observation ||
					observation.key !== resultKey() )
			)
				return;
			const status = Number( error && error.data && error.data.status );
			if ( observation && status >= 400 && status < 500 )
				observation.stopped = true;
			state.connectionError = __(
				'Latest task status is unavailable. Reopen Bhranti to reconnect.',
				'bhranti'
			);
			if ( observation && state.open ) render();
			else syncTrigger();
		} finally {
			backgroundReading = false;
			scheduleBackgroundObservation();
		}
		// Cron cannot perform a browser-only preview check; resume only this exact observed handoff.
		if (
			continueBrowser &&
			blockedObservation === observation &&
			observation.key === resultKey()
		)
			continueRun();
	}
	async function resumeRun() {
		if ( state.busy || state.stepping ) return;
		if ( editorState().unsaved_changes ) {
			state.pausedForEditor = true;
			render();
			return;
		}
		state.busy = true;
		render();
		try {
			await reportEditor( true, true );
			if ( editorSummary().dirty ) {
				state.pausedForEditor = true;
				return;
			}
			state.pausedForEditor = false;
		} catch ( error ) {
			state.pausedForEditor = true;
			notify( state.editorError || errorMessage( error ) );
		} finally {
			state.busy = false;
			render();
		}
		if ( ! state.pausedForEditor ) {
			blockedObservation = null;
			continueRun();
		}
	}
	function runtimeContext() {
		const context = {
			...( settings.context || {} ),
			unsaved_changes: editorState().unsaved_changes,
		};
		try {
			const editor = wp.data && wp.data.select( 'core/editor' );
			let id, postType;
			if ( editor && editor.getCurrentPostId ) {
				id = editor.getCurrentPostId();
				postType =
					editor.getCurrentPostType && editor.getCurrentPostType();
				const title =
					editor.getEditedPostAttribute &&
					editor.getEditedPostAttribute( 'title' );
				context.title =
					typeof title === 'string' ? title : context.title;
			}
			if ( ( id === undefined || id === null || id === '' ) && wp.data ) {
				const site = wp.data.select( 'core/edit-site' );
				if ( site && site.getEditedPostId ) {
					id = site.getEditedPostId();
					postType =
						site.getEditedPostType && site.getEditedPostType();
				}
			}
			if ( id !== undefined && id !== null && id !== '' ) {
				context.entity_id = String( id ).slice( 0, 200 );
				context.post_id =
					Number.isInteger( Number( id ) ) && Number( id ) > 0
						? Number( id )
						: 0;
			}
			if ( postType ) context.post_type = postType;
			const blocks = wp.data && wp.data.select( 'core/block-editor' );
			const block =
				blocks && blocks.getSelectedBlock && blocks.getSelectedBlock();
			if ( block ) {
				const content =
					wp.blocks && wp.blocks.serialize
						? wp.blocks.serialize( [ block ] )
						: block.attributes &&
						  typeof block.attributes.content === 'string'
						? block.attributes.content
						: '';
				context.selected_block = {
					client_id: block.clientId,
					name: block.name,
					path: ( blocks.getBlockParents
						? blocks.getBlockParents( block.clientId )
						: []
					)
						.concat( block.clientId )
						.slice( -20 ),
					content: content.slice( 0, 8000 ),
				};
			}
		} catch ( error ) {
			/* Core editor stores are absent on ordinary site screens. */
		}
		return context;
	}
	function renderedPage() {
		try {
			const frame = document.querySelector(
				'iframe[name="editor-canvas"], iframe.editor-canvas'
			);
			const region =
				frame && frame.contentDocument
					? frame.contentDocument.body
					: document.querySelector(
							'#wpbody-content, main, [role="main"]'
					  ) || document.body;
			const copy = region.cloneNode( true );
			const originals = region.querySelectorAll( '*' ),
				copies = copy.querySelectorAll( '*' ),
				hasLayout = region.getClientRects().length > 0;
			originals.forEach( ( node, index ) => {
				const style =
					node.ownerDocument.defaultView.getComputedStyle( node );
				if (
					style.display === 'none' ||
					style.visibility === 'hidden' ||
					style.visibility === 'collapse' ||
					style.opacity === '0' ||
					node.classList.contains( 'screen-reader-text' ) ||
					node.closest( '[inert]' ) ||
					( hasLayout &&
						style.display !== 'contents' &&
						! Array.from( node.getClientRects() ).some(
							( rect ) => rect.width > 0 && rect.height > 0
						) )
				)
					copies[ index ].remove();
			} );
			copy.querySelectorAll(
				'#bhranti-app, #wpadminbar, #adminmenuwrap, script, style, noscript, form, input, textarea, select, button, iframe, link, meta, [hidden], [aria-hidden="true"]'
			).forEach( ( node ) => node.remove() );
			copy.querySelectorAll( '*' ).forEach( ( node ) => {
				Array.from( node.attributes ).forEach( ( attribute ) => {
					if (
						! [ 'class', 'href', 'src', 'alt' ].includes(
							attribute.name
						)
					)
						node.removeAttribute( attribute.name );
				} );
				[ 'href', 'src' ].forEach( ( name ) => {
					if ( node.hasAttribute( name ) ) {
						try {
							const url = new URL(
								node.getAttribute( name ),
								window.location.href
							);
							if (
								! [ 'https:', 'http:' ].includes( url.protocol )
							)
								node.removeAttribute( name );
							else {
								url.search = '';
								url.hash = '';
								node.setAttribute( name, url.href );
							}
						} catch ( error ) {
							node.removeAttribute( name );
						}
					}
				} );
			} );
			return {
				html: copy.innerHTML.slice( 0, 12000 ),
				text: ( copy.textContent || '' )
					.replace( /\s+/g, ' ' )
					.trim()
					.slice( 0, 8000 ),
				viewport: {
					width: window.innerWidth,
					height: window.innerHeight,
				},
			};
		} catch ( error ) {
			return null;
		}
	}
	async function attachScreenshot() {
		if ( state.capturing || state.busy || state.loading || activeRun() )
			return;
		if (
			! navigator.mediaDevices ||
			! navigator.mediaDevices.getDisplayMedia
		) {
			notify(
				__(
					'This browser cannot capture a tab screenshot. Page HTML and editor context are still available.',
					'bhranti'
				)
			);
			render();
			return;
		}
		state.capturing = true;
		render();
		let stream;
		try {
			stream = await navigator.mediaDevices.getDisplayMedia( {
				video: { displaySurface: 'browser' },
				audio: false,
				preferCurrentTab: true,
				selfBrowserSurface: 'include',
				monitorTypeSurfaces: 'exclude',
				surfaceSwitching: 'exclude',
			} );
			const track = stream.getVideoTracks()[ 0 ];
			if (
				! track ||
				( track.getSettings().displaySurface &&
					track.getSettings().displaySurface !== 'browser' )
			)
				throw new Error(
					__(
						'Choose a browser tab to capture its screenshot.',
						'bhranti'
					)
				);
			const video = document.createElement( 'video' );
			video.srcObject = stream;
			video.muted = true;
			await video.play();
			if ( ! video.videoWidth || ! video.videoHeight )
				throw new Error(
					__(
						'The browser did not return a screenshot frame.',
						'bhranti'
					)
				);
			const scale = Math.min(
					1,
					1600 / video.videoWidth,
					1600 / video.videoHeight
				),
				canvas = document.createElement( 'canvas' );
			canvas.width = Math.max(
				1,
				Math.round( video.videoWidth * scale )
			);
			canvas.height = Math.max(
				1,
				Math.round( video.videoHeight * scale )
			);
			canvas
				.getContext( '2d' )
				.drawImage( video, 0, 0, canvas.width, canvas.height );
			const data = canvas
				.toDataURL( 'image/jpeg', 0.8 )
				.split( ',' )[ 1 ];
			if ( ! data || data.length > 2796204 )
				throw new Error(
					__(
						'The screenshot is too large. Choose a smaller browser window.',
						'bhranti'
					)
				);
			state.screenshot = { mime_type: 'image/jpeg', data };
			rememberDraft();
		} catch ( error ) {
			if ( error.name !== 'NotAllowedError' )
				notify( errorMessage( error ) );
		} finally {
			if ( stream )
				stream.getTracks().forEach( ( track ) => track.stop() );
			state.capturing = false;
			render();
		}
	}
	async function attachReferenceImage( file ) {
		if (
			! file ||
			state.capturing ||
			state.busy ||
			state.loading ||
			activeRun()
		)
			return;
		state.capturing = true;
		render();
		let url;
		try {
			if (
				( file.type &&
					! [ 'image/jpeg', 'image/png', 'image/webp' ].includes(
						file.type
					) ) ||
				file.size < 1 ||
				file.size > 10485760
			)
				throw new Error(
					__(
						'Choose a PNG, JPEG or WebP image up to 10 MiB.',
						'bhranti'
					)
				);
			const bytes = new Uint8Array( await file.arrayBuffer() ),
				match = ( values, offset = 0 ) =>
					values.every(
						( value, index ) => bytes[ index + offset ] === value
					);
			const type = match( [ 255, 216, 255 ] )
				? 'image/jpeg'
				: match( [ 137, 80, 78, 71, 13, 10, 26, 10 ] )
				? 'image/png'
				: match( [ 82, 73, 70, 70 ] ) && match( [ 87, 69, 66, 80 ], 8 )
				? 'image/webp'
				: '';
			const complete =
				type === 'image/jpeg'
					? match( [ 255, 217 ], bytes.length - 2 )
					: type === 'image/png'
					? match(
							[ 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130 ],
							bytes.length - 12
					  )
					: type === 'image/webp' &&
					  new DataView( bytes.buffer ).getUint32( 4, true ) + 8 ===
							bytes.length;
			if ( ! type || ! complete || bytes.length !== file.size )
				throw new Error(
					__(
						'This file is not a complete PNG, JPEG or WebP image. Export it again in one of those formats and attach the new file.',
						'bhranti'
					)
				);
			// File pickers can label a JPEG screenshot as PNG; decode the actual raster type, then share only new pixels.
			url = URL.createObjectURL( new Blob( [ bytes ], { type } ) );
			const image = await new Promise( ( resolve, reject ) => {
				const image = new Image();
				image.onload = () => resolve( image );
				image.onerror = () =>
					reject(
						new Error(
							__(
								'This image could not be opened. Choose another PNG, JPEG or WebP image.',
								'bhranti'
							)
						)
					);
				image.src = url;
			} );
			const width = image.naturalWidth,
				height = image.naturalHeight;
			if (
				! width ||
				! height ||
				width > 10000 ||
				height > 10000 ||
				width * height > 25000000
			)
				throw new Error(
					__(
						'Choose an image up to 25 megapixels and 10,000 pixels on either side.',
						'bhranti'
					)
				);
			const scale = Math.min( 1, 1600 / width, 1600 / height ),
				canvas = document.createElement( 'canvas' );
			canvas.width = Math.max( 1, Math.round( width * scale ) );
			canvas.height = Math.max( 1, Math.round( height * scale ) );
			const context = canvas.getContext( '2d' );
			if ( ! context )
				throw new Error(
					__( 'This browser could not prepare the image.', 'bhranti' )
				);
			context.fillStyle = '#fff';
			context.fillRect( 0, 0, canvas.width, canvas.height );
			context.drawImage( image, 0, 0, canvas.width, canvas.height );
			const encoded = canvas.toDataURL( 'image/jpeg', 0.8 ),
				data = encoded.startsWith( 'data:image/jpeg;base64,' )
					? encoded.slice( 23 )
					: '';
			if (
				! data ||
				data.length > 2796204 ||
				atob( data ).length > 2097152
			)
				throw new Error(
					__(
						'This image is too large to attach. Choose a smaller image.',
						'bhranti'
					)
				);
			if ( ! pageSuspended ) {
				state.screenshot = { mime_type: 'image/jpeg', data };
				rememberDraft();
			}
		} catch ( error ) {
			if ( ! pageSuspended ) notify( errorMessage( error ) );
		} finally {
			if ( url ) URL.revokeObjectURL( url );
			state.capturing = false;
			if ( ! pageSuspended ) {
				render();
				focusComposer();
			}
		}
	}

	function attachedContext() {
		const context = runtimeContext();
		if ( state.screenshot ) context.screenshot = state.screenshot;
		if ( referenceImageRemoved() ) context.discard_reference_image = true;
		if ( ! state.attachPage )
			return {
				unsaved_changes: !! context.unsaved_changes,
				...( state.screenshot ? { screenshot: state.screenshot } : {} ),
				...( context.discard_reference_image
					? { discard_reference_image: true }
					: {} ),
			};
		context.rendered_page = renderedPage();
		if ( ! state.attachBlock ) delete context.selected_block;
		return context;
	}
	function resultKey() {
		const run = currentRun();
		return state.session && run ? state.session.id + ':' + run.id : '';
	}
	function collectResults() {
		const run = currentRun(),
			key = resultKey();
		if ( ! run || ! key ) return;
		if ( run.status === 'running' && ! state.results.has( key ) )
			state.results.set( key, new Map() );
		const results = state.results.get( key );
		if ( ! results ) return;
		( state.session.events || [] ).forEach( ( event ) => {
			if (
				event.type === 'action' &&
				event.success === true &&
				event.run_id === run.id &&
				[ 'write', 'image', 'rollback' ].includes( event.operation ) &&
				safeTarget( event.target )
			)
				results.set( String( event.id ), event );
		} );
	}
	function observeSettingsChanges() {
		const run = currentRun(),
			key = resultKey();
		if ( ! run || ! key ) return;
		const events = state.session.events || [],
			seen = settingsEvents.get( key );
		if ( ! seen ) {
			settingsEvents.set(
				key,
				new Set( events.map( ( event ) => String( event.id ) ) )
			);
			return;
		}
		events.forEach( ( event ) => {
			const id = String( event.id || '' );
			if ( ! id || seen.has( id ) ) return;
			seen.add( id );
			if (
				event.type === 'action' &&
				event.success === true &&
				event.run_id === run.id &&
				[ 'write', 'rollback' ].includes( event.operation ) &&
				event.target &&
				event.target.type === 'bhranti_settings' &&
				safeTarget( event.target )
			)
				window.dispatchEvent(
					new CustomEvent( 'bhranti:settings-changed' )
				);
		} );
	}
	function markExistingEvents() {
		reconcileActionControl();
		syncEditorControl();
		observeEditorChanges();
		observeSettingsChanges();
		collectResults();
		( ( state.session && state.session.events ) || [] ).forEach(
			( event ) => state.seen.add( String( event.id ) )
		);
	}
	function safeTarget( target ) {
		if ( ! target || typeof target.url !== 'string' || ! target.url.trim() )
			return null;
		try {
			const url = new URL( target.url, window.location.href );
			if (
				url.origin !== window.location.origin ||
				! [ 'http:', 'https:' ].includes( url.protocol )
			)
				return null;
			const admin = new URL(
				settings.connectorsUrl || '/wp-admin/options-connectors.php',
				window.location.origin
			).pathname.replace( /[^/]+$/, '' );
			const ownSettings =
				target.type === 'bhranti_settings' &&
				url.pathname === admin + 'admin.php' &&
				url.searchParams.get( 'page' ) === 'bhranti' &&
				Array.from( url.searchParams.keys() ).every(
					( key ) => key === 'page'
				);
			const commerceSettings =
				target.type === 'woocommerce_settings' &&
				url.pathname === admin + 'admin.php' &&
				url.searchParams.get( 'page' ) === 'wc-settings' &&
				[
					'general',
					'products',
					'advanced',
					'shipping',
					'checkout',
					'site-visibility',
				].includes( url.searchParams.get( 'tab' ) ) &&
				Array.from( url.searchParams.keys() ).every( ( key ) =>
					[ 'page', 'tab' ].includes( key )
				) &&
				[ 'page', 'tab' ].every(
					( key ) => url.searchParams.getAll( key ).length === 1
				);
			const productCategory =
				target.type === 'product_cat' &&
				url.pathname === admin + 'term.php' &&
				url.searchParams.get( 'taxonomy' ) === 'product_cat' &&
				/^[1-9]\d*$/.test( url.searchParams.get( 'tag_ID' ) || '' ) &&
				url.searchParams.get( 'post_type' ) === 'product' &&
				Array.from( url.searchParams.keys() ).every( ( key ) =>
					[ 'taxonomy', 'tag_ID', 'post_type' ].includes( key )
				) &&
				[ 'taxonomy', 'tag_ID', 'post_type' ].every(
					( key ) => url.searchParams.getAll( key ).length === 1
				);
			if (
				url.pathname === admin + 'term.php' &&
				( target.type === 'product_cat' ||
					url.searchParams.get( 'taxonomy' ) === 'product_cat' ) &&
				! productCategory
			)
				return null;
			if (
				url.pathname.startsWith( admin ) &&
				! ownSettings &&
				! commerceSettings &&
				! productCategory &&
				! [
					'post.php',
					'edit.php',
					'revision.php',
					'site-editor.php',
					'upload.php',
					'edit-tags.php',
					'term.php',
					'edit-comments.php',
					'options-general.php',
					'options-reading.php',
					'nav-menus.php',
					'comment.php',
					'plugins.php',
				].includes( url.pathname.slice( admin.length ) )
			)
				return null;
			if (
				url.searchParams.has( '_wpnonce' ) ||
				( url.searchParams.get( 'action' ) &&
					! [ 'edit', 'view' ].includes(
						url.searchParams.get( 'action' )
					) &&
					! (
						url.pathname.endsWith( '/comment.php' ) &&
						url.searchParams.get( 'action' ) === 'editcomment'
					) )
			)
				return null;
			url.searchParams.set( 'bhranti', '1' );
			if ( state.session )
				url.searchParams.set( 'bhranti_session', state.session.id );
			return url;
		} catch ( error ) {
			return null;
		}
	}
	function contentTarget( target, url ) {
		return (
			target &&
			target.type !== 'attachment' &&
			/\/(?:post|site-editor)\.php$/.test( url.pathname )
		);
	}
	function viewMatches( event ) {
		const current = new URL( window.location.href ),
			type = event.target && event.target.type,
			context = runtimeContext();
		if ( /\/edit\.php$/.test( current.pathname ) )
			return (
				type === ( current.searchParams.get( 'post_type' ) || 'post' )
			);
		if ( /\/upload\.php$/.test( current.pathname ) )
			return type === 'attachment';
		if ( /\/plugins\.php$/.test( current.pathname ) )
			return type === 'plugin';
		if ( /\/edit-comments\.php$/.test( current.pathname ) )
			return type === 'comment';
		if ( /\/edit-tags\.php$/.test( current.pathname ) )
			return type === current.searchParams.get( 'taxonomy' );
		return (
			! settings.isAdmin &&
			!! context.post_id &&
			String( context.post_id ) ===
				String( event.target && event.target.id )
		);
	}
	function rememberView( event ) {
		try {
			const key = storageKey + ':view-update',
				value = resultKey() + ':' + event.id;
			if ( window.sessionStorage.getItem( key ) === value ) return false;
			window.sessionStorage.setItem( key, value );
			return true;
		} catch ( error ) {
			return false;
		}
	}
	function revealNewTarget( previousEvents, runId ) {
		if (
			! previousEvents ||
			! runId ||
			! currentRun() ||
			currentRun().id !== runId
		)
			return false;
		collectResults();
		const results = Array.from(
			( state.results.get( resultKey() ) || new Map() ).values()
		);
		if (
			! results.length ||
			currentRun().status !== 'complete' ||
			! state.open ||
			editorIsOpen() ||
			pageFormDirty ||
			runtimeContext().unsaved_changes ||
			editorSummary().dirty
		)
			return false;
		const matching = results.filter( viewMatches ),
			contents = results.filter(
				( event ) =>
					[ 'write', 'rollback' ].includes( event.operation ) &&
					contentTarget( event.target, safeTarget( event.target ) )
			);
		const dashboard =
			settings.isAdmin &&
			/\/wp-admin\/(?:index\.php)?$/.test( window.location.pathname );
		const latest = matching.length
			? matching[ matching.length - 1 ]
			: dashboard
			? contents[ contents.length - 1 ]
			: null;
		if ( ! latest || ! rememberView( latest ) ) return false;
		const target = matching.length
			? new URL( window.location.href )
			: safeTarget( latest.target );
		rememberDraft();
		rememberSession( state.session.id );
		window.location.assign( target.href );
		return true;
	}
	async function refreshWorkspace() {
		const workspace = await request( '/workspace' );
		state.workspace = {
			...workspace,
			sessions: Array.isArray( workspace.sessions )
				? workspace.sessions
				: [],
			agents: Array.isArray( workspace.agents )
				? workspace.agents
				: [ { id: 'bhranti', name: 'Bhranti' } ],
		};
	}
	async function refreshWorkspaceQuietly() {
		try {
			await refreshWorkspace();
		} catch ( error ) {
			state.connectionError = __(
				'The chat list will update when the connection returns.',
				'bhranti'
			);
		}
	}
	function toggleChats() {
		state.menu = ! state.menu;
		render();
		if ( ! state.menu ) return;
		const first = root.querySelector( '.bhranti-chat-item' );
		if ( first && ! first.disabled ) first.focus();
		if ( ! chatListRequest )
			chatListRequest = refreshWorkspaceQuietly().finally( () => {
				chatListRequest = null;
				if ( ! pageSuspended ) render();
			} );
	}
	function sessionUnavailable( error ) {
		return (
			( error &&
				[
					'bhranti_session_missing',
					'bhranti_session_owner',
					'bhranti_session_not_found',
				].includes( error.code ) ) ||
			( error && error.data && Number( error.data.status ) === 404 )
		);
	}
	async function loadSession( id ) {
		try {
			return await request( '/sessions/' + encodeURIComponent( id ) );
		} catch ( error ) {
			if ( ! sessionUnavailable( error ) ) throw error;
			rememberSession( null );
			state.agentId = 'bhranti';
			state.connectionError = '';
			return null;
		}
	}
	async function openSession( id, preserveDraft = false ) {
		if ( state.busy || state.loading || state.capturing || state.stepping )
			return;
		rememberDraft();
		state.busy = true;
		state.error = '';
		state.credentialWarning = false;
		state.menu = false;
		render();
		try {
			state.session = await loadSession( id );
			if ( ! preserveDraft ) {
				state.draft = restoreDraft( state.session && state.session.id );
				state.screenshot =
					state.screenshots.get(
						draftKey( state.session && state.session.id )
					) || null;
			}
			state.agentId =
				( state.session && state.session.agent_id ) || 'bhranti';
			rememberSession( state.session && state.session.id );
			markExistingEvents();
		} catch ( error ) {
			state.error = errorMessage( error );
		} finally {
			state.busy = false;
			render();
		}
		if ( ! state.error ) continueRun();
	}
	async function openPanel( trigger ) {
		if ( trigger ) lastTrigger = trigger;
		window.clearTimeout( backgroundTimer );
		backgroundTimer = null;
		rememberOpen( true );
		state.open = true;
		root.hidden = false;
		document.body.classList.add( 'bhranti-panel-open' );
		syncTrigger();
		render();
		if ( ! state.initialized ) {
			state.loading = true;
			state.error = '';
			render();
			try {
				await refreshWorkspace();
				const query = new URL( window.location.href ).searchParams;
				const id =
					query.get( 'bhranti_session' ) ||
					query.get( 'session' ) ||
					String( settings.initialSessionId || '' ) ||
					savedSession();
				if ( id && /^[1-9]\d*$/.test( id ) ) {
					state.session = await loadSession( id );
					state.agentId =
						( state.session && state.session.agent_id ) ||
						'bhranti';
					rememberSession( state.session && state.session.id );
					markExistingEvents();
				}
				if ( ! state.draft )
					state.draft = restoreDraft(
						state.session && state.session.id
					);
				state.initialized = true;
			} catch ( error ) {
				state.error = errorMessage( error );
			} finally {
				state.loading = false;
				render();
			}
		} else if ( ! state.busy && ! state.stepping ) {
			state.busy = true;
			state.error = '';
			render();
			try {
				await refreshWorkspaceQuietly();
				if ( state.session ) {
					state.session = await loadSession( state.session.id );
					state.agentId =
						( state.session && state.session.agent_id ) ||
						'bhranti';
					markExistingEvents();
				}
			} catch ( error ) {
				state.error = errorMessage( error );
			} finally {
				state.busy = false;
				render();
			}
		}
		if ( state.open ) {
			const control = root.querySelector( '#bhranti-composer' );
			if ( control && ! control.disabled ) control.focus();
			else root.querySelector( '#bhranti-close' ).focus();
			continueRun();
		}
	}
	function closeUnavailable() {
		return !! (
			siteControl &&
			actionInFlight( currentRun() ) &&
			! ( siteControl.opener && siteControl.opener.isConnected ) &&
			! Array.from(
				document.querySelectorAll(
					'[data-bhranti-editor-open], #wp-admin-bar-bhranti > a, [data-bhranti-open]'
				)
			).some( triggerAvailable )
		);
	}
	function closePanel() {
		if ( closeUnavailable() ) return;
		abortRender();
		rememberOpen( false );
		const url = new URL( window.location.href );
		if ( url.searchParams.has( 'bhranti' ) ) {
			url.searchParams.delete( 'bhranti' );
			window.history.replaceState( null, '', url );
		}
		state.open = false;
		state.menu = false;
		root.hidden = true;
		document.body.classList.remove( 'bhranti-panel-open' );
		syncTrigger();
		syncEditorControl();
		scheduleBackgroundObservation();
		const trigger = triggerAvailable( lastTrigger )
			? lastTrigger
			: Array.from(
					document.querySelectorAll(
						'#bhranti-site-opener, [data-bhranti-editor-open], #wp-admin-bar-bhranti > a'
					)
			  ).find( triggerAvailable );
		if ( trigger ) trigger.focus();
		// The native launcher can change portal parents when editor control ends.
		window.requestAnimationFrame( () => {
			if ( document.activeElement === document.body ) {
				const opener = Array.from(
					document.querySelectorAll(
						'#bhranti-site-opener, [data-bhranti-editor-open], #wp-admin-bar-bhranti > a'
					)
				).find( triggerAvailable );
				if ( opener ) opener.focus();
			}
		} );
	}
	function triggerAvailable( trigger ) {
		if (
			! trigger ||
			! trigger.isConnected ||
			trigger.closest( '[hidden], [inert], [aria-hidden="true"]' )
		)
			return false;
		const style = window.getComputedStyle( trigger );
		return (
			! trigger.disabled &&
			style.display !== 'none' &&
			style.visibility !== 'hidden' &&
			trigger.getClientRects().length > 0
		);
	}
	function syncTrigger() {
		const trigger = document.querySelector( '#wp-admin-bar-bhranti > a' ),
			bar = document.getElementById( 'wpadminbar' );
		const adminBarAvailable = triggerAvailable( trigger );
		const top =
			bar &&
			( triggerAvailable( bar ) ||
				( siteControl &&
					siteControl.admin &&
					bar.getClientRects().length &&
					window.getComputedStyle( bar ).visibility !== 'hidden' ) )
				? Math.max( 0, bar.getBoundingClientRect().bottom )
				: 0;
		root.style.top = top + 'px';
		document
			.querySelectorAll(
				'#bhranti-site-opener, #wp-admin-bar-bhranti > a, [data-bhranti-editor-open]'
			)
			.forEach( ( opener ) => {
				opener.setAttribute( 'aria-controls', 'bhranti-app' );
				opener.setAttribute( 'aria-expanded', String( state.open ) );
				opener.setAttribute( 'aria-haspopup', 'dialog' );
				if ( opener.hasAttribute( 'data-bhranti-editor-open' ) )
					opener.setAttribute( 'aria-pressed', String( state.open ) );
			} );
		const detail = {
			open: state.open,
			adminBarAvailable,
			controlled: !! editorControl,
		};
		const fingerprint = JSON.stringify( detail );
		if ( fingerprint !== triggerFingerprint ) {
			triggerFingerprint = fingerprint;
			document.dispatchEvent(
				new CustomEvent( 'bhranti:panel-state', { detail } )
			);
		}
		if ( ! trigger ) return;
		let marker = trigger.querySelector( '.bhranti-background-status' );
		const run = currentRun();
		const background =
			! state.open &&
			run &&
			run.background &&
			[ 'running', 'awaiting_confirmation' ].includes( run.status );
		if ( ! background ) {
			if ( marker ) marker.remove();
			trigger.removeAttribute( 'title' );
			return;
		}
		const label =
			run.background_error || state.editorError
				? __( 'Background unavailable', 'bhranti' )
				: run.status === 'awaiting_confirmation'
				? __( 'Approval required', 'bhranti' )
				: __( 'Continuing in background', 'bhranti' );
		if ( ! marker ) {
			marker = element( 'span', 'bhranti-background-status' );
			marker.setAttribute( 'role', 'status' );
			trigger.appendChild( marker );
		}
		marker.textContent = label;
		trigger.title = label;
	}
	function newChat() {
		if ( state.busy || state.loading || state.capturing || activeRun() )
			return;
		rememberDraft();
		state.session = null;
		state.draft = restoreDraft( null );
		state.screenshot = state.screenshots.get( draftKey( null ) ) || null;
		state.error = '';
		state.credentialWarning = false;
		state.menu = false;
		state.seen.clear();
		rememberSession( null );
		render();
		root.querySelector( '#bhranti-composer' ).focus();
	}
	function acceptedSubmission(
		session,
		content,
		previousCount,
		previousRunId
	) {
		return !! (
			session &&
			session.run &&
			typeof session.run.id === 'string' &&
			session.run.id &&
			session.run.id !== previousRunId &&
			( session.messages || [] )
				.slice( previousCount )
				.some(
					( message ) =>
						message.role === 'user' && message.content === content
				)
		);
	}
	async function reloadAfterError( content, previousCount, previousRunId ) {
		if ( ! state.session ) {
			if ( content ) state.draft = content;
			return;
		}
		const id = state.session.id,
			runId = ! content && currentRun() && currentRun().id;
		try {
			const fresh = await request(
				'/sessions/' + encodeURIComponent( id )
			);
			if (
				pageSuspended ||
				! state.session ||
				state.session.id !== id ||
				( runId && ( ! currentRun() || currentRun().id !== runId ) )
			)
				return false;
			if (
				! fresh ||
				fresh.id !== id ||
				( runId && ( ! fresh.run || fresh.run.id !== runId ) )
			)
				throw new Error(
					__(
						'The saved task changed. Reconnect to review it.',
						'bhranti'
					)
				);
			state.session = fresh;
			if (
				content &&
				! acceptedSubmission(
					state.session,
					content,
					previousCount,
					previousRunId
				)
			)
				state.draft = content;
			markExistingEvents();
			return true;
		} catch ( error ) {
			if ( content ) state.draft = content;
			state.error = __(
				'Connection interrupted. Reconnect to check the saved task before continuing.',
				'bhranti'
			);
			return false;
		}
	}
	async function reconnectChat() {
		if ( state.busy || state.loading || state.stepping ) return;
		state.error = '';
		if ( state.session ) await openSession( state.session.id, true );
		else {
			state.initialized = false;
			await openPanel();
		}
	}
	function containsCredentials( value ) {
		return !! (
			window.bhrantiCredentials &&
			window.bhrantiCredentials.contains( value )
		);
	}
	function submissionHasCredentials( content, context = attachedContext() ) {
		const textContext = { ...context };
		if ( textContext.screenshot ) {
			const { data, ...metadata } = textContext.screenshot;
			textContext.screenshot = metadata;
		}
		return containsCredentials( { content, context: textContext } );
	}
	function credentialWarning() {
		if ( state.credentialWarning === 'history' )
			return __(
				'This conversation contains older text that looks like a credential. Start a new chat without it. This request was not saved or sent to AI.',
				'bhranti'
			);
		if ( state.credentialWarning === 'settings' )
			return __(
				'Your Bhranti instructions appear to contain credentials. Remove them in Bhranti Settings before sending. Manage provider credentials in WordPress Connectors.',
				'bhranti'
			);
		return __(
			'This message or its attached context appears to contain a password, API key, token or other credential. Remove it before sending. Manage provider credentials in WordPress Connectors.',
			'bhranti'
		);
	}
	function updateCredentialWarning(
		input = root.querySelector( '#bhranti-composer' ),
		warning = root.querySelector( '#bhranti-credential-warning' )
	) {
		if ( warning ) warning.hidden = ! state.credentialWarning;
		if ( input ) {
			input.setAttribute(
				'aria-describedby',
				'bhranti-composer-hint bhranti-disclosure' +
					( state.credentialWarning
						? ' bhranti-credential-warning'
						: '' )
			);
			if ( state.credentialWarning )
				input.setAttribute( 'aria-invalid', 'true' );
			else input.removeAttribute( 'aria-invalid' );
		}
	}
	async function rejectCredentialSubmission(
		content,
		id,
		messages,
		context
	) {
		if (
			pageSuspended ||
			( ( state.session && state.session.id ) || null ) !== id
		)
			return;
		const rejectedSession = state.session;
		if ( rejectedSession ) rejectedSession.messages = messages;
		state.draft = content;
		credentialDrafts.set( draftKey( id ), content );
		state.error = '';
		state.credentialWarning = [ 'history', 'settings' ].includes( context )
			? context
			: true;
		if ( ! id ) return;
		credentialReview = true;
		render();
		try {
			const fresh = await request(
				'/sessions/' + encodeURIComponent( id )
			);
			if (
				! pageSuspended &&
				state.session === rejectedSession &&
				fresh &&
				fresh.id === id
			)
				state.session = fresh;
		} catch ( error ) {
			/* The rejected content was never accepted; keep its editable draft. */
		} finally {
			credentialReview = false;
		}
	}
	async function sendMessage() {
		const draft = state.draft,
			content = draft.trim();
		if (
			! content ||
			state.busy ||
			state.loading ||
			state.capturing ||
			state.error ||
			activeRun()
		)
			return;
		const context = attachedContext();
		if ( submissionHasCredentials( content, context ) ) {
			state.credentialWarning = true;
			rememberDraft();
			render();
			focusComposer();
			return;
		}
		state.busy = true;
		state.credentialWarning = false;
		state.draft = '';
		rememberDraft();
		state.stopRequested = false;
		state.pausedForEditor = false;
		render();
		let previousCount = 0,
			previousRunId = null,
			submissionId = null,
			messages = [];
		try {
			if ( ! state.session )
				state.session = await request( '/sessions', 'POST', {} );
			submissionId = state.session.id;
			messages = state.session.messages || [];
			rememberSession( submissionId );
			previousCount = messages.length;
			previousRunId = ( currentRun() && currentRun().id ) || null;
			state.session.messages = messages.concat( {
				role: 'user',
				content,
				created: new Date().toISOString(),
			} );
			render();
			state.session = await request(
				'/sessions/' + encodeURIComponent( submissionId ) + '/messages',
				'POST',
				{ content, context, agent_id: state.agentId }
			);
			state.screenshot = null;
			state.screenshots.delete( draftKey( null ) );
			clearReferenceRemoval( state.session && state.session.id );
		} catch ( error ) {
			if ( error.code === 'bhranti_credentials' )
				await rejectCredentialSubmission(
					draft,
					submissionId,
					messages,
					error.data && error.data.credential_context
				);
			else {
				state.error = errorMessage( error );
				if (
					( await reloadAfterError(
						content,
						previousCount,
						previousRunId
					) ) &&
					acceptedSubmission(
						state.session,
						content,
						previousCount,
						previousRunId
					)
				) {
					state.error = '';
					state.screenshot = null;
					state.screenshots.delete( draftKey( null ) );
					clearReferenceRemoval( state.session && state.session.id );
				}
			}
		} finally {
			await refreshWorkspaceQuietly();
			state.busy = false;
			rememberDraft();
			render();
		}
		markExistingEvents();
		continueRun();
		focusComposer();
	}
	function paintPreparedAction() {
		return new Promise( ( resolve ) => {
			let settled = false,
				frame = 0;
			const done = () => {
				if ( settled ) return;
				settled = true;
				window.clearTimeout( timer );
				window.cancelAnimationFrame( frame );
				resolve();
			};
			// Hidden tabs can suspend animation frames while Cron advances the task.
			const timer = window.setTimeout( done, 250 );
			frame = window.requestAnimationFrame( () => {
				frame = window.requestAnimationFrame( done );
			} );
		} );
	}
	function abortRender() {
		if ( renderController ) renderController.abort();
	}
	async function inspectRenderedPage( id, runId ) {
		const before = currentRun(),
			jobId = before.render_job && before.render_job.id,
			step = before.step;
		if (
			! jobId ||
			! window.BhrantiRender ||
			typeof window.BhrantiRender.run !== 'function'
		)
			throw new Error(
				__(
					'The rendered preview checker could not load. Reload Bhranti to continue.',
					'bhranti'
				)
			);
		state.session = await request(
			'/sessions/' + encodeURIComponent( id ) + '/render-start',
			'POST',
			{ run_id: runId, prepared_step: step, job_id: jobId }
		);
		if (
			! state.open ||
			pageSuspended ||
			! currentRun() ||
			currentRun().id !== runId ||
			currentRun().phase !== 'browser' ||
			state.stopRequested
		)
			return;
		const job = currentRun().render_job,
			controller = new AbortController();
		renderController = controller;
		state.progress = {
			label: __( 'Loading the saved WordPress preview…', 'bhranti' ),
			started: Date.now(),
		};
		render();
		progressTimer = window.setInterval( updateElapsed, 1000 );
		try {
			const report = await window.BhrantiRender.run( job, {
				signal: controller.signal,
				axeUrl: settings.renderCheck && settings.renderCheck.axeUrl,
				onProgress: ( progress ) => {
					if (
						! controller.signal.aborted &&
						currentRun() &&
						currentRun().id === runId &&
						state.open
					) {
						state.progress.label = progress.message;
						render();
					}
				},
			} );
			if (
				controller.signal.aborted ||
				pageSuspended ||
				! state.open ||
				state.stopRequested ||
				! currentRun() ||
				currentRun().id !== runId
			)
				return;
			state.session = await request(
				'/sessions/' + encodeURIComponent( id ) + '/render-result',
				'POST',
				{ run_id: runId, prepared_step: step, job_id: job.id, report }
			);
			markExistingEvents();
		} catch ( error ) {
			if ( error && error.name === 'AbortError' ) return;
			// A lost response may already be settled; reconcile without replaying it.
			const fresh = await request(
				'/sessions/' + encodeURIComponent( id )
			);
			if (
				pageSuspended ||
				! state.session ||
				state.session.id !== id ||
				! currentRun() ||
				currentRun().id !== runId
			)
				return;
			state.session = fresh;
			if (
				! fresh.run ||
				fresh.run.id !== runId ||
				fresh.run.phase !== 'browser' ||
				! fresh.run.render_job ||
				fresh.run.render_job.id !== job.id
			)
				return;
			if ( error && error.code === 'bhranti_render_stale' ) return;
			throw error;
		} finally {
			if ( renderController === controller ) renderController = null;
			window.clearInterval( progressTimer );
			progressTimer = null;
			state.progress = null;
			render();
		}
	}
	async function continueRun() {
		if (
			pageSuspended ||
			state.stepping ||
			state.busy ||
			( state.error && ! state.stopRequested ) ||
			! state.open ||
			! currentRun() ||
			currentRun().status !== 'running'
		)
			return;
		if (
			! state.stopRequested &&
			pendingAction &&
			pendingAction.key === resultKey() &&
			pendingAction.step === currentRun().step &&
			currentRun().phase === 'prepared'
		) {
			state.error = __(
				'The previous action response is still unconfirmed. Reconnect to check the saved task before continuing.',
				'bhranti'
			);
			render();
			return;
		}
		blockedObservation = null;
		state.stepping = true;
		state.busy = true;
		collectResults();
		render();
		let waitingSince = null,
			observedRetryAt = null,
			cancelAttempted = false,
			continueStop = false;
		try {
			while (
				! pageSuspended &&
				state.open &&
				currentRun() &&
				( currentRun().status === 'running' ||
					( state.stopRequested &&
						currentRun().status === 'awaiting_confirmation' ) )
			) {
				// Let the saved canvas update before another model request occupies the server.
				await flushEditorChanges();
				if ( pageSuspended || ! state.open ) break;
				const id = state.session.id,
					runId = currentRun().id;
				if ( currentRun().interrupted && ! state.stopRequested ) {
					state.error = __(
						'The previous request was interrupted. Stop this run and check Logs before starting again.',
						'bhranti'
					);
					render();
					break;
				}
				if (
					state.stopRequested &&
					( currentRun().interrupted ||
						! [ 'model', 'action', 'tool' ].includes(
							currentRun().phase
						) )
				) {
					state.error = '';
					cancelAttempted = true;
					state.session = await request(
						'/sessions/' + encodeURIComponent( id ) + '/cancel',
						'POST',
						{ run_id: runId }
					);
					state.stopRequested = false;
					state.pausedForEditor = false;
					render();
					break;
				}
				if ( currentRun().phase === 'browser' ) {
					await inspectRenderedPage( id, runId );
					if ( ! state.open || pageSuspended ) break;
					continue;
				}
				const retryAt = Number( currentRun().retry_at ) * 1000;
				if (
					Number.isFinite( retryAt ) &&
					retryAt > 0 &&
					( retryAt > Date.now() || observedRetryAt !== retryAt )
				) {
					if ( retryAt > Date.now() ) {
						await wait( Math.min( 1000, retryAt - Date.now() ) );
						if ( ! state.open || pageSuspended ) break;
						render();
						if ( state.stopRequested || retryAt > Date.now() )
							continue;
					}
					state.session = await request(
						'/sessions/' + encodeURIComponent( id )
					);
					markExistingEvents();
					render();
					observedRetryAt = retryAt;
					if (
						! currentRun() ||
						currentRun().id !== runId ||
						revealNewTarget( new Set(), runId )
					)
						break;
					continue;
				}
				if (
					[ 'model', 'action', 'tool' ].includes( currentRun().phase )
				) {
					if ( waitingSince === null ) waitingSince = Date.now();
					if ( Date.now() - waitingSince >= 600000 )
						throw new Error(
							__(
								'WordPress is still busy. Stop this run and check Logs before retrying.',
								'bhranti'
							)
						);
					await wait( 1500 );
					if ( ! state.open ) break;
					state.session = await request(
						'/sessions/' + encodeURIComponent( id )
					);
					markExistingEvents();
					render();
					if (
						! currentRun() ||
						currentRun().id !== runId ||
						revealNewTarget( new Set(), runId )
					)
						break;
					continue;
				}
				const previousEvents = new Set(
					( state.session.events || [] ).map( ( event ) =>
						String( event.id )
					)
				);
				const prepared = currentRun().phase === 'prepared',
					action = currentRun().next_action,
					preparedStep = currentRun().step;
				if ( prepared ) {
					if ( ! action )
						throw new Error(
							__(
								'The prepared action could not load. Reload this conversation before continuing.',
								'bhranti'
							)
						);
					if (
						String( action.method || 'GET' ).toUpperCase() !== 'GET'
					) {
						if ( editorState().unsaved_changes ) {
							state.pausedForEditor = true;
							render();
							break;
						}
						try {
							await reportEditor( false, true );
						} catch ( error ) {
							state.pausedForEditor = true;
							render();
							break;
						}
						if (
							editorState().unsaved_changes ||
							editorSummary().dirty
						) {
							state.pausedForEditor = true;
							render();
							break;
						}
					}
					state.pausedForEditor = false;
					render();
					await paintPreparedAction();
					if ( ! state.open || pageSuspended ) break;
					if ( state.stopRequested ) continue;
				}
				try {
					state.session = await runRequest(
						'/sessions/' +
							encodeURIComponent( id ) +
							( prepared ? '/execute' : '/step' ),
						prepared
							? {
									run_id: runId,
									prepared_step: preparedStep,
									unsaved_changes:
										!! runtimeContext().unsaved_changes,
							  }
							: { run_id: runId, defer_tool: true },
						prepared
							? action.summary ||
									__(
										'Executing prepared action…',
										'bhranti'
									)
							: __( 'Planning next step…', 'bhranti' )
					);
				} catch ( error ) {
					if (
						[
							'bhranti_unsaved_changes',
							'bhranti_editor_busy',
						].includes( error.code )
					) {
						state.pausedForEditor = true;
						await reloadAfterError();
						render();
						break;
					}
					if (
						prepared &&
						error.code === 'bhranti_run_missing' &&
						error.data &&
						error.data.status === 409
					) {
						// Cron can advance while this browser is reopening. Never replay the rejected step.
						const fresh = await request(
							'/sessions/' + encodeURIComponent( id )
						);
						if (
							pageSuspended ||
							! state.session ||
							state.session.id !== id ||
							! currentRun() ||
							currentRun().id !== runId
						)
							break;
						if (
							! fresh ||
							fresh.id !== id ||
							! fresh.run ||
							fresh.run.id !== runId ||
							! Number.isSafeInteger( fresh.run.step ) ||
							fresh.run.step <= preparedStep
						)
							throw error;
						state.session = fresh;
						waitingSince = null;
						markExistingEvents();
						render();
						if ( revealNewTarget( previousEvents, runId ) ) break;
						continue;
					}
					if (
						error.code !== 'bhranti_busy' ||
						! error.data ||
						error.data.status !== 409
					)
						throw error;
					if ( waitingSince === null ) waitingSince = Date.now();
					if ( Date.now() - waitingSince >= 600000 )
						throw new Error(
							__(
								'WordPress is still busy. Stop this run and check Logs before retrying.',
								'bhranti'
							)
						);
					await wait( 1500 );
					if ( ! state.open ) break;
					state.session = await request(
						'/sessions/' + encodeURIComponent( id )
					);
					markExistingEvents();
					render();
					if ( ! currentRun() || currentRun().id !== runId ) break;
					continue;
				}
				waitingSince = null;
				if (
					prepared &&
					currentRun() &&
					currentRun().id === runId &&
					currentRun().phase === 'prepared'
				)
					throw new Error(
						__(
							'WordPress has not finished this action. Reload this conversation before retrying.',
							'bhranti'
						)
					);
				render();
				if ( state.stopRequested ) continue;
				if ( revealNewTarget( previousEvents, runId ) ) break;
			}
		} catch ( error ) {
			state.error = errorMessage( error );
			if ( ( await reloadAfterError() ) && currentRun() ) {
				if ( currentRun().status !== 'running' ) state.error = '';
				// A lost execute may never reach WordPress. Honour this run's explicit Stop through native locked cancellation once.
				continueStop =
					! cancelAttempted &&
					state.stopRequested &&
					stopRunKey === resultKey() &&
					currentRun().status === 'running' &&
					currentRun().phase === 'prepared';
			}
		} finally {
			state.busy = false;
			state.stepping = false;
			reconcileActionControl();
			render();
			focusComposer();
			scheduleBackgroundObservation();
		}
		if ( continueStop ) continueRun();
	}
	async function resumeTask() {
		const run = currentRun();
		if (
			state.busy ||
			state.stepping ||
			! state.session ||
			! run ||
			run.status !== 'failed' ||
			! run.can_resume
		)
			return;
		state.busy = true;
		state.error = '';
		state.stopRequested = false;
		state.pausedForEditor = false;
		render();
		let resumed = false;
		try {
			state.session = await runRequest(
				'/sessions/' +
					encodeURIComponent( state.session.id ) +
					'/resume',
				{ run_id: run.id },
				__( 'Resuming task…', 'bhranti' )
			);
			markExistingEvents();
			resumed = true;
		} catch ( error ) {
			state.error = errorMessage( error );
			if (
				( await reloadAfterError() ) &&
				currentRun() &&
				currentRun().id === run.id &&
				currentRun().status === 'running'
			) {
				state.error = '';
				resumed = true;
			}
		} finally {
			state.busy = false;
			render();
		}
		if ( resumed ) continueRun();
	}
	async function confirmAction( approved ) {
		if ( state.busy || ! state.session || ! state.session.confirmation )
			return;
		const restoreFocus = [ 'bhranti-confirm', 'bhranti-cancel' ].includes(
			document.activeElement && document.activeElement.id
		);
		const id = state.session.id,
			confirmationId = state.session.confirmation.id,
			runId = currentRun() && currentRun().id,
			previousEvents = new Set(
				( state.session.events || [] ).map( ( event ) =>
					String( event.id )
				)
			);
		let completed = false;
		state.busy = true;
		render();
		try {
			state.session = await runRequest(
				'/sessions/' + encodeURIComponent( id ) + '/confirm',
				{ confirmation_id: confirmationId, approved },
				approved
					? __( 'Applying approved action…', 'bhranti' )
					: __( 'Cancelling action…', 'bhranti' )
			);
			completed = true;
		} catch ( error ) {
			notify( errorMessage( error ) );
			await reloadAfterError();
		} finally {
			state.busy = false;
			render();
			if (
				restoreFocus &&
				state.open &&
				! root.querySelector( '#bhranti-confirmation' ) &&
				document.activeElement === document.body
			)
				root.querySelector( '#bhranti-composer' ).focus();
		}
		if (
			! completed ||
			! approved ||
			! revealNewTarget( previousEvents, runId )
		)
			continueRun();
	}
	async function rollback( actionId ) {
		if ( state.busy || activeRun() ) return;
		if (
			! window.confirm(
				__(
					'Roll back this change? Newly created items will move to Trash; updates restore their previous values. Later edits are protected.',
					'bhranti'
				)
			)
		)
			return;
		state.busy = true;
		render();
		try {
			await request(
				'/sessions/' +
					encodeURIComponent( state.session.id ) +
					'/rollback',
				'POST',
				{ action_id: actionId }
			);
			state.session = await request(
				'/sessions/' + encodeURIComponent( state.session.id )
			);
			notify( __( 'Rollback completed.', 'bhranti' ), 'success' );
		} catch ( error ) {
			notify( errorMessage( error ) );
			await reloadAfterError();
		} finally {
			state.busy = false;
			render();
		}
		markExistingEvents();
	}
	async function deleteChat() {
		if (
			state.busy ||
			state.loading ||
			state.capturing ||
			activeRun() ||
			! state.session
		)
			return;
		if (
			! window.confirm(
				__(
					'Delete this conversation permanently? Site actions and their audit records are not undone by deleting a chat.',
					'bhranti'
				)
			)
		)
			return;
		state.busy = true;
		render();
		try {
			await request(
				'/sessions/' + encodeURIComponent( state.session.id ),
				'DELETE'
			);
			const key = draftKey( state.session.id );
			clearReferenceRemoval( state.session.id );
			state.drafts.delete( key );
			credentialDrafts.delete( key );
			state.screenshots.delete( key );
			try {
				window.sessionStorage.removeItem( key );
			} catch ( error ) {
				/* Storage may be unavailable. */
			}
			state.session = null;
			state.draft = restoreDraft( null );
			state.screenshot =
				state.screenshots.get( draftKey( null ) ) || null;
			rememberSession( null );
			await refreshWorkspaceQuietly();
			state.menu = false;
		} catch ( error ) {
			notify( errorMessage( error ) );
		} finally {
			state.busy = false;
			render();
		}
	}
	function focusComposer() {
		const input = root.querySelector( '#bhranti-composer' ),
			focused = document.activeElement;
		if (
			state.open &&
			! interacting &&
			! composing &&
			input &&
			! input.disabled &&
			( ! focused ||
				focused === document.body ||
				focused === input ||
				focused.id === 'bhranti-send' )
		)
			input.focus();
	}
	function displayTime( value ) {
		if ( ! value ) return '';
		const date = new Date( value );
		if ( Number.isNaN( date.getTime() ) ) return '';
		return wp.date && wp.date.dateI18n
			? wp.date.dateI18n( settings.dateFormat || 'M j, Y g:i a', value )
			: date.toLocaleTimeString( [], {
					hour: 'numeric',
					minute: '2-digit',
			  } );
	}
	function targetLink( target ) {
		const url = safeTarget( target );
		if ( ! url ) return null;
		const link = element(
			'a',
			'bhranti-target-link',
			target.title || __( 'Open target', 'bhranti' )
		);
		link.href = url.href;
		link.addEventListener( 'click', ( event ) => {
			if ( runtimeContext().unsaved_changes ) {
				event.preventDefault();
				notify(
					__(
						'Save or discard your editor changes before opening another item.',
						'bhranti'
					),
					'warning'
				);
				render();
			} else rememberDraft();
		} );
		return link;
	}
	function notices() {
		const wrap = element( 'div', 'bhranti-notices' );
		state.notices.forEach( ( notice ) => {
			const node = element(
				'div',
				'bhranti-notice is-' + notice.type,
				element( 'span', '', notice.message ),
				iconButton(
					__( 'Dismiss notice', 'bhranti' ),
					'bhranti-icon-button',
					() => {
						dismissNotice( notice.id );
						render();
					},
					'close',
					viewId( 'notice:' + notice.id + ':dismiss' )
				)
			);
			node.setAttribute(
				'role',
				notice.type === 'error' ? 'alert' : 'status'
			);
			wrap.appendChild( node );
		} );
		return wrap;
	}
	function menu() {
		const wrap = element( 'section', 'bhranti-menu' );
		wrap.id = 'bhranti-menu';
		wrap.setAttribute( 'aria-label', __( 'Chats', 'bhranti' ) );
		wrap.appendChild( element( 'h3', '', __( 'Chats', 'bhranti' ) ) );
		const label = element(
			'label',
			'screen-reader-text',
			__( 'Search chats', 'bhranti' )
		);
		label.htmlFor = 'bhranti-chat-search';
		const search =
			root.querySelector( '#bhranti-chat-search' ) ||
			element( 'input', 'bhranti-chat-search' );
		search.id = 'bhranti-chat-search';
		search.type = 'search';
		search.placeholder = __( 'Search chats…', 'bhranti' );
		search.maxLength = 150;
		if ( search.value !== chatSearch ) search.value = chatSearch;
		if ( ! search.dataset.bound ) {
			search.dataset.bound = '1';
			search.addEventListener( 'input', () => {
				chatSearch = search.value;
				render();
			} );
		}
		wrap.appendChild( label );
		wrap.appendChild( search );
		const list = element( 'ul', 'bhranti-chat-list' ),
			query = chatSearch.trim().toLocaleLowerCase();
		state.workspace.sessions
			.filter(
				( session ) =>
					! query ||
					( session.title || '' )
						.toLocaleLowerCase()
						.includes( query )
			)
			.forEach( ( session ) => {
				const item = button(
					'',
					'bhranti-menu-item bhranti-chat-item',
					() => openSession( session.id ),
					'bhranti-chat-' + session.id
				);
				item.appendChild( icon( 'history' ) );
				item.appendChild(
					element(
						'span',
						'bhranti-chat-info',
						element(
							'span',
							'bhranti-chat-title',
							session.title ||
								__( 'Untitled conversation', 'bhranti' )
						),
						session.updated
							? element(
									'span',
									'bhranti-chat-date',
									displayTime( session.updated )
							  )
							: null
					)
				);
				if (
					state.session &&
					String( state.session.id ) === String( session.id )
				)
					item.setAttribute( 'aria-current', 'page' );
				item.disabled =
					state.busy ||
					state.loading ||
					state.capturing ||
					!! activeRun();
				list.appendChild( element( 'li', '', item ) );
			} );
		wrap.appendChild( list );
		if ( ! list.children.length )
			wrap.appendChild(
				element(
					'p',
					'bhranti-chat-empty',
					query
						? __( 'No matching chats.', 'bhranti' )
						: __( 'No conversations yet.', 'bhranti' )
				)
			);
		if ( ( state.workspace.agents || [] ).length > 1 ) {
			const label = element( 'label', '', __( 'Agent', 'bhranti' ) );
			label.htmlFor = 'bhranti-agent';
			const select = element( 'select', 'bhranti-history' );
			select.id = 'bhranti-agent';
			state.workspace.agents.forEach( ( agent ) => {
				const option = element( 'option', '', agent.name );
				option.value = agent.id;
				select.appendChild( option );
			} );
			select.value = state.agentId;
			select.disabled = state.busy || state.loading || !! activeRun();
			select.addEventListener( 'change', () => {
				state.agentId = select.value;
			} );
			wrap.appendChild( label );
			wrap.appendChild( select );
		}
		if ( state.session ) {
			const remove = button(
				__( 'Delete this chat', 'bhranti' ),
				'bhranti-menu-item',
				deleteChat,
				'bhranti-delete-chat'
			);
			remove.disabled =
				state.busy ||
				state.loading ||
				state.capturing ||
				!! activeRun();
			wrap.appendChild( remove );
		}
		return wrap;
	}
	function runStatus() {
		const run = currentRun();
		if ( ! run ) return null;
		const labels = {
			running:
				run.phase === 'browser'
					? __( 'Waiting for the rendered preview check', 'bhranti' )
					: [ 'prepared', 'action', 'tool' ].includes( run.phase )
					? ( run.next_action && run.next_action.summary ) ||
					  __( 'Running a WordPress action…', 'bhranti' )
					: run.recovery_stage > 0
					? __( 'Continuing in smaller steps…', 'bhranti' )
					: __( 'Planning next step…', 'bhranti' ),
			awaiting_confirmation: __( 'Waiting for your approval', 'bhranti' ),
			awaiting_input: __( 'Waiting for your answer', 'bhranti' ),
			complete: __( 'Completed', 'bhranti' ),
			incomplete: __( 'Partially complete', 'bhranti' ),
			failed: run.can_resume
				? __( 'Connection paused', 'bhranti' )
				: __( 'Task stopped', 'bhranti' ),
			cancelled: __( 'Stopped', 'bhranti' ),
		};
		const retrySeconds = Math.max(
			0,
			Math.ceil( Number( run.retry_at ) - Date.now() / 1000 )
		);
		const label = state.stopRequested
			? __( 'Stopping after the current step…', 'bhranti' )
			: state.pausedForEditor && run.status === 'running'
			? state.editorError ||
			  ( editorSummary().dirty && ! editorState().unsaved_changes
					? __(
							'Save or discard changes in your other editor tabs, then Resume',
							'bhranti'
					  )
					: __(
							'Save or discard your editor changes to continue',
							'bhranti'
					  ) )
			: state.progress
			? state.progress.label
			: state.error && run.status === 'running'
			? __( 'Paused after a request error', 'bhranti' )
			: run.status === 'running' && retrySeconds > 0
			? sprintf(
					run.recovery_stage >= 2
						? __( 'Continuing saved task in %ds', 'bhranti' )
						: __( 'Retrying connection in %ds', 'bhranti' ),
					retrySeconds
			  )
			: labels[ run.status ] || run.status;
		const pending =
			run.status === 'running' &&
			! state.stopRequested &&
			! state.pausedForEditor &&
			! state.error &&
			! retrySeconds;
		const name = pending
			? 'sparkle'
			: run.status === 'complete'
			? 'check'
			: run.status === 'failed'
			? 'error'
			: 'clock';
		const status = element(
			'div',
			'bhranti-run-status',
			element(
				'span',
				'bhranti-status-icon' + ( pending ? ' is-running' : '' ),
				icon( name )
			),
			element( 'span', 'bhranti-status-label', label )
		);
		status.id = 'bhranti-run-status';
		status.setAttribute( 'role', 'status' );
		if (
			[ 'running', 'awaiting_confirmation' ].includes( run.status ) &&
			Number.isInteger( run.step ) &&
			run.step > 0
		)
			status.appendChild(
				element(
					'span',
					'bhranti-step',
					sprintf( __( 'Step %d', 'bhranti' ), run.step )
				)
			);
		if ( state.progress ) {
			const elapsed = element(
				'span',
				'bhranti-elapsed',
				sprintf(
					__( '%ds', 'bhranti' ),
					Math.floor( ( Date.now() - state.progress.started ) / 1000 )
				)
			);
			elapsed.setAttribute( 'aria-live', 'off' );
			status.appendChild( elapsed );
		}
		if ( run.status === 'running' ) {
			const actions = element( 'div', 'bhranti-run-controls' );
			if ( state.pausedForEditor ) {
				const resume = button(
					__( 'Resume', 'bhranti' ),
					'bhranti-inline-button',
					resumeRun,
					'bhranti-resume-run'
				);
				resume.disabled = state.busy || state.stepping;
				actions.appendChild( resume );
			}
			const stop = button(
				__( 'Stop', 'bhranti' ),
				'bhranti-inline-button',
				() => {
					state.stopRequested = true;
					abortRender();
					stopRunKey = resultKey();
					state.pausedForEditor = false;
					render();
					if ( ! state.stepping && ! state.busy ) continueRun();
				},
				'bhranti-stop'
			);
			stop.prepend( icon( 'stop' ) );
			stop.disabled = state.stopRequested;
			actions.appendChild( stop );
			status.appendChild( actions );
		}
		return status;
	}
	function actionPreview() {
		const run = currentRun(),
			action = run && run.next_action;
		if (
			! run ||
			run.status !== 'running' ||
			! [ 'prepared', 'action', 'tool', 'browser' ].includes(
				run.phase
			) ||
			! action
		)
			return null;
		const name =
			action.tool === 'generate_image'
				? 'image'
				: action.tool === 'plugin_request'
				? 'plugin'
				: action.write
				? 'write'
				: 'search';
		const preview = element(
			'section',
			'bhranti-action-preview',
			element(
				'h3',
				'',
				icon( name ),
				action.summary || __( 'Prepared WordPress action', 'bhranti' )
			)
		);
		preview.id = 'bhranti-action-preview';
		if (
			typeof action.intent === 'string' &&
			action.intent.trim() &&
			action.intent.trim() !== action.summary
		)
			preview.appendChild(
				element(
					'p',
					'bhranti-action-intent',
					action.intent.trim().slice( 0, 240 )
				)
			);
		const meta = [ action.tool, action.method, action.path ]
			.filter( ( value ) => typeof value === 'string' && value )
			.map( ( value ) => value.slice( 0, 200 ) )
			.join( ' · ' );
		if ( meta ) {
			const children = [ element( 'p', '', meta ) ];
			if ( Array.isArray( action.details ) && action.details.length )
				children.push(
					element(
						'p',
						'',
						sprintf(
							__( 'Fields: %s', 'bhranti' ),
							action.details
								.filter(
									( value ) => typeof value === 'string'
								)
								.slice( 0, 20 )
								.map( ( value ) => value.slice( 0, 100 ) )
								.join( ', ' )
						)
					)
				);
			preview.appendChild(
				disclosure(
					'action:' + run.id + ':' + run.step + ':' + meta,
					'bhranti-action-meta',
					element( 'summary', '', __( 'Action details', 'bhranti' ) ),
					children
				)
			);
		}
		const link = targetLink( action.target );
		if ( link ) {
			link.textContent = sprintf(
				__( 'Open %s', 'bhranti' ),
				action.target.title || __( 'item', 'bhranti' )
			);
			preview.appendChild( link );
		}
		return preview;
	}
	function taskPlan() {
		const task = state.session && state.session.task;
		if (
			! task ||
			typeof task.objective !== 'string' ||
			! task.objective.trim() ||
			! Array.isArray( task.outcomes ) ||
			! task.outcomes.length
		)
			return null;
		const rows = ( values, checks ) => {
			const list = element( 'ul', 'bhranti-plan-list' );
			const labels = checks
				? {
						pending: __( 'Pending', 'bhranti' ),
						read_back: __( 'Saved state checked', 'bhranti' ),
						rendered_check: __(
							'Rendered checks passed',
							'bhranti'
						),
						manual: __( 'Review needed', 'bhranti' ),
				  }
				: {
						pending: __( 'Pending', 'bhranti' ),
						in_progress: __( 'In progress', 'bhranti' ),
						completed: __( 'Recorded', 'bhranti' ),
						blocked: __( 'Blocked', 'bhranti' ),
				  };
			values.slice( 0, 12 ).forEach( ( row ) => {
				if (
					! row ||
					typeof row.title !== 'string' ||
					! labels[ row.status ]
				)
					return;
				list.appendChild(
					element(
						'li',
						'bhranti-plan-row',
						element(
							'span',
							'bhranti-plan-title',
							row.title.slice( 0, 240 )
						),
						element(
							'span',
							'bhranti-plan-state',
							labels[ row.status ]
						)
					)
				);
			} );
			return list;
		};
		const body = [
			element(
				'p',
				'bhranti-plan-objective',
				task.objective.slice( 0, 1200 )
			),
			rows( task.outcomes, false ),
		];
		if ( Array.isArray( task.checks ) && task.checks.length )
			body.push(
				element( 'h3', '', __( 'Checks', 'bhranti' ) ),
				rows( task.checks, true )
			);
		const plan = disclosure(
			'plan:' + state.session.id,
			'bhranti-work-plan',
			element(
				'summary',
				'bhranti-plan-summary',
				element( 'span', '', __( 'Work plan', 'bhranti' ) ),
				element( 'span', 'bhranti-chevron', icon( 'chevron' ) )
			),
			body
		);
		plan.id = 'bhranti-work-plan';
		return plan;
	}
	function pluginApprovalLabels( action ) {
		const labels = {
			install: [
				__( 'Install plugin', 'bhranti' ),
				__( 'Approve installation', 'bhranti' ),
			],
			activate: [
				__( 'Activate plugin', 'bhranti' ),
				__( 'Approve activation', 'bhranti' ),
			],
			deactivate: [
				__( 'Deactivate plugin', 'bhranti' ),
				__( 'Approve deactivation', 'bhranti' ),
			],
			delete: [
				__( 'Delete plugin', 'bhranti' ),
				__( 'Approve deletion', 'bhranti' ),
			],
		};
		return [ 'install', 'activate', 'deactivate', 'delete' ].includes(
			action
		)
			? labels[ action ]
			: null;
	}
	function approvalDecision( value ) {
		return (
			value.type === 'confirmation' && typeof value.approved === 'boolean'
		);
	}
	function toolEvent( value ) {
		const decision = approvalDecision( value ),
			action =
				decision &&
				value.target &&
				value.target.type === 'plugin' &&
				pluginApprovalLabels( value.action );
		const failed = decision
				? [ 'expired', 'conflict' ].includes( value.status )
				: value.type === 'error' || value.success === false,
			success = value.type === 'action' && value.success === true;
		const decisionLabel = decision
			? value.status === 'expired'
				? __( 'Approval expired', 'bhranti' )
				: value.status === 'conflict'
				? __( 'Approval invalidated', 'bhranti' )
				: value.approved
				? __( 'Approved', 'bhranti' )
				: action
				? __( 'Cancelled', 'bhranti' )
				: __( 'Denied', 'bhranti' )
			: '';
		const types = {
			post: __( 'post', 'bhranti' ),
			page: __( 'page', 'bhranti' ),
			attachment: __( 'media', 'bhranti' ),
			wp_template: __( 'template', 'bhranti' ),
			wp_template_part: __( 'template part', 'bhranti' ),
			plugin: __( 'plugin', 'bhranti' ),
		};
		const type = value.target && types[ value.target.type ];
		const labels = {
			image: __( 'Generated image', 'bhranti' ),
			rollback: __( 'Restored changes', 'bhranti' ),
			write:
				value.summary || __( 'WordPress action completed', 'bhranti' ),
			read: type
				? sprintf( __( 'Read %s', 'bhranti' ), type )
				: value.summary || __( 'Read site information', 'bhranti' ),
		};
		const name = decision
			? action
				? 'plugin'
				: 'check'
			: failed
			? 'error'
			: {
					image: 'image',
					rollback: 'rollback',
					write: 'write',
					read: type ? 'document' : 'search',
			  }[ value.operation ] || 'context';
		const title = element(
			'span',
			'bhranti-tool-title',
			element(
				'span',
				'bhranti-tool-label',
				decision
					? action
						? sprintf(
								__( '%s · %s', 'bhranti' ),
								decisionLabel,
								action[ 0 ]
						  )
						: decisionLabel
					: failed
					? __( 'Action issue', 'bhranti' )
					: labels[ value.operation ] ||
					  value.summary ||
					  __( 'WordPress action', 'bhranti' )
			),
			value.target && value.target.title
				? element( 'span', 'bhranti-tool-target', value.target.title )
				: null
		);
		const summary = element(
			'summary',
			'bhranti-tool-summary',
			element( 'span', 'bhranti-tool-icon', icon( name ) ),
			title,
			statusIcon(
				failed
					? 'error'
					: decision
					? value.approved
						? 'check'
						: 'close'
					: success
					? 'check'
					: 'clock',
				decision
					? decisionLabel
					: failed
					? __( 'Failed', 'bhranti' )
					: success
					? __( 'Completed', 'bhranti' )
					: __( 'Recorded', 'bhranti' ),
				'bhranti-tool-state'
			),
			element( 'span', 'bhranti-chevron', icon( 'chevron' ) )
		);
		const key =
				'event:' +
				( value.id ||
					[ value.run_id, value.created, value.summary ].join(
						':'
					) ),
			date = element(
				'time',
				'bhranti-event-date',
				displayTime( value.created )
			);
		if ( value.created ) date.dateTime = value.created;
		const link = targetLink( value.target );
		if ( link ) link.id = viewId( key + ':target' );
		const body = element(
			'div',
			'bhranti-tool-body',
			element( 'p', '', value.summary || '' ),
			decision && value.target && value.target.title && ! link
				? element( 'p', '', value.target.title )
				: null,
			date,
			link
		);
		if ( value.rollback_available && value.action_id ) {
			const undo = button(
				__( 'Roll back', 'bhranti' ),
				'bhranti-inline-button',
				() => rollback( value.action_id ),
				viewId( key + ':rollback' )
			);
			undo.disabled = state.busy || !! activeRun();
			body.appendChild( undo );
		}
		return disclosure(
			key,
			'bhranti-tool-event ' +
				( failed ? 'is-error' : success ? 'is-success' : 'is-note' ),
			summary,
			[ body ],
			failed
		);
	}
	function toolGroup( values ) {
		if ( values.length === 1 ) return toolEvent( values[ 0 ] );
		const decisions = values.some( approvalDecision ),
			run = currentRun(),
			open =
				! decisions &&
				run &&
				run.status === 'running' &&
				values[ 0 ].run_id === run.id;
		return disclosure(
			'group:' + ( values[ 0 ].run_id || '' ) + ':' + values[ 0 ].id,
			'bhranti-tool-group',
			element(
				'summary',
				'bhranti-tool-group-summary',
				icon( decisions ? 'history' : 'check' ),
				element(
					'span',
					'',
					sprintf(
						decisions
							? __( '%d task events', 'bhranti' )
							: __( '%d completed actions', 'bhranti' ),
						values.length
					)
				),
				element( 'span', 'bhranti-chevron', icon( 'chevron' ) )
			),
			values.map( toolEvent ),
			!! open
		);
	}
	function recoveryCard( title, text, label, action ) {
		const card = element(
			'section',
			'bhranti-recovery-card',
			element( 'h3', '', title ),
			element( 'p', '', text )
		);
		card.setAttribute( 'role', 'status' );
		card.id = viewId( 'recovery:' + label );
		const control = button(
			label,
			'bhranti-small-button',
			action,
			card.id + '-control'
		);
		control.disabled = state.busy || state.loading || state.stepping;
		card.appendChild(
			element( 'div', 'bhranti-confirmation-actions', control )
		);
		return card;
	}
	function conversation() {
		const stream = element( 'div', 'bhranti-stream' );
		stream.id = 'bhranti-stream';
		stream.dataset.session = String(
			( state.session && state.session.id ) || 'new'
		);
		stream.setAttribute( 'role', 'log' );
		stream.setAttribute(
			'aria-label',
			__( 'Bhranti conversation', 'bhranti' )
		);
		stream.setAttribute( 'aria-live', 'polite' );
		const run = currentRun(),
			messages = ( state.session && state.session.messages ) || [],
			lastMessage = messages[ messages.length - 1 ];
		const pausedMessage =
			run &&
			run.status === 'failed' &&
			run.can_resume === true &&
			lastMessage &&
			lastMessage.role === 'error' &&
			( ! lastMessage.run_id || lastMessage.run_id === run.id )
				? lastMessage
				: null;
		if ( state.session && state.session.title ) {
			const title = element(
				'p',
				'bhranti-thread-label',
				state.session.title
			);
			title.id = 'bhranti-thread-label';
			title.title = state.session.title;
			stream.appendChild( title );
		}
		if ( ! state.session || ! messages.length ) {
			const empty = element(
				'div',
				'bhranti-empty',
				element( 'span', 'bhranti-empty-icon', icon( 'sparkle' ) ),
				element(
					'h3',
					'',
					__( 'What would you like to work on?', 'bhranti' )
				),
				element(
					'p',
					'',
					__(
						'Create, edit, or explore your WordPress site.',
						'bhranti'
					)
				)
			);
			empty.id = 'bhranti-empty';
			stream.appendChild( empty );
		} else {
			const timeline = ( state.session.messages || [] )
				.map( ( value ) => ( { kind: 'message', value } ) )
				.concat(
					( state.session.events || [] ).map( ( value ) => ( {
						kind: 'event',
						value,
					} ) )
				);
			const rank = ( item ) =>
				item.kind === 'event' ? 1 : item.value.role === 'user' ? 0 : 2;
			timeline.sort(
				( a, b ) =>
					( Date.parse( a.value.created ) || 0 ) -
						( Date.parse( b.value.created ) || 0 ) ||
					rank( a ) - rank( b )
			);
			let actions = [];
			const flushActions = () => {
				if ( actions.length )
					stream.appendChild( toolGroup( actions ) );
				actions = [];
			};
			timeline.forEach( ( { kind, value } ) => {
				if ( kind === 'event' ) {
					if (
						( value.type === 'action' && value.success === true ) ||
						( approvalDecision( value ) &&
							! [ 'expired', 'conflict' ].includes(
								value.status
							) )
					) {
						if (
							actions.length &&
							actions[ 0 ].run_id !== value.run_id
						)
							flushActions();
						actions.push( value );
					} else {
						flushActions();
						stream.appendChild( toolEvent( value ) );
					}
					return;
				}
				flushActions();
				const role = [ 'user', 'assistant', 'error' ].includes(
					value.role
				)
					? value.role
					: 'assistant';
				if ( value === pausedMessage ) return;
				if ( role === 'error' && value.resolved === true ) {
					stream.appendChild(
						disclosure(
							'resolved:' + ( value.id || value.created ),
							'bhranti-resolved-error',
							element(
								'summary',
								'',
								__(
									'Earlier connection issue · resumed',
									'bhranti'
								)
							),
							[ element( 'p', '', value.content || '' ) ]
						)
					);
					return;
				}
				const requester =
					( state.workspace.user || {} ).name ||
					__( 'You', 'bhranti' );
				const title =
					role === 'user'
						? requester
						: role === 'error'
						? __( 'Request issue', 'bhranti' )
						: __( 'Bhranti', 'bhranti' );
				const content = element(
					'div',
					'bhranti-message-content',
					value.content || ''
				);
				if (
					role === 'assistant' &&
					typeof value.rendered === 'string'
				) {
					// Only this server-produced field passes native WordPress chat HTML sanitization.
					content.innerHTML = value.rendered;
					content.classList.add( 'is-rendered' );
				}
				const article = element(
					'article',
					'bhranti-message is-' + role,
					element(
						'div',
						'bhranti-message-meta screen-reader-text',
						element( 'span', '', title ),
						element( 'span', '', displayTime( value.created ) )
					),
					content
				);
				article.id = viewId(
					'message:' + ( value.id || value.created + ':' + role )
				);
				if (
					role === 'assistant' &&
					window.navigator.clipboard &&
					window.navigator.clipboard.writeText
				)
					article.appendChild(
						iconButton(
							__( 'Copy response', 'bhranti' ),
							'bhranti-copy-button',
							async () => {
								try {
									await window.navigator.clipboard.writeText(
										value.content || ''
									);
									notify(
										__( 'Response copied.', 'bhranti' ),
										'success'
									);
								} catch ( error ) {
									notify(
										__(
											'The response could not be copied.',
											'bhranti'
										)
									);
								}
								render();
							},
							'copy',
							article.id + '-copy'
						)
					);
				stream.appendChild( article );
			} );
			flushActions();
		}
		if ( state.error )
			stream.appendChild(
				recoveryCard(
					__( 'Reconnect to continue', 'bhranti' ),
					state.error,
					__( 'Reconnect', 'bhranti' ),
					reconnectChat
				)
			);
		else if ( run && run.status === 'failed' && run.can_resume === true )
			stream.appendChild(
				recoveryCard(
					__( 'Connection paused', 'bhranti' ),
					( pausedMessage && pausedMessage.content ) ||
						__(
							'Your completed work is saved. Resume from the last checkpoint when you’re ready.',
							'bhranti'
						),
					__( 'Resume task', 'bhranti' ),
					resumeTask
				)
			);
		if (
			state.reconnecting ||
			( state.connectionError && ! state.error )
		) {
			const connection = element(
				'p',
				'bhranti-connection-status',
				state.reconnecting
					? __(
							'Reconnecting… Your conversation is safe.',
							'bhranti'
					  )
					: state.connectionError
			);
			connection.setAttribute( 'role', 'status' );
			stream.appendChild( connection );
		}
		if ( editorSync.message ) {
			const pending = element(
				'p',
				'bhranti-connection-status',
				editorSync.message
			);
			pending.setAttribute( 'role', 'status' );
			stream.appendChild( pending );
		}
		const plan = taskPlan();
		if ( plan ) stream.appendChild( plan );
		const preview = actionPreview();
		if ( preview ) stream.appendChild( preview );
		const status = ! activeRun() && runStatus();
		if ( status ) stream.appendChild( status );
		const confirmation = state.session && state.session.confirmation;
		if ( confirmation ) {
			const action =
				confirmation.target &&
				confirmation.target.type === 'plugin' &&
				pluginApprovalLabels( confirmation.action );
			const heading = element(
				'h3',
				'',
				icon( action ? 'plugin' : 'clock' ),
				action ? action[ 0 ] : __( 'Approval required', 'bhranti' )
			);
			heading.id = 'bhranti-confirmation-title';
			const target =
				confirmation.target && confirmation.target.title
					? element(
							'p',
							'bhranti-confirmation-target',
							targetLink( confirmation.target ) ||
								confirmation.target.title
					  )
					: null;
			if ( target ) target.id = 'bhranti-confirmation-target';
			const description = element(
				'p',
				'bhranti-confirmation-description',
				confirmation.summary || ''
			);
			description.id = 'bhranti-confirmation-description';
			const card = element(
				'section',
				'bhranti-confirmation',
				heading,
				target,
				description
			);
			card.id = 'bhranti-confirmation';
			card.setAttribute( 'aria-labelledby', heading.id );
			const yes = button(
					action ? action[ 1 ] : __( 'Approve', 'bhranti' ),
					'bhranti-small-button is-primary',
					() => confirmAction( true ),
					'bhranti-confirm'
				),
				no = button(
					action
						? __( 'Cancel', 'bhranti' )
						: __( 'Deny', 'bhranti' ),
					'bhranti-small-button',
					() => confirmAction( false ),
					'bhranti-cancel'
				);
			yes.disabled = no.disabled = state.busy;
			[ yes, no ].forEach( ( control ) =>
				control.setAttribute(
					'aria-describedby',
					[ heading.id, target && target.id, description.id ]
						.filter( Boolean )
						.join( ' ' )
				)
			);
			card.appendChild(
				element( 'div', 'bhranti-confirmation-actions', yes, no )
			);
			stream.appendChild( card );
		}
		if ( ( state.loading || state.busy ) && ! activeRun() ) {
			const status = element(
				'div',
				'bhranti-progress',
				state.loading
					? __( 'Loading…', 'bhranti' )
					: __( 'Working…', 'bhranti' )
			);
			status.setAttribute( 'role', 'status' );
			stream.appendChild( status );
		}
		Array.from( stream.children ).forEach( ( node, index ) => {
			if ( ! node.id )
				node.id = viewId( 'notice:' + node.className + ':' + index );
		} );
		const previous = root.querySelector( '#bhranti-stream' );
		if ( previous && previous.dataset.session === stream.dataset.session ) {
			syncChildren( previous, Array.from( stream.children ) );
			return previous;
		}
		return stream;
	}
	function contextChip( label, name, remove, id ) {
		return element(
			'span',
			'bhranti-context-chip',
			icon( name ),
			element( 'span', '', label ),
			iconButton(
				__( 'Remove context', 'bhranti' ),
				'bhranti-chip-remove',
				remove,
				'close',
				id
			)
		);
	}
	function composer() {
		const form = element( 'form', 'bhranti-composer-form' );
		form.id = 'bhranti-composer-form';
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			sendMessage();
		} );
		const context = runtimeContext(),
			chips = element( 'div', 'bhranti-context-chips' );
		const labels = {
			page: __( 'Page', 'bhranti' ),
			post: __( 'Post', 'bhranti' ),
			wp_template: __( 'Template', 'bhranti' ),
			wp_template_part: __( 'Template Part', 'bhranti' ),
		};
		if ( state.attachPage ) {
			const label =
				labels[ context.post_type ] ||
				( context.title
					? context.title
					: __( 'Current page', 'bhranti' ) );
			const chip = contextChip(
				label,
				'context',
				() => {
					state.attachPage = false;
					render();
				},
				'bhranti-remove-context'
			);
			chip.title = context.title || context.url || '';
			chips.appendChild( chip );
		}
		if ( state.attachPage && state.attachBlock && context.selected_block ) {
			let label = context.selected_block.name;
			if ( wp.blocks && wp.blocks.getBlockType ) {
				const type = wp.blocks.getBlockType( label );
				if ( type ) label = type.title;
			}
			chips.appendChild(
				contextChip(
					label,
					'block',
					() => {
						state.attachBlock = false;
						render();
					},
					'bhranti-remove-block'
				)
			);
		}
		if (
			! state.screenshot &&
			retainedReferenceImage() &&
			! referenceImageRemoved()
		) {
			const remove = iconButton(
				__( 'Remove reference image', 'bhranti' ),
				'bhranti-chip-remove',
				removeReferenceImage,
				'close',
				'bhranti-remove-reference-image'
			);
			remove.disabled =
				state.busy ||
				state.loading ||
				state.capturing ||
				!! activeRun();
			chips.appendChild(
				element(
					'span',
					'bhranti-context-chip',
					icon( 'image' ),
					element( 'span', '', __( 'Reference image', 'bhranti' ) ),
					remove
				)
			);
		}
		const input =
			root.querySelector( '#bhranti-composer' ) ||
			element( 'textarea', 'bhranti-composer' );
		input.id = 'bhranti-composer';
		input.rows = 2;
		input.maxLength = 12000;
		if ( input.value !== state.draft ) input.value = state.draft;
		input.placeholder = activeRun()
			? __( 'Draft your next message…', 'bhranti' )
			: __( 'Ask anything…', 'bhranti' );
		input.setAttribute( 'aria-label', __( 'Message Bhranti', 'bhranti' ) );
		input.setAttribute(
			'aria-describedby',
			'bhranti-composer-hint bhranti-disclosure'
		);
		input.disabled =
			state.loading ||
			state.capturing ||
			( state.busy &&
				! state.stepping &&
				! activeRun() &&
				! credentialReview );
		const sendingBlocked = () =>
			state.busy ||
			state.loading ||
			state.capturing ||
			!! state.error ||
			!! activeRun() ||
			! state.draft.trim();
		const send = iconButton(
			__( 'Send message', 'bhranti' ),
			'bhranti-send-button',
			sendMessage,
			'send',
			'bhranti-send'
		);
		send.disabled = sendingBlocked();
		if ( ! input.dataset.bound ) {
			input.dataset.bound = '1';
			input.addEventListener( 'input', () => {
				state.draft = input.value;
				if ( state.credentialWarning ) {
					state.credentialWarning = submissionHasCredentials(
						state.draft
					);
					updateCredentialWarning();
				}
				rememberDraft();
				const control = root.querySelector( '#bhranti-send' );
				if ( control ) control.disabled = sendingBlocked();
				sizeComposer( input );
			} );
			input.addEventListener( 'keydown', ( event ) => {
				if (
					event.key !== 'Enter' ||
					event.shiftKey ||
					event.altKey ||
					event.isComposing ||
					composing ||
					event.keyCode === 229
				)
					return;
				event.preventDefault();
				if ( ! event.repeat ) sendMessage();
			} );
		}
		const attach = iconButton(
			__( 'Attach current page context', 'bhranti' ),
			'bhranti-icon-button',
			() => {
				state.attachPage = true;
				state.attachBlock = true;
				render();
			},
			'plus',
			'bhranti-attach'
		);
		if ( state.screenshot ) {
			const image = element( 'img', 'bhranti-screenshot-preview' );
			image.src =
				'data:' +
				state.screenshot.mime_type +
				';base64,' +
				state.screenshot.data;
			image.alt = __( 'Attached image', 'bhranti' );
			const remove = iconButton(
				__( 'Remove image', 'bhranti' ),
				'bhranti-icon-button',
				() => {
					state.screenshot = null;
					rememberDraft();
					render();
				},
				'close',
				'bhranti-remove-screenshot'
			);
			remove.disabled =
				state.busy ||
				state.loading ||
				state.capturing ||
				!! activeRun();
			form.appendChild(
				element(
					'div',
					'bhranti-screenshot',
					element(
						'div',
						'bhranti-image-context',
						image,
						element(
							'p',
							'bhranti-image-sharing',
							__( 'Shared with AI when you send.', 'bhranti' )
						)
					),
					remove
				)
			);
		}
		const file = element( 'input' );
		file.type = 'file';
		file.accept = 'image/png,image/jpeg,image/webp';
		file.hidden = true;
		file.id = 'bhranti-image-file';
		file.addEventListener( 'change', () => {
			const selected = file.files && file.files[ 0 ];
			file.value = '';
			if ( selected ) attachReferenceImage( selected );
		} );
		const upload = iconButton(
			__( 'Attach reference image', 'bhranti' ),
			'bhranti-icon-button',
			() => file.click(),
			'image',
			'bhranti-attach-image'
		);
		upload.disabled =
			state.busy || state.loading || state.capturing || !! activeRun();
		const screenshot = iconButton(
			state.capturing
				? __( 'Choosing screenshot…', 'bhranti' )
				: __( 'Attach tab screenshot', 'bhranti' ),
			'bhranti-icon-button',
			attachScreenshot,
			'camera',
			'bhranti-screenshot'
		);
		screenshot.disabled =
			state.busy || state.loading || state.capturing || !! activeRun();
		const hint = element(
			'span',
			'screen-reader-text',
			__( 'Enter to send. Shift+Enter for new line.', 'bhranti' )
		);
		hint.id = 'bhranti-composer-hint';
		const warning = element(
			'p',
			'bhranti-credential-warning',
			credentialWarning()
		);
		warning.id = 'bhranti-credential-warning';
		warning.setAttribute( 'role', 'alert' );
		warning.hidden = ! state.credentialWarning;
		form.appendChild( file );
		form.appendChild( chips );
		form.appendChild( input );
		form.appendChild( warning );
		updateCredentialWarning( input, warning );
		form.appendChild(
			element(
				'div',
				'bhranti-composer-actions',
				element(
					'div',
					'bhranti-header-controls',
					attach,
					upload,
					screenshot
				),
				hint,
				send
			)
		);
		return form;
	}
	function sizeComposer( input ) {
		input.style.height = 'auto';
		input.style.height =
			Math.max( 48, Math.min( 150, input.scrollHeight ) ) + 'px';
	}
	function resizeHandle() {
		const handle = element( 'div', 'bhranti-resize' );
		handle.tabIndex = 0;
		handle.setAttribute( 'role', 'separator' );
		handle.setAttribute(
			'aria-label',
			__( 'Resize Bhranti sidebar', 'bhranti' )
		);
		handle.setAttribute( 'aria-orientation', 'vertical' );
		function setWidth( width ) {
			state.width = Math.max(
				300,
				Math.min( width, Math.min( 650, window.innerWidth - 320 ) )
			);
			document.documentElement.style.setProperty(
				'--bhranti-panel-width',
				state.width + 'px'
			);
			handle.setAttribute( 'aria-valuenow', state.width );
		}
		handle.setAttribute( 'aria-valuemin', '300' );
		handle.setAttribute(
			'aria-valuemax',
			String( Math.min( 650, window.innerWidth - 320 ) )
		);
		handle.setAttribute( 'aria-valuenow', state.width );
		handle.addEventListener( 'keydown', ( event ) => {
			if ( [ 'ArrowLeft', 'ArrowRight' ].includes( event.key ) ) {
				event.preventDefault();
				setWidth(
					state.width + ( event.key === 'ArrowLeft' ? 24 : -24 )
				);
			}
		} );
		handle.addEventListener( 'pointerdown', ( event ) => {
			event.preventDefault();
			handle.setPointerCapture( event.pointerId );
			const start = event.clientX,
				width = state.width;
			const move = ( pointer ) =>
				setWidth( width + start - pointer.clientX );
			const end = () => {
				handle.removeEventListener( 'pointermove', move );
				handle.removeEventListener( 'pointerup', end );
				handle.removeEventListener( 'pointercancel', end );
			};
			handle.addEventListener( 'pointermove', move );
			handle.addEventListener( 'pointerup', end );
			handle.addEventListener( 'pointercancel', end );
		} );
		return handle;
	}
	function render() {
		syncEditorControl();
		observeEditorChanges();
		observeSettingsChanges();
		// Native editor focus updates can otherwise replace a button before its click fires.
		if ( interacting ) {
			renderPending = true;
			return;
		}
		renderPending = false;
		// Native details toggles can precede their asynchronous toggle event.
		root.querySelectorAll( 'details[data-bhranti-disclosure]' ).forEach(
			( node ) => {
				if ( String( node.open ) !== node.dataset.openAtRender ) {
					disclosureStates.set(
						node.dataset.bhrantiDisclosure,
						node.open
					);
					node.dataset.openAtRender = String( node.open );
				}
			}
		);
		if (
			! state.busy &&
			! state.stepping &&
			! activeRun() &&
			state.workspace.agents &&
			! state.workspace.agents.some(
				( agent ) => agent.id === state.agentId
			)
		)
			state.agentId = 'bhranti';
		const context = runtimeContext(),
			retry =
				currentRun() && currentRun().status === 'running'
					? Math.max(
							0,
							Math.ceil(
								Number( currentRun().retry_at ) -
									Date.now() / 1000
							)
					  )
					: 0;
		const fingerprint = JSON.stringify( [
			state.open,
			state.initialized,
			state.loading,
			state.busy,
			state.stepping,
			state.stopRequested,
			state.pausedForEditor,
			state.editorError,
			state.progress,
			state.menu,
			state.workspace,
			state.session,
			state.draft,
			state.screenshot,
			referenceImageRemoved(),
			state.capturing,
			state.error,
			state.credentialWarning,
			credentialReview,
			state.reconnecting,
			state.connectionError,
			state.notices,
			state.width,
			state.attachPage,
			state.attachBlock,
			state.agentId,
			editorSync.message,
			editorSummary().dirty,
			editorState().unsaved_changes,
			closeUnavailable(),
			context.post_type,
			context.title,
			context.selected_block && [
				context.selected_block.client_id,
				context.selected_block.name,
			],
			retry,
			chatSearch,
		] );
		if ( fingerprint === renderedFingerprint ) {
			syncTrigger();
			return;
		}
		renderedFingerprint = fingerprint;
		const focused = document.activeElement,
			focusId = focused && root.contains( focused ) ? focused.id : null,
			selection = [ 'bhranti-composer', 'bhranti-chat-search' ].includes(
				focusId
			)
				? [ focused.selectionStart, focused.selectionEnd ]
				: null;
		const previousMenu = root.querySelector( '#bhranti-menu' );
		if ( previousMenu ) menuScroll = previousMenu.scrollTop;
		const previousStream = root.querySelector( '.bhranti-stream' ),
			session = String( ( state.session && state.session.id ) || 'new' );
		if ( previousStream && streamSession !== null )
			scrollPositions.set( streamSession, {
				top: previousStream.scrollTop,
				bottom:
					previousStream.scrollHeight -
						previousStream.clientHeight -
						previousStream.scrollTop <=
					48,
			} );
		const position = scrollPositions.get( session );
		streamSession = session;
		root.className = 'bhranti-app';
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-modal', 'false' );
		root.setAttribute( 'aria-labelledby', 'bhranti-panel-title' );
		root.hidden = ! state.open;
		document.documentElement.style.setProperty(
			'--bhranti-panel-width',
			state.width + 'px'
		);
		const chats = iconButton(
			__( 'Chats', 'bhranti' ),
			'bhranti-icon-button',
			toggleChats,
			'history',
			'bhranti-chats'
		);
		chats.setAttribute( 'aria-expanded', String( state.menu ) );
		chats.setAttribute( 'aria-controls', 'bhranti-menu' );
		const start = iconButton(
			__( 'New chat', 'bhranti' ),
			'bhranti-icon-button',
			newChat,
			'plus',
			'bhranti-new-chat'
		);
		start.disabled =
			state.busy || state.loading || state.capturing || !! activeRun();
		const controls = element(
				'div',
				'bhranti-header-controls',
				chats,
				start
			),
			trailing = element( 'div', 'bhranti-header-controls' );
		if ( settings.settingsUrl ) {
			const link = element(
				'a',
				'bhranti-icon-button',
				icon( 'settings' )
			);
			link.id = 'bhranti-settings';
			link.href = settings.settingsUrl;
			link.setAttribute(
				'aria-label',
				__( 'Bhranti Settings', 'bhranti' )
			);
			link.title = __( 'Bhranti Settings', 'bhranti' );
			trailing.appendChild( link );
		}
		const close = iconButton(
			__( 'Close Bhranti', 'bhranti' ),
			'bhranti-icon-button',
			closePanel,
			'close',
			'bhranti-close'
		);
		close.disabled = closeUnavailable();
		if ( close.disabled )
			close.title = __(
				'Available after the current change finishes',
				'bhranti'
			);
		trailing.appendChild( close );
		const brand = element(
			'h2',
			'bhranti-brand',
			__( 'Bhranti', 'bhranti' )
		);
		brand.id = 'bhranti-panel-title';
		const header = element(
			'header',
			'bhranti-header',
			controls,
			brand,
			trailing
		);
		header.id = 'bhranti-header';
		const details = disclosure(
			'disclosure',
			'bhranti-disclosure',
			element(
				'summary',
				'',
				__( 'You’re chatting with AI. Details.', 'bhranti' )
			),
			[
				element(
					'p',
					'',
					__(
						'Sending shares your messages, recent conversation, configured system prompt and guardrails, selected agent and skill instructions, requested site content, image-generation prompts, query and action results, relevant saved preferences and verified image facts, inspected public reference pages, rendered-preview accessibility findings and layout measurements, attached rendered-page HTML/text, editor context and any screenshot or reference image you choose with your configured WordPress AI provider. Images are prepared in your browser and shared only when you send. A chosen reference stays with the same task through its questions and answers until removed or replaced. Provider terms apply. Site changes and rollback are recorded in Logs.',
						'bhranti'
					)
				),
			]
		);
		details.id = 'bhranti-disclosure';
		if ( ! ( state.workspace.ai || {} ).available && state.initialized )
			details.appendChild(
				element(
					'p',
					'',
					__(
						'No AI provider is configured. Use WordPress connectors in the Bhranti menu.',
						'bhranti'
					)
				)
			);
		const streamNode = conversation(),
			dock = activeRun()
				? element( 'div', 'bhranti-task-dock', runStatus() )
				: null;
		if ( dock ) dock.id = 'bhranti-task-dock';
		const jump = iconButton(
			__( 'Jump to latest', 'bhranti' ),
			'bhranti-jump-latest',
			( event ) => {
				const stream = root.querySelector( '.bhranti-stream' );
				if ( stream ) {
					stream.scrollTop = stream.scrollHeight;
					scrollPositions.set(
						String(
							( state.session && state.session.id ) || 'new'
						),
						{ top: stream.scrollTop, bottom: true }
					);
				}
				event.currentTarget.hidden = true;
				const input = root.querySelector( '#bhranti-composer' );
				if ( input && ! input.disabled ) input.focus();
				else root.querySelector( '#bhranti-close' ).focus();
			},
			'down',
			'bhranti-jump-latest'
		);
		jump.appendChild(
			element( 'span', '', __( 'Jump to latest', 'bhranti' ) )
		);
		jump.hidden = ! position || position.bottom;
		const region =
			root.querySelector( '#bhranti-conversation-region' ) ||
			element( 'div', 'bhranti-conversation-region' );
		region.id = 'bhranti-conversation-region';
		syncChildren( region, [ streamNode, jump ] );
		const handle = resizeHandle();
		handle.id = 'bhranti-resize';
		const noticeNodes = notices();
		noticeNodes.id = 'bhranti-notices';
		syncChildren(
			root,
			[
				handle,
				header,
				state.menu ? menu() : null,
				noticeNodes,
				region,
				dock,
				composer(),
				details,
			].filter( Boolean )
		);
		syncTrigger();
		if ( focusId ) {
			const node = document.getElementById( focusId );
			if ( node && ! node.disabled ) {
				node.focus();
				if ( selection ) node.setSelectionRange( ...selection );
			}
		}
		const menuNode = root.querySelector( '#bhranti-menu' );
		if ( menuNode ) menuNode.scrollTop = menuScroll;
		sizeComposer( root.querySelector( '#bhranti-composer' ) );
		const stream = root.querySelector( '.bhranti-stream' );
		if ( stream && ! stream.dataset.bound ) {
			stream.dataset.bound = '1';
			stream.addEventListener(
				'scroll',
				() => {
					const bottom =
						stream.scrollHeight -
							stream.clientHeight -
							stream.scrollTop <=
						48;
					scrollPositions.set( stream.dataset.session, {
						top: stream.scrollTop,
						bottom,
					} );
					const jump = root.querySelector( '#bhranti-jump-latest' );
					if ( jump ) jump.hidden = bottom;
				},
				{ passive: true }
			);
		}
		if ( stream )
			stream.scrollTop =
				! position || position.bottom
					? stream.scrollHeight
					: position.top;
		requestAnimationFrame( () => {
			if ( stream && stream.isConnected )
				stream.scrollTop =
					! position || position.bottom
						? stream.scrollHeight
						: position.top;
		} );
	}

	window.addEventListener( 'bhranti:settings-saved', async () => {
		await refreshWorkspaceQuietly();
		render();
	} );
	window.addEventListener( 'bhranti:settings-state', () => {
		const form = document.getElementById( 'bhranti-settings-app' );
		if ( ! form ) return;
		pageFormDirty = form.dataset.bhrantiDirty === 'true';
		syncEditorControl();
		if ( heartbeatToken && ! pageSuspended )
			reportEditor().catch( () => {} );
		if ( state.open ) render();
	} );
	[ 'input', 'change' ].forEach( ( name ) =>
		document.addEventListener( name, ( event ) => {
			if (
				! document.querySelector(
					'#editor.block-editor__container, .edit-site-layout'
				) &&
				! /\/site-editor\.php$/.test( window.location.pathname ) &&
				! root.contains( event.target ) &&
				event.target.closest &&
				! event.target.closest( '#bhranti-settings-app' ) &&
				event.target.closest(
					'#wpbody-content form, .wp-site-blocks form, #page form, main form, [role="main"] form'
				)
			) {
				pageFormDirty = true;
				syncEditorControl();
				if ( heartbeatToken && ! pageSuspended )
					reportEditor().catch( () => {} );
			}
		} )
	);
	[ 'focusin', 'focusout' ].forEach( ( name ) =>
		document.addEventListener(
			name,
			() => window.setTimeout( nativeEditorChanged, 0 ),
			true
		)
	);
	function finishInteraction( event ) {
		if ( composing ) return;
		if ( ! event || ! [ 'pointerup', 'keyup' ].includes( event.type ) )
			interacting = false;
		window.clearTimeout( interactionTimer );
		interactionTimer = window.setTimeout( () => {
			interactionTimer = null;
			interacting = false;
			if ( renderPending && ! pageSuspended ) render();
		}, 0 );
	}
	function beginInteraction( event ) {
		if (
			event.target.closest &&
			event.target.closest(
				'#bhranti-app, #bhranti-site-opener, #wp-admin-bar-bhranti, [data-bhranti-editor-open]'
			)
		) {
			interacting = true;
			window.clearTimeout( interactionTimer );
			interactionTimer = null;
		}
	}
	document.addEventListener( 'pointerdown', beginInteraction, true );
	document.addEventListener( 'keydown', beginInteraction, true );
	root.addEventListener( 'compositionstart', () => {
		composing = true;
		interacting = true;
		window.clearTimeout( interactionTimer );
		interactionTimer = null;
	} );
	root.addEventListener( 'compositionend', () => {
		composing = false;
		finishInteraction();
	} );
	[ 'pointerup', 'pointercancel', 'keyup', 'click' ].forEach( ( name ) =>
		document.addEventListener( name, finishInteraction, true )
	);
	window.addEventListener( 'blur', () => {
		composing = false;
		finishInteraction();
	} );
	document.addEventListener( 'click', ( event ) => {
		const trigger =
			event.target.closest &&
			event.target.closest(
				'#wp-admin-bar-bhranti > a, [data-bhranti-open]'
			);
		if ( trigger ) {
			event.preventDefault();
			if ( state.open ) closePanel();
			else openPanel( trigger );
		}
	} );
	document.addEventListener( 'click', ( event ) => {
		if (
			state.menu &&
			event.target.closest &&
			! event.target.closest( '#bhranti-menu, #bhranti-chats' )
		) {
			state.menu = false;
			render();
		}
	} );
	document.addEventListener( 'bhranti:editor-launcher-ready', syncTrigger );
	document.addEventListener( 'bhranti:editor-launcher-toggle', ( event ) => {
		if ( state.open ) closePanel();
		else openPanel( event.detail.trigger );
	} );
	window.addEventListener( 'resize', syncTrigger );
	root.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			if ( state.menu ) {
				state.menu = false;
				render();
				root.querySelector( '#bhranti-chats' ).focus();
			} else closePanel();
		}
	} );
	window.addEventListener(
		'keydown',
		( event ) => {
			if (
				( ! editorControl && ! siteControl ) ||
				! ( event.ctrlKey || event.metaKey ) ||
				! [ 'z', 'y', 's' ].includes( event.key.toLowerCase() )
			)
				return;
			// Core undo/save shortcuts listen on document even while its editor is inert.
			event.stopImmediatePropagation();
			const textUndo =
				event.key.toLowerCase() !== 's' &&
				event.target.closest &&
				event.target.closest(
					'textarea, input, [contenteditable="true"]'
				) &&
				( root.contains( event.target ) ||
					( siteControl &&
						! editorControl &&
						! siteControl.nodes.some( ( { node } ) =>
							node.contains( event.target )
						) ) );
			if ( ! textUndo ) event.preventDefault();
		},
		true
	);
	if ( wp.data && wp.data.subscribe )
		wp.data.subscribe( () => {
			nativeEditorChanged();
			if (
				heartbeatToken &&
				! pageSuspended &&
				heartbeatFingerprint !== JSON.stringify( editorState() )
			)
				reportEditor().catch( () => {} );
			let fingerprint = '';
			try {
				const editor = wp.data.select( 'core/editor' ),
					blocks = wp.data.select( 'core/block-editor' );
				let id =
					editor &&
					editor.getCurrentPostId &&
					editor.getCurrentPostId();
				if ( id === undefined || id === null || id === '' ) {
					const site = wp.data.select( 'core/edit-site' );
					if ( site && site.getEditedPostId )
						id = site.getEditedPostId();
				}
				fingerprint = JSON.stringify( [
					id,
					blocks &&
						blocks.getSelectedBlockClientId &&
						blocks.getSelectedBlockClientId(),
				] );
			} catch ( error ) {
				return;
			}
			if ( fingerprint !== contextFingerprint ) {
				contextFingerprint = fingerprint;
				if ( state.open ) render();
			}
		} );
	window.addEventListener( 'pagehide', () => {
		pageSuspended = true;
		abortRender();
		releaseEditorControl();
		releaseSiteControl();
		composing = false;
		interacting = false;
		renderPending = false;
		window.clearTimeout( interactionTimer );
		interactionTimer = null;
		window.clearInterval( heartbeatTimer );
		heartbeatTimer = null;
		window.clearTimeout( backgroundTimer );
		backgroundTimer = null;
		window.clearInterval( progressTimer );
		progressTimer = null;
		window.clearTimeout( editorSync.timer );
		editorSync.timer = null;
	} );
	window.addEventListener( 'pageshow', ( event ) => {
		if ( ! event.persisted ) return;
		pageSuspended = false;
		startHeartbeat();
		if ( editorSync.pending ) {
			editorSync.pending.attempts = 0;
			scheduleEditorChanges();
		}
		if ( state.open ) openPanel();
		else scheduleBackgroundObservation( true );
	} );
	startHeartbeat();
	if ( ! settings.autoOpen ) scheduleBackgroundObservation( true );
	syncTrigger();
	if ( settings.autoOpen ) openPanel();
} )();
