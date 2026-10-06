<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

/**
 * "Stop these emails" link for cart emails ({{ab_cart_*.stop_url}}).
 *
 * Stops the reminders about one cart and keeps the contact out of every cart automation for a while,
 * without unsubscribing them from anything else. FluentCRM's own Unsubscribe link stays in the footer
 * for leaving all marketing email.
 *
 * Opening the link only shows a confirmation page; the stop happens on its form post, so mail
 * scanners that open every link in a message cannot stop the emails on the shopper's behalf.
 */
class CartEmailStop {

	public const QUERY_ARG = 'gk_cart_stop';

	/**
	 * Contact meta holding the Unix time the contact last used the link.
	 */
	public const META_KEY = 'ab_cart_emails_stopped_at';

	/**
	 * Days a contact who used the link gets no cart emails. Filter: `customcrm/edd_ab_cart/stop_days`.
	 */
	public const STOP_DAYS = 90;

	/**
	 * Notes for carts held back in this request, keyed by cart ID.
	 *
	 * @var array<int,string>
	 */
	private static $held_back = [];

	/**
	 * Hooks the link's page.
	 */
	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'handleRequest' ], 0 );
	}

	/**
	 * The link for one cart, or an empty string for a cart that cannot be stopped.
	 *
	 * @param AbandonCartModel $cart
	 */
	public static function url( AbandonCartModel $cart ): string {
		if ( ! $cart->id || ! $cart->checkout_key ) {
			return '';
		}

		return add_query_arg(
			[
				self::QUERY_ARG => (int) $cart->id,
				'key'           => self::key( $cart ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Whether this cart's contact used the link within the stop period. Called by every cart driver
	 * before it starts an automation.
	 *
	 * @param AbandonCartModel $cart
	 */
	public static function holdBack( AbandonCartModel $cart ): bool {
		$contact_id = self::contactId( $cart );

		if ( ! $contact_id ) {
			return false;
		}

		$stopped_at = (int) fluentcrm_get_subscriber_meta( $contact_id, self::META_KEY, 0 );
		$days       = (int) apply_filters( 'customcrm/edd_ab_cart/stop_days', self::STOP_DAYS, $cart );
		$is_stopped = $stopped_at && $days > 0 && $stopped_at >= time() - ( $days * DAY_IN_SECONDS );

		if ( ! $is_stopped ) {
			return false;
		}

		$note = sprintf(
			/* translators: %s: date */
			__( 'Held back: the shopper stopped cart emails on %s', 'fluent-crm-custom-features' ),
			wp_date( get_option( 'date_format' ), $stopped_at )
		);

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
	}

	/**
	 * Shows the confirmation page, or stops the emails on its form post.
	 */
	public static function handleRequest(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$cart_id = absint( $_GET[ self::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cart    = $cart_id ? AbandonCartModel::find( $cart_id ) : null;

		nocache_headers();

		$is_valid = $cart && hash_equals( self::key( $cart ), $key );

		if ( ! $is_valid ) {
			self::render( __( 'This link has expired', 'fluent-crm-custom-features' ), __( 'If you keep getting emails about a cart, reply to any of them and we’ll stop them for you.', 'fluent-crm-custom-features' ), '', 404 );
		}

		$is_post = 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );

		if ( $is_post ) {
			self::stop( $cart );
			self::render( __( 'Done. No more cart reminders.', 'fluent-crm-custom-features' ), __( 'You won’t get any more emails about what you left in your cart. You’ll still get receipts, license emails and anything else you signed up for.', 'fluent-crm-custom-features' ) );
		}

		self::render(
			__( 'Stop cart reminders?', 'fluent-crm-custom-features' ),
			__( 'We’ll stop emailing you about what you left in your cart. You’ll still get receipts, license emails and anything else you signed up for.', 'fluent-crm-custom-features' ),
			esc_url( self::url( $cart ) )
		);
	}

	/**
	 * Stops this cart's emails and records the stop on the contact.
	 *
	 * @param AbandonCartModel $cart
	 */
	public static function stop( AbandonCartModel $cart ): void {
		$contact_id = self::contactId( $cart );

		// FluentCRM's opt-out also removes the contact from the cart's automation run.
		$cart->optOut();
		AbandonCartModel::where( 'id', $cart->id )->update( [ 'note' => __( 'Shopper stopped cart emails from the email link', 'fluent-crm-custom-features' ) ] );

		if ( ! $contact_id ) {
			return;
		}

		fluentcrm_update_subscriber_meta( $contact_id, self::META_KEY, time() );

		$campaign_ids = CartEmailGuard::cartCampaignIds();

		if ( $campaign_ids ) {
			CampaignEmail::where( 'subscriber_id', $contact_id )
				->whereIn( 'campaign_id', $campaign_ids )
				->whereIn( 'status', [ 'pending', 'scheduled' ] )
				->update(
					[
						'status' => 'cancelled',
						'note'   => __( 'Shopper stopped cart emails', 'fluent-crm-custom-features' ),
					]
				);
		}

		// Other open carts for the same contact (a renewal and an upgrade, say) stop too.
		foreach ( AbandonCartModel::where( 'contact_id', $contact_id )->where( 'id', '!=', $cart->id )->whereIn( 'status', [ 'draft', 'pending', 'processing' ] )->get() as $other ) {
			$other->optOut();
		}
	}

	/**
	 * An unguessable key for the cart's link, tied to the cart's private checkout key.
	 *
	 * @param AbandonCartModel $cart
	 */
	private static function key( AbandonCartModel $cart ): string {
		return substr( hash_hmac( 'sha256', 'cart-stop|' . (int) $cart->id . '|' . $cart->checkout_key, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * The cart's contact: its own, or the contact with its email.
	 *
	 * @param AbandonCartModel $cart
	 */
	private static function contactId( AbandonCartModel $cart ): int {
		if ( $cart->contact_id ) {
			return (int) $cart->contact_id;
		}

		$contact = $cart->email ? FluentCrmApi( 'contacts' )->getContact( $cart->email ) : null;

		return $contact ? (int) $contact->id : 0;
	}

	/**
	 * Prints a small branded page and exits.
	 *
	 * @param string $title       Heading.
	 * @param string $message     Paragraph under it.
	 * @param string $form_action Escaped URL for a confirm button; empty for no button.
	 * @param int    $status      HTTP status.
	 */
	private static function render( string $title, string $message, string $form_action = '', int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		include __DIR__ . '/Views/CartEmailStopPage.php';
		exit;
	}
}
