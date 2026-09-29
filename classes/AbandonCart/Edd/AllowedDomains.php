<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

/**
 * Internal-only mode: when any domains are listed, only carts from those email domains get cart
 * emails. Other carts are skipped before FluentCRM creates a contact or starts an automation.
 */
class AllowedDomains {

	public const OPTION = 'customcrm_edd_ab_cart_allowed_domains';

	/**
	 * Carts held back in this request, keyed by cart ID, with the note to store once the runner is done.
	 *
	 * @var array<int,string>
	 */
	private static $held_back = [];

	/**
	 * The listed domains; empty means every domain may get cart emails.
	 *
	 * @return string[] Lowercase domains, e.g. `gravitykit.com`.
	 */
	public static function get(): array {
		$saved   = (array) get_option( self::OPTION, [] );
		$domains = (array) apply_filters( 'customcrm/edd_ab_cart/allowed_domains', $saved );

		return self::normalize( $domains );
	}

	/**
	 * Replaces the list from free text: domains separated by commas, spaces or new lines.
	 *
	 * @param string $text Text from the settings form.
	 * @return string[] What was stored.
	 */
	public static function save( string $text ): array {
		$domains = self::normalize( preg_split( '/[\s,]+/', $text ) ?: [] );

		update_option( self::OPTION, $domains, true );

		return $domains;
	}

	/**
	 * Whether this email may get cart emails: always when no domains are listed, otherwise when
	 * its domain is listed or is a subdomain of one that is.
	 *
	 * @param string $email Shopper email.
	 */
	public static function allows( string $email ): bool {
		$domains = self::get();

		if ( ! $domains ) {
			return true;
		}

		$at     = strrchr( strtolower( trim( $email ) ), '@' );
		$domain = false === $at ? '' : substr( $at, 1 );

		foreach ( $domains as $allowed ) {
			$is_match     = $domain === $allowed;
			$is_subdomain = substr( $domain, -strlen( '.' . $allowed ) ) === '.' . $allowed;

			if ( '' !== $domain && ( $is_match || $is_subdomain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Holds a cart back when its email is not allowed, recording why on the cart.
	 *
	 * Called from the drivers' cool-off check, which FluentCRM's runner calls before it creates a
	 * contact; returning true there marks the cart skipped.
	 *
	 * @param AbandonCartModel $cart The cart about to start an automation.
	 * @return bool True when the cart is held back.
	 */
	public static function holdBack( AbandonCartModel $cart ): bool {
		if ( self::allows( (string) $cart->email ) ) {
			return false;
		}

		$note = sprintf(
			/* translators: %s: comma-separated domains */
			__( 'Held back: cart emails are limited to %s', 'fluent-crm-custom-features' ),
			implode( ', ', self::get() )
		);

		$data              = $cart->cart ?: [];
		$data['held_back'] = $note;
		$cart->cart        = $data;

		if ( ! self::$held_back ) {
			add_action( 'shutdown', [ self::class, 'writeNotes' ], 1 );
		}

		self::$held_back[ (int) $cart->id ] = $note;

		return true;
	}

	/**
	 * Replaces the runner's "Under Cool Off Period" note on held-back carts with the real reason.
	 */
	public static function writeNotes(): void {
		foreach ( self::$held_back as $cart_id => $note ) {
			AbandonCartModel::where( 'id', $cart_id )->where( 'status', 'skipped' )->update( [ 'note' => $note ] );
		}

		self::$held_back = [];
	}

	/**
	 * @param array<int,mixed> $domains Raw entries.
	 * @return string[] Lowercase, without a leading `@`, duplicates and invalid entries removed.
	 */
	private static function normalize( array $domains ): array {
		$clean = [];

		foreach ( $domains as $domain ) {
			$domain = is_scalar( $domain ) ? strtolower( trim( ltrim( trim( (string) $domain ), '@' ) ) ) : '';

			if ( preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) ) {
				$clean[] = $domain;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
