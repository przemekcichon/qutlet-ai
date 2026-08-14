<?php
/**
 * Testy jednostkowe AiRewrite\TitleGenerationMetaBox::is_stale() (P-9.1a.2, D-9.1a.1).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\Tests\AiRewrite;

use Qutlet\Ai\AiRewrite\TitleGenerationMetaBox;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Charakteryzuje decyzję "Nowy" (P-9.1a): flaga zapala się, gdy nazwa Allegro
 * zmieniła się od ostatniej generacji/resetu tytułu. Czysta funkcja, bez WP —
 * wołana przez Reflection (metoda prywatna), jak `ProductWriterGtinFilterTest::write_gtin()`.
 */
final class TitleGenerationMetaBoxStalenessTest extends TestCase {

	private function is_stale( string $current_raw, string $source_raw, string $current_title = '' ): bool {
		$method = new ReflectionMethod( TitleGenerationMetaBox::class, 'is_stale' );
		$method->setAccessible( true );

		return $method->invoke( null, $current_raw, $source_raw, $current_title );
	}

	public function test_not_stale_when_source_matches_current_raw(): void {
		$this->assertFalse( $this->is_stale( 'SONY APARAT X', 'SONY APARAT X', 'Sony aparat X' ) );
	}

	public function test_stale_when_current_raw_changed_since_last_generation(): void {
		$this->assertTrue( $this->is_stale( 'SONY APARAT X WERSJA 2', 'SONY APARAT X', 'Sony aparat X' ) );
	}

	public function test_not_stale_when_never_generated_and_title_still_matches_raw(): void {
		// Świeżo utworzony produkt (P-9.1a.1): post_title = nazwa Allegro z
		// ProductWriter w chwili utworzenia, TitleWriter jeszcze nigdy nie
		// stemplował SOURCE_RAW_META — dopóki oferta się nie zmieniła, brak "Nowy".
		$this->assertFalse( $this->is_stale( 'SONY APARAT X', '', 'SONY APARAT X' ) );
	}

	public function test_stale_when_never_generated_but_offer_name_changed_since_creation(): void {
		// Recenzja P-9.1a.2 (sesja 2026-08-14): pusty stempel NIE znaczy "nic się
		// nie zmieniło" — produkt mógł nigdy nie trafić do metaboxa, mimo że
		// oferta na Allegro zmieniła nazwę po utworzeniu. post_title (nadal =
		// nazwa z chwili utworzenia, P-9.1a.1) rozjeżdża się z bieżącą raw.
		$this->assertTrue( $this->is_stale( 'SONY APARAT X WERSJA 2', '', 'SONY APARAT X' ) );
	}
}
