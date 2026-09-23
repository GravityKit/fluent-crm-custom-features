<?php
/**
 * Restores the group padding FluentCRM 3.2.0 stopped emitting in outgoing email.
 *
 * @package CustomCRM
 */

namespace CustomCRM\Integrations;

/**
 * FluentCRM 2.9.x gave every `core/group` a blanket `padding: 20px 20px 20px 20px` and
 * `margin: 20px 0px`, whether or not the block declared any spacing of its own. None of our
 * campaign blocks declare padding — they are plain
 * `{"backgroundColor":"primary","layout":{"type":"constrained"}}` — so that renderer default was
 * the only thing putting breathing room inside the navy header band and around content groups.
 *
 * 3.2.0 replaced that with a narrow rule: a block is auto-padded only when it has a background
 * AND its name appears in GutenbergEmailParser::$autoPaddedElements, which lists
 * core/heading, core/paragraph and core/list. `core/group` is not in it, so groups now render
 * flush and the header band hugs the logo.
 *
 * The durable fix is to author the padding into the blocks themselves, which 3.2.0 honors via
 * `style.spacing.padding` — verified through the full render chain. That is a migration across
 * thousands of stored campaign bodies, so this class restores the prior appearance at render time
 * in the meantime. It only ever ADDS padding to a group that declares none, so a block that does
 * carry its own spacing is left exactly as authored and the migration can land underneath this
 * without conflict.
 */
class EmailGroupSpacing {

	/**
	 * Padding 2.9.x applied to every group, and which this restores.
	 */
	private const DEFAULT_PADDING = '20px';

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
	 * Priority 1001, one step after the palette fallback, so both run on the fully assembled and
	 * inlined document and their order is deterministic.
	 */
	public function register(): void {
		foreach ( self::TEMPLATE_TYPES as $type ) {
			add_filter( "fluent_crm/email-design-template-{$type}", [ $this, 'restore_group_padding' ], 1001, 3 );
		}
	}

	/**
	 * Add the default padding to any group div that has none.
	 *
	 * @param string              $html            The rendered email HTML.
	 * @param mixed               $email_body      The email body content.
	 * @param array<string,mixed> $template_config Template configuration.
	 *
	 * @return string
	 */
	public function restore_group_padding( string $html, $email_body = '', $template_config = [] ): string {
		if ( false === strpos( $html, 'fc_group' ) ) {
			return $html;
		}

		// Both quote styles: BlockParser emits single-quoted attributes and only Emogrifier
		// normalizes them to double. This filter runs after Emogrifier today, but matching one
		// style only would silently skip every group if that ordering ever changed.
		return (string) preg_replace_callback(
			'/<div\b[^>]*\bclass=(["\'])[^"\']*\bfc_group\b[^"\']*\1[^>]*>/i',
			[ $this, 'pad_one_group' ],
			$html
		);
	}

	/**
	 * Inject padding into a single group tag, unless it already declares some.
	 *
	 * @param array<int,string> $matches preg_replace_callback matches; [0] is the whole tag.
	 * @return string
	 */
	private function pad_one_group( array $matches ): string {
		$tag = $matches[0];

		// Respect anything the block authored for itself, including a deliberate `padding: 0`.
		if ( preg_match( '/style=(["\'])[^"\']*\bpadding(?:-top|-right|-bottom|-left)?\s*:/i', $tag ) ) {
			return $tag;
		}

		$padding = 'padding: ' . self::DEFAULT_PADDING . ';';

		// Append inside the existing style attribute, reusing whichever delimiter it already uses,
		// or add one when the tag carries none. Reusing the delimiter is what stops a single-quoted
		// style attribute from gaining a second, double-quoted one.
		if ( preg_match( '/\bstyle=(["\'])(.*?)\1/i', $tag, $style ) ) {
			$quote    = $style[1];
			$existing = rtrim( trim( $style[2] ), ';' );
			$updated  = '' === $existing ? $padding : $existing . '; ' . $padding;

			return str_replace( $style[0], 'style=' . $quote . $updated . $quote, $tag );
		}

		return (string) preg_replace( '/<div\b/i', '<div style="' . $padding . '"', $tag, 1 );
	}
}
