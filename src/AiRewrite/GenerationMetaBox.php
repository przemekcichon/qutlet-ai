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
 * Flow celowo BEZ JS/AJAX — w całym projekcie akcje admina zmieniające stan idą
 * przez `admin-post.php` + `wp_nonce_field`/`check_admin_referer` (wzorzec z
 * `Qutlet\Allegro\Auth\OAuthController`), a same akcje są prawdziwymi
 * `<form method="post">` — CELOWO nie GET-linkami: „Generuj" woła płatne
 * wywołanie zewnętrznego dostawcy AI, a link (goły `href`) dałby się odpalić
 * przypadkiem — spekulatywnym prefetch/prerender przeglądarki albo web-shieldem
 * antywirusa skanującym linki na stronie (środek ostrożności, NIE obserwowany
 * fakt — `CLAUDE.md` dokumentuje na tej maszynie inne zachowania Avasta:
 * kwarantannę śledzonych plików repo i blokowanie certów HTTPS composerowi,
 * nie skanowanie linków w adminie WP). POST wymaga faktycznego submitu
 * formularza, którego żaden z tych mechanizmów nie robi.
 *
 * Formularze NIE mogą jednak żyć wewnątrz metaboxa: ten renderuje się wewnątrz
 * jednego wielkiego `<form id="post" action="post.php">` WordPressa na ekranie
 * edycji produktu, a zagnieżdżony `<form>` jest nieprawidłowym HTML-em —
 * przeglądarka spłaszcza go do formularza zewnętrznego, nasze ukryte pole
 * `name="action"` nadpisuje pole WP `action=editpost`, `post.php` dostaje
 * nierozpoznaną akcję i przekierowuje na `edit.php` (listę postów) zamiast
 * wołać nasz handler — dokładnie tak to wyglądało, zanim to poprawiono (to
 * realny bug, nie hipoteza). Rozwiązanie: trzy niewidoczne `<form>` renderują
 * się PO ZAMKNIĘCIU formularza WP, na hooku `admin_footer-post.php`
 * ({@see self::render_footer_forms()}) — a przyciski w metaboksie to zwykłe
 * `<button type="submit" form="…">`, wiążące się z formularzem przez HTML5
 * atrybut `form` (bez potrzeby zagnieżdżania ani JS-a).
 *
 * 1. „Generuj" woła {@see RewriteGenerator::generate()} i odkłada wynik jako
 *    PODGLĄD w krótkotrwałym transiencie (`qutlet_ai_pending_{id}`) — NIE
 *    zapisuje jeszcze do realnych pól.
 * 2. Metabox pokazuje podgląd obok surowego wejścia i bieżącej warstwy
 *    przerobionej, żeby dało się ocenić, co model zrobił ze źródłem.
 * 3. „Zaakceptuj" woła {@see RewriteWriter::accept()} (zapis realny) i czyści
 *    podgląd; „Odrzuć" tylko czyści podgląd bez zapisu.
 *
 * Komunikat po akcji: transient per produkt+użytkownik, NIE query string jak w
 * `OAuthController` — tam status musiał przetrwać zewnętrzny redirect z Allegro
 * (adres nie był z góry znany jako „ten sam ekran"); tu cel przekierowania to
 * zawsze ten sam, znany z góry ekran edycji TEGO produktu, więc nie ma powodu
 * kodować stanu w URL-u.
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
	 * Nazwa akcji `admin-post` generującej podgląd przeróbki.
	 */
	private const GENERATE_ACTION = 'qutlet_ai_generate_rewrite';

	/**
	 * Nazwa akcji `admin-post` akceptującej podgląd (zapis realny).
	 */
	private const ACCEPT_ACTION = 'qutlet_ai_accept_rewrite';

	/**
	 * Nazwa akcji `admin-post` odrzucającej podgląd (bez zapisu).
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
	 * TTL komunikatu po akcji (transient) — jednorazowy odczyt zaraz po redirect.
	 */
	private const NOTICE_TTL = MINUTE_IN_SECONDS;

	/**
	 * Wpina rejestrację metaboxa i handlery akcji `admin-post`. Wołane z
	 * bootstrapu `qutlet-ai` (na `plugins_loaded`, po sprawdzeniu twardej
	 * zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
		// `admin_footer-post.php` — hook specyficzny dla EKRANU (nie post type'u);
		// `render_footer_forms()` sam sprawdza `post_type`. Fires PO zamknięciu
		// `<form id="post">` (patrz docblock klasy) — stąd formularze akcji tu, nie
		// w metaboksie.
		add_action( 'admin_footer-post.php', array( self::class, 'render_footer_forms' ) );
		add_action( 'admin_post_' . self::GENERATE_ACTION, array( self::class, 'handle_generate' ) );
		add_action( 'admin_post_' . self::ACCEPT_ACTION, array( self::class, 'handle_accept' ) );
		add_action( 'admin_post_' . self::DISCARD_ACTION, array( self::class, 'handle_discard' ) );
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
			__( 'Qutlet — generacja AI (przeróbka)', 'qutlet-ai' ),
			array( self::class, 'render' ),
			self::SCREEN,
			'normal',
			'high'
		);
	}

	/**
	 * Renderuje metabox: komunikat po akcji, zestawienie kolumn (surowe /
	 * przerobione / podgląd) i przycisk „Generuj".
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$product_id = $post->ID;

		self::render_notice( $product_id );

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
			self::render_action_button(
				$product_id,
				self::GENERATE_ACTION,
				__( 'Generuj', 'qutlet-ai' ),
				'button-primary'
			);
		}

		echo '</p>';
	}

	/**
	 * Akcja „Generuj": woła generację i odkłada wynik jako podgląd (transient).
	 *
	 * @return void
	 */
	public static function handle_generate(): void {
		$product_id = self::authorized_product_id( self::GENERATE_ACTION );

		$result = RewriteGenerator::generate( $product_id );

		if ( is_wp_error( $result ) ) {
			self::set_notice( $product_id, 'error', $result->get_error_message() );
			self::redirect_to_edit_screen( $product_id );
		}

		set_transient( self::pending_key( $product_id ), $result, self::PENDING_TTL );

		self::set_notice(
			$product_id,
			'success',
			__( 'Wygenerowano podgląd przeróbki — porównaj poniżej i zaakceptuj albo odrzuć.', 'qutlet-ai' )
		);
		self::redirect_to_edit_screen( $product_id );
	}

	/**
	 * Akcja „Zaakceptuj": zapisuje podgląd do realnych pól i czyści go.
	 *
	 * @return void
	 */
	public static function handle_accept(): void {
		$product_id = self::authorized_product_id( self::ACCEPT_ACTION );
		$pending    = get_transient( self::pending_key( $product_id ) );

		if ( ! self::is_pending_shape( $pending ) ) {
			self::set_notice(
				$product_id,
				'error',
				__( 'Brak wygenerowanego podglądu do zaakceptowania (mógł wygasnąć) — wygeneruj ponownie.', 'qutlet-ai' )
			);
			self::redirect_to_edit_screen( $product_id );
		}

		$saved = RewriteWriter::accept( $product_id, $pending['opis'] );

		if ( ! $saved ) {
			// Produkt zniknął między „Generuj" a „Zaakceptuj" (np. usunięty) —
			// podgląd zostaje w transiencie (TTL i tak go w końcu wygasi), NIE
			// pokazujemy fałszywego sukcesu.
			self::set_notice(
				$product_id,
				'error',
				__( 'Zapis nie powiódł się — produkt nie istnieje albo nie jest produktem WooCommerce.', 'qutlet-ai' )
			);
			self::redirect_to_edit_screen( $product_id );
		}

		delete_transient( self::pending_key( $product_id ) );

		self::set_notice(
			$product_id,
			'success',
			__( 'Przeróbka zaakceptowana i zapisana (opis).', 'qutlet-ai' )
		);
		self::redirect_to_edit_screen( $product_id );
	}

	/**
	 * Akcja „Odrzuć": czyści podgląd bez zapisu.
	 *
	 * @return void
	 */
	public static function handle_discard(): void {
		$product_id = self::authorized_product_id( self::DISCARD_ACTION );

		delete_transient( self::pending_key( $product_id ) );

		self::set_notice( $product_id, 'success', __( 'Podgląd odrzucony.', 'qutlet-ai' ) );
		self::redirect_to_edit_screen( $product_id );
	}

	/**
	 * Kolumna „Surowe" — opis prozą i specyfikacja z warstwy surowej (Allegro).
	 * Pełny JSON oferty pokazuje osobno `RawLayerMetaBox` (core, P-5.3) — tu tylko
	 * te dwa pola: opis wchodzi do porównania z wygenerowaną przeróbką (kolumny
	 * niżej), specyfikacja zostaje jako kontekst wejścia AI (ten sam surowy JSON
	 * karmi generację opisu) mimo że od P-13.4b/D-13.G1 nie ma już z czym jej
	 * porównać — atrybuty WC tłumaczy 1:1 sync Allegro, nie ten flow.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_raw_column( int $product_id ): void {
		$description   = (string) get_post_meta( $product_id, RawLayerMeta::META_DESCRIPTION_RAW, true );
		$specification = get_post_meta( $product_id, RawLayerMeta::META_SPECIFICATION_RAW, true );

		if ( ! is_array( $specification ) ) {
			$specification = array();
		}

		echo '<div style="flex:1;min-width:18em">';
		printf( '<h4>%s</h4>', esc_html__( 'Surowe (Allegro)', 'qutlet-ai' ) );
		self::render_html_preview( $description, esc_html__( 'Brak opisu tekstowego w ofercie.', 'qutlet-ai' ) );
		self::render_pairs_list( $specification, esc_html__( 'Brak parametrów w ofercie.', 'qutlet-ai' ) );
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
		self::render_html_preview( $opis, esc_html__( 'Brak opisu — jeszcze nie wygenerowano/zredagowano.', 'qutlet-ai' ) );
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
	 * Kolumna „Wygenerowane (podgląd)" — widoczna tylko, gdy istnieje nieodrzucony
	 * podgląd z ostatniego „Generuj". Daje przyciski „Zaakceptuj"/„Odrzuć".
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_pending_column( int $product_id ): void {
		$pending = get_transient( self::pending_key( $product_id ) );

		if ( ! self::is_pending_shape( $pending ) ) {
			return;
		}

		echo '<div style="flex:1;min-width:18em;background:#f6f7f7;padding:.75em;border:1px solid #dcdcde">';
		printf( '<h4>%s</h4>', esc_html__( 'Wygenerowane (podgląd — jeszcze nie zapisane)', 'qutlet-ai' ) );
		self::render_html_preview( $pending['opis'], esc_html__( 'Model zwrócił pusty opis.', 'qutlet-ai' ) );

		echo '<p>';
		self::render_action_button( $product_id, self::ACCEPT_ACTION, __( 'Zaakceptuj', 'qutlet-ai' ), 'button-primary' );
		echo ' ';
		self::render_action_button( $product_id, self::DISCARD_ACTION, __( 'Odrzuć', 'qutlet-ai' ), 'button' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Renderuje HTML (opis) w przewijalnym pudełku, przez `wp_kses_post()` — ten
	 * sam allowlist, co w `RawLayerMetaBox` (bezpieczne formatowanie przechodzi,
	 * `<script>`/`on*` odcięte). Puste → nota o braku.
	 *
	 * @param string $html    Treść HTML.
	 * @param string $empty_note Nota wyświetlana, gdy treść jest pusta (już `esc_html`).
	 * @return void
	 */
	private static function render_html_preview( string $html, string $empty_note ): void {
		if ( '' === trim( $html ) ) {
			printf( '<p><em>%s</em></p>', $empty_note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $empty_note już esc_html w wywołaniu.

			return;
		}

		printf(
			'<div style="max-height:14em;overflow:auto;padding:.5em;border:1px solid #dcdcde;background:#fff;word-break:break-word;margin-bottom:.5em">%s</div>',
			wp_kses_post( $html )
		);
	}

	/**
	 * Renderuje listę par etykieta→wartość jako prostą listę definicyjną. Puste →
	 * nota o braku. Jedyny dziś konsument to {@see self::render_raw_column()}
	 * (surowa specyfikacja Allegro) — od P-13.4b/D-13.G1 atrybuty WC nie są już
	 * generowane przez AI, więc nie ma ich (ani ich podglądu) tu do wyrenderowania.
	 *
	 * @param array<int, array{etykieta?: mixed, wartosc?: mixed}> $pairs      Lista par.
	 * @param string                                               $empty_note Nota wyświetlana, gdy lista jest pusta (już `esc_html`).
	 * @return void
	 */
	private static function render_pairs_list( array $pairs, string $empty_note ): void {
		if ( array() === $pairs ) {
			printf( '<p><em>%s</em></p>', $empty_note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $empty_note już esc_html w wywołaniu.

			return;
		}

		echo '<dl style="margin:0">';

		foreach ( $pairs as $pair ) {
			$label = isset( $pair['etykieta'] ) ? (string) $pair['etykieta'] : '';
			$value = isset( $pair['wartosc'] ) ? (string) $pair['wartosc'] : '';

			printf( '<dt style="font-weight:600">%s</dt>', esc_html( $label ) );
			printf( '<dd style="margin:0 0 .5em 0">%s</dd>', esc_html( $value ) );
		}

		echo '</dl>';
	}

	/**
	 * Renderuje przycisk akcji jako `<button type="submit" form="…">` — wiąże się
	 * z formularzem renderowanym osobno w stopce ({@see self::render_footer_forms()})
	 * przez HTML5 atrybut `form` (button NIE musi być potomkiem formularza, do
	 * którego się odnosi), więc może bezpiecznie żyć wewnątrz metaboxa mimo że
	 * właściwy `<form>` akcji jest gdzie indziej w drzewie DOM (patrz docblock
	 * klasy — powód, dla którego formularze nie mogą być tu, w metaboksie).
	 *
	 * @param int    $product_id ID produktu.
	 * @param string $action     Nazwa akcji `admin-post`.
	 * @param string $label      Etykieta przycisku.
	 * @param string $css_class  Klasa CSS (`button`/`button-primary`).
	 * @return void
	 */
	private static function render_action_button( int $product_id, string $action, string $label, string $css_class ): void {
		printf(
			'<button type="submit" form="%1$s" class="button %2$s">%3$s</button>',
			esc_attr( self::form_id( $action, $product_id ) ),
			esc_attr( $css_class ),
			esc_html( $label )
		);
	}

	/**
	 * Renderuje (na `admin_footer-post.php`, PO zamknięciu `<form id="post">") trzy
	 * niewidoczne formularze akcji — jeden zawsze wystarczy do obsłużenia
	 * dowolnego przycisku w metaboksie tego produktu, niezależnie od tego, które z
	 * nich metabox akurat pokazuje (Generuj zawsze; Zaakceptuj/Odrzuć tylko gdy
	 * jest podgląd). Nieużyty formularz jest nieszkodliwy — bez odpowiadającego
	 * przycisku nikt go nie submituje.
	 *
	 * @return void
	 */
	public static function render_footer_forms(): void {
		$screen = get_current_screen();

		if ( null === $screen || self::SCREEN !== $screen->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko do ustalenia, DLA KTÓREGO produktu wyrenderować formularze; autoryzację (capability + nonce) i tak wykonuje handler przy submicie.
		$product_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $product_id <= 0 ) {
			return;
		}

		foreach ( array( self::GENERATE_ACTION, self::ACCEPT_ACTION, self::DISCARD_ACTION ) as $action ) {
			self::render_footer_form( $product_id, $action );
		}
	}

	/**
	 * Renderuje jeden niewidoczny formularz akcji (nonce związany z produktem —
	 * wzorzec `Qutlet\Allegro\Auth\ConnectionsPage::render_disconnect_form()`).
	 *
	 * `wp_nonce_field()` dostaje jawną nazwę pola (`self::nonce_field_name()`),
	 * NIE domyślną `_wpnonce` — trzy formularze w stopce renderują się obok
	 * siebie na tej samej stronie, a strona MA JUŻ własne `#_wpnonce` z głównego
	 * `<form id="post">` WP; bez tego trzy kolejne pola o tym samym `id` byłyby
	 * duplikatem (nieprawidłowy HTML, nawet jeśli dziś nieszkodliwy, bo jedyny
	 * konsument, `wp-admin/js/post.js`, i tak trafia we WŁAŚCIWE — pierwsze w
	 * DOM — pole WP).
	 *
	 * @param int    $product_id ID produktu.
	 * @param string $action     Nazwa akcji `admin-post`.
	 * @return void
	 */
	private static function render_footer_form( int $product_id, string $action ): void {
		printf(
			'<form method="post" action="%1$s" id="%2$s" style="display:none">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::form_id( $action, $product_id ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="product_id" value="%d">', $product_id );
		wp_nonce_field( self::nonce_action( $action, $product_id ), self::nonce_field_name( $action ) );
		echo '</form>';
	}

	/**
	 * Nazwa pola nonce (`$name` w `wp_nonce_field()`/`$query_arg` w
	 * `check_admin_referer()`) — jedna na akcję, żeby uniknąć duplikatu `id`
	 * (patrz docblock {@see self::render_footer_form()}).
	 *
	 * @param string $action Nazwa akcji `admin-post`.
	 * @return string
	 */
	private static function nonce_field_name( string $action ): string {
		return '_wpnonce_' . $action;
	}

	/**
	 * Identyfikator DOM formularza akcji — wiąże przycisk (`render_action_button()`,
	 * atrybut `form`) z jego formularzem w stopce (`render_footer_form()`).
	 *
	 * @param string $action     Nazwa akcji `admin-post`.
	 * @param int    $product_id ID produktu.
	 * @return string
	 */
	private static function form_id( string $action, int $product_id ): string {
		return 'qutlet-ai-' . $action . '-' . $product_id;
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
	 * Autoryzuje żądanie akcji `admin-post`: capability na WSKAZANYM produkcie +
	 * nonce związany z (akcja, produkt). Kończy żądanie (`wp_die`), gdy
	 * nieautoryzowane.
	 *
	 * Czyta `product_id` z `$_POST` — akcje idą przez prawdziwe formularze POST,
	 * renderowane w stopce (patrz docblock klasy i {@see self::render_footer_forms()}).
	 *
	 * @param string $action Nazwa akcji `admin-post` (do zbudowania nazwy nonce'a).
	 * @return int ID produktu (zawsze > 0, gdy funkcja wraca).
	 */
	private static function authorized_product_id( string $action ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce weryfikowany niżej (check_admin_referer), po walidacji ID.
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( $product_id <= 0 || ! current_user_can( self::CAPABILITY, $product_id ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tej akcji na tym produkcie.', 'qutlet-ai' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::nonce_action( $action, $product_id ), self::nonce_field_name( $action ) );

		return $product_id;
	}

	/**
	 * Nazwa akcji nonce — wiąże nonce z konkretną akcją I produktem (wzorzec
	 * `OAuthController::connect_nonce_action()`).
	 *
	 * @param string $action     Nazwa akcji `admin-post`.
	 * @param int    $product_id ID produktu.
	 * @return string
	 */
	private static function nonce_action( string $action, int $product_id ): string {
		return $action . '_' . $product_id;
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

	/**
	 * Klucz transientu komunikatu po akcji (per produkt + użytkownik).
	 *
	 * @param int $product_id ID produktu.
	 * @return string
	 */
	private static function notice_key( int $product_id ): string {
		return 'qutlet_ai_notice_' . $product_id . '_' . get_current_user_id();
	}

	/**
	 * Odkłada komunikat do wyświetlenia po przekierowaniu z powrotem na ekran edycji.
	 *
	 * @param int    $product_id ID produktu.
	 * @param string $type       `success` albo `error`.
	 * @param string $message    Treść komunikatu.
	 * @return void
	 */
	private static function set_notice( int $product_id, string $type, string $message ): void {
		set_transient(
			self::notice_key( $product_id ),
			array(
				'type'    => $type,
				'message' => $message,
			),
			self::NOTICE_TTL
		);
	}

	/**
	 * Renderuje (i konsumuje — jednorazowy odczyt) komunikat po ostatniej akcji.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_notice( int $product_id ): void {
		$notice = get_transient( self::notice_key( $product_id ) );
		delete_transient( self::notice_key( $product_id ) );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Przekierowuje z powrotem na ekran edycji produktu. Kończy żądanie (`exit`).
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 *
	 * @phpstan-return never
	 */
	private static function redirect_to_edit_screen( int $product_id ): void {
		$url = get_edit_post_link( $product_id, 'raw' );

		if ( ! is_string( $url ) || '' === $url ) {
			$url = admin_url( 'edit.php?post_type=product' );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
