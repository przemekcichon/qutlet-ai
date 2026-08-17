<?php
/**
 * Slice AiRewrite — globalna kolejność priorytetów dostawców AI (P-18.2).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use WordPress\AiClient\AiClient;

/**
 * Odczyt/zapis zapisanej kolejności priorytetów dostawców AI Client
 * (`qutlet_ai_provider_priority`, kontrakt §13/D-18.G1) — konsument to WŁASNA
 * pętla runtime failover w {@see TextGenerationService} (D-18.7): próbuje
 * kolejnych dostawców z listy na KAŻDY błąd generacji, w jednym kliknięciu
 * „Generuj".
 *
 * UI (`qutlet-ai`) i pętla failover NIE czytają opcji bezpośrednio przez
 * `get_option()` — obie przechodzą przez {@see self::ordered_configured_provider_ids()},
 * żeby dostawca usunięty z konfiguracji (klucz API zdjęty z `wp-config.php` PO
 * zapisaniu priorytetu) nigdy nie trafił do próby wywołania: filtrujemy do
 * dostawców, dla których `AiClient::defaultRegistry()->isProviderConfigured()`
 * zwraca `true` W DANYM MOMENCIE (D-18.6, zaakceptowane ryzyko: `ProviderRegistry`
 * nie jest udokumentowanym, stabilnym API dla pluginów).
 */
final class ProviderPrioritySettings {

	/**
	 * Nazwa globalnej opcji kolejności priorytetów — kontrakt §13 (VERBATIM).
	 */
	public const OPTION_NAME = 'qutlet_ai_provider_priority';

	/**
	 * Zapisana kolejność priorytetów, przefiltrowana do dostawców AKTUALNIE
	 * skonfigurowanych (D-18.6). Pusta zapisana lista (albo lista, z której po
	 * filtrze nic nie zostało — wszyscy przestali być skonfigurowani) → pusta
	 * tablica: wołający ({@see TextGenerationService}) interpretuje to jako
	 * „brak preferencji", fallback na dzisiejsze zachowanie AI Client
	 * (pierwszy skonfigurowany dostawca wg kolejności rejestracji pluginów).
	 *
	 * @return list<string>
	 */
	public static function ordered_configured_provider_ids(): array {
		return self::filter_to_known( self::saved_provider_ids(), self::available_provider_ids() );
	}

	/**
	 * Kolejność do wyświetlenia w UI ustawień: zapisana kolejność (przefiltrowana
	 * do aktualnie skonfigurowanych) + dowolni NOWO skonfigurowani dostawcy
	 * (jeszcze nieobecni w zapisanej liście) dopisani na końcu — administrator
	 * widzi WSZYSTKICH aktualnie skonfigurowanych dostawców, nawet przy pierwszym
	 * otwarciu strony (zapisana lista jeszcze pusta).
	 *
	 * @return list<string>
	 */
	public static function display_order(): array {
		$ordered = self::ordered_configured_provider_ids();

		foreach ( self::available_provider_ids() as $provider_id ) {
			if ( ! in_array( $provider_id, $ordered, true ) ) {
				$ordered[] = $provider_id;
			}
		}

		return $ordered;
	}

	/**
	 * Dostawcy zarejestrowani w AI Client (`ProviderRegistry`, D-18.6) i
	 * AKTUALNIE skonfigurowani (mają klucz API) — dynamicznie, nie sztywna lista
	 * w kodzie, mimo zaakceptowanego ryzyka niestabilności tego API rdzenia WP.
	 *
	 * @return list<string>
	 */
	public static function available_provider_ids(): array {
		$registry = AiClient::defaultRegistry();

		$configured = array();

		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			if ( $registry->isProviderConfigured( $provider_id ) ) {
				$configured[] = $provider_id;
			}
		}

		return $configured;
	}

	/**
	 * Surowa zapisana opcja (bez filtrowania) — WYŁĄCZNIE do wewnętrznego użycia
	 * tej klasy; wołający zewnętrzny ma czytać {@see self::ordered_configured_provider_ids()}.
	 *
	 * @return array<mixed>
	 */
	private static function saved_provider_ids(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Przecięcie zapisanej listy ze zbiorem znanych/dostępnych ID, zachowujące
	 * kolejność zapisanej listy i odrzucające duplikaty — czysta funkcja (bez
	 * WordPressa), pokryta testami.
	 *
	 * @param array<mixed>  $saved Zapisana kolejność (surowa, może nieść nieznane/nieaktualne ID).
	 * @param list<string>  $known Zbiór ID uznawanych za aktualnie dostępne.
	 * @return list<string>
	 */
	public static function filter_to_known( array $saved, array $known ): array {
		$known_lookup = array_flip( $known );
		$result       = array();

		foreach ( $saved as $provider_id ) {
			if ( is_string( $provider_id ) && isset( $known_lookup[ $provider_id ] ) && ! in_array( $provider_id, $result, true ) ) {
				$result[] = $provider_id;
			}
		}

		return $result;
	}

	/**
	 * Sanityzuje surową wartość z formularza ustawień: `$_POST` niesie mapę
	 * `ID dostawcy => ranga` (numerowane selecty, D-18.2/P-18.2 — kształt UI do
	 * ustalenia przy realizacji, wybrany bez JS, konsystentnie z resztą strony
	 * ustawień), tu sortujemy WEDŁUG RANGI do listy w kolejności priorytetu.
	 * Sortowanie stabilne własnoręcznie (decorate-sort-undecorate) — projekt
	 * wspiera PHP 7.4, gdzie `usort()`/`asort()` NIE są jeszcze stabilne
	 * (stabilność dopiero od PHP 8.0) — remisy rang muszą zachować kolejność
	 * przesłania formularza, nie przypadkową kolejność wewnętrzną sortowania.
	 *
	 * Czysta funkcja (bez WordPressa) — pokryta testami.
	 *
	 * @param mixed $value Surowa wartość z formularza (`array<string, mixed>` — ID dostawcy => ranga).
	 * @return list<string>
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$entries = array();
		$index   = 0;

		foreach ( $value as $provider_id => $rank ) {
			if ( is_string( $provider_id ) && '' !== $provider_id ) {
				$entries[] = array( $index++, is_numeric( $rank ) ? (int) $rank : 0, $provider_id );
			}
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a[1] <=> $b[1] ?: $a[0] <=> $b[0];
			}
		);

		$ordered = array();

		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry[2], $ordered, true ) ) {
				$ordered[] = $entry[2];
			}
		}

		return $ordered;
	}
}
