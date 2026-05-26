/* Digitale Bazen AI Module — Settings tabs
 *
 * Klikbare tabs voor de Settings-page. Onthoudt actieve tab in
 * localStorage en waarschuwt bij wegnavigeren met onopgeslagen
 * wijzigingen. Pure vanilla JS, geen dependencies.
 */
( function () {
	'use strict';

	const tabs = document.querySelector( '.db-ai-tabs' );
	if ( ! tabs ) {
		return;
	}

	const TAB_KEY = 'db_ai_settings_active_tab';
	const form = tabs.querySelector( 'form' );
	const nav = tabs.querySelector( '.db-ai-tabs-nav' );
	if ( ! form || ! nav ) {
		return;
	}

	const navItems = Array.from( nav.querySelectorAll( 'li[data-tab]' ) );
	const panes = Array.from( tabs.querySelectorAll( '.db-ai-tabs-pane[data-tab]' ) );
	if ( panes.length === 0 ) {
		return;
	}

	let isDirty = false;

	function readStoredTab() {
		try {
			const raw = window.localStorage.getItem( TAB_KEY );
			const valid = panes.some( ( p ) => p.dataset.tab === raw );
			return valid ? raw : panes[ 0 ].dataset.tab;
		} catch ( e ) {
			return panes[ 0 ].dataset.tab;
		}
	}

	function persistTab( id ) {
		try {
			window.localStorage.setItem( TAB_KEY, id );
		} catch ( e ) {
			/* localStorage unavailable — non-fatal */
		}
	}

	function showTab( id ) {
		panes.forEach( ( pane ) => {
			pane.classList.toggle( 'is-active', pane.dataset.tab === id );
		} );

		navItems.forEach( ( li ) => {
			li.classList.toggle( 'is-active', li.dataset.tab === id );
		} );

		persistTab( id );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	navItems.forEach( ( li ) => {
		li.addEventListener( 'click', () => {
			const id = li.dataset.tab;
			if ( id ) {
				showTab( id );
			}
		} );
	} );

	// Dirty tracking
	form.addEventListener( 'input', () => {
		if ( ! isDirty ) {
			isDirty = true;
			tabs.classList.add( 'is-dirty' );
		}
	} );

	form.addEventListener( 'change', () => {
		if ( ! isDirty ) {
			isDirty = true;
			tabs.classList.add( 'is-dirty' );
		}
	} );

	form.addEventListener( 'submit', () => {
		isDirty = false;
		tabs.classList.remove( 'is-dirty' );
	} );

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( isDirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// Init
	tabs.classList.add( 'js-enabled' );
	showTab( readStoredTab() );

	// ─── KWO uploader + delete (Settings → Zoekwoorden tab) ─────────────
	const kwoConfig = window.dbAiSettings || null;
	const kwoUploader = document.getElementById( 'db-ai-kwo-uploader' );
	if ( ! kwoConfig || ! kwoUploader ) {
		return;
	}

	const kwoStatus = document.getElementById( 'db-ai-kwo-status' );
	const kwoName = document.getElementById( 'db-ai-kwo-name' );
	const kwoFile = document.getElementById( 'db-ai-kwo-file' );
	const kwoUpload = document.getElementById( 'db-ai-kwo-upload-btn' );
	const kwoTable = document.getElementById( 'db-ai-kwo-table' );

	function setKwoStatus( msg, type ) {
		if ( ! kwoStatus ) return;
		kwoStatus.textContent = msg || '';
		kwoStatus.className = 'db-ai-status' + ( type ? ' is-' + type : '' );
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function renderKwoList( all ) {
		if ( ! kwoTable ) return;
		const tbody = kwoTable.querySelector( 'tbody' );
		if ( ! tbody ) return;
		const i18n = kwoConfig.i18n || {};

		if ( ! all || all.length === 0 ) {
			tbody.innerHTML = '<tr class="db-ai-kwo-empty"><td colspan="4"><em>'
				+ escapeHtml( i18n.noKwoYet || 'Nog geen onderzoeken opgeslagen.' )
				+ '</em></td></tr>';
			return;
		}

		let html = '';
		all.forEach( function ( r ) {
			html += '<tr data-kwo-id="' + r.id + '">'
				+ '<td>' + escapeHtml( r.name ) + '</td>'
				+ '<td>' + r.count + '</td>'
				+ '<td>' + escapeHtml( r.uploaded_at ) + '</td>'
				+ '<td><button type="button" class="button-link-delete db-ai-kwo-delete-btn" data-kwo-id="' + r.id + '">'
				+ escapeHtml( i18n.tableDeleteLabel || 'Verwijder' )
				+ '</button></td>'
				+ '</tr>';
		} );
		tbody.innerHTML = html;
	}

	// Converteer xlsx/xls/ods/csv naar pure CSV file via SheetJS, dan POST naar server.
	function uploadKwo() {
		const i18n = kwoConfig.i18n || {};
		const name = ( kwoName && kwoName.value || '' ).trim();
		const file = kwoFile && kwoFile.files && kwoFile.files[ 0 ];

		if ( ! name ) {
			setKwoStatus( i18n.missingName || 'Geef het onderzoek een naam.', 'error' );
			return;
		}
		if ( ! file ) {
			setKwoStatus( i18n.missingFile || 'Selecteer een bestand.', 'error' );
			return;
		}

		setKwoStatus( i18n.parsing || 'Bestand lezen…', 'loading' );
		if ( kwoUpload ) kwoUpload.disabled = true;

		const reader = new FileReader();
		reader.onload = function ( e ) {
			try {
				const data = new Uint8Array( e.target.result );
				const wb = XLSX.read( data, { type: 'array' } );
				const ws = wb.Sheets[ wb.SheetNames[ 0 ] ];
				const csv = XLSX.utils.sheet_to_csv( ws, { FS: ',', RS: '\n' } );

				const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } );
				const csvFile = new File( [ blob ], name.replace( /[^a-z0-9-]+/gi, '-' ) + '.csv', { type: 'text/csv' } );

				const formData = new FormData();
				formData.append( 'action', 'db_ai_save_kwo' );
				formData.append( 'nonce', kwoConfig.nonce );
				formData.append( 'name', name );
				formData.append( 'csv', csvFile );

				setKwoStatus( i18n.uploading || 'Bezig met uploaden…', 'loading' );

				fetch( kwoConfig.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				} )
					.then( function ( res ) { return res.json(); } )
					.then( function ( json ) {
						if ( kwoUpload ) kwoUpload.disabled = false;
						if ( ! json || ! json.success ) {
							const msg = ( json && json.data && json.data.message ) || ( i18n.uploadFailed || 'Upload mislukt.' );
							setKwoStatus( msg, 'error' );
							return;
						}
						setKwoStatus( ( i18n.uploadOk || 'Opgeslagen — %d zoekwoorden.' ).replace( '%d', json.data.count ), 'success' );
						renderKwoList( json.data.all || [] );
						if ( kwoName ) kwoName.value = '';
						if ( kwoFile ) kwoFile.value = '';
						kwoNameDirty = false; // klaar voor de volgende upload
					} )
					.catch( function () {
						if ( kwoUpload ) kwoUpload.disabled = false;
						setKwoStatus( i18n.networkError || 'Netwerkfout.', 'error' );
					} );
			} catch ( err ) {
				if ( kwoUpload ) kwoUpload.disabled = false;
				setKwoStatus( i18n.parseFailed || 'Bestand kon niet gelezen worden.', 'error' );
			}
		};
		reader.onerror = function () {
			if ( kwoUpload ) kwoUpload.disabled = false;
			setKwoStatus( i18n.parseFailed || 'Bestand kon niet gelezen worden.', 'error' );
		};
		reader.readAsArrayBuffer( file );
	}

	function deleteKwo( id ) {
		const i18n = kwoConfig.i18n || {};
		if ( ! window.confirm( i18n.confirmDelete || 'Dit onderzoek verwijderen?' ) ) {
			return;
		}

		const formData = new FormData();
		formData.append( 'action', 'db_ai_delete_kwo' );
		formData.append( 'nonce', kwoConfig.nonce );
		formData.append( 'id', String( id ) );

		fetch( kwoConfig.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					setKwoStatus( ( json && json.data && json.data.message ) || ( i18n.deleteFailed || 'Verwijderen mislukt.' ), 'error' );
					return;
				}
				setKwoStatus( i18n.deleted || 'Verwijderd.', 'success' );
				renderKwoList( json.data.all || [] );
			} )
			.catch( function () {
				setKwoStatus( i18n.networkError || 'Netwerkfout.', 'error' );
			} );
	}

	// Auto-fill naam met bestandsnaam (zonder extensie) zolang user nog niet
	// handmatig heeft getypt. Bij elke nieuwe file-keuze opnieuw vullen — tenzij
	// user de naam handmatig heeft aangepast. Volledig aanpasbaar blijft het.
	let kwoNameDirty = false;
	if ( kwoName ) {
		kwoName.addEventListener( 'input', function () {
			kwoNameDirty = kwoName.value.trim() !== '';
		} );
	}
	if ( kwoFile && kwoName ) {
		kwoFile.addEventListener( 'change', function ( e ) {
			const file = e.target.files && e.target.files[ 0 ];
			if ( ! file ) return;
			if ( ! kwoNameDirty ) {
				kwoName.value = file.name.replace( /\.(csv|xlsx|xls|ods)$/i, '' );
			}
		} );
	}

	if ( kwoUpload ) {
		kwoUpload.addEventListener( 'click', uploadKwo );
	}

	// Delegate click-handler op de table voor delete-buttons (werkt ook na re-render).
	if ( kwoTable ) {
		kwoTable.addEventListener( 'click', function ( e ) {
			const target = e.target;
			if ( target && target.classList && target.classList.contains( 'db-ai-kwo-delete-btn' ) ) {
				const id = parseInt( target.dataset.kwoId, 10 );
				if ( ! isNaN( id ) ) deleteKwo( id );
			}
		} );
	}
} )();
