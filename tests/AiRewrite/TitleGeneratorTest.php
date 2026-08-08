<?php
/**
 * Testy jednostkowe czystej funkcji AiRewrite\TitleGenerator::decode_response() (P-13.2c).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\Tests\AiRewrite;

use Qutlet\Ai\AiRewrite\TitleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje dekodowanie/walidację kształtu odpowiedzi AI (JSON wymuszony
 * przez `as_json_response()`) BEZ WordPressa — sanityzacja WP-owa
 * (`sanitize_text_field`) należy do zapisu ({@see \Qutlet\Ai\AiRewrite\TitleWriter}),
 * nie do tej funkcji.
 */
final class TitleGeneratorTest extends TestCase {

	public function test_decodes_well_formed_response(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => 'Soundcore Anker Life Q30',
				'podnazwa' => 'Słuchawki bezprzewodowe z ANC, czarne',
			)
		);

		$result = TitleGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'tytul'    => 'Soundcore Anker Life Q30',
				'podnazwa' => 'Słuchawki bezprzewodowe z ANC, czarne',
			),
			$result
		);
	}

	public function test_accepts_empty_podnazwa(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => 'Soundcore Anker Life Q30',
				'podnazwa' => '',
			)
		);

		$result = TitleGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'tytul'    => 'Soundcore Anker Life Q30',
				'podnazwa' => '',
			),
			$result
		);
	}

	public function test_trims_whitespace_from_both_fields(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => '  Soundcore Anker Life Q30  ',
				'podnazwa' => "  Czarne \n",
			)
		);

		$result = TitleGenerator::decode_response( $json );

		$this->assertSame(
			array(
				'tytul'    => 'Soundcore Anker Life Q30',
				'podnazwa' => 'Czarne',
			),
			$result
		);
	}

	public function test_rejects_blank_tytul(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => '   ',
				'podnazwa' => '',
			)
		);

		$this->assertNull( TitleGenerator::decode_response( $json ) );
	}

	public function test_rejects_invalid_json(): void {
		$this->assertNull( TitleGenerator::decode_response( 'to nie jest JSON' ) );
	}

	public function test_rejects_missing_tytul_key(): void {
		$json = wp_json_encode_stub( array( 'podnazwa' => '' ) );

		$this->assertNull( TitleGenerator::decode_response( $json ) );
	}

	public function test_rejects_missing_podnazwa_key(): void {
		$json = wp_json_encode_stub( array( 'tytul' => 'Tytuł' ) );

		$this->assertNull( TitleGenerator::decode_response( $json ) );
	}

	public function test_rejects_non_string_tytul(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => array( 'nie string' ),
				'podnazwa' => '',
			)
		);

		$this->assertNull( TitleGenerator::decode_response( $json ) );
	}

	public function test_rejects_non_string_podnazwa(): void {
		$json = wp_json_encode_stub(
			array(
				'tytul'    => 'Tytuł',
				'podnazwa' => array( 'nie string' ),
			)
		);

		$this->assertNull( TitleGenerator::decode_response( $json ) );
	}

	public function test_rejects_top_level_non_object_json(): void {
		$this->assertNull( TitleGenerator::decode_response( '"tylko string"' ) );
		$this->assertNull( TitleGenerator::decode_response( '[]' ) );
	}
}
