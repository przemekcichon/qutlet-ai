<?php
/**
 * Slice AiRewrite — orkiestracja surowe→AI→przerobione (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\ProductInfo\RawLayerMeta;

/**
 * Generuje PODGLĄD warstwy przerobionej (opis) z warstwy surowej jednego
 * produktu (D-7.G5/D-5.G4): wejściem jest cała verbatim oferta Allegro
 * (`RawLayerMeta::META_OFFER`) — daje modelowi komplet parametrów tej kategorii,
 * nigdy cały katalog. Wynik NIE jest tu zapisywany do realnych pól — to robi
 * dopiero {@see RewriteWriter::accept()} po akceptacji admina (D-7.3.1: zwykła
 * akcja admina generuj/podgląd/zaakceptuj, nie Ability).
 *
 * Do P-13.4 (D-13.G1, REWIZJA D-5.1.1/D-5.1.2) generator zwracał też
 * `specyfikacja` — pole USUNIĘTE z odpowiedzi: atrybuty WC są odtąd
 * tłumaczone 1:1 z surowych parametrów Allegro przez sync
 * ({@see \Qutlet\Allegro\OfferSync\ProductWriter}, P-13.4a), bez udziału AI.
 *
 * Odpowiedź AI wymuszamy jako JSON (`TextGenerationService::generate_json()`,
 * `as_json_response()`) zamiast wyłuskiwać opis z wolnej prozy niedeterministyczną
 * heurystyką.
 */
final class RewriteGenerator {

	/**
	 * Generuje podgląd przeróbki dla produktu.
	 *
	 * @param int $product_id ID produktu (post ID).
	 * @return array{opis: string}|\WP_Error
	 */
	public static function generate( int $product_id ) {
		$raw_offer = get_post_meta( $product_id, RawLayerMeta::META_OFFER, true );

		if ( ! is_string( $raw_offer ) || '' === trim( $raw_offer ) ) {
			return new \WP_Error(
				'qutlet_ai_missing_raw_offer',
				__( 'Produkt nie ma zapisanej surowej oferty Allegro (brak materiału wejściowego do przeróbki) — utworzony ręcznie albo jeszcze nie zsynchronizowany.', 'qutlet-ai' ),
				array( 'status' => 422 )
			);
		}

		$response = TextGenerationService::generate_json(
			$raw_offer,
			self::response_schema(),
			PromptSettings::effective_prompt( $product_id )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = self::decode_response( $response );

		if ( null === $parsed ) {
			return new \WP_Error(
				'qutlet_ai_malformed_response',
				__( 'Odpowiedź AI nie odpowiada oczekiwanemu kształtowi (pole „opis").', 'qutlet-ai' ),
				array( 'status' => 502 )
			);
		}

		return $parsed;
	}

	/**
	 * Dekoduje i waliduje kształt odpowiedzi JSON od AI — czysta funkcja (bez
	 * WordPressa), pokryta testami. Sanityzacja WP-owa (HTML w opisie) należy
	 * do momentu ZAPISU ({@see RewriteWriter::accept()}), nie podglądu —
	 * podgląd pokazuje wynik możliwie wiernie temu, co zwrócił model.
	 *
	 * @param string $json Surowa odpowiedź AI (JSON — wymuszony `as_json_response()`).
	 * @return array{opis: string}|null Null, gdy kształt się nie zgadza.
	 */
	public static function decode_response( string $json ): ?array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['opis'] )
			|| ! is_string( $decoded['opis'] ) ) {
			return null;
		}

		return array(
			'opis' => $decoded['opis'],
		);
	}

	/**
	 * Schemat JSON oczekiwanej odpowiedzi (`as_json_response()`): wyłącznie opis
	 * (proza) — pole `specyfikacja` USUNIĘTE (P-13.4b, D-13.G1): atrybuty WC
	 * odtąd tłumaczy 1:1 z surowych parametrów sync Allegro
	 * ({@see \Qutlet\Allegro\OfferSync\ProductWriter}, P-13.4a), nie AI.
	 *
	 * @return array<string, mixed>
	 */
	private static function response_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'opis' => array( 'type' => 'string' ),
			),
			'required'             => array( 'opis' ),
			'additionalProperties' => false,
		);
	}
}
