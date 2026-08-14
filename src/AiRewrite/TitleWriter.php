<?php
/**
 * Slice AiRewrite — zapis tytułu/podnazwy (generacja + reset) (P-13.2c).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

use Qutlet\Core\ProductInfo\RawLayerMeta;

/**
 * Zapisuje `post_title` + `podnazwa` (ACF, `qutlet-core`, `RewrittenFields`,
 * kontrakt §9.2) — dwie ścieżki wywołania z {@see TitleGenerationMetaBox}:
 * {@see self::accept()} po generacji AI, {@see self::reset()} dla przycisku
 * „Reset" (przywraca `post_title` = `RawLayerMeta::META_NAME_RAW`, czyści
 * `podnazwa`) — zaimplementowane jako `accept()` z oryginalną nazwą i pustą
 * podnazwą, żeby nie duplikować logiki zapisu.
 *
 * Zapis `podnazwa` przez `update_field()` (klucz ACF), NIE `update_post_meta()`
 * — ten sam powód co {@see RewriteWriter}: bez referencji pola ACF traktuje
 * wartość jak „dummy" (niepewne odczyty `get_field()`), dopóki ktoś nie zapisze
 * posta ręcznie w adminie.
 *
 * P-9.1a.2 (D-9.1a.1): przy KAŻDYM zapisie stempluje {@see self::SOURCE_RAW_META}
 * bieżącą wartością {@see RawLayerMeta::META_NAME_RAW} — „z jakiej nazwy Allegro
 * powstał ten tytuł/podnazwa". {@see TitleGenerationMetaBox::render()} porównuje
 * ten stempel z aktualną warstwą surową i pokazuje flagę „Nowy", gdy się rozjadą
 * (sync ProductWriter — qutlet-allegro — zmienia tylko warstwę surową po
 * utworzeniu produktu, P-9.1a.1, nigdy sam `post_title`). Meta plugin-owned
 * (bookkeeping `qutlet-ai`, nie warstwa surowa core) — bez `register_post_meta()`,
 * jak `ImageSideloader::META_SOURCE_URL` w `qutlet-allegro`.
 */
final class TitleWriter {

	/**
	 * Klucz ACF pola `podnazwa` (VERBATIM z `Qutlet\Core\ProductInfo\RewrittenFields`,
	 * `field_qutlet_podnazwa`) — wymagany przez `update_field()`.
	 */
	private const ACF_KEY_PODNAZWA = 'field_qutlet_podnazwa';

	/**
	 * `meta_key` stempla „z jakiej nazwy Allegro powstał bieżący tytuł/podnazwa"
	 * (P-9.1a.2) — patrz docblock klasy.
	 */
	public const SOURCE_RAW_META = '_qutlet_ai_title_source_raw';

	/**
	 * Hook odpalany po KAŻDYM udanym zapisie tytułu/podnazwy (generacja AI ORAZ
	 * reset — obie ścieżki wołają {@see self::accept()}), P-9.1a.2. Rezerwacja pod
	 * przyszły mechanizm notyfikacji (świadomie NIEZAIMPLEMENTOWANY teraz) — patrz
	 * `docs/title-generated-hook.md`.
	 *
	 * `do_action( self::ACTION_TITLE_GENERATED, int $product_id, string $tytul, string $podnazwa )`
	 */
	public const ACTION_TITLE_GENERATED = 'qutlet_product_title_generated';

	/**
	 * Zapisuje tytuł i podnazwę (generacja AI albo reset — patrz docblock klasy).
	 *
	 * @param int    $product_id ID produktu (post ID).
	 * @param string $tytul      Nowy tytuł (`post_title`) — sanityzowany tu jako plain text.
	 * @param string $podnazwa   Nowa podnazwa (ACF `podnazwa`) — może być pusta.
	 * @return array{tytul: string, podnazwa: string}|null Zapisane (znormalizowane) wartości, albo null gdy zapis się nie powiódł.
	 */
	public static function accept( int $product_id, string $tytul, string $podnazwa ): ?array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		// Tytuł produktu to plain text (jak natywne pole #title WP) — nie HTML,
		// więc `sanitize_text_field()`, nie `wp_kses_post()` jak przy opisie.
		$clean_title    = sanitize_text_field( $tytul );
		$clean_subtitle = sanitize_text_field( $podnazwa );

		if ( '' === $clean_title ) {
			return null;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $product_id,
				'post_title' => $clean_title,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return null;
		}

		update_field( self::ACF_KEY_PODNAZWA, $clean_subtitle, $product_id );

		$raw_name = (string) get_post_meta( $product_id, RawLayerMeta::META_NAME_RAW, true );

		update_post_meta( $product_id, self::SOURCE_RAW_META, wp_slash( $raw_name ) );

		do_action( self::ACTION_TITLE_GENERATED, $product_id, $clean_title, $clean_subtitle );

		return array(
			'tytul'    => $clean_title,
			'podnazwa' => $clean_subtitle,
		);
	}

	/**
	 * Przywraca oryginalną nazwę Allegro jako `post_title` i czyści `podnazwa`
	 * (przycisk „Reset").
	 *
	 * @param int $product_id ID produktu (post ID).
	 * @return array{tytul: string, podnazwa: string}|null Zapisane wartości, albo null gdy brak warstwy surowej (nic do przywrócenia) albo zapis się nie powiódł.
	 */
	public static function reset( int $product_id ): ?array {
		$raw_name = get_post_meta( $product_id, RawLayerMeta::META_NAME_RAW, true );

		if ( ! is_string( $raw_name ) || '' === trim( $raw_name ) ) {
			return null;
		}

		return self::accept( $product_id, $raw_name, '' );
	}
}
