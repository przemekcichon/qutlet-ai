<?php
/**
 * Slice AiRewrite — zapis zaakceptowanej przeróbki do warstwy przerobionej (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use WC_Product_Attribute;

/**
 * Zapisuje ZAAKCEPTOWANY podgląd {@see RewriteGenerator::generate()} do realnych
 * pól warstwy przerobionej (kontrakt §9.2): `opis` (ACF WYSIWYG, rejestruje
 * `qutlet-core`, `RewrittenFields`) + natywne atrybuty produktu WooCommerce
 * (specyfikacja). `qutlet-ai` NIE rejestruje żadnego z tych pól (D-7.G6) — tylko
 * pisze do literału zgodnego z kontraktem.
 *
 * Warstwa przerobiona pozostaje ręcznie edytowalna po zapisie — ten writer to
 * jedyne miejsce, które ją tworzy/nadpisuje z inicjatywy AI; sync z Allegro
 * (FAZA 6) jej nigdy nie dotyka (D-5.G4).
 */
final class RewriteWriter {

	/**
	 * `meta_key` opisu (przerobiona warstwa) — kontrakt §9.2 (VERBATIM). Pole
	 * rejestruje `qutlet-core` (`RewrittenFields`); `qutlet-ai` tylko pisze do
	 * tego literału (granica D-7.G6).
	 */
	public const FIELD_OPIS = 'opis';

	/**
	 * Zapisuje opis i specyfikację zaakceptowane przez admina.
	 *
	 * @param int                                            $product_id    ID produktu (post ID).
	 * @param string                                         $opis          Opis (prawdopodobnie HTML) do zapisania jako warstwa przerobiona.
	 * @param array<int, array{etykieta: string, wartosc: string}> $specyfikacja Pary etykieta→wartość do zapisania jako atrybuty WC.
	 * @return bool True, gdy produkt istnieje i zapis się powiódł.
	 */
	public static function accept( int $product_id, string $opis, array $specyfikacja ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		// Opis: ten sam allowlist co treść postów (spójnie z podglądem surowego
		// opisu w `RawLayerMetaBox`) — AI generuje prozę, nie potrzebuje skryptów
		// ani atrybutów `on*`.
		update_post_meta( $product_id, self::FIELD_OPIS, wp_kses_post( $opis ) );

		$product->set_attributes( self::build_attributes( $specyfikacja ) );
		$product->save();

		return true;
	}

	/**
	 * Buduje listę atrybutów WC (custom, per-produkt — NIE taksonomia) z par
	 * etykieta→wartość. Wiersze z pustą etykietą albo wartością (po sanityzacji)
	 * są pomijane — pusty atrybut nie niesie informacji.
	 *
	 * @param array<int, array{etykieta: string, wartosc: string}> $specyfikacja Pary etykieta→wartość.
	 * @return array<int, WC_Product_Attribute>
	 */
	private static function build_attributes( array $specyfikacja ): array {
		$attributes = array();
		$position   = 0;

		foreach ( $specyfikacja as $row ) {
			$label = sanitize_text_field( $row['etykieta'] );
			$value = sanitize_text_field( $row['wartosc'] );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 ); // 0 = atrybut lokalny (custom), nie taksonomia globalna.
			$attribute->set_name( $label );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$attributes[] = $attribute;
			++$position;
		}

		return $attributes;
	}
}
