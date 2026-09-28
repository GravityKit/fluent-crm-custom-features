<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\Framework\Support\Arr;

/**
 * Single-use EDD discounts for abandoned carts: one code per cart per profile, created on first
 * use and reused by later emails so their expiry wording stays true.
 *
 * `default` backs `{{ab_cart_edd.recovery_discount_code}}`; named profiles back
 * `{{ab_cart_edd.discount.<profile>.code}}` (filter `customcrm/edd_ab_cart/discount_profiles`).
 */
class EddRecoveryDiscount {

	public const DEFAULT_PROFILE = 'default';

	/**
	 * Every profile, keyed by slug.
	 *
	 * @return array<string,array{label: string, type: string, amount: float, expiry_hours: int, min_amount: float, prefix: string}>
	 *         type is `percent` or `flat`; expiry_hours 0 means the code never expires.
	 */
	public function getProfiles(): array {
		$default = (array) apply_filters(
			'customcrm/edd_ab_cart/recovery_discount',
			[
				'amount'       => 40,
				'expiry_hours' => 48,
				'prefix'       => 'CART',
			]
		);

		// Recapture's unique-code discounts. Its "40% Off (expires after 2 days)" was set to 3 days.
		$profiles = (array) apply_filters(
			'customcrm/edd_ab_cart/discount_profiles',
			[
				'pct40_3d' => [ 'label' => '40% off, expires after 3 days', 'type' => 'percent', 'amount' => 40, 'expiry_hours' => 72, 'min_amount' => 1 ],
				'pct40_7d' => [ 'label' => '40% off, expires after 7 days', 'type' => 'percent', 'amount' => 40, 'expiry_hours' => 168, 'min_amount' => 1 ],
				'pct20'    => [ 'label' => '20% off', 'type' => 'percent', 'amount' => 20, 'expiry_hours' => 0, 'min_amount' => 0 ],
				'pct10_1d' => [ 'label' => '10% off, expires after 1 day', 'type' => 'percent', 'amount' => 10, 'expiry_hours' => 24, 'min_amount' => 0 ],
				'usd20'    => [ 'label' => '$20 off', 'type' => 'flat', 'amount' => 20, 'expiry_hours' => 0, 'min_amount' => 0 ],
			]
		);

		$profiles = [ self::DEFAULT_PROFILE => array_merge( [ 'label' => 'Default recovery discount', 'type' => 'percent' ], $default ) ] + $profiles;

		$normalized = [];

		foreach ( $profiles as $slug => $profile ) {
			$slug = sanitize_key( (string) $slug );

			if ( ! $slug ) {
				continue;
			}

			$normalized[ $slug ] = [
				'label'        => (string) Arr::get( $profile, 'label', $slug ),
				'type'         => 'flat' === Arr::get( $profile, 'type' ) ? 'flat' : 'percent',
				'amount'       => (float) Arr::get( $profile, 'amount', 0 ),
				'expiry_hours' => max( 0, (int) Arr::get( $profile, 'expiry_hours', 0 ) ),
				'min_amount'   => max( 0, (float) Arr::get( $profile, 'min_amount', 0 ) ),
				'prefix'       => preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) Arr::get( $profile, 'prefix', 'CART' ) ) ) ?: 'CART',
			];
		}

		return $normalized;
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

		$amount = rtrim( rtrim( number_format( $settings['amount'], 2, '.', '' ), '0' ), '.' );

		if ( 'flat' === $settings['type'] ) {
			return function_exists( 'edd_currency_filter' ) ? html_entity_decode( edd_currency_filter( edd_format_amount( $settings['amount'] ) ) ) : '$' . $amount;
		}

		return $amount . '%';
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
		$issued = array_filter( array_merge( [ Arr::get( $cart->cart, 'recovery_discount' ) ], array_values( (array) Arr::get( $cart->cart, 'recovery_discounts', [] ) ) ) );

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
