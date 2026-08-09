<?php
/**
 * Slice AiRewrite — zapis zaakceptowanej przeróbki do warstwy przerobionej (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

/**
 * Zapisuje ZAAKCEPTOWANY podgląd {@see RewriteGenerator::generate()} do realnego
 * pola warstwy przerobionej (kontrakt §9.2): natywny opis produktu (`post_content`,
 * P-13.3a/b — `opis` ACF wycofane, `qutlet-core`, `RewrittenFields`). `qutlet-ai`
 * NIE rejestruje tego pola (D-7.G6) — tylko pisze do literału zgodnego z kontraktem.
 *
 * Do P-13.4b (D-13.G1, REWIZJA D-5.1.1/D-5.1.2) writer zapisywał tu też natywne
 * atrybuty WooCommerce (specyfikacja, `build_attributes()`/`set_attributes()`) —
 * USUNIĘTE: atrybuty odtąd tłumaczy 1:1 z surowych parametrów Allegro sync
 * ({@see \Qutlet\Allegro\OfferSync\ProductWriter}, P-13.4a), AI nie dotyka ich
 * wcale. `qutlet-ai` od tego punktu pisze WYŁĄCZNIE opis.
 *
 * Warstwa przerobiona (opis) pozostaje ręcznie edytowalna po zapisie — ten writer
 * to jedyne miejsce, które ją tworzy/nadpisuje z inicjatywy AI; sync z Allegro
 * (FAZA 6) jej nigdy nie dotyka (D-5.G4).
 *
 * Zapis opisu przez `wp_update_post()` (`post_content`), NIE ACF `update_field()`
 * jak przed P-13.3b — natywne pole WP nie ma referencji pola do utrzymania.
 */
final class RewriteWriter {

	/**
	 * Zapisuje opis zaakceptowany przez admina.
	 *
	 * @param int    $product_id ID produktu (post ID).
	 * @param string $opis       Opis (prawdopodobnie HTML) do zapisania jako warstwa przerobiona.
	 * @return bool True, gdy produkt istnieje i zapis się powiódł.
	 */
	public static function accept( int $product_id, string $opis ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		// Opis: ten sam allowlist co treść postów (spójnie z podglądem surowego
		// opisu w `RawLayerMetaBox`) — AI generuje prozę, nie potrzebuje skryptów
		// ani atrybutów `on*`.
		$updated = wp_update_post(
			array(
				'ID'           => $product_id,
				'post_content' => wp_kses_post( $opis ),
			),
			true
		);

		return ! is_wp_error( $updated );
	}
}
