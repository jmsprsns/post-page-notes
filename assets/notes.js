/**
 * WordPress Post and Page Notes: the notes panel.
 *
 * One interface for both editors. The classic editor's box mounts it on its
 * own ([data-ppn-mount]); the block editor's sidebar (sidebar.js) mounts it
 * through window.postPageNotesUI. Each post's notes, open form and unsent
 * drafts live in a store, so closing and reopening the sidebar loses nothing.
 *
 * Notes are plain text. They're only ever put on the page as text nodes, and
 * web addresses in them become links built as elements (http and https only).
 * Every change goes through the plugin's REST routes, which answer with the
 * post's whole list of notes; the panel also checks for new notes every
 * minute while it's open and the browser tab is in view.
 *
 * postPageNotes comes from PHP (Post_Page_Notes_Editor::config).
 */

/* global wp, postPageNotes */
( function ( window, document, wp, config ) {
	'use strict';

	if ( ! config || ! wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var NS = '/post-page-notes/v1/';
	var MAX_DEPTH = 3;
	var POLL_MS = 60000;
	var STALE_MS = 15000;
	var HIGHLIGHT_MS = 2600;
	var URL_RE = /\bhttps?:\/\/[^\s<>"'`]+/g;
	var icons = config.icons || {};
	var stores = {};
	var uid = 0;

	// Stores -------------------------------------------------------------------

	function getStore( postId ) {
		postId = parseInt( postId, 10 ) || 0;

		if ( ! stores[ postId ] ) {
			stores[ postId ] = {
				postId: postId,
				notes: [],
				counts: { total: 0, open: 0 },
				signature: '',
				fetchedAt: 0,
				loading: false,
				busy: false,
				error: '',
				drafts: {},
				form: '',
				showResolved: false,
				target: null,
				views: [],
				listeners: [],
			};
		}

		return stores[ postId ];
	}

	function findNote( store, id ) {
		for ( var i = 0; i < store.notes.length; i++ ) {
			if ( store.notes[ i ].id === id ) {
				return store.notes[ i ];
			}
		}

		return null;
	}

	function rootOf( store, note ) {
		var guard = 0;

		while ( note && note.parent && guard++ < 500 ) {
			var parent = findNote( store, note.parent );

			if ( ! parent ) {
				break;
			}

			note = parent;
		}

		return note;
	}

	function formKey( kind, id ) {
		return kind + '-' + id;
	}

	function parseKey( key ) {
		var parts = String( key || '' ).split( '-' );

		return { kind: parts[ 0 ], id: parseInt( parts[ 1 ], 10 ) || 0 };
	}

	// Whether the open reply/edit form still makes sense after new data.
	function formStillValid( store ) {
		var key = parseKey( store.form );
		var note = findNote( store, key.id );

		if ( ! note || note.deleted ) {
			return false;
		}

		return key.kind === 'edit' ? note.canEdit : key.kind === 'reply';
	}

	function apply( store, data, force ) {
		var signature;

		if ( ! data || ! Array.isArray( data.notes ) ) {
			return;
		}

		store.fetchedAt = Date.now();
		signature = JSON.stringify( [ data.notes, data.counts ] );

		if ( signature === store.signature && ! force ) {
			return;
		}

		store.signature = signature;
		store.notes = data.notes;
		store.counts = data.counts || { total: 0, open: 0 };

		if ( store.form && ! formStillValid( store ) ) {
			delete store.drafts[ store.form ];
			store.form = '';
		}

		store.views.forEach( renderList );
		store.listeners.forEach( function ( listener ) {
			listener( store.counts );
		} );
	}

	function refresh( store ) {
		if ( store.busy || store.loading ) {
			return;
		}

		store.loading = true;

		wp.apiFetch( { path: NS + 'posts/' + store.postId } ).then(
			function ( data ) {
				store.loading = false;
				apply( store, data );
			},
			function () {
				store.loading = false;
			}
		);
	}

	// Send a change. `before` runs once it's saved, before the list redraws.
	function send( store, options, before ) {
		if ( store.busy ) {
			return;
		}

		setBusy( store, true );
		setError( store, '' );

		wp.apiFetch( options ).then(
			function ( data ) {
				setBusy( store, false );

				if ( before ) {
					before( data );
				}

				apply( store, data, true );
			},
			function ( error ) {
				setBusy( store, false );
				setError( store, ( error && error.message ) || __( 'Something went wrong. Try again.', 'post-page-notes' ) );
			}
		);
	}

	function setBusy( store, busy ) {
		store.busy = busy;
		store.views.forEach( function ( view ) {
			view.root.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
			view.root.querySelectorAll( 'button' ).forEach( function ( button ) {
				button.disabled = busy;
			} );
		} );
	}

	function setError( store, message ) {
		store.error = message;
		store.views.forEach( function ( view ) {
			view.error.textContent = '';

			if ( message ) {
				view.error.appendChild( icon( 'circle-alert' ) );
				view.error.appendChild( el( 'span', '', message ) );
			}

			view.error.hidden = ! message;
		} );
	}

	function speak( message ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message );
		}
	}

	// Small DOM helpers ----------------------------------------------------------

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined && text !== null && text !== '' ) {
			node.textContent = text;
		}

		return node;
	}

	// Bundled Lucide SVGs from PHP; never anything a user typed.
	function icon( name ) {
		var span = el( 'span', 'ppn-icon' );

		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = icons[ name ] || '';

		return span;
	}

	function button( className, iconName, label, onClick ) {
		var node = el( 'button', className );

		node.type = 'button';

		if ( iconName ) {
			node.appendChild( icon( iconName ) );
		}

		node.appendChild( el( 'span', '', label ) );
		node.addEventListener( 'click', onClick );

		return node;
	}

	// Note text with its web addresses as links. Text nodes only.
	function fillText( node, text ) {
		var last = 0;
		var match;

		URL_RE.lastIndex = 0;

		while ( ( match = URL_RE.exec( text ) ) !== null ) {
			var url = match[ 0 ].replace( /[.,;:!?'"]+$/, '' );
			var link;

			// Keep a closing bracket that isn't part of the address.
			if ( /\)$/.test( url ) && url.indexOf( '(' ) === -1 ) {
				url = url.slice( 0, -1 );
			}

			if ( ! /^https?:\/\/[^/\s]/.test( url ) ) {
				continue;
			}

			node.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
			link = el( 'a', '', url );
			link.href = url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			node.appendChild( link );
			last = match.index + url.length;
			URL_RE.lastIndex = last;
		}

		node.appendChild( document.createTextNode( text.slice( last ) ) );
	}

	function initials( name ) {
		var words = String( name || '' ).trim().split( /\s+/ ).filter( Boolean );
		var first = words.length ? Array.from( words[ 0 ] )[ 0 ] : '?';
		var second = words.length > 1 ? Array.from( words[ words.length - 1 ] )[ 0 ] : '';

		return ( first + second ).toUpperCase();
	}

	// One of eight colours, always the same for the same person.
	function colour( seed ) {
		var hash = 0;

		String( seed || '' ).split( '' ).forEach( function ( char ) {
			hash = ( hash * 31 + char.charCodeAt( 0 ) ) % 9973;
		} );

		return hash % 8;
	}

	function shortcutHint() {
		var mac = /Mac|iPhone|iPad/.test( window.navigator.platform || window.navigator.userAgent || '' );

		return mac ? __( '⌘ + Enter to post', 'post-page-notes' ) : __( 'Ctrl + Enter to post', 'post-page-notes' );
	}

	function grow( area ) {
		area.style.height = 'auto';
		area.style.height = Math.min( area.scrollHeight + 2, 360 ) + 'px';
	}

	function length( text ) {
		return Array.from( text ).length;
	}

	// Text boxes -------------------------------------------------------------------

	function textarea( view, key, placeholder ) {
		var store = view.store;
		var area = el( 'textarea', 'ppn-textarea' );

		area.rows = key === 'new' ? 3 : 2;
		area.maxLength = config.maxLength;
		area.placeholder = placeholder;
		area.setAttribute( 'data-ppn-key', key );
		area.value = store.drafts[ key ] || '';

		area.addEventListener( 'input', function () {
			store.drafts[ key ] = area.value;
			grow( area );
		} );

		area.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				submit( view, key );
			} else if ( event.key === 'Escape' && key !== 'new' ) {
				event.preventDefault();
				event.stopPropagation();
				closeForm( view );
			}
		} );

		return area;
	}

	function focusKey( view, key, select ) {
		var area = view.root.querySelector( 'textarea[data-ppn-key="' + key + '"]' );

		if ( area ) {
			area.focus();

			if ( select !== false ) {
				area.setSelectionRange( area.value.length, area.value.length );
			}
		}
	}

	// Actions -----------------------------------------------------------------------

	function submit( view, key ) {
		var store = view.store;
		var text = ( store.drafts[ key ] || '' ).trim();
		var parsed = parseKey( key );

		if ( store.busy ) {
			return;
		}

		if ( ! text ) {
			focusKey( view, key );
			return;
		}

		if ( length( text ) > config.maxLength ) {
			/* translators: %s: most characters allowed in a note. */
			setError( store, sprintf( __( 'Notes can be up to %s characters long.', 'post-page-notes' ), config.maxLength ) );
			return;
		}

		if ( parsed.kind === 'edit' ) {
			send(
				store,
				{ path: NS + 'notes/' + parsed.id, method: 'POST', data: { text: text } },
				function () {
					delete store.drafts[ key ];
					store.form = '';
					store.target = { id: parsed.id, block: 'nearest' };
					speak( __( 'Note updated.', 'post-page-notes' ) );
				}
			);
			return;
		}

		send(
			store,
			{
				path: NS + 'posts/' + store.postId,
				method: 'POST',
				data: { text: text, parent: parsed.kind === 'reply' ? parsed.id : 0 },
			},
			function ( data ) {
				delete store.drafts[ key ];

				if ( parsed.kind === 'reply' ) {
					store.form = '';
				} else {
					store.views.forEach( function ( other ) {
						other.newArea.value = '';
						other.newArea.style.height = '';
					} );
				}

				store.target = data && data.created ? { id: data.created, block: 'nearest' } : null;
				speak( parsed.kind === 'reply' ? __( 'Reply added.', 'post-page-notes' ) : __( 'Note added.', 'post-page-notes' ) );
			}
		);
	}

	function openForm( view, kind, note ) {
		var store = view.store;
		var key = formKey( kind, note.id );

		store.form = key;

		if ( kind === 'edit' && store.drafts[ key ] === undefined ) {
			store.drafts[ key ] = note.text;
		}

		store.views.forEach( renderList );
		focusKey( view, key );
	}

	function closeForm( view ) {
		var store = view.store;
		var parsed = parseKey( store.form );

		delete store.drafts[ store.form ];
		store.form = '';
		store.views.forEach( renderList );

		// Back to the note the form belonged to.
		var actions = view.list.querySelector( '[data-ppn-note="' + parsed.id + '"] .ppn-actions button' );

		if ( actions ) {
			actions.focus();
		}
	}

	function removeNote( view, note ) {
		var store = view.store;
		var hasReplies = store.notes.some( function ( other ) {
			return other.parent === note.id;
		} );
		var question = hasReplies
			? __( 'Delete this note? Its replies stay in the thread.', 'post-page-notes' )
			: __( 'Delete this note?', 'post-page-notes' );

		if ( ! window.confirm( question ) ) {
			return;
		}

		send( store, { path: NS + 'notes/' + note.id, method: 'DELETE' }, function () {
			if ( parseKey( store.form ).id === note.id ) {
				store.form = '';
			}

			speak( __( 'Note deleted.', 'post-page-notes' ) );
		} );
	}

	function setResolved( view, note, resolved ) {
		var store = view.store;

		send( store, { path: NS + 'notes/' + note.id, method: 'POST', data: { resolved: resolved } }, function () {
			if ( ! resolved ) {
				store.target = { id: note.id, block: 'nearest' };
			}

			speak( resolved ? __( 'Thread resolved.', 'post-page-notes' ) : __( 'Thread reopened.', 'post-page-notes' ) );
		} );
	}

	// Drawing -------------------------------------------------------------------------

	function buildTree( notes ) {
		var ids = {};
		var children = {};
		var roots = [];

		notes.forEach( function ( note ) {
			ids[ note.id ] = true;
		} );

		notes.forEach( function ( note ) {
			if ( note.parent && ids[ note.parent ] ) {
				( children[ note.parent ] = children[ note.parent ] || [] ).push( note );
			} else {
				roots.push( note );
			}
		} );

		// Newest thread first; replies stay in the order they were written.
		roots.reverse();

		return { children: children, roots: roots };
	}

	function renderList( view ) {
		var store = view.store;
		var list = view.list;
		var tree = buildTree( store.notes );
		var open = tree.roots.filter( function ( root ) {
			return ! root.resolved;
		} );
		var done = tree.roots.filter( function ( root ) {
			return root.resolved;
		} );
		var active = document.activeElement;
		var activeKey = active && list.contains( active ) && active.getAttribute( 'data-ppn-key' );
		var selection = activeKey ? [ active.selectionStart, active.selectionEnd ] : null;

		list.textContent = '';

		if ( ! tree.roots.length ) {
			list.appendChild( emptyState() );
		} else {
			if ( open.length ) {
				list.appendChild( sectionHead(
					/* translators: %s: number of open threads. */
					sprintf( _n( '%s open thread', '%s open threads', open.length, 'post-page-notes' ), open.length )
				) );
				list.appendChild( threadList( view, open, tree ) );
			} else {
				var allDone = el( 'p', 'ppn-alldone' );

				allDone.appendChild( icon( 'check-check' ) );
				allDone.appendChild( el( 'span', '', __( 'Everything here is resolved.', 'post-page-notes' ) ) );
				list.appendChild( allDone );
			}

			if ( done.length ) {
				list.appendChild( resolvedFold( view, done, tree ) );
			}
		}

		if ( store.busy ) {
			list.querySelectorAll( 'button' ).forEach( function ( node ) {
				node.disabled = true;
			} );
		}

		list.querySelectorAll( 'textarea' ).forEach( grow );

		if ( activeKey ) {
			var again = list.querySelector( 'textarea[data-ppn-key="' + activeKey + '"]' );

			if ( again ) {
				again.focus();
				again.setSelectionRange( selection[ 0 ], selection[ 1 ] );
			}
		}

		scrollToTarget( view );
	}

	function sectionHead( text ) {
		return el( 'h3', 'ppn-section-head', text );
	}

	function emptyState() {
		var box = el( 'div', 'ppn-empty' );
		var badge = el( 'span', 'ppn-empty-icon' );

		badge.appendChild( icon( 'message-square-text' ) );
		box.appendChild( badge );
		box.appendChild( el( 'strong', '', __( 'No notes yet', 'post-page-notes' ) ) );
		box.appendChild( el( 'span', '', sprintf(
			/* translators: %s: content type, singular, e.g. "page". */
			__( 'Leave one for anyone else editing this %s: something to fix, a change to make, a question.', 'post-page-notes' ),
			config.singular
		) ) );

		return box;
	}

	function resolvedFold( view, done, tree ) {
		var store = view.store;
		var wrap = el( 'div', 'ppn-fold-wrap' );
		var toggle = el( 'button', 'ppn-fold' );

		toggle.type = 'button';
		toggle.setAttribute( 'aria-expanded', store.showResolved ? 'true' : 'false' );
		/* translators: %s: number of resolved threads. */
		toggle.appendChild( el( 'span', '', sprintf( _n( '%s resolved thread', '%s resolved threads', done.length, 'post-page-notes' ), done.length ) ) );
		toggle.appendChild( icon( 'chevron-down' ) );
		toggle.addEventListener( 'click', function () {
			store.showResolved = ! store.showResolved;
			store.views.forEach( renderList );

			var again = view.list.querySelector( '.ppn-fold' );

			if ( again ) {
				again.focus();
			}
		} );

		wrap.appendChild( toggle );

		if ( store.showResolved ) {
			wrap.appendChild( threadList( view, done, tree ) );
		}

		return wrap;
	}

	function threadList( view, roots, tree ) {
		var list = el( 'ol', 'ppn-threads' );

		roots.forEach( function ( root ) {
			list.appendChild( thread( view, root, tree ) );
		} );

		return list;
	}

	function thread( view, root, tree ) {
		var item = el( 'li', 'ppn-thread' + ( root.resolved ? ' is-resolved' : '' ) );

		if ( root.resolved ) {
			var bar = el( 'div', 'ppn-resolved-bar' );

			bar.appendChild( icon( 'circle-check' ) );
			bar.appendChild( el( 'span', '', root.resolvedAgo
				/* translators: 1: who resolved the thread, 2: when, e.g. "2 hours ago". */
				? sprintf( __( 'Resolved by %1$s · %2$s', 'post-page-notes' ), root.resolvedBy, root.resolvedAgo )
				/* translators: %s: who resolved the thread. */
				: sprintf( __( 'Resolved by %s', 'post-page-notes' ), root.resolvedBy )
			) );
			bar.appendChild( button( 'ppn-action', 'rotate-ccw', __( 'Reopen', 'post-page-notes' ), function () {
				setResolved( view, root, false );
			} ) );
			item.appendChild( bar );
		}

		item.appendChild( noteBody( view, root, null ) );
		appendReplies( view, root, tree, item, 1 );

		return item;
	}

	// Replies nest up to MAX_DEPTH levels; deeper ones line up at the last
	// level and say who they answer.
	function appendReplies( view, parent, tree, container, depth ) {
		var replies = tree.children[ parent.id ];

		if ( ! replies ) {
			return;
		}

		var list = container.tagName === 'OL' ? container : el( 'ol', 'ppn-replies' );

		replies.forEach( function ( reply ) {
			var item = el( 'li', 'ppn-reply' );

			item.appendChild( noteBody( view, reply, depth > MAX_DEPTH ? parent : null ) );
			list.appendChild( item );

			if ( depth < MAX_DEPTH ) {
				appendReplies( view, reply, tree, item, depth + 1 );
			} else {
				appendReplies( view, reply, tree, list, depth + 1 );
			}
		} );

		if ( list !== container ) {
			container.appendChild( list );
		}
	}

	function noteBody( view, note, answers ) {
		var store = view.store;
		var article = el( 'article', 'ppn-note' + ( note.deleted ? ' is-deleted' : '' ) + ( note.mine ? ' is-mine' : '' ) );

		article.setAttribute( 'data-ppn-note', String( note.id ) );

		if ( note.deleted ) {
			var gone = el( 'p', 'ppn-text' );

			gone.appendChild( icon( 'trash-2' ) );
			gone.appendChild( el( 'span', '', __( 'This note was deleted.', 'post-page-notes' ) ) );
			article.appendChild( gone );

			return article;
		}

		article.appendChild( noteHead( note ) );

		// A thread's first note gets the Resolve button, top right.
		if ( ! note.parent && ! note.resolved ) {
			var resolve = el( 'button', 'ppn-resolve' );

			resolve.type = 'button';
			resolve.title = __( 'Resolve thread', 'post-page-notes' );
			resolve.setAttribute( 'aria-label', __( 'Resolve thread', 'post-page-notes' ) );
			resolve.appendChild( icon( 'circle-check' ) );
			resolve.addEventListener( 'click', function () {
				setResolved( view, note, true );
			} );
			article.querySelector( '.ppn-note-head' ).appendChild( resolve );
		}

		if ( answers && ! answers.deleted ) {
			var to = el( 'p', 'ppn-to' );

			to.appendChild( icon( 'corner-down-right' ) );
			/* translators: %s: name of the person being answered. */
			to.appendChild( el( 'span', '', sprintf( __( 'To %s', 'post-page-notes' ), answers.name ) ) );
			article.appendChild( to );
		}

		var key = store.form;
		var editing = key === formKey( 'edit', note.id );

		if ( editing ) {
			article.appendChild( form( view, key, note ) );
			return article;
		}

		var text = el( 'div', 'ppn-text' );

		fillText( text, note.text );
		article.appendChild( text );
		article.appendChild( noteActions( view, note ) );

		if ( key === formKey( 'reply', note.id ) ) {
			article.appendChild( form( view, key, note ) );
		}

		return article;
	}

	function noteHead( note ) {
		var head = el( 'header', 'ppn-note-head' );
		var avatar = el( 'span', 'ppn-avatar' );
		var who = el( 'span', 'ppn-who' );
		var meta = el( 'span', 'ppn-meta' );
		var time = el( 'time', 'ppn-time', note.ago );

		avatar.setAttribute( 'aria-hidden', 'true' );

		if ( note.avatar ) {
			var img = el( 'img' );

			img.src = note.avatar;
			img.alt = '';
			img.loading = 'lazy';
			avatar.appendChild( img );
		} else {
			avatar.textContent = initials( note.name );
			avatar.className += ' is-' + colour( note.login || note.name );
		}

		who.appendChild( el( 'strong', 'ppn-name', note.name ) );

		if ( note.login ) {
			/* translators: %s: username. */
			who.appendChild( el( 'span', 'ppn-login', ' ' + sprintf( __( '(%s)', 'post-page-notes' ), note.login ) ) );
		}

		if ( note.gone ) {
			who.appendChild( el( 'span', 'ppn-login', ' ' + __( '(account deleted)', 'post-page-notes' ) ) );
		}

		time.title = note.date;
		meta.appendChild( time );

		if ( note.edited ) {
			meta.appendChild( el( 'span', 'ppn-edited', ' · ' + __( 'edited', 'post-page-notes' ) ) );
		}

		head.appendChild( avatar );
		head.appendChild( who );
		head.appendChild( meta );

		return head;
	}

	function noteActions( view, note ) {
		var actions = el( 'div', 'ppn-actions' );

		actions.appendChild( button( 'ppn-action', 'reply', __( 'Reply', 'post-page-notes' ), function () {
			openForm( view, 'reply', note );
		} ) );

		if ( note.canEdit ) {
			actions.appendChild( button( 'ppn-action', 'pencil', __( 'Edit', 'post-page-notes' ), function () {
				openForm( view, 'edit', note );
			} ) );
		}

		if ( note.canDel ) {
			actions.appendChild( button( 'ppn-action is-danger', 'trash-2', __( 'Delete', 'post-page-notes' ), function () {
				removeNote( view, note );
			} ) );
		}

		return actions;
	}

	function form( view, key, note ) {
		var parsed = parseKey( key );
		var wrap = el( 'div', 'ppn-form ppn-form-' + parsed.kind );
		var label = el( 'label', 'screen-reader-text' );
		var id = 'ppn-field-' + ( ++uid );
		var area = textarea(
			view,
			key,
			/* translators: %s: name of the person being answered. */
			parsed.kind === 'reply' ? sprintf( __( 'Reply to %s…', 'post-page-notes' ), note.name ) : ''
		);
		var foot = el( 'div', 'ppn-form-foot' );

		/* translators: %s: name of the person being answered. */
		label.textContent = parsed.kind === 'reply' ? sprintf( __( 'Reply to %s', 'post-page-notes' ), note.name ) : __( 'Edit your note', 'post-page-notes' );
		label.htmlFor = id;
		area.id = id;

		foot.appendChild( button( 'button', '', __( 'Cancel', 'post-page-notes' ), function () {
			closeForm( view );
		} ) );
		foot.appendChild( button(
			'button button-primary',
			parsed.kind === 'reply' ? 'send' : '',
			parsed.kind === 'reply' ? __( 'Reply', 'post-page-notes' ) : __( 'Save', 'post-page-notes' ),
			function () {
				submit( view, key );
			}
		) );

		wrap.appendChild( label );
		wrap.appendChild( area );
		wrap.appendChild( foot );

		return wrap;
	}

	// Scroll to (and briefly highlight) a note after a change or a link.
	function scrollToTarget( view ) {
		var store = view.store;
		var target = store.target;
		var node;

		if ( ! target ) {
			return;
		}

		if ( target.id ) {
			node = view.list.querySelector( '[data-ppn-note="' + target.id + '"]' );

			if ( ! node ) {
				var root = rootOf( store, findNote( store, target.id ) );

				// It's in the folded "resolved" list: unfold it first.
				if ( root && root.resolved && ! store.showResolved ) {
					store.showResolved = true;
					renderList( view );
				} else {
					store.target = null;
				}

				return;
			}
		} else {
			node = view.root.closest( '.postbox' ) || view.root;
		}

		store.target = null;

		window.requestAnimationFrame( function () {
			if ( ! node.isConnected ) {
				return;
			}

			node.scrollIntoView( { block: target.block || 'center', behavior: 'smooth' } );

			if ( target.id ) {
				node.classList.add( 'is-target' );
				window.setTimeout( function () {
					node.classList.remove( 'is-target' );
				}, HIGHLIGHT_MS );
			}
		} );
	}

	// Views --------------------------------------------------------------------------

	function mount( container, postId ) {
		var store = getStore( postId );
		var view = { store: store, root: el( 'div', 'ppn' ), list: el( 'div', 'ppn-list' ), error: el( 'div', 'ppn-error' ), timer: 0 };
		var compose = el( 'div', 'ppn-compose' );
		var label = el( 'label', 'screen-reader-text', __( 'New note', 'post-page-notes' ) );
		/* translators: %s: content type, singular, e.g. "page". */
		var area = textarea( view, 'new', sprintf( __( 'Leave a note about this %s…', 'post-page-notes' ), config.singular ) );
		var foot = el( 'div', 'ppn-compose-foot' );

		label.htmlFor = area.id = 'ppn-new-' + ( ++uid );
		foot.appendChild( el( 'span', 'ppn-hint', shortcutHint() ) );
		foot.appendChild( button( 'button button-primary ppn-submit', 'send', __( 'Add note', 'post-page-notes' ), function () {
			submit( view, 'new' );
		} ) );
		compose.appendChild( label );
		compose.appendChild( area );
		compose.appendChild( foot );

		view.newArea = area;
		view.error.setAttribute( 'role', 'alert' );
		view.error.hidden = true;
		view.root.setAttribute( 'aria-busy', 'false' );
		view.root.appendChild( compose );
		view.root.appendChild( view.error );
		view.root.appendChild( view.list );

		container.textContent = '';
		container.appendChild( view.root );
		store.views.push( view );

		renderList( view );
		setError( store, store.error );
		window.requestAnimationFrame( function () {
			grow( area );
		} );

		if ( Date.now() - store.fetchedAt > STALE_MS ) {
			refresh( store );
		}

		// Pick up other people's notes while the panel is open.
		view.timer = window.setInterval( function () {
			if ( document.visibilityState === 'visible' && ! typing( view ) ) {
				refresh( store );
			}
		}, POLL_MS );

		return view;
	}

	function unmount( view ) {
		if ( ! view ) {
			return;
		}

		window.clearInterval( view.timer );
		view.store.views = view.store.views.filter( function ( other ) {
			return other !== view;
		} );
	}

	function typing( view ) {
		var active = document.activeElement;

		return active && active.tagName === 'TEXTAREA' && view.root.contains( active ) && active.value !== '';
	}

	function subscribe( postId, listener ) {
		var store = getStore( postId );

		store.listeners.push( listener );

		return function () {
			store.listeners = store.listeners.filter( function ( other ) {
				return other !== listener;
			} );
		};
	}

	function focusNote( postId, noteId ) {
		var store = getStore( postId );

		store.target = { id: parseInt( noteId, 10 ) || 0, block: 'center' };
		store.views.forEach( scrollToTarget );
	}

	// "#post-page-notes" or "#ppn-note-12" in the address.
	function hashTarget() {
		var match = /^#(?:post-page-notes|ppn-note-(\d+))$/.exec( window.location.hash || '' );

		return match ? { note: match[ 1 ] ? parseInt( match[ 1 ], 10 ) : 0 } : null;
	}

	// Unsent text: a new note, or an open reply/edit that changed something.
	function hasDrafts() {
		return Object.keys( stores ).some( function ( id ) {
			var store = stores[ id ];

			return [ 'new', store.form ].some( function ( key ) {
				var draft = key && store.drafts[ key ] ? store.drafts[ key ].trim() : '';
				var parsed = parseKey( key );
				var note = parsed.kind === 'edit' ? findNote( store, parsed.id ) : null;

				return draft !== '' && ! ( note && note.text === draft );
			} );
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( hasDrafts() ) {
			event.preventDefault();
			event.returnValue = '';
			return '';
		}
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState !== 'visible' ) {
			return;
		}

		Object.keys( stores ).forEach( function ( id ) {
			var store = stores[ id ];

			if ( store.views.length && Date.now() - store.fetchedAt > STALE_MS ) {
				refresh( store );
			}
		} );
	} );

	// Start with the notes printed into the page, so the panel opens at once.
	if ( config.initial ) {
		apply( getStore( config.postId ), config.initial );
	}

	window.postPageNotesUI = {
		mount: mount,
		unmount: unmount,
		subscribe: subscribe,
		focus: focusNote,
		hashTarget: hashTarget,
		counts: function ( postId ) {
			return getStore( postId ).counts;
		},
	};

	// Classic editor --------------------------------------------------------------------

	function openClassicBox( target ) {
		var box = document.getElementById( 'post-page-notes' );
		var toggle = document.getElementById( 'post-page-notes-hide' );

		if ( ! box ) {
			return;
		}

		// Hidden under Screen Options: switch it back on (WordPress saves that).
		if ( toggle && ! toggle.checked ) {
			toggle.click();
		}

		if ( window.getComputedStyle( box ).display === 'none' ) {
			box.style.display = 'block';
		}

		if ( box.classList.contains( 'closed' ) ) {
			box.classList.remove( 'closed' );

			var handle = box.querySelector( '.handlediv' );

			if ( handle ) {
				handle.setAttribute( 'aria-expanded', 'true' );
			}
		}

		focusNote( config.postId, target.note );
	}

	function initClassic() {
		var container = document.querySelector( '[data-ppn-mount]' );
		var badge = document.querySelector( '[data-ppn-box-count]' );

		if ( ! container ) {
			return;
		}

		mount( container, config.postId );

		// Keep the box title's "2 open" current.
		subscribe( config.postId, function ( counts ) {
			if ( badge ) {
				badge.hidden = ! counts.open;
				/* translators: %s: number of open threads. */
				badge.textContent = sprintf( __( '%s open', 'post-page-notes' ), counts.open );
			}
		} );

		// WordPress wires up its boxes (and their Screen Options switches) in
		// jQuery ready handlers, which run after this; follow the link after them.
		afterPostboxes( function () {
			if ( hashTarget() ) {
				openClassicBox( hashTarget() );
			}

			window.addEventListener( 'hashchange', function () {
				if ( hashTarget() ) {
					openClassicBox( hashTarget() );
				}
			} );
		} );
	}

	function afterPostboxes( callback ) {
		if ( window.jQuery ) {
			window.jQuery( function () {
				window.setTimeout( callback, 0 );
			} );
		} else {
			callback();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initClassic );
	} else {
		initClassic();
	}
} )( window, document, window.wp, window.postPageNotes );
