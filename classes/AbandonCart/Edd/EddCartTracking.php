<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\App\Modules\AbandonCart\AbCartHelper;
use FluentCrm\Framework\Support\Arr;

/**
 * Records EDD carts in fc_abandoned_carts, rebuilds them from recovery links, and closes them on purchase.
 *
 * A cart is only recorded once it has an email: typed at checkout (sent by the checkout script),
 * or the logged-in user's. After that every cart change updates the same row, found by the
 * `fc_ab_edd_cart_token` cookie.
 */
class EddCartTracking {

	private const COOKIE        = 'fc_ab_edd_cart_token';
	private const OPT_OUT       = 'fc_ab_cart_skip_track';
	private const NONCE         = 'customcrm_edd_ab_cart';
	private const AJAX_SYNC     = 'customcrm_edd_ab_cart_sync';
	private const AJAX_OPT_OUT  = 'customcrm_edd_ab_cart_opt_out';
	private const ORDER_META    = '_fc_ab_cart_id';
	private const OPEN_STATUSES = [ 'draft', 'processing', 'pending', 'opt_out' ];
	private const PROVIDERS     = [ EddCartDriver::PROVIDER, EddRenewalCartDriver::PROVIDER, EddUpgradeCartDriver::PROVIDER ];
	private const INDEX_OPTION  = 'customcrm_ab_cart_indexes';

	/** Days after a renewal or upgrade cart is marked lost that renewing or upgrading its license still counts as recovering it. */
	private const LOST_RENEWAL_DAYS = 30;

	/**
	 * License IDs renewed during this request. Their carts are closed at shutdown, so an order
	 * completing in the same request marks its own cart recovered first.
	 *
	 * @var int[]
	 */
	private static $renewed_licenses = [];

	/**
	 * Licenses upgraded during this request, keyed by license ID, with the upgrade order ID.
	 * Their renewal carts are closed at shutdown with the renewed ones.
	 *
	 * @var array<int,int>
	 */
	private static $upgraded_licenses = [];

	/** @var EddCartDriver */
	private $driver;

	/**
	 * True while a recovery link is rebuilding the cart, so the rebuild is not recorded as a new change.
	 * Static: the cart hooks run on the instanceIfEnabled() instance, not the one running restoreCart().
	 *
	 * @var bool
	 */
	private static $restoring = false;

	/**
	 * @param EddCartDriver $driver The provider this instance serves.
	 */
	public function __construct( EddCartDriver $driver ) {
		$this->driver = $driver;
	}

	/**
	 * Every EDD provider calls this. The checkout script and AJAX endpoints are shared and added
	 * once; the recovery-link handler and smart codes belong to this tracker's provider.
	 */
	public function register(): void {
		static $shared_registered = false;

		if ( ! $shared_registered ) {
			$shared_registered = true;

			add_action( 'wp_enqueue_scripts', [ $this, 'enqueueCheckoutScript' ] );

			add_action( 'wp_ajax_' . self::AJAX_SYNC, [ $this, 'ajaxSync' ] );
			add_action( 'wp_ajax_nopriv_' . self::AJAX_SYNC, [ $this, 'ajaxSync' ] );
			add_action( 'wp_ajax_' . self::AJAX_OPT_OUT, [ $this, 'ajaxOptOut' ] );
			add_action( 'wp_ajax_nopriv_' . self::AJAX_OPT_OUT, [ $this, 'ajaxOptOut' ] );
		}

		add_action(
			'fluent_crm/handle_frontend_for_' . $this->driver->getHandlerName(),
			function ( $data ) {
				add_action(
					'template_redirect',
					function () use ( $data ) {
						try {
							$this->restoreCart( $data );
						} catch ( \Throwable $e ) {
							self::$restoring = false;
							self::logError( 'restoreCart', $e );
							wp_safe_redirect( self::emailCheckoutUrl( null, (array) $data ) );
							exit;
						}
					},
					1
				);
			}
		);

		add_filter( 'fluent_crm_funnel_context_smart_codes', [ $this, 'pushContextCodes' ], 1, 2 );
		add_filter( 'fluent_crm/smartcode_group_callback_' . $this->driver->getSmartCodeGroupKey(), [ $this, 'parseSmartCode' ], 10, 4 );
	}

	/**
	 * Cart and order hooks, attached at plugins_loaded rather than from register().
	 *
	 * EDD adds to cart and processes checkout from `init` actions that run before FluentCRM boots its
	 * cart drivers (init priority 90), so hooks added in register() miss every order placed through
	 * a normal form post. Each callback checks at run time that EDD carts are enabled.
	 */
	public static function registerEarlyHooks(): void {
		// These run inside add-to-cart and order completion. An error here is logged and swallowed:
		// a missed cart email is recoverable, a broken checkout or undelivered license key is not.
		$call = function ( string $method ) {
			return function ( ...$args ) use ( $method ) {
				try {
					$tracking = self::instanceIfEnabled();
					if ( $tracking ) {
						$tracking->{$method}( ...$args );
					}
				} catch ( \Throwable $e ) {
					self::logError( $method, $e );
				}
			};
		};

		// Same cart events the Recapture plugin listens to.
		foreach ( [ 'edd_post_add_to_cart', 'edd_post_remove_from_cart', 'edd_after_set_cart_item_quantity', 'edd_cart_discounts_updated' ] as $hook ) {
			add_action( $hook, $call( 'syncKnownCart' ), 99, 0 );
		}

		add_action( 'edd_insert_payment', $call( 'linkOrder' ), 10, 2 );
		add_action( 'edd_complete_purchase', $call( 'handleCompletedOrder' ), 10, 1 );

		// Fires however the license was renewed: this checkout, another email, auto-renew, or an admin.
		add_action(
			'edd_sl_post_license_renewal',
			function ( $license_id ) use ( $call ) {
				self::scheduleRenewalClose( $call );
				self::$renewed_licenses[] = (int) $license_id;
			},
			10,
			1
		);

		// An upgrade replaces the renewal (the customer paid for the license on a new plan) and
		// closes the license's upgrade cart, however the upgrade was bought.
		add_action(
			'edd_sl_license_upgraded',
			function ( $license_id, $args = [] ) use ( $call ) {
				self::scheduleRenewalClose( $call );
				self::$upgraded_licenses[ (int) $license_id ] = (int) Arr::get( (array) $args, 'payment_id', 0 );
			},
			10,
			2
		);
	}

