<?php
/**
 * Slice AiRewrite — strona ustawień globalnego promptu AI (P-7.2b).
 *
 * @package Qutlet\Ai
 */

declare( strict_types=1 );

namespace Qutlet\Ai\AiRewrite;

/**
 * Strona ustawień globalnego promptu AI (D-7.G4): podmenu pod menu WooCommerce,
 * jedno pole textarea zapisywane przez Settings API do opcji
 * `qutlet_ai_prompt_global` (kontrakt §13, D-7.2b.1).
 *
 * Wzorzec 1:1 z `Qutlet\Core\Pricing\DiscountRateSettingsPage` — ustawienia
 * sklepowe Qutlet mieszkają pod jednym menu (WooCommerce), niezależnie od tego,
 * który plugin je rejestruje. Prompt jest wprowadzany ręcznie przez administratora
 * i czytany przy generacji (P-7.3) przez {@see PromptSettings::effective_prompt()}.
 *
 * Troski WP-owe (Settings API, capability) mieszkają WEWNĄTRZ slice'a AiRewrite —
 * bez globalnego `settings/` (vertical slice, CLAUDE.md).
 *
 * Sekcja „Kolejność dostawców AI" (P-18.2, D-18.5) — TA SAMA strona/grupa opcji
 * (jedno „Zapisz zmiany"), NIE nowa strona menu i NIE pole w metaboksie produktu
 * (D-18.2: ustawienie GLOBALNE, nie override per-produkt). Lista pokazuje
 * WYŁĄCZNIE dostawców aktualnie skonfigurowanych ({@see ProviderPrioritySettings},
 * D-18.6) jako numerowane selecty (kształt UI do ustalenia przy realizacji —
 * wybrany bez JS/drag&drop, konsystentnie z resztą tej strony, która dziś nie ma
 * żadnego skryptu).
 */
final class PromptSettingsPage {

	/**
	 * Slug strony ustawień (podmenu WooCommerce).
	 */
	private const PAGE_SLUG = 'qutlet-ai-prompt';

	/**
	 * Grupa opcji Settings API (`settings_fields()` / `register_setting()`).
	 */
	private const OPTION_GROUP = 'qutlet_ai_prompt';

