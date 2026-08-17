<?php
/**
 * Slice AiRewrite — cienki serwis generacji tekstu przez core AI Client (P-7.1).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

/**
 * Wywołuje wbudowany w WordPress 7.0 AI Client (`wp_ai_client_prompt()`, D-7.G3).
 * Provider-agnostyczność (wybór dostawcy/modelu Anthropic/OpenAI/Google) daje
 * core przez Connectors API (Settings → Connectors) — ta klasa NIE zna, NIE
 * wybiera i NIE różnicuje zachowania per dostawca (D-7.G3: nie budujemy własnego
 * interfejsu dostawcy).
 *
 * Zakres celowo wąski: sam wrapper wołania (tekst wolny i — od P-7.3 —
 * ustrukturyzowany JSON) + feature-detection + konwersja błędów. Orkiestracja
 * surowe→AI→przerobione (czytanie `RawLayerMeta`/zapis do `opis`+atrybutów WC z
 * qutlet-core) mieszka w {@see \Qutlet\Ai\AiRewrite\RewriteGenerator} (P-7.3) —
 * tu jej świadomie nie ma.
 *
 * Runtime failover (P-18.2, D-18.7): core AI Client wybiera model/dostawcę
 * DOKŁADNIE RAZ, przed wywołaniem, na podstawie konfiguracji — nigdy na
 * podstawie wyniku wywołania (potwierdzone ground-truthem sesji planistycznej
 * FAZY 18, `docs/plan.md`). Skoro core nie robi failoveru sam, ta klasa buduje
 * WŁASNĄ pętlę ({@see self::with_provider_failover()}): dla jednego kliknięcia
 * „Generuj" próbuje kolejnych dostawców z {@see ProviderPrioritySettings} w
 * zapisanej kolejności priorytetu, łapiąc KAŻDY błąd próby i przechodząc do
 * następnego — wspólna dla {@see \Qutlet\Ai\AiRewrite\RewriteGenerator} i
 * {@see \Qutlet\Ai\AiRewrite\TitleGenerator} (D-18.4), bo obaj wołający
 * przechodzą przez `generate_text()`/`generate_json()` bez zmiany sygnatury.
 */
final class TextGenerationService {

	/**
	 * Generuje tekst z pojedynczego promptu.
	 *
	 * Kolejność: zbuduj prompt → (opcjonalnie) instrukcja systemowa → (opcjonalnie)
	 * preferencja modelu/dostawcy z ustawienia (P-7.2b) → feature-detection
	 * (`is_supported_for_text_generation()`) → generacja. Błędy dostawcy (sieć,
	 * limity, brak konfiguracji) core zwraca jako `WP_Error` — przekazujemy je
	 * bez zmian, żeby wołający (P-7.3) mógł je pokazać w adminie. Cała próba (i
	 * ewentualny failover na kolejnego dostawcę, P-18.2) idzie przez
	 * {@see self::with_provider_failover()}.
	 *
	 * @param string                   $prompt             Treść promptu (JSON surowego produktu, D-7.G5/D-5.G4, albo dowolny tekst).
	 * @param string|null              $system_instruction Opcjonalna instrukcja systemowa (np. globalny prompt z ustawienia, P-7.2b).
	 * @param string|list<string>|null $model_preference   Opcjonalna preferencja dostawcy/modelu (`using_model_preference()`); jeden identyfikator albo lista w kolejności preferencji.
	 * @return string|\WP_Error Wygenerowany tekst albo błąd (brak wspieranego dostawcy, limit, błąd HTTP — WSZYSCY dostawcy z listy priorytetu zawiedli).
	 */
	public static function generate_text( string $prompt, ?string $system_instruction = null, $model_preference = null ) {
		return self::with_provider_failover(
			static function ( ?string $provider_id ) use ( $prompt, $system_instruction, $model_preference ) {
				$builder = self::build_prompt( $prompt, $system_instruction, $model_preference, $provider_id );

				if ( ! $builder->is_supported_for_text_generation() ) {
					return self::unsupported_error();
				}

				return $builder->generate_text();
			}
		);
	}

