<?php
/**
 * Slice AiRewrite — metabox generatora tytułu/podnazwy: AJAX generuj/reset (P-13.2c).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\ProductInfo\RawLayerMeta;
use Qutlet\Core\ProductInfo\RewrittenFields;
use WP_Post;

/**
 * Metabox tuż pod tytułem ekranu edycji produktu (kontekst `acf_after_title`,
 * pełna szerokość głównej kolumny — patrz {@see self::register()}) —
 * scalony punkt edycji nazwy produktu (P-20.4b, D-20.3): natywny tytuł wpisu
 * (`#titlediv`, przeniesiony tu fizycznie przez JS,
 * {@see self::enqueue_script()}), „Druga linia nazwy produktu"
 * ({@see RewrittenFields::render_field()}, `qutlet-core`), oryginalna nazwa
 * Allegro (verbatim — jedyne miejsce w adminie, gdzie jest dziś widoczna,
 * patrz niżej) i dwa przyciski, „Generuj" i „Reset", oba wołające AJAX.
 *
 * Świadoma niekonsystencja z {@see GenerationMetaBox} (opis) — D-13.G2. Obie
 * klasy są dziś `wp_ajax_*` (P-17.1 przeniosło też opis na AJAX) — różnica NIE
 * jest już w transporcie, tylko w modelu bezpieczeństwa: tu zapis BEZPOŚREDNI
 * (bez transientu/etapu akceptacji — decyzja użytkownika, sesja realizująca
 * P-13.2c: AJAX bez przeładowania daje szybki „undo" przez „Reset", więc
 * osobny krok akceptacji byłby zbędny), tam trójstopniowy podgląd→akceptuj/
 * odrzuć. Zabezpieczenie zastępcze za brak kroku akceptacji: `window.confirm()`
 * w JS PRZED wysłaniem żądania — patrz `assets/js/title-generator.js` — plus
 * nonce + capability w handlerze. Od P-20.4b (D-20.4) potwierdzenie zostaje
 * WYŁĄCZNIE dla „Reset" — „Generuj" wysyła żądanie od razu.
 *
 * Domyka też lukę zasygnalizowaną przy P-5.3 (`RawLayerMetaBox`, `qutlet-core`):
 * ten panel powstał PRZED P-13.2 i nie pokazuje nazwy — `RawLayerMeta::META_NAME_RAW`
 * nie było jeszcze wtedy widoczne NIGDZIE w adminie. Ten metabox (`render()`
 * niżej) pokazuje ją wprost, więc osobny punkt w core na samo pokazanie nazwy
 * nie jest potrzebny (decyzja użytkownika, sesja 2026-08-08).
 */
final class TitleGenerationMetaBox {

	/**
	 * Ekran (typ posta), na którym pokazujemy metabox — produkt WooCommerce.
	 */
	private const SCREEN = 'product';

	/**
	 * Identyfikator metaboxa (unikalny w obrębie ekranu).
	 */
	private const META_BOX_ID = 'qutlet_ai_title_generator';

	/**
	 * Nazwa akcji `wp_ajax_*` generującej i zapisującej tytuł/podnazwę.
	 */
	private const GENERATE_ACTION = 'qutlet_ai_generate_title';

	/**
	 * Nazwa akcji `wp_ajax_*` przywracającej oryginalną nazwę Allegro.
	 */
	private const RESET_ACTION = 'qutlet_ai_reset_title';

	/**
	 * Capability wymagana do akcji — meta-capability WP dla EDYCJI TEGO produktu
	 * (wzorzec {@see GenerationMetaBox::CAPABILITY}).
	 */
	private const CAPABILITY = 'edit_post';

	/**
	 * Uchwyt (handle) skryptu JS obsługującego przyciski metaboxa.
	 */
	private const SCRIPT_HANDLE = 'qutlet-ai-title-generator';

	/**
	 * Uchwyt (handle) skryptu JS przenoszącego `#titlediv` do wnętrza metaboxa
	 * (P-20.4b, D-20.3) — osobny plik/handle od {@see self::SCRIPT_HANDLE},
	 * bez zależności na config AJAX-a (nonce/productId).
	 */
	private const TITLEDIV_SCRIPT_HANDLE = 'qutlet-ai-title-metabox-layout';

