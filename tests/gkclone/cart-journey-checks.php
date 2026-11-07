<?php
/**
 * Upgrade and renewal journey checks (WEBSITE-544) against a gkclone copy of the store. Creates
 * t544-* contacts and deletes them; blocks all outbound HTTP while it runs.
 *
 *   docker cp tests/gkclone/cart-journey-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/cart-journey-checks.php
 */

use CustomCRM\AbandonCart\Edd\CartJourney;
use CustomCRM\AbandonCart\Edd\EddCartDriver;
use CustomCRM\AbandonCart\Edd\EddRenewalCartDriver;
use CustomCRM\AbandonCart\Edd\EddUpgradeCartDriver;
use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

$run     = substr( md5( microtime() ), 0, 6 );
$results = [];
$started = [];
$base_id = random_int( 900000000, 999000000 );

add_filter( 'pre_http_request', static function () { return new WP_Error( 't544', 'blocked' ); }, 1 );

// The theme's listener would queue PostHog events for these made-up licenses.
remove_all_actions( 'customcrm/edd_cart/journey_started' );

$on_started = static function ( $data ) use ( &$started ) {
	$started[] = $data;
};
add_action( 'customcrm/edd_cart/journey_started', $on_started );

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$results ) {
	$results[] = [ $name, $pass, $detail ];
};

$email_for = static function ( string $tag ) use ( $run ) {
	return "t544-{$tag}-{$run}@gravitykit-t402.dev";
};

$contact_for = static function ( string $tag ) use ( $email_for ) {
	return FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $email_for( $tag ), 'status' => 'subscribed' ] );
};

/** An unsaved cart row, the shape cartSaved() receives after syncCart() stores one. */
$cart = static function ( string $provider, string $email, int $id = 1, int $user_id = 0 ) {
	$row           = new AbandonCartModel();
	$row->id       = $id;
	$row->provider = $provider;
	$row->email    = $email;
	$row->user_id  = $user_id;

	return $row;
};

$item = static function ( int $license_id ) {
	return [ 'license_id' => $license_id ];
};

$events = static function ( $contact, string $key ) {
	return EventTracker::where( 'subscriber_id', $contact->id )->where( 'event_key', $key )->get();
};

$upgrade = EddUpgradeCartDriver::PROVIDER;
$renewal = EddRenewalCartDriver::PROVIDER;

