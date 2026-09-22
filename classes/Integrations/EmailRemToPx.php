<?php
/**
 * Converts rem lengths in outgoing email to px.
 *
 * @package CustomCRM
 */

namespace CustomCRM\Integrations;

/**
 * Rewrites every rem length in an email's inline styles and `<style>` blocks as px.
 *
 * A rem follows the mail client's root font size, not the email's, so the theme's rem font sizes
 * render oversized in a client with a large root and are ignored by Outlook desktop.
 */
class EmailRemToPx {

	/**
	 * Pixels per rem: the browser default the theme's rem scale is designed against.
	 */
	private const PX_PER_REM = 16;

	/**
	 * Template types FluentCRM filters the rendered email through.
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
	 * Register the filters.
	 *
	 * Priority 1002, after the palette fallback (1000) and group spacing (1001), so it runs on the
	 * fully assembled and inlined document.
	 */
	public function register(): void {
		foreach ( self::TEMPLATE_TYPES as $type ) {
			add_filter( "fluent_crm/email-design-template-{$type}", [ $this, 'convert' ], 1002, 3 );
		}
	}

	/**
	 * Convert rem to px inside style attributes and style blocks, leaving body text untouched.
	 *
	 * @param string              $html            The rendered email HTML.
	 * @param mixed               $email_body      The email body content.
	 * @param array<string,mixed> $template_config Template configuration.
	 *
	 * @return string
	 */
	public function convert( string $html, $email_body = '', $template_config = [] ): string {
		if ( false === stripos( $html, 'rem' ) ) {
			return $html;
		}

		// Only inside real tags, so a CSS snippet in body text is left alone. The tag pattern skips
		// over quoted attribute values, which may contain `>`.
		$converted = preg_replace_callback(
			'/<[a-z][a-z0-9-]*\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
			[ $this, 'convert_tag' ],
			$html
		);

		$converted = null === $converted ? null : preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			function ( array $m ): string {
				return $m[1] . $this->to_px( $m[2] ) . $m[3];
			},
			$converted
		);

		// A PCRE failure returns null; sending the email unconverted beats sending it empty.
		return null === $converted ? $html : $converted;
	}

	/**
	 * Convert rem to px in a single tag's `style` attribute, quoted or unquoted.
	 *
	 * @param array<int,string> $matches preg_replace_callback matches; [0] is the whole tag.
	 *
	 * @return string
	 */
	private function convert_tag( array $matches ): string {
		$tag = $matches[0];
		if ( false === stripos( $tag, 'rem' ) ) {
			return $tag;
		}

		// `style` must be its own attribute: preceded by whitespace, so `data-style` never matches.
		$result = preg_replace_callback(
			'/(?<=\s)(style\s*=\s*)(?:(["\'])(.*?)\2|([^\s"\'>]+))/is',
			function ( array $m ): string {
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					return $m[1] . $this->to_px( $m[4] );
				}

				return $m[1] . $m[2] . $this->to_px( $m[3] ) . $m[2];
			},
			$tag
		);

		return null === $result ? $tag : $result;
	}

	/**
	 * Rewrite each rem length in a CSS fragment as px.
	 *
	 * @param string $css CSS declarations or a stylesheet.
	 *
	 * @return string
	 */
	private function to_px( string $css ): string {
		$result = preg_replace_callback(
			'/(?<![\w.])(-?\d*\.?\d+)rem\b/i',
			static function ( array $m ): string {
				$px = number_format( (float) $m[1] * self::PX_PER_REM, 2, '.', '' );

				return rtrim( rtrim( $px, '0' ), '.' ) . 'px';
			},
			$css
		);

		return null === $result ? $css : $result;
	}
}
