<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\Framework\Support\Arr;

/**
 * Single-use EDD discounts for abandoned carts: one code per cart per profile, created on first
 * use and reused by later emails so their expiry wording stays true.
 *
 * `default` backs `{{ab_cart_edd.recovery_discount_code}}`; named profiles back
 * `{{ab_cart_edd.discount.<profile>.code}}`. Profiles are edited under FluentCRM > Cart Discounts.
 */
class EddRecoveryDiscount {

	public const DEFAULT_PROFILE = 'default';

	public const OPTION = 'customcrm_edd_ab_cart_discount_profiles';

	/**
	 * Stored on first read, then edited on the Cart Discounts page. The named profiles match
	 * Recapture's unique-code discounts; its "40% Off (expires after 2 days)" became 3 days.
	 */
	private const STARTING_PROFILES = [
		self::DEFAULT_PROFILE => [ 'label' => 'Default recovery discount', 'type' => 'percent', 'amount' => 40, 'expiry_hours' => 48, 'min_amount' => 0, 'prefix' => 'CART' ],
		'pct40_3d'            => [ 'label' => '40% off, expires after 3 days', 'type' => 'percent', 'amount' => 40, 'expiry_hours' => 72, 'min_amount' => 1, 'prefix' => 'CART' ],
		'pct40_7d'            => [ 'label' => '40% off, expires after 7 days', 'type' => 'percent', 'amount' => 40, 'expiry_hours' => 168, 'min_amount' => 1, 'prefix' => 'CART' ],
		'pct20'               => [ 'label' => '20% off', 'type' => 'percent', 'amount' => 20, 'expiry_hours' => 0, 'min_amount' => 0, 'prefix' => 'CART' ],
		'pct10_1d'            => [ 'label' => '10% off, expires after 1 day', 'type' => 'percent', 'amount' => 10, 'expiry_hours' => 24, 'min_amount' => 0, 'prefix' => 'CART' ],
		'usd20'               => [ 'label' => '$20 off', 'type' => 'flat', 'amount' => 20, 'expiry_hours' => 0, 'min_amount' => 0, 'prefix' => 'CART' ],
	];

	/**
	 * Every profile, keyed by slug: the saved settings (Cart Discounts page), then the
	 * `customcrm/edd_ab_cart/discount_profiles` filter.
	 *
	 * @return array<string,array{label: string, type: string, amount: float, expiry_hours: int, min_amount: float, prefix: string}>
	 *         type is `percent` or `flat`; expiry_hours 0 means the code never expires.
	 */
	public function getProfiles(): array {
		$profiles = (array) apply_filters( 'customcrm/edd_ab_cart/discount_profiles', self::savedProfiles() );

		return self::normalizeProfiles( $profiles );
	}

	/**
	 * The profiles as saved on the Cart Discounts page. The first read stores the starting set.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function savedProfiles(): array {
		$saved = get_option( self::OPTION, null );

		if ( ! is_array( $saved ) ) {
			$saved = self::STARTING_PROFILES;
			add_option( self::OPTION, $saved, '', false );
		}

		return self::normalizeProfiles( $saved );
	}

	/**
	 * Replaces the saved profiles. The default profile is always kept.
	 *
	 * @param array<string,array<string,mixed>> $profiles Keyed by slug.
	 * @return array<string,array<string,mixed>> What was stored.
	 */
	public static function saveProfiles( array $profiles ): array {
		$normalized = self::normalizeProfiles( $profiles );

		update_option( self::OPTION, $normalized, false );

		return $normalized;
	}

	/**
	 * Cleans every profile and makes sure the default one exists.
	 *
	 * @param array<string,mixed> $profiles Keyed by slug.
	 * @return array<string,array{label: string, type: string, amount: float, expiry_hours: int, min_amount: float, prefix: string}>
	 */
	private static function normalizeProfiles( array $profiles ): array {
		$profiles  += [ self::DEFAULT_PROFILE => self::STARTING_PROFILES[ self::DEFAULT_PROFILE ] ];
		$normalized = [];

		foreach ( $profiles as $slug => $profile ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug ) {
				$normalized[ $slug ] = self::normalizeProfile( $slug, (array) $profile );
			}
		}

		// The default profile first, so it heads the settings page and the smart-code list.
		$default = [ self::DEFAULT_PROFILE => $normalized[ self::DEFAULT_PROFILE ] ];

