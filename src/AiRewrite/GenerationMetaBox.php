<?php
/**
 * Slice AiRewrite — metabox generacji AI: generuj/podgląd/zaakceptuj (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\AiRewrite\PromptOverrideField;
use Qutlet\Core\ProductInfo\RawLayerMeta;
use WP_Post;

/**
 * Ekran generacji na edycji produktu: zestawienie porównawcze surowe ↔
 * przerobione ↔ (jeśli jest) wygenerowany podgląd, plus akcje admina „Generuj" /
 * „Zaakceptuj" / „Odrzuć" (D-7.3.1 — zwykła akcja admina, nie Ability).
 *
 * Współistnieje z {@see \Qutlet\Core\ProductInfo\RawLayerMetaBox} (P-5.3, gołe
 * pole surowe + pełny JSON) jako OSOBNY metabox tego samego ekranu — podział z
 * D-5.G3: bare raw field pokazuje core, zestawienie porównawcze pokazuje
 * `qutlet-ai` na swoim ekranie.
 *
 * Flow to `wp_ajax_*` (P-17.1, D-17.2) — WZOREM {@see TitleGenerationMetaBox}
 * (P-13.2c): przyciski w metaboksie są zwykłymi `<button type="button">` z JS
 * (`assets/js/rewrite-generator.js`) wołającym `admin-ajax.php` przez `fetch()`,
 * bez przeładowania strony. Do P-17.1 flow szedł przez `admin-post.php` +
 * prawdziwe `<form method="post">` (zagnieżdżenie w głównym `<form id="post">`
 * WP wymagało trzech niewidocznych formularzy w stopce, `admin_footer-post.php`)
 * — USUNIĘTE wraz z konwersją, AJAX nie potrzebuje żadnego `<form>`.
 *
 * Trójstopniowość (generuj→podgląd→akceptuj/odrzuć) ZOSTAJE (D-17.2, D-13.G2
 * aktualne co do KROKU) — to ona jest tu zabezpieczeniem zamiast
 * `window.confirm()` z {@see TitleGenerationMetaBox}: „Generuj" nie zapisuje
 * nic nieodwracalnego (tylko podgląd w transiencie), więc przypadkowe
 * kliknięcie kosztuje najwyżej jedno zbędne (płatne) wywołanie dostawcy AI, nie
 * utratę danych — stąd brak potwierdzenia w JS, w przeciwieństwie do generatora
 * tytułu (zapis BEZPOŚREDNI, bez podglądu).
 *
 * 1. „Generuj" woła {@see RewriteGenerator::generate()} i odkłada wynik jako
 *    PODGLĄD w krótkotrwałym transiencie (`qutlet_ai_pending_{id}`) — NIE
 *    zapisuje jeszcze do realnych pól.
 * 2. Metabox pokazuje podgląd obok surowego wejścia i bieżącej warstwy
 *    przerobionej, żeby dało się ocenić, co model zrobił ze źródłem.
 * 3. „Zaakceptuj" woła {@see RewriteWriter::accept()} (zapis realny) i czyści
 *    podgląd; „Odrzuć" tylko czyści podgląd bez zapisu.
 *
 * Komunikat po akcji: wraca bezpośrednio w odpowiedzi JSON (jak przy tytule) —
 * transient komunikatu z ery `admin-post.php` (potrzebny, żeby przetrwać
 * przekierowanie po pełnym przeładowaniu) jest USUNIĘTY wraz z P-17.1, AJAX nie
 * przeładowuje strony.
 *
 * Prompt AI (P-13.6b, D-13.G4): metabox zyskał też sekcję promptu (przed
 * przyciskiem „Generuj") — nadpisanie per produkt ({@see PromptOverrideField::render_field()},
 * pole `prompt_ai` rejestrowane przez `qutlet-core`) obok READ-ONLY podglądu
 * promptu globalnego ({@see self::render_global_prompt_preview()}).
 */
