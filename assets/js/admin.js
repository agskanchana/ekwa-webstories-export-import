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

	function startExport( all ) {
		var cfg = window.ekwaWsei;
		var ids = collectIds( all );

		if ( ! ids.length ) {
			window.alert( cfg.i18n.noneChosen );
			return;
		}

		var progress = document.getElementById( 'ekwa-wsei-export-progress' );
		var status = progress.querySelector( '.ekwa-wsei-progress-status' );
		var list = document.getElementById( 'ekwa-wsei-export-downloads' );
		var redownloadBtn = document.getElementById( 'ekwa-wsei-download-all' );
		var spinner = document.querySelector( '.ekwa-wsei-spinner' );
		var buttons = document.querySelectorAll( '#ekwa-wsei-export-btn, #ekwa-wsei-export-all-btn' );

		progress.hidden = false;
		list.innerHTML = '';
		redownloadBtn.hidden = true;
		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );

		status.textContent = cfg.i18n.building;

		// All selected stories go into ONE bundle. The build is done server-side
		// (assets are streamed into the ZIP, so memory stays flat regardless of
		// how many stories), then the finished file is downloaded — and kept on
		// the server for ~2 hours so an interrupted download can be re-clicked.
		var body = new URLSearchParams();
		body.append( 'action', 'ekwa_wsei_export_batch' );
		body.append( 'nonce', cfg.nonce );
		ids.forEach( function ( id ) {
			body.append( 'story_ids[]', id );
		} );

		function finish() {
			if ( spinner ) {
				spinner.classList.remove( 'is-active' );
			}
			buttons.forEach( function ( b ) {
				b.disabled = false;
			} );
		}

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
					addDownloadRow( list, d );
					triggerDownload( d.downloadUrl );
					status.textContent = cfg.i18n.done;
					redownloadBtn.hidden = false;
					redownloadBtn.onclick = function () {
						triggerDownload( d.downloadUrl );
					};
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : 'error';
					addErrorRow( list, msg );
					status.textContent = '';
				}
			} )
			.catch( function ( err ) {
				addErrorRow( list, String( err ) );
				status.textContent = '';
			} )
			.finally( finish );
	}

	function addDownloadRow( list, data ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		var label = document.createElement( 'span' );
		label.textContent = sprintf( cfg.i18n.label, data.stories, data.assets ) + ' — ';
		var a = document.createElement( 'a' );
		a.href = data.downloadUrl;
		a.textContent = cfg.i18n.download + ' (' + data.filename + ')';
		a.setAttribute( 'download', data.filename );
		li.appendChild( label );
		li.appendChild( a );
		list.appendChild( li );
	}

	function addErrorRow( list, msg ) {
		var cfg = window.ekwaWsei;
		var li = document.createElement( 'li' );
		li.className = 'ekwa-wsei-error';
		li.textContent = sprintf( cfg.i18n.failed, msg );
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