		return $default + $normalized;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array{label: string, type: string, amount: float, expiry_hours: int, min_amount: float, prefix: string}
	 */
	private static function normalizeProfile( string $slug, array $profile ): array {
		$prefix = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) Arr::get( $profile, 'prefix', 'CART' ) ) );

		return [
			'label'        => sanitize_text_field( (string) Arr::get( $profile, 'label', $slug ) ),
			'type'         => 'flat' === Arr::get( $profile, 'type' ) ? 'flat' : 'percent',
			'amount'       => max( 0, (float) Arr::get( $profile, 'amount', 0 ) ),
			'expiry_hours' => max( 0, (int) Arr::get( $profile, 'expiry_hours', 0 ) ),
			'min_amount'   => max( 0, (float) Arr::get( $profile, 'min_amount', 0 ) ),
			'prefix'       => $prefix ?: 'CART',
		];
	}

	/**
	 * The default profile's settings.
	 *
	 * @return array{label: string, type: string, amount: float, expiry_hours: int, min_amount: float, prefix: string}
	 */
	public function getSettings(): array {
		return $this->getProfiles()[ self::DEFAULT_PROFILE ];
	}

	/**
	 * The profile's discount as shoppers read it.
	 *
	 * @param string $profile Profile slug.
	 * @return string "40%" or a formatted flat amount; empty for an unknown profile.
	 */
	public function getAmountLabel( string $profile = self::DEFAULT_PROFILE ): string {
		$settings = $this->getProfiles()[ $profile ] ?? null;

		if ( ! $settings ) {
			return '';
		}

		if ( 'flat' === $settings['type'] ) {
			return $this->formatFlatAmount( $settings['amount'] );
		}

		return $this->trimDecimals( $settings['amount'] ) . '%';
	}

	/**
	 * A flat amount in the store's currency format.
	 */
	private function formatFlatAmount( float $amount ): string {
		if ( ! function_exists( 'edd_currency_filter' ) ) {
			return '$' . $this->trimDecimals( $amount );
		}

		$formatted     = edd_format_amount( $amount );
		$with_currency = edd_currency_filter( $formatted );

		return html_entity_decode( $with_currency );
	}

	/**
	 * The amount without trailing zeros: 40.00 is "40", 12.50 is "12.5".
	 */
	private function trimDecimals( float $amount ): string {
		$fixed = number_format( $amount, 2, '.', '' );

		return rtrim( rtrim( $fixed, '0' ), '.' );
	}

	/**
	 * The cart's code for a profile, creating it in EDD if the cart does not have one yet.
	 *
	 * Email previews get a placeholder, so opening the editor never writes a discount to the store.
	 *
	 * @return array{code: string, expires: int, discount_id: int}|null Expiry is a Unix timestamp
	 *         (UTC), 0 when the code never expires. Null for an unknown profile.
	 */
	public function getOrCreate( AbandonCartModel $cart, string $profile = self::DEFAULT_PROFILE ): ?array {
		$settings = $this->getProfiles()[ $profile ] ?? null;

		if ( ! $settings ) {
			return null;
		}

		$existing = $this->getStored( $cart, $profile );

		if ( $existing ) {
			return $existing;
		}

		$expires = $settings['expiry_hours'] ? time() + ( $settings['expiry_hours'] * HOUR_IN_SECONDS ) : 0;

		if ( defined( 'FLUENTCRM_PREVIEWING_EMAIL' ) ) {
			return [
				'code'        => 'CART-PREVIEW',
				'expires'     => $expires,
				'discount_id' => 0,
			];
		}

		if ( ! function_exists( 'edd_add_discount' ) ) {
			return null;
		}

		$code = $this->uniqueCode( $settings['prefix'] );
		$args = [
			'name'              => sprintf( 'Cart recovery: %s (cart %d, %s)', $cart->email, $cart->id, $settings['label'] ),
			'code'              => $code,
			'status'            => 'active',
			'type'              => 'discount',
			'scope'             => 'global',
			'amount_type'       => $settings['type'],
			'amount'            => $settings['amount'],
			'max_uses'          => 1,
			'once_per_customer' => 1,
			'min_charge_amount' => $settings['min_amount'],
			'start_date'        => gmdate( 'Y-m-d H:i:s' ),
		];

		if ( $expires ) {
			$args['expiration'] = gmdate( 'Y-m-d H:i:s', $expires );
		}

		$discount_id = edd_add_discount( $args );

		if ( ! $discount_id ) {
			return null;
		}

		$discount = [
			'code'        => $code,
			'expires'     => $expires,
			'discount_id' => (int) $discount_id,
		];

		$data = $cart->cart ?: [];

		if ( self::DEFAULT_PROFILE === $profile ) {
			$data['recovery_discount'] = $discount;
		} else {
			$data['recovery_discounts']             = (array) Arr::get( $data, 'recovery_discounts', [] );
			$data['recovery_discounts'][ $profile ] = $discount;
		}

		$cart->cart = $data;
		$cart->save();

		return $discount;
	}

	/**
	 * Codes already issued to this cart, newest first.
	 *
	 * @return string[]
	 */
	public function getIssuedCodes( AbandonCartModel $cart ): array {
		$default = Arr::get( $cart->cart, 'recovery_discount' );
		$named   = array_values( (array) Arr::get( $cart->cart, 'recovery_discounts', [] ) );
		$issued  = array_filter( array_merge( [ $default ], $named ) );

		usort(
			$issued,
			function ( $a, $b ) {
				return (int) Arr::get( $b, 'discount_id' ) <=> (int) Arr::get( $a, 'discount_id' );
			}
		);

		return array_values( array_filter( wp_list_pluck( $issued, 'code' ) ) );
	}

	/**
	 * @return array{code: string, expires: int, discount_id: int}|null
	 */
	private function getStored( AbandonCartModel $cart, string $profile ): ?array {
		$stored = self::DEFAULT_PROFILE === $profile
			? Arr::get( $cart->cart, 'recovery_discount' )
			: Arr::get( $cart->cart, 'recovery_discounts.' . $profile );

		return is_array( $stored ) && ! empty( $stored['code'] ) ? $stored : null;
	}

	/**
	 * A discount code not already in EDD.
	 *
	 * @param string $prefix Uppercase letters and digits.
	 */
	private function uniqueCode( string $prefix ): string {
		do {
			// Not wp_generate_password(): plugins can filter its output through `random_password`.
			$code = $prefix . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
		} while ( function_exists( 'edd_get_discount_by_code' ) && edd_get_discount_by_code( $code ) );

		return $code;
	}
}