	/**
	 * Wpina rejestrację metaboxa, enqueue skryptu i handlery AJAX. Wołane z
	 * bootstrapu `qutlet-ai` (na `plugins_loaded`, po sprawdzeniu twardej
	 * zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
		add_action( 'wp_ajax_' . self::GENERATE_ACTION, array( self::class, 'handle_generate' ) );
		add_action( 'wp_ajax_' . self::RESET_ACTION, array( self::class, 'handle_reset' ) );
	}

	/**
	 * Rejestruje metabox tylko dla ekranu edycji produktu, w kontekście
	 * `acf_after_title` — ACF Pro renderuje tam boxy TUŻ POD tytułem/
	 * odnośnikiem, PEŁNEJ szerokości głównej kolumny (`form-post.php::
	 * edit_form_after_title()`, `do_meta_boxes( …, 'acf_after_title', … )`).
	 * Zastępuje wcześniejsze `side` (wąska kolumna) — po scaleniu z
	 * `#titlediv` (P-20.4b) box niesie zbyt dużo treści (tytuł, druga linia
	 * nazwy, przyciski) na wąski `side` box; ten kontekst jest już twardą
	 * zależnością tego repo (ACF Pro, D-G5), więc nie dokłada nowego ryzyka.
	 * Box jest dziś JEDYNYM konsumentem tego kontekstu w projekcie (grep:
	 * żadna grupa ACF core/ai nie rejestruje `position => 'acf_after_title'`).
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
			__( 'Nazwa produktu (AI)', 'qutlet-ai' ),
			array( self::class, 'render' ),
			self::SCREEN,
			'acf_after_title',
			'default'
		);
	}

	/**
	 * Renderuje scalony metabox nazwy produktu (P-20.4b, D-20.3), w tej
	 * kolejności: status (wypełniane przez JS) → kotwica na `#titlediv`
	 * (pusty `<div>` — {@see self::enqueue_script()} przenosi tam natywny
	 * tytuł/odnośnik przy starcie strony) → „Druga linia nazwy produktu"
	 * ({@see RewrittenFields::render_field()}, `qutlet-core`) → [gdy brak
	 * warstwy surowej: komunikat, koniec] → banner „Nowy" (gdy stale) →
	 * oryginalna nazwa Allegro (read-only) → przyciski „Generuj"/„Reset".
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$raw_name = (string) get_post_meta( $post->ID, RawLayerMeta::META_NAME_RAW, true );

		echo '<p data-qutlet-ai-title-status style="margin-top:0"></p>';
		echo '<div data-qutlet-ai-titlediv-anchor></div>';

		RewrittenFields::render_field( $post->ID );

		if ( '' === trim( $raw_name ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Brak oryginalnej nazwy Allegro — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany. Nie ma z czego wygenerować tytułu ani do czego przywracać.', 'qutlet-ai' )
			);

			return;
		}

		$source_raw = (string) get_post_meta( $post->ID, TitleWriter::SOURCE_RAW_META, true );

		if ( self::is_stale( $raw_name, $source_raw, $post->post_title ) ) {
			printf(
				'<p data-qutlet-ai-title-stale style="background:#fcf0f1;border-left:4px solid #d63638;padding:8px 12px;margin:0 0 12px"><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Nowy', 'qutlet-ai' ),
				esc_html__( '— nazwa oferty zmieniła się na Allegro od ostatniej generacji/resetu. Zweryfikuj tytuł i ewentualnie wygeneruj ponownie.', 'qutlet-ai' )
			);
		}

		printf(
			'<p><strong>%1$s</strong><br><span style="word-break:break-word">%2$s</span></p>',
			esc_html__( 'Nazwa oryginalna (Allegro):', 'qutlet-ai' ),
			esc_html( $raw_name )
		);

		echo '<p>';
		printf(
			'<button type="button" class="button button-primary" data-qutlet-ai-title-generate>%s</button> ',
			esc_html__( 'Generuj', 'qutlet-ai' )
		);
		printf(
			'<button type="button" class="button" data-qutlet-ai-title-reset>%s</button>',
			esc_html__( 'Reset', 'qutlet-ai' )
		);
		echo '</p>';
	}

	/**
	 * Ładuje JS obsługi WYŁĄCZNIE na ekranie edycji produktu (nie na liście
	 * produktów ani innych ekranach admina). Dwa niezależne skrypty:
	 * przeniesienie `#titlediv` ({@see self::TITLEDIV_SCRIPT_HANDLE}) ładuje
	 * się ZAWSZE na tym ekranie (też `post-new.php` — box renderuje kotwicę i
	 * {@see RewrittenFields::render_field()} niezależnie od istnienia warstwy
	 * surowej, patrz {@see self::render()}); generator AJAX
	 * ({@see self::SCRIPT_HANDLE}) wymaga istniejącego produktu (nonce/ID).
	 *
	 * @return void
	 */
	public static function enqueue_script(): void {
		$screen = get_current_screen();

		if ( null === $screen || self::SCREEN !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		wp_enqueue_script(
			self::TITLEDIV_SCRIPT_HANDLE,
			plugins_url( 'assets/js/title-metabox-layout.js', \Qutlet\Ai\PLUGIN_FILE ),
			array(),
			\Qutlet\Ai\VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko do zbudowania nonce'a dla TEGO produktu; autoryzację (capability + nonce) i tak wykonuje handler AJAX przy każdym żądaniu.
		$product_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $product_id <= 0 ) {
			return; // post-new.php: produkt jeszcze nie istnieje, nie ma warstwy surowej do wygenerowania/zresetowania.
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/title-generator.js', \Qutlet\Ai\PLUGIN_FILE ),
			array(),
			\Qutlet\Ai\VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'qutletAiTitleGenerator',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'generateAction' => self::GENERATE_ACTION,
				'resetAction'    => self::RESET_ACTION,
				'nonce'          => wp_create_nonce( self::nonce_action( $product_id ) ),
				'productId'      => $product_id,
				'i18n'           => array(
					'confirmReset' => __( 'Przywrócić oryginalną nazwę Allegro? To NADPISZE bieżący tytuł i wyczyści podnazwę.', 'qutlet-ai' ),
					'generating'   => __( 'Generowanie…', 'qutlet-ai' ),
					'resetting'    => __( 'Przywracanie…', 'qutlet-ai' ),
					'genericError' => __( 'Coś poszło nie tak — spróbuj ponownie.', 'qutlet-ai' ),
				),
			)
		);
	}

