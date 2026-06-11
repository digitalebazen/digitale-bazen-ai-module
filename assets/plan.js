/* global window, document, fetch, FormData */
( function () {
	'use strict';

	var cfg  = window.dbAiPlan || {};
	var i18n = cfg.i18n || {};

	function $( sel ) {
		return document.querySelector( sel );
	}

	var btn      = $( '#db-ai-analyze-plan' );
	var statusEl = $( '#db-ai-plan-status' );
	if ( ! btn ) {
		return;
	}

	var pollTimer = null;

	function setStatus( msg, cls ) {
		if ( statusEl ) {
			statusEl.textContent = msg;
			statusEl.className   = 'db-ai-status ' + ( cls || '' );
		}
	}

	function fail( msg ) {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
		btn.disabled = false;
		setStatus( msg || i18n.failed || 'Mislukt.', 'is-error' );
	}

	function poll( jobKey ) {
		var url = cfg.ajaxUrl + '?action=db_ai_job_status&nonce=' + encodeURIComponent( cfg.nonce ) +
			'&job_key=' + encodeURIComponent( jobKey );

		pollTimer = setInterval( function () {
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( ! j || ! j.success ) {
						return;
					}
					var d = j.data || {};
					setStatus( ( d.stage_label || i18n.analyzing ) + ' (' + ( d.progress || 0 ) + '%)', 'is-loading' );

					if ( 'done' === d.status ) {
						clearInterval( pollTimer );
						pollTimer = null;
						setStatus( i18n.done || 'Klaar!', 'is-success' );
						window.location.reload();
					} else if ( 'failed' === d.status ) {
						fail( d.error_msg || i18n.failed );
					}
				} )
				.catch( function () { /* netwerk-hapering: volgende tick probeert opnieuw */ } );
		}, 2500 );
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		setStatus( i18n.analyzing || 'Analyseren…', 'is-loading' );

		var fd = new FormData();
		fd.append( 'action', 'db_ai_analyze_plan' );
		fd.append( 'nonce', cfg.nonce );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					fail( ( j && j.data && j.data.message ) || i18n.failed );
					return;
				}
				poll( j.data.job_key );
			} )
			.catch( function () { fail( i18n.networkError ); } );
	} );

	// Inline bewerken van de funnel-hoek per rij → opslaan bij wijziging.
	Array.prototype.forEach.call( document.querySelectorAll( '.db-ai-angle-input' ), function ( input ) {
		input.addEventListener( 'change', function () {
			var fd = new FormData();
			fd.append( 'action', 'db_ai_update_angle' );
			fd.append( 'nonce', cfg.nonce );
			fd.append( 'keyword', input.dataset.keyword || '' );
			fd.append( 'angle', input.value );

			input.classList.remove( 'is-saved' );
			input.classList.add( 'is-saving' );

			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					input.classList.remove( 'is-saving' );
					if ( j && j.success ) {
						input.classList.add( 'is-saved' );
						setTimeout( function () { input.classList.remove( 'is-saved' ); }, 1200 );
					}
				} )
				.catch( function () { input.classList.remove( 'is-saving' ); } );
		} );
	} );

	// Plan importeren: kies een .json-bestand → upload → herlaad.
	var importBtn   = document.getElementById( 'db-ai-import-plan' );
	var importFile  = document.getElementById( 'db-ai-import-file' );
	var importState = document.getElementById( 'db-ai-import-status' );
	if ( importBtn && importFile ) {
		importBtn.addEventListener( 'click', function () { importFile.click(); } );
		importFile.addEventListener( 'change', function () {
			var file = importFile.files && importFile.files[ 0 ];
			if ( ! file ) {
				return;
			}
			if ( ! window.confirm( i18n.importConfirm || 'Dit vervangt het huidige onderzoek en plan. Doorgaan?' ) ) {
				importFile.value = '';
				return;
			}
			if ( importState ) {
				importState.textContent = i18n.importing || 'Importeren…';
				importState.className   = 'db-ai-status is-loading';
			}
			var fd = new FormData();
			fd.append( 'action', 'db_ai_import_plan' );
			fd.append( 'nonce', cfg.nonce );
			fd.append( 'plan', file );

			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( j && j.success ) {
						if ( importState ) {
							importState.textContent = i18n.importOk || 'Geïmporteerd — pagina wordt ververst.';
							importState.className   = 'db-ai-status is-success';
						}
						window.location.reload();
					} else {
						importFile.value = '';
						if ( importState ) {
							importState.textContent = ( j && j.data && j.data.message ) || i18n.importFailed || 'Import mislukt.';
							importState.className   = 'db-ai-status is-error';
						}
					}
				} )
				.catch( function () {
					importFile.value = '';
					if ( importState ) {
						importState.textContent = i18n.networkError || 'Netwerkfout.';
						importState.className   = 'db-ai-status is-error';
					}
				} );
		} );
	}
} )();
