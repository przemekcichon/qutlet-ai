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
 * Zakres P-7.1 celowo wąski: sam wrapper wołania + feature-detection +
 * konwersja błędów. Orkiestracja surowe→AI→przerobione (czytanie
 * `RawLayerMeta`/zapis do `RewrittenFields` z qutlet-core) to P-7.3 — tu jej
 * świadomie nie ma.
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

		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'qutlet_ai_text_generation_unsupported',
				__( 'Żaden skonfigurowany dostawca AI (Settings → Connectors) nie obsługuje generowania tekstu dla tego promptu.', 'qutlet-ai' ),
				array( 'status' => 503 )
			);
		}

		return $builder->generate_text();
	}
}