	/**
	 * Akcja „Generuj": woła generację AI i OD RAZU zapisuje wynik (bez podglądu —
	 * patrz docblock klasy).
	 *
	 * @return void
	 */
	public static function handle_generate(): void {
		$product_id = self::authorized_product_id();

		$result = TitleGenerator::generate( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				self::error_status( $result )
			);
		}

		$saved = TitleWriter::accept( $product_id, $result['tytul'], $result['podnazwa'] );

		if ( null === $saved ) {
			wp_send_json_error(
				array( 'message' => __( 'Zapis nie powiódł się — produkt nie istnieje albo nie jest produktem WooCommerce.', 'qutlet-ai' ) ),
				500
			);
		}

		wp_send_json_success(
			array_merge(
				$saved,
				array( 'message' => __( 'Tytuł i podnazwa wygenerowane i zapisane.', 'qutlet-ai' ) )
			)
		);
	}

	/**
	 * Akcja „Reset": przywraca oryginalną nazwę Allegro jako `post_title` i czyści
	 * `podnazwa`.
	 *
	 * @return void
	 */
	public static function handle_reset(): void {
		$product_id = self::authorized_product_id();

		$saved = TitleWriter::reset( $product_id );

		if ( null === $saved ) {
			wp_send_json_error(
				array( 'message' => __( 'Brak oryginalnej nazwy Allegro do przywrócenia.', 'qutlet-ai' ) ),
				422
			);
		}

		wp_send_json_success(
			array_merge(
				$saved,
				array( 'message' => __( 'Przywrócono oryginalną nazwę Allegro.', 'qutlet-ai' ) )
			)
		);
	}

	/**
	 * Autoryzuje żądanie AJAX: capability na WSKAZANYM produkcie + nonce związany
	 * z (metabox, produkt). Kończy żądanie (`wp_send_json_error()`, który sam
	 * wywołuje `wp_die()`), gdy nieautoryzowane.
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
	 * {@see TitleGenerator::generate()}/{@see RewriteGenerator::generate()}) —
	 * 500, gdy błąd nie niesie statusu.
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
	 * obu akcji (Generuj/Reset): oba operują na tym samym (produkt, capability),
	 * osobne nonce per akcja nie dodałoby ochrony.
	 *
	 * @param int $product_id ID produktu.
	 * @return string
	 */
	private static function nonce_action( int $product_id ): string {
		return 'qutlet_ai_title_' . $product_id;
	}

	/**
	 * Flaga „Nowy" (P-9.1a.2, D-9.1a.1): nazwa Allegro zmieniła się od ostatniej
	 * generacji/resetu (`TitleWriter::SOURCE_RAW_META`) — czysta funkcja, bez WP,
	 * pokryta testami.
	 *
	 * `$source_raw` puste = nic JESZCZE nie wygenerowano/zresetowano dla tego
	 * produktu przez TEN metabox — NIE znaczy „nic się nie zdezaktualizowało"
	 * (recenzja P-9.1a.2, sesja 2026-08-14): produkt mógł zostać utworzony dawno,
	 * a nazwa oferty na Allegro zmienić się od tamtej pory, mimo że nikt nigdy
	 * nie otworzył tego metaboxa. Fallback na tę gałąź: porównanie z bieżącym
	 * `$current_title` (`post_title`) — od P-9.1a.1 sync ustawia `post_title`
	 * WYŁĄCZNIE przy tworzeniu produktu (`qutlet-allegro::ProductWriter`), więc
	 * dopóki nikt nic nie wygenerował/zresetował, `post_title` wciąż jest tą
	 * samą surową nazwą, jaką miała oferta w chwili utworzenia — rozjazd z
	 * bieżącą `$current_raw` oznacza, że oferta zmieniła się PO utworzeniu.
	 *
	 * @param string $current_raw  Bieżąca `RawLayerMeta::META_NAME_RAW`.
	 * @param string $source_raw   Stempel `TitleWriter::SOURCE_RAW_META` (może być pusty).
	 * @param string $current_title Bieżący `post_title` produktu.
	 * @return bool
	 */
	private static function is_stale( string $current_raw, string $source_raw, string $current_title ): bool {
		if ( '' !== $source_raw ) {
			return $source_raw !== $current_raw;
		}

		return $current_title !== $current_raw;
	}
}