final class GenerationMetaBox {

	/**
	 * Ekran (typ posta), na którym pokazujemy metabox — produkt WooCommerce.
	 */
	private const SCREEN = 'product';

	/**
	 * Identyfikator metaboxa (unikalny w obrębie ekranu; różny od
	 * `RawLayerMetaBox::META_BOX_ID` w core).
	 */
	private const META_BOX_ID = 'qutlet_ai_generation';

	/**
	 * Nazwa akcji `wp_ajax_*` generującej podgląd przeróbki.
	 */
	private const GENERATE_ACTION = 'qutlet_ai_generate_rewrite';

	/**
	 * Nazwa akcji `wp_ajax_*` akceptującej podgląd (zapis realny).
	 */
	private const ACCEPT_ACTION = 'qutlet_ai_accept_rewrite';

	/**
	 * Nazwa akcji `wp_ajax_*` odrzucającej podgląd (bez zapisu).
	 */
	private const DISCARD_ACTION = 'qutlet_ai_discard_rewrite';

	/**
	 * Capability wymagana do akcji — meta-capability WP dla EDYCJI TEGO produktu
	 * (nie `manage_woocommerce`, bo to akcja na pojedynczym poście, nie ustawienie
	 * sklepowe).
	 */
	private const CAPABILITY = 'edit_post';

	/**
	 * TTL podglądu wygenerowanej przeróbki (transient) — wystarczająco długo na
	 * przejrzenie i decyzję w tej samej sesji admina.
	 */
	private const PENDING_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Uchwyt (handle) skryptu JS obsługującego przyciski metaboxa (wzorzec
	 * {@see TitleGenerationMetaBox::SCRIPT_HANDLE}).
	 */
	private const SCRIPT_HANDLE = 'qutlet-ai-rewrite-generator';

	/**
	 * Wpina rejestrację metaboxa, enqueue skryptu i handlery `wp_ajax_*` (P-17.1).
	 * Wołane z bootstrapu `qutlet-ai` (na `plugins_loaded`, po sprawdzeniu twardej
	 * zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
		add_action( 'wp_ajax_' . self::GENERATE_ACTION, array( self::class, 'handle_generate' ) );
		add_action( 'wp_ajax_' . self::ACCEPT_ACTION, array( self::class, 'handle_accept' ) );
		add_action( 'wp_ajax_' . self::DISCARD_ACTION, array( self::class, 'handle_discard' ) );
	}

	/**
	 * Rejestruje metabox tylko dla ekranu edycji produktu.
	 *
	 * Priorytet `high` (P-13.3b) — TEN SAM co natywny „Product data" WooCommerce
	 * (`WC_Admin_Meta_Boxes::add_meta_boxes()`, hook `add_meta_boxes` priorytet 30).
	 * WP nie ma priorytetu WYŻEJ niż `high`; w obrębie jednego priorytetu kolejność
	 * renderu to kolejność DOPISANIA do `$wp_meta_boxes` (`do_meta_boxes()` w
	 * rdzeniu, `foreach` po tablicy asocjacyjnej) — czyli kolejność wykonania
	 * callbacków hooka `add_meta_boxes`. `self::init()` wpina `register()` na tym
	 * samym hooku z priorytetem DOMYŚLNYM (10) — NIŻSZYM niż 30 WooCommerce —
	 * więc nasz `add_meta_box()` wykonuje się first, ląduje w `high` PRZED
	 * `woocommerce-product-data`, i renderuje się nad nim (bezpośrednio pod
	 * natywnym edytorem treści). Bez wymuszania kolejności przez
	 * `remove_meta_box()`+`add_meta_box()` — mniej inwazyjne, brak ryzyka
	 * konfliktu z przyszłym repozycjonowaniem Product Data przez WooCommerce.
	 *
	 * @param string $post_type Typ posta bieżącego ekranu edycji.
	 * @return void
	 */
	public static function register( string $post_type ): void {
		if ( self::SCREEN !== $post_type ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Generacja AI (przeróbka)', 'qutlet-ai' ),
			array( self::class, 'render' ),
			self::SCREEN,
			'normal',
			'high'
		);
	}

