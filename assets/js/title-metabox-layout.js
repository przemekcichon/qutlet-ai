/**
 * Qutlet AI — scalenie natywnego tytułu produktu z metaboksem „Nazwa produktu
 * (AI)" (P-20.4b, D-20.3). Przenosi `#titlediv` (tytuł + edytor bezpośredniego
 * odnośnika, rdzeń WP) do kotwicy wewnątrz metaboksa — RAZ, przy starcie
 * strony, TRWALE (bez logiki przywracania, w odróżnieniu od
 * `product-review-wizard.js`, który przenosi węzły tylko na czas otwarcia
 * kreatora). `name="post_title"` zapisuje się wprost do kolumny bazy, więc
 * przeniesienie węzła nie wymaga żadnej zmiany zapisu (D-20.3). Box żyje w
 * kontekście `acf_after_title` ({@see \Qutlet\Ai\AiRewrite\TitleGenerationMetaBox::register()})
 * — PEŁNA szerokość głównej kolumny, ta sama co natywne miejsce `#titlediv`,
 * więc jego font-size zostaje bez zmian (w odróżnieniu od wcześniejszej
 * wersji tej zmiany, gdy box żył w wąskim `side`).
 *
 * Edytor bezpośredniego odnośnika (`#edit-slug-box`, dziecko `#titlediv
 * .inside`) wypina się z tytułu i ląduje POD CAŁYM boxem — wewnątrz tego
 * samego skraca się na środku formularza z polem „Druga linia nazwy
 * produktu"/przyciskami, więc czytelniej jest go trzymać osobno, tak jak w
 * natywnym WP (odnośnik POD tytułem, nie zagnieżdżony między innymi polami).
 *
 * ACF Pro doklada do KAŻDEGO boxa w tym kontekście własny margines
 * (`#post-body-content #acf_after_title-sortables{margin:20px 0 -20px}`,
 * `acf-input.css`) — myślany pod pola ACF „przyklejone" do tytułu. Dla
 * naszego (dużo wyższego) boxa ujemny dół zjada margines pod nim (zero
 * odstępu do kolejnego metaboxa), a dodatkowy góra sumuje się z marginesem
 * przycisku „Otwórz kreator" (zbyt duży odstęp nad boxem, zweryfikowane
 * wizualnie przy realizacji P-20.4b). Box jest dziś JEDYNYM konsumentem tego
 * kontekstu w projekcie (patrz docblock `register()`), więc nadpisanie tego
 * marginesu inline jest bezpieczne — nie dotyka żadnego innego elementu.
 */
( function () {
	var anchor = document.querySelector( '[data-qutlet-ai-titlediv-anchor]' );
	var titlediv = document.getElementById( 'titlediv' );
	var editSlugBox = document.getElementById( 'edit-slug-box' );
	var postbox = document.getElementById( 'qutlet_ai_title_generator' );

	if ( editSlugBox && postbox && postbox.parentNode ) {
		postbox.parentNode.insertBefore( editSlugBox, postbox.nextSibling );
	}

	if ( anchor && titlediv ) {
		anchor.appendChild( titlediv );

		var insideBox = titlediv.querySelector( '.inside' );

		if ( insideBox ) {
			insideBox.remove(); // Pusty po wypięciu #edit-slug-box wyżej — zostawiony, zajmowałby martwe miejsce (padding bez treści).
		}
	}

	var container = document.getElementById( 'acf_after_title-sortables' );

	if ( container ) {
		container.style.margin = '0 0 20px';
	}
} )();