	/**
	 * Generuje tekst ustrukturyzowany jako JSON zgodny z podanym schematem
	 * (`as_json_response()`, P-7.3 — specyfikacja etykieta→wartość jako
	 * ustrukturyzowane wyjście zamiast wyłuskiwania z prozy). Zwrot to nadal
	 * `string|WP_Error` — sam JSON (tekst), niedekodowany; dekodowanie i walidacja
	 * kształtu należą do wołającego (`RewriteGenerator`, P-7.3), bo tylko on zna
	 * oczekiwany kontrakt danych. Failover na kolejnego dostawcę (P-18.2) — jak
	 * w {@see self::generate_text()}.
	 *
	 * @param string                        $prompt             Treść promptu (JSON surowego produktu, D-7.G5/D-5.G4).
	 * @param array<string, mixed>          $schema             Schemat JSON oczekiwanej odpowiedzi (`as_json_response()`).
	 * @param string|null                   $system_instruction Opcjonalna instrukcja systemowa (globalny prompt/nadpisanie, P-7.2b).
	 * @param string|list<string>|null      $model_preference   Opcjonalna preferencja dostawcy/modelu.
	 * @return string|\WP_Error Wygenerowany JSON (jako string) albo błąd (WSZYSCY dostawcy z listy priorytetu zawiedli).
	 */
	public static function generate_json( string $prompt, array $schema, ?string $system_instruction = null, $model_preference = null ) {
		return self::with_provider_failover(
			static function ( ?string $provider_id ) use ( $prompt, $schema, $system_instruction, $model_preference ) {
				$builder = self::build_prompt( $prompt, $system_instruction, $model_preference, $provider_id )->as_json_response( $schema );

				if ( ! $builder->is_supported_for_text_generation() ) {
					return self::unsupported_error();
				}

				return $builder->generate_text();
			}
		);
	}

	/**
	 * Runtime failover (P-18.2, D-18.7): próbuje KAŻDEGO dostawcy z zapisanej
	 * listy priorytetów ({@see ProviderPrioritySettings::ordered_configured_provider_ids()}),
	 * w kolejności, aż jedna próba się powiedzie (`! is_wp_error()`) — łapie
	 * KAŻDY błąd próby (nie tylko 429/5xx, decyzja użytkownika D-18.7) i
	 * przechodzi do następnego dostawcy. Błąd wraca do wołającego wyłącznie, gdy
	 * WSZYSCY dostawcy z listy zawiodą (ostatni błąd).
	 *
	 * Pusta lista (żaden dostawca skonfigurowany albo ustawienie nigdy
	 * niezapisane) → JEDNA próba bez wskazanego dostawcy (`$provider_id = null`)
	 * — dzisiejsze zachowanie AI Client (pierwszy skonfigurowany dostawca wg
	 * kolejności rejestracji pluginów, `ProviderRegistry::findModelsMetadataForSupport()`).
	 *
	 * @param callable(?string): (string|\WP_Error) $attempt Wykonuje jedną próbę generacji dla podanego ID dostawcy (`null` = brak wskazania, domyślne zachowanie AI Client).
	 * @return string|\WP_Error
	 */
	private static function with_provider_failover( callable $attempt ) {
		$provider_ids = ProviderPrioritySettings::ordered_configured_provider_ids();

		if ( array() === $provider_ids ) {
			return $attempt( null );
		}

		$last_error = null;

		foreach ( $provider_ids as $provider_id ) {
			$result = $attempt( $provider_id );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;
		}

		return $last_error;
	}

	/**
	 * Wspólny fragment budowy promptu (dostawca + instrukcja systemowa +
	 * preferencja modelu) dzielony przez {@see self::generate_text()} i
	 * {@see self::generate_json()}.
	 *
	 * @param string                   $prompt             Treść promptu.
	 * @param string|null              $system_instruction Opcjonalna instrukcja systemowa.
	 * @param string|list<string>|null $model_preference   Opcjonalna preferencja dostawcy/modelu.
	 * @param string|null              $provider_id        Opcjonalne wskazanie KONKRETNEGO dostawcy na tę próbę (`using_provider()`, P-18.2 failover) — `null` = brak wskazania, AI Client wybiera sam.
	 * @return \WP_AI_Client_Prompt_Builder
	 */
	private static function build_prompt( string $prompt, ?string $system_instruction, $model_preference, ?string $provider_id = null ): \WP_AI_Client_Prompt_Builder {
		$builder = wp_ai_client_prompt( $prompt );

		if ( null !== $provider_id ) {
			$builder = $builder->using_provider( $provider_id );
		}

		if ( null !== $system_instruction ) {
			$builder = $builder->using_system_instruction( $system_instruction );
		}

		if ( null !== $model_preference ) {
			$preferences = (array) $model_preference;

			// Pusta lista wywołałaby `using_model_preference()` bez argumentów, co SDK
			// zgłasza jako InvalidArgumentException — builder złapałby ten wyjątek jako
			// WP_Error, ale poniższy feature-detection zwróciłby zamiast niego mylący,
			// generyczny komunikat „unsupported". Pusta lista == brak preferencji.
			if ( array() !== $preferences ) {
				$builder = $builder->using_model_preference( ...$preferences );
			}
		}

		return $builder;
	}

	/**
	 * Błąd wspólny dla obu metod generujących, gdy żaden skonfigurowany dostawca
	 * nie obsługuje generowania tekstu dla danego promptu.
	 *
	 * @return \WP_Error
	 */
	private static function unsupported_error(): \WP_Error {
		return new \WP_Error(
			'qutlet_ai_text_generation_unsupported',
			__( 'Żaden skonfigurowany dostawca AI (Settings → Connectors) nie obsługuje generowania tekstu dla tego promptu.', 'qutlet-ai' ),
			array( 'status' => 503 )
		);
	}
}
