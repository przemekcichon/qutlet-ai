<?php
/**
 * Stuby PHPStan dla core AI Client (WordPress 7.0, P-7.1).
 *
 * `php-stubs/wordpress-stubs` (via `szepeviktor/phpstan-wordpress: ^2.0`) jest
 * przypięty do `^6.6.2` — nie zna jeszcze symboli WP 7.0. Ten plik NIE jest
 * ładowany w runtime (WordPress dostarcza te symbole naprawdę); służy WYŁĄCZNIE
 * do analizy statycznej — dopięty przez `scanFiles` w `phpstan.neon`.
 *
 * Sygnatury skopiowane 1:1 z realnego WP 7.0.2 (zweryfikowane w P-7.1):
 * `wp-includes/ai-client.php` i
 * `wp-includes/ai-client/class-wp-ai-client-prompt-builder.php`. Ograniczone do
 * metod faktycznie używanych w `TextGenerationService` (D-7.G3 — cienki serwis,
 * bez własnego interfejsu dostawcy) — realna klasa ma znacznie więcej metod
 * fluent, patrz plik źródłowy.
 *
 * TODO: usunąć ten plik i wpis w `scanFiles`, gdy `szepeviktor/phpstan-wordpress`
 * podniesie ograniczenie na `php-stubs/wordpress-stubs` do `^7.0`.
 */

/**
 * @method self using_system_instruction(string $systemInstruction) Sets the system instruction.
 * @method self using_model_preference(...$preferredModels) Sets preferred models to evaluate in order.
 * @method bool is_supported_for_text_generation() Checks if the prompt is supported for text generation.
 * @method string|WP_Error generate_text() Generates text from the prompt.
 */
class WP_AI_Client_Prompt_Builder {}

/**
 * @param string|null $prompt Optional. Initial prompt content.
 * @return WP_AI_Client_Prompt_Builder
 */
function wp_ai_client_prompt( $prompt = null ) {}
