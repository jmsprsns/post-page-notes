/**
 * Block editor: the "Page notes" sidebar.
 *
 * A toolbar button (with the number of open threads on it) opens a sidebar
 * holding the shared notes panel from notes.js. Links ending in
 * #post-page-notes or #ppn-note-<id> open the sidebar straight away.
 * PluginSidebar moved from wp.editPost to wp.editor in WordPress 6.6; both
 * are tried, so this works from 5.8 on.
 */

/* global wp, postPageNotes */
( function ( wp, config ) {
	'use strict';

	var ui = window.postPageNotesUI;

	if ( ! wp || ! config || ! ui || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var editor = wp.editor || {};
	var editPost = wp.editPost || {};
	var PluginSidebar = editor.PluginSidebar || editPost.PluginSidebar;
	var MoreMenuItem = editor.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;
	var NAME = 'post-page-notes';
	var SIDEBAR = NAME + '/' + NAME;
	var postId = parseInt( config.postId, 10 );

	if ( ! PluginSidebar ) {
		return;
	}

	// The bundled notepad-text icon (an SVG string from PHP, not user input).
	function NoteIcon() {
		return el( 'span', {
			className: 'ppn-toolbar-svg',
			'aria-hidden': true,
			dangerouslySetInnerHTML: { __html: config.icons[ 'notepad-text' ] || '' },
		} );
	}

	function ToolbarIcon() {
		var state = useState( ui.counts( postId ) );
		var counts = state[ 0 ];
		var setCounts = state[ 1 ];

		useEffect( function () {
			setCounts( ui.counts( postId ) );

			return ui.subscribe( postId, setCounts );
		}, [] );

		return el(
			'span',
			{ className: 'ppn-toolbar-icon' },
			el( NoteIcon ),
			counts.open ? el( 'span', { className: 'ppn-toolbar-badge', 'aria-hidden': true }, counts.open > 99 ? '99+' : String( counts.open ) ) : null
		);
	}

	function Panel() {
		var ref = useRef( null );

		useEffect( function () {
			var view = ui.mount( ref.current, postId );

			return function () {
				ui.unmount( view );
			};
		}, [] );

		return el( 'div', { className: 'ppn-sidebar', ref: ref } );
	}

	function Notes() {
		return el(
			Fragment,
			null,
			MoreMenuItem ? el( MoreMenuItem, { target: NAME, icon: el( NoteIcon ) }, config.title ) : null,
			el( PluginSidebar, { name: NAME, title: config.title, icon: el( ToolbarIcon ) }, el( Panel ) )
		);
	}

	wp.plugins.registerPlugin( NAME, { render: Notes, icon: el( NoteIcon ) } );

	function isOpen() {
		var store = wp.data.select( 'core/edit-post' );

		return !! ( store && store.getActiveGeneralSidebarName && store.getActiveGeneralSidebarName() === SIDEBAR );
	}

	function open() {
		var store = wp.data.dispatch( 'core/edit-post' );

		if ( store && store.openGeneralSidebar ) {
			store.openGeneralSidebar( SIDEBAR );
		}
	}

	// Open the sidebar for #post-page-notes links. The editor restores its own
	// sidebar while it starts up, so check a few times that ours stayed open.
	function followHash() {
		var target = ui.hashTarget();
		var tries = 0;
		var timer;

		if ( ! target ) {
			return;
		}

		ui.focus( postId, target.note );
		open();

		timer = window.setInterval( function () {
			tries++;

			if ( ! isOpen() ) {
				open();
			} else if ( tries >= 4 ) {
				window.clearInterval( timer );
			}

			if ( tries >= 12 ) {
				window.clearInterval( timer );
			}
		}, 250 );
	}

	if ( wp.domReady ) {
		wp.domReady( followHash );
	} else {
		window.addEventListener( 'load', followHash );
	}

	window.addEventListener( 'hashchange', followHash );
} )( window.wp, window.postPageNotes );
