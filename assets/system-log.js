/**
 * Noformat Logger System log console.
 *
 * One renderer for every line: the initial page (JSON in
 * #nflog-console-data), follow mode (admin-ajax nflog_tail with `after`) and
 * "Load older" (`before`). Builds DOM nodes only (textContent), never HTML
 * strings, so event data cannot inject markup. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'nflog-console' );
	var dataEl = document.getElementById( 'nflog-console-data' );
	if ( ! root || ! dataEl ) {
		return;
	}
	var data;
	try {
		data = JSON.parse( dataEl.textContent || '{}' );
	} catch ( e ) {
		return;
	}
	var i18n = data.i18n || {};
	var list = root.querySelector( '[data-nflog-lines]' );
	var olderBtn = root.querySelector( '[data-nflog-older]' );
	var status = root.querySelector( '[data-nflog-status]' );
	var followBtn = root.querySelector( '[data-nflog-follow]' );
	var wrapBtn = root.querySelector( '[data-nflog-wrap]' );
	var live = root.querySelector( '.nflog-console__live' );
	var form = root.querySelector( '[data-nflog-console-form]' );
	var query = document.getElementById( 'nflog-q' );
	var TAGS = { critical: 'CRIT', error: 'ERR', warning: 'WARN', notice: 'NOTE', info: 'INFO', debug: 'DBG' };
	var newest = 0;
	var oldest = 0;
	var count = 0;
	var lastDate = null;
	var firstDate = null;
	var timer = null;
	var followStarted = 0;
	var busy = false;

	// ---------- helpers ----------
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		return node;
	}
	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return window.localStorage.getItem( key );
			}
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
		return null;
	}
	function shortPath( loc ) {
		if ( ! loc || loc.indexOf( '/' ) === -1 ) {
			return loc || '';
		}
		var parts = loc.split( '/' );
		return parts.length > 3 ? '…/' + parts.slice( -3 ).join( '/' ) : loc;
	}
	function filterUrl( params ) {
		var url = new URL( data.urls.self, window.location.href );
		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		return url.toString();
	}
	function fmt( template, n ) {
		return String( template || '%d' ).replace( '%d', String( n ) );
	}
	function setStatus( text ) {
		if ( status ) {
			status.textContent = text || '';
		}
	}

	// Pretty JSON as DOM with tinted keys, strings, numbers and literals.
	function jsonNode( value, indent ) {
		var frag = document.createDocumentFragment();
		var pad = new Array( indent + 1 ).join( '  ' );
		var padIn = pad + '  ';
		if ( null === value || 'boolean' === typeof value ) {
			frag.appendChild( el( 'span', 'l', String( value ) ) );
		} else if ( 'number' === typeof value ) {
			frag.appendChild( el( 'span', 'n', String( value ) ) );
		} else if ( 'string' === typeof value ) {
			frag.appendChild( el( 'span', 's', JSON.stringify( value ) ) );
		} else if ( Array.isArray( value ) ) {
			if ( ! value.length ) {
				frag.appendChild( document.createTextNode( '[]' ) );
				return frag;
			}
			frag.appendChild( document.createTextNode( '[\n' ) );
			value.forEach( function ( item, i ) {
				frag.appendChild( document.createTextNode( padIn ) );
				frag.appendChild( jsonNode( item, indent + 1 ) );
				frag.appendChild( document.createTextNode( i < value.length - 1 ? ',\n' : '\n' ) );
			} );
			frag.appendChild( document.createTextNode( pad + ']' ) );
		} else if ( 'object' === typeof value ) {
			var keys = Object.keys( value );
			if ( ! keys.length ) {
				frag.appendChild( document.createTextNode( '{}' ) );
				return frag;
			}
			frag.appendChild( document.createTextNode( '{\n' ) );
			keys.forEach( function ( key, i ) {
				frag.appendChild( document.createTextNode( padIn ) );
				frag.appendChild( el( 'span', 'k', JSON.stringify( key ) ) );
				frag.appendChild( document.createTextNode( ': ' ) );
				frag.appendChild( jsonNode( value[ key ], indent + 1 ) );
				frag.appendChild( document.createTextNode( i < keys.length - 1 ? ',\n' : '\n' ) );
			} );
			frag.appendChild( document.createTextNode( pad + '}' ) );
		}
		return frag;
	}

	function block( label, content ) {
		var wrap = el( 'div', 'nflog-detail__block' );
		wrap.appendChild( el( 'p', 'nflog-detail__label', label ) );
		wrap.appendChild( content );
		return wrap;
	}

	function filterLink( value, params ) {
		var a = el( 'a', '', value );
		a.href = filterUrl( params );
		a.title = i18n.filterBy || '';
		return a;
	}

	// The event without display fields (_*), as served by the API.
	function eventOf( line ) {
		var out = {};
		Object.keys( line ).forEach( function ( key ) {
			if ( '_' !== key.charAt( 0 ) ) {
				out[ key ] = line[ key ];
			}
		} );
		return out;
	}

	// ---------- rendering ----------
	function detail( line ) {
		var box = el( 'div', 'nflog-detail' );
		box.hidden = true;
		box.setAttribute( 'role', 'listitem' );
		var grid = el( 'div', 'nflog-detail__grid' );
		var left = el( 'div', '' );
		var right = el( 'div', '' );
		var context = line.context && 'object' === typeof line.context ? line.context : {};
		var trace = Array.isArray( context.trace ) ? context.trace : null;
		var rest = {};
		Object.keys( context ).forEach( function ( key ) {
			if ( 'trace' !== key ) {
				rest[ key ] = context[ key ];
			}
		} );
		if ( line.changes && line.changes.length ) {
			var pre = el( 'pre', 'nflog-json-view' );
			pre.appendChild( jsonNode( line.changes, 0 ) );
			left.appendChild( block( i18n.changes, pre ) );
		}
		var ctx = el( 'pre', 'nflog-json-view' );
		ctx.appendChild( jsonNode( rest, 0 ) );
		left.appendChild( block( i18n.context, ctx ) );
		if ( trace && trace.length ) {
			var ol = el( 'ol', 'nflog-trace' );
			trace.forEach( function ( frame ) {
				var li = el( 'li', '' );
				var body = el( 'div', '' );
				var fn = ( frame['class'] ? frame['class'] + '::' : '' ) + ( frame['function'] || '{main}' ) + '()';
				body.appendChild( el( 'div', 'nflog-trace__fn', fn ) );
				if ( frame.file ) {
					body.appendChild( el( 'div', 'nflog-trace__at', frame.file + ( frame.line ? ':' + frame.line : '' ) ) );
				}
				li.appendChild( body );
				ol.appendChild( li );
			} );
			left.appendChild( block( i18n.trace, ol ) );
		}

		var dl = el( 'dl', 'nflog-meta' );
		function row( label, value ) {
			if ( null === value || undefined === value || '' === value ) {
				return;
			}
			dl.appendChild( el( 'dt', '', label ) );
			var dd = el( 'dd', '' );
			if ( value instanceof Node ) {
				dd.appendChild( value );
			} else {
				dd.textContent = String( value );
			}
			dl.appendChild( dd );
		}
		var req = line.request || {};
		row( i18n.event, '#' + line.id );
		row( i18n.time, line.occurred_at );
		row( i18n.category, line.category );
		row( i18n.source, req.source || '' );
		row( i18n.actor, line._actor + ( line.actor && line.actor.role ? ' (' + line.actor.role + ')' : '' ) );
		row( i18n.route, [ req.method, req.route ].filter( Boolean ).join( ' ' ) );
		if ( req.id ) {
			row( i18n.request, filterLink( req.id, { request_id: req.id, window: 'all' } ) );
		}
		if ( line.fingerprint ) {
			row( i18n.fingerprint, filterLink( line.fingerprint, { fingerprint: line.fingerprint, window: 'all' } ) );
		}
		if ( line.count > 1 ) {
			row( i18n.count, '×' + line.count );
		}
		row( i18n.hash, line.hash );
		row( i18n.prevHash, line.prev_hash );
		right.appendChild( dl );

		var actions = el( 'div', 'nflog-detail__actions' );
		var copy = el( 'button', 'nflog-cbtn', i18n.copy );
		copy.type = 'button';
		copy.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			var text = JSON.stringify( eventOf( line ), null, 2 );
			var done = function () {
				copy.textContent = i18n.copied;
				window.setTimeout( function () {
					copy.textContent = i18n.copy;
				}, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			}
		} );
		actions.appendChild( copy );
		if ( line._content && data.urls.content && req.id ) {
			var open = el( 'a', 'nflog-cbtn', i18n.openContent );
			var url = new URL( data.urls.content, window.location.href );
			url.searchParams.set( 'request_id', req.id );
			url.searchParams.set( 'window', 'all' );
			open.href = url.toString();
			actions.appendChild( open );
		}
		right.appendChild( actions );
		grid.appendChild( left );
		grid.appendChild( right );
		box.appendChild( grid );
		return box;
	}

	function lineNode( line, isNew ) {
		var sev = line.severity || 'info';
		var node = el( 'div', 'nflog-line nflog-line--' + sev + ( isNew ? ' is-new' : '' ) );
		node.setAttribute( 'role', 'listitem' );
		node.tabIndex = 0;
		node.setAttribute( 'aria-expanded', 'false' );
		node.dataset.id = line.id;
		node.title = line.occurred_at || '';
		node.appendChild( el( 'span', 'nflog-line__time', line._time ) );
		node.appendChild( el( 'span', 'nflog-line__sev', TAGS[ sev ] || sev ) );
		node.appendChild( el( 'span', 'nflog-line__act', line.action ) );
		var loc = el( 'span', 'nflog-line__loc' );
		var bdi = el( 'bdi', '', shortPath( line._loc ) );
		loc.title = line._loc || '';
		loc.appendChild( bdi );
		node.appendChild( loc );
		node.appendChild( el( 'span', 'nflog-line__msg', line._msg ) );
		var cnt = el( 'span', '', line.count > 1 ? '×' + line.count : '' );
		if ( line.count > 1 ) {
			cnt.className = 'nflog-line__count';
		}
		node.appendChild( cnt );
		var box = null;
		var toggle = function () {
			if ( ! box ) {
				box = detail( line );
				node.parentNode.insertBefore( box, node.nextSibling );
			}
			var open = 'true' !== node.getAttribute( 'aria-expanded' );
			node.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			box.hidden = ! open;
		};
		node.addEventListener( 'click', function ( event ) {
			if ( window.getSelection && String( window.getSelection() ).length > 0 && event.detail < 2 ) {
				return; // Selecting text, not toggling.
			}
			toggle();
		} );
		node.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				toggle();
			}
		} );
		return node;
	}

	function daySep( date ) {
		var sep = el( 'div', 'nflog-daysep', date );
		sep.setAttribute( 'role', 'presentation' );
		return sep;
	}

	// Append older lines at the bottom (lines arrive newest first).
	function append( lines ) {
		var frag = document.createDocumentFragment();
		lines.forEach( function ( line ) {
			if ( line._date !== lastDate ) {
				if ( null !== lastDate ) {
					frag.appendChild( daySep( line._date ) );
				}
				if ( null === firstDate ) {
					firstDate = line._date;
				}
				lastDate = line._date;
			}
			frag.appendChild( lineNode( line, false ) );
			track( line );
		} );
		list.appendChild( frag );
	}

	// Prepend new lines at the top (lines arrive newest first).
	function prepend( lines ) {
		if ( ! lines.length ) {
			return;
		}
		var frag = document.createDocumentFragment();
		var top = firstDate;
		lines.forEach( function ( line, i ) {
			if ( i > 0 && line._date !== lines[ i - 1 ]._date ) {
				frag.appendChild( daySep( lines[ i - 1 ]._date ) );
			}
			frag.appendChild( lineNode( line, true ) );
			track( line );
		} );
		var lastNew = lines[ lines.length - 1 ]._date;
		if ( null !== top && lastNew !== top ) {
			frag.appendChild( daySep( top ) );
		}
		firstDate = lines[ 0 ]._date;
		if ( null === lastDate ) {
			lastDate = lastNew;
		}
		clearEmpty();
		list.insertBefore( frag, list.firstChild );
	}

	function track( line ) {
		var id = Number( line.id );
		newest = Math.max( newest, id );
		oldest = 0 === oldest ? id : Math.min( oldest, id );
		count++;
	}

	function showEmpty() {
		if ( ! list.querySelector( '.nflog-console__empty' ) ) {
			list.appendChild( el( 'p', 'nflog-console__empty', i18n.empty ) );
		}
	}
	function clearEmpty() {
		var empty = list.querySelector( '.nflog-console__empty' );
		if ( empty ) {
			empty.parentNode.removeChild( empty );
		}
	}

	// ---------- data ----------
	function request( params ) {
		var url = new URL( data.ajax.url, window.location.href );
		url.searchParams.set( 'action', 'nflog_tail' );
		url.searchParams.set( '_ajax_nonce', data.ajax.nonce );
		var filters = data.filters || {};
		Object.keys( filters ).forEach( function ( key ) {
			url.searchParams.set( key, filters[ key ] );
		} );
		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		return fetch( url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } } ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok || ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : String( response.status ) );
				}
				return json.data;
			} );
		} );
	}

	function loadOlder() {
		if ( busy || ! oldest ) {
			return;
		}
		busy = true;
		olderBtn.disabled = true;
		setStatus( i18n.loading );
		request( { before: oldest } ).then( function ( result ) {
			append( result.lines || [] );
			olderBtn.hidden = ! result.hasMore;
			setStatus( result.hasMore ? fmt( i18n.lines, count ) : i18n.noMore );
		} ).catch( function ( error ) {
			setStatus( i18n.error + ': ' + error.message );
		} ).then( function () {
			busy = false;
			olderBtn.disabled = false;
		} );
	}

	function poll() {
		if ( busy || 'hidden' === document.visibilityState ) {
			return;
		}
		if ( Date.now() - followStarted > ( data.follow.maxMs || 1800000 ) ) {
			setFollow( false );
			setStatus( i18n.stopped );
			return;
		}
		busy = true;
		request( { after: newest } ).then( function ( result ) {
			var lines = result.lines || [];
			prepend( lines );
			if ( lines.length ) {
				setStatus( fmt( i18n.newLines, lines.length ) );
			}
		} ).catch( function ( error ) {
			setStatus( i18n.error + ': ' + error.message );
		} ).then( function () {
			busy = false;
		} );
	}

	function setFollow( on ) {
		followBtn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		live.hidden = ! on;
		if ( timer ) {
			window.clearInterval( timer );
			timer = null;
		}
		if ( on ) {
			followStarted = Date.now();
			poll();
			timer = window.setInterval( poll, data.follow.interval || 5000 );
		}
	}

	// ---------- controls ----------
	followBtn.addEventListener( 'click', function () {
		setFollow( 'true' !== followBtn.getAttribute( 'aria-pressed' ) );
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState && timer ) {
			poll();
		}
	} );

	function setWrap( on ) {
		wrapBtn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		root.classList.toggle( 'is-wrap', on );
		store( 'nflogConsoleWrap', on ? '1' : '0' );
	}
	wrapBtn.addEventListener( 'click', function () {
		setWrap( 'true' !== wrapBtn.getAttribute( 'aria-pressed' ) );
	} );
	setWrap( '1' === store( 'nflogConsoleWrap' ) );

	olderBtn.addEventListener( 'click', loadOlder );

	// Chips and selects apply at once; the text filter on Enter.
	if ( form ) {
		form.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target !== query ) {
				var chip = event.target.closest( '.nflog-cchip' );
				if ( chip ) {
					chip.classList.toggle( 'is-active', event.target.checked );
				}
				submit();
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit();
		} );
	}
	function submit() {
		var params = new URLSearchParams();
		Array.prototype.forEach.call( form.elements, function ( input ) {
			if ( ! input.name || ( 'checkbox' === input.type && ! input.checked ) ) {
				return;
			}
			if ( '' === input.value && 'q' !== input.name ) {
				return;
			}
			params.append( input.name, input.value );
		} );
		window.location.href = ( form.getAttribute( 'action' ) || '' ) + '?' + params.toString();
	}

	// "/" focuses the filter unless the user is typing somewhere.
	document.addEventListener( 'keydown', function ( event ) {
		if ( '/' !== event.key || event.metaKey || event.ctrlKey || event.altKey ) {
			return;
		}
		var target = event.target;
		var tag = target && target.tagName ? target.tagName.toLowerCase() : '';
		if ( 'input' === tag || 'textarea' === tag || 'select' === tag || ( target && target.isContentEditable ) ) {
			return;
		}
		event.preventDefault();
		query.focus();
		query.select();
	} );

	// ---------- initial page ----------
	var initial = data.lines || [];
	if ( initial.length ) {
		append( initial );
	} else {
		showEmpty();
	}
	olderBtn.hidden = ! data.hasMore;
	setStatus( initial.length ? fmt( i18n.lines, initial.length ) : '' );
}() );
