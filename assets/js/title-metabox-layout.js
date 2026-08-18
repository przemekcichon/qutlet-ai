/**
 * Qutlet AI — scalenie natywnego tytułu produktu z metaboksem „Nazwa produktu
 * (AI)" (P-20.4b, D-20.3). Przenosi `#titlediv` (tytuł + edytor bezpośredniego
 * odnośnika, rdzeń WP) do kotwicy wewnątrz metaboksa — RAZ, przy starcie
 * strony, TRWALE (bez logiki przywracania, w odróżnieniu od
 * `product-review-wizard.js`, który przenosi węzły tylko na czas otwarcia
 * kreatora). `name="post_title"` zapisuje się wprost do kolumny bazy, więc
 * przeniesienie węzła nie wymaga żadnej zmiany zapisu (D-20.3).
 *
 * `#title` w rdzeniu WP ma font-size dobrany pod PEŁNĄ szerokość kolumny
 * głównej (`edit-form-advanced.php`) — wewnątrz wąskiego bocznego metaboksa
 * dłuższe nazwy produktów się nie mieszczą (zweryfikowane wizualnie przy
 * realizacji P-20.4b). Zmniejszamy inline, bez osobnego pliku CSS — ten sam
 * styl co reszta tego metaboksa (inline `style` w PHP), tylko dociągnięty tu,
 * bo `#title` to węzeł rdzenia WP, nie nasz markup.
 */
( function () {
	var anchor = document.querySelector( '[data-qutlet-ai-titlediv-anchor]' );
	var titlediv = document.getElementById( 'titlediv' );

	if ( ! anchor || ! titlediv ) {
		return;
	}

	anchor.appendChild( titlediv );

	var titleInput = document.getElementById( 'title' );

	if ( titleInput ) {
		titleInput.style.fontSize = '14px';
		titleInput.style.height = 'auto';
		titleInput.style.padding = '6px 8px';
	}
} )();