	/**
	 * Logs an error from a cart or order hook without interrupting the request.
	 */
	public static function logError( string $where, \Throwable $e ): void {
		error_log( sprintf( '[fluent-crm-custom-features] %s: %s in %s:%d', $where, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Runs closeRenewedCarts() once at shutdown, after the request's orders are complete.
	 *
	 * @param callable $call Wraps a method name in a callback on the enabled instance.
	 */
	private static function scheduleRenewalClose( callable $call ): void {
		static $scheduled = false;

		if ( ! $scheduled ) {
			$scheduled = true;
			add_action( 'shutdown', $call( 'closeRenewedCarts' ), 1, 0 );
		}
	}

	/**
	 * Add email and user_id indexes to fc_abandoned_carts, which FluentCRM keys only by status and
	 * checkout_key. Every cart lookup here filters by one of the two. Runs until the table exists.
	 */
	public static function ensureIndexes(): void {
		if ( get_option( self::INDEX_OPTION ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'fc_abandoned_carts';

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}

		// Schema changes have no model or core API, so these two stay as prepared SQL.
		$existing = array_map( 'strtolower', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 ) );

		foreach ( [ 'email', 'user_id' ] as $column ) {
			if ( in_array( 'customcrm_' . $column, $existing, true ) ) {
				continue;
			}

			$added = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX %i (%i)', $table, 'customcrm_' . $column, $column ) );

			if ( false === $added ) {
				return;
			}
		}

		update_option( self::INDEX_OPTION, 1, true );
	}

	/**
	 * The shared instance the cart and order hooks run on.
	 *
	 * @return self|null Null when abandoned carts or every EDD provider is off.
	 */
	private static function instanceIfEnabled(): ?self {
		static $instance = null;

		if ( null === $instance ) {
			$enabled  = AbCartHelper::isActive() && array_filter( self::PROVIDERS, [ self::class, 'isProviderEnabled' ] );
			$instance = $enabled ? new self( new EddCartDriver() ) : false;
		}

		return $instance ?: null;
	}

	/**
	 * Whether a provider is switched on in FluentCRM's abandoned-cart settings.
	 *
	 * @param string $provider Provider key.
	 */
	private static function isProviderEnabled( string $provider ): bool {
		$needs_licensing = in_array( $provider, [ EddRenewalCartDriver::PROVIDER, EddUpgradeCartDriver::PROVIDER ], true );

		if ( $needs_licensing && ! function_exists( 'edd_software_licensing' ) ) {
			return false;
		}

		return in_array( $provider, (array) AbCartHelper::getSetting( 'enabled_providers', [] ), true );
	}

	/**
	 * @return EddCartDriver|EddRenewalCartDriver|EddUpgradeCartDriver
	 */
	private static function driverFor( string $provider ): EddCartDriver {
		if ( EddRenewalCartDriver::PROVIDER === $provider ) {
			return new EddRenewalCartDriver();
		}

		if ( EddUpgradeCartDriver::PROVIDER === $provider ) {
			return new EddUpgradeCartDriver();
		}

		return new EddCartDriver();
	}

	/**
	 * The provider a cart belongs to: any renewal makes it a renewal cart, otherwise any upgrade
	 * makes it an upgrade cart. Existing customers never get the new-customer emails or discount.
	 *
	 * @param array<int,array<string,mixed>> $items Stored cart items, or `edd_get_cart_content_details()` items.
	 */
	private static function providerForItems( array $items ): string {
		$is_renewal = false;
		$is_upgrade = false;

		foreach ( $items as $item ) {
			$is_renewal = $is_renewal || ! empty( $item['is_renewal'] ) || ! empty( Arr::get( $item, 'item_number.options.is_renewal' ) );
			$is_upgrade = $is_upgrade || ! empty( $item['is_upgrade'] ) || ! empty( Arr::get( $item, 'item_number.options.is_upgrade' ) );
		}

		if ( $is_renewal ) {
			return EddRenewalCartDriver::PROVIDER;
		}

		return $is_upgrade ? EddUpgradeCartDriver::PROVIDER : EddCartDriver::PROVIDER;
	}

	/**
	 * Loads the script that sends the checkout email and name as they are typed.
	 */
	public function enqueueCheckoutScript(): void {
		if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() || ! AbCartHelper::willCartTrack() ) {
			return;
		}

		if ( $this->hasOptedOut() ) {
			return;
		}

		$plugin_file = dirname( __DIR__, 3 ) . '/fluent-crm-custom-features.php';
		$script_path = dirname( __DIR__, 3 ) . '/assets/checkout-fields.js';

		wp_enqueue_script(
			'customcrm-checkout-fields',
			plugins_url( 'assets/checkout-fields.js', $plugin_file ),
			[],
			(string) filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'customcrm-checkout-fields',
			'customcrmEddAbCart',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'syncAction'   => self::AJAX_SYNC,
				'optOutAction' => self::AJAX_OPT_OUT,
				'gdprMessage'  => AbCartHelper::getGDPRMessage(),
			]
		);
	}

	/**
	 * AJAX: saves the current cart for the email typed at checkout.
	 */
	public function ajaxSync(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$email = sanitize_email( self::postedText( 'email' ) );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'invalid_email' ], 400 );
		}

		$first     = sanitize_text_field( self::postedText( 'first_name' ) );
		$last      = sanitize_text_field( self::postedText( 'last_name' ) );
		$full_name = trim( $first . ' ' . $last );

		$record = $this->syncCart( $email, $full_name );

		wp_send_json_success( [ 'tracked' => (bool) $record ] );
	}

	/**
	 * A posted field as unslashed text; empty when missing or not a string (a crafted request).
	 */
	private static function postedText( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
		$value = $_POST[ $key ] ?? '';

		return is_scalar( $value ) ? (string) wp_unslash( $value ) : '';
	}

	/**
	 * AJAX: stops tracking this visitor's cart for the opt-out cookie's lifetime.
	 */
	public function ajaxOptOut(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$record = $this->getCurrentRecord();

		if ( $record ) {
			$record->optOut();
		}

		$days = (int) apply_filters( 'fluent_crm/ab_cart_opt_out_cookie_validity', 7 );
		setcookie( self::OPT_OUT, 'yes', time() + ( DAY_IN_SECONDS * $days ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		wp_send_json_success( [ 'message' => __( 'You have opted out from cart tracking', 'fluent-crm-custom-features' ) ] );
	}

	/**
	 * Update the stored cart after a cart change, when we already know who the shopper is.
	 */
	public function syncKnownCart(): void {
		if ( self::$restoring || ! AbCartHelper::willCartTrack() ) {
			return;
		}

		$record = $this->getCurrentRecord();

		if ( $record ) {
			$this->syncCart( $record->email, $record->full_name );
			return;
		}

		$user = wp_get_current_user();

		if ( $user && $user->ID && is_email( $user->user_email ) ) {
			$this->syncCart( $user->user_email, trim( $user->first_name . ' ' . $user->last_name ) );
		}
	}

	/**
	 * Save the current EDD cart for this email. Removes the record when the cart is empty or free.
	 *
	 * @return AbandonCartModel|null The stored cart, or null when nothing was stored.
	 */
	public function syncCart( string $email, string $full_name = '' ): ?AbandonCartModel {
		if ( ! AbCartHelper::willCartTrack() || self::isIgnoredEmail( $email ) ) {
			return null;
		}

		$record = $this->getCurrentRecord( $email );
		$items  = $this->getCartContents();
		$total  = (float) edd_get_cart_total();

		$provider   = self::providerForItems( $items );
		$is_renewal = EddRenewalCartDriver::PROVIDER === $provider;
		$has_offer  = EddCartDriver::PROVIDER === $provider;

		// Once a cart's sequence has started, it belongs to that email and that kind of cart. Someone
		// typing another address, or turning a renewal or upgrade into a new purchase, starts a new cart; only a
		// draft may change, which is a shopper correcting a typo or still building the cart.
		$started = $record && ! in_array( $record->status, [ 'draft', 'pending' ], true );
		if ( $started && ( 0 !== strcasecmp( (string) $record->email, $email ) || $record->provider !== $provider ) ) {
			$record = null;
		}

		if ( $this->hasOptedOut() ) {
			if ( $record && 'opt_out' !== $record->status ) {
				$record->optOut();
			}
			return null;
		}

		// Free downloads are not abandoned purchases. Recapture emailed these carts, mostly the
		// free Elementor widget, asking people to "complete your purchase" of a $0 item.
		if ( ! $items || $total <= 0 || ! self::isProviderEnabled( $provider ) ) {
			if ( $record && in_array( $record->status, [ 'draft', 'pending' ], true ) ) {
				$record->delete();
				$this->setCookie( '', -1 );
			}
			return null;
		}

		$contact = FluentCrmApi( 'contacts' )->getContact( $email );

		if ( ! $full_name && $contact ) {
			$full_name = trim( $contact->first_name . ' ' . $contact->last_name );
		}

		$name_parts = explode( ' ', $full_name, 2 );
		$previous   = $record ? ( $record->cart ?: [] ) : [];

		$data = [
			'email'      => $email,
			'full_name'  => $full_name,
			'provider'   => $provider,
			'user_id'    => get_current_user_id() ?: null,
			'contact_id' => $contact ? $contact->id : null,
			'cart_hash'  => md5( wp_json_encode( wp_list_pluck( $items, 'key' ) ) ),
			'subtotal'   => $this->money( edd_get_cart_subtotal() ),
			'discounts'  => $this->money( edd_get_cart_discounted_amount() ),
			'tax'        => $this->money( edd_get_cart_tax() ),
			'shipping'   => 0,
			'fees'       => 0,
			'total'      => $this->money( $total ),
			'currency'   => edd_get_currency(),
			'cart'       => [
				'cart_contents' => $items,
				'coupons'       => array_values( (array) edd_get_cart_discounts() ),
				'customer_data' => [
					'billingAddress' => [
						'first_name' => $name_parts[0],
						'last_name'  => $name_parts[1] ?? '',
					],
				],
				// Keep discount codes already issued for this cart; the later emails reuse them.
				// Renewals and upgrades get no discount.
				'recovery_discount'  => $has_offer ? Arr::get( $previous, 'recovery_discount' ) : null,
				'recovery_discounts' => $has_offer ? Arr::get( $previous, 'recovery_discounts' ) : null,
			],
		];

		if ( ! $record ) {
			$data['status'] = 'draft';
			$record         = AbandonCartModel::create( $data );
		} else {
			$record->fill( $data );
			$record->save();
		}

		// Typing name and email fires overlapping requests; each can miss the others' insert.
		AbandonCartModel::whereIn( 'provider', self::PROVIDERS )
			->where( 'email', $email )
			->where( 'id', '!=', $record->id )
			->whereIn( 'status', [ 'draft', 'pending' ] )
			->delete();

		$this->setCookie( $record->checkout_key );

		// Renewal wins: a license being renewed stops getting upgrade emails.
		if ( $is_renewal ) {
			$renewal_items = array_filter( $items, function ( $item ) {
				return ! empty( $item['is_renewal'] );
			} );

			$this->dropLicenseUpgradeCarts(
				array_map( 'intval', wp_list_pluck( $renewal_items, 'license_id' ) ),
				__( 'Cancelled because a renewal for this license was started', 'fluent-crm-custom-features' )
			);
		}

		return $record;
	}

	/**
	 * Cart items in the shape the Abandoned Carts report modal reads, plus what a rebuild needs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getCartContents(): array {
		$items = [];

		foreach ( (array) edd_get_cart_content_details() as $index => $item ) {
			$product_id = (int) Arr::get( $item, 'id' );
			$price_id   = Arr::get( $item, 'item_number.options.price_id' );
			$quantity   = max( 1, (int) Arr::get( $item, 'quantity', 1 ) );
			$image      = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
			$is_renewal = ! empty( Arr::get( $item, 'item_number.options.is_renewal' ) );
			$is_upgrade = ! $is_renewal && ! empty( Arr::get( $item, 'item_number.options.is_upgrade' ) );
			$license_id = $is_renewal || $is_upgrade ? (int) Arr::get( $item, 'item_number.options.license_id' ) : 0;
			$license    = $license_id && function_exists( 'edd_software_licensing' ) ? edd_software_licensing()->get_license( $license_id ) : false;

			$items[] = [
				'key'           => $product_id . '|' . ( null === $price_id ? '' : (int) $price_id ) . '|' . $quantity . '|' . $license_id,
				'is_renewal'    => $is_renewal && $license_id,
				'is_upgrade'    => $is_upgrade,
				'license_id'    => $license_id ?: null,
				// Index into the license's upgrade paths; prices are worked out from it when an email is sent.
				'upgrade_id'    => $is_upgrade ? (int) Arr::get( $item, 'item_number.options.upgrade_id' ) : null,
				// Unix time; 0 for a lifetime license. A later value after saving means it was renewed.
				'license_expiration' => $license ? (int) $license->expiration : null,
				'license_lifetime'   => $license ? (bool) $license->is_lifetime : null,
				// The license's plan when the cart was saved. A different plan later means it was upgraded.
				'license_download_id' => $license ? (int) $license->download_id : null,
				'license_price_id'    => $license && is_numeric( $license->price_id ) ? (int) $license->price_id : null,
				'product_id'    => $product_id,
				'price_id'      => null === $price_id ? null : (int) $price_id,
				'quantity'      => $quantity,
				'title'         => wp_strip_all_tags( (string) Arr::get( $item, 'name', edd_get_download_name( $product_id, $price_id ) ) ),
				'line_total'    => $this->money( Arr::get( $item, 'price', 0 ) ),
				'item_price'    => $this->money( Arr::get( $item, 'item_price', 0 ) ),
				'product_image' => $image ?: '',
				'product_url'   => get_permalink( $product_id ) ?: '',
				'cart_index'    => (int) $index,
			];
		}

		return $items;
	}

	/**
	 * Store the order ID on the cart when EDD creates the order, so completion can find it.
	 *
	 * @param int                 $order_id
	 * @param array<string,mixed> $order_data
	 */
	public function linkOrder( $order_id, $order_data = [] ): void {
		$email = (string) ( Arr::get( $order_data, 'user_email' ) ?: Arr::get( $order_data, 'user_info.email', '' ) );

		// Only a renewal order may close a renewal cart, and only an upgrade order an upgrade cart.
		$provider = self::providerForItems( (array) Arr::get( $order_data, 'cart_details', [] ) );
		$record   = $this->getCurrentRecord( $email, $provider );

		if ( ! $record ) {
			return;
		}

		$record->order_id = (int) $order_id;
		$record->save();

		edd_update_order_meta( $order_id, self::ORDER_META, $record->id );
	}

	/**
	 * Close carts when an order completes: recovered if the sequence had started, deleted otherwise.
	 *
	 * @param int $order_id
	 */
	public function handleCompletedOrder( $order_id ): void {
		$order = edd_get_order( $order_id );

		if ( ! $order || (float) $order->total <= 0 ) {
			return;
		}

		$cart_id = (int) edd_get_order_meta( $order_id, self::ORDER_META, true );
		$record  = $cart_id ? AbandonCartModel::find( $cart_id ) : null;

		// A renewal cart is only closed by its own order or by its licenses renewing (closeRenewedCarts):
		// buying something else leaves the renewal outstanding. A lost cart is not matched by email: its
		// sequence ended days ago, so a purchase now is not a recovery.
		if ( ! $record ) {
			$record = AbandonCartModel::where( 'provider', EddCartDriver::PROVIDER )
				->where( 'email', $order->email )
				->whereIn( 'status', [ 'draft', 'processing', 'pending', 'opt_out' ] )
				->orderBy( 'id', 'DESC' )
				->first();
		}

		if ( $record ) {
			$this->closeCart( $record, $order );

			$reasons = [
				EddRenewalCartDriver::PROVIDER => __( 'Cancelled because the contact renewed', 'fluent-crm-custom-features' ),
				EddUpgradeCartDriver::PROVIDER => __( 'Cancelled because the contact upgraded', 'fluent-crm-custom-features' ),
			];

			if ( isset( $reasons[ $record->provider ] ) ) {
				$this->cancelCartAutomation( $record, $reasons[ $record->provider ] );
			}
		}

		// Any other open carts for this buyer are done too, and so is a sequence that started
		// from one of them: someone who bought should not get "you left items in your cart".
		$this->deleteOtherCarts( $record ? (int) $record->id : 0, $order->email, (int) $order->user_id );
		$this->cancelAutomations( $order->email, (int) $order->user_id, __( 'Cancelled because the contact completed a purchase', 'fluent-crm-custom-features' ) );

		// A paid order stops the buyer's upgrade emails. Carts whose license this order upgraded are
		// left open here: closeRenewedCarts() marks them recovered at shutdown.
		$this->dropBuyerUpgradeCarts(
			$record ? (int) $record->id : 0,
			(string) $order->email,
			(int) $order->user_id,
			__( 'Cancelled because the contact completed another purchase', 'fluent-crm-custom-features' )
		);

		$this->setCookie( '', -1 );
	}

	/**
	 * Mark a cart recovered when its sequence had started, or delete it when it had not.
	 *
	 * @param AbandonCartModel   $record The shopper's cart.
	 * @param \EDD\Orders\Order $order  The completed order.
	 */
	private function closeCart( AbandonCartModel $record, $order, string $note = '' ): void {
		if ( in_array( $record->status, [ 'draft', 'pending', 'opt_out' ], true ) ) {
			$record->deleteCart();
			return;
		}

		if ( ! in_array( $record->status, [ 'processing', 'lost' ], true ) ) {
			return;
		}

		$settings   = AbCartHelper::getSettings();
		$subscriber = $record->subscriber;

		if ( $subscriber ) {
			$lists = Arr::get( $settings, 'lists_on_cart_abandoned', [] );
			$tags  = Arr::get( $settings, 'tags_on_cart_abandoned', [] );

			if ( $lists ) {
				$subscriber->detachLists( $lists );
			}
			if ( $tags ) {
				$subscriber->detachTags( $tags );
			}
		}

		$old_status           = $record->status;
		$record->status       = 'recovered';
		$record->order_id     = $order ? (int) $order->id : $record->order_id;
		$record->total        = $order ? $this->money( $order->total ) : $record->total;
		$record->recovered_at = current_time( 'mysql' );
		if ( $note ) {
			$record->note = $note;
		}
		$record->save();

		do_action( 'fluent_crm/ab_cart_edd_recovered', $record, $order, $old_status );
	}

	/**
	 * Close renewal and upgrade carts whose licenses were renewed or upgraded, by any route. Runs at shutdown.
	 *
	 * A renewal cart closes once all its licenses are renewed or upgraded. An upgrade cart closes as
	 * recovered when one of its licenses is upgraded, and is dropped when one is renewed.
	 */
	public function closeRenewedCarts(): void {
		$upgraded    = self::$upgraded_licenses;
		$license_ids = array_unique( array_merge( self::$renewed_licenses, array_keys( $upgraded ) ) );

		if ( ! $license_ids ) {
			return;
		}

		$driver = new EddRenewalCartDriver();
		$carts  = $this->openOrRecentlyLostCarts( EddRenewalCartDriver::PROVIDER );

		foreach ( $carts as $cart ) {
			$renewal_items = array_filter(
				Arr::get( $cart->cart, 'cart_contents', [] ),
				function ( $item ) {
					return empty( $item['is_upgrade'] );
				}
			);
			$cart_licenses = array_filter( array_map( 'intval', wp_list_pluck( $renewal_items, 'license_id' ) ) );
			$cart_upgrades = array_intersect_key( $upgraded, array_flip( $cart_licenses ) );

			if ( self::wasStopped( $cart ) || ! array_intersect( $cart_licenses, $license_ids ) || ! $driver->allLicensesRenewed( $cart, array_keys( $cart_upgrades ) ) ) {
				continue;
			}

			if ( $cart_upgrades ) {
				$order = edd_get_order( (int) max( $cart_upgrades ) ) ?: null;
				$note  = __( 'Upgraded instead of renewed', 'fluent-crm-custom-features' );
				$why   = __( 'Cancelled because the license was upgraded', 'fluent-crm-custom-features' );
			} else {
				$order = $this->latestRenewalOrder( $cart_licenses );
				$note  = __( 'Renewed outside the recovery link', 'fluent-crm-custom-features' );
				$why   = __( 'Cancelled because the license was renewed', 'fluent-crm-custom-features' );
			}

			$this->closeCart( $cart, $order, $note );
			$this->cancelCartAutomation( $cart, $why );
		}

		$this->closeUpgradedCarts( $upgraded );

		$renewed_only = array_diff( array_map( 'intval', self::$renewed_licenses ), array_keys( $upgraded ) );
		$this->dropLicenseUpgradeCarts( $renewed_only, __( 'Cancelled because the license was renewed', 'fluent-crm-custom-features' ) );
	}

	/**
	 * A provider's open carts, plus those marked lost recently enough that a renewal or upgrade still counts as recovering them.
	 *
	 * @param string $provider Provider key.
	 * @return AbandonCartModel[]
	 */
	private function openOrRecentlyLostCarts( string $provider ) {
		// A lost cart counts for 30 days after it was marked lost. FluentCRM marks carts lost
		// `lost_cart_days` after they were created, in site-local time.
		$lost_days   = (int) AbCartHelper::getSetting( 'lost_cart_days', 15 ) + self::LOST_RENEWAL_DAYS;
		$lost_cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $lost_days * DAY_IN_SECONDS ) );

		return AbandonCartModel::where( 'provider', $provider )
			->where(
				function ( $query ) use ( $lost_cutoff ) {
					$query->whereIn( 'status', [ 'draft', 'pending', 'processing', 'opt_out' ] )
						->orWhere(
							function ( $lost ) use ( $lost_cutoff ) {
								$lost->where( 'status', 'lost' )->where( 'created_at', '>=', $lost_cutoff );
							}
						);
				}
			)
			->get();
	}

	/**
	 * Marks upgrade carts recovered when one of their licenses was upgraded, through the recovery
	 * link or any other route, and stops their emails.
	 *
	 * @param array<int,int> $upgraded Upgrade order IDs keyed by license ID.
	 */
	private function closeUpgradedCarts( array $upgraded ): void {
		if ( ! $upgraded ) {
			return;
		}

		foreach ( $this->openOrRecentlyLostCarts( EddUpgradeCartDriver::PROVIDER ) as $cart ) {
			$cart_upgrades = array_intersect_key( $upgraded, array_flip( EddUpgradeCartDriver::upgradeLicenseIds( $cart ) ) );

			if ( ! $cart_upgrades || self::wasStopped( $cart ) ) {
				continue;
			}

			$order_id     = (int) max( $cart_upgrades );
			$order        = $order_id ? ( edd_get_order( $order_id ) ?: null ) : null;
			$through_link = $order && (int) $cart->order_id === (int) $order->id;

			$this->closeCart( $cart, $order, $through_link ? '' : __( 'Upgraded outside the recovery link', 'fluent-crm-custom-features' ) );
			$this->cancelCartAutomation( $cart, __( 'Cancelled because the license was upgraded', 'fluent-crm-custom-features' ) );
		}
	}

	/**
	 * Drops the open upgrade carts holding any of these licenses: a renewal took over.
	 *
	 * @param int[]  $license_ids
	 * @param string $why Shown on the cancelled automation run.
	 */
	private function dropLicenseUpgradeCarts( array $license_ids, string $why ): void {
		$license_ids = array_filter( array_map( 'intval', $license_ids ) );

		if ( ! $license_ids ) {
			return;
		}

		$carts = AbandonCartModel::where( 'provider', EddUpgradeCartDriver::PROVIDER )
			->whereIn( 'status', [ 'draft', 'pending', 'processing', 'opt_out' ] )
			->get();

		foreach ( $carts as $cart ) {
			if ( array_intersect( EddUpgradeCartDriver::upgradeLicenseIds( $cart ), $license_ids ) ) {
				$this->dropUpgradeCart( $cart, $why );
			}
		}
	}

	/**
	 * Drops the buyer's open upgrade carts after a paid order, except the order's own cart and
	 * carts whose license was upgraded in this request (closeUpgradedCarts() recovers those).
	 *
	 * @param int    $keep_id Cart to keep; 0 for none.
	 * @param string $email
	 * @param int    $user_id 0 for a guest.
	 * @param string $why     Shown on the cancelled automation run.
	 */
	private function dropBuyerUpgradeCarts( int $keep_id, string $email, int $user_id, string $why ): void {
		$carts = AbandonCartModel::where( 'provider', EddUpgradeCartDriver::PROVIDER )
			->where( 'id', '!=', $keep_id )
			->whereIn( 'status', [ 'draft', 'pending', 'processing', 'opt_out' ] )
			->where(
				function ( $query ) use ( $email, $user_id ) {
					$query->where( 'email', $email );
					if ( $user_id ) {
						$query->orWhere( 'user_id', $user_id );
					}
				}
			)
			->get();

		$upgraded_now = array_keys( self::$upgraded_licenses );

		foreach ( $carts as $cart ) {
			if ( ! array_intersect( EddUpgradeCartDriver::upgradeLicenseIds( $cart ), $upgraded_now ) ) {
				$this->dropUpgradeCart( $cart, $why );
			}
		}
	}

	/**
	 * Stops an upgrade cart that will not be recovered: a cart whose emails had started is marked
	 * lost with the reason (and its run cancelled with it); one still being built is deleted.
	 *
	 * @param AbandonCartModel $cart
	 * @param string           $why Stored on the cart and on the cancelled automation run.
	 */
	private function dropUpgradeCart( AbandonCartModel $cart, string $why ): void {
		$this->cancelCartAutomation( $cart, $why );

		if ( 'processing' !== $cart->status ) {
			$cart->delete();
			return;
		}

		$data                    = $cart->cart ?: [];
		$data['stopped_because'] = $why;

		$cart->status = 'lost';
		$cart->note   = $why;
		$cart->cart   = $data;
		$cart->save();
	}

	/**
	 * Whether a cart was stopped on purpose (dropUpgradeCart()). A stopped cart never counts as
	 * recovered, even when its license is upgraded or renewed later.
	 *
	 * @param AbandonCartModel $cart
	 */
	private static function wasStopped( AbandonCartModel $cart ): bool {
		return ! empty( Arr::get( $cart->cart, 'stopped_because' ) );
	}

	/**
	 * The newest completed order that renewed one of these licenses, if any.
	 *
	 * @param int[] $license_ids
	 * @return \EDD\Orders\Order|null
	 */
	private function latestRenewalOrder( array $license_ids ) {
		$latest = null;

		foreach ( $license_ids as $license_id ) {
			$license = edd_software_licensing()->get_license( $license_id );

			foreach ( $license ? array_reverse( (array) $license->get_meta( '_edd_sl_payment_id', false ) ) : [] as $order_id ) {
				$order = edd_get_order( (int) $order_id );

				if ( $order && in_array( $order->status, edd_get_complete_order_statuses(), true ) ) {
					if ( ! $latest || $order->date_created > $latest->date_created ) {
						$latest = $order;
					}
					break;
				}
			}
		}

		return $latest;
	}

	/**
	 * Cancels the automation run started by this cart.
	 *
	 * @param AbandonCartModel $cart
	 * @param string           $note Shown on the run in FluentCRM.
	 */
	private function cancelCartAutomation( AbandonCartModel $cart, string $note ): void {
		$runs = FunnelSubscriber::where( 'source_ref_id', $cart->id )
			->where( 'source_trigger_name', self::driverFor( (string) $cart->provider )->getTriggerName() )
			->whereIn( 'status', [ 'active', 'pending', 'paused' ] )
			->get();

		self::cancelRuns( $runs, $note );
	}

	/**
	 * Cancels automation runs and the emails FluentCRM already queued for them.
	 *
	 * Cancelling a run stops its next steps, but an email step that already ran leaves a pending or
	 * scheduled email that still sends; those are cancelled too.
	 *
	 * @param iterable<FunnelSubscriber> $runs
	 * @param string                     $note Shown on each run and each cancelled email.
	 */
	private static function cancelRuns( $runs, string $note ): void {
		foreach ( $runs as $run ) {
			$run->status = 'cancelled';
			$run->notes  = $note;
			$run->save();

			$campaign_ids = CartEmailGuard::campaignIdsFor( [ (int) $run->funnel_id ] );

			if ( ! $campaign_ids ) {
				continue;
			}

			CampaignEmail::where( 'subscriber_id', $run->subscriber_id )
				->whereIn( 'campaign_id', $campaign_ids )
				->whereIn( 'status', [ 'pending', 'scheduled' ] )
				->update(
					[
						'status' => 'cancelled',
						'note'   => $note,
					]
				);
		}
	}

	/**
	 * Deletes the buyer's other open new-purchase carts.
	 *
	 * @param int    $keep_id Cart to keep; 0 for none.
	 * @param string $email
	 * @param int    $user_id 0 for a guest.
	 */
	private function deleteOtherCarts( int $keep_id, string $email, int $user_id ): void {
		$carts = AbandonCartModel::where( 'provider', EddCartDriver::PROVIDER )
			->where( 'id', '!=', $keep_id )
			->whereIn( 'status', [ 'draft', 'processing', 'pending' ] )
			->where(
				function ( $query ) use ( $email, $user_id ) {
					$query->where( 'email', $email );
					if ( $user_id ) {
						$query->orWhere( 'user_id', $user_id );
					}
				}
			)
			->get();

		foreach ( $carts as $cart ) {
			$cart->deleteCart();
		}
	}

	/**
	 * Cancels the buyer's running new-purchase cart automations.
	 *
	 * @param string $email
	 * @param int    $user_id 0 for a guest.
	 * @param string $note    Shown on the run in FluentCRM.
	 */
	private function cancelAutomations( string $email, int $user_id, string $note ): void {
		$subscriber_ids = Subscriber::where( 'email', $email )
			->when(
				$user_id,
				function ( $query ) use ( $user_id ) {
					return $query->orWhere( 'user_id', $user_id );
				}
			)
			->pluck( 'id' )
			->toArray();

		if ( ! $subscriber_ids ) {
			return;
		}

		$trigger_name = $this->driver->getTriggerName();

		$runs = FunnelSubscriber::whereIn( 'subscriber_id', $subscriber_ids )
			->whereHas(
				'funnel',
				function ( $query ) use ( $trigger_name ) {
					$query->where( 'trigger_name', $trigger_name );
				}
			)
			->whereIn( 'status', [ 'active', 'pending', 'paused' ] )
			->get();

		self::cancelRuns( $runs, $note );
	}

	/**
	 * Rebuild the EDD cart from a recovery link, apply its recovery discount, and go to checkout.
	 *
	 * Coupons: the codes the shopper entered before leaving stay on the rebuilt cart, for every
	 * provider. Our own codes (a code named in the link, or one this cart was sent) are added only
	 * to new-purchase carts, never to renewal or upgrade carts. So for a renewal or upgrade the
	 * checkout can be lower than the email's "You pay today", never higher.
	 *
	 * @param array<string,mixed> $data Query vars from FluentCRM's external-page router.
	 */
	public function restoreCart( $data ): void {
		$hash   = sanitize_text_field( (string) Arr::get( $data, 'fc_ab_hash', '' ) );
		$record = $hash ? AbandonCartModel::where( 'checkout_key', $hash )->where( 'provider', $this->driver->getProviderSlug() )->first() : null;

		if ( ! $record || 'processing' !== $record->status ) {
			do_action( 'fluent_crm/ab_cart_restore_failed', $record );
			wp_safe_redirect( self::emailCheckoutUrl( $record, (array) $data ) );
			exit;
		}

		self::$restoring = true;

		$this->selectCartCurrency( $record );

		edd_empty_cart();

		$failed_renewals = [];
		$failed_upgrades = [];

		foreach ( Arr::get( $record->cart, 'cart_contents', [] ) as $item ) {
			// Added the way Software Licensing's upgrade link adds it, at today's prorated price.
			if ( ! empty( $item['is_upgrade'] ) && ! empty( $item['license_id'] ) ) {
				$added = $this->addUpgradeToCart( $item );

				if ( is_wp_error( $added ) ) {
					// Never sell the new plan as a new purchase: the customer may already be on it.
					$failed_upgrades[] = sprintf( '#%d: %s', (int) $item['license_id'], $added->get_error_code() );
				}

				continue;
			} elseif ( ! empty( $item['license_id'] ) && function_exists( 'edd_sl_add_renewal_to_cart' ) ) {
				// Added the way a renewal link adds it, so EDD prices it as a renewal and records it on the license.
				$added = edd_sl_add_renewal_to_cart( (int) $item['license_id'] );

				if ( ! is_wp_error( $added ) ) {
					continue;
				}

				// The license can no longer be renewed (disabled, or its product unpublished). Add the
				// product as a new purchase so the shopper still lands on a checkout with it.
				$failed_renewals[] = sprintf( '#%d: %s', (int) $item['license_id'], $added->get_error_code() );
			}

			$options = [ 'quantity' => (int) Arr::get( $item, 'quantity', 1 ) ];

			if ( null !== Arr::get( $item, 'price_id' ) ) {
				$options['price_id'] = (int) $item['price_id'];
			}

			edd_add_to_cart( (int) $item['product_id'], $options );
		}

		foreach ( array_filter( (array) Arr::get( $record->cart, 'coupons', [] ) ) as $code ) {
			if ( edd_is_discount_valid( $code, '', false ) ) {
				edd_set_cart_discount( $code );
			}
		}

		// One of ours at most: a code named in the link (a store-wide code such as BFCM50), else the
		// newest code this cart was sent. Renewals and upgrades never get one.
		$gets_offer = EddCartDriver::PROVIDER === $record->provider;
		$ours       = $gets_offer
			? array_merge(
				[ strtoupper( sanitize_text_field( (string) Arr::get( $data, 'fc_ab_code', '' ) ) ) ],
				( new EddRecoveryDiscount() )->getIssuedCodes( $record )
			)
			: [];

		foreach ( array_unique( array_filter( $ours ) ) as $code ) {
			if ( edd_is_discount_valid( $code, '', false ) ) {
				edd_set_cart_discount( $code );
				break;
			}
		}

		self::$restoring = false;

		$record->click_counts = (int) $record->click_counts + 1;
		$notes                = [];
		if ( $failed_renewals ) {
			/* translators: %s: license IDs and EDD error codes */
			$notes[] = sprintf( __( 'Recovery link could not renew license %s; added as a new purchase', 'fluent-crm-custom-features' ), implode( ', ', $failed_renewals ) );
		}
		if ( $failed_upgrades ) {
			/* translators: %s: license IDs and error codes */
			$notes[] = sprintf( __( 'Recovery link could not upgrade license %s; nothing added', 'fluent-crm-custom-features' ), implode( ', ', $failed_upgrades ) );
			edd_set_error( 'customcrm_upgrade_unavailable', __( 'This license was already upgraded, or the upgrade is no longer available.', 'fluent-crm-custom-features' ) );
		}
		if ( $notes ) {
			$record->note = implode( '; ', $notes );
		}
		$record->save();

		$this->setCookie( $record->checkout_key );

		wp_safe_redirect( self::emailCheckoutUrl( $record, (array) $data ) );
		exit;
	}

	/**
	 * Checkout URL with the email's UTM tags, so analytics credits the visit to that email.
	 *
	 * No analytics script loads on the recovery link, which only redirects, so its `utm_*` are
	 * copied onto checkout; defaults fill only missing tags. `fc_ab_cart` is the cart row ID, never its key.
	 *
	 * @param AbandonCartModel|null $record   Null when the link no longer matches an open cart.
	 * @param array<string,mixed>   $incoming Query vars of the recovery link.
	 */
	public static function emailCheckoutUrl( $record = null, array $incoming = [] ): string {
		$args = [
			'utm_source'   => 'fluentcrm',
			'utm_medium'   => 'email',
			'utm_campaign' => 'cart-recovery',
		];

		if ( $record ) {
			$args['utm_content'] = (string) $record->provider;
			$args['fc_ab_cart']  = (int) $record->id;
		}

		// FluentCRM's router may not pass unknown query vars through, so read the request too.
		$sources = array_merge( wp_unslash( $_GET ), $incoming ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id' ] as $key ) {
			$value = isset( $sources[ $key ] ) && is_scalar( $sources[ $key ] ) ? sanitize_text_field( (string) $sources[ $key ] ) : '';
			if ( '' !== $value ) {
				$args[ $key ] = substr( $value, 0, 100 );
			}
		}

		return add_query_arg( array_map( 'rawurlencode', $args ), edd_get_checkout_uri() );
	}

	/**
	 * Selects the cart's currency for this visitor, so the rebuilt checkout shows the currency the
	 * cart and its emails were priced in. Only with EDD Multi Currency and a currency it offers.
	 *
	 * @param AbandonCartModel $record
	 */
	private function selectCartCurrency( AbandonCartModel $record ): void {
		$currency  = strtoupper( (string) $record->currency );
		$can_set   = $currency && function_exists( 'eddMultiCurrency' ) && class_exists( '\\EDD_Multi_Currency\\Checkout\\CurrencyHandler' );
		$is_chosen = $can_set && edd_get_currency() === $currency;

		if ( ! $can_set || $is_chosen ) {
			return;
		}

		try {
			eddMultiCurrency( \EDD_Multi_Currency\Checkout\CurrencyHandler::class )->setCurrencyForSession( $currency );
		} catch ( \Throwable $e ) {
			// Not a currency the store offers any more: checkout uses the visitor's current one.
			self::logError( 'selectCartCurrency', $e );
		}
	}

	/**
	 * Puts a license upgrade in the EDD cart as Software Licensing's `sl_license_upgrade` action
	 * does (edd_sl_add_upgrade_to_cart() in 3.9.5), without its redirect.
	 *
	 * @param array<string,mixed> $item Stored upgrade cart item.
	 * @return true|\WP_Error Error code says why the upgrade could not be added.
	 */
	private function addUpgradeToCart( array $item ) {
		$license_id = (int) $item['license_id'];
		$upgrade_id = (int) Arr::get( $item, 'upgrade_id' );

		// Also catches a license that moved to another plan, whose paths the stored upgrade ID no longer indexes.
		if ( ! ( new EddUpgradeCartDriver() )->isUpgradeOpen( $item ) ) {
			return new \WP_Error( 'upgrade_unavailable' );
		}

		$license        = edd_software_licensing()->get_license( $license_id );
		$payment_status = edd_get_payment_status( edd_software_licensing()->get_payment_id( $license_id ) );

		if ( ! in_array( $payment_status, [ 'publish', 'complete', 'partially_refunded' ], true ) ) {
			return new \WP_Error( 'payment_not_complete' );
		}

		$upgrade = edd_sl_get_upgrade_path( $license->download_id, $upgrade_id );
		$cost    = edd_sl_get_license_upgrade_cost( $license_id, $upgrade_id );

		if ( function_exists( 'eddMultiCurrency' ) ) {
			try {
				$cost = \EDD_Multi_Currency\Utils\Currency::convert( $cost, \EDD_Multi_Currency\Utils\Currency::getBaseCurrency(), eddMultiCurrency( \EDD_Multi_Currency\Checkout\CurrencyHandler::class )->getSelectedCurrency() );
			} catch ( \Exception $e ) {
				// Keep the store-currency cost, as Software Licensing does.
				self::logError( 'addUpgradeToCart', $e );
			}
		}

		edd_add_to_cart(
			$upgrade['download_id'],
			[
				'price_id'   => $upgrade['price_id'],
				'is_upgrade' => true,
				'upgrade_id' => $upgrade_id,
				'license_id' => $license_id,
				'cost'       => $cost,
			]
		);

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $codes
	 * @param string                         $context
	 * @return array<int,array<string,mixed>>
	 */
	public function pushContextCodes( $codes, $context ) {
		if ( $this->driver->getTriggerName() !== $context ) {
			return $codes;
		}

		$group = $this->driver->getSmartCodeGroupKey();

		$extra_codes = EddRenewalCartDriver::PROVIDER === $this->driver->getProviderSlug()
			? [
				'{{' . $group . '.license_expiration}}' => __( 'License Expiration Date (earliest in the cart)', 'fluent-crm-custom-features' ),
				'{{' . $group . '.license_status}}'     => __( 'License Status ("expires on …" or "expired on …")', 'fluent-crm-custom-features' ),
			]
			: [];

		$is_upgrade = EddUpgradeCartDriver::PROVIDER === $this->driver->getProviderSlug();

		if ( $is_upgrade ) {
			// Prices are worked out when the email is sent: the upgrade price drops every day.
			$extra_codes = [
				'{{' . $group . '.current_plan}}'      => __( 'Current Plan', 'fluent-crm-custom-features' ),
				'{{' . $group . '.new_plan}}'          => __( 'New Plan', 'fluent-crm-custom-features' ),
				'{{' . $group . '.current_product}}'   => __( 'Current Product (no site count)', 'fluent-crm-custom-features' ),
				'{{' . $group . '.new_plan_name}}'     => __( 'New Plan Name for Sentences (product, or "a {product} plan with more sites")', 'fluent-crm-custom-features' ),
				'{{' . $group . '.full_price}}'        => __( 'New Plan Regular Price', 'fluent-crm-custom-features' ),
				'{{' . $group . '.today_price}}'       => __( 'Upgrade Price Today', 'fluent-crm-custom-features' ),
				'{{' . $group . '.credit}}'            => __( 'Credit for the Current Plan', 'fluent-crm-custom-features' ),
				'{{' . $group . '.price_breakdown}}'   => __( 'Price Breakdown (three lines)', 'fluent-crm-custom-features' ),
				'{{' . $group . '.price_change_line}}' => __( 'Whether the Price Goes Up or Down Each Day', 'fluent-crm-custom-features' ),
				'{{' . $group . '.renewal_line}}'      => __( 'What Happens to the Renewal Date', 'fluent-crm-custom-features' ),
				'{{' . $group . '.renewal_soon_line}}' => __( 'Renews-Soon Paragraph (empty unless the license renews within 30 days)', 'fluent-crm-custom-features' ),
				'{{' . $group . '.new_plan_extras}}'   => __( 'What the New Plan Adds (bullet list)', 'fluent-crm-custom-features' ),
			];
		}

		if ( EddCartDriver::PROVIDER === $this->driver->getProviderSlug() ) {
			$discounts = new EddRecoveryDiscount();

			foreach ( $discounts->getProfiles() as $slug => $profile ) {
				if ( EddRecoveryDiscount::DEFAULT_PROFILE === $slug ) {
					continue;
				}
				/* translators: %s: discount profile label */
				$extra_codes[ '{{' . $group . '.discount.' . $slug . '.code}}' ] = sprintf( __( 'One-time code: %s', 'fluent-crm-custom-features' ), $profile['label'] );
				$extra_codes[ '{{' . $group . '.discount.' . $slug . '.amount}}' ] = sprintf( __( 'Amount: %s', 'fluent-crm-custom-features' ), $profile['label'] );
				$extra_codes[ '{{' . $group . '.discount.' . $slug . '.expires}}' ] = sprintf( __( 'Expiry date: %s', 'fluent-crm-custom-features' ), $profile['label'] );
			}

			$extra_codes[ '##' . $group . '.recovery_url_code.CODE##' ] = __( 'Recovery URL that also applies a store-wide code (replace CODE)', 'fluent-crm-custom-features' );
		}

		$discount_codes = [
			'{{' . $group . '.recovery_discount_code}}'    => __( 'One-time Recovery Discount Code (created on first use)', 'fluent-crm-custom-features' ),
			'{{' . $group . '.recovery_discount_amount}}'  => __( 'Recovery Discount Amount (e.g. 40%)', 'fluent-crm-custom-features' ),
			'{{' . $group . '.recovery_discount_expires}}' => __( 'Recovery Discount Expiry Date', 'fluent-crm-custom-features' ),
		];

		$codes[] = [
			'key'        => $group,
			/* translators: %s: cart provider, e.g. "Easy Digital Downloads" */
			'title'      => sprintf( __( 'Abandoned Cart - %s', 'fluent-crm-custom-features' ), $this->driver->getProviderLabel() ),
			'shortcodes' => $extra_codes + ( $is_upgrade ? [] : $discount_codes ) + [
				'{{' . $group . '.cart_items_table}}'   => __( 'Cart Items', 'fluent-crm-custom-features' ),
				'##' . $group . '.recovery_url##'       => __( 'Cart Recovery URL', 'fluent-crm-custom-features' ),
				'##' . $group . '.stop_url##'           => __( 'Stop These Emails URL', 'fluent-crm-custom-features' ),
				'{{' . $group . '.first_product_name}}' => __( 'First Product Name', 'fluent-crm-custom-features' ),
				'{{' . $group . '.product_names}}'      => __( 'All Product Names', 'fluent-crm-custom-features' ),
				'{{' . $group . '.cart_total}}'         => __( 'Cart Total', 'fluent-crm-custom-features' ),
				'{{' . $group . '.subtotal}}'           => __( 'Cart Subtotal', 'fluent-crm-custom-features' ),
				'{{' . $group . '.discount_total}}'     => __( 'Cart Discount Total', 'fluent-crm-custom-features' ),
				'{{' . $group . '.coupon_codes}}'       => __( 'Applied Coupon Codes', 'fluent-crm-custom-features' ),
				'{{' . $group . '.billing_full_name}}'  => __( 'Full Name', 'fluent-crm-custom-features' ),
				'{{' . $group . '.billing_first_name}}' => __( 'First Name', 'fluent-crm-custom-features' ),
			],
		];

		return $codes;
	}

	/**
	 * @param string     $code          Full smart code.
	 * @param string     $value_key     Part after the group key.
	 * @param string     $default_value Fallback after the pipe.
	 * @param Subscriber $subscriber
	 * @return string
	 */
	public function parseSmartCode( $code, $value_key, $default_value, $subscriber ) {
		// Shopper-typed values (names) are escaped here: the result is placed straight into email HTML.
		$cart = $this->findCartForSubscriber( $subscriber );

		if ( ! $cart ) {
			return defined( 'FLUENTCRM_PREVIEWING_EMAIL' ) ? __( 'Dynamic text will be available in the real email', 'fluent-crm-custom-features' ) : $default_value;
		}

		$items = Arr::get( $cart->cart, 'cart_contents', [] );

		if ( $this->driver instanceof EddUpgradeCartDriver ) {
			$upgrade_value = $this->driver->getUpgradeCodeValue( $cart, (string) $value_key, (string) $default_value );

			if ( null !== $upgrade_value ) {
				return $upgrade_value;
			}

			// Upgrades never get a discount code, even from a hand-edited email.
			$is_discount_code = 0 === strpos( (string) $value_key, 'recovery_discount' ) || 0 === strpos( (string) $value_key, 'discount.' );

			if ( $is_discount_code ) {
				return $default_value;
			}

			if ( 0 === strpos( (string) $value_key, 'recovery_url_code.' ) ) {
				return $this->driver->getRecoveryUrl( $cart ) ?: edd_get_checkout_uri();
			}
		}

		switch ( $value_key ) {
			case 'cart_items_table':
				return $this->driver->getCartItemsHtml( $cart );
			case 'recovery_url':
				return $this->driver->getRecoveryUrl( $cart ) ?: edd_get_checkout_uri();
			case 'stop_url':
				return CartEmailStop::url( $cart ) ?: home_url( '/' );
			case 'first_product_name':
				return $items ? esc_html( (string) $items[0]['title'] ) : $default_value;
			case 'product_names':
				return $items ? esc_html( implode( ', ', wp_list_pluck( $items, 'title' ) ) ) : $default_value;
			case 'cart_total':
				return $this->driver->formatPrice( $cart->total, $cart->currency );
			case 'subtotal':
				return $this->driver->formatPrice( $cart->subtotal, $cart->currency );
			case 'discount_total':
				return $cart->discounts > 0 ? $this->driver->formatPrice( $cart->discounts, $cart->currency ) : $default_value;
			case 'coupon_codes':
				$coupons = (array) Arr::get( $cart->cart, 'coupons', [] );
				return $coupons ? esc_html( strtoupper( implode( ', ', $coupons ) ) ) : $default_value;
			case 'billing_full_name':
				return $cart->full_name ? esc_html( $cart->full_name ) : $default_value;
			case 'billing_first_name':
				$first = trim( explode( ' ', (string) $cart->full_name )[0] );
				return $first ? esc_html( $first ) : $default_value;
			case 'recovery_discount_code':
				$discount = ( new EddRecoveryDiscount() )->getOrCreate( $cart );
				return $discount ? $discount['code'] : $default_value;
			case 'recovery_discount_amount':
				return ( new EddRecoveryDiscount() )->getAmountLabel();
			case 'recovery_discount_expires':
				$discount = ( new EddRecoveryDiscount() )->getOrCreate( $cart );
				return $discount && $discount['expires'] ? (string) wp_date( get_option( 'date_format' ), $discount['expires'] ) : $default_value;
			case 'license_expiration':
			case 'license_status':
				$expirations = array_filter( array_map( 'intval', wp_list_pluck( $items, 'license_expiration' ) ) );
				if ( ! $expirations ) {
					return $default_value;
				}
				// EDD SL stores expiration as site-local time, so it is formatted without a timezone shift.
				$earliest = min( $expirations );
				$date     = date_i18n( get_option( 'date_format' ), $earliest );
				if ( 'license_expiration' === $value_key ) {
					return $date;
				}
				/* translators: %s: date */
				return sprintf( $earliest < current_time( 'timestamp' ) ? __( 'expired on %s', 'fluent-crm-custom-features' ) : __( 'expires on %s', 'fluent-crm-custom-features' ), $date );
			default:
				if ( preg_match( '/^discount\.([a-z0-9_-]+)\.(code|amount|expires)$/', $value_key, $m ) ) {
					return $this->discountValue( $cart, $m[1], $m[2], $default_value );
				}
				if ( 0 === strpos( $value_key, 'recovery_url_code.' ) ) {
					$url = $this->driver->getRecoveryUrl( $cart );
					return $url ? add_query_arg( 'fc_ab_code', rawurlencode( strtoupper( substr( $value_key, 18 ) ) ), $url ) : edd_get_checkout_uri();
				}
				return (string) apply_filters( 'fluent_crm/ab_cart_smart_code_default_value', $default_value, $value_key, $cart );
		}
	}

	/**
	 * Value of a `discount.<profile>.<field>` smart code, creating the code on first use.
	 *
	 * @param AbandonCartModel $cart
	 * @param string           $profile       Discount profile slug.
	 * @param string           $field         `code`, `amount` or `expires`.
	 * @param string           $default_value Returned when there is no value.
	 */
	private function discountValue( AbandonCartModel $cart, string $profile, string $field, string $default_value ): string {
		$discounts = new EddRecoveryDiscount();

		if ( 'amount' === $field ) {
			return $discounts->getAmountLabel( $profile ) ?: $default_value;
		}

		$discount = $discounts->getOrCreate( $cart, $profile );

		if ( ! $discount ) {
			return $default_value;
		}

		if ( 'code' === $field ) {
			return $discount['code'];
		}

		return $discount['expires'] ? (string) wp_date( get_option( 'date_format' ), $discount['expires'] ) : $default_value;
	}

	/**
	 * The cart that started this email's automation run, falling back to the contact's newest cart.
	 *
	 * @param Subscriber $subscriber
	 */
	private function findCartForSubscriber( $subscriber ): ?AbandonCartModel {
		$cart = null;

		if ( ! empty( $subscriber->funnel_subscriber_id ) ) {
			$funnel_subscriber = FunnelSubscriber::find( $subscriber->funnel_subscriber_id );

			if ( $funnel_subscriber && $funnel_subscriber->source_ref_id ) {
				$cart = AbandonCartModel::where( 'id', $funnel_subscriber->source_ref_id )->where( 'provider', $this->driver->getProviderSlug() )->first();
			}
		}

		if ( ! $cart && ! empty( $subscriber->email ) ) {
			$cart = AbandonCartModel::where( 'email', $subscriber->email )
				->where( 'provider', $this->driver->getProviderSlug() )
				->whereIn( 'status', [ 'processing', 'opt_out', 'lost' ] )
				->orderBy( 'id', 'DESC' )
				->first();
		}

		if ( ! $cart && defined( 'FLUENTCRM_PREVIEWING_EMAIL' ) ) {
			$cart = AbandonCartModel::where( 'provider', $this->driver->getProviderSlug() )->orderBy( 'id', 'DESC' )->first();
		}

		return $cart;
	}

	/**
	 * Find this visitor's open cart: by cookie token, then email, then logged-in user.
	 *
	 * @param string $email    Shopper email, if known.
	 * @param string $provider Limit to one provider; empty for either EDD provider.
	 */
	private function getCurrentRecord( string $email = '', string $provider = '' ): ?AbandonCartModel {
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) );

		$query = function () use ( $provider ) {
			return AbandonCartModel::whereIn( 'provider', $provider ? [ $provider ] : self::PROVIDERS )->whereIn( 'status', self::OPEN_STATUSES );
		};

		if ( $token ) {
			$record = $query()->where( 'checkout_key', $token )->first();
			if ( $record ) {
				return $record;
			}
		}

		// Anyone can type any email at checkout, so a match on email alone never hands over a cart
		// that is being emailed: its recovery link and discount code belong to that shopper.
		if ( $email ) {
			$record = $query()->where( 'email', $email )->whereIn( 'status', [ 'draft', 'pending' ] )->orderBy( 'id', 'DESC' )->first();
			if ( $record ) {
				return $record;
			}
		}

		$user_id = get_current_user_id();

		if ( $user_id ) {
			return $query()->where( 'user_id', $user_id )->orderBy( 'id', 'DESC' )->first();
		}

		return null;
	}

	/**
	 * Test and throwaway addresses: each would become a FluentCRM contact, and a paid enrichment lookup.
	 */
	public static function isIgnoredEmail( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );

		if ( '' === $domain ) {
			return false;
		}

		// Reserved for testing by RFC 2606 and RFC 6761; `.local` is mDNS and never a real mailbox.
		$reserved_tlds = [ 'test', 'local', 'invalid', 'example', 'localhost' ];

		$ignored_domains = (array) apply_filters(
			'customcrm/edd_ab_cart/ignored_email_domains',
			[ 'example.com', 'example.net', 'example.org', 'mailinator.com', 'yopmail.com' ],
			$email
		);

		$tld = (string) substr( (string) strrchr( '.' . $domain, '.' ), 1 );

		if ( in_array( $tld, $reserved_tlds, true ) ) {
			return true;
		}

		foreach ( $ignored_domains as $ignored ) {
			$ignored = strtolower( ltrim( (string) $ignored, '@.' ) );

			if ( $ignored && ( $domain === $ignored || substr( $domain, -strlen( '.' . $ignored ) ) === '.' . $ignored ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this visitor has the cart-tracking opt-out cookie.
	 */
	private function hasOptedOut(): bool {
		return 'yes' === sanitize_text_field( wp_unslash( $_COOKIE[ self::OPT_OUT ] ?? '' ) );
	}

	/**
	 * Sets the cart token cookie, and `$_COOKIE` for the rest of this request.
	 *
	 * @param string $value Checkout key; empty with a negative `$days` to clear it.
	 * @param int    $days  0 for the `fluent_crm/ab_cart_cookie_validity` default.
	 */
	private function setCookie( string $value, int $days = 0 ): void {
		if ( headers_sent() ) {
			return;
		}

		if ( ! $days ) {
			$days = (int) apply_filters( 'fluent_crm/ab_cart_cookie_validity', 30 );
		}

		setcookie( self::COOKIE, $value, time() + ( DAY_IN_SECONDS * $days ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * @param mixed $amount
	 */
	private function money( $amount ): string {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
