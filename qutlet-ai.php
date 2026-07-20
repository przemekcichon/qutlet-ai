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
 * FAZA 0 (bootstrap) = czysty szkielet: brak slice'ów, brak logiki AI, brak
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
	 * TODO (kolejne fazy): tu wpinamy inicjalizację slice'ów AI (AiRewrite/ …)
	 * ładowanych z przestrzeni Qutlet\Ai.
	 *
	 * UWAGA o kolejności (D-G5): WP ładuje wtyczki alfabetycznie, więc
	 * `qutlet-ai` startuje jako PIERWSZY z rodziny Qutlet — przed allegro i
	 * przed core. Sprawdzenie OBECNOŚCI core poniżej jest bezpieczne (stała
	 * `Qutlet\Core\VERSION` powstaje przy ładowaniu pliku core, zanim odpali
	 * jakikolwiek `plugins_loaded`), ale KOLEJNOŚCI callbacków nie gwarantuje.
	 * Realny init slice'ów — które czytają pola/serwisy zarejestrowane przez
	 * core (np. pole „prompt per-produkt" z P-7.2a) — musi wpiąć się na
	 * PÓŹNIEJSZYM priorytecie niż core (core hakuje `plugins_loaded` z domyślnym
	 * 10, więc ai np. priorytet > 10) lub na dedykowanym hooku „core gotowe".
	 */
}

/**
 * Sprawdza obecność twardej zależności AI (D-G5): Qutlet Core.
 *
 * Zależność ai to WYŁĄCZNIE core (nie WooCommerce — Woo jest zależnością core,
 * nie ai). Weryfikujemy OBECNOŚĆ na `plugins_loaded` (kolejność callbacków to
 * osobna sprawa — patrz TODO w `bootstrap()`). Literał wykrycia sprawdzony w
 * realnym kodzie: Qutlet Core definiuje stałą `Qutlet\Core\VERSION` (w
 * `qutlet-core.php`, na poziomie pliku). Test to literał-string — nie wymaga stubów.
 *
 * @return bool True, gdy Qutlet Core jest obecny.
 */
function dependencies_met(): bool {
	return defined( 'Qutlet\\Core\\VERSION' );
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
