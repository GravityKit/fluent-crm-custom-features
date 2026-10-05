<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\Framework\Support\Arr;

/**
 * License upgrades left at an EDD checkout, as their own FluentCRM cart provider: the buyer is an
 * existing customer, so they get their own automation and never the new-customer sequence or its
 * discount.
 *
 * Every price in the emails is worked out when the email is sent, from the license and upgrade
 * path stored on the cart: Software Licensing prorates upgrades by time, so the price drops a
 * little every day.
 */
class EddUpgradeCartDriver extends EddCartDriver {

	public const PROVIDER = 'edd_upgrade';

	/**
	 * Default days during which a contact who entered the upgrade automation does not get it again.
	 * Filter: `customcrm/edd_ab_cart/upgrade_resend_cap_days`.
	 */
	private const RESEND_CAP_DAYS = 60;

	/** A license expiring within this many days gets the "renew first, upgrade then" line. */
	private const RENEWAL_SOON_DAYS = 30;

	/** Order statuses that count as a completed, paid order. Refunded and revoked orders do not. */
	private const PAID_ORDER_STATUSES = [ 'publish', 'complete', 'completed', 'partially_refunded' ];

	/**
	 * Carts skipped in this request, keyed by cart ID, with the reason to store once the runner is done.
	 *
	 * @var array<int,string>
	 */
	private static $skip_notes = [];

	/** Renewal cart statuses that mean a renewal is under way. */
	private const OPEN_RENEWAL_STATUSES = [ 'draft', 'pending', 'processing' ];

	/**
	 * Smart codes that are empty once the upgrade can no longer be bought.
	 */
	private const PRICE_CODES = [ 'full_price', 'today_price', 'credit', 'price_breakdown', 'price_change_line', 'renewal_line' ];

	/**
	 * Provider key stored in `fc_abandoned_carts.provider`.
	 *
	 * @return string
	 */
	public function getProviderSlug() {
		return self::PROVIDER;
	}

	/**
	 * Provider name shown in FluentCRM settings and reports.
	 *
	 * @return string
	 */
	public function getProviderLabel() {
		return __( 'Easy Digital Downloads (license upgrades)', 'fluent-crm-custom-features' );
	}

	/**
	 * Whether EDD and EDD Software Licensing's upgrade functions are active.
	 *
	 * @return bool
	 */
	public function isAvailable() {
		return parent::isAvailable() && function_exists( 'edd_software_licensing' ) && function_exists( 'edd_sl_get_license_upgrade_cost' );
	}

	/**
	 * Registers the upgrade automation trigger.
	 *
	 * @return void
	 */
	public function registerAutomationTrigger() {
		new EddUpgradeCartAutomationTrigger();
	}

