<?php
/**
 * Testy jednostkowe czystych funkcji AiRewrite\ProviderPrioritySettings (P-18.2).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\Tests\AiRewrite;

use Qutlet\Ai\AiRewrite\ProviderPrioritySettings;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje CZYSTE funkcje `ProviderPrioritySettings` (bez WordPressa) —
 * odczyt WP-owy (`ordered_configured_provider_ids()`/`available_provider_ids()`,
 * wołający `AiClient::defaultRegistry()`) jest poza zasięgiem tego harnessu
 * (wzorzec `RewriteGeneratorTest`/`phpunit.xml`: testy bez sieci i bez WordPressa).
 */
final class ProviderPrioritySettingsTest extends TestCase {

	public function test_filter_to_known_preserves_saved_order(): void {
		$result = ProviderPrioritySettings::filter_to_known(
			array( 'openai', 'anthropic', 'google' ),
			array( 'google', 'openai', 'anthropic' )
		);

		$this->assertSame( array( 'openai', 'anthropic', 'google' ), $result, 'Kolejność ZAPISANEJ listy ma pierwszeństwo przed kolejnością znanych ID.' );
	}

	public function test_filter_to_known_drops_unconfigured_provider(): void {
		$result = ProviderPrioritySettings::filter_to_known(
			array( 'google', 'openai', 'anthropic' ),
			array( 'google', 'anthropic' )
		);

		$this->assertSame( array( 'google', 'anthropic' ), $result, 'Dostawca usunięty z konfiguracji (klucz zdjęty PO zapisaniu priorytetu) jest cicho pomijany.' );
	}

	public function test_filter_to_known_drops_duplicates(): void {
		$result = ProviderPrioritySettings::filter_to_known(
			array( 'google', 'google', 'openai' ),
			array( 'google', 'openai' )
		);

		$this->assertSame( array( 'google', 'openai' ), $result );
	}

	public function test_filter_to_known_ignores_non_string_entries(): void {
		$result = ProviderPrioritySettings::filter_to_known(
			array( 'google', 123, null, array( 'openai' ) ),
			array( 'google', 'openai' )
		);

		$this->assertSame( array( 'google' ), $result );
	}

	public function test_filter_to_known_empty_saved_returns_empty(): void {
		$this->assertSame(
			array(),
			ProviderPrioritySettings::filter_to_known( array(), array( 'google', 'openai' ) )
		);
	}

	public function test_filter_to_known_all_dropped_returns_empty(): void {
		$this->assertSame(
			array(),
			ProviderPrioritySettings::filter_to_known( array( 'anthropic' ), array( 'google', 'openai' ) )
		);
	}

	public function test_sanitize_orders_by_rank(): void {
		$result = ProviderPrioritySettings::sanitize(
			array(
				'google'    => '2',
				'openai'    => '1',
				'anthropic' => '3',
			)
		);

		$this->assertSame( array( 'openai', 'google', 'anthropic' ), $result );
	}

	public function test_sanitize_breaks_rank_ties_by_submission_order(): void {
		$result = ProviderPrioritySettings::sanitize(
			array(
				'google'    => '1',
				'openai'    => '1',
				'anthropic' => '2',
			)
		);

		$this->assertSame( array( 'google', 'openai', 'anthropic' ), $result, 'Remis rang zachowuje kolejność przesłania formularza (sortowanie MUSI być stabilne — projekt wspiera PHP 7.4, gdzie usort() nie jest jeszcze stabilny).' );
	}

	public function test_sanitize_treats_non_numeric_rank_as_lowest(): void {
		$result = ProviderPrioritySettings::sanitize(
			array(
				'google' => 'nie liczba',
				'openai' => '1',
			)
		);

		$this->assertSame( array( 'google', 'openai' ), $result );
	}

	public function test_sanitize_ignores_non_array_value(): void {
		$this->assertSame( array(), ProviderPrioritySettings::sanitize( 'nie tablica' ) );
		$this->assertSame( array(), ProviderPrioritySettings::sanitize( null ) );
	}

	public function test_sanitize_ignores_empty_and_non_string_keys(): void {
		$result = ProviderPrioritySettings::sanitize(
			array(
				''       => '1',
				'google' => '2',
			)
		);

		$this->assertSame( array( 'google' ), $result );
	}

	public function test_sanitize_empty_array_returns_empty(): void {
		$this->assertSame( array(), ProviderPrioritySettings::sanitize( array() ) );
	}
}