try {
	// A new-purchase cart is no journey.
	$contact = $contact_for( 'a' );
	$lic     = $base_id + 1;

	CartJourney::cartSaved( $cart( EddCartDriver::PROVIDER, $contact->email ), [ $item( 0 ) ] );
	$check( 'a new-purchase cart starts nothing', 0 === count( $started ), $started );

	// One start, however often the cart syncs.
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 10 ), [ $item( $lic ) ] );
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 10 ), [ $item( $lic ) ] );
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 10 ), [ $item( $lic ) ] );
	$rows = $events( $contact, 'upgrade_started' );
	$check( 'repeat syncs fire once', 1 === count( $started ) && 'upgrade' === $started[0]['journey'] && [ $lic ] === $started[0]['license_ids'], $started );
	$check( 'repeat syncs record once on the contact', 1 === count( $rows ) && 1 === (int) $rows[0]->counter, $rows->toArray() );
	$check( 'the event names the cart and license', [ 'cart_id' => 10, 'license_ids' => (string) $lic ] === json_decode( (string) $rows[0]->value, true ), $rows[0]->value );

	$widgets = apply_filters( 'fluent_crm/subscriber_info_widgets', [], $contact );
	$check( 'the contact page shows the event', false !== strpos( (string) ( $widgets['event_tracking_json']['content'] ?? '' ), 'Upgrade started' ), array_keys( $widgets ) );

	// The checkout card empties a one-item cart, so the swap arrives as a new row.
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 11 ), [ $item( $lic ) ] );
	$check( 'a swap that makes a new row is not a new start', 1 === count( $started ), $started );

	// Undo, then the same upgrade again.
	CartJourney::cartSaved( $cart( EddCartDriver::PROVIDER, $contact->email, 12 ), [ $item( 0 ) ] );
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 13 ), [ $item( $lic ) ] );
	$check( 'undo and redo is not a new start', 1 === count( $started ), $started );

	// More licenses join a started cart.
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 13 ), [ $item( $lic ), $item( $lic + 1 ) ] );
	$check( 'adding a license to a started cart is not a new start', 1 === count( $started ), $started );

	// A renewal of the same license is its own journey.
	CartJourney::cartSaved( $cart( $renewal, $contact->email, 14 ), [ $item( $lic ) ] );
	$check( 'a renewal of that license starts its own journey', 2 === count( $started ) && 'renewal' === $started[1]['journey'], $started );
	$check( 'the renewal is on the contact', 1 === count( $events( $contact, 'renewal_started' ) ) );

	// Finishing frees the licenses and is recorded.
	do_action( 'gk/store/journey_completed', [ 'journey' => 'upgrade', 'order_type' => 'upgrade', 'order_id' => 77, 'email' => $contact->email, 'user_id' => 0, 'license_ids' => [ $lic ] ] );
	$rows = $events( $contact, 'upgrade_completed' );
	$check( 'a completed order is recorded', 1 === count( $rows ) && 77 === json_decode( (string) $rows[0]->value, true )['order_id'], $rows->toArray() );

	$before = count( $started );
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 15 ), [ $item( $lic ) ] );
	$check( 'a later cart for a finished license is a new start', $before + 1 === count( $started ), $started );
	$check( 'the contact counts both starts', 2 === (int) $events( $contact, 'upgrade_started' )[0]->counter );

	// Sessions the filter calls staff.
	$before = count( $started );
	add_filter( 'gk/store/is_staff_session', '__return_true', 99 );
	CartJourney::cartSaved( $cart( $upgrade, $contact->email, 16 ), [ $item( $lic + 10 ) ] );
	remove_filter( 'gk/store/is_staff_session', '__return_true', 99 );
	$check( 'a staff session starts nothing', $before === count( $started ), $started );
	$check( 'a staff session claims nothing', false === get_transient( 'customcrm_cart_journey_upgrade_' . ( $lic + 10 ) ) );

	// FluentCRM's excluded roles count as staff.
	$roles = (array) \FluentCrm\App\Modules\AbandonCart\AbCartHelper::getSetting( 'disabled_user_roles', [] );
	$staff = wp_insert_user( [ 'user_login' => 't544staff' . $run, 'user_email' => $email_for( 'staff' ), 'user_pass' => wp_generate_uuid4(), 'role' => 'support_provider' ] );
	$buyer = wp_insert_user( [ 'user_login' => 't544buyer' . $run, 'user_email' => $email_for( 'buyer' ), 'user_pass' => wp_generate_uuid4(), 'role' => 'subscriber' ] );
	wp_set_current_user( $staff );
	$check( 'an excluded role counts as staff', in_array( 'support_provider', $roles, true ) && true === apply_filters( 'gk/store/is_staff_session', false ), $roles );
	wp_set_current_user( $buyer );
	$check( 'a customer does not', false === apply_filters( 'gk/store/is_staff_session', false ) );
	wp_set_current_user( 0 );

	// Internal addresses, for a team member testing while logged out.
	$check( 'an internal address counts as staff', true === apply_filters( 'gk/store/is_staff_session', false, 'someone@gravitykit.com' ) );
	$check( 'an outside address does not', false === apply_filters( 'gk/store/is_staff_session', false, $contact->email ) );

	// The right contact: the email first, then the user.
	$linked          = $contact_for( 'buyer' );
	$linked->user_id = (int) $buyer;
	$linked->save();
	do_action( 'gk/store/journey_completed', [ 'journey' => 'renewal', 'order_type' => 'manual_renewal', 'order_id' => 78, 'email' => 'typed-' . $email_for( 'other' ), 'user_id' => (int) $buyer, 'license_ids' => [] ] );
	$check( 'another typed address falls back to the user\'s contact', 1 === count( $events( $linked, 'renewal_completed' ) ) );

	// No contact: nothing is created.
	$ghost = $email_for( 'ghost' );
	CartJourney::cartSaved( $cart( $upgrade, $ghost, 17 ), [ $item( $lic + 20 ) ] );
	do_action( 'gk/store/journey_completed', [ 'journey' => 'upgrade', 'order_type' => 'upgrade', 'order_id' => 79, 'email' => $ghost, 'user_id' => 0, 'license_ids' => [ $lic + 20 ] ] );
	$check( 'no contact is ever created', ! Subscriber::where( 'email', $ghost )->first() );
	$check( 'the start still goes to PostHog without a contact', $lic + 20 === end( $started )['license_ids'][0], end( $started ) );

	// A broken listener stays out of the cart.
	$boom = static function () {
		throw new RuntimeException( 'listener broke' );
	};
	add_action( 'customcrm/edd_cart/journey_started', $boom, 1 );
	$threw = false;
	try {
		CartJourney::cartSaved( $cart( $upgrade, $contact->email, 18 ), [ $item( $lic + 30 ) ] );
	} catch ( Throwable $e ) {
		$threw = true;
	}
	remove_action( 'customcrm/edd_cart/journey_started', $boom, 1 );
	$check( 'a failing listener never reaches the cart', ! $threw );

	// Bad data from the other plugin.
	$threw = false;
	try {
		CartJourney::orderCompleted( 'not an array' );
		CartJourney::orderCompleted( [ 'journey' => 'nonsense', 'email' => $contact->email ] );
	} catch ( Throwable $e ) {
		$threw = true;
	}
	$check( 'bad completion data does nothing', ! $threw && 0 === count( $events( $contact, 'nonsense_completed' ) ) );

	// Without the theme's listener the plugin still records the start.
	remove_all_actions( 'customcrm/edd_cart/journey_started' );
	remove_all_filters( 'gk/store/is_staff_session' );
	$alone = $contact_for( 'alone' );
	CartJourney::cartSaved( $cart( $upgrade, $alone->email, 19 ), [ $item( $lic + 40 ) ] );
	$check( 'nothing listening is fine', 1 === count( $events( $alone, 'upgrade_started' ) ) );
} catch ( Throwable $e ) {
	$check( 'run finished', false, $e->getMessage() . ' @' . $e->getLine() );
} finally {
	foreach ( range( 0, 45 ) as $offset ) {
		foreach ( [ 'upgrade', 'renewal' ] as $journey ) {
			delete_transient( "customcrm_cart_journey_{$journey}_" . ( $base_id + $offset ) );
		}
	}

	$ids = Subscriber::where( 'email', 'like', "t544-%-{$run}@gravitykit-t402.dev" )->pluck( 'id' )->toArray();
	EventTracker::whereIn( 'subscriber_id', $ids )->delete();
	Subscriber::whereIn( 'id', $ids )->delete();

	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( [ 'staff', 'buyer' ] as $tag ) {
		$user_id = email_exists( $email_for( $tag ) );
		if ( $user_id ) {
			wp_delete_user( $user_id );
		}
	}
}

$failed = array_filter(
	$results,
	static function ( $row ) {
		return ! $row[1];
	}
);

foreach ( $results as $row ) {
	echo ( $row[1] ? 'PASS ' : 'FAIL ' ), $row[0], $row[1] ? '' : ' ' . wp_json_encode( $row[2] ), "\n";
}

echo sprintf( "%d checks, %d failed\n", count( $results ), count( $failed ) );
