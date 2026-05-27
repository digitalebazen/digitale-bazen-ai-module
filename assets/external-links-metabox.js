/**
 * UI logica voor de "AI — Externe bronnen" metabox.
 *
 * Verzamelt aangevinkte suggesties en stuurt ze naar `db_ai_insert_external_link`.
 * Verwerpen-knop per item triggert `db_ai_dismiss_external_link`.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.dbAiExternalLinks ) {
		return;
	}

	var cfg = window.dbAiExternalLinks;

	function setStatus( msg, isError ) {
		var $s = $( '#db-ai-extlinks-status' );
		$s.text( msg );
		$s.css( 'color', isError ? '#a00' : '#080' );
	}

	$( document ).on( 'click', '#db-ai-extlinks-insert', function ( e ) {
		e.preventDefault();

		var indexes = $( '.db-ai-extlink-check:checked' )
			.map( function () {
				return parseInt( this.value, 10 );
			} )
			.get();

		if ( ! indexes.length ) {
			setStatus( cfg.i18n.noSelection, true );
			return;
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true );
		setStatus( cfg.i18n.inserting, false );

		$.post( cfg.ajaxUrl, {
			action:  'db_ai_insert_external_link',
			nonce:   cfg.nonce,
			post_id: cfg.postId,
			indexes: indexes
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					setStatus( res.data && res.data.message ? res.data.message : cfg.i18n.inserted, false );
					renderDiagnostic( res.data );
					// Removed inserted suggestions from the list voor visuele feedback.
					indexes.forEach( function ( i ) {
						$( '.db-ai-extlinks-list li[data-index="' + i + '"]' ).slideUp( 200, function () {
							$( this ).remove();
						} );
					} );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : cfg.i18n.insertFailed;
					setStatus( msg, true );
					renderDiagnostic( res && res.data );
				}
			} )
			.fail( function () {
				setStatus( cfg.i18n.networkError, true );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );

		function renderDiagnostic( data ) {
			if ( ! data || ! data.trace ) return;

			var $box = $( '#db-ai-extlinks-diag' );
			if ( ! $box.length ) {
				$box = $( '<details id="db-ai-extlinks-diag" style="margin-top:8px;font-size:11px;background:#fafafa;padding:6px;border:1px solid #ddd;"><summary style="cursor:pointer;">Diagnose (kopieer en plak indien faalt)</summary><pre style="white-space:pre-wrap;word-break:break-all;margin:6px 0 0;"></pre></details>' );
				$( '#db-ai-extlinks-status' ).after( $box );
			}

			var dump = {
				update_result:  data.update_result,
				persisted_urls: data.persisted_urls || [],
				trace:          data.trace || {},
				diag:           data.diag || {}
			};
			$box.find( 'pre' ).text( JSON.stringify( dump, null, 2 ) );
			$box.prop( 'open', true );
		}
	} );

	$( document ).on( 'click', '.db-ai-extlink-dismiss', function ( e ) {
		e.preventDefault();

		var index = parseInt( $( this ).data( 'index' ), 10 );
		if ( isNaN( index ) ) return;

		if ( ! window.confirm( cfg.i18n.confirmDismiss ) ) {
			return;
		}

		var $li = $( '.db-ai-extlinks-list li[data-index="' + index + '"]' );

		$.post( cfg.ajaxUrl, {
			action:  'db_ai_dismiss_external_link',
			nonce:   cfg.nonce,
			post_id: cfg.postId,
			index:   index
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$li.slideUp( 200, function () {
						$( this ).remove();
					} );
				} else {
					setStatus( cfg.i18n.insertFailed, true );
				}
			} )
			.fail( function () {
				setStatus( cfg.i18n.networkError, true );
			} );
	} );
} )( jQuery );
