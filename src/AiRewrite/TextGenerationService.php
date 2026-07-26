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
 */
final class TextGenerationService {

	/**
	 * Generuje tekst z pojedynczego promptu.
	 *
	 * Kolejność: zbuduj prompt → (opcjonalnie) instrukcja systemowa → (opcjonalnie)
	 * preferencja modelu/dostawcy z ustawienia (P-7.2b) → feature-detection
	 * (`is_supported_for_text_generation()`) → generacja. Błędy dostawcy (sieć,
	 * limity, brak konfiguracji) core zwraca jako `WP_Error` — przekazujemy je
	 * bez zmian, żeby wołający (P-7.3) mógł je pokazać w adminie.
	 *
	 * @param string                   $prompt             Treść promptu (JSON surowego produktu, D-7.G5/D-5.G4, albo dowolny tekst).
	 * @param string|null              $system_instruction Opcjonalna instrukcja systemowa (np. globalny prompt z ustawienia, P-7.2b).
	 * @param string|list<string>|null $model_preference   Opcjonalna preferencja dostawcy/modelu (`using_model_preference()`); jeden identyfikator albo lista w kolejności preferencji.
	 * @return string|\WP_Error Wygenerowany tekst albo błąd (brak wspieranego dostawcy, limit, błąd HTTP).
	 */
	public static function generate_text( string $prompt, ?string $system_instruction = null, $model_preference = null ) {
		$builder = self::build_prompt( $prompt, $system_instruction, $model_preference );

		if ( ! $builder->is_supported_for_text_generation() ) {
			return self::unsupported_error();
		}

		return $builder->generate_text();
	}

	/**
	 * Generuje tekst ustrukturyzowany jako JSON zgodny z podanym schematem
	 * (`as_json_response()`, P-7.3 — specyfikacja etykieta→wartość jako
	 * ustrukturyzowane wyjście zamiast wyłuskiwania z prozy). Zwrot to nadal
	 * `string|WP_Error` — sam JSON (tekst), niedekodowany; dekodowanie i walidacja
	 * kształtu należą do wołającego (`RewriteGenerator`, P-7.3), bo tylko on zna
	 * oczekiwany kontrakt danych.
	 *
	 * @param string                        $prompt             Treść promptu (JSON surowego produktu, D-7.G5/D-5.G4).
	 * @param array<string, mixed>          $schema             Schemat JSON oczekiwanej odpowiedzi (`as_json_response()`).
	 * @param string|null                   $system_instruction Opcjonalna instrukcja systemowa (globalny prompt/nadpisanie, P-7.2b).
	 * @param string|list<string>|null      $model_preference   Opcjonalna preferencja dostawcy/modelu.
	 * @return string|\WP_Error Wygenerowany JSON (jako string) albo błąd.
	 */
	public static function generate_json( string $prompt, array $schema, ?string $system_instruction = null, $model_preference = null ) {
		$builder = self::build_prompt( $prompt, $system_instruction, $model_preference )->as_json_response( $schema );

		if ( ! $builder->is_supported_for_text_generation() ) {
			return self::unsupported_error();
		}

		return $builder->generate_text();
	}

	/**
	 * Wspólny fragment budowy promptu (instrukcja systemowa + preferencja modelu)
	 * dzielony przez {@see self::generate_text()} i {@see self::generate_json()}.
	 *
	 * @param string                   $prompt             Treść promptu.
	 * @param string|null              $system_instruction Opcjonalna instrukcja systemowa.
	 * @param string|list<string>|null $model_preference   Opcjonalna preferencja dostawcy/modelu.
	 * @return \WP_AI_Client_Prompt_Builder
	 */
	private static function build_prompt( string $prompt, ?string $system_instruction, $model_preference ): \WP_AI_Client_Prompt_Builder {
		$builder = wp_ai_client_prompt( $prompt );

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