	/**
	 * Capability strony i zapisu opcji. `manage_woocommerce` (rola Shop Manager +
	 * admin) — to ustawienie sklepowe, nie systemowe, więc nie `manage_options`
	 * (spójnie z `DiscountRateSettingsPage`/`OAuthController`).
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Wpina rejestrację menu i opcji. Wołane z bootstrapu `qutlet-ai` (na
	 * `plugins_loaded`, po sprawdzeniu twardej zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );

		// `options.php` sprawdza domyślnie `manage_options`; bez tego filtra zapis
		// przez Shop Managera (manage_woocommerce) kończyłby się odmową mimo
		// widocznej strony.
		add_filter(
			'option_page_capability_' . self::OPTION_GROUP,
			array( self::class, 'option_page_capability' )
		);
	}

	/**
	 * Rejestruje podmenu pod menu WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Qutlet — prompt AI', 'qutlet-ai' ),
			__( 'Qutlet — prompt AI', 'qutlet-ai' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Rejestruje opcje w Settings API — TA SAMA grupa (`self::OPTION_GROUP`) dla
	 * promptu globalnego i kolejności priorytetów dostawców (P-18.2): jeden
	 * formularz, jedno „Zapisz zmiany" zapisuje obie opcje naraz.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			PromptSettings::OPTION_NAME,
			array(
				'type'              => 'string',
				'description'       => 'Globalny prompt AI (instrukcja systemowa) dla przeróbki opisów produktów.',
				'sanitize_callback' => array( PromptSettings::class, 'sanitize' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			ProviderPrioritySettings::OPTION_NAME,
			array(
				'type'              => 'array',
				'description'       => 'Kolejność priorytetów dostawców AI Client (lista ID w kolejności, D-18.G1) — runtime failover w TextGenerationService.',
				'sanitize_callback' => array( ProviderPrioritySettings::class, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Capability zapisu grupy opcji (filtr `option_page_capability_{group}`).
	 *
	 * @return string
	 */
	public static function option_page_capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Renderuje stronę ustawień: jedno pole textarea + opis mechanizmu nadpisania.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$value = get_option( PromptSettings::OPTION_NAME, '' );
		$value = is_string( $value ) ? $value : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Qutlet — prompt AI', 'qutlet-ai' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Prompt globalny (instrukcja systemowa) używany przy generowaniu przerobionego opisu produktu przez AI. Można go nadpisać na pojedynczym produkcie (pole „Prompt AI (nadpisanie)" w edycji produktu) — wtedy nadpisanie ma pierwszeństwo, a to pole jest pomijane.',
					'qutlet-ai'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( PromptSettings::OPTION_NAME ); ?>">
								<?php esc_html_e( 'Globalny prompt AI', 'qutlet-ai' ); ?>
							</label>
						</th>
						<td>
							<textarea
								id="<?php echo esc_attr( PromptSettings::OPTION_NAME ); ?>"
								name="<?php echo esc_attr( PromptSettings::OPTION_NAME ); ?>"
								rows="8"
								cols="60"
								class="large-text"
							><?php echo esc_textarea( $value ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Puste = brak instrukcji systemowej (generacja bez promptu, dopóki nie ustawisz tego pola albo nadpisania na produkcie).', 'qutlet-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php self::render_provider_priority_section(); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sekcja „Kolejność dostawców AI" (P-18.2, D-18.5): numerowany select per
	 * aktualnie skonfigurowany dostawca ({@see ProviderPrioritySettings::display_order()}
	 * — D-18.6, dynamicznie z `ProviderRegistry`, nie sztywna lista w kodzie).
	 * Brak jakiegokolwiek skonfigurowanego dostawcy → tylko komunikat, bez pól
	 * (nic do ułożenia) — generacja i tak działa (fallback na dzisiejsze
	 * zachowanie AI Client, {@see TextGenerationService}).
	 *
	 * @return void
	 */
	private static function render_provider_priority_section(): void {
		$ordered = ProviderPrioritySettings::display_order();

		printf( '<h2>%s</h2>', esc_html__( 'Kolejność dostawców AI', 'qutlet-ai' ) );
		echo '<p class="description">';
		esc_html_e(
			'Kolejność, w jakiej „Generuj" próbuje dostawców AI: przy błędzie (limit, awaria, brak konfiguracji) system automatycznie próbuje kolejnego z listy w tym samym kliknięciu. Widoczni są wyłącznie dostawcy ze skonfigurowanym kluczem API (Ustawienia → Łączniki).',
			'qutlet-ai'
		);
		echo '</p>';

		if ( array() === $ordered ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Brak skonfigurowanych dostawców AI (Ustawienia → Łączniki) — generacja użyje domyślnego zachowania AI Client.', 'qutlet-ai' )
			);

			return;
		}

		$count = count( $ordered );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $ordered as $position => $provider_id ) {
			printf( '<tr><th scope="row">%s</th><td>', esc_html( self::provider_label( $provider_id ) ) );
			printf(
				'<select name="%s[%s]">',
				esc_attr( ProviderPrioritySettings::OPTION_NAME ),
				esc_attr( $provider_id )
			);

			for ( $rank = 1; $rank <= $count; $rank++ ) {
				printf(
					'<option value="%1$d"%2$s>%1$d</option>',
					$rank,
					( $position + 1 === $rank ) ? ' selected="selected"' : ''
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Etykieta dostawcy do wyświetlenia — czysto kosmetyczna mapa znanych ID
	 * (potwierdzonych w kodzie, kontrakt §13/D-18.G1) z fallbackiem na surowe ID
	 * dla dowolnego przyszłego dostawcy — NIE zawęża to, KTÓRZY dostawcy są
	 * dostępni (to zostaje dynamiczne, D-18.6), wpływa tylko na podpis w UI.
	 *
	 * @param string $provider_id ID dostawcy.
	 * @return string
	 */
	private static function provider_label( string $provider_id ): string {
		$labels = array(
			'google'    => 'Google',
			'openai'    => 'OpenAI',
			'anthropic' => 'Anthropic',
		);

		return $labels[ $provider_id ] ?? $provider_id;
	}
}
