/**
 * Bhranti — Native editor launcher.
 *
 * Editable runtime source; no compilation is required.
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';
	const wp = window.wp || {},
		root = document.getElementById( 'bhranti-app' ),
		settings = window.bhrantiSettings || {};
	if (
		! root ||
		! settings.isAdmin ||
		! [ 'post', 'page' ].includes( ( settings.context || {} ).post_type ) ||
		! wp.editor ||
		! wp.editor.PluginSidebar ||
		! wp.element ||
		! wp.components ||
		! wp.plugins ||
		! wp.compose ||
		! wp.data
	)
		return;
	// shortcut: core's pinned header slot name is internal; replace this Fill when core exposes a stable additive toolbar API.
	if (
		! wp.components.Fill ||
		! wp.element.createPortal ||
		wp.plugins.getPlugin( 'bhranti-editor-launcher' )
	)
		return;
	const {
		createElement: h,
		createPortal,
		useState,
		useRef,
		useEffect,
		useLayoutEffect,
	} = wp.element;
	const __ = wp.i18n.__;
	const host = document.createElement( 'div' );
	host.className = 'bhranti-editor-launcher-floating';
	document.body.appendChild( host );
	function available( node ) {
		if (
			! node ||
			! node.isConnected ||
			node.closest( '[hidden], [inert], [aria-hidden="true"]' )
		)
			return false;
		const style = window.getComputedStyle( node );
		return (
			style.display !== 'none' &&
			style.visibility !== 'hidden' &&
			node.getClientRects().length > 0
		);
	}
	function snapshot() {
		return {
			open: ! root.hidden,
			adminBarAvailable: available(
				document.querySelector( '#wp-admin-bar-bhranti > a' )
			),
			controlled: !! document.querySelector( '.bhranti-editor-control' ),
		};
	}
	function Launcher() {
		const [ state, setState ] = useState( snapshot ),
			slot = useRef( null );
		const wide = wp.compose.useViewportMatch( 'large' );
		const labels = wp.data.useSelect(
			( select ) =>
				select( 'core/preferences' ).get( 'core', 'showIconLabels' ),
			[]
		);
		const fallbackNeeded = labels && ! wide,
			shown = ! state.adminBarAvailable || state.controlled;
		const [ fallback, setFallback ] = useState( null );
		useEffect( () => {
			if ( ! host.isConnected ) document.body.appendChild( host );
			let observedBar = null;
			const refresh = () => {
				const bar = document.getElementById( 'wpadminbar' );
				if ( bar !== observedBar ) {
					observer.disconnect();
					observer.observe( document.body, {
						attributes: true,
						attributeFilter: [ 'class', 'style', 'hidden' ],
						childList: true,
					} );
					if ( bar )
						observer.observe( bar, {
							attributes: true,
							attributeFilter: [ 'class', 'style', 'hidden' ],
							subtree: true,
						} );
					observedBar = bar;
				}
				const next = snapshot();
				setState( ( previous ) =>
					Object.keys( next ).every(
						( key ) => next[ key ] === previous[ key ]
					)
						? previous
						: next
				);
			};
			const changed = ( event ) =>
				setState( ( previous ) =>
					Object.keys( event.detail ).every(
						( key ) => event.detail[ key ] === previous[ key ]
					)
						? previous
						: event.detail
				);
			const observer = new MutationObserver( refresh );
			observer.observe( document.body, {
				attributes: true,
				attributeFilter: [ 'class', 'style', 'hidden' ],
				childList: true,
			} );
			document.addEventListener( 'bhranti:panel-state', changed );
			window.addEventListener( 'resize', refresh );
			refresh();
			return () => {
				observer.disconnect();
				document.removeEventListener( 'bhranti:panel-state', changed );
				window.removeEventListener( 'resize', refresh );
				host.remove();
			};
		}, [] );
		useLayoutEffect( () => {
			if ( ! fallbackNeeded || ! shown ) return;
			// Core omits the pinned slot on narrow screens with icon labels; mount only our control beside its existing settings.
			const mount = document.createElement( 'span' );
			mount.className = 'bhranti-editor-launcher-fallback';
			const attach = () => {
				const header = document.querySelector(
					'#editor .editor-header__settings'
				);
				if ( header && mount.parentNode !== header ) {
					header.appendChild( mount );
					setFallback( mount );
					document.dispatchEvent(
						new CustomEvent( 'bhranti:editor-launcher-position' )
					);
				}
			};
			const observer = new MutationObserver( attach );
			observer.observe(
				document.getElementById( 'editor' ) || document.body,
				{ childList: true, subtree: true }
			);
			attach();
			return () => {
				observer.disconnect();
				mount.remove();
				setFallback( null );
			};
		}, [ fallbackNeeded, shown ] );
		useLayoutEffect( () => {
			let observedSlot = null;
			const position = () => {
				if ( ! slot.current ) return;
				if ( observer && slot.current !== observedSlot ) {
					if ( observedSlot ) observer.unobserve( observedSlot );
					observer.observe( slot.current );
					observedSlot = slot.current;
				}
				const rect = slot.current.getBoundingClientRect();
				Object.assign( host.style, {
					top: rect.top + 'px',
					left: rect.left + 'px',
					width: rect.width + 'px',
					height: rect.height + 'px',
				} );
			};
			const observer =
				window.ResizeObserver && new ResizeObserver( position );
			position();
			document.dispatchEvent(
				new CustomEvent( 'bhranti:editor-launcher-ready' )
			);
			document.addEventListener(
				'bhranti:editor-launcher-position',
				position
			);
			window.addEventListener( 'resize', position );
			window.addEventListener( 'scroll', position, true );
			return () => {
				if ( observer ) observer.disconnect();
				document.removeEventListener(
					'bhranti:editor-launcher-position',
					position
				);
				window.removeEventListener( 'resize', position );
				window.removeEventListener( 'scroll', position, true );
			};
		}, [
			state.adminBarAvailable,
			state.controlled,
			state.open,
			fallback,
		] );
		if ( ! shown ) return null;
		const icon = h(
			'svg',
			{
				viewBox: '0 0 16 16',
				width: 20,
				height: 20,
				fill: 'currentColor',
				'aria-hidden': true,
				focusable: false,
			},
			h( 'path', {
				d: 'M7.657 6.247c.11-.33.576-.33.686 0l.645 1.937a2.89 2.89 0 0 0 1.829 1.828l1.936.645c.33.11.33.576 0 .686l-1.937.645a2.89 2.89 0 0 0-1.828 1.829l-.645 1.936a.361.361 0 0 1-.686 0l-.645-1.937a2.89 2.89 0 0 0-1.828-1.828l-1.937-.645a.361.361 0 0 1 0-.686l1.937-.645a2.89 2.89 0 0 0 1.828-1.828zM3.794 1.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387A1.73 1.73 0 0 0 4.593 5.69l-.387 1.162a.217.217 0 0 1-.412 0L3.407 5.69A1.73 1.73 0 0 0 2.31 4.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387A1.73 1.73 0 0 0 3.407 2.31zM10.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732L9.1 2.137a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z',
			} )
		);
		const button = h(
			wp.components.Button,
			{
				className: 'bhranti-editor-launcher',
				size: 'compact',
				icon,
				label: __( 'Bhranti', 'bhranti' ),
				showTooltip: true,
				tooltipPosition: 'bottom',
				'aria-controls': 'bhranti-app',
				'aria-expanded': state.open,
				'aria-pressed': state.open,
				'aria-haspopup': 'dialog',
				'data-bhranti-editor-open': '',
				onClick: ( event ) => {
					event.preventDefault();
					document.dispatchEvent(
						new CustomEvent( 'bhranti:editor-launcher-toggle', {
							detail: { trigger: event.currentTarget },
						} )
					);
				},
			},
			h(
				'span',
				{ className: 'bhranti-editor-launcher-label' },
				__( 'Bhranti', 'bhranti' )
			)
		);
		// Portal only the opener outside inert while retaining its native toolbar position and all editor protection.
		const content = h(
			'span',
			{
				ref: ( node ) => {
					slot.current = node;
					if ( node )
						document.dispatchEvent(
							new CustomEvent(
								'bhranti:editor-launcher-position'
							)
						);
				},
				className: 'bhranti-editor-launcher-slot',
			},
			state.controlled ? createPortal( button, host ) : button
		);
		return fallbackNeeded
			? fallback && createPortal( content, fallback )
			: h( wp.components.Fill, { name: 'PinnedItems/core' }, content );
	}
	wp.plugins.registerPlugin( 'bhranti-editor-launcher', {
		render: Launcher,
	} );
} )();
