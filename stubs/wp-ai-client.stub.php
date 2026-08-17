<?php
/**
 * Stuby PHPStan dla core AI Client (WordPress 7.0, P-7.1) + PHP AI Client SDK
 * (P-18.2 — `WordPress\AiClient\AiClient`/`ProviderRegistry`, potrzebne
 * `ProviderPrioritySettings` do dynamicznego odczytu zarejestrowanych/
 * skonfigurowanych dostawców, D-18.6).
 *
 * `php-stubs/wordpress-stubs` (via `szepeviktor/phpstan-wordpress: ^2.0`) jest
 * przypięty do `^6.6.2` — nie zna jeszcze symboli WP 7.0. Ten plik NIE jest
 * ładowany w runtime (WordPress dostarcza te symbole naprawdę); służy WYŁĄCZNIE
 * do analizy statycznej — dopięty przez `scanFiles` w `phpstan.neon`.
 *
 * Sygnatury skopiowane 1:1 z realnego WP 7.0.2 (zweryfikowane w P-7.1/P-7.3/P-18.2):
 * `wp-includes/ai-client.php`,
 * `wp-includes/ai-client/class-wp-ai-client-prompt-builder.php`,
 * `wp-includes/php-ai-client/src/AiClient.php`,
 * `wp-includes/php-ai-client/src/Providers/ProviderRegistry.php`. Ograniczone do
 * metod faktycznie używanych w `TextGenerationService`/`ProviderPrioritySettings`
 * (D-7.G3 — cienki serwis, bez własnego interfejsu dostawcy) — realne klasy mają
 * znacznie więcej metod, patrz pliki źródłowe.
 *
 * TODO: usunąć ten plik i wpis w `scanFiles`, gdy `szepeviktor/phpstan-wordpress`
 * podniesie ograniczenie na `php-stubs/wordpress-stubs` do `^7.0`.
 */

// Blok bez nazwy (D-18.2, wymóg PHP): plik miesza wiele namespace'ów (globalny +
// `WordPress\AiClient*` niżej), więc WSZYSTKIE deklaracje namespace w pliku muszą
// używać składni z klamrami — "Namespace declaration statement has to be the
// very first statement" przy mieszaniu z bezklamrową składnią.
namespace {

	/**
	 * @method self using_system_instruction(string $systemInstruction) Sets the system instruction.
	 * @method self using_model_preference(...$preferredModels) Sets preferred models to evaluate in order.
	 * @method self using_provider(string $providerIdOrClassName) Sets the provider to use for generation (P-18.2 — runtime failover, D-18.7).
	 * @method self as_json_response(?array $schema = null) Configures the prompt for JSON response output (P-7.3 — structured specyfikacja).
	 * @method bool is_supported_for_text_generation() Checks if the prompt is supported for text generation.
	 * @method string|WP_Error generate_text() Generates text from the prompt.
	 */
	class WP_AI_Client_Prompt_Builder {}

	/**
	 * @param string|null $prompt Optional. Initial prompt content.
	 * @return WP_AI_Client_Prompt_Builder
	 */
	function wp_ai_client_prompt( $prompt = null ) {}
}

namespace WordPress\AiClient {

	/**
	 * Stub ograniczony do metody używanej w `ProviderPrioritySettings` (P-18.2).
	 */
	class AiClient {
		public static function defaultRegistry(): \WordPress\AiClient\Providers\ProviderRegistry {}
	}
}

namespace WordPress\AiClient\Providers {

	/**
	 * Stub ograniczony do metod używanych w `ProviderPrioritySettings` (P-18.2,
	 * D-18.6) — realna klasa ma znacznie więcej metod, patrz plik źródłowy.
	 */
	class ProviderRegistry {
		/**
		 * @return list<string>
		 */
		public function getRegisteredProviderIds(): array {}

		public function isProviderConfigured( string $idOrClassName ): bool {}
	}
}
