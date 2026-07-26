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
	 * Rejestruje opcję w Settings API.
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
