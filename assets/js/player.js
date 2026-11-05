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
	   prototype values were 5 + 1.5 and 4 + 1. Relaxed was lengthened again
	   after a later class test (8 + 2.5 → 12 + 2.5, +4 s per phrase). Fast
	   is unchanged. */
	var PACE = {
		relaxed: { base: 12, perWord: 2.5 },
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

	/* ---------- Sound effects ----------
	   Made in the browser with Web Audio, so there are no files to fetch,
	   cache or license. The context is created on the Start tap, which is
	   what unlocks sound on iOS and Android. The end-of-round cheer is the
	   one recorded sound: an MP3 the server made once with ElevenLabs,
	   decoded into the same context; without it the game claps itself. */
	var Sfx = {
		ctx: null,
		out: null,
		noise: null,
		cheer: null,

		/** Create or wake the context. Call from a tap. */
		unlock: function () {
			var AC = window.AudioContext || window.webkitAudioContext;
			if ( ! AC ) {
				return false;
			}
			try {
				if ( ! this.ctx ) {
					this.ctx = new AC();
					this.out = this.ctx.createGain();
					this.out.gain.value = 0.55;
					this.out.connect( this.ctx.destination );
				}
				if ( this.ctx.state === 'suspended' ) {
					this.ctx.resume();
				}
			} catch ( e ) {
				this.ctx = null;
			}
			return !! this.ctx;
		},

		/** Fetch and decode the cheer once, in the background. */
		loadCheer: function ( url ) {
			var self = this;
			if ( ! url || self.cheer || ! self.ctx || ! window.fetch ) {
				return;
			}
			self.cheer = 'loading';
			fetch( url ).then( function ( r ) {
				return r.ok ? r.arrayBuffer() : Promise.reject();
			} ).then( function ( buf ) {
				return new Promise( function ( ok, no ) {
					// Old Safari only has the callback form.
					var p = self.ctx.decodeAudioData( buf, ok, no );
					if ( p && p.then ) {
						p.then( ok, no );
					}
				} );
			} ).then( function ( decoded ) {
				self.cheer = decoded;
			} ).catch( function () {
				self.cheer = null;
			} );
		},

		tone: function ( freq, at, dur, type, vol, toFreq ) {
			var c = this.ctx;
			var t = c.currentTime + at;
			var o = c.createOscillator();
			var g = c.createGain();
			o.type = type || 'sine';
			o.frequency.setValueAtTime( freq, t );
			if ( toFreq ) {
				o.frequency.exponentialRampToValueAtTime( toFreq, t + dur );
			}
			g.gain.setValueAtTime( 0.0001, t );
			g.gain.exponentialRampToValueAtTime( vol, t + 0.012 );
			g.gain.exponentialRampToValueAtTime( 0.0001, t + dur );
			o.connect( g );
			g.connect( this.out );
			o.start( t );
			o.stop( t + dur + 0.03 );
		},

		noiseBuffer: function () {
			if ( ! this.noise ) {
				var len = this.ctx.sampleRate;
				this.noise = this.ctx.createBuffer( 1, len, this.ctx.sampleRate );
				var d = this.noise.getChannelData( 0 );
				for ( var i = 0; i < len; i++ ) {
					d[ i ] = Math.random() * 2 - 1;
				}
			}
			return this.noise;
		},

		/** One hand clap: a short burst of filtered noise. */
		clap: function ( at, vol ) {
			var c = this.ctx;
			var t = c.currentTime + at;
			var src = c.createBufferSource();
			src.buffer = this.noiseBuffer();
			var f = c.createBiquadFilter();
			f.type = 'bandpass';
			f.frequency.value = 900 + Math.random() * 1600;
			f.Q.value = 0.9;
			var g = c.createGain();
			var len = 0.025 + Math.random() * 0.04;
			g.gain.setValueAtTime( 0.0001, t );
			g.gain.exponentialRampToValueAtTime( vol, t + 0.004 );
			g.gain.exponentialRampToValueAtTime( 0.0001, t + len );
			src.connect( f );
			f.connect( g );
			g.connect( this.out );
			src.start( t, Math.random() * 0.9, len + 0.02 );
		},

		play: function ( name ) {
			if ( ! this.ctx ) {
				return;
			}
			try {
				// A phone suspends the context when the page was in the background.
				if ( this.ctx.state === 'suspended' ) {
					this.ctx.resume();
				}
				this[ 'fx_' + name ]();
			} catch ( e ) {}
		},

		/** A word right for its position: a light rising ding. */
		fx_good: function () {
			this.tone( 880, 0, 0.13, 'sine', 0.32 );
			this.tone( 1320, 0.055, 0.16, 'sine', 0.24 );
		},
		/** A word wrong for its position: a soft low bonk. */
		fx_bad: function () {
			this.tone( 220, 0, 0.2, 'triangle', 0.42, 140 );
		},
		/** The whole phrase right: a quick rising arpeggio. */
		fx_win: function () {
			var self = this;
			[ 523.25, 659.25, 783.99, 1046.5 ].forEach( function ( f, i ) {
				self.tone( f, i * 0.075, 0.24, 'sine', 0.28 );
				self.tone( f * 2, i * 0.075, 0.12, 'sine', 0.06 );
			} );
		},
		/** Wrong or out of time: two falling low tones. */
		fx_lose: function () {
			this.tone( 247, 0, 0.22, 'triangle', 0.4, 196 );
			this.tone( 185, 0.2, 0.36, 'triangle', 0.4, 123 );
		},
		/** The end: the recorded cheer if there is one, else applause. */
		fx_cheer: function () {
			if ( this.cheer && this.cheer !== 'loading' ) {
				var src = this.ctx.createBufferSource();
				src.buffer = this.cheer;
				src.connect( this.out );
				src.start();
				return;
			}
			// About 3s of a small room clapping: dense at first, thinning out.
			for ( var i = 0; i < 150; i++ ) {
				var at = Math.pow( Math.random(), 1.6 ) * 3;
				this.clap( at, ( 0.5 + Math.random() * 0.5 ) * ( 1 - at / 3.6 ) );
			}
			this.fx_win();
		}
	};

	/* ---------- Fireworks ----------
	   Ported from TBT Swipe's end-of-deck celebration (deck.js,
	   fireFireworks) so the two tools celebrate the same way: shells rise
	   and burst on one canvas for ~5.8s, then the canvas is removed. The
	   caller skips it for reduced motion. On the light background the
	   sparks use the strong palette without glow; in dark mode, the pale
	   palette with additive glow, as Swipe does on its blue phone field. */
	var FX_MS = 5800;
	var FX_FADE = 500;
	var FX_OUT = 600;
	var FX_GRAV = 0.00042;
	var FX_DRAG = 0.9945;
	var FX_TAIL = 50;
	var FX_SHELLS = 15;
	var FX_SPARKS = 110;
	var FX_SPREAD = 3.4;
	var FX_LIFE = 1.5;
	var FX_PALETTE_DARK = [ '#FFFFFF', '#FFD466', '#7FE0A6', '#BFD4FF', '#FFAECF', '#CC9933' ];
	var FX_PALETTE_LIGHT = [ '#0856C9', '#E8A317', '#2FAE6A', '#5B8DEF', '#D9469B', '#C87A12' ];

	function fireworks( host ) {
		if ( ! window.requestAnimationFrame ) {
			return;
		}
		var canvas = document.createElement( 'canvas' );
		var ctx = canvas.getContext ? canvas.getContext( '2d' ) : null;
		if ( ! ctx ) {
			return;
		}
		var w = window.innerWidth;
		var h = window.innerHeight;
		var dpr = Math.min( window.devicePixelRatio || 1, 2 );
		canvas.className = 'tbtf-fx';
		canvas.width = Math.round( w * dpr );
		canvas.height = Math.round( h * dpr );
		canvas.style.width = w + 'px';
		canvas.style.height = h + 'px';
		canvas.setAttribute( 'aria-hidden', 'true' );
		ctx.scale( dpr, dpr );
		ctx.lineCap = 'round';
		host.appendChild( canvas );

		var dark = !! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches );
		var colors = dark ? FX_PALETTE_DARK : FX_PALETTE_LIGHT;
		var glow = dark;
		var rgb = colors.map( function ( c ) {
			return 'rgba(' + parseInt( c.slice( 1, 3 ), 16 ) + ',' + parseInt( c.slice( 3, 5 ), 16 ) + ',' + parseInt( c.slice( 5, 7 ), 16 ) + ',';
		} );

		// Sparks are bucketed by colour × alpha × width and each bucket is
		// stroked as one path: 1,650 separate strokes a frame drop frames on
		// a mid-range phone.
		var ALPHA_STEPS = 8;
		var WIDTH_STEPS = 3;
		var bucketCount = colors.length * ALPHA_STEPS * WIDTH_STEPS;
		var buckets = [];
		for ( var b = 0; b < bucketCount; b++ ) {
			buckets.push( [] );
		}

		var below = h + 12;
		var shells = [];
		var sparks = [];
		var flashes = [];
		var launchWindow = FX_MS * 0.6;
		var finaleCount = Math.max( 3, Math.round( FX_SHELLS * 0.22 ) );
		var mainCount = FX_SHELLS - finaleCount;
		for ( var i = 0; i < FX_SHELLS; i++ ) {
			var at;
			if ( i >= mainCount ) {
				at = launchWindow + Math.random() * 260;
			} else if ( i === 0 ) {
				at = 0;
			} else if ( i === 1 ) {
				at = 90;
			} else {
				at = ( i / ( mainCount - 1 ) ) * launchWindow * 0.86;
			}
			at = Math.max( 0, at + ( Math.random() * 2 - 1 ) * 130 );
			var burstY = h * ( 0.16 + Math.random() * 0.3 );
			var rise = 520 + Math.random() * 220;
			shells.push( {
				x: w * ( 0.12 + Math.random() * 0.76 ),
				y: below,
				burstY: burstY,
				vy: -( below - burstY ) / rise,
				at: at,
				ci: i % colors.length,
				live: false,
				done: false
			} );
		}

		function burst( shell ) {
			var base = Math.random() * Math.PI * 2;
			var slice = ( Math.PI * 2 ) / FX_SPARKS;
			var sMin = 0.1 * FX_SPREAD;
			var sMax = 0.34 * FX_SPREAD;
			var wSpan = 0.9 + FX_SPREAD * 0.32;
			for ( var s = 0; s < FX_SPARKS; s++ ) {
				var ang = base + s * slice + ( Math.random() - 0.5 ) * slice;
				var speed = sMin + ( sMax - sMin ) * Math.sqrt( Math.random() );
				var width = 1.5 + Math.random() * wSpan;
				sparks.push( {
					x: shell.x,
					y: shell.y,
					vx: Math.cos( ang ) * speed,
					vy: Math.sin( ang ) * speed,
					ci: ( glow && Math.random() < 0.1 ) ? 0 : shell.ci,
					wb: Math.min( WIDTH_STEPS - 1, ( ( width - 1.5 ) / wSpan * WIDTH_STEPS ) | 0 ),
					ttl: ( 1000 + Math.random() * 700 ) * FX_LIFE,
					crackle: Math.random() < 0.45,
					age: 0
				} );
			}
			flashes.push( { x: shell.x, y: shell.y, ci: shell.ci, age: 0, ttl: 260, r: 26 + FX_SPREAD * 26 } );
		}

		var last = performance.now();
		var elapsed = 0;

		function frame() {
			var t = performance.now();
			var dt = Math.min( 34, t - last );
			last = t;
			elapsed += dt;
			if ( ! canvas.parentNode ) {
				return;
			}
			if ( elapsed > FX_MS ) {
				canvas.remove();
				return;
			}
			ctx.clearRect( 0, 0, w, h );
			ctx.globalCompositeOperation = glow ? 'lighter' : 'source-over';
			var out = elapsed > FX_MS - FX_OUT ? ( FX_MS - elapsed ) / FX_OUT : 1;
			var drag = Math.pow( FX_DRAG, dt );
			var n, p;

			for ( n = flashes.length - 1; n >= 0; n-- ) {
				var fl = flashes[ n ];
				fl.age += dt;
				if ( fl.age >= fl.ttl ) {
					flashes.splice( n, 1 );
					continue;
				}
				var k = fl.age / fl.ttl;
				var fade = ( 1 - k ) * ( 1 - k );
				var rad = fl.r * ( 0.4 + 1.9 * k );
				var pre = rgb[ fl.ci ];
				var grad = ctx.createRadialGradient( fl.x, fl.y, 0, fl.x, fl.y, rad );
				grad.addColorStop( 0, glow ? 'rgba(255,255,255,' + ( 0.85 * fade * out ) + ')' : pre + ( 0.85 * fade * out ) + ')' );
				grad.addColorStop( 0.4, pre + ( 0.35 * fade * out ) + ')' );
				grad.addColorStop( 1, pre + '0)' );
				ctx.globalAlpha = 1;
				ctx.fillStyle = grad;
				ctx.beginPath();
				ctx.arc( fl.x, fl.y, rad, 0, Math.PI * 2 );
				ctx.fill();
			}

			for ( n = 0; n < shells.length; n++ ) {
				var sh = shells[ n ];
				if ( sh.done ) {
					continue;
				}
				if ( ! sh.live ) {
					if ( elapsed < sh.at ) {
						continue;
					}
					sh.live = true;
				}
				sh.y += sh.vy * dt;
				if ( sh.y <= sh.burstY ) {
					sh.y = sh.burstY;
					sh.done = true;
					burst( sh );
					continue;
				}
				ctx.globalAlpha = 1;
				ctx.strokeStyle = colors[ sh.ci ];
				ctx.lineWidth = 2;
				ctx.beginPath();
				ctx.moveTo( sh.x, Math.min( sh.y + 14, below ) );
				ctx.lineTo( sh.x, sh.y );
				ctx.stroke();
			}

			for ( n = 0; n < bucketCount; n++ ) {
				buckets[ n ].length = 0;
			}
			for ( n = 0; n < sparks.length; n++ ) {
				p = sparks[ n ];
				p.age += dt;
				var leftMs = p.ttl - p.age;
				if ( leftMs <= 0 ) {
					continue;
				}
				p.vy += FX_GRAV * dt;
				p.vx *= drag;
				p.vy *= drag;
				p.x += p.vx * dt;
				p.y += p.vy * dt;
				var a = leftMs < FX_FADE ? leftMs / FX_FADE : 1;
				if ( p.crackle && p.age > p.ttl * 0.5 && Math.random() < 0.45 ) {
					a *= 0.18;
				}
				if ( a < 0.02 ) {
					continue;
				}
				var ab = Math.min( ALPHA_STEPS - 1, ( a * ALPHA_STEPS ) | 0 );
				buckets[ ( p.ci * ALPHA_STEPS + ab ) * WIDTH_STEPS + p.wb ].push( p.x - p.vx * FX_TAIL, p.y - p.vy * FX_TAIL, p.x, p.y );
			}
			for ( n = 0; n < bucketCount; n++ ) {
				var quads = buckets[ n ];
				if ( ! quads.length ) {
					continue;
				}
				var wb = n % WIDTH_STEPS;
				var rest = ( n - wb ) / WIDTH_STEPS;
				var abi = rest % ALPHA_STEPS;
				ctx.globalAlpha = ( abi + 1 ) / ALPHA_STEPS * out;
				ctx.strokeStyle = colors[ ( rest - abi ) / ALPHA_STEPS ];
				ctx.lineWidth = 1.8 + wb * 1.2;
				ctx.beginPath();
				for ( var v = 0; v < quads.length; v += 4 ) {
					ctx.moveTo( quads[ v ], quads[ v + 1 ] );
					ctx.lineTo( quads[ v + 2 ], quads[ v + 3 ] );
				}
				ctx.stroke();
			}
			window.requestAnimationFrame( frame );
		}
		window.requestAnimationFrame( frame );
	}

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
			line: q( 'line' ),
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
			audio: hasAudio && store.get( 'audio' ) !== 'off' ? 'on' : 'off',
			sfx: store.get( 'sfx' ) === 'off' ? 'off' : 'on'
		};
		function sfx( name ) {
			if ( settings.sfx === 'on' ) {
				Sfx.play( name );
			}
		}
		// The spoken phrase waits for the result sound to finish.
		function speechDelay() {
			return settings.sfx === 'on' ? 550 : 0;
		}

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

		var screenNow = 'start';
		function show( id ) {
			screenNow = id;
			Object.keys( screens ).forEach( function ( k ) {
				screens[ k ].hidden = k !== id;
			} );
			app.classList.toggle( 'is-playing', id === 'game' );
			app.scrollTop = 0;
			if ( id !== 'end' ) {
				Array.prototype.forEach.call( app.querySelectorAll( '.tbtf-fx' ), function ( c ) { c.remove(); } );
			}
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
			cur = { idx: idx, item: item, answer: answer, tiles: all, placed: [], locked: false };

			el.progress.textContent = fmt( t.progress, G.pos + 1, G.queue.length );
			el.cue.textContent = item.pl;
			el.reveal.hidden = true;
			el.line.hidden = false;
			el.line.className = 'tbtf-line';

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
			renderLine();
			sizeLine();
			// Too crowded for full-size tiles on this screen (a long phrase on a
			// small phone): use the compact size, still at least 48px tall.
			if ( ! layoutTiles() ) {
				app.classList.add( 'is-compact' );
				sizeLine();
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

		/* The answer line keeps one height for the whole round, so the bottom
		   never grows and the field never shrinks under the tiles. It
		   reserves room for as many chips as the answer has words, measured on
		   the widest tiles of the round (an extra word may be placed in place
		   of a right one), on one or two rows. If they need three rows (a long
		   phrase on a small phone), the chip text shrinks step by step down to
		   LINE_FONT_MIN to fit two; failing that, the line reserves what the
		   words need at the smallest size. */
		var LINE_FONT = 17;
		var LINE_FONT_MIN = 11;
		function sizeLine() {
			var box = el.line;
			box.style.removeProperty( '--tbtf-line-h' );
			box.style.removeProperty( '--tbtf-line-font' );

			var probe = document.createElement( 'div' );
			probe.className = 'tbtf-line';
			probe.style.cssText = 'position:absolute;left:0;top:0;visibility:hidden;min-height:0;width:' + box.clientWidth + 'px;';
			var chips = cur.tiles.map( function ( tile ) {
				var chip = document.createElement( 'span' );
				chip.className = 'tbtf-tile';
				chip.textContent = tile.text;
				probe.appendChild( chip );
				return chip;
			} );
			box.parentNode.appendChild( probe );
			chips.map( function ( chip ) { return { chip: chip, w: chip.offsetWidth }; } )
				.sort( function ( a, b ) { return b.w - a.w; } )
				.slice( cur.answer.length )
				.forEach( function ( c ) { c.chip.remove(); } );

			function rows() {
				var tops = [];
				Array.prototype.forEach.call( probe.children, function ( chip ) {
					if ( tops.indexOf( chip.offsetTop ) === -1 ) {
						tops.push( chip.offsetTop );
					}
				} );
				return tops.length;
			}
			var font = LINE_FONT;
			while ( rows() > 2 && font > LINE_FONT_MIN ) {
				font--;
				probe.style.setProperty( '--tbtf-line-font', font + 'px' );
			}
			if ( font < LINE_FONT ) {
				box.style.setProperty( '--tbtf-line-font', font + 'px' );
			}
			box.style.setProperty( '--tbtf-line-h', probe.offsetHeight + 'px' );
			probe.remove();
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

		/* Placed words in tap order. A tap on one sends it back to its own
		   spot in the field; the words after it close up. */
		function renderLine() {
			el.line.innerHTML = '';
			cur.placed.forEach( function ( id, k ) {
				var chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'tbtf-tile';
				chip.textContent = cur.tiles[ id ].text;
				chip.setAttribute( 'lang', 'en' );
				chip.setAttribute( 'aria-label', fmt( t.remove, cur.tiles[ id ].text ) );
				chip.addEventListener( 'click', function () { unplace( k ); } );
				el.line.appendChild( chip );
			} );
			cur.tiles.forEach( function ( tile ) {
				tile.el.classList.toggle( 'is-used', cur.placed.indexOf( tile.id ) !== -1 );
			} );
		}

		function place( id ) {
			if ( ! cur || cur.locked || ! cur.timer.running || cur.placed.indexOf( id ) !== -1 ) {
				return;
			}
			// A full line is being checked.
			if ( cur.placed.length >= cur.answer.length ) {
				return;
			}
			cur.placed.push( id );
			renderLine();
			if ( cur.placed.length === cur.answer.length ) {
				check();
			} else {
				// Each word as it is placed: the right word for its position or not.
				sfx( cur.tiles[ id ].text === cur.answer[ cur.placed.length - 1 ] ? 'good' : 'bad' );
			}
		}

		function unplace( k ) {
			if ( ! cur || cur.locked || ! cur.timer.running ) {
				return;
			}
			cur.placed.splice( k, 1 );
			renderLine();
		}

		/* Compared by text, so duplicate words in a phrase are fine. */
		function check() {
			var right = cur.placed.every( function ( id, i ) {
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
			el.line.hidden = true;
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
			el.line.classList.add( 'is-good' );
			chasers.forEach( function ( c ) { c.classList.add( 'is-stunned' ); } );
			el.gap.classList.add( 'is-gone' );
			hero.className = 'tbtf-critter tbtf-hero is-escape';
			later( function () { reveal( t.correct, 'is-good' ); }, 350 );
			sfx( 'win' );
			var item = cur.item;
			later( function () { playAudio( item ); }, speechDelay() );
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
				el.line.classList.add( 'is-bad' );
			}
			var item = cur.item;
			sfx( 'lose' );
			later( function () {
				reveal( reason === 'time' ? t.timeUp : t.notQuite, 'is-bad' );
			}, reason === 'wrong' ? 450 : 0 );
			later( function () { playAudio( item ); }, Math.max( reason === 'wrong' ? 450 : 0, speechDelay() + 150 ) );
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

			// The celebration: fireworks (unless motion is unwelcome) and the
			// cheer (unless sound effects are off).
			if ( ! reducedMotion() ) {
				fireworks( app );
			}
			sfx( 'cheer' );

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

		/* ---------- Freshness: reload when the set was saved since ---------- */

		/* A phone can keep this tab alive (or bring it back from the
		   back/forward cache) for days without asking the server again, so
		   the no-store headers alone never reach it. The page asks the public
		   stamp route whether its set has been saved since it loaded. Any
		   failure fails open: the game must never be blocked by the check. */
		var FRESH_TIMEOUT = 1500;
		var freshPending = null;
		function freshKey() {
			return 'tbtf-fresh-' + SET.slug;
		}
		function freshReload( stamp ) {
			try {
				if ( window.sessionStorage.getItem( freshKey() ) === stamp ) {
					// Already reloaded for this save and still got the old
					// page: something is caching it. Don't loop.
					if ( window.console && console.warn ) {
						console.warn( 'TBT Fill: the set has changed, but reloading still served the old page.' );
					}
					return false;
				}
				window.sessionStorage.setItem( freshKey(), stamp );
			} catch ( e ) {}
			var url;
			try {
				url = new URL( window.location.href );
				url.searchParams.set( 'tbtf_v', stamp );
				url = url.toString();
			} catch ( e ) {
				var href = window.location.href.replace( /#.*$/, '' );
				href = href.replace( /([?&])tbtf_v=[^&]*(&|$)/, function ( m, pre, post ) { return post ? pre : ''; } );
				url = href + ( href.indexOf( '?' ) === -1 ? '?' : '&' ) + 'tbtf_v=' + encodeURIComponent( stamp );
			}
			window.location.replace( url );
			return true;
		}
		/* Resolves true when the page is current (or the check couldn't run),
		   false when a reload has been started. */
		function checkFresh() {
			if ( freshPending ) {
				return freshPending;
			}
			if ( ! window.fetch || ! window.Promise || ! SET.stamp || ! cfg.stampUrl || ! SET.slug ) {
				return Promise.resolve( true );
			}
			// rest_url() carries ?rest_route= on sites without pretty permalinks.
			var url = cfg.stampUrl + encodeURIComponent( SET.slug ) + '/stamp' +
				( cfg.stampUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + '_=' + Date.now();
			freshPending = new Promise( function ( resolve ) {
				var settled = false;
				var finish = function ( ok ) {
					if ( ! settled ) {
						settled = true;
						freshPending = null;
						resolve( ok );
					}
				};
				// A late answer after the timeout is ignored: by then the
				// game may have started.
				setTimeout( function () { finish( true ); }, FRESH_TIMEOUT );
				window.fetch( url, { cache: 'no-store', credentials: 'same-origin' } )
					.then( function ( res ) {
						return res.ok ? res.json() : null;
					} )
					.then( function ( json ) {
						if ( settled ) {
							return;
						}
						if ( ! json || typeof json.stamp !== 'string' || json.stamp === SET.stamp ) {
							finish( true );
							return;
						}
						finish( ! freshReload( json.stamp ) );
					} )
					.catch( function () { finish( true ); } );
			} );
			return freshPending;
		}
		// Mid-game the check never runs: a returning phone stays paused, and
		// the new version loads at the next START or Play again.
		function checkFreshIdle() {
			if ( screenNow === 'start' || screenNow === 'end' ) {
				checkFresh();
			}
		}
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				checkFreshIdle();
			}
		} );
		window.addEventListener( 'pageshow', function ( e ) {
			if ( e.persisted ) {
				checkFreshIdle();
			}
		} );

		// Taps while the check is in flight are ignored.
		var starting = false;
		function startWhenFresh( waitFonts ) {
			starting = true;
			checkFresh().then( function ( ok ) {
				if ( ! ok ) {
					return; // Reloading; leave starting set so nothing else runs.
				}
				var ready = waitFonts && document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
				return ready.then( function () {
					starting = false;
					startGame();
				} );
			} ).catch( function () {
				starting = false;
			} );
		}

		q( 'start' ).addEventListener( 'click', function () {
			if ( starting ) {
				return;
			}
			// Synchronous, inside the tap: iOS only unlocks audio here.
			unlockAudio();
			if ( Sfx.unlock() ) {
				Sfx.loadCheer( SET.cheer );
			}
			startWhenFresh( true );
		} );
		q( 'again' ).addEventListener( 'click', function () {
			if ( starting ) {
				return;
			}
			Sfx.unlock();
			startWhenFresh( false );
		} );
		q( 'settings' ).addEventListener( 'click', function () { show( 'start' ); } );

		var resizeTimer = 0;
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				if ( cur && ! cur.locked ) {
					// A rotation changes the line's width: re-reserve its
					// height before the field is laid out again.
					sizeLine();
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
