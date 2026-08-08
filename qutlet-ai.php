<?php
/**
 * Plugin Name:       Qutlet AI
 * Plugin URI:        https://github.com/przemekcichon/qutlet-ai
 * Description:       Provider-agnostyczna przeróbka opisów produktów: czyta warstwę surową (dane z Allegro), generuje warstwę przerobioną (user-facing). Zależny od Qutlet Core (model danych). Klucze API dostawców AI w wp-config.php.
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Requires at least: 6.4
 * Author:            Qutlet
 * Text Domain:       qutlet-ai
 * License:           proprietary
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai;

// Blokada bezpośredniego wywołania pliku poza WordPressem.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wersja wtyczki (jedno źródło prawdy — używać zamiast literału).
 */
const VERSION = '0.1.0';

/**
 * Ścieżka głównego pliku wtyczki — jedno źródło prawdy dla `plugins_url()`/
 * `plugin_dir_path()` (P-13.2c: enqueue JS generatora tytułu).
 */
const PLUGIN_FILE = __FILE__;

/*
 * Autoloader Composera (D-G1): ładowany z guardem. Brak `vendor/autoload.php`
 * NIE jest fatal errorem — pokazujemy notice w adminie i przerywamy bootstrap,
 * żeby nie wywrócić całego WordPressa.
 */
$qutlet_ai_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $qutlet_ai_autoload ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );

	return;
}

require_once $qutlet_ai_autoload;

// Slice'y AI uruchamiamy dopiero, gdy twarde zależności są obecne (D-G5).
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Punkt wejścia wtyczki. Uruchamiany na `plugins_loaded`.
 *
 * Bootstrap (P-7.0) = czysty szkielet: brak slice'ów, brak logiki AI, brak
 * rejestracji pól (D-7.G6 — pola ACF/CPT rejestruje wyłącznie qutlet-core).
 * Weryfikujemy tu wyłącznie OBECNOŚĆ twardej zależności i przy braku robimy
 * no-op + notice.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! dependencies_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependencies_notice' );

		return; // No-op: bez Qutlet Core wtyczka AI niczego nie rejestruje.
	}

	/*
	 * UWAGA o kolejności (D-G5): WP ładuje wtyczki alfabetycznie, więc
	 * `qutlet-ai` startuje jako PIERWSZY z rodziny Qutlet — przed allegro i
	 * przed core. Sprawdzenie OBECNOŚCI core poniżej jest bezpieczne (stała
	 * `Qutlet\Core\VERSION` powstaje przy ładowaniu pliku core, zanim odpali
	 * jakikolwiek `plugins_loaded`), ale KOLEJNOŚCI callbacków nie gwarantuje.
	 * Slice'y poniżej wpinają hooki PÓŹNIEJSZE niż `plugins_loaded`
	 * (`admin_menu`/`admin_init`) i nie czytają nic zarejestrowanego przez core
	 * na `plugins_loaded`, więc kolejność callbacków między ai a core nie jest
	 * tu krytyczna.
	 */

	// AiRewrite (P-7.2b): strona ustawień globalnego promptu AI (opcja
	// `qutlet_ai_prompt_global`). Efektywny prompt (override per-produkt z
	// P-7.2a ?? ta opcja) czyta `PromptSettings::effective_prompt()` — wołany
	// przez generację (P-7.3) poniżej.
	AiRewrite\PromptSettingsPage::init();

	// AiRewrite (P-7.3): metabox generacji (generuj/podgląd/zaakceptuj) na
	// edycji produktu + orkiestracja surowe→AI→przerobione.
	AiRewrite\GenerationMetaBox::init();

	// AiRewrite (P-13.2c): metabox generatora tytułu/podnazwy (AJAX, zapis
	// bezpośredni) + Reset do oryginalnej nazwy Allegro — D-13.G2 (świadomie
	// AJAX, inny mechanizm niż generator opisu powyżej).
	AiRewrite\TitleGenerationMetaBox::init();
}

/**
 * Sprawdza obecność twardych zależności AI (D-G5): Qutlet Core + WooCommerce.
 *
 * Do P-7.2b zależność ai była WYŁĄCZNIE core (Woo była zależnością core, nie
 * ai — czytana tylko pośrednio). Od P-7.3 `AiRewrite\RewriteWriter`/
 * `GenerationMetaBox` wołają funkcje/klasy WooCommerce BEZPOŚREDNIO
 * (`wc_get_product()`, `WC_Product_Attribute`), więc guard dopisuje
 * `class_exists('WooCommerce')` — wzorzec 1:1 z `qutlet-allegro`
 * (`dependencies_met()` tam sprawdza to samo z tego samego powodu). Weryfikujemy
 * OBECNOŚĆ na `plugins_loaded` (kolejność callbacków to osobna sprawa — patrz
 * TODO w `bootstrap()`). Literał wykrycia core sprawdzony w realnym kodzie:
 * Qutlet Core definiuje stałą `Qutlet\Core\VERSION` (w `qutlet-core.php`, na
 * poziomie pliku). Test to literały — nie wymaga stubów.
 *
 * @return bool True, gdy Qutlet Core i WooCommerce są obecne.
 */
function dependencies_met(): bool {
	return defined( 'Qutlet\\Core\\VERSION' ) && class_exists( 'WooCommerce' );
}

/**
 * Notice w adminie: brak autoloadera Composera.
 *
 * @return void
 */
function render_missing_autoloader_notice(): void {
	$message = __(
		'Qutlet AI: brak autoloadera Composera (vendor/autoload.php). Uruchom „composer install" w katalogu wtyczki.',
		'qutlet-ai'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Notice w adminie: brak twardej zależności (Qutlet Core).
 *
 * @return void
 */
function render_missing_dependencies_notice(): void {
	$message = __(
		'Qutlet AI wymaga aktywnej wtyczki Qutlet Core. Do czasu jej aktywacji wtyczka nie robi nic.',
		'qutlet-ai'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
