/**
 * TBT Fill — teacher page.
 *
 * Two independent parts, each starting only if its markup is on the page:
 *   - the library: copy link, QR code, delete with an in-page confirmation
 *   - the builder: phrase rows, the paste box, the live tile preview,
 *     reordering, save / publish / share
 *
 * The tile preview comes from the server (TBTF_Phrase::tokenize via the
 * /manage/tiles route): this file never splits a phrase itself, so what the
 * teacher sees is exactly what students get.
 *
 * Vanilla JS, no dependencies beyond the self-hosted QR library.
 */
( function () {
	'use strict';

	var cfg = window.tbtfTeacher || {};
	var t = cfg.i18n || {};

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** sprintf-lite: %d / %s in order, or %1$d / %2$s by position. */
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( _, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var val = attrs[ key ];
			if ( val === null || val === undefined || val === false ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = val;
			} else if ( key === 'className' ) {
				node.className = val;
			} else if ( key.indexOf( 'on' ) === 0 && typeof val === 'function' ) {
				node.addEventListener( key.slice( 2 ), val );
			} else {
				node.setAttribute( key, val === true ? '' : val );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function api( method, path, body ) {
		return fetch( cfg.restBase + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				var msg = ( data && data.message ) || t.networkError;
				if ( data && data.code === 'rest_cookie_invalid_nonce' ) {
					msg = t.staleNonce;
				}
				var err = new Error( msg );
				err.code = data && data.code;
				throw err;
			} );
		}, function () {
			throw new Error( t.networkError );
		} );
	}

	function copyText( text, button ) {
		var label = button.textContent;
		function done() {
			button.textContent = t.copied;
			setTimeout( function () { button.textContent = label; }, 1600 );
		}
		function fallback() {
			var area = el( 'textarea', { readonly: true, style: 'position:fixed;opacity:0' } );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {}
			document.body.removeChild( area );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function drawQr( target, url ) {
		target.innerHTML = '';
		if ( ! url || typeof window.QRCode === 'undefined' ) {
			return;
		}
		/* eslint-disable no-new */
		new window.QRCode( target, {
			text: url,
			width: 176,
			height: 176,
			colorDark: '#0E2A4F',
			colorLight: '#FFFFFF',
			correctLevel: window.QRCode.CorrectLevel.M
		} );
	}

	function showError( box, message ) {
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.hidden = ! message;
		if ( message ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Library
	 * ------------------------------------------------------------------ */

	function initLibrary( root ) {
		var errorBox = root.querySelector( '[data-role="error"]' );
		var modal = document.querySelector( '[data-role="qr-modal"]' );

		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-role]' );
			if ( ! btn ) {
				return;
			}
			var role = btn.getAttribute( 'data-role' );
			var row = btn.closest( '.tbtf-set' );
			var confirmBox = row && row.querySelector( '[data-role="confirm"]' );
			var actions = row && row.querySelector( '[data-role="actions"]' );

			if ( role === 'copy' ) {
				copyText( btn.getAttribute( 'data-url' ), btn );
			} else if ( role === 'qr' && modal ) {
				modal.querySelector( '[data-role="qr-title"]' ).textContent = btn.getAttribute( 'data-title' );
				drawQr( modal.querySelector( '[data-role="qr-target"]' ), btn.getAttribute( 'data-url' ) );
				modal.hidden = false;
				modal.querySelector( '[data-role="qr-close"]' ).focus();
			} else if ( role === 'delete' && confirmBox ) {
				actions.hidden = true;
				confirmBox.hidden = false;
				confirmBox.querySelector( '[data-role="confirm-no"]' ).focus();
			} else if ( role === 'confirm-no' && confirmBox ) {
				confirmBox.hidden = true;
				actions.hidden = false;
			} else if ( role === 'confirm-yes' && row ) {
				btn.disabled = true;
				api( 'DELETE', 'sets/' + row.getAttribute( 'data-id' ) ).then( function () {
					var group = row.closest( '.tbtf-group' );
					row.remove();
					if ( group && ! group.querySelector( '.tbtf-set' ) ) {
						group.remove();
					}
				} ).catch( function ( err ) {
					btn.disabled = false;
					showError( errorBox, err.message );
				} );
			}
		} );

		if ( modal ) {
			var close = function () { modal.hidden = true; };
			modal.addEventListener( 'click', function ( e ) {
				if ( e.target === modal || e.target.closest( '[data-role="qr-close"]' ) ) {
					close();
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && ! modal.hidden ) {
					close();
				}
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Builder
	 * ------------------------------------------------------------------ */

	function initBuilder( root ) {
		var $ = function ( role ) { return root.querySelector( '[data-role="' + role + '"]' ); };
		var initial = null;
		try {
			initial = JSON.parse( $( 'set-data' ).textContent );
		} catch ( e ) {}

		var errorBox = $( 'error' );
		var list = $( 'items' );
		var uid = 0;

		var state = {
			id: initial ? initial.id : 0,
			status: initial ? initial.status : '',
			url: initial ? initial.url : '',
			items: []
		};
		var dirty = false;
		var busy = false;

		/* ---------- Items ---------- */

		function newItem( data ) {
			data = data || {};
			return {
				key: ++uid,
				english: data.english || '',
				polish: data.polish || '',
				distractors: data.distractors || '',
				tiles: data.tiles || [],
				check: data.check || ( ( data.tiles || [] ).length ? 'ok' : 'empty' ),
				hand: data.hand || [],
				audio: data.audio || ( cfg.audioEnabled ? 'pending' : 'off' ),
				savedEnglish: data.audio ? data.english : null,
				savedAudio: data.audio || null,
				dom: null
			};
		}

		function markDirty() {
			dirty = true;
		}

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = t.confirmLeave;
				return t.confirmLeave;
			}
		} );

		/* ---------- Live tile preview (server-side tokenize) ---------- */

		var pending = {};
		var previewTimer = 0;

		function queuePreview( item ) {
			pending[ item.key ] = item;
			clearTimeout( previewTimer );
			previewTimer = setTimeout( flushPreview, 280 );
		}

		function flushPreview() {
			var batch = Object.keys( pending ).map( function ( k ) { return pending[ k ]; } );
			pending = {};
			if ( ! batch.length ) {
				return;
			}
			var sent = batch.map( function ( item ) {
				return { english: item.english, distractors: item.distractors };
			} );
			api( 'POST', 'tiles', { items: sent } ).then( function ( res ) {
				batch.forEach( function ( item, i ) {
					// A newer edit is already queued: its own answer will follow.
					if ( ! res[ i ] || item.english !== sent[ i ].english || item.distractors !== sent[ i ].distractors ) {
						return;
					}
					item.tiles = res[ i ].tiles || [];
					item.check = res[ i ].check || 'empty';
					item.hand = res[ i ].distractors || [];
					renderPreview( item );
				} );
			} ).catch( function () {} );
		}

		/* ---------- Rows ---------- */

		function audioLabel( item ) {
			return {
				ready: t.audioReady,
				pending: t.audioPending,
				failed: t.audioFailed,
				off: t.audioOff
			}[ item.audio ] || '';
		}

		function buildRow( item ) {
			var d = {};
			d.n = el( 'span', { className: 'tbtf-item-n' } );
			d.handle = el( 'span', { className: 'tbtf-item-handle', title: t.dragHandle, 'aria-hidden': 'true', draggable: 'true' }, [ '⋮⋮' ] );
			d.up = el( 'button', { type: 'button', className: 'tbtf-item-icon', 'aria-label': t.moveUp, title: t.moveUp, onclick: function () { move( item, -1 ); } }, [ '↑' ] );
			d.down = el( 'button', { type: 'button', className: 'tbtf-item-icon', 'aria-label': t.moveDown, title: t.moveDown, onclick: function () { move( item, 1 ); } }, [ '↓' ] );
			d.x = el( 'button', {
				type: 'button',
				className: 'tbtf-item-icon tbtf-item-x',
				'aria-label': t.removePhrase,
				title: t.removePhrase,
				onclick: function () { removeItem( item ); }
			}, [ '×' ] );
			d.audio = el( 'span', { className: 'tbtf-audio' } );

			var id = 'tbtf-i' + item.key;
			d.en = el( 'input', { type: 'text', className: 'tbtf-input tbtf-input--phrase', id: id + '-en', lang: 'en', maxlength: '200', placeholder: t.enPlaceholder, autocomplete: 'off' } );
			d.pl = el( 'input', { type: 'text', className: 'tbtf-input', id: id + '-pl', lang: 'pl', maxlength: '200', placeholder: t.plPlaceholder, autocomplete: 'off' } );
			d.d = el( 'input', { type: 'text', className: 'tbtf-input', id: id + '-d', lang: 'en', maxlength: '200', placeholder: t.dPlaceholder, autocomplete: 'off' } );
			d.en.value = item.english;
			d.pl.value = item.polish;
			d.d.value = item.distractors;

			d.en.addEventListener( 'input', function () {
				item.english = d.en.value;
				if ( item.savedEnglish !== null ) {
					item.audio = item.english.trim() === item.savedEnglish ? item.savedAudio : ( cfg.audioEnabled ? 'pending' : 'off' );
					renderAudio( item );
				}
				markDirty();
				queuePreview( item );
			} );
			d.pl.addEventListener( 'input', function () {
				item.polish = d.pl.value;
				markDirty();
			} );
			d.d.addEventListener( 'input', function () {
				item.distractors = d.d.value;
				markDirty();
				queuePreview( item );
			} );

			d.tiles = el( 'div', { className: 'tbtf-tiles', 'aria-live': 'polite' } );
			d.check = el( 'p', { className: 'tbtf-tiles-note' } );

			var li = el( 'li', { className: 'tbtf-item' }, [
				el( 'div', { className: 'tbtf-item-head' }, [ d.handle, d.n, d.audio, el( 'span', { className: 'tbtf-item-spacer' } ), d.up, d.down, d.x ] ),
				el( 'div', { className: 'tbtf-item-grid' }, [
					el( 'div', { className: 'tbtf-item-field' }, [ el( 'label', { className: 'tbtf-mini-label', for: id + '-en', text: t.english } ), d.en ] ),
					el( 'div', { className: 'tbtf-item-field' }, [ el( 'label', { className: 'tbtf-mini-label', for: id + '-pl', text: t.polish } ), d.pl ] ),
					el( 'div', { className: 'tbtf-item-field' }, [ el( 'label', { className: 'tbtf-mini-label', for: id + '-d', text: t.distractors } ), d.d ] )
				] ),
				el( 'div', { className: 'tbtf-item-preview' }, [ el( 'span', { className: 'tbtf-mini-label', text: t.tilesLabel } ), d.tiles, d.check ] )
			] );

			setupDrag( li, d.handle, item );
			item.dom = d;
			item.li = li;
			renderPreview( item );
			renderAudio( item );
			return li;
		}

		function renderAudio( item ) {
			var d = item.dom;
			d.audio.textContent = audioLabel( item );
			d.audio.className = 'tbtf-audio tbtf-audio--' + item.audio;
		}

		function renderPreview( item ) {
			var d = item.dom;
			d.tiles.innerHTML = '';
			if ( ! item.tiles.length ) {
				d.tiles.appendChild( el( 'span', { className: 'tbtf-tiles-empty', text: t.tilesEmpty } ) );
			}
			item.tiles.forEach( function ( word ) {
				d.tiles.appendChild( el( 'span', { className: 'tbtf-tile-chip', lang: 'en', text: word } ) );
			} );
			var mode = $( 'mode' ).value;
			var count = parseInt( $( 'count' ).value, 10 ) || 0;
			if ( item.tiles.length && count > 0 ) {
				if ( mode === 'hand' && item.hand.length ) {
					item.hand.slice( 0, count ).forEach( function ( word ) {
						d.tiles.appendChild( el( 'span', { className: 'tbtf-tile-chip tbtf-tile-chip--extra', lang: 'en', text: word } ) );
					} );
				} else {
					d.tiles.appendChild( el( 'span', { className: 'tbtf-tiles-empty', text: '+ ' + count + ' · ' + t.fromBranch } ) );
				}
			}
			d.check.className = 'tbtf-tiles-note';
			d.check.textContent = '';
			if ( item.check === 'warn' ) {
				d.check.textContent = fmt( t.tilesWarn, item.tiles.length );
				d.check.className += ' tbtf-tiles-note--warn';
			} else if ( item.check === 'long' ) {
				d.check.textContent = fmt( t.tilesLong, cfg.maxTiles );
				d.check.className += ' tbtf-tiles-note--error';
			}
			d.check.hidden = ! d.check.textContent;
		}

		function renumber() {
			state.items.forEach( function ( item, i ) {
				item.dom.n.textContent = fmt( t.phraseN, i + 1 );
				item.dom.up.disabled = i === 0;
				item.dom.down.disabled = i === state.items.length - 1;
			} );
		}

		function renderAll() {
			list.innerHTML = '';
			state.items.forEach( function ( item ) {
				list.appendChild( item.li || buildRow( item ) );
			} );
			renumber();
		}

		function addItem( data, focus ) {
			var item = newItem( data );
			state.items.push( item );
			list.appendChild( buildRow( item ) );
			renumber();
			if ( focus ) {
				item.dom.en.focus();
			}
			return item;
		}

		function isBlank( item ) {
			return ! item.english.trim() && ! item.polish.trim() && ! item.distractors.trim();
		}

		function removeItem( item ) {
			if ( ! isBlank( item ) && ! window.confirm( t.confirmRemove ) ) {
				return;
			}
			state.items = state.items.filter( function ( it ) { return it !== item; } );
			item.li.remove();
			if ( ! state.items.length ) {
				addItem( null, false );
			}
			renumber();
			markDirty();
		}

		function move( item, delta ) {
			var i = state.items.indexOf( item );
			var j = i + delta;
			if ( j < 0 || j >= state.items.length ) {
				return;
			}
			state.items.splice( i, 1 );
			state.items.splice( j, 0, item );
			renderAll();
			( delta < 0 ? item.dom.up : item.dom.down ).focus();
			markDirty();
		}

		/* Drag by the handle (desktop). Touch screens use the arrows. */
		var dragging = null;
		function setupDrag( li, handle, item ) {
			handle.addEventListener( 'dragstart', function ( e ) {
				dragging = item;
				li.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
				try {
					e.dataTransfer.setData( 'text/plain', '' );
					e.dataTransfer.setDragImage( li, 20, 20 );
				} catch ( err ) {}
			} );
			handle.addEventListener( 'dragend', function () {
				li.classList.remove( 'is-dragging' );
				dragging = null;
			} );
			li.addEventListener( 'dragover', function ( e ) {
				if ( ! dragging || dragging === item ) {
					return;
				}
				e.preventDefault();
				var rect = li.getBoundingClientRect();
				var after = e.clientY > rect.top + rect.height / 2;
				var from = state.items.indexOf( dragging );
				state.items.splice( from, 1 );
				var to = state.items.indexOf( item ) + ( after ? 1 : 0 );
				state.items.splice( to, 0, dragging );
				list.insertBefore( dragging.li, after ? li.nextSibling : li );
				renumber();
				markDirty();
			} );
		}

		/* ---------- Paste box ---------- */

		function parsePaste( text ) {
			return String( text || '' ).split( /\r?\n/ ).map( function ( line ) {
				var parts = line.split( '|' ).map( function ( p ) { return p.trim(); } );
				return {
					english: parts[ 0 ] || '',
					polish: parts[ 1 ] || '',
					distractors: parts.slice( 2 ).join( ', ' ).replace( /^[,\s]+|[,\s]+$/g, '' )
				};
			} ).filter( function ( row ) {
				return row.english || row.polish;
			} );
		}

		$( 'paste-add' ).addEventListener( 'click', function () {
			var rows = parsePaste( $( 'paste' ).value );
			var msg = $( 'paste-msg' );
			if ( ! rows.length ) {
				msg.textContent = t.pasteNone;
				return;
			}
			// Replace the empty starter rows rather than leaving gaps above.
			state.items.filter( isBlank ).forEach( function ( item ) {
				item.li.remove();
			} );
			state.items = state.items.filter( function ( item ) { return ! isBlank( item ); } );
			rows.forEach( function ( row ) {
				queuePreview( addItem( row, false ) );
			} );
			renumber();
			markDirty();
			$( 'paste' ).value = '';
			msg.textContent = fmt( t.pasteAdded, rows.length );
		} );

		$( 'add' ).addEventListener( 'click', function () {
			addItem( null, true );
			markDirty();
		} );

		/* ---------- Details ---------- */

		[ 'title', 'label' ].forEach( function ( role ) {
			$( role ).addEventListener( 'input', markDirty );
		} );
		$( 'pace' ).addEventListener( 'change', markDirty );
		[ 'mode', 'count' ].forEach( function ( role ) {
			$( role ).addEventListener( 'change', function () {
				markDirty();
				state.items.forEach( renderPreview );
			} );
		} );

		var classInput = $( 'class' );
		var classId = $( 'class-id' );
		var lessonWrap = $( 'lesson-wrap' );
		var lessonSel = $( 'lesson' );

		function resolveClass() {
			var name = classInput.value.trim();
			var match = null;
			Array.prototype.forEach.call( document.querySelectorAll( '#tbtf-class-list option' ), function ( opt ) {
				if ( ! match && opt.value === name ) {
					match = opt;
				}
			} );
			$( 'class-hint' ).hidden = ! name || !! match;
			var id = match ? match.getAttribute( 'data-id' ) : '';
			if ( id === classId.value ) {
				return;
			}
			classId.value = id;
			lessonWrap.hidden = ! id;
			lessonSel.innerHTML = '';
			if ( ! id ) {
				return;
			}
			lessonSel.disabled = true;
			api( 'GET', 'classes/' + id + '/lessons' ).then( function ( lessons ) {
				lessonSel.innerHTML = '';
				( lessons || [] ).forEach( function ( lesson ) {
					lessonSel.appendChild( el( 'option', { value: String( lesson.id ), text: lesson.title } ) );
				} );
				lessonSel.appendChild( el( 'option', { value: '0', text: t.noLesson } ) );
				lessonSel.disabled = false;
			} ).catch( function ( err ) {
				lessonSel.disabled = false;
				showError( errorBox, err.message );
			} );
		}

		if ( classInput ) {
			classInput.addEventListener( 'input', function () {
				markDirty();
				resolveClass();
			} );
			classInput.addEventListener( 'change', resolveClass );
			lessonSel.addEventListener( 'change', markDirty );
		}

		/* ---------- Save / publish ---------- */

		function validate() {
			if ( ! $( 'title' ).value.trim() ) {
				$( 'title' ).focus();
				return t.needTitle;
			}
			var filled = state.items.filter( function ( item ) { return ! isBlank( item ); } );
			if ( ! filled.length ) {
				return t.noItems;
			}
			if ( filled.length > cfg.maxItems ) {
				return fmt( t.tooManyItems, cfg.maxItems );
			}
			for ( var i = 0; i < state.items.length; i++ ) {
				var item = state.items[ i ];
				if ( isBlank( item ) ) {
					continue;
				}
				if ( ! item.english.trim() ) {
					item.dom.en.focus();
					return fmt( t.missingEn, i + 1 );
				}
				if ( ! item.polish.trim() ) {
					item.dom.pl.focus();
					return fmt( t.missingPl, i + 1 );
				}
				if ( item.check === 'long' ) {
					item.dom.en.focus();
					return fmt( t.tooLong, i + 1 );
				}
			}
			return '';
		}

		function payload( status ) {
			return {
				id: state.id || 0,
				title: $( 'title' ).value,
				label: $( 'label' ).value,
				status: status,
				pace_default: $( 'pace' ).value,
				distractor_mode: $( 'mode' ).value,
				distractor_count: parseInt( $( 'count' ).value, 10 ) || 0,
				class_id: classId ? parseInt( classId.value, 10 ) || 0 : 0,
				lesson_id: classId && classId.value && lessonSel ? parseInt( lessonSel.value, 10 ) || 0 : 0,
				items: state.items.filter( function ( item ) { return ! isBlank( item ); } ).map( function ( item ) {
					return { english: item.english, polish: item.polish, distractors: item.distractors };
				} )
			};
		}

		function setBusy( on ) {
			busy = on;
			[ 'save', 'publish', 'unpublish' ].forEach( function ( role ) {
				$( role ).disabled = on;
			} );
		}

		function save( status ) {
			if ( busy ) {
				return;
			}
			showError( errorBox, '' );
			var problem = validate();
			if ( problem ) {
				showError( errorBox, problem );
				return;
			}
			var publishing = status === 'published';
			setBusy( true );
			$( 'saved-msg' ).textContent = publishing ? t.publishing : t.saving;

			api( 'POST', 'sets', payload( status ) ).then( function ( res ) {
				var wasLive = state.status === 'published';
				state.id = res.id;
				state.status = res.status;
				state.url = res.url;

				// The server's list is the saved one: rebuild the rows from it so
				// tiles and audio status are exactly what was stored.
				state.items = ( res.items || [] ).map( newItem );
				if ( ! state.items.length ) {
					state.items.push( newItem() );
				}
				renderAll();
				dirty = false;

				if ( window.history && window.history.replaceState && cfg.editParam ) {
					var u = new URL( window.location.href );
					u.searchParams.delete( 'tbtf_new' );
					u.searchParams.set( cfg.editParam, String( res.id ) );
					window.history.replaceState( null, '', u.toString() );
				}

				var msg = res.status === 'published' ? ( wasLive ? t.savedLive : t.published ) : t.savedDraft;
				if ( ! res.url ) {
					msg = t.noPlayerPage;
				}
				if ( res.status === 'published' && res.audioFailed ) {
					msg += ' ' + fmt( t.audioMissing, res.audioFailed );
				}
				$( 'saved-msg' ).textContent = msg;
				renderState();
			} ).catch( function ( err ) {
				$( 'saved-msg' ).textContent = '';
				showError( errorBox, err.message );
			} ).then( function () {
				setBusy( false );
			} );
		}

		function renderState() {
			var live = state.status === 'published';
			var line = $( 'state-line' );
			line.textContent = ! state.id ? t.stateNew : ( live ? t.stateLive : t.stateDraft );
			$( 'save' ).textContent = live ? t.saveChanges : t.saveDraft;
			$( 'publish' ).hidden = live;
			$( 'unpublish' ).hidden = ! live;

			var preview = $( 'preview' );
			preview.hidden = ! state.id || ! state.url || live;
			if ( state.url ) {
				preview.href = state.url;
			}

			var share = $( 'share' );
			share.hidden = ! live || ! state.url;
			$( 'another-row' ).hidden = share.hidden;
			if ( live && state.url ) {
				$( 'share-url' ).value = state.url;
				$( 'share-open' ).href = state.url;
				drawQr( $( 'share-qr' ), state.url );
			}
		}

		$( 'save' ).addEventListener( 'click', function () {
			save( state.status === 'published' ? 'published' : 'draft' );
		} );
		$( 'publish' ).addEventListener( 'click', function () {
			save( 'published' );
		} );
		$( 'unpublish' ).addEventListener( 'click', function () {
			if ( window.confirm( t.unpublishConfirm ) ) {
				save( 'draft' );
			}
		} );
		$( 'share-copy' ).addEventListener( 'click', function ( e ) {
			copyText( $( 'share-url' ).value, e.currentTarget );
		} );
		$( 'share-url' ).addEventListener( 'focus', function ( e ) {
			e.target.select();
		} );

		/* ---------- Start ---------- */

		if ( initial && initial.items && initial.items.length ) {
			initial.items.forEach( function ( data ) {
				state.items.push( newItem( data ) );
			} );
		} else {
			state.items.push( newItem() );
		}
		renderAll();
		renderState();
	}

	function boot() {
		var library = document.querySelector( '.tbtf-teacher [data-role="library"]' );
		if ( library ) {
			initLibrary( library );
		}
		var builder = document.querySelector( '.tbtf-teacher [data-role="builder"]' );
		if ( builder ) {
			initBuilder( builder );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
