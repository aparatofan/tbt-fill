/**
 * TBT Fill — the student game.
 *
 * Ported from docs/fill-prototype.html, which is the reference for
 * behaviour, timing, layout and animation. Differences from the prototype:
 *   - the set comes from the JSON the server rendered into the page;
 *   - tiles and distractors come from TBTF_Phrase on the server: this file
 *     never splits a phrase, it only compares sequences by text;
 *   - audio is the cached ElevenLabs MP3, not the browser voice;
 *   - pace, audio and the best score are remembered in localStorage.
 *
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.tbtfPlayer || {};
	var t = cfg.i18n || {};

	/* Seconds = base + perWord × answer tiles (distractors don't count).
	   These come from play-testing. Relaxed and Standard were lengthened
	   after the first class test (students needed more time); the original
	   prototype values were 5 + 1.5 and 4 + 1. Fast is unchanged. */
	var PACE = {
		relaxed: { base: 8, perWord: 2.5 },
		standard: { base: 6, perWord: 1.8 },
		fast: { base: 3, perWord: 0.7 }
	};

	var SCORE_BASE = 10;
	var SCORE_PER_SECOND = 2;
	var SCORE_MISS = -5;
	var NEXT_AFTER_CORRECT = 1700;
	var NEXT_AFTER_MISS = 2400;

	/* Chase geometry. */
	var HERO_W = 30;
	var LEAD_W = 42;
	var PACK_X = 72;
	var PUFF_EVERY = 160;

	/** sprintf-lite: %d / %s in order, or %1$d / %2$s by position. */
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( _, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	function shuffle( a ) {
		a = a.slice();
		for ( var i = a.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = a[ i ];
			a[ i ] = a[ j ];
			a[ j ] = tmp;
		}
		return a;
	}

	function reducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/* localStorage, wrapped: the game works without it. */
	var store = {
		get: function ( key ) {
			try {
				return window.localStorage.getItem( 'tbtf:' + key );
			} catch ( e ) {
				return null;
			}
		},
		set: function ( key, value ) {
			try {
				window.localStorage.setItem( 'tbtf:' + key, String( value ) );
			} catch ( e ) {}
		}
	};

	/* Critter art: horned TBT-coloured monsters look right at the hero; the
	   sage hero runs right, glancing back. Shapes are signed off — keep them. */
	var ART = {
		chaser: function ( c ) {
			return '<svg viewBox="0 0 42 42" aria-hidden="true">' +
				'<path d="M9 15 L10 3 L17 11 Z M18 9 L21 0 L25 9 Z M27 11 L33 3 L34 15 Z" fill="' + c + '"/>' +
				'<circle cx="21" cy="24" r="17" fill="' + c + '" stroke="var(--tbtf-surface)" stroke-width="1.5"/>' +
				'<ellipse cx="22" cy="19" rx="4" ry="3.4" fill="#fff"/><ellipse cx="31" cy="18.5" rx="3.6" ry="3.2" fill="#fff"/>' +
				'<circle cx="23.6" cy="19.6" r="1.9" fill="#120808"/><circle cx="32.4" cy="19" r="1.7" fill="#120808"/>' +
				'<path d="M16.5 13.5 L26 17 M27.5 15.8 L35.5 12.5" stroke="#120808" stroke-width="2.2" stroke-linecap="round"/>' +
				'<g class="tbtf-jaw"><ellipse cx="26" cy="30.5" rx="9.5" ry="5.2" fill="#1a0707"/>' +
				'<path d="M17.5 27.5 L19.5 31 L21.5 27 L23.5 31 L25.5 26.8 L27.5 31 L29.5 26.9 L31.5 31 L33.6 27.6 Z" fill="#fff"/>' +
				'<path d="M19.5 34.2 L21.5 31.5 L23.5 35 L25.5 31.5 L27.5 35.2 L29.5 31.5 L31.5 34.4 Z" fill="#fff"/></g></svg>';
		},
		hero: function () {
			return '<svg viewBox="0 0 30 30" aria-hidden="true">' +
				'<ellipse class="tbtf-leg" cx="11" cy="28" rx="3.4" ry="1.9" fill="var(--tbtf-sage-ink)"/>' +
				'<ellipse class="tbtf-leg tbtf-leg-b" cx="19" cy="28" rx="3.4" ry="1.9" fill="var(--tbtf-sage-ink)"/>' +
				'<circle cx="15" cy="15" r="12" fill="var(--tbtf-sage)" stroke="var(--tbtf-surface)" stroke-width="1.5"/>' +
				'<ellipse cx="11" cy="12" rx="3.1" ry="3.7" fill="#fff"/><ellipse cx="18" cy="12" rx="3.1" ry="3.7" fill="#fff"/>' +
				'<circle cx="9.8" cy="12.5" r="1.6" fill="#0E1B33"/><circle cx="16.8" cy="12.5" r="1.6" fill="#0E1B33"/>' +
				'<ellipse cx="14" cy="20" rx="2.2" ry="2" fill="#0E1B33"/>' +
				'<path class="tbtf-sweat" d="M26 3 Q28.5 7 26 8.5 Q23.5 7 26 3 Z" fill="var(--tbtf-blue)"/></svg>';
		}
	};

	/** Let env(safe-area-inset-*) report real values on notched phones. */
	function ensureViewportFit() {
		var meta = document.querySelector( 'meta[name="viewport"]' );
		if ( ! meta ) {
			meta = document.createElement( 'meta' );
			meta.name = 'viewport';
			meta.content = 'width=device-width, initial-scale=1';
			document.head.appendChild( meta );
		}
		if ( ! /viewport-fit/.test( meta.content ) ) {
			meta.content += ', viewport-fit=cover';
		}
	}

	function initGame( app ) {
		var dataNode = app.querySelector( '[data-role="set-data"]' );
		var SET;
		try {
			SET = JSON.parse( dataNode.textContent );
		} catch ( e ) {
			return;
		}
		if ( ! SET || ! SET.items || ! SET.items.length ) {
			return;
		}

		ensureViewportFit();
		// The app is a full-screen layer; the page behind it must not scroll.
		document.documentElement.classList.add( 'tbtf-lock' );

		var q = function ( role ) { return app.querySelector( '[data-role="' + role + '"]' ); };
		var screens = {};
		Array.prototype.forEach.call( app.querySelectorAll( '[data-screen]' ), function ( s ) {
			screens[ s.getAttribute( 'data-screen' ) ] = s;
		} );

		var el = {
			field: q( 'field' ),
			slots: q( 'slots' ),
			reveal: q( 'reveal' ),
			revealLabel: q( 'reveal-label' ),
			revealText: q( 'reveal-text' ),
			cue: q( 'cue' ),
			progress: q( 'progress' ),
			score: q( 'score' ),
			secs: q( 'secs' ),
			chase: q( 'chase' ),
			gap: q( 'gap' ),
			overlay: q( 'pause-overlay' )
		};
		var chaseBox = el.chase;
		var hero = chaseBox.querySelector( '.tbtf-hero' );
		var pack = {
			c1: chaseBox.querySelector( '.tbtf-c1' ),
			c2: chaseBox.querySelector( '.tbtf-c2' ),
			c3: chaseBox.querySelector( '.tbtf-c3' )
		};
		var chasers = [ pack.c1, pack.c2, pack.c3 ];

		Array.prototype.forEach.call( app.querySelectorAll( '[data-art]' ), function ( node ) {
			node.innerHTML = ART[ node.getAttribute( 'data-art' ) ]( node.getAttribute( 'data-color' ) );
		} );

		/* ---------- Settings ---------- */

		var hasAudio = !! app.querySelector( '[data-key="audio"]' );
		var settings = {
			pace: PACE[ store.get( 'pace' ) ] ? store.get( 'pace' ) : ( PACE[ SET.pace ] ? SET.pace : 'standard' ),
			audio: hasAudio && store.get( 'audio' ) !== 'off' ? 'on' : 'off'
		};

		Array.prototype.forEach.call( app.querySelectorAll( '.tbtf-seg' ), function ( seg ) {
			var key = seg.getAttribute( 'data-key' );
			var sync = function () {
				Array.prototype.forEach.call( seg.querySelectorAll( 'button' ), function ( b ) {
					b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-v' ) === settings[ key ] ) );
				} );
			};
			seg.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( 'button' );
				if ( ! b ) {
					return;
				}
				settings[ key ] = b.getAttribute( 'data-v' );
				store.set( key, settings[ key ] );
				sync();
			} );
			sync();
		} );

		var bestKey = 'best:' + SET.slug;
		function best() {
			return parseInt( store.get( bestKey ), 10 ) || 0;
		}
		function showBest( node, isNew ) {
			var b = best();
			node.hidden = ! b;
			node.textContent = isNew ? t.newBest + ' ' + fmt( t.best, b ) : fmt( t.best, b );
			node.classList.toggle( 'is-new', !! isNew );
		}
		showBest( q( 'best' ), false );

		/* ---------- Audio: one element, unlocked by the Start tap ---------- */

		var player = null;
		function audioOn() {
			return hasAudio && settings.audio === 'on';
		}
		function unlockAudio() {
			if ( ! audioOn() ) {
				return;
			}
			if ( ! player ) {
				player = new Audio();
				player.preload = 'auto';
			}
			var first = SET.items.filter( function ( it ) { return it.audio; } )[ 0 ];
			if ( ! first ) {
				return;
			}
			player.src = first.audio;
			player.muted = true;
			var p = player.play();
			var done = function () {
				player.pause();
				player.muted = false;
			};
			if ( p && p.then ) {
				p.then( done, done );
			} else {
				done();
			}
		}
		function preload( item ) {
			if ( ! audioOn() || ! player || ! item.audio ) {
				return;
			}
			if ( player.getAttribute( 'src' ) !== item.audio ) {
				player.src = item.audio;
				player.load();
			}
		}
		function playAudio( item ) {
			if ( ! audioOn() || ! player || ! item.audio ) {
				return;
			}
			try {
				if ( player.getAttribute( 'src' ) !== item.audio ) {
					player.src = item.audio;
				}
				player.muted = false;
				player.currentTime = 0;
				var p = player.play();
				if ( p && p.catch ) {
					p.catch( function () {} );
				}
			} catch ( e ) {}
		}
		function stopAudio() {
			if ( player ) {
				try {
					player.pause();
				} catch ( e ) {}
			}
		}

		/* ---------- Screens ---------- */

		function show( id ) {
			Object.keys( screens ).forEach( function ( k ) {
				screens[ k ].hidden = k !== id;
			} );
			app.classList.toggle( 'is-playing', id === 'game' );
			app.scrollTop = 0;
		}

		/* ---------- Chase ---------- */

		var lastPuff = 0;
		function chaseGeom( f ) {
			var W = chaseBox.clientWidth;
			var end = W - HERO_W - 2;
			var p = 1 - f;
			var heroStart = PACK_X + LEAD_W + ( end - PACK_X - LEAD_W ) * 0.5;
			return { lead: PACK_X + ( end - LEAD_W - PACK_X ) * p, hero: heroStart + ( end - heroStart ) * p };
		}
		function placePack( lead ) {
			pack.c1.style.left = lead + 'px';
			pack.c2.style.left = ( lead - 36 ) + 'px';
			pack.c3.style.left = ( lead - 70 ) + 'px';
		}
		function setChase( f ) {
			var g = chaseGeom( f );
			placePack( g.lead );
			hero.style.left = g.hero + 'px';
			var gs = g.lead + LEAD_W - 6;
			el.gap.style.left = gs + 'px';
			el.gap.style.width = Math.max( 0, g.hero + 6 - gs ) + 'px';
			el.gap.classList.toggle( 'is-low', f < 0.3 );
			hero.classList.toggle( 'is-nervous', f < 0.3 && f > 0 );
			var now = performance.now();
			if ( f > 0 && now - lastPuff > PUFF_EVERY && ! reducedMotion() ) {
				lastPuff = now;
				var d = document.createElement( 'span' );
				d.className = 'tbtf-puff';
				d.style.left = ( g.hero + 2 ) + 'px';
				chaseBox.appendChild( d );
				setTimeout( function () { d.remove(); }, 500 );
			}
		}
		function resetChase() {
			chasers.forEach( function ( c ) { c.classList.remove( 'is-lunge', 'is-stunned' ); } );
			hero.className = 'tbtf-critter tbtf-hero';
			el.gap.className = 'tbtf-gap';
			setChase( 1 );
		}

		/* ---------- Game state ---------- */

		var G = null;
		var cur = null;
		var raf = 0;
		var timers = [];

		function later( fn, ms ) {
			timers.push( setTimeout( fn, ms ) );
		}
		function clearLater() {
			timers.forEach( clearTimeout );
			timers = [];
		}

		function startGame() {
			clearLater();
			G = {
				queue: shuffle( SET.items.map( function ( _, i ) { return i; } ) ),
				pos: 0,
				score: 0,
				retried: {},
				result: {}
			};
			show( 'game' );
			el.score.textContent = '0';
			nextItem();
		}

		function nextItem() {
			if ( G.pos >= G.queue.length ) {
				return finish();
			}
			var idx = G.queue[ G.pos ];
			var item = SET.items[ idx ];
			var answer = item.tiles.slice();
			var all = shuffle( answer.concat( item.distractors || [] ) ).map( function ( text, i ) {
				return { id: i, text: text };
			} );
			cur = { idx: idx, item: item, answer: answer, tiles: all, slots: answer.map( function () { return null; } ), locked: false };

			el.progress.textContent = fmt( t.progress, G.pos + 1, G.queue.length );
			el.cue.textContent = item.pl;
			el.reveal.hidden = true;
			el.slots.hidden = false;
			el.slots.className = 'tbtf-slots';

			el.field.innerHTML = '';
			all.forEach( function ( tile ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'tbtf-tile';
				b.textContent = tile.text;
				b.setAttribute( 'lang', 'en' );
				b.addEventListener( 'click', function () { place( tile.id ); } );
				el.field.appendChild( b );
				tile.el = b;
			} );
			app.classList.remove( 'is-compact' );
			renderSlots();
			sizeSlots();
			// Too crowded for full-size tiles on this screen (a long phrase on a
			// small phone): use the compact size, still at least 48px tall.
			if ( ! layoutTiles() ) {
				app.classList.add( 'is-compact' );
				sizeSlots();
				layoutTiles();
			}
			preload( item );

			resetChase();
			var pace = PACE[ settings.pace ] || PACE.standard;
			var total = pace.base + pace.perWord * answer.length;
			cur.timer = { total: total, left: total, running: true, last: performance.now() };
			el.secs.textContent = Math.ceil( total );
			cancelAnimationFrame( raf );
			raf = requestAnimationFrame( tick );
		}

		/* All slots share one width, so the width gives nothing away: enough
		   for the widest word of the round (distractors included) as it shows
		   in a slot. The slots are a one-row grid that cannot wrap: when the
		   row is tight the slots share the line and the slot text shrinks to
		   match, down to SLOT_FONT_MIN. */
		var SLOT_FONT = 17;
		var SLOT_FONT_MIN = 11;
		var SLOT_GAP = 8;
		var SLOT_GAP_TIGHT = 4;
		var SLOT_MIN_W = 64;
		function sizeSlots() {
			var box = el.slots;
			box.style.removeProperty( '--tbtf-slot-font' );
			box.style.removeProperty( '--tbtf-slot-pad' );
			box.style.removeProperty( '--tbtf-slot-gap' );

			var probe = document.createElement( 'div' );
			probe.className = 'tbtf-slot';
			probe.style.cssText = 'position:absolute;visibility:hidden;width:auto;';
			var chip = document.createElement( 'span' );
			chip.className = 'tbtf-tile';
			chip.style.width = 'auto';
			probe.appendChild( chip );
			box.appendChild( probe );
			var maxW = 0;
			var maxText = 0;
			cur.tiles.forEach( function ( tile ) {
				chip.textContent = tile.text;
				maxW = Math.max( maxW, chip.offsetWidth );
				maxText = Math.max( maxText, chip.scrollWidth );
			} );
			var frame = chip.offsetWidth - chip.clientWidth; // the tile's borders
			probe.remove();

			var n = cur.slots.length;
			var line = box.parentNode.clientWidth;
			var share = Math.floor( ( line - SLOT_GAP * ( n - 1 ) ) / n );
			var want = maxW + 8;
			var width = want;

			if ( want <= share ) {
				width = Math.min( Math.max( want, SLOT_MIN_W ), share );
			} else {
				// Tight row: smaller gaps and padding, then the font size the
				// widest word needs.
				share = Math.floor( ( line - SLOT_GAP_TIGHT * ( n - 1 ) ) / n );
				box.style.setProperty( '--tbtf-slot-gap', SLOT_GAP_TIGHT + 'px' );
				var textRoom = share - 4 - frame - 2;
				var textNow = maxText - 12; // scrollWidth includes 6px padding a side
				var font = Math.floor( SLOT_FONT * textRoom / Math.max( 1, textNow ) );
				width = Math.max( 1, share );
				box.style.setProperty( '--tbtf-slot-font', Math.max( SLOT_FONT_MIN, Math.min( SLOT_FONT, font ) ) + 'px' );
				box.style.setProperty( '--tbtf-slot-pad', '2px' );
			}
			box.style.setProperty( '--tbtf-slot-w', width + 'px' );
			box.style.gridTemplateColumns = 'repeat(' + n + ', minmax(0, ' + width + 'px))';
		}

		/* Random spots with ≥ 12px between tiles and ≥ 6px from the edges
		   (rejection sampling, 400 tries a tile); centred wrapped rows if any
		   tile cannot be placed. Tiles never move during play. Returns false
		   when even the rows overflow the field. */
		function layoutTiles() {
			var W = el.field.clientWidth;
			var H = el.field.clientHeight;
			var pad = 6;
			var gap = 12;
			var tiles = cur.tiles.filter( function ( tile ) { return ! tile.el.classList.contains( 'is-used' ); } );

			function tryRandom() {
				var placed = [];
				for ( var n = 0; n < tiles.length; n++ ) {
					var w = tiles[ n ].el.offsetWidth;
					var h = tiles[ n ].el.offsetHeight;
					var spot = null;
					for ( var k = 0; k < 400 && ! spot; k++ ) {
						var x = pad + Math.random() * Math.max( 0, W - w - 2 * pad );
						var y = pad + Math.random() * Math.max( 0, H - h - 2 * pad );
						var clear = placed.every( function ( p ) {
							return x + w + gap < p.x || p.x + p.w + gap < x || y + h + gap < p.y || p.y + p.h + gap < y;
						} );
						if ( clear ) {
							spot = { x: x, y: y, w: w, h: h };
						}
					}
					if ( ! spot ) {
						return null;
					}
					placed.push( spot );
				}
				return placed;
			}

			function rows() {
				var lines = [];
				var line = { items: [], w: 0, h: 0 };
				tiles.forEach( function ( tile ) {
					var w = tile.el.offsetWidth;
					var h = tile.el.offsetHeight;
					var need = line.items.length ? line.w + 10 + w : w;
					if ( line.items.length && need > W - 2 * pad ) {
						lines.push( line );
						line = { items: [], w: 0, h: 0 };
						need = w;
					}
					line.items.push( { w: w } );
					line.w = need;
					line.h = Math.max( line.h, h );
				} );
				lines.push( line );
				var total = lines.reduce( function ( s, l ) { return s + l.h; }, 0 ) + 10 * ( lines.length - 1 );
				fits = total <= H - 2 * pad;
				var y = Math.max( pad, ( H - total ) / 2 );
				var out = [];
				lines.forEach( function ( l ) {
					var x = Math.max( pad, ( W - l.w ) / 2 );
					l.items.forEach( function ( it ) {
						out.push( { x: x, y: y } );
						x += it.w + 10;
					} );
					y += l.h + 10;
				} );
				return out;
			}

			var fits = true;
			var spots = tryRandom() || rows();
			tiles.forEach( function ( tile, i ) {
				tile.el.style.left = spots[ i ].x + 'px';
				tile.el.style.top = spots[ i ].y + 'px';
				tile.el.style.animationDelay = ( i * 40 ) + 'ms';
			} );
			return fits;
		}

		function renderSlots() {
			el.slots.innerHTML = '';
			cur.slots.forEach( function ( id, k ) {
				var s = document.createElement( 'div' );
				s.className = id === null ? 'tbtf-slot' : 'tbtf-slot is-filled';
				if ( id !== null ) {
					var chip = document.createElement( 'button' );
					chip.type = 'button';
					chip.className = 'tbtf-tile';
					chip.textContent = cur.tiles[ id ].text;
					chip.setAttribute( 'lang', 'en' );
					chip.setAttribute( 'aria-label', fmt( t.remove, cur.tiles[ id ].text ) );
					chip.addEventListener( 'click', function () { unplace( k ); } );
					s.appendChild( chip );
				}
				el.slots.appendChild( s );
			} );
			cur.tiles.forEach( function ( tile ) {
				tile.el.classList.toggle( 'is-used', cur.slots.indexOf( tile.id ) !== -1 );
			} );
		}

		function place( id ) {
			if ( ! cur || cur.locked || ! cur.timer.running || cur.slots.indexOf( id ) !== -1 ) {
				return;
			}
			var k = cur.slots.indexOf( null );
			if ( k < 0 ) {
				return;
			}
			cur.slots[ k ] = id;
			renderSlots();
			if ( cur.slots.indexOf( null ) === -1 ) {
				check();
			}
		}

		function unplace( k ) {
			if ( ! cur || cur.locked || ! cur.timer.running ) {
				return;
			}
			cur.slots[ k ] = null;
			renderSlots();
		}

		/* Compared by text, so duplicate words in a phrase are fine. */
		function check() {
			var right = cur.slots.every( function ( id, i ) {
				return cur.tiles[ id ].text === cur.answer[ i ];
			} );
			if ( right ) {
				success();
			} else {
				fail( 'wrong' );
			}
		}

		function tick( now ) {
			var tm = cur && cur.timer;
			if ( ! tm ) {
				return;
			}
			if ( tm.running ) {
				tm.left -= ( now - tm.last ) / 1000;
				tm.last = now;
				var f = Math.max( 0, tm.left / tm.total );
				el.secs.textContent = Math.max( 0, Math.ceil( tm.left ) );
				setChase( f );
				if ( tm.left <= 0 ) {
					return fail( 'time' );
				}
			} else {
				tm.last = now;
			}
			raf = requestAnimationFrame( tick );
		}

		function addScore( n ) {
			G.score = Math.max( 0, G.score + n );
			el.score.textContent = G.score;
			var g = document.createElement( 'span' );
			g.className = 'tbtf-gain ' + ( n >= 0 ? 'is-plus' : 'is-minus' );
			g.textContent = ( n >= 0 ? '+' : '' ) + n;
			el.score.parentNode.appendChild( g );
			setTimeout( function () { g.remove(); }, 1000 );
		}

		function reveal( label, cls ) {
			el.slots.hidden = true;
			el.reveal.hidden = false;
			el.reveal.className = 'tbtf-reveal ' + cls;
			el.revealLabel.textContent = label;
			el.revealText.textContent = cur.item.en;
		}

		function success() {
			cur.locked = true;
			cur.timer.running = false;
			cancelAnimationFrame( raf );
			addScore( SCORE_BASE + Math.ceil( cur.timer.left ) * SCORE_PER_SECOND );
			G.result[ cur.idx ] = G.retried[ cur.idx ] ? 'retry' : 'first';
			el.slots.classList.add( 'is-good' );
			chasers.forEach( function ( c ) { c.classList.add( 'is-stunned' ); } );
			el.gap.classList.add( 'is-gone' );
			hero.className = 'tbtf-critter tbtf-hero is-escape';
			later( function () { reveal( t.correct, 'is-good' ); }, 350 );
			playAudio( cur.item );
			later( function () {
				G.pos++;
				nextItem();
			}, NEXT_AFTER_CORRECT );
		}

		function fail( reason ) {
			cur.locked = true;
			cur.timer.running = false;
			cancelAnimationFrame( raf );
			el.secs.textContent = '0';
			el.gap.classList.add( 'is-gone' );
			chasers.forEach( function ( c ) { c.classList.add( 'is-lunge' ); } );
			placePack( parseFloat( hero.style.left ) - LEAD_W + 10 );
			hero.className = 'tbtf-critter tbtf-hero';
			later( function () { hero.className = 'tbtf-critter tbtf-hero is-gulp'; }, 280 );
			addScore( SCORE_MISS );
			// A miss comes back once, at the end of the queue; a second miss is final.
			if ( ! G.retried[ cur.idx ] ) {
				G.retried[ cur.idx ] = true;
				G.queue.push( cur.idx );
			} else {
				G.result[ cur.idx ] = 'missed';
			}
			if ( reason === 'wrong' ) {
				el.slots.classList.add( 'is-bad' );
			}
			var item = cur.item;
			later( function () {
				reveal( reason === 'time' ? t.timeUp : t.notQuite, 'is-bad' );
				playAudio( item );
			}, reason === 'wrong' ? 450 : 0 );
			later( function () {
				G.pos++;
				nextItem();
			}, NEXT_AFTER_MISS );
		}

		function finish() {
			cur = null;
			show( 'end' );
			q( 'final' ).textContent = G.score;
			var ids = SET.items.map( function ( _, i ) { return i; } );
			var first = ids.filter( function ( i ) { return G.result[ i ] === 'first'; } ).length;
			q( 'summary' ).textContent = fmt( t.summary, first, ids.length );

			var isNew = G.score > best();
			if ( isNew ) {
				store.set( bestKey, G.score );
			}
			showBest( q( 'end-best' ), isNew && G.score > 0 );
			showBest( q( 'best' ), false );

			var labels = { first: t.firstTry, retry: t.secondTry, missed: t.missed };
			var list = q( 'results' );
			list.innerHTML = '';
			ids.forEach( function ( i ) {
				var r = G.result[ i ] || 'missed';
				var li = document.createElement( 'li' );
				var s = document.createElement( 'span' );
				s.textContent = SET.items[ i ].en;
				s.setAttribute( 'lang', 'en' );
				var c = document.createElement( 'span' );
				c.className = 'tbtf-chip is-' + r;
				c.textContent = labels[ r ];
				li.appendChild( s );
				li.appendChild( c );
				list.appendChild( li );
			} );
		}

		/* ---------- Pause ---------- */

		function pause() {
			if ( ! cur || cur.locked || ! cur.timer.running ) {
				return;
			}
			cur.timer.running = false;
			el.overlay.hidden = false;
			q( 'resume' ).focus();
		}
		function resume() {
			el.overlay.hidden = true;
			if ( cur && ! cur.locked ) {
				cur.timer.last = performance.now();
				cur.timer.running = true;
			}
		}
		function quit() {
			el.overlay.hidden = true;
			cancelAnimationFrame( raf );
			clearLater();
			stopAudio();
			cur = null;
			show( 'start' );
		}

		q( 'pause' ).addEventListener( 'click', pause );
		q( 'resume' ).addEventListener( 'click', resume );
		q( 'quit' ).addEventListener( 'click', quit );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				pause();
			}
		} );
		window.addEventListener( 'pagehide', pause );

		q( 'start' ).addEventListener( 'click', function () {
			unlockAudio();
			( document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve() ).then( startGame );
		} );
		q( 'again' ).addEventListener( 'click', startGame );
		q( 'settings' ).addEventListener( 'click', function () { show( 'start' ); } );

		var resizeTimer = 0;
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				if ( cur && ! cur.locked ) {
					layoutTiles();
					if ( cur.timer ) {
						setChase( Math.max( 0, cur.timer.left / cur.timer.total ) );
					}
				}
			}, 120 );
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.tbtf-player [data-role="app"]' ), initGame );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
