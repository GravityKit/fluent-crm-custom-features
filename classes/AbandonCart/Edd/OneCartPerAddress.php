<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\App\Modules\AbandonCart\AbCartHelper;

/**
 * Emails one new-purchase draft per address. An address can hold drafts from several browsers, and
 * each automation start would replace the previous run with that cart's items.
 */
class OneCartPerAddress {

	/**
	 * Notes for carts held back during this request, keyed by cart ID.
	 *
	 * @var array<int,string>
	 */
	private static $held_back = [];

	/**
	 * Holds a draft back while its address opted out at checkout in any browser, or while another open
	 * draft for it goes first: the account's, else the oldest. Runs last in the driver's cool-off check,
	 * whose other rules depend only on the address.
	 *
	 * @param AbandonCartModel $cart The draft the runner picked.
	 * @return bool True when the cart is held back.
	 */
	public static function holdBack( AbandonCartModel $cart ): bool {
		if ( ! $cart->email || EddCartDriver::PROVIDER !== $cart->provider ) {
			return false;
		}

		// The opt-out cookie stops tracking only in the browser that opted out, for this many days.
		$opt_out_days = (int) apply_filters( 'fluent_crm/ab_cart_opt_out_cookie_validity', 7 );
		$opted_out    = AbandonCartModel::whereIn( 'provider', [ EddCartDriver::PROVIDER, EddRenewalCartDriver::PROVIDER, EddUpgradeCartDriver::PROVIDER ] )
			->where( 'email', $cart->email )
			->where( 'status', 'opt_out' )
			->where( 'updated_at', '>=', gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $opt_out_days * DAY_IN_SECONDS ) )
			->exists();

		if ( $opted_out ) {
			return self::hold( $cart, __( 'Held back: this address opted out of cart emails at checkout', 'fluent-crm-custom-features' ) );
		}

		// Only a draft the runner can pick now goes first, as AbandonCart::maybeRunAbRunner() picks them:
		// a draft still being changed can be emptied or bought before then, and a held-back cart is
		// never looked at again.
		$minutes  = (int) AbCartHelper::getSetting( 'capture_after_minutes', 5 );
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $minutes * MINUTE_IN_SECONDS );
		$owner    = get_user_by( 'email', (string) $cart->email );
		$owner_id = $owner ? (int) $owner->ID : 0;
		$drafts   = function () use ( $cart, $cutoff ) {
			return AbandonCartModel::where( 'provider', EddCartDriver::PROVIDER )
				->where( 'email', $cart->email )
				->where( 'id', '!=', $cart->id )
				->where( 'status', 'draft' )
				->where( 'updated_at', '<=', $cutoff );
		};

		$is_account = $owner_id && (int) $cart->user_id === $owner_id;
		if ( $is_account ) {
			$first = $drafts()->where( 'user_id', $owner_id )->where( 'id', '<', $cart->id )->orderBy( 'id' )->first();
		} else {
			$account_cart = $owner_id ? $drafts()->where( 'user_id', $owner_id )->orderBy( 'id' )->first() : null;
			$first        = $account_cart ?: $drafts()->where( 'id', '<', $cart->id )->orderBy( 'id' )->first();
		}

		if ( ! $first ) {
			return false;
		}

		/* translators: %d: ID of the cart that is emailed instead */
		return self::hold( $cart, sprintf( __( 'Held back: cart #%d for this address is emailed instead', 'fluent-crm-custom-features' ), (int) $first->id ) );
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
	 * Records why the cart is held back, for writeNotes().
	 *
	 * @param AbandonCartModel $cart The cart held back.
	 * @param string           $note The reason, shown on the cart in FluentCRM.
	 * @return bool Always true.
	 */
	private static function hold( AbandonCartModel $cart, string $note ): bool {
		$data              = $cart->cart ?: [];
		$data['held_back'] = $note;
		$cart->cart        = $data;

		if ( ! self::$held_back ) {
			add_action( 'shutdown', [ self::class, 'writeNotes' ], 1 );
		}

		self::$held_back[ (int) $cart->id ] = $note;

		return true;
	}
}
