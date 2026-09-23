<?php
/**
 * Safety net that resolves FluentCRM palette color tokens left unreplaced in outgoing email.
 *
 * @package CustomCRM
 */

namespace CustomCRM\Integrations;

/**
 * Last-resort safety net for unresolved FluentCRM color tokens in outgoing email.
 *
 * FluentCRM 3.2.0's block email parser writes named palette colors into inline style attributes
 * as `var(--fcom--color--{slug})` (GutenbergEmailParser, for text, background, border and
 * separator colors) and expects `BlockEditorHelper::replaceStyleSlugsWithValues()` to swap them
 * back to hex before the mail goes out. That swap is driven by `Helper::getThemeColorPalette()`,
 * which reads the palette from theme.json only when `wp_is_block_theme()` is true and otherwise
 * falls back to `get_theme_support( 'editor-color-palette' )`.
 *
 * On a hybrid theme — classic templates plus a theme.json, which is what this site runs — both
 * branches can come back empty, the swap silently does nothing, and the raw `var()` ships. The
 * variable is defined nowhere in the email, so it resolves to nothing in EVERY mail client: a
 * navy header renders white, and white-on-dark text can disappear entirely.
 *
 * The real fix is in the theme, which registers its theme.json palette as classic
 * `editor-color-palette` support so FluentCRM's own resolution works. This class exists only for
 * the case where that fix is absent or bypassed — a render in a context that never loaded the
 * theme's functions.php (a WP-CLI job run with `--skip-themes`, for instance), or a future
 * FluentCRM release that moves the palette source again. It has already moved twice: a direct
 * theme.json file read in 2.9.x, then the `wp_is_block_theme()` gate in 3.2.0.
 *
 * Because of that, a replacement here is NOT routine — it means the primary fix has stopped
 * working. Every replacement is logged so the next regression surfaces in the logs instead of in
 * customer inboxes.
 *
 * Deliberately does NOT strip tokens it cannot resolve. `backgroundColor: "custom"` blocks also
 * carry a literal hex in their own style attribute, which the parser writes over the top of the
 * `var()`, so an unresolved `custom` token never reaches the final markup as the winning value.
 */
class EmailPaletteFallback {

	/**
	 * Template types FluentCRM filters the rendered email through.
	 *
	 * Includes `block_editor`, which 3.2.0 added and which `simple` is now aliased to.
	 *
	 * @var string[]
	 */
	private const TEMPLATE_TYPES = [
		'block_editor',
		'simple',
		'plain',
		'classic',
		'raw_classic',
		'web_preview',
	];

	/**
	 * Variable prefixes FluentCRM emits for named palette colors.
	 *
	 * @var string[]
	 */
	private const VAR_PREFIXES = [
		'--fcom--color--',
		'--wp--preset--color--',
	];

	/**
	 * Register the filters.
	 *
	 * Priority 1000 so this runs after FluentCRM's own template handler (10) and after
	 * CustomEmailCSS (999) — at that point the document is fully assembled and inlined, so a
	 * token surviving here is genuinely going out the door.
	 */
	public function register(): void {
		foreach ( self::TEMPLATE_TYPES as $type ) {
			add_filter( "fluent_crm/email-design-template-{$type}", [ $this, 'resolve_tokens' ], 1000, 3 );
		}
	}

	/**
	 * Replace any surviving palette token with its hex value.
	 *
	 * @param string              $html            The rendered email HTML.
	 * @param mixed               $email_body      The email body content.
	 * @param array<string,mixed> $template_config Template configuration.
	 *
	 * @return string
	 */
	public function resolve_tokens( string $html, $email_body = '', $template_config = [] ): string {
		if ( false === strpos( $html, 'var(--' ) ) {
			return $html;
		}

		$replacements = $this->get_replacements();

		if ( ! $replacements ) {
			return $html;
		}

		$resolved = str_replace( array_keys( $replacements ), array_values( $replacements ), $html );

		if ( $resolved !== $html ) {
			$this->log_replacement( $html, $replacements );
		}

		return $resolved;
	}

	/**
	 * Build the token => hex map from the theme.json palette.
	 *
	 * Reads `wp_get_global_settings()` directly rather than FluentCRM's own
	 * `Helper::getThemeColorPalette()`, because that method returning an empty palette is the
	 * failure this class exists to cover.
	 *
	 * @return array<string,string>
	 */
	private function get_replacements(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = [];

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $cache;
		}

		$palette = wp_get_global_settings( [ 'color', 'palette' ] );

		// Theme colors first, then core defaults, so a theme slug always wins a name collision.
		foreach ( [ 'theme', 'default' ] as $group ) {
			foreach ( (array) ( $palette[ $group ] ?? [] ) as $color ) {
				if ( empty( $color['slug'] ) || empty( $color['color'] ) ) {
					continue;
				}

				foreach ( self::VAR_PREFIXES as $prefix ) {
					$token = 'var(' . $prefix . $color['slug'] . ')';

					if ( ! isset( $cache[ $token ] ) ) {
						$cache[ $token ] = $color['color'];
					}
				}
			}
		}

		return $cache;
	}

	/**
	 * Record that the safety net fired, naming the tokens it had to resolve.
	 *
	 * @param string               $html         The HTML before replacement.
	 * @param array<string,string> $replacements The token => hex map.
	 *
	 * @return void
	 */
	private function log_replacement( string $html, array $replacements ): void {
		$hit = [];

		foreach ( array_keys( $replacements ) as $token ) {
			if ( false !== strpos( $html, $token ) ) {
				$hit[] = $token;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate:
		// this line firing is the alarm. It only runs when FluentCRM failed to resolve a color
		// token, which means the theme-level fix regressed and customer email is about to ship
		// with a broken header. Silent recovery here would hide the next regression.
		error_log(
			sprintf(
				'[GK FluentCRM] Palette fallback fired — FluentCRM did not resolve %s. '
				. 'The theme-level editor-color-palette fix is missing or was bypassed in this context.',
				implode( ', ', $hit )
			)
		);
	}
}
