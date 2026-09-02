/**
 * Trust Signals for HivePress - live preview.
 *
 * Draws the sidebar block in a panel to the right of the settings, with the style, signals,
 * icons and colours on the page, following every change as it is made and storing nothing until
 * Save. The markup is the one hpts_render_block() emits and the rules are hpts_css_rules() with
 * this page's values in place of the saved ones, so what is drawn is the block itself rather than
 * an imitation of it; the figures are examples, and the description under the panel says so.
 *
 * Icons are emitted as `<i class="fa-solid fa-NAME">` and drawn by the shared icon library's
 * admin script, which watches the document and swaps them for inline SVG.
 *
 * Everything from "folding panels" down is the preview chrome shared with the other extensions
 * that have a preview (Action Bar, Holiday Mode); only the prefix differs. Fix it in one and
 * sweep the others.
 */

/* global hptsPreviewData */

( function () {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	var PREFIX = 'hp_trust_signals_',
		STORE = 'hptsPreviewPanels',
		WIDTH_STORE = 'hptsPreviewWidth';

	window.jQuery( function ( $ ) {
		var root = document.querySelector( '.hpts-preview' );

		if ( ! root ) {
			return;
		}

		var data = window.hptsPreviewData || {},
			signals = data.signals || [],
			icons = data.icons || {},
			colours = data.colours || {},
			strokes = data.strokes || {},
			labels = data.labels || {},
			stage = root.querySelector( '[data-hpts-part="block"]' );

		function input( name ) {
			return document.querySelector( '[name="' + PREFIX + name + '"]' );
		}

		function value( name ) {
			var field = input( name );

			if ( ! field ) {
				return '';
			}

			if ( 'checkbox' === field.type ) {
				return field.checked ? '1' : '';
			}

			return ( field.value || '' ).trim();
		}

		// A 6-digit hex, or '' for anything else; 3-digit shorthand is expanded the way the
		// settings screen expands it before saving.
		function hex( raw ) {
			raw = ( raw || '' ).trim();

			if ( /^#[0-9a-f]{6}$/i.test( raw ) ) {
				return raw.toLowerCase();
			}

			var short = /^#([0-9a-f]{3})$/i.exec( raw );

			return short ? ( '#' + short[ 1 ].replace( /./g, '$&$&' ) ).toLowerCase() : '';
		}

		// The bare icon name from a picker value, which may still carry a family prefix from
		// before the icon library.
		function iconName( raw ) {
			var name = '';

			( raw || '' ).toLowerCase().split( /\s+/ ).forEach( function ( token ) {
				if ( 0 === token.indexOf( 'fa-' ) ) {
					name = token.slice( 3 );
				} else if ( ! name && /^[a-z0-9-]+$/.test( token ) && -1 === [ 'fas', 'fab', 'far' ].indexOf( token ) ) {
					name = token;
				}
			} );

			return /^[a-z0-9-]+$/.test( name ) ? name : '';
		}

		// Both halves, as the front end emits them: -webkit-text-stroke for a font glyph, stroke
		// and stroke-width for the inline SVG the icon library draws.
		function strokeCss( width ) {
			return width ? '-webkit-text-stroke:' + width + ' currentColor;stroke:currentColor;stroke-width:' + width + ';paint-order:stroke fill;' : '';
		}

		// Emitted as the class the icon library's admin script watches for; it swaps the element's
		// contents for inline SVG and reads the icon's real family from its own index.
		function icon( name, className, style ) {
			var element = document.createElement( 'i' );

			element.className = className + ' fa-solid fa-' + name;
			element.setAttribute( 'aria-hidden', 'true' );

			if ( style ) {
				element.style.cssText = style;
			}

			return element;
		}

		function enabledKeys() {
			return Array.prototype.map.call( document.querySelectorAll( '[name="' + PREFIX + 'items[]"]:checked' ), function ( box ) {
				return box.value;
			} );
		}

		function clear( element ) {
			while ( element.firstChild ) {
				element.removeChild( element.firstChild );
			}
		}

		// hpts_render_block(), with the theme's widget classes left off: the theme is not here to
		// style them, and .hpts-block--card carries the same card values as its fallback.
		function paint() {
			if ( ! stage ) {
				return;
			}

			var style = 'pills' === value( 'style' ) ? 'pills' : 'rows',
				layout = 'inline' === value( 'pill_layout' ) ? 'inline' : 'stacked',
				card = '' !== value( 'card' ),
				showIcons = '' !== value( 'icons' ),
				title = value( 'title' ),
				size = parseInt( value( 'icon_size' ), 10 ),
				stroke = strokes[ value( 'icon_weight' ) ] || '',
				enabled = enabledKeys(),
				block = document.createElement( 'div' ),
				list = document.createElement( 'ul' );

			if ( isNaN( size ) || size < 50 || size > 400 ) {
				size = 100;
			}

			block.className = 'hpts-block hpts-block--' + style + ( 'pills' === style ? ' hpts-pills--' + layout : '' ) + ( card ? ' hpts-block--card' : '' );
			block.style.setProperty( '--hpts-icon-color', hex( value( 'color_icon' ) ) || colours.icon );
			block.style.setProperty( '--hpts-pill-bg', hex( value( 'color_pill_bg' ) ) || colours.pillBg );
			block.style.setProperty( '--hpts-pill-text', hex( value( 'color_pill_text' ) ) || colours.pillText );
			block.style.setProperty( '--hpts-icon-size', size + '%' );

			if ( title ) {
				var heading = document.createElement( 'h5' );

				heading.className = 'hpts-block__title';
				heading.appendChild( document.createTextNode( title ) );
				block.appendChild( heading );
			}

			list.className = 'hpts-list';

			signals.forEach( function ( signal ) {
				if ( -1 === enabled.indexOf( signal.key ) ) {
					return;
				}

				var item = document.createElement( 'li' ),
					glyph = null;

				item.className = 'hpts-item hpts-item--' + signal.key;

				if ( showIcons ) {
					glyph = icon( iconName( value( 'icon_' + signal.key ) ) || icons[ signal.key ] || 'circle', 'hpts-icon', strokeCss( stroke ) );
				}

				if ( 'pills' === style ) {
					var text = document.createElement( 'span' );

					text.className = 'hpts-item__text';
					text.appendChild( document.createTextNode( signal.pill ) );

					if ( glyph ) {
						item.appendChild( glyph );
					}

					item.appendChild( text );
				} else {
					var label = document.createElement( 'span' ),
						labelText = document.createElement( 'span' ),
						amount = document.createElement( 'span' );

					label.className = 'hpts-item__label';
					labelText.className = 'hpts-item__label-text';
					labelText.appendChild( document.createTextNode( signal.label ) );

					if ( glyph ) {
						label.appendChild( glyph );
					}

					label.appendChild( labelText );

					amount.className = 'hpts-item__value';
					amount.appendChild( document.createTextNode( signal.value ) );

					item.appendChild( label );
					item.appendChild( amount );
				}

				list.appendChild( item );
			} );

			clear( stage );

			if ( ! list.childNodes.length ) {
				var note = document.createElement( 'p' );

				note.className = 'hpts-preview__note';
				note.appendChild( document.createTextNode( labels.none || '' ) );
				stage.appendChild( note );

				return;
			}

			block.appendChild( list );
			stage.appendChild( block );
		}

		/* ---- folding panels -------------------------------------------------- */

		function readStore() {
			try {
				return JSON.parse( window.localStorage.getItem( STORE ) ) || {};
			} catch ( error ) {
				return {};
			}
		}

		function writeStore( store ) {
			try {
				window.localStorage.setItem( STORE, JSON.stringify( store ) );
			} catch ( error ) {
				// Storage blocked; the panels still fold, they just forget on the next load.
			}
		}

		function setOpen( panel, open, remember ) {
			var header = panel.querySelector( '.hpts-preview__header' ),
				body = panel.querySelector( '.hpts-preview__body' ),
				chevron = header ? header.querySelector( '.dashicons' ) : null;

			panel.classList.toggle( 'hpts-preview__panel--collapsed', ! open );

			if ( header ) {
				header.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}

			if ( body ) {
				body.hidden = ! open;
			}

			if ( chevron ) {
				chevron.className = 'dashicons ' + ( open ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' );
			}

			if ( remember ) {
				var store = readStore();

				store[ panel.getAttribute( 'data-panel' ) ] = open ? 1 : 0;
				writeStore( store );
			}
		}

		var remembered = readStore();

		Array.prototype.forEach.call( root.querySelectorAll( '.hpts-preview__panel' ), function ( panel ) {
			var key = panel.getAttribute( 'data-panel' ),
				header = panel.querySelector( '.hpts-preview__header' );

			setOpen( panel, 'undefined' === typeof remembered[ key ] ? true : !! remembered[ key ], false );

			if ( header ) {
				header.addEventListener( 'click', function () {
					setOpen( panel, panel.classList.contains( 'hpts-preview__panel--collapsed' ), true );
				} );
			}
		} );

		/* ---- follow the form ------------------------------------------------- */

		var repaintTimer = null;

		function repaint() {
			window.clearTimeout( repaintTimer );
			repaintTimer = window.setTimeout( paint, 50 );
		}

		// jQuery-delegated on purpose: select2 and Iris announce their changes with jQuery-triggered
		// events, which a native listener never hears. Iris fires "irischange" on the input when a
		// swatch or the palette is used, and no DOM event at all, so it is listened for by name.
		$( document ).on( 'input change irischange', '[name^="' + PREFIX + '"]', repaint );
		$( document ).on( 'click', '.iris-palette, .wp-picker-clear, .wp-picker-default', repaint );

		// Links inside the preview are illustrations; following one would scroll the settings away.
		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( 'a' ) ) {
				event.preventDefault();
			}
		} );

		paint();

		/* ---- resizable panel ------------------------------------------------ */

		var WIDTH_DEFAULT = 320,
			WIDTH_MIN = 280,
			resizer = root.querySelector( '.hpts-preview__resizer' ),
			form = root.closest( 'form' );

		function maxWidth() {
			// Leave the settings column at least 480px; below that the fields wrap badly.
			return Math.max( WIDTH_MIN, Math.floor( ( form ? form.getBoundingClientRect().width : window.innerWidth ) - 480 ) );
		}

		function applyWidth( width, remember ) {
			width = Math.round( Math.min( maxWidth(), Math.max( WIDTH_MIN, width ) ) );

			if ( form ) {
				form.style.setProperty( '--hpts-preview-width', width + 'px' );
			}

			if ( resizer ) {
				resizer.setAttribute( 'aria-valuenow', String( width ) );
				resizer.setAttribute( 'aria-valuemin', String( WIDTH_MIN ) );
				resizer.setAttribute( 'aria-valuemax', String( maxWidth() ) );
			}

			if ( remember ) {
				try {
					window.localStorage.setItem( WIDTH_STORE, String( width ) );
				} catch ( error ) {
					// Storage blocked; the width holds for this page only.
				}
			}

			return width;
		}

		function currentWidth() {
			var stored = 0;

			try {
				stored = parseInt( window.localStorage.getItem( WIDTH_STORE ), 10 );
			} catch ( error ) {
				stored = 0;
			}

			return stored > 0 ? stored : WIDTH_DEFAULT;
		}

		if ( resizer && form ) {
			applyWidth( currentWidth(), false );

			var dragging = null;

			resizer.addEventListener( 'pointerdown', function ( event ) {
				if ( 0 !== event.button ) {
					return;
				}

				dragging = { x: event.clientX, width: parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT };
				resizer.setPointerCapture( event.pointerId );
				root.classList.add( 'hpts-preview--resizing' );
				event.preventDefault();
			} );

			resizer.addEventListener( 'pointermove', function ( event ) {
				if ( ! dragging ) {
					return;
				}

				// The handle is on the LEFT edge, so moving the pointer left makes the panel wider.
				applyWidth( dragging.width + ( dragging.x - event.clientX ), false );
			} );

			function endDrag( event ) {
				if ( ! dragging ) {
					return;
				}

				dragging = null;
				root.classList.remove( 'hpts-preview--resizing' );

				if ( event.pointerId !== undefined && resizer.hasPointerCapture( event.pointerId ) ) {
					resizer.releasePointerCapture( event.pointerId );
				}

				applyWidth( parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT, true );
			}

			resizer.addEventListener( 'pointerup', endDrag );
			resizer.addEventListener( 'pointercancel', endDrag );

			resizer.addEventListener( 'dblclick', function () {
				applyWidth( WIDTH_DEFAULT, true );
			} );

			resizer.addEventListener( 'keydown', function ( event ) {
				var step = event.shiftKey ? 80 : 20,
					width = parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT;

				if ( 'ArrowLeft' === event.key ) {
					applyWidth( width + step, true );
				} else if ( 'ArrowRight' === event.key ) {
					applyWidth( width - step, true );
				} else if ( 'Home' === event.key ) {
					applyWidth( WIDTH_DEFAULT, true );
				} else {
					return;
				}

				event.preventDefault();
			} );

			window.addEventListener( 'resize', function () {
				applyWidth( parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT, false );
			} );
		}
	} );
}() );
