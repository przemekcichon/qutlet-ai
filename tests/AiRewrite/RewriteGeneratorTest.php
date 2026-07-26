<?php
/**
 * Testy jednostkowe czystej funkcji AiRewrite\RewriteGenerator::decode_response() (P-7.3).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\Tests\AiRewrite;

use Qutlet\Ai\AiRewrite\RewriteGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje dekodowanie/walidację kształtu odpowiedzi AI (JSON wymuszony
 * przez `as_json_response()`) BEZ WordPressa — sanityzacja WP-owa (HTML,
 * `sanitize_text_field`) należy do zapisu ({@see \Qutlet\Ai\AiRewrite\RewriteWriter}),
 * nie do tej funkcji.
 */
final class RewriteGeneratorTest extends TestCase {

	public function test_decodes_well_formed_response(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => '<p>Świetny produkt</p>',
				'specyfikacja' => array(
					array(
						'etykieta' => 'Marka',
						'wartosc'  => 'Soundcore',
					),
					array(
						'etykieta' => 'Kolor',
						'wartosc'  => 'Czarny',
					),
				),
			)
		);

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'opis'         => '<p>Świetny produkt</p>',
				'specyfikacja' => array(
					array(
						'etykieta' => 'Marka',
						'wartosc'  => 'Soundcore',
					),
					array(
						'etykieta' => 'Kolor',
						'wartosc'  => 'Czarny',
					),
				),
			),
			$result
		);
	}

	public function test_accepts_empty_specification_list(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => 'Opis bez parametrów.',
				'specyfikacja' => array(),
			)
		);

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'opis'         => 'Opis bez parametrów.',
				'specyfikacja' => array(),
			),
			$result
		);
	}

	public function test_skips_malformed_specification_rows_but_keeps_valid_ones(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => 'Opis',
				'specyfikacja' => array(
					array(
						'etykieta' => 'OK',
						'wartosc'  => 'Wartość',
					),
					array( 'etykieta' => 'Brak wartości' ),
					array(
						'etykieta' => 123,
						'wartosc'  => 'Etykieta nie jest stringiem',
					),
					'nie-tablica',
				),
			)
		);

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'opis'         => 'Opis',
				'specyfikacja' => array(
					array(
						'etykieta' => 'OK',
						'wartosc'  => 'Wartość',
					),
				),
			),
			$result
		);
	}

	public function test_rejects_invalid_json(): void {
		$this->assertNull( RewriteGenerator::decode_response( 'to nie jest JSON' ) );
	}

	public function test_rejects_missing_opis_key(): void {
		$json = wp_json_encode_stub( array( 'specyfikacja' => array() ) );

		$this->assertNull( RewriteGenerator::decode_response( $json ) );
	}

	public function test_rejects_missing_specyfikacja_key(): void {
		$json = wp_json_encode_stub( array( 'opis' => 'Opis' ) );

		$this->assertNull( RewriteGenerator::decode_response( $json ) );
	}

	public function test_rejects_non_string_opis(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => array( 'nie string' ),
				'specyfikacja' => array(),
			)
		);

		$this->assertNull( RewriteGenerator::decode_response( $json ) );
	}

	public function test_rejects_non_array_specyfikacja(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => 'Opis',
				'specyfikacja' => 'nie tablica',
			)
		);

		$this->assertNull( RewriteGenerator::decode_response( $json ) );
	}

	public function test_rejects_top_level_non_object_json(): void {
		$this->assertNull( RewriteGenerator::decode_response( '"tylko string"' ) );
		$this->assertNull( RewriteGenerator::decode_response( '[]' ) );
	}
}

/**
 * `json_encode` z tymi samymi flagami co `wp_json_encode()` (Unicode/slashe
 * nieescape'owane) — test nie ładuje WordPressa, więc nie ma dostępu do
 * `wp_json_encode()`, a `decode_response()` i tak woła surowe `json_decode()`.
 *
 * @param array<string, mixed> $data Dane do zakodowania.
 * @return string
 */
function wp_json_encode_stub( array $data ): string {
	$encoded = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

	return false !== $encoded ? $encoded : '{}';
}
