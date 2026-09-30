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
		return __( 'Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	/**
	 * Whether EDD is active.
	 *
	 * @return bool
	 */
	public function isAvailable() {
		return function_exists( 'EDD' ) && function_exists( 'edd_get_cart_contents' );
	}

	/**
	 * Hooks the checkout script, AJAX endpoints, cart restore and smart codes for this provider.
	 *
	 * @return void
	 */
	public function register() {
		( new EddCartTracking( $this ) )->register();
	}

	/**
	 * Registers this provider's automation trigger.
	 *
	 * @return void
	 */
	public function registerAutomationTrigger() {
		// BaseTrigger registers its own hooks in the constructor.
		new EddCartAutomationTrigger();
	}

	/**
	 * Directory holding this driver's email templates.
	 *
	 * @return string
	 */
	protected function getViewsBasePath() {
		return __DIR__ . '/Views/';
	}

	/**
	 * Skip a cart when its email is outside internal-only mode's domains, the shopper bought
	 * recently, or already got this sequence recently.
	 *
	 * The first half uses FluentCRM's own `cool_off_period_days` setting. The second half is
	 * Recapture's frequency cap: someone who abandons carts on two devices, or comes back every
	 * few days, would otherwise restart the whole sequence each time, because the runner deletes
	 * the previous run before starting a new one.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function isWithinCoolOffPeriod( AbandonCartModel $cart ) {
		return AllowedDomains::holdBack( $cart ) || $this->boughtRecently( $cart ) || $this->sentRecently( $cart );
	}

	/**
	 * Whether the shopper completed an order within FluentCRM's cool-off days.
	 *
	 * @param AbandonCartModel $cart
	 */
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

	/**
	 * Days during which a contact who entered this provider's automation does not get it again.
	 *
	 * @param AbandonCartModel $cart
	 */
	protected function resendCapDays( AbandonCartModel $cart ): int {
		return (int) apply_filters( 'customcrm/edd_ab_cart/resend_cap_days', self::RESEND_CAP_DAYS, $cart );
	}

	/**
	 * Whether this contact entered this provider's automation, or got Recapture's cart emails, within the resend cap.
	 *
	 * @param AbandonCartModel $cart
	 */
	protected function sentRecently( AbandonCartModel $cart ): bool {
		$cap_days = $this->resendCapDays( $cart );

		if ( $cap_days <= 0 || ! $cart->email ) {
			return false;
		}

		if ( PriorRecipients::lastSentAt( $cart->email ) >= time() - ( $cap_days * DAY_IN_SECONDS ) ) {
			return true;
		}

		return $this->enteredAutomationWithin( $cart, $cap_days );
	}

	/**
	 * Whether this contact entered this provider's automation in the last `$cap_days` days.
	 *
	 * @param AbandonCartModel $cart
	 * @param int              $cap_days
	 */
	protected function enteredAutomationWithin( AbandonCartModel $cart, int $cap_days ): bool {
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

	/**
	 * Cart items table for the `cart_items_table` smart code.
	 *
	 * @param AbandonCartModel $cart
	 * @return string HTML.
	 */
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

	/**
	 * Formats an amount in the store's currency style.
	 *
	 * @param float|string $amount
	 * @param string       $currency Currency code; empty for the store currency.
	 * @return string
	 */
	public function formatPrice( $amount, $currency = '' ) {
		if ( ! function_exists( 'edd_currency_filter' ) ) {
			return '$' . number_format( (float) $amount, 2 );
		}

		return edd_currency_filter( edd_format_amount( (float) $amount ), $currency ?: edd_get_currency() );
	}

	/**
	 * Link that rebuilds the cart and opens checkout.
	 *
	 * @param AbandonCartModel $cart
	 * @return string Empty unless the cart's sequence is running.
	 */
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

	/**
	 * Product and category IDs the automation's cart conditions match against.
	 *
	 * @param AbandonCartModel $cart
	 * @return array{product_ids: int[], category_ids: int[]}
	 */
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

	/**
	 * Adds the order link and address block the Abandoned Carts report expects.
	 *
	 * @param AbandonCartModel $cart
	 * @return AbandonCartModel
	 */
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
