/**
 * Qutlet AI — generator opisu produktu (metabox „Qutlet — generacja AI
 * (przeróbka)", P-17.1). W odróżnieniu od `title-generator.js` (zapis
 * BEZPOŚREDNI, `window.confirm()` jako zabezpieczenie zastępcze) ten flow
 * zachowuje trójstopniowość generuj→podgląd→akceptuj/odrzuć
 * (wp_ajax_qutlet_ai_generate_rewrite / _accept_rewrite / _discard_rewrite):
 * „Generuj" tylko odkłada podgląd w transiencie, nic nieodwracalnego się nie
 * dzieje, więc żadny z trzech przycisków nie potrzebuje potwierdzenia.
 *
 * Wszystkie fragmenty HTML w odpowiedziach (`opis_html`) są już sanityzowane
 * po stronie serwera (`wp_kses_post()`, {@see GenerationMetaBox::html_preview_markup()})
 * — ten skrypt tylko wstawia je przez `innerHTML`, nie buduje HTML-a z danych
 * z odpowiedzi samodzielnie.
 */
( function () {
	var config = window.qutletAiRewriteGenerator;

	if ( ! config ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var generateBtn = e.target.closest( '[data-qutlet-ai-generate]' );

		if ( generateBtn ) {
			runAction( generateBtn, config.generateAction, config.i18n.generating, onGenerateSuccess );

			return;
		}

		var acceptBtn = e.target.closest( '[data-qutlet-ai-accept]' );

		if ( acceptBtn ) {
			runAction( acceptBtn, config.acceptAction, config.i18n.accepting, onAcceptSuccess );

			return;
		}

		var discardBtn = e.target.closest( '[data-qutlet-ai-discard]' );

		if ( discardBtn ) {
			runAction( discardBtn, config.discardAction, config.i18n.discarding, onDiscardSuccess );
		}
	} );

	function runAction( button, action, busyLabel, onSuccess ) {
		var status = document.querySelector( '[data-qutlet-ai-status]' );
		var originalLabel = button.textContent;

		button.disabled = true;
		button.textContent = busyLabel;
		setStatus( status, '', '' );

		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce );
		body.set( 'product_id', config.productId );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					throw new Error( ( payload && payload.data && payload.data.message ) || config.i18n.genericError );
				}

				onSuccess( payload.data );
				setStatus( status, 'success', payload.data.message || '' );
			} )
			.catch( function ( error ) {
				setStatus( status, 'error', error.message || config.i18n.genericError );
			} )
			.then( function () {
				button.disabled = false;
				button.textContent = originalLabel;
			} );
	}

	function onGenerateSuccess( data ) {
		var preview = document.getElementById( 'qutlet-ai-pending-preview' );
		var column = document.getElementById( 'qutlet-ai-pending-column' );
		var actions = document.getElementById( 'qutlet-ai-pending-actions' );

		if ( preview && 'string' === typeof data.opis_html ) {
			preview.innerHTML = data.opis_html;
		}

		if ( column ) {
			column.style.display = '';
		}

		if ( actions ) {
			actions.style.display = '';
		}
	}

	function onAcceptSuccess( data ) {
		var current = document.getElementById( 'qutlet-ai-current-opis' );

		if ( current && 'string' === typeof data.opis_html ) {
			current.innerHTML = data.opis_html;
		}

		hidePendingColumn();
	}

	function onDiscardSuccess() {
		hidePendingColumn();
	}

	function hidePendingColumn() {
		var column = document.getElementById( 'qutlet-ai-pending-column' );
		var preview = document.getElementById( 'qutlet-ai-pending-preview' );
		var actions = document.getElementById( 'qutlet-ai-pending-actions' );

		if ( column ) {
			column.style.display = 'none';
		}

		if ( preview ) {
			preview.innerHTML = '';
		}

		if ( actions ) {
			actions.style.display = 'none';
		}
	}

	function setStatus( el, type, message ) {
		if ( ! el ) {
			return;
		}

		el.textContent = message || '';
		el.style.color = 'error' === type ? '#d63638' : ( 'success' === type ? '#008a20' : '' );
	}
} )();
