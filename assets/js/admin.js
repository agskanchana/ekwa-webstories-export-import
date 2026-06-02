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

	// ---- Batched export -----------------------------------------------------
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

		var sizeInput = document.getElementById( 'ekwa-wsei-batch-size' );
		var size = Math.max( 1, parseInt( sizeInput && sizeInput.value, 10 ) || 1 );
		var batches = chunk( ids, size );

		var progress = document.getElementById( 'ekwa-wsei-export-progress' );
		var status = progress.querySelector( '.ekwa-wsei-progress-status' );
		var list = document.getElementById( 'ekwa-wsei-export-downloads' );
		var downloadAllBtn = document.getElementById( 'ekwa-wsei-download-all' );
		var spinner = document.querySelector( '.ekwa-wsei-spinner' );
		var buttons = document.querySelectorAll( '#ekwa-wsei-export-btn, #ekwa-wsei-export-all-btn' );

		progress.hidden = false;
		list.innerHTML = '';
		downloadAllBtn.hidden = true;
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
				status.textContent = sprintf( cfg.i18n.done, builtUrls.length );
				if ( builtUrls.length > 0 ) {
					downloadAllBtn.hidden = false;
				}
				return;
			}

			status.textContent = sprintf( cfg.i18n.building, index + 1, batches.length );

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
						addDownloadRow( list, index + 1, d );
						// Download each batch as soon as it is built. Because the
						// builds are seconds apart, the transfers do not overlap
						// (which is what truncated/corrupted the later ZIPs when
						// all downloads fired at once).
						triggerDownload( d.downloadUrl );
					} else {
						var msg = res && res.data && res.data.message ? res.data.message : 'error';
						addErrorRow( list, index + 1, msg );
					}
				} )
				.catch( function ( err ) {
					addErrorRow( list, index + 1, String( err ) );
				} )
				.finally( function () {
					// Small gap between batches keeps downloads from overlapping.
					setTimeout( function () {
						buildBatch( index + 1 );
					}, 600 );
				} );
		}

		buildBatch( 0 );

		// Manual "download all again" for any transfer that got interrupted.
		// Files stay on the server for ~2 hours, so re-clicking always works.
		downloadAllBtn.onclick = function () {
			builtUrls.forEach( function ( url, i ) {
				setTimeout( function () {
					triggerDownload( url );
				}, i * 2000 );
			} );
		};
	}

	function addDownloadRow( list, num, data ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		var label = document.createElement( 'span' );
		label.textContent = sprintf( cfg.i18n.batchLabel, num, data.stories, data.assets ) + ' — ';
		var a = document.createElement( 'a' );
		a.href = data.downloadUrl;
		a.textContent = cfg.i18n.download + ' (' + data.filename + ')';
		a.setAttribute( 'download', data.filename );
		li.appendChild( label );
		li.appendChild( a );
		list.appendChild( li );
	}

	function addErrorRow( list, num, msg ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		li.className = 'ekwa-wsei-error';
		li.textContent = sprintf( cfg.i18n.failed, num, msg );
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