	/**
	 * Skip an upgrade cart when any of these hold:
	 *
	 * - its email is outside internal-only mode's domains;
	 * - no license in it can still take its upgrade (already upgraded, or the path is gone);
	 * - the contact completed a paid order after leaving the cart;
	 * - a license in it is being renewed, or was renewed after the cart was saved (renewal wins);
	 * - the contact entered this automation within the resend cap.
	 *
	 * The reason is stored on the cart in place of the runner's "Under Cool Off Period" note.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function isWithinCoolOffPeriod( AbandonCartModel $cart ) {
		if ( AllowedDomains::holdBack( $cart ) ) {
			return true;
		}

		$reason = $this->skipReason( $cart );

		if ( '' === $reason ) {
			return false;
		}

		$data                    = $cart->cart ?: [];
		$data['skipped_because'] = $reason;
		$cart->cart              = $data;

		if ( ! self::$skip_notes ) {
			add_action( 'shutdown', [ self::class, 'writeSkipNotes' ], 1 );
		}

		self::$skip_notes[ (int) $cart->id ] = $reason;

		return true;
	}

	/**
	 * Why this cart should not start the upgrade automation; empty when it should.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function skipReason( AbandonCartModel $cart ): string {
		if ( ! $this->openUpgradeItems( $cart ) ) {
			$closed_reasons = array_map( [ $this, 'upgradeClosedReason' ], self::upgradeItems( $cart ) );

			return in_array( 'upgraded', $closed_reasons, true )
				? __( 'Skipped: the license was already upgraded', 'fluent-crm-custom-features' )
				: __( 'Skipped: the upgrade is no longer available for this license', 'fluent-crm-custom-features' );
		}

		if ( $this->paidOrderSince( $cart ) ) {
			return __( 'Skipped: the contact completed a paid order after leaving the cart', 'fluent-crm-custom-features' );
		}

		if ( $this->renewalUnderWay( $cart ) ) {
			return __( 'Skipped: a renewal for this license is under way', 'fluent-crm-custom-features' );
		}

		if ( $this->sentRecently( $cart ) ) {
			/* translators: %d: days */
			return sprintf( __( 'Skipped: the contact got the upgrade emails within the last %d days', 'fluent-crm-custom-features' ), $this->resendCapDays( $cart ) );
		}

		return '';
	}

	/**
	 * Replaces the runner's "Under Cool Off Period" note on skipped carts with the real reason.
	 */
	public static function writeSkipNotes(): void {
		foreach ( self::$skip_notes as $cart_id => $note ) {
			AbandonCartModel::where( 'id', $cart_id )->where( 'status', 'skipped' )->update( [ 'note' => $note ] );
		}

		self::$skip_notes = [];
	}

	/**
	 * Only this upgrade automation counts toward the cap. Recapture's list of shoppers it emailed
	 * is about new-purchase carts, so it is not checked here.
	 *
	 * @param AbandonCartModel $cart
	 */
	protected function sentRecently( AbandonCartModel $cart ): bool {
		return $this->enteredAutomationWithin( $cart, $this->resendCapDays( $cart ) );
	}

	/**
	 * Days during which a contact who entered the upgrade automation does not get it again.
	 *
	 * @param AbandonCartModel $cart
	 */
	protected function resendCapDays( AbandonCartModel $cart ): int {
		return (int) apply_filters( 'customcrm/edd_ab_cart/upgrade_resend_cap_days', self::RESEND_CAP_DAYS, $cart );
	}

	/**
	 * The cart's upgrade items.
	 *
	 * @param AbandonCartModel $cart
	 * @return array<int,array<string,mixed>>
	 */
	public static function upgradeItems( AbandonCartModel $cart ): array {
		return array_values(
			array_filter(
				(array) Arr::get( $cart->cart, 'cart_contents', [] ),
				function ( $item ) {
					return ! empty( $item['is_upgrade'] ) && ! empty( $item['license_id'] );
				}
			)
		);
	}

	/**
	 * License IDs of the cart's upgrade items.
	 *
	 * @param AbandonCartModel $cart
	 * @return int[]
	 */
	public static function upgradeLicenseIds( AbandonCartModel $cart ): array {
		return array_values( array_unique( array_map( 'intval', wp_list_pluck( self::upgradeItems( $cart ), 'license_id' ) ) ) );
	}

	/**
	 * Upgrade items whose license can still take the upgrade that was left at checkout.
	 *
	 * @param AbandonCartModel $cart
	 * @return array<int,array<string,mixed>>
	 */
	public function openUpgradeItems( AbandonCartModel $cart ): array {
		return array_values( array_filter( self::upgradeItems( $cart ), [ $this, 'isUpgradeOpen' ] ) );
	}

	/**
	 * Whether this license can still be upgraded along this item's path.
	 *
	 * @param array<string,mixed> $item Stored upgrade cart item.
	 */
	public function isUpgradeOpen( array $item ): bool {
		return '' === $this->upgradeClosedReason( $item );
	}

	/**
	 * Why this license can no longer take this item's upgrade, or empty when it can.
	 *
	 * `upgraded` once the license moved to another plan (this upgrade, or a different one, went
	 * through) or is already on the path's plan. `unavailable` when it is gone, disabled, revoked
	 * or expired (Software Licensing refuses to upgrade those), or the path is no longer offered.
	 *
	 * @param array<string,mixed> $item Stored upgrade cart item.
	 * @return string `upgraded`, `unavailable`, or empty.
	 */
	public function upgradeClosedReason( array $item ): string {
		$license = edd_software_licensing()->get_license( (int) Arr::get( $item, 'license_id' ) );

		if ( ! $license || in_array( $license->status, [ 'revoked', 'disabled', 'expired' ], true ) ) {
			return 'unavailable';
		}

		$captured_download = Arr::get( $item, 'license_download_id' );
		$captured_price    = Arr::get( $item, 'license_price_id' );
		$plan_changed      = null !== $captured_download
			&& ( (int) $captured_download !== (int) $license->download_id || (int) $captured_price !== (int) $license->price_id );

		if ( $plan_changed ) {
			return 'upgraded';
		}

		$upgrade_id = (int) Arr::get( $item, 'upgrade_id' );
		$path       = edd_sl_get_upgrade_path( $license->download_id, $upgrade_id );

		if ( ! $path ) {
			return 'unavailable';
		}

		$already_on_path = (int) $path['download_id'] === (int) $license->download_id && (int) $path['price_id'] === (int) $license->price_id;

		if ( $already_on_path ) {
			return 'upgraded';
		}

		$offered = (array) edd_sl_get_license_upgrades( $license->ID );

		return isset( $offered[ $upgrade_id ] ) ? '' : 'unavailable';
	}

	/**
	 * Whether the contact completed a paid order after the cart was saved.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function paidOrderSince( AbandonCartModel $cart ): bool {
		if ( ! $cart->email || ! function_exists( 'edd_get_orders' ) ) {
			return false;
		}

		// Carts are dated in site time, orders in UTC.
		$since = get_gmt_from_date( (string) $cart->created_at );
		$args  = [
			'type'       => 'sale',
			'status__in' => self::PAID_ORDER_STATUSES,
			'number'     => 50,
			'date_query' => [
				[
					'after'     => $since,
					'inclusive' => true,
				],
			],
		];

		$orders = edd_get_orders( $args + [ 'email' => $cart->email ] );

		if ( $cart->user_id ) {
			$orders = array_merge( $orders, edd_get_orders( $args + [ 'user_id' => (int) $cart->user_id ] ) );
		}

		foreach ( $orders as $order ) {
			if ( (float) $order->total > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a license in the cart has an open renewal cart, or was renewed after the cart was saved.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function renewalUnderWay( AbandonCartModel $cart ): bool {
		$license_ids = self::upgradeLicenseIds( $cart );

		if ( ! $license_ids ) {
			return false;
		}

		if ( array_intersect( $license_ids, self::licensesWithOpenRenewalCarts() ) ) {
			return true;
		}

		foreach ( self::upgradeItems( $cart ) as $item ) {
			$license = edd_software_licensing()->get_license( (int) $item['license_id'] );

			// Lifetime licenses do not renew.
			if ( ! $license || $license->is_lifetime ) {
				continue;
			}

			$saved_expiration = (int) Arr::get( $item, 'license_expiration', 0 );
			$renewed          = $saved_expiration > 0 && (int) $license->expiration > $saved_expiration;

			if ( $renewed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * License IDs in renewal carts that are being built or emailed.
	 *
	 * @return int[]
	 */
	public static function licensesWithOpenRenewalCarts(): array {
		$license_ids = [];

		$carts = AbandonCartModel::where( 'provider', EddRenewalCartDriver::PROVIDER )
			->whereIn( 'status', self::OPEN_RENEWAL_STATUSES )
			->get();

		foreach ( $carts as $renewal ) {
			foreach ( (array) Arr::get( $renewal->cart, 'cart_contents', [] ) as $item ) {
				if ( ! empty( $item['license_id'] ) && empty( $item['is_upgrade'] ) ) {
					$license_ids[] = (int) $item['license_id'];
				}
			}
		}

		return array_values( array_unique( $license_ids ) );
	}

	/**
	 * Value of one of this provider's own smart codes, or null when the key is not one of them.
	 *
	 * Works on the cart's first upgrade item: Software Licensing puts one upgrade in a cart at a time.
	 *
	 * @param AbandonCartModel $cart
	 * @param string           $value_key     Part after the group key.
	 * @param string           $default_value Fallback after the pipe.
	 * @return string|null
	 */
	public function getUpgradeCodeValue( AbandonCartModel $cart, string $value_key, string $default_value ): ?string {
		$codes = [ 'current_plan', 'new_plan', 'current_product', 'new_plan_name', 'full_price', 'today_price', 'credit', 'price_breakdown', 'price_change_line', 'renewal_line', 'renewal_soon_line', 'new_plan_extras' ];

		if ( ! in_array( $value_key, $codes, true ) ) {
			return null;
		}

		$items = self::upgradeItems( $cart );
		$item  = $items[0] ?? null;

		if ( ! $item ) {
			return $default_value;
		}

		$license = edd_software_licensing()->get_license( (int) $item['license_id'] );
		$path    = $license ? edd_sl_get_upgrade_path( $license->download_id, (int) $item['upgrade_id'] ) : false;

		if ( ! $license || ! $path ) {
			return $default_value;
		}

		$new_download = (int) $path['download_id'];
		$new_price_id = isset( $path['price_id'] ) && is_numeric( $path['price_id'] ) ? (int) $path['price_id'] : null;
		$is_open      = $this->isUpgradeOpen( $item );

		if ( ! $is_open && in_array( $value_key, self::PRICE_CODES, true ) ) {
			return $default_value;
		}

		switch ( $value_key ) {
			case 'current_plan':
				return self::planName( (int) $license->download_id, $license->price_id );
			case 'new_plan':
				return self::planName( $new_download, $new_price_id );
			case 'current_product':
				return self::planName( (int) $license->download_id, null );
			case 'new_plan_name':
				return self::newPlanName( $license, $new_download, $new_price_id );
			case 'full_price':
				$lines = $this->priceLines( $cart, $license, $item, $path );
				return $this->formatPrice( $lines['full_price'], $lines['currency'] );
			case 'today_price':
				$lines = $this->priceLines( $cart, $license, $item, $path );
				return $this->formatPrice( $lines['today'], $lines['currency'] );
			case 'credit':
				$lines = $this->priceLines( $cart, $license, $item, $path );
				return $this->formatPrice( $lines['credit'], $lines['currency'] );
			case 'price_breakdown':
				return $this->priceBreakdown( $cart, $license, $item, $path );
			case 'price_change_line':
				return esc_html( $this->priceChangeLine( $license, $path, $new_download, $new_price_id ) );
			case 'renewal_line':
				return esc_html( $this->renewalLine( $license, $new_download, $new_price_id ) );
			case 'renewal_soon_line':
				return $this->renewalSoonLine( $license );
			case 'new_plan_extras':
				return $this->newPlanExtras( $license, $new_download, $new_price_id );
		}

		return $default_value;
	}

	/**
	 * Product name plus price option, e.g. "GravityView — Up to 3 Sites", escaped for email HTML.
	 *
	 * @param int      $download_id
	 * @param int|null $price_id
	 */
	private static function planName( int $download_id, $price_id ): string {
		$price_id = is_numeric( $price_id ) ? (int) $price_id : null;
		$name     = (string) edd_get_download_name( $download_id, $price_id );

		return esc_html( wp_specialchars_decode( wp_strip_all_tags( $name ), ENT_QUOTES ) );
	}

	/**
	 * How the emails name the new plan, without the site count: the product when the upgrade moves
	 * to another product ("All Access Pass"). For a different plan of the same product it names
	 * what changes: "a GravityImport plan with more sites", "a lifetime GravityImport plan", or both.
	 * Written to sit mid-sentence, so the article stays lowercase.
	 *
	 * @param \EDD_SL_License $license      The license being upgraded.
	 * @param int             $new_download The upgrade path's download.
	 * @param int|null        $new_price_id The upgrade path's price, when the product has variable prices.
	 */
	private static function newPlanName( $license, int $new_download, $new_price_id ): string {
		$current_download = (int) $license->download_id;

		if ( $new_download !== $current_download ) {
			return self::planName( $new_download, null );
		}

		$product = self::planName( $current_download, null );

		// An activation limit of 0 means unlimited sites.
		$current_limit = (int) edd_software_licensing()->get_price_activation_limit( $current_download, $license->price_id );
		$new_limit     = (int) edd_software_licensing()->get_price_activation_limit( $current_download, $new_price_id );
		$more_sites    = 0 !== $current_limit && ( 0 === $new_limit || $new_limit > $current_limit );
		$to_lifetime   = ! $license->is_lifetime && edd_software_licensing()->get_price_is_lifetime( $current_download, $new_price_id );

		if ( $more_sites && $to_lifetime ) {
			/* translators: %s: product name, e.g. GravityImport */
			return sprintf( esc_html__( 'a lifetime %s plan with more sites', 'fluent-crm-custom-features' ), $product );
		}

		if ( $more_sites ) {
			/* translators: %s: product name, e.g. GravityImport */
			return sprintf( esc_html__( 'a %s plan with more sites', 'fluent-crm-custom-features' ), $product );
		}

		if ( $to_lifetime ) {
			/* translators: %s: product name, e.g. GravityImport */
			return sprintf( esc_html__( 'a lifetime %s plan', 'fluent-crm-custom-features' ), $product );
		}

		/* translators: %s: product name, e.g. GravityImport */
		return sprintf( esc_html__( 'a different %s plan', 'fluent-crm-custom-features' ), $product );
	}

	/**
	 * The new plan's regular price in the store currency, as Software Licensing reads it.
	 *
	 * @param int      $download_id
	 * @param int|null $price_id
	 */
	private static function regularPrice( int $download_id, ?int $price_id ): float {
		return (float) ( null === $price_id ? edd_get_download_price( $download_id ) : edd_get_price_option_amount( $download_id, $price_id ) );
	}

	/**
	 * What the upgrade costs today, in the store currency.
	 *
	 * @param array<string,mixed> $item
	 */
	private function todayPrice( array $item ): float {
		return (float) edd_sl_get_license_upgrade_cost( (int) $item['license_id'], (int) $item['upgrade_id'] );
	}

	/**
	 * Runs a price calculation in the cart's currency.
	 *
	 * EDD Multi Currency converts and rounds each product price into the shopper's currency, and
	 * Software Licensing prorates those converted prices. Converting a store-currency result instead
	 * can land a cent or more away from what checkout shows, so the calculation runs with the cart's
	 * currency selected, the way Multi Currency selects it from a `?currency=` link.
	 *
	 * @param AbandonCartModel $cart
	 * @param callable         $calculate Returns the amounts.
	 * @return array{0: mixed, 1: string} What the callback returned, and the currency it is in.
	 */
	private function inCartCurrency( AbandonCartModel $cart, callable $calculate ): array {
		$store    = (string) edd_get_currency();
		$currency = strtoupper( (string) ( $cart->currency ?: $store ) );

		$can_switch = $currency !== $store
			&& function_exists( 'eddMultiCurrency' )
			&& class_exists( '\\EDD_Multi_Currency\\Utils\\Currency' )
			&& \EDD_Multi_Currency\Utils\Currency::isValidCurrency( $currency );

		if ( ! $can_switch ) {
			return [ $calculate(), $store ];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- restored below; nothing is read from the request.
		$had_get          = array_key_exists( 'currency', $_GET );
		$previous_get     = $had_get ? $_GET['currency'] : null;
		$previous_session = EDD()->session->get( 'currency' );
		$_GET['currency'] = $currency;

		try {
			// Multi Currency ignores `?currency=` in some contexts (wp-admin, some AJAX actions).
			$is_selected = edd_get_currency() === $currency;
			$result      = $is_selected ? $calculate() : null;
		} finally {
			if ( $had_get ) {
				$_GET['currency'] = $previous_get;
			} else {
				unset( $_GET['currency'] );
			}
			EDD()->session->set( 'currency', $previous_session );
		}
		// phpcs:enable

		if ( ! $is_selected ) {
			// Priced in whatever currency is selected here, and labelled with it.
			return [ $calculate(), (string) edd_get_currency() ];
		}

		return [ $result, $currency ];
	}

	/**
	 * The breakdown's three amounts, in the cart's currency and rounded to the currency's decimals.
	 *
	 * Today's price is Software Licensing's own cost, filters and rounding included. The first line
	 * is the new plan's price for what SL charges: the rest of the current term when SL prorates by
	 * time and the new plan has a term, otherwise the full price. The credit is the difference, so
	 * the lines always add up to what the customer pays.
	 *
	 * @param AbandonCartModel    $cart
	 * @param \EDD_SL_License     $license
	 * @param array<string,mixed> $item Stored upgrade cart item.
	 * @param array<string,mixed> $path Upgrade path.
	 * @return array{full_price: float, new_plan: float, credit: float, today: float, currency: string, time_based: bool, until_expiration: bool}
	 */
	private function priceLines( AbandonCartModel $cart, $license, array $item, array $path ): array {
		[ $lines, $currency ] = $this->inCartCurrency(
			$cart,
			function () use ( $license, $item, $path ) {
				$new_download = (int) $path['download_id'];
				$new_price_id = isset( $path['price_id'] ) && is_numeric( $path['price_id'] ) ? (int) $path['price_id'] : null;
				$full_price   = self::regularPrice( $new_download, $new_price_id );
				$seconds_used = self::secondsUsedIfProratedByTime( $license, $path, $full_price );
				$new_length   = edd_sl_get_product_license_length( $new_download, $new_price_id ?? false );
				$until_expiry = null !== $seconds_used && 'lifetime' !== $new_length;
				$new_plan     = $full_price;

				if ( $until_expiry ) {
					// Same arithmetic as edd_sl_get_time_based_pro_rated_upgrade_cost().
					$midnight_today = strtotime( 'today midnight' );
					$new_seconds    = strtotime( $new_length, $midnight_today ) - $midnight_today;
					$new_plan       = $full_price * abs( 1 - $seconds_used / $new_seconds );
				}

				return [
					'full_price'       => $full_price,
					'new_plan'         => $new_plan,
					'today'            => $this->todayPrice( $item ),
					'time_based'       => null !== $seconds_used,
					'until_expiration' => $until_expiry,
				];
			}
		);

		$decimals = (int) edd_currency_decimal_filter();
		$new_plan = round( $lines['new_plan'], $decimals );
		$today    = round( $lines['today'], $decimals );

		return [
			'full_price'       => round( $lines['full_price'], $decimals ),
			'new_plan'         => $new_plan,
			'credit'           => round( $new_plan - $today, $decimals ),
			'today'            => $today,
			'currency'         => $currency,
			'time_based'       => $lines['time_based'],
			'until_expiration' => $lines['until_expiration'],
		];
	}

	/**
	 * How much of the current term is used, when SL prices this upgrade by time; null when SL
	 * falls back to cost-based pricing (or does not prorate the path at all).
	 *
	 * Mirrors edd_sl_get_license_upgrade_cost() and edd_sl_get_time_based_pro_rated_upgrade_cost()
	 * in Software Licensing 3.9.5: cost-based when the path is not prorated, the method is
	 * cost-based, the license is lifetime, or it was bought within the minimum time (a day).
	 *
	 * @param \EDD_SL_License     $license
	 * @param array<string,mixed> $path       Upgrade path.
	 * @param float               $full_price The new plan's regular price.
	 * @return int|null Seconds.
	 */
	private static function secondsUsedIfProratedByTime( $license, array $path, float $full_price ): ?int {
		if ( empty( $path['pro_rated'] ) ) {
			return null;
		}

		$method    = apply_filters( 'edd_sl_proration_method', edd_get_option( 'edd_sl_proration_method', 'cost-based' ), $license->ID, self::oldPrice( $license ), $full_price );
		$is_simple = 'cost-based' === $method || apply_filters( 'edd_sl_license_upgrade_pro_rate_simple', false );

		if ( $is_simple || $license->is_lifetime ) {
			return null;
		}

		$license_length = edd_software_licensing()->get_license_length( $license->ID, $license->payment_id, $license->download_id );
		$midnight_today = strtotime( 'today midnight' );
		$length_seconds = strtotime( $license_length, $midnight_today ) - $midnight_today;
		$seconds_left   = absint( edd_software_licensing()->get_license_expiration( $license->ID ) - time() + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$seconds_used   = $length_seconds - $seconds_left;
		$minimum_time   = apply_filters( 'edd_sl_get_time_based_pro_rated_minimum_time', DAY_IN_SECONDS );
		$within_minimum = $minimum_time >= $seconds_used;

		return $within_minimum ? null : (int) $seconds_used;
	}

	/**
	 * The current plan's price as Software Licensing reads it for proration (the price option's current amount).
	 *
	 * @param \EDD_SL_License $license
	 */
	private static function oldPrice( $license ): float {
		$is_variable = edd_has_variable_prices( $license->download_id );

		return (float) ( $is_variable ? edd_get_price_option_amount( $license->download_id, $license->price_id ) : edd_get_download_price( $license->download_id ) );
	}

	/**
	 * Three lines that add up: the new plan's price, the credit, and what is due today.
	 *
	 * @param AbandonCartModel    $cart
	 * @param \EDD_SL_License     $license
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $path
	 */
	private function priceBreakdown( AbandonCartModel $cart, $license, array $item, array $path ): string {
		$lines        = $this->priceLines( $cart, $license, $item, $path );
		$new_price_id = isset( $path['price_id'] ) && is_numeric( $path['price_id'] ) ? (int) $path['price_id'] : null;
		$new_plan     = self::planName( (int) $path['download_id'], $new_price_id );
		$current_plan = self::planName( (int) $license->download_id, $license->price_id );

		if ( $lines['until_expiration'] ) {
			/* translators: 1: new plan, 2: date the current term ends */
			$new_plan = sprintf( __( '%1$s until %2$s', 'fluent-crm-custom-features' ), $new_plan, self::formatExpiration( (int) $license->expiration ) );
		}

		$credit_label = $lines['time_based']
			/* translators: %s: current plan */
			? sprintf( __( 'Credit for the unused time on %s', 'fluent-crm-custom-features' ), $current_plan )
			/* translators: %s: current plan */
			: sprintf( __( 'Credit for %s', 'fluent-crm-custom-features' ), $current_plan );

		$rows = [
			sprintf( '%s: %s', $new_plan, $this->formatPrice( $lines['new_plan'], $lines['currency'] ) ),
			sprintf( '%s: −%s', $credit_label, $this->formatPrice( $lines['credit'], $lines['currency'] ) ),
			/* translators: %s: amount due today */
			'<strong>' . sprintf( __( 'You pay today: %s', 'fluent-crm-custom-features' ), $this->formatPrice( $lines['today'], $lines['currency'] ) ) . '</strong>',
		];

		return '<p class="customcrm-upgrade-price" style="margin:0 0 16px;line-height:1.8">' . implode( '<br>', $rows ) . '</p>';
	}

	/**
	 * Which way the price moves from day to day, when Software Licensing prorates by time.
	 *
	 * The credit is the unused share of the current plan, so it shrinks every day. Moving to a
	 * plan with a term, the new plan's remaining share shrinks faster, so the price drops. Moving
	 * to a lifetime plan, the new plan's price is fixed, so the price rises. Empty when the price
	 * does not change daily: cost-based proration, a lifetime license, or a path that is not prorated.
	 *
	 * @param \EDD_SL_License     $license
	 * @param array<string,mixed> $path Upgrade path.
	 * @param int                 $new_download
	 * @param int|null            $new_price_id
	 */
	private function priceChangeLine( $license, array $path, int $new_download, ?int $new_price_id ): string {
		$method     = apply_filters( 'edd_sl_proration_method', edd_get_option( 'edd_sl_proration_method', 'cost-based' ), $license->ID, self::oldPrice( $license ), self::regularPrice( $new_download, $new_price_id ) );
		$time_based = 'cost-based' !== $method && ! apply_filters( 'edd_sl_license_upgrade_pro_rate_simple', false );

		if ( ! $time_based || empty( $path['pro_rated'] ) || $license->is_lifetime ) {
			return '';
		}

		if ( 'lifetime' === edd_sl_get_product_license_length( $new_download, $new_price_id ?? false ) ) {
			return __( 'This price is for today. It goes up a little each day, because your credit covers the time left on your current plan, and that time gets shorter.', 'fluent-crm-custom-features' );
		}

		return __( 'This price is for today. It gets a little lower each day, because your credit covers the time left on your current plan.', 'fluent-crm-custom-features' );
	}

	/**
	 * What happens to the renewal date: nothing to renew, the date stays, or the new date. Empty
	 * when the date cannot be worked out.
	 *
	 * @param \EDD_SL_License $license
	 * @param int             $new_download
	 * @param int|null        $new_price_id
	 */
	private function renewalLine( $license, int $new_download, ?int $new_price_id ): string {
		$new_expiration = self::expirationAfterUpgrade( $license, $new_download, $new_price_id );

		if ( 'lifetime' === $new_expiration ) {
			return __( 'It’s a lifetime license, so there’s nothing to renew.', 'fluent-crm-custom-features' );
		}

		if ( ! $new_expiration ) {
			return '';
		}

		$date = self::formatExpiration( (int) $new_expiration );

		if ( (int) $new_expiration === (int) $license->expiration ) {
			/* translators: %s: date */
			return sprintf( __( 'Your renewal date stays %s.', 'fluent-crm-custom-features' ), $date );
		}

		/* translators: %s: date */
		return sprintf( __( 'Your license will then renew on %s.', 'fluent-crm-custom-features' ), $date );
	}

	/**
	 * The expiration the upgrade would give the license, by the rule in edd_sl_process_license_upgrade()
	 * (Software Licensing 3.9.5, standard licenses).
	 *
	 * A lifetime price makes the license lifetime. The same term length keeps the expiration. A
	 * different length counts the new length from the license's latest order (`date_created`), or
	 * from now when the license had no expiration. SL's own edd_sl_get_product_expiration_date()
	 * counts from the completed date instead, so it can disagree with the handler by a day.
	 *
	 * @param \EDD_SL_License $license
	 * @param int             $new_download
	 * @param int|null        $new_price_id
	 * @return int|string|false Timestamp, `lifetime`, or false when it cannot be worked out.
	 */
	private static function expirationAfterUpgrade( $license, int $new_download, ?int $new_price_id ) {
		$new_length = edd_sl_get_product_license_length( $new_download, $new_price_id ?? false );

		if ( 'lifetime' === $new_length ) {
			return 'lifetime';
		}

		// One base time for both, so the comparison can't straddle a second boundary.
		$base         = time();
		$old_length   = $license->license_length();
		$old_seconds  = 'lifetime' !== $old_length ? strtotime( $old_length, $base ) : 'lifetime';
		$length_moves = $old_seconds !== strtotime( $new_length, $base );

		if ( ! $length_moves ) {
			return (int) $license->expiration;
		}

		if ( 'lifetime' === $old_length || empty( $license->expiration ) ) {
			return strtotime( $new_length, current_time( 'timestamp' ) );
		}

		$payment_ids = (array) $license->payment_ids;
		$last_order  = $payment_ids ? edd_get_order( (int) end( $payment_ids ) ) : false;

		return $last_order ? strtotime( $new_length, strtotime( $last_order->date_created ) ) : false;
	}

	/**
	 * A nudge to upgrade at renewal instead, when the license renews within 30 days.
	 *
	 * Returned as its own paragraph, so an email can place it with no wrapper and an empty value
	 * leaves nothing behind.
	 *
	 * @param \EDD_SL_License $license
	 * @return string `<p>` HTML, or empty.
	 */
	private function renewalSoonLine( $license ): string {
		$expiration  = (int) $license->expiration;
		$now         = current_time( 'timestamp' );
		$renews_soon = ! $license->is_lifetime && $expiration > $now && $expiration - $now <= self::RENEWAL_SOON_DAYS * DAY_IN_SECONDS;

		if ( ! $renews_soon ) {
			return '';
		}

		/* translators: %s: date */
		$sentence = sprintf( __( 'Your license renews on %s. If you’d rather upgrade then, just reply and we’ll set it up.', 'fluent-crm-custom-features' ), self::formatExpiration( $expiration ) );

		return '<p>' . esc_html( $sentence ) . '</p>';
	}

	/**
	 * License dates are stored in site time, so they are formatted without a timezone shift.
	 *
	 * @param int $timestamp
	 */
	private static function formatExpiration( int $timestamp ): string {
		return (string) date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * A bullet list of what the new plan adds.
	 *
	 * - A bundle: its products the current license does not cover.
	 * - An All Access pass: every GravityKit plugin.
	 * - The same product on a bigger price option: the new option in place of the current one.
	 * - Anything else: the new product with updates and support.
	 *
	 * Filter the list with `customcrm/edd_ab_cart/upgrade_plan_extras`.
	 *
	 * @param \EDD_SL_License $license
	 * @param int             $new_download
	 * @param int|null        $new_price_id
	 */
	private function newPlanExtras( $license, int $new_download, ?int $new_price_id ): string {
		$current_download = (int) $license->download_id;
		$extras           = [];

		$is_all_access = function_exists( 'edd_all_access_download_is_all_access' ) && edd_all_access_download_is_all_access( $new_download );

		if ( edd_is_bundled_product( $new_download ) ) {
			$covered = array_merge( [ $current_download ], self::bundledIds( $current_download, $license->price_id ) );

			foreach ( self::bundledIds( $new_download, $new_price_id ) as $product_id ) {
				if ( ! in_array( $product_id, $covered, true ) ) {
					$extras[] = self::planName( $product_id, null );
				}
			}
		} elseif ( $is_all_access ) {
			// Falls through to the "every GravityKit plugin" line below.
			$extras = [];
		} elseif ( $new_download === $current_download && null !== $new_price_id ) {
			$extras[] = sprintf(
				/* translators: 1: new price option, 2: current price option */
				esc_html__( '%1$s, instead of %2$s', 'fluent-crm-custom-features' ),
				esc_html( (string) edd_get_price_option_name( $new_download, $new_price_id ) ),
				esc_html( (string) edd_get_price_option_name( $current_download, $license->price_id ) )
			);
		} else {
			/* translators: %s: product name */
			$extras[] = sprintf( esc_html__( 'Everything in %s, with all updates and support', 'fluent-crm-custom-features' ), self::planName( $new_download, null ) );
		}

		if ( ! $extras ) {
			$extras[] = esc_html__( 'every GravityKit plugin, with all updates and support', 'fluent-crm-custom-features' );
		}

		$extras = (array) apply_filters( 'customcrm/edd_ab_cart/upgrade_plan_extras', $extras, $license, $new_download, $new_price_id );

		return '<ul class="customcrm-upgrade-extras"><li>' . implode( '</li><li>', $extras ) . '</li></ul>';
	}

	/**
	 * Download IDs in a bundle, without their price option suffixes ("123_2" is download 123).
	 *
	 * @param int      $download_id
	 * @param int|null $price_id
	 * @return int[]
	 */
	private static function bundledIds( int $download_id, $price_id ): array {
		if ( ! edd_is_bundled_product( $download_id ) ) {
			return [];
		}

		$price_id = is_numeric( $price_id ) && edd_has_variable_prices( $download_id ) ? (int) $price_id : null;
		$products = (array) edd_get_bundled_products( $download_id, $price_id );

		return array_values( array_unique( array_map( 'intval', $products ) ) );
	}
}
