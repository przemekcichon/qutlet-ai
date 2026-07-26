<?php
/**
 * Slice AiRewrite — efektywny prompt AI: globalna opcja + override per-produkt (P-7.2b).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

/**
 * Jedno źródło odczytu EFEKTYWNEGO promptu AI (D-7.G4): nadpisanie per produkt
 * (`prompt_ai`, pole ACF rejestrowane przez `qutlet-core`, P-7.2a) ?? globalna
 * opcja (`qutlet_ai_prompt_global`, ta klasa + {@see PromptSettingsPage}, P-7.2b).
 * Literały z `docs/kontrakt-danych.md` §13 — VERBATIM, case-sensitive. Wzorzec 1:1
 * z `Qutlet\Core\Pricing\DiscountRate::effective_percent()`.
 *
 * Konsumentem jest generacja (`qutlet-ai`, P-7.3), która przekazuje wynik jako
 * `$system_instruction` do {@see TextGenerationService::generate_text()}. Puste/
 * brak obu wartości → `null` (NIE pusty string), żeby wołający jawnie pominął
 * `using_system_instruction()` zamiast wysłać do core AI Client pustą instrukcję.
 *
 * Pole `prompt_ai` czytamy przez `get_post_meta()`, nie `get_field()` — ACF
 * przechowuje proste pola tekstowe (textarea) jako zwykłe post meta (§9.2/§13),
 * a `qutlet-ai` nie ma twardej zależności na ACF PRO (ma ją `qutlet-core`, D-G5).
 */
final class PromptSettings {

	/**
	 * Nazwa globalnej opcji promptu — kontrakt §13 (VERBATIM).
	 */
	public const OPTION_NAME = 'qutlet_ai_prompt_global';

	/**
	 * `meta_key` override'u promptu per produkt — kontrakt §13 (VERBATIM). Pole
	 * rejestruje `qutlet-core` (`Qutlet\Core\AiRewrite\PromptOverrideField`,
	 * P-7.2a); `qutlet-ai` tylko czyta ten literał (granica D-7.G6).
	 */
	public const META_OVERRIDE = 'prompt_ai';

	/**
	 * Efektywny prompt AI dla danego produktu.
	 *
	 * Nadpisanie per produkt ma pierwszeństwo; puste (po przycięciu białych
	 * znaków) nadpisanie → globalna opcja; pusta również → `null` (brak
	 * instrukcji systemowej).
	 *
	 * @param int $product_id ID produktu (post ID).
	 * @return string|null Treść promptu albo `null`, gdy nic nie ustawiono.
	 */
	public static function effective_prompt( int $product_id ): ?string {
		$override = get_post_meta( $product_id, self::META_OVERRIDE, true );

		if ( is_string( $override ) && '' !== trim( $override ) ) {
			return $override;
		}

		$global = get_option( self::OPTION_NAME, '' );

		if ( is_string( $global ) && '' !== trim( $global ) ) {
			return $global;
		}

		return null;
	}

	/**
	 * Sanityzuje surową wartość globalnego promptu (formularz ustawień).
	 *
	 * `sanitize_textarea_field()` (rdzeń WP) usuwa znaczniki HTML, ale zachowuje
	 * łamania linii — spójnie z polem per-produkt (ACF `textarea`, plain text,
	 * bez WYSIWYG, §13).
	 *
	 * @param mixed $value Surowa wartość z formularza.
	 * @return string Znormalizowany tekst promptu (może być pusty).
	 */
	public static function sanitize( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_textarea_field( $value );
	}
}
