/**
 * Qutlet AI — generator tytułu/podnazwy produktu (metabox „Nazwa produktu
 * (AI)", P-13.2c). Zapis jest BEZPOŚREDNI: „Generuj"/„Reset" od razu
 * piszą do bazy (wp_ajax_qutlet_ai_generate_title / wp_ajax_qutlet_ai_reset_title)
 * — bez etapu podglądu/akceptacji jak przy generatorze opisu. Ten skrypt tylko
 * odświeża pola na ekranie (natywny #title i pole ACF „podnazwa") po udanym
 * zapisie, żeby kolejne kliknięcie natywnego „Aktualizuj" nie nadpisało ich z
 * powrotem starą wartością wciąż siedzącą w formularzu.
 *
 * `window.confirm()` przed żądaniem „Reset" — zabezpieczenie zastępcze za brak
 * `admin-post.php` (D-13.G2): ten mechanizm (w odróżnieniu od generatora opisu)
 * jest AJAX-em, więc nic nie chroni przed przypadkowym/prefetchowanym
 * kliknięciem poza jawnym potwierdzeniem tutaj. „Generuj" NIE potwierdza od
 * P-20.4b (D-20.4) — `runAction()` pomija `window.confirm()`, gdy
 * `confirmMessage` jest puste/`null`.
 */
( function () {
	var config = window.qutletAiTitleGenerator;

	if ( ! config ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var generateBtn = e.target.closest( '[data-qutlet-ai-title-generate]' );

		if ( generateBtn ) {
			runAction( generateBtn, config.generateAction, null, config.i18n.generating );

			return;
		}

		var resetBtn = e.target.closest( '[data-qutlet-ai-title-reset]' );

		if ( resetBtn ) {
			runAction( resetBtn, config.resetAction, config.i18n.confirmReset, config.i18n.resetting );
		}
	} );

	function runAction( button, action, confirmMessage, busyLabel ) {
		if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
			return;
		}

		var status = document.querySelector( '[data-qutlet-ai-title-status]' );
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

				applyResult( payload.data );
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

	function applyResult( data ) {
		var titleInput = document.getElementById( 'title' );

		if ( titleInput && 'string' === typeof data.tytul ) {
			titleInput.value = data.tytul;
			titleInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			titleInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		if ( 'string' === typeof data.podnazwa ) {
			setSubtitleField( data.podnazwa );
		}

		// P-9.1a.2: udany zapis stempluje TitleWriter::SOURCE_RAW_META na bieżącej
		// nazwie Allegro, więc flaga „Nowy" przestaje być aktualna — usuń ją bez
		// przeładowania strony (spójne z resztą tego skryptu).
		var stale = document.querySelector( '[data-qutlet-ai-title-stale]' );

		if ( stale ) {
			stale.remove();
		}
	}

	function setSubtitleField( value ) {
		// `acf.getField('podnazwa')` (bare name string) does NOT reliably resolve
		// to the real field model in this ACF version — `.val()` on it silently
		// no-ops. `acf.getFields({ name: … })` (plural, args object) does.
		if ( window.acf && 'function' === typeof window.acf.getFields ) {
			var fields = window.acf.getFields( { name: 'podnazwa' } );

			if ( fields && fields.length ) {
				fields[ 0 ].val( value );

				return;
			}
		}

		// Brak `acf` JS API (np. ACF nie załadowało się) — fallback po markupie
		// pola: ACF renderuje wrapper `.acf-field` z `data-name="{name}"`.
		var input = document.querySelector( '.acf-field[data-name="podnazwa"] input[type="text"]' );

		if ( input ) {
			input.value = value;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
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
