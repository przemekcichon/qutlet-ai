<?php
/**
 * Slice AiRewrite — metabox generacji AI: generuj/podgląd/zaakceptuj (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\ProductInfo\RawLayerMeta;
use WC_Product_Attribute;
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
 * przez `admin-post.php` + `wp_nonce_field`/`check_admin_referer` (wzorzec 1:1
 * z `Qutlet\Allegro\Auth\OAuthController`), więc trzymamy się tego samego stylu:
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
		add_action( 'admin_post_' . self::GENERATE_ACTION, array( self::class, 'handle_generate' ) );
		add_action( 'admin_post_' . self::ACCEPT_ACTION, array( self::class, 'handle_accept' ) );
		add_action( 'admin_post_' . self::DISCARD_ACTION, array( self::class, 'handle_discard' ) );
	}

	/**
	 * Rejestruje metabox tylko dla ekranu edycji produktu.
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
			'default'
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
		self::render_current_column( $product_id );
		self::render_pending_column( $product_id );
		echo '</div>';

		echo '<p style="margin-top:1em">';

		if ( ! $has_raw ) {
			esc_html_e( 'Brak warstwy surowej — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany. Nie ma z czego wygenerować przeróbki.', 'qutlet-ai' );
		} else {
			self::render_action_form(
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

		RewriteWriter::accept( $product_id, $pending['opis'], $pending['specyfikacja'] );
		delete_transient( self::pending_key( $product_id ) );

		self::set_notice(
			$product_id,
			'success',
			__( 'Przeróbka zaakceptowana i zapisana (opis + specyfikacja).', 'qutlet-ai' )
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
	 * te dwa pola, bo to one wchodzą do porównania z wygenerowaną przeróbką.
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
	 * pole `opis` i atrybuty WC niebędące taksonomią (custom, per-produkt —
	 * te, które zapisuje {@see RewriteWriter}).
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	private static function render_current_column( int $product_id ): void {
		$opis    = (string) get_post_meta( $product_id, RewriteWriter::FIELD_OPIS, true );
		$product = wc_get_product( $product_id );

		$pairs = array();

		if ( false !== $product ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof WC_Product_Attribute || $attribute->is_taxonomy() ) {
					continue; // Taksonomia (np. marka) — poza zakresem tego zestawienia.
				}

				$pairs[] = array(
					'etykieta' => $attribute->get_name(),
					'wartosc'  => implode( ', ', $attribute->get_options() ),
				);
			}
		}

		echo '<div style="flex:1;min-width:18em">';
		printf( '<h4>%s</h4>', esc_html__( 'Przerobione (bieżące, na stronie)', 'qutlet-ai' ) );
		self::render_html_preview( $opis, esc_html__( 'Brak opisu — jeszcze nie wygenerowano/zredagowano.', 'qutlet-ai' ) );
		self::render_pairs_list( $pairs, esc_html__( 'Brak atrybutów produktu.', 'qutlet-ai' ) );
		echo '</div>';
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
		self::render_pairs_list( $pending['specyfikacja'], esc_html__( 'Model zwrócił pustą specyfikację.', 'qutlet-ai' ) );

		echo '<p>';
		self::render_action_form( $product_id, self::ACCEPT_ACTION, __( 'Zaakceptuj', 'qutlet-ai' ), 'button-primary' );
		echo ' ';
		self::render_action_form( $product_id, self::DISCARD_ACTION, __( 'Odrzuć', 'qutlet-ai' ), 'button' );
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
	 * Renderuje listę par etykieta→wartość (surowa specyfikacja, atrybuty WC albo
	 * podgląd wygenerowanej specyfikacji) jako prostą listę definicyjną. Puste →
	 * nota o braku.
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
	 * Renderuje formularz POST jednego przycisku akcji (`admin-post.php` + nonce
	 * związany z produktem — wzorzec `OAuthController::render_disconnect_form()`).
	 *
	 * @param int    $product_id ID produktu.
	 * @param string $action     Nazwa akcji `admin-post`.
	 * @param string $label      Etykieta przycisku.
	 * @param string $css_class  Klasa CSS przycisku (`button`/`button-primary`).
	 * @return void
	 */
	private static function render_action_form( int $product_id, string $action, string $label, string $css_class ): void {
		printf( '<form method="post" action="%s" style="display:inline-block">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="product_id" value="%d">', $product_id );
		wp_nonce_field( self::nonce_action( $action, $product_id ) );
		printf( '<button type="submit" class="button %1$s">%2$s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * Waliduje kształt wartości odczytanej z transientu podglądu (obrona przed
	 * uszkodzonym/wygasłym wpisem).
	 *
	 * @param mixed $pending Wartość z `get_transient()`.
	 * @return bool
	 * @phpstan-assert-if-true array{opis: string, specyfikacja: array<int, array{etykieta: string, wartosc: string}>} $pending
	 */
	private static function is_pending_shape( $pending ): bool {
		return is_array( $pending )
			&& isset( $pending['opis'], $pending['specyfikacja'] )
			&& is_string( $pending['opis'] )
			&& is_array( $pending['specyfikacja'] );
	}

	/**
	 * Autoryzuje żądanie akcji `admin-post`: capability na WSKAZANYM produkcie +
	 * nonce związany z (akcja, produkt). Kończy żądanie (`wp_die`), gdy
	 * nieautoryzowane.
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

		check_admin_referer( self::nonce_action( $action, $product_id ) );

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
