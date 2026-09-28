<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\App\Modules\AbandonCart\AbCartHelper;
use FluentCrm\App\Modules\AbandonCart\Drivers\AbstractCartDriver;
use FluentCrm\Framework\Support\Arr;

/**
 * Easy Digital Downloads provider for FluentCRM's abandoned-cart module (FluentCRM 3.2+).
 *
 * FluentCRM's core runner decides when a cart is abandoned (`capture_after_minutes` without an
 * update); this driver only records carts, rebuilds them from the recovery link, and closes them
 * on purchase.
 */
class EddCartDriver extends AbstractCartDriver {

	public const PROVIDER = 'edd';

	/**
	 * Default number of days during which the same email is not sent this sequence again.
	 *
	 * Mirrors Recapture's "campaign frequency cap" of 21 days. Filter:
	 * `customcrm/edd_ab_cart/resend_cap_days`.
	 */
	private const RESEND_CAP_DAYS = 21;

	public function getProviderSlug() {
		return self::PROVIDER;
	}

	public function getProviderLabel() {
		return __( 'Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	public function isAvailable() {
		return function_exists( 'EDD' ) && function_exists( 'edd_get_cart_contents' );
	}

	public function register() {
		( new EddCartTracking( $this ) )->register();
	}

	public function registerAutomationTrigger() {
		// BaseTrigger registers its own hooks in the constructor.
		new EddCartAutomationTrigger();
	}

	protected function getViewsBasePath() {
		return __DIR__ . '/Views/';
	}

	/**
	 * Skip a cart when the shopper bought recently, or already got this sequence recently.
	 *
	 * The first half uses FluentCRM's own `cool_off_period_days` setting. The second half is
	 * Recapture's frequency cap: someone who abandons carts on two devices, or comes back every
	 * few days, would otherwise restart the whole sequence each time, because the runner deletes
	 * the previous run before starting a new one.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function isWithinCoolOffPeriod( AbandonCartModel $cart ) {
		return $this->boughtRecently( $cart ) || $this->sentRecently( $cart );
	}

	protected function boughtRecently( AbandonCartModel $cart ): bool {
		$cool_off_days = (int) AbCartHelper::getSetting( 'cool_off_period_days', 0 );

		if ( $cool_off_days > 0 && $cart->email && function_exists( 'edd_count_orders' ) ) {
			$recent_orders = edd_count_orders(
				[
					'email'      => $cart->email,
					'type'       => 'sale',
					'status__in' => edd_get_complete_order_statuses(),
					'date_query' => [
						[
							'after'     => gmdate( 'Y-m-d H:i:s', time() - ( $cool_off_days * DAY_IN_SECONDS ) ),
							'inclusive' => true,
						],
					],
				]
			);

			if ( $recent_orders > 0 ) {
				return true;
			}
		}

		return false;
	}

	protected function sentRecently( AbandonCartModel $cart ): bool {
		$cap_days = (int) apply_filters( 'customcrm/edd_ab_cart/resend_cap_days', self::RESEND_CAP_DAYS, $cart );

		if ( $cap_days <= 0 || ! $cart->email ) {
			return false;
		}

		$contact = FluentCrmApi( 'contacts' )->getContact( $cart->email );

		if ( ! $contact ) {
			return false;
		}

		$trigger_name = $this->getTriggerName();

		// A run cancelled because the shopper bought does not count: a later abandoned cart is a new one.
		return FunnelSubscriber::where( 'subscriber_id', $contact->id )
			->where( 'status', '!=', 'cancelled' )
			->where( 'created_at', '>=', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $cap_days * DAY_IN_SECONDS ) ) )
			->whereHas(
				'funnel',
				function ( $query ) use ( $trigger_name ) {
					$query->where( 'trigger_name', $trigger_name );
				}
			)
			->exists();
	}

	public function getCartItemsHtml( AbandonCartModel $cart ) {
		return $this->loadView(
			'AbandonCartItems',
			[
				'cart_items' => Arr::get( $cart->cart, 'cart_contents', [] ),
				'currency'  => $cart->currency,
				'driver'    => $this,
			]
		);
	}

	public function formatPrice( $amount, $currency = '' ) {
		if ( ! function_exists( 'edd_currency_filter' ) ) {
			return '$' . number_format( (float) $amount, 2 );
		}

		return edd_currency_filter( edd_format_amount( (float) $amount ), $currency ?: edd_get_currency() );
	}

	public function getRecoveryUrl( AbandonCartModel $cart ) {
		if ( 'processing' !== $cart->status ) {
			return '';
		}

		return add_query_arg(
			[
				FLUENTCRM_EXTERNAL_URL_PARAM => 1,
				'route'                      => 'general',
				'handler'                    => $this->getHandlerName(),
				'fc_ab_hash'                 => $cart->checkout_key,
			],
			home_url()
		);
	}

	public function extractCartConditionData( AbandonCartModel $cart ) {
		$product_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $item ) {
							return (int) Arr::get( $item, 'product_id' );
						},
						Arr::get( $cart->cart, 'cart_contents', [] )
					)
				)
			)
		);

		$category_ids = [];

		if ( $product_ids ) {
			$terms = wp_get_object_terms( $product_ids, 'download_category', [ 'fields' => 'ids' ] );

			if ( ! is_wp_error( $terms ) ) {
				$category_ids = array_map( 'intval', $terms );
			}
		}

		return [
			'product_ids'  => $product_ids,
			'category_ids' => $category_ids,
		];
	}

	public function enrichCartForListing( AbandonCartModel $cart ) {
		if ( $cart->order_id ) {
			$cart->order_url = admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . (int) $cart->order_id );
		}

		// The shared Vue modal reads cart.cart_contents[*].title / quantity / line_total / product_image,
		// which is the shape EddCartTracking already stores, so only the address block needs filling.
		$data = $cart->cart ?: [];

		if ( empty( $data['customer_data'] ) ) {
			$data['customer_data'] = [
				'billingAddress' => [
					'first_name' => $cart->full_name,
					'last_name'  => '',
				],
			];
		}

		$cart->cart = $data;

		return $cart;
	}
}