	/**
	 * Ładuje JS obsługi przycisków WYŁĄCZNIE na ekranie edycji produktu (wzorzec
	 * {@see TitleGenerationMetaBox::enqueue_script()}).
	 *
	 * @return void
	 */
	public static function enqueue_script(): void {
		$screen = get_current_screen();

		if ( null === $screen || self::SCREEN !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko do zbudowania nonce'a dla TEGO produktu; autoryzację (capability + nonce) i tak wykonuje handler AJAX przy każdym żądaniu.
		$product_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $product_id <= 0 ) {
			return; // post-new.php: produkt jeszcze nie istnieje, nie ma warstwy surowej do przerobienia.
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/rewrite-generator.js', \Qutlet\Ai\PLUGIN_FILE ),
			array(),
			\Qutlet\Ai\VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'qutletAiRewriteGenerator',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'generateAction' => self::GENERATE_ACTION,
				'acceptAction'   => self::ACCEPT_ACTION,
				'discardAction'  => self::DISCARD_ACTION,
				'nonce'          => wp_create_nonce( self::nonce_action( $product_id ) ),
				'productId'      => $product_id,
				'i18n'           => array(
					'generating'   => __( 'Generowanie…', 'qutlet-ai' ),
					'accepting'    => __( 'Zapisywanie…', 'qutlet-ai' ),
					'discarding'   => __( 'Odrzucanie…', 'qutlet-ai' ),
					'genericError' => __( 'Coś poszło nie tak — spróbuj ponownie.', 'qutlet-ai' ),
				),
			)
		);
	}

	/**
	 * Renderuje metabox: pole statusu (wypełniane przez JS), zestawienie kolumn
	 * (surowe / przerobione / podgląd) i przycisk „Generuj".
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$product_id = $post->ID;

		echo '<p data-qutlet-ai-status style="margin-top:0"></p>';

		self::render_content_editor_section( $post );

		$raw_offer = get_post_meta( $product_id, RawLayerMeta::META_OFFER, true );
		$has_raw   = is_string( $raw_offer ) && '' !== trim( $raw_offer );

		echo '<div style="display:flex;gap:1.5em;flex-wrap:wrap;align-items:flex-start">';
		self::render_raw_column( $product_id );
		self::render_current_column( $post );
		self::render_pending_column( $product_id );
		echo '</div>';

		self::render_prompt_section( $product_id );

		echo '<p style="margin-top:1em">';

		if ( ! $has_raw ) {
			esc_html_e( 'Brak warstwy surowej — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany. Nie ma z czego wygenerować przeróbki.', 'qutlet-ai' );
		} else {
			printf(
				'<button type="button" class="button button-primary" data-qutlet-ai-generate>%s</button>',
				esc_html__( 'Generuj', 'qutlet-ai' )
			);
		}

		echo '</p>';
	}

	/**
	 * Natywny edytor treści (`post_content`) — PIERWSZA sekcja scalonego
	 * metaboksu (D-20.6, D-20.G4). `qutlet-core` zdejmuje wsparcie edytora dla
	 * CPT `product` ({@see \Qutlet\Core\AiRewrite\ContentEditorSupport}) —
	 * natywny box „Opis produktu" (`#postdivrich`) przestaje się renderować
	 * osobno na ekranie; render `wp_editor()` przenosi się tutaj. Zapis
	 * (`$_POST['content']` → `post_content`, `_wp_translate_postdata()`) i JS
	 * synchronizacji po „Zaakceptuj" ({@see \Qutlet\Ai\AiRewrite\RewriteGenerator},
	 * `rewrite-generator.js::setContentField()`) celują w pole PO ID
	 * (`content`), więc działają bez zmian niezależnie od miejsca renderu.
	 *
	 * Opcje skopiowane z dzisiejszego wywołania w rdzeniu WP
	 * (`wp-admin/edit-form-advanced.php`) — Z WYJĄTKIEM opcji „distraction free
	 * writing" (`_content_editor_dfw`/`wp_autoresize_on`/skrypt
	 * `editor-expand`): ten mechanizm jest myślany pod pełnoszerokościowy
	 * `#postdivrich`, nie pod wąski metabox, i tak czy inaczej przestaje się
	 * ładować dla `product` po zdjęciu wsparcia edytora (blok w rdzeniu, który
	 * go enqueue'uje, jest bramkowany tą samą flagą).
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	private static function render_content_editor_section( WP_Post $post ): void {
		printf( '<h4 style="margin-top:0">%s</h4>', esc_html__( 'Opis produktu', 'qutlet-ai' ) );

		wp_editor(
			$post->post_content,
			'content',
			array(
				'drag_drop_upload' => true,
				'editor_height'    => 300,
			)
		);
	}

	/**
	 * Akcja „Generuj" (AJAX): woła generację i odkłada wynik jako podgląd
	 * (transient) — kod statusu z {@see RewriteGenerator::generate()} przez
	 * {@see self::error_status()} (wzorzec {@see TitleGenerationMetaBox::handle_generate()}).
	 *
	 * @return void
	 */
	public static function handle_generate(): void {
		$product_id = self::authorized_product_id();

		$result = RewriteGenerator::generate( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				self::error_status( $result )
			);
		}

		set_transient( self::pending_key( $product_id ), $result, self::PENDING_TTL );

		wp_send_json_success(
			array(
				'opis_html' => self::html_preview_markup( $result['opis'], esc_html__( 'Model zwrócił pusty opis.', 'qutlet-ai' ) ),
				'message'   => __( 'Wygenerowano podgląd przeróbki — porównaj poniżej i zaakceptuj albo odrzuć.', 'qutlet-ai' ),
			)
		);
	}

	/**
	 * Akcja „Zaakceptuj" (AJAX): zapisuje podgląd do realnych pól i czyści go.
	 *
	 * Odpowiedź niesie też SUROWY (posanityzowany) opis (`opis`), nie tylko
	 * gotowy podgląd HTML (`opis_html`) — bez tego natywny edytor treści
	 * (`#content`, TinyMCE) zostaje ze STARĄ wartością sprzed AJAX-owego
	 * zapisu, a kolejne kliknięcie natywnego „Aktualizuj" (albo kroku
	 * kreatora, {@see \Qutlet\Core\ProductReviewWizard\ProductReviewWizard})
	 * nadpisuje `post_content` z powrotem tą starą wartością — dokładnie ten
	 * sam problem, który {@see TitleGenerationMetaBox} rozwiązuje dla
	 * `#title`/`podnazwa` (patrz jego docblock). JS
	 * (`assets/js/rewrite-generator.js`) wstawia `opis` do edytora.
	 *
	 * @return void
	 */
	public static function handle_accept(): void {
		$product_id = self::authorized_product_id();
		$pending    = get_transient( self::pending_key( $product_id ) );

		if ( ! self::is_pending_shape( $pending ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Brak wygenerowanego podglądu do zaakceptowania (mógł wygasnąć) — wygeneruj ponownie.', 'qutlet-ai' ) ),
				422
			);
		}

		$saved = RewriteWriter::accept( $product_id, $pending['opis'] );

		if ( ! $saved ) {
			// Produkt zniknął między „Generuj" a „Zaakceptuj" (np. usunięty) —
			// podgląd zostaje w transiencie (TTL i tak go w końcu wygasi), NIE
			// pokazujemy fałszywego sukcesu.
			wp_send_json_error(
				array( 'message' => __( 'Zapis nie powiódł się — produkt nie istnieje albo nie jest produktem WooCommerce.', 'qutlet-ai' ) ),
				500
			);
		}

		delete_transient( self::pending_key( $product_id ) );

		// Ten sam allowlist, którym {@see RewriteWriter::accept()} sanityzuje
		// PRZED zapisem — odpowiedź niesie to, co POWINNO odpowiadać
		// `post_content` (nie surowy, niesanityzowany `$pending['opis']`), z
		// zastrzeżeniem, że `wp_update_post()` przepuszcza `post_content`
		// dodatkowo przez `content_save_pre` (`convert_invalid_entities`,
		// `balanceTags`) — przy niezbalansowanym HTML-u z modelu wynik może się
		// SUBTELNIE różnić od bajtów faktycznie zapisanych w bazie.
		$opis_saved = wp_kses_post( $pending['opis'] );

		wp_send_json_success(
			array(
				'opis'      => $opis_saved,
				'opis_html' => self::html_preview_markup( $opis_saved, esc_html__( 'Brak opisu — jeszcze nie wygenerowano/zredagowano.', 'qutlet-ai' ) ),
				'message'   => __( 'Przeróbka zaakceptowana i zapisana (opis).', 'qutlet-ai' ),
			)
		);
	}

	/**
	 * Akcja „Odrzuć" (AJAX): czyści podgląd bez zapisu.
	 *
	 * @return void
	 */
	public static function handle_discard(): void {
		$product_id = self::authorized_product_id();

		delete_transient( self::pending_key( $product_id ) );

		wp_send_json_success(
			array( 'message' => __( 'Podgląd odrzucony.', 'qutlet-ai' ) )
		);
	}

	/**
	 * Kolumna „Surowe" — opis prozą z warstwy surowej (Allegro). Pełny JSON
	 * oferty pokazuje osobno `RawLayerMetaBox` (core, P-5.3). Lista
	 * atrybutów/parametrów spod tego nagłówka USUNIĘTA (D-20.5, zgłoszenie
	 * FAZY 20 pkt 8) — był to znany relikt sprzed P-13.4b/D-13.G1: od tamtej
	 * fazy specyfikacja nie ma już z czym się tu porównywać (atrybuty WC
	 * tłumaczy 1:1 sync Allegro, nie ten flow), a lista niosła tylko szum.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_raw_column( int $product_id ): void {
		$description = (string) get_post_meta( $product_id, RawLayerMeta::META_DESCRIPTION_RAW, true );

		echo '<div style="flex:1;min-width:18em">';
		printf( '<h4>%s</h4>', esc_html__( 'Surowe (Allegro)', 'qutlet-ai' ) );
		echo self::html_preview_markup( $description, esc_html__( 'Brak opisu tekstowego w ofercie.', 'qutlet-ai' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html_preview_markup() już wp_kses_post/esc_html wewnątrz.
		echo '</div>';
	}

	/**
	 * Kolumna „Przerobione (bieżące)" — to, co dziś widać na stronie produktu:
	 * natywny opis (`post_content`, P-13.3a/b). Do P-13.4b pokazywała tu też
	 * atrybuty WC (specyfikacja) — USUNIĘTE (D-13.G1): atrybuty nie są już
	 * częścią tego flow (pisze je sync Allegro, nie AI, P-13.4a), więc nie ma
	 * ich z czym tu porównywać.
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	private static function render_current_column( WP_Post $post ): void {
		$opis = (string) $post->post_content;

		echo '<div style="flex:1;min-width:18em">';
		printf( '<h4>%s</h4>', esc_html__( 'Przerobione (bieżące, na stronie)', 'qutlet-ai' ) );
		echo '<div id="qutlet-ai-current-opis">';
		echo self::html_preview_markup( $opis, esc_html__( 'Brak opisu — jeszcze nie wygenerowano/zredagowano.', 'qutlet-ai' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html_preview_markup() już wp_kses_post/esc_html wewnątrz.
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Sekcja promptu AI (P-13.6b, D-13.G4): edytowalne nadpisanie per produkt
	 * (pole `prompt_ai`, rejestruje `qutlet-core` — renderuje przez
	 * {@see PromptOverrideField::render_field()}, bo `qutlet-ai` nie ma twardej
	 * zależności na ACF Pro) obok READ-ONLY podglądu promptu globalnego —
	 * kurator widzi oba na raz przed „Generuj", bez przeskakiwania na stronę
	 * ustawień.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_prompt_section( int $product_id ): void {
		echo '<div style="margin-top:1em;padding-top:1em;border-top:1px solid #dcdcde">';

		PromptOverrideField::render_field( $product_id );
		self::render_global_prompt_preview();

		echo '</div>';
	}

	/**
	 * Podgląd promptu globalnego (`PromptSettings::OPTION_NAME`) — READ-ONLY, sam
	 * odczyt `get_option()` (P-13.6b), bez linku edycji: strona ustawień
	 * (`PromptSettingsPage`, pod menu WooCommerce) jest osobnym, wystarczającym
	 * sposobem na edycję — nie duplikujemy tu formularza.
	 *
	 * @return void
	 */
	private static function render_global_prompt_preview(): void {
		$global = get_option( PromptSettings::OPTION_NAME, '' );
		$global = is_string( $global ) ? $global : '';

		printf( '<h4>%s</h4>', esc_html__( 'Prompt globalny (podgląd, tylko do odczytu)', 'qutlet-ai' ) );

		if ( '' === trim( $global ) ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Brak ustawionego promptu globalnego.', 'qutlet-ai' ) );

			return;
		}

		printf(
			'<div style="max-height:10em;overflow:auto;padding:.5em;border:1px solid #dcdcde;background:#f6f7f7;white-space:pre-wrap;word-break:break-word">%s</div>',
			esc_html( $global )
		);
	}

	/**
	 * Kolumna „Wygenerowane (podgląd)" — wrapper renderuje się ZAWSZE (ukryty
	 * `display:none`, gdy brak podglądu), żeby JS mógł go pokazać/wypełnić po
	 * udanym „Generuj" bez przeładowania strony (P-17.1). Przy renderze z
	 * istniejącym, nieodrzuconym podglądem z ostatniego „Generuj" (np. reload w
	 * trakcie oceny) wypełnia się od razu.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_pending_column( int $product_id ): void {
		$pending     = get_transient( self::pending_key( $product_id ) );
		$has_pending = self::is_pending_shape( $pending );

		printf(
			'<div id="qutlet-ai-pending-column" style="flex:1;min-width:18em;background:#f6f7f7;padding:.75em;border:1px solid #dcdcde%s">',
			$has_pending ? '' : ';display:none'
		);
		printf( '<h4>%s</h4>', esc_html__( 'Wygenerowane (podgląd — jeszcze nie zapisane)', 'qutlet-ai' ) );

		echo '<div id="qutlet-ai-pending-preview">';
		if ( $has_pending ) {
			echo self::html_preview_markup( $pending['opis'], esc_html__( 'Model zwrócił pusty opis.', 'qutlet-ai' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html_preview_markup() już wp_kses_post/esc_html wewnątrz.
		}
		echo '</div>';

		printf( '<p id="qutlet-ai-pending-actions"%s>', $has_pending ? '' : ' style="display:none"' );
		printf(
			'<button type="button" class="button button-primary" data-qutlet-ai-accept>%s</button> ',
			esc_html__( 'Zaakceptuj', 'qutlet-ai' )
		);
		printf(
			'<button type="button" class="button" data-qutlet-ai-discard>%s</button>',
			esc_html__( 'Odrzuć', 'qutlet-ai' )
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Renderuje HTML (opis) w przewijalnym pudełku, przez `wp_kses_post()` — ten
	 * sam allowlist, co w `RawLayerMetaBox` (bezpieczne formatowanie przechodzi,
	 * `<script>`/`on*` odcięte). Puste → nota o braku. Zwraca markup (string) —
	 * NIE echo — żeby ten sam fragment mógł wrócić w odpowiedzi JSON AJAX-a
	 * (P-17.1: sanityzacja HTML zawsze po stronie serwera, JS tylko wstawia
	 * już-bezpieczny fragment).
	 *
	 * @param string $html       Treść HTML.
	 * @param string $empty_note Nota wyświetlana, gdy treść jest pusta (już `esc_html`).
	 * @return string
	 */
	private static function html_preview_markup( string $html, string $empty_note ): string {
		if ( '' === trim( $html ) ) {
			return sprintf( '<p><em>%s</em></p>', $empty_note );
		}

		return sprintf(
			'<div style="max-height:14em;overflow:auto;padding:.5em;border:1px solid #dcdcde;background:#fff;word-break:break-word;margin-bottom:.5em">%s</div>',
			wp_kses_post( $html )
		);
	}

	/**
	 * Waliduje kształt wartości odczytanej z transientu podglądu (obrona przed
	 * uszkodzonym/wygasłym wpisem).
	 *
	 * @param mixed $pending Wartość z `get_transient()`.
	 * @return bool
	 * @phpstan-assert-if-true array{opis: string} $pending
	 */
	private static function is_pending_shape( $pending ): bool {
		return is_array( $pending )
			&& isset( $pending['opis'] )
			&& is_string( $pending['opis'] );
	}

	/**
	 * Autoryzuje żądanie AJAX: capability na WSKAZANYM produkcie + nonce związany
	 * z (metabox, produkt). Kończy żądanie (`wp_send_json_error()`, który sam
	 * wywołuje `wp_die()`), gdy nieautoryzowane (wzorzec
	 * {@see TitleGenerationMetaBox::authorized_product_id()}).
	 *
	 * @return int ID produktu (zawsze > 0, gdy funkcja wraca).
	 */
	private static function authorized_product_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce weryfikowany niżej (check_ajax_referer), po walidacji ID.
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( $product_id <= 0 || ! current_user_can( self::CAPABILITY, $product_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Brak uprawnień do tej akcji na tym produkcie.', 'qutlet-ai' ) ),
				403
			);
		}

		if ( ! check_ajax_referer( self::nonce_action( $product_id ), 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Nieprawidłowy token bezpieczeństwa — odśwież stronę i spróbuj ponownie.', 'qutlet-ai' ) ),
				403
			);
		}

		return $product_id;
	}

	/**
	 * Kod statusu HTTP z danych `WP_Error` (`array('status' => …)`, wzorzec
	 * {@see RewriteGenerator::generate()}) — 500, gdy błąd nie niesie statusu
	 * (wzorzec {@see TitleGenerationMetaBox::error_status()}).
	 *
	 * @param \WP_Error $error Błąd zwrócony przez generację.
	 * @return int
	 */
	private static function error_status( \WP_Error $error ): int {
		$data = $error->get_error_data();

		return ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
	}

	/**
	 * Nazwa akcji nonce — wiąże nonce z konkretnym produktem. Jedna wspólna dla
	 * wszystkich trzech akcji (Generuj/Zaakceptuj/Odrzuć): wszystkie operują na
	 * tym samym (produkt, capability), osobne nonce per akcja nie dodałoby
	 * ochrony (wzorzec {@see TitleGenerationMetaBox::nonce_action()}).
	 *
	 * @param int $product_id ID produktu.
	 * @return string
	 */
	private static function nonce_action( int $product_id ): string {
		return 'qutlet_ai_rewrite_' . $product_id;
	}

	/**
	 * Klucz transientu podglądu wygenerowanej przeróbki (per produkt).
	 *
	 * @param int $product_id ID produktu.
	 * @return string
	 */
	private static function pending_key( int $product_id ): string {
		return 'qutlet_ai_pending_' . $product_id;
	}
}
