<?php
/**
 * Slice AiRewrite — generacja tytułu/podnazwy z oryginalnej nazwy Allegro (P-13.2c).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\ProductInfo\RawLayerMeta;

/**
 * Generuje PRZERÓBKĘ nazwy produktu: oczyszczony tytuł + (opcjonalnie) podnazwa,
 * z oryginalnej nazwy Allegro (`RawLayerMeta::META_NAME_RAW`). Instrukcja
 * systemowa jest OSOBNA od {@see RewriteGenerator} (opis) — zadanie
 * jest algorytmiczne (te same reguły za każdym razem: kapitaliki, fragmenty
 * niezwiązane z produktem, próg długości), nie stylistyczne jak ton opisu, więc
 * świadomie NIE korzysta z {@see PromptSettings} (globalny prompt/override per
 * produkt tamtego mechanizmu dotyczy opisu — mieszanie dwóch zadań pod jednym
 * ustawieniem dawałoby nieprzewidywalne wyniki; decyzja sesji realizującej
 * P-13.2c, 2026-08-08).
 *
 * Zapis wyniku ({@see TitleWriter::accept()}) dzieje się BEZPOŚREDNIO z akcji
 * AJAX ({@see TitleGenerationMetaBox}) — bez etapu podglądu w transiencie jak w
 * {@see RewriteGenerator}/{@see RewriteWriter} (decyzja użytkownika, sesja
 * realizująca P-13.2c: AJAX bez przeładowania i tak daje szybki „undo" przez
 * przycisk Reset, więc osobny krok akceptacji jest zbędny).
 */
final class TitleGenerator {

	/**
	 * Nazwa opcji globalnego promptu nazwy — kontrakt §13 (VERBATIM, D-20.G1,
	 * FAZA 20/P-20.1).
	 */
	public const OPTION_NAME = 'qutlet_ai_prompt_title_global';

	/**
	 * Instrukcja systemowa dla modelu — DOMYŚLNA wartość opcji {@see OPTION_NAME}
	 * (D-20.1: dopóki administrator nie zapisze pola „Globalny prompt nazwy
	 * produktu" na {@see PromptSettingsPage}, generacja zachowuje się identycznie
	 * jak przed FAZĄ 20). `public` od FAZY 20 (wcześniej `private`), żeby
	 * `PromptSettingsPage` mogła się do niej odwołać jako `default` bez
	 * duplikowania tekstu jako osobny literał.
	 */
	public const SYSTEM_INSTRUCTION = <<<'PROMPT'
Jesteś asystentem redagującym tytuły produktów w sklepie internetowym Qutlet
(elektronika outletowa). Dostajesz oryginalną nazwę oferty z Allegro — często
zapisaną KAPITALIKAMI, z fragmentami niezwiązanymi z samym produktem.

Zadanie:
1. Usuń zapis kapitalikami — zastosuj naturalną polską pisownię (wielka litera
   na początku, reszta mała), zachowując nazwy własne/marki/modele w formie, w
   jakiej zwykle się je zapisuje.
2. Usuń fragmenty niezwiązane z samym produktem (np. „brak opakowania",
   „ekspozycja", informacje o stanie/gwarancji, numery katalogowe sprzedawcy) —
   zostaw tylko to, co identyfikuje produkt (marka, model, kluczowe cechy).
3. Jeśli oczyszczona nazwa jest zbyt długa jako pojedynczy tytuł (orientacyjnie
   ponad ok. 60 znaków), rozbij ją na główny tytuł („tytul") i drugą linię
   („podnazwa") z pozostałymi, mniej istotnymi szczegółami (np. wariant koloru,
   pojemność, numer wersji). Sam zdecyduj, GDZIE podzielić — najważniejsze
   informacje (marka, model) zawsze zostają w „tytul".
4. Jeśli oczyszczona nazwa mieści się w jednej krótkiej linii, „podnazwa" zwróć
   jako pusty string.

Zwróć WYŁĄCZNIE JSON zgodny z podanym schematem — bez dodatkowych komentarzy.
PROMPT;

	/**
	 * Generuje przeróbkę nazwy dla produktu.
	 *
	 * @param int $product_id ID produktu (post ID).
	 * @return array{tytul: string, podnazwa: string}|\WP_Error
	 */
	public static function generate( int $product_id ) {
		$raw_name = get_post_meta( $product_id, RawLayerMeta::META_NAME_RAW, true );

		if ( ! is_string( $raw_name ) || '' === trim( $raw_name ) ) {
			return new \WP_Error(
				'qutlet_ai_missing_raw_name',
				__( 'Produkt nie ma zapisanej oryginalnej nazwy Allegro (brak materiału wejściowego do przeróbki) — utworzony ręcznie albo jeszcze nie zsynchronizowany.', 'qutlet-ai' ),
				array( 'status' => 422 )
			);
		}

		$system_instruction = get_option( self::OPTION_NAME, self::SYSTEM_INSTRUCTION );
		$system_instruction = is_string( $system_instruction ) ? $system_instruction : self::SYSTEM_INSTRUCTION;

		$response = TextGenerationService::generate_json(
			$raw_name,
			self::response_schema(),
			$system_instruction
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = self::decode_response( $response );

		if ( null === $parsed ) {
			return new \WP_Error(
				'qutlet_ai_malformed_response',
				__( 'Odpowiedź AI nie odpowiada oczekiwanemu kształtowi (pola „tytul" i „podnazwa").', 'qutlet-ai' ),
				array( 'status' => 502 )
			);
		}

		return $parsed;
	}

	/**
	 * Dekoduje i waliduje kształt odpowiedzi JSON od AI — czysta funkcja (bez
	 * WordPressa), pokryta testami. Sanityzacja WP-owa (`sanitize_text_field`)
	 * należy do momentu ZAPISU ({@see TitleWriter::accept()}), nie podglądu.
	 *
	 * `tytul` musi być niepustym stringiem (po przycięciu białych znaków) — pusty
	 * tytuł jest bezużyteczny, produkt musi mieć jakąś nazwę. `podnazwa` może być
	 * pustym stringiem (oznacza „nie dzielić").
	 *
	 * @param string $json Surowa odpowiedź AI (JSON — wymuszony `as_json_response()`).
	 * @return array{tytul: string, podnazwa: string}|null Null, gdy kształt się nie zgadza.
	 */
	public static function decode_response( string $json ): ?array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['tytul'], $decoded['podnazwa'] )
			|| ! is_string( $decoded['tytul'] )
			|| ! is_string( $decoded['podnazwa'] ) ) {
			return null;
		}

		$title = trim( $decoded['tytul'] );

		if ( '' === $title ) {
			return null;
		}

		return array(
			'tytul'    => $title,
			'podnazwa' => trim( $decoded['podnazwa'] ),
		);
	}

	/**
	 * Schemat JSON oczekiwanej odpowiedzi (`as_json_response()`).
	 *
	 * @return array<string, mixed>
	 */
	private static function response_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'tytul'    => array( 'type' => 'string' ),
				'podnazwa' => array( 'type' => 'string' ),
			),
			'required'             => array( 'tytul', 'podnazwa' ),
			'additionalProperties' => false,
		);
	}
}
