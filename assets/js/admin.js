( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function sprintf( tpl ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( tpl ).replace( /%\d?\$?[ds]/g, function () {
			return args[ i++ ];
		} );
	}

	ready( function () {
		setupSelectAll();
		setupExportMode();
		setupBatchExport();
	} );

	// ---- Select-all checkbox ------------------------------------------------
	function setupSelectAll() {
		var selectAll = document.getElementById( 'ekwa-wsei-select-all' );
		var boxes = document.querySelectorAll( '.ekwa-wsei-story' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = selectAll.checked;
				} );
			} );
		}

		boxes.forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				if ( ! selectAll ) {
					return;
				}
				var checked = document.querySelectorAll( '.ekwa-wsei-story:checked' );
				selectAll.checked = boxes.length === checked.length;
				selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
			} );
		} );
	}

	// ---- Export mode (single ZIP vs batches) --------------------------------
	function getExportMode() {
		var checked = document.querySelector( 'input[name="ekwa-wsei-export-mode"]:checked' );
		return checked && 'batch' === checked.value ? 'batch' : 'single';
	}

	// Show the batch-size control only when "Multiple batch ZIPs" is chosen.
	function setupExportMode() {
		var radios = document.querySelectorAll( 'input[name="ekwa-wsei-export-mode"]' );
		var control = document.querySelector( '.ekwa-wsei-batch-control' );
		if ( ! radios.length || ! control ) {
			return;
		}
		function sync() {
			control.style.display = 'batch' === getExportMode() ? '' : 'none';
		}
		radios.forEach( function ( r ) {
			r.addEventListener( 'change', sync );
		} );
		sync();
	}

	// ---- Export -------------------------------------------------------------
	function setupBatchExport() {
		var form = document.getElementById( 'ekwa-wsei-export-form' );
		if ( ! form || typeof window.ekwaWsei === 'undefined' ) {
			return; // No JS config -> fall back to plain form submit (single ZIP).
		}

		var btnSelected = document.getElementById( 'ekwa-wsei-export-btn' );
		var btnAll = document.getElementById( 'ekwa-wsei-export-all-btn' );

		if ( btnSelected ) {
			btnSelected.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startExport( false );
			} );
		}
		if ( btnAll ) {
			btnAll.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startExport( true );
			} );
		}
	}

	function collectIds( all ) {
		var sel = all ? '.ekwa-wsei-story' : '.ekwa-wsei-story:checked';
		return Array.prototype.map.call(
			document.querySelectorAll( sel ),
			function ( box ) {
				return box.value;
			}
		);
	}

	function chunk( arr, size ) {
		var out = [];
		for ( var i = 0; i < arr.length; i += size ) {
			out.push( arr.slice( i, i + size ) );
		}
		return out;
	}

	function startExport( all ) {
		var cfg = window.ekwaWsei;
		var ids = collectIds( all );

		if ( ! ids.length ) {
			window.alert( cfg.i18n.noneChosen );
			return;
		}

		// Decide how the selection is split into server requests. Single mode =
		// one ZIP with everything (memory-safe: assets are streamed into the ZIP
		// on the server, never loaded into PHP memory). Batch mode = several
		// smaller ZIPs of `size` stories each, built one at a time.
		var single = 'batch' !== getExportMode();
		var batches;
		if ( single ) {
			batches = [ ids ];
		} else {
			var sizeInput = document.getElementById( 'ekwa-wsei-batch-size' );
			var size = Math.max( 1, parseInt( sizeInput && sizeInput.value, 10 ) || 1 );
			batches = chunk( ids, size );
		}

		var progress = document.getElementById( 'ekwa-wsei-export-progress' );
		var status = progress.querySelector( '.ekwa-wsei-progress-status' );
		var list = document.getElementById( 'ekwa-wsei-export-downloads' );
		var downloadAllBtn = document.getElementById( 'ekwa-wsei-download-all' );
		var spinner = document.querySelector( '.ekwa-wsei-spinner' );
		var buttons = document.querySelectorAll( '#ekwa-wsei-export-btn, #ekwa-wsei-export-all-btn' );

		progress.hidden = false;
		list.innerHTML = '';
		downloadAllBtn.hidden = true;
		downloadAllBtn.textContent = single ? cfg.i18n.downloadAgain : cfg.i18n.downloadAllBatches;
		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );

		var builtUrls = [];

		function buildBatch( index ) {
			if ( index >= batches.length ) {
				// Done.
				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
				buttons.forEach( function ( b ) {
					b.disabled = false;
				} );
				status.textContent = single
					? cfg.i18n.doneSingle
					: sprintf( cfg.i18n.doneBatch, builtUrls.length );
				if ( builtUrls.length > 0 ) {
					downloadAllBtn.hidden = false;
				}
				return;
			}

			status.textContent = single
				? cfg.i18n.buildingSingle
				: sprintf( cfg.i18n.buildingBatch, index + 1, batches.length );

			var body = new URLSearchParams();
			body.append( 'action', 'ekwa_wsei_export_batch' );
			body.append( 'nonce', cfg.nonce );
			batches[ index ].forEach( function ( id ) {
				body.append( 'story_ids[]', id );
			} );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.success ) {
						var d = res.data;
						builtUrls.push( d.downloadUrl );
						addDownloadRow( list, index + 1, d, single );
						// Download each ZIP as soon as it is built. In batch mode the
						// builds are seconds apart, so the transfers do not overlap
						// (overlapping is what truncated the later ZIPs before).
						triggerDownload( d.downloadUrl );
					} else {
						var msg = res && res.data && res.data.message ? res.data.message : 'error';
						addErrorRow( list, index + 1, msg, single );
					}
				} )
				.catch( function ( err ) {
					addErrorRow( list, index + 1, String( err ), single );
				} )
				.finally( function () {
					// Small gap between batches keeps downloads from overlapping.
					setTimeout( function () {
						buildBatch( index + 1 );
					}, 600 );
				} );
		}

		buildBatch( 0 );

		// Manual "download again" for any transfer that got interrupted. Files
		// stay on the server for ~2 hours, so re-clicking always works.
		downloadAllBtn.onclick = function () {
			builtUrls.forEach( function ( url, i ) {
				setTimeout( function () {
					triggerDownload( url );
				}, i * 2000 );
			} );
		};
	}

	function addDownloadRow( list, num, data, single ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		var label = document.createElement( 'span' );
		var text = single
			? sprintf( cfg.i18n.labelSingle, data.stories, data.assets )
			: sprintf( cfg.i18n.labelBatch, num, data.stories, data.assets );
		label.textContent = text + ' — ';
		var a = document.createElement( 'a' );
		a.href = data.downloadUrl;
		a.textContent = cfg.i18n.download + ' (' + data.filename + ')';
		a.setAttribute( 'download', data.filename );
		li.appendChild( label );
		li.appendChild( a );
		list.appendChild( li );
	}

	function addErrorRow( list, num, msg, single ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		li.className = 'ekwa-wsei-error';
		li.textContent = single
			? sprintf( cfg.i18n.failedSingle, msg )
			: sprintf( cfg.i18n.failedBatch, num, msg );
		list.appendChild( li );
	}

	function triggerDownload( url ) {
		var a = document.createElement( 'a' );
		a.href = url;
		a.style.display = 'none';
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			document.body.removeChild( a );
		}, 100 );
	}
} )();
