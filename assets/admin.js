/* Greenlight settings page: tabs, live counters, unsaved-changes bar, list filters, page compare. */
( function () {
	var app = document.getElementById( 'gl-app' );
	if ( ! app ) {
		return;
	}
	app.classList.add( 'gl-js' );
	var $ = function ( sel, root ) { return ( root || app ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || app ).querySelectorAll( sel ) ); };
	var tabs = $$( '[role="tab"]' );
	var referer = $( 'input[name="_wp_http_referer"]' );

	/* Tabs. The whole page is one form, so every tab's settings save together. */
	function activate( slug, focus ) {
		var tab = $( '#gl-tab-' + slug );
		if ( ! tab ) {
			return;
		}
		tabs.forEach( function ( t ) {
			var on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			var panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
			if ( panel ) {
				panel.classList.toggle( 'is-active', on );
			}
		} );
		if ( focus ) {
			tab.focus();
		}
		tab.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
		// Keep the tab in the address and come back to it after saving.
		var url = new URL( window.location.href );
		if ( 'overview' === slug ) {
			url.searchParams.delete( 'tab' );
		} else {
			url.searchParams.set( 'tab', slug );
		}
		url.searchParams.delete( 'settings-updated' );
		window.history.replaceState( null, '', url );
		if ( referer ) {
			referer.value = url.pathname + url.search;
		}
	}
	tabs.forEach( function ( t, i ) {
		t.addEventListener( 'click', function () { activate( t.dataset.tab ); } );
		t.addEventListener( 'keydown', function ( e ) {
			var next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[ e.key ];
			if ( undefined === next ) {
				return;
			}
			e.preventDefault();
			activate( tabs[ ( next + tabs.length ) % tabs.length ].dataset.tab, true );
		} );
	} );
	$$( '[data-goto]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			activate( b.dataset.goto );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	} );

	/* Counters, dependencies and the master switch, updated as you click. */
	function settingSwitch( key ) {
		return document.getElementById( 'greenlight-' + key );
	}
	function refresh() {
		var allOn = 0;
		var all = 0;
		$$( '[data-panel]' ).forEach( function ( panel ) {
			var slug = panel.dataset.panel;
			var boxes = $$( '.gl-rows input[role="switch"]', panel );
			var on = boxes.filter( function ( b ) { return b.checked; } ).length;
			allOn += on;
			all += boxes.length;
			var count = $( '[data-tally="' + slug + '"]' );
			if ( count ) {
				count.textContent = on + '/' + boxes.length;
			}
			var foot = $( '[data-tile-tally="' + slug + '"]' );
			if ( foot ) {
				foot.textContent = on + ' of ' + boxes.length + ' on';
			}
			var bar = $( '[data-bar="' + slug + '"]' );
			if ( bar ) {
				bar.style.width = ( boxes.length ? Math.round( on / boxes.length * 100 ) : 0 ) + '%';
			}
		} );
		var ring = $( '[data-ring]' );
		if ( ring ) {
			ring.style.setProperty( '--p', all ? Math.round( allOn / all * 100 ) : 0 );
			$( '[data-total-on]', ring ).textContent = allOn;
		}
		$$( '[data-requires]' ).forEach( function ( el ) {
			var parent = settingSwitch( el.dataset.requires );
			el.classList.toggle( 'is-inactive', !! parent && ! parent.checked );
		} );
		var master = settingSwitch( 'enabled' );
		app.classList.toggle( 'is-off', !! master && ! master.checked );
		$$( '[data-list]' ).forEach( function ( card ) {
			var items = $$( '.gl-item', card );
			var on = items.filter( function ( li ) {
				var del = $( '.gl-remove input', li );
				li.classList.toggle( 'is-removed', del.checked );
				return ! del.checked && $( '.gl-switch input', li ).checked;
			} ).length;
			var removed = items.filter( function ( li ) { return $( '.gl-remove input', li ).checked; } ).length;
			var off = items.length - on - removed;
			$( '[data-count]', card ).textContent = on + ' on' + ( off ? ' · ' + off + ' off' : '' ) + ( removed ? ' · ' + removed + ' to remove' : '' );
		} );
	}

	/* Unsaved changes: compare the form with how it loaded. */
	var status = $( '[data-save-status]' );
	var discard = $( '[data-discard]' );
	function snapshot() {
		var data = new FormData( app );
		data.delete( '_wp_http_referer' );
		return new URLSearchParams( data ).toString();
	}
	var initial = snapshot();
	var dirty = false;
	function check() {
		refresh();
		dirty = snapshot() !== initial;
		app.classList.toggle( 'is-dirty', dirty );
		status.textContent = dirty ? 'Unsaved changes' : 'All changes saved';
		discard.hidden = ! dirty;
	}
	app.addEventListener( 'change', check );
	app.addEventListener( 'input', function ( e ) {
		if ( ! e.target.matches( '[data-filter], [data-compare]' ) ) {
			check();
		}
	} );
	discard.addEventListener( 'click', function () {
		app.reset();
		$$( '[data-filter]' ).forEach( function ( f ) { f.value = ''; filter( f ); } );
		check();
	} );
	app.addEventListener( 'submit', function () { dirty = false; } );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* Filter long lists. */
	function filter( input ) {
		var q = input.value.trim().toLowerCase();
		$$( '.gl-item', input.closest( '[data-list]' ) ).forEach( function ( li ) {
			li.hidden = !! q && li.dataset.search.indexOf( q ) === -1;
		} );
	}
	$$( '[data-filter]' ).forEach( function ( input ) {
		input.addEventListener( 'input', function () { filter( input ); } );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
			}
		} );
	} );

	/* Compare a page with and without Greenlight (same site only). */
	var path = $( '[data-compare]' );
	function openPage( mode ) {
		var home = new URL( app.dataset.home );
		var url;
		try {
			url = new URL( path.value.trim() || '/', home );
		} catch ( err ) {
			url = new URL( home );
		}
		if ( url.host !== home.host ) {
			url = new URL( url.pathname + url.search, home );
		}
		if ( 'off' === mode ) {
			url.searchParams.set( 'greenlight', 'off' );
		} else {
			url.searchParams.delete( 'greenlight' );
		}
		window.open( url.toString(), '_blank', 'noopener' );
	}
	$$( '[data-compare-open]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () { openPage( b.dataset.compareOpen ); } );
	} );
	if ( path ) {
		path.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				openPage( 'on' );
			}
		} );
	}

	refresh();
} )();
