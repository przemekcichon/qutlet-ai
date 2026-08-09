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
 * przez `as_json_response()`) BEZ WordPressa — sanityzacja WP-owa (HTML) należy
 * do zapisu ({@see \Qutlet\Ai\AiRewrite\RewriteWriter}), nie do tej funkcji.
 *
 * Od P-13.4b (D-13.G1) odpowiedź niesie WYŁĄCZNIE `opis` — `specyfikacja` USUNIĘTA
 * ze schematu (atrybuty WC tłumaczy odtąd 1:1 sync Allegro, nie AI).
 */
final class RewriteGeneratorTest extends TestCase {

	public function test_decodes_well_formed_response(): void {
		$json = wp_json_encode_stub(
			array(
				'opis' => '<p>Świetny produkt</p>',
			)
		);

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'opis' => '<p>Świetny produkt</p>',
			),
			$result
		);
	}

	public function test_decodes_empty_opis(): void {
		$json = wp_json_encode_stub( array( 'opis' => '' ) );

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame( array( 'opis' => '' ), $result );
	}

	public function test_ignores_unexpected_extra_keys(): void {
		$json = wp_json_encode_stub(
			array(
				'opis'         => 'Opis',
				'specyfikacja' => array( array( 'etykieta' => 'Marka', 'wartosc' => 'Soundcore' ) ),
			)
		);

		$result = RewriteGenerator::decode_response( $json );

		$this->assertSame( array( 'opis' => 'Opis' ), $result, 'Zbędne klucze w odpowiedzi (np. stary "specyfikacja") są ignorowane, nie odrzucają całej odpowiedzi.' );
	}

	public function test_rejects_invalid_json(): void {
		$this->assertNull( RewriteGenerator::decode_response( 'to nie jest JSON' ) );
	}

	public function test_rejects_missing_opis_key(): void {
		$json = wp_json_encode_stub( array() );

		$this->assertNull( RewriteGenerator::decode_response( $json ) );
	}

	public function test_rejects_non_string_opis(): void {
		$json = wp_json_encode_stub( array( 'opis' => array( 'nie string' ) ) );

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
