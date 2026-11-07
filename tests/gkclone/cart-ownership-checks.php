<?php
/**
 * WEBSITE-498: each browser saves its own cart, and checkout sign-in links and cart emails work together.
 * Creates t498-* fixtures (a product, accounts, carts, orders, a discount) and deletes them; blocks
 * outbound HTTP while it runs. Needs only EDD and FluentCRM, so it runs on a fresh gkclone store.
 *
 *   docker cp tests/gkclone/cart-ownership-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/cart-ownership-checks.php
 */

use CustomCRM\AbandonCart\Edd\AllowedDomains;
use CustomCRM\AbandonCart\Edd\EddCartDriver;
use CustomCRM\AbandonCart\Edd\EddCartTracking;
use CustomCRM\AbandonCart\Edd\EddRenewalCartDriver;
use CustomCRM\AbandonCart\Edd\OneCartPerAddress;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\App\Modules\AbandonCart\AbCartHelper;

$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$is_local = in_array( $host, [ 'localhost', '127.0.0.1' ], true ) || '.smint.orb.local' === substr( $host, -16 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! $is_local ) {
	throw new RuntimeException( 'Cart ownership checks require a disposable local WP-CLI site.' );
}

global $wpdb;
$p           = $wpdb->prefix;
$run         = substr( md5( microtime() ), 0, 6 );
$results     = [];
$skips       = [];
$emails      = [];
$orders      = [];
$users       = [];
$product     = 0;
$allowed     = get_option( AllowedDomains::OPTION, null );
$cookie_name = 'fc_ab_edd_cart_token';
$handoff_name = 'fc_ab_edd_cart_handoff';
$tracker     = new EddCartTracking( new EddCartDriver() );
// When cookies started being signed; the checks move it to test cookies from before.
$since_option = ( new ReflectionClassConstant( EddCartTracking::class, 'SIGNED_SINCE_OPTION' ) )->getValue();
$signed_since = get_option( $since_option, null );

$check = function ( string $name, bool $pass, $detail = '' ) use ( &$results ) {
	$results[] = [ 'check' => $name, 'pass' => $pass, 'detail' => $detail ];
};
$email = function ( string $tag ) use ( $run, &$emails ) {
	$address  = "t498-{$tag}-{$run}@gravitykit-t498.dev";
	$emails[] = $address;
	return $address;
};
$user = function ( string $address ) use ( $run, &$users ) {
	$id      = wp_insert_user( [ 'user_login' => 't498-' . md5( $address ), 'user_email' => $address, 'user_pass' => wp_generate_password( 24 ) ] );
	$users[] = $id;
	return (int) $id;
};
// A browser is its cookies and its EDD cart; $cookies is the cart cookie, or [ cart cookie, handoff cookie ].
// Runs $act in it and returns its result and both cookies after.
$browser = function ( $cookies, ?int $price_id, callable $act ) use ( &$product, $cookie_name, $handoff_name ) {
	[ $cart_cookie, $handoff ] = array_pad( (array) $cookies, 2, '' );
	unset( $_COOKIE[ $cookie_name ], $_COOKIE[ $handoff_name ] );
	if ( '' !== $cart_cookie ) {
		$_COOKIE[ $cookie_name ] = $cart_cookie;
	}
	if ( '' !== $handoff ) {
		$_COOKIE[ $handoff_name ] = $handoff;
	}
	edd_empty_cart();
	if ( null !== $price_id ) {
		edd_add_to_cart( $product, [ 'price_id' => $price_id ] );
	}
	$out = $act();
	return [ $out, (string) ( $_COOKIE[ $cookie_name ] ?? '' ), (string) ( $_COOKIE[ $handoff_name ] ?? '' ) ];
};
$row = function ( $cart ) {
	return $cart ? AbandonCartModel::find( (int) ( is_object( $cart ) ? $cart->id : $cart ) ) : null;
};
// The signed cookie checkout sets for a cart.
$cookie_for = function ( $cart ) {
	$method = new ReflectionMethod( EddCartTracking::class, 'cookieValue' );
	$method->setAccessible( true );
	return (string) $method->invoke( null, $cart );
};
// The handoff cookie a reminder or sign-in link sets for a cart.
$handoff_for = function ( $cart ) {
	$method = new ReflectionMethod( EddCartTracking::class, 'handoffSignature' );
	$method->setAccessible( true );
	$expires = time() + HOUR_IN_SECONDS;
	return (int) $cart->id . '.' . $expires . '.' . $method->invoke( null, (int) $cart->id, $expires );
};
$prices = function ( $cart ) {
	return array_map( 'intval', wp_list_pluck( (array) ( $cart->cart['cart_contents'] ?? [] ), 'price_id' ) );
};
$in_session = function () {
	return array_map(
		function ( $item ) {
			return (int) $item['id'] . ':' . (int) ( $item['options']['price_id'] ?? -1 );
		},
		(array) edd_get_cart_contents()
	);
};
$make_cart = function ( string $address, string $status, string $provider = 'edd' ) use ( &$product ) {
	return AbandonCartModel::create( [
		'email'    => $address,
		'provider' => $provider,
		'status'   => $status,
		'currency' => 'USD',
		'total'    => 19,
		'cart'     => [ 'cart_contents' => [ [ 'product_id' => $product, 'price_id' => 0, 'quantity' => 1, 'key' => $product . '|0|1|' ] ], 'coupons' => [] ],
	] );
};
// FluentCRM's runner emails a draft once updated_at is older than this (AbandonCart::maybeRunAbRunner()).
$runner_would_pick = function ( int $id ) use ( $wpdb, $p ) {
	$minutes = (int) AbCartHelper::getSetting( 'capture_after_minutes', 5 );
	$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * 60 );
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$p}fc_abandoned_carts WHERE id=%d", $id ) ) <= $cutoff;
};
$age = function ( int $id ) use ( $wpdb, $p ) {
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'updated_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * HOUR_IN_SECONDS ) ], [ 'id' => $id ] );
};
$sign_in_link = function ( string $address ) {
	return EddCartTracking::checkoutSignInUrl( add_query_arg( 'gk_checkout_sign_in', '1', edd_get_checkout_uri() ), $address );
};
$link_cart = function ( string $link ) {
	return (int) ( wp_parse_args( (string) wp_parse_url( $link, PHP_URL_QUERY ) )['gk_checkout_cart'] ?? 0 );
};
// restoreSignInCart() and restoreCart() end in a redirect and exit; the filter stops them first.
$no_redirect = function ( callable $act ) {
	$stop = function () {
		throw new RuntimeException( 't498-redirect' );
	};
	add_filter( 'wp_redirect', $stop, -1000 );
	try {
		$act();
	} catch ( RuntimeException $error ) {
		if ( 't498-redirect' !== $error->getMessage() ) {
			throw $error;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop, -1000 );
	}
};
$use_link = function ( string $link ) use ( $no_redirect ) {
	$_GET = wp_parse_args( (string) wp_parse_url( $link, PHP_URL_QUERY ) );
	try {
		$no_redirect( [ EddCartTracking::class, 'restoreSignInCart' ] );
	} finally {
		$_GET = [];
	}
};
// Completes an order for the EDD cart, as checkout would, so linkOrder() and handleCompletedOrder() run.
$buy = function ( string $address, int $user_id ) use ( &$orders ) {
	$order_id = edd_build_order( [
		'price'        => (float) edd_get_cart_total(),
		'date'         => current_time( 'mysql' ),
		'user_email'   => $address,
		'purchase_key' => strtolower( md5( uniqid( '', true ) ) ),
		'currency'     => edd_get_currency(),
		'downloads'    => (array) edd_get_cart_contents(),
		'cart_details' => (array) edd_get_cart_content_details(),
		'user_info'    => [ 'id' => $user_id, 'email' => $address, 'first_name' => 'T', 'last_name' => 'Four98', 'discount' => 'none', 'address' => [] ],
		'status'       => 'pending',
		'gateway'      => 'manual',
		'mode'         => 'test',
	] );
	$orders[] = (int) $order_id;
	edd_update_order_status( $order_id, 'complete' );
	return (int) $order_id;
};

$blocked_http = [];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$blocked_http ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
			$blocked_http[] = $host;
			return new WP_Error( 't498_blocked', 'blocked by t498' );
		}
		return $pre;
	},
	1,
	3
);

try {
	wp_set_current_user( 0 );
	$product = (int) wp_insert_post( [ 'post_type' => 'download', 'post_status' => 'publish', 'post_title' => 'WEBSITE498 fixture' ] );
	update_post_meta( $product, '_variable_pricing', 1 );
	update_post_meta( $product, 'edd_variable_prices', [ 0 => [ 'name' => 'One site', 'amount' => 19 ], 1 => [ 'name' => 'Three sites', 'amount' => 39 ] ] );

	// 1. Typing another shopper's address saves this browser's own cart.
	$owner = $email( 'owner' );
	[ $a_cart, $a_cookie ] = $browser( '', 0, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Vera Owner' ); } );
	[ $b_cart, $b_cookie ] = $browser( '', 1, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Andy Other' ); } );
	$a_now = $row( $a_cart );
	$check(
		"another browser typing the owner's address saves its own cart",
		$a_cart && $b_cart && (int) $b_cart->id !== (int) $a_cart->id && 'Vera Owner' === $a_now->full_name && [ 0 ] === $prices( $a_now ) && $a_now->checkout_key === $a_cart->checkout_key,
		[ 'owner' => $a_cart ? $a_cart->id : null, 'other' => $b_cart ? $b_cart->id : null, 'owner_name' => $a_now ? $a_now->full_name : null ]
	);
	$check(
		'cookies name their own cart and hold no checkout key',
		0 === strpos( $a_cookie, $a_cart->id . '.' ) && 0 === strpos( $b_cookie, $b_cart->id . '.' ) && false === strpos( $a_cookie . $b_cookie, (string) $a_cart->checkout_key ) && false === strpos( $b_cookie, (string) $b_cart->checkout_key ),
		compact( 'a_cookie', 'b_cookie' )
	);
	[ $a_again ] = $browser( $a_cookie, 1, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Vera Owner' ); } );
	$check( "control: the owner's browser still updates its own cart", $a_again && (int) $a_again->id === (int) $a_cart->id && [ 1 ] === $prices( $row( $a_cart ) ) );
	$typo = $email( 'typo' );
	[ $fixed ] = $browser( $a_cookie, 1, function () use ( $tracker, $typo ) { return $tracker->syncCart( $typo, 'Vera Owner' ); } );
	$check( 'a typo fix in the same browser updates the same cart', $fixed && (int) $fixed->id === (int) $a_cart->id && $typo === $row( $a_cart )->email );
	$browser( $a_cookie, 0, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Vera Owner' ); } );

	// 2. A cookie must be signed for its row. One holding the bare key, as cookies did before signing,
	// opens only a cart saved before then, and only while such cookies can still exist.
	[ $forged ] = $browser( $a_cart->id . '.' . str_repeat( '0', 64 ), 1, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Forger' ); } );
	$check( 'a cookie with a made-up signature does not reach the cart', $forged && (int) $forged->id !== (int) $a_cart->id && 'Vera Owner' === $row( $a_cart )->full_name );
	[ $bare ] = $browser( (string) $a_cart->checkout_key, 1, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Key Holder' ); } );
	$check( 'a cookie holding the key of a cart saved since signing does not reach it', $bare && (int) $bare->id !== (int) $a_cart->id && 'Vera Owner' === $row( $a_cart )->full_name );
	update_option( $since_option, time() + HOUR_IN_SECONDS );
	[ $legacy, $legacy_cookie ] = $browser( (string) $a_cart->checkout_key, 0, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Vera Owner' ); } );
	$check( 'control: a cookie from before signing still reaches its cart and is replaced', $legacy && (int) $legacy->id === (int) $a_cart->id && 0 === strpos( $legacy_cookie, $a_cart->id . '.' ), $legacy_cookie );
	update_option( $since_option, time() - 31 * DAY_IN_SECONDS );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ], [ 'id' => $a_cart->id ] );
	[ $expired ] = $browser( (string) $a_cart->checkout_key, 1, function () use ( $tracker, $owner ) { return $tracker->syncCart( $owner, 'Late Key' ); } );
	$check( 'a cookie from before signing stops working once such cookies have expired', $expired && (int) $expired->id !== (int) $a_cart->id && 'Vera Owner' === $row( $a_cart )->full_name );
	update_option( $since_option, $signed_since );

	// 3. One cart per address is emailed: the account's, else the first, among drafts the runner can pick now.
	$goes_first = function ( $cart ) use ( $row ) {
		return OneCartPerAddress::holdBack( $row( $cart ) );
	};
	foreach ( [ $a_cart, $b_cart, $forged, $bare, $expired ] as $draft ) {
		$age( (int) $draft->id );
	}
	$check( 'the first cart for an address is emailed, later ones held back', false === $goes_first( $a_cart ) && true === $goes_first( $b_cart ) && true === $goes_first( $forged ) );
	$owner_id    = $user( $owner );
	$stranger_id = $user( $email( 'stranger' ) );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'user_id' => $stranger_id ], [ 'id' => $forged->id ] );
	$check( 'control: a cart tied to another account does not go first', false === $goes_first( $a_cart ) && true === $goes_first( $forged ) );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'user_id' => $owner_id ], [ 'id' => $b_cart->id ] );
	$check( "the cart tied to the address's account goes first", true === $goes_first( $a_cart ) && false === $goes_first( $b_cart ) );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $b_cart->id ] );
	$check( 'a draft still being changed does not hold the others back', false === $goes_first( $a_cart ) );
	$age( (int) $b_cart->id );

	// Each driver's isWithinCoolOffPeriod() is what makes FluentCRM's runner skip a cart. The upgrade
	// driver needs a license that can take an upgrade; abandoned-cart-checks.php section 14b covers it.
	update_option( AllowedDomains::OPTION, [] );
	add_filter( 'customcrm/edd_ab_cart/resend_cap_days', '__return_zero' );
	$expect_hold = [ 'edd' => true, 'edd_renewal' => false ];
	foreach ( [ 'edd' => EddCartDriver::class, 'edd_renewal' => EddRenewalCartDriver::class ] as $provider => $class ) {
		$pair   = $email( "pair-{$provider}" );
		$first  = $make_cart( $pair, 'draft', $provider );
		$later  = $make_cart( $pair, 'draft', $provider );
		$age( (int) $first->id );
		$lone   = $make_cart( $email( "lone-{$provider}" ), 'draft', $provider );
		$driver = new $class();
		$alone  = $driver->isWithinCoolOffPeriod( $lone );
		if ( $alone ) {
			$skips[] = "{$provider} driver: a lone cart is already held back on this store";
			continue;
		}
		$name = $expect_hold[ $provider ] ? "{$provider} driver holds back only the later of two carts for one address" : "control: {$provider} driver leaves two carts for one address to its own rules";
		$check( $name, $expect_hold[ $provider ] === $driver->isWithinCoolOffPeriod( $later ) && false === $driver->isWithinCoolOffPeriod( $first ) );
	}
	remove_filter( 'customcrm/edd_ab_cart/resend_cap_days', '__return_zero' );

	// A held-back cart's note names the cart emailed instead.
	OneCartPerAddress::holdBack( $row( $forged ) );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'skipped' ], [ 'id' => $forged->id ] );
	OneCartPerAddress::writeNotes();
	$check( 'a held-back cart says which cart is emailed instead', false !== strpos( (string) $row( $forged )->note, '#' . $b_cart->id ), $row( $forged )->note );

	// An opt-out at checkout covers the address's carts in other browsers while the opt-out cookie lasts.
	$opted     = $email( 'opted' );
	$opt_row   = $make_cart( $opted, 'opt_out' );
	$opt_draft = $make_cart( $opted, 'draft' );
	$check( "an opt-out at checkout holds back the address's carts in other browsers", true === OneCartPerAddress::holdBack( $opt_draft ) );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'updated_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 8 * DAY_IN_SECONDS ) ], [ 'id' => $opt_row->id ] );
	$check( 'control: an opt-out older than its cookie does not', false === OneCartPerAddress::holdBack( $row( $opt_draft ) ) );

	// 4. A signed-in owner gets their account's carts on any device, never a logged-out cart saved under their address.
	$device  = $email( 'device' );
	$dev_id  = $user( $device );
	[ $r, $c1 ] = $browser( '', 0, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Dee Vice' ); } );
	wp_set_current_user( $dev_id );
	[ $acct, $c2 ] = $browser( '', 0, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Signed In' ); } );
	[ $acct_again ] = $browser( '', 1, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Signed In' ); } );
	wp_set_current_user( 0 );
	$check(
		"the address's signed-in owner does not pick up a logged-out cart saved under it",
		$acct && (int) $acct->id !== (int) $r->id && ! $row( $r )->user_id && 'Dee Vice' === $row( $r )->full_name && $dev_id === (int) $row( $acct )->user_id,
		[ $r->id, $acct ? $acct->id : null ]
	);
	$check( "the owner picks up their account's cart on another device", $acct_again && (int) $acct_again->id === (int) $acct->id && [ 1 ] === $prices( $row( $acct ) ) );
	$browser( $c2, 1, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Signed In' ); } );
	$check( 'a logged-out save keeps the account a signed-in save added', $dev_id === (int) $row( $acct )->user_id );
	$moved = $email( 'moved' );
	$browser( $c2, 1, function () use ( $tracker, $moved ) { return $tracker->syncCart( $moved, 'Mo Ved' ); } );
	wp_set_current_user( $dev_id );
	[ $after_move ] = $browser( '', 1, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Signed In' ); } );
	wp_set_current_user( 0 );
	$check( 'a logged-out save under another address drops the account tie, so the account no longer reaches that cart', ! $row( $acct )->user_id && $after_move && (int) $after_move->id !== (int) $acct->id );
	$after_move->delete();
	[ $r3 ] = $browser( '', 0, function () use ( $tracker, $device ) { return $tracker->syncCart( $device, 'Someone' ); } );
	$check( 'control: a logged-out browser without the cookie starts its own cart', $r3 && (int) $r3->id !== (int) $r->id );
	$r3->delete();

	// 5. Sign-in links: a request saves only its own browser's cart, and asking and using both count as activity.
	$r_before = $row( $r );
	[ $link_x, $x_cookie ] = $browser( '', 1, function () use ( $sign_in_link, $device ) { return $sign_in_link( $device ); } );
	$x_cart = $link_cart( $link_x );
	$check(
		"a sign-in request from a browser without the cart's cookie saves its own cart",
		$x_cart && $x_cart !== (int) $r->id && '' === $x_cookie && $prices( $row( $r ) ) === $prices( $r_before ) && $row( $r )->full_name === $r_before->full_name,
		[ 'link_cart' => $x_cart, 'cookie' => $x_cookie ]
	);
	$age( (int) $r->id );
	[ $link_r ] = $browser( $c1, 0, function () use ( $sign_in_link, $device ) { return $sign_in_link( $device ); } );
	$check( "asking for a sign-in link carries the browser's cart and restarts the clock", (int) $r->id === $link_cart( $link_r ) && ! $runner_would_pick( (int) $r->id ), $link_r );

	$age( (int) $r->id );
	wp_set_current_user( $dev_id );
	[ , $phone_cookie ] = $browser( '', null, function () use ( $use_link, $link_r ) { $use_link( $link_r ); } );
	$restored = $in_session();
	wp_set_current_user( 0 );
	$check(
		'the sign-in link moves the cart to another device with a signed cookie and restarts the clock',
		[ $product . ':0' ] === $restored && 0 === strpos( $phone_cookie, $r->id . '.' ) && ! $runner_would_pick( (int) $r->id ),
		compact( 'restored', 'phone_cookie' )
	);

	// The browser that asked keeps its live cart (here the three-site price), which the saved row never saw.
	$age( (int) $r->id );
	unset( $_COOKIE[ $cookie_name ] );
	edd_empty_cart();
	edd_add_to_cart( $product, [ 'price_id' => 1 ] );
	wp_set_current_user( $dev_id );
	$_COOKIE[ $cookie_name ] = $c1;
	$use_link( $link_r );
	$kept = $in_session();
	wp_set_current_user( 0 );
	$check( 'the browser that asked keeps its live cart, and signing in restarts the clock', [ $product . ':1' ] === $kept && [ 0 ] === $prices( $row( $r ) ) && ! $runner_would_pick( (int) $r->id ), $kept );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'skipped' ], [ 'id' => $r->id ] );
	wp_set_current_user( $dev_id );
	$browser( '', null, function () use ( $use_link, $link_r ) { $use_link( $link_r ); } );
	$held_items = $in_session();
	wp_set_current_user( 0 );
	$check( 'the sign-in link still restores a cart the runner held back', [ $product . ':0' ] === $held_items, $held_items );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'draft' ], [ 'id' => $r->id ] );

	// 6. Back from a reminder, then signed in on another device: same cart and discount code; the purchase recovers it.
	$code        = 'T498' . strtoupper( wp_generate_password( 8, false ) );
	$discount_id = (int) edd_add_discount( [ 'name' => 'T498 fixture', 'code' => $code, 'type' => 'percent', 'amount' => 10, 'status' => 'active' ] );
	$r_now       = $row( $r );
	$r_now->status = 'processing';
	$r_now->cart   = array_merge( (array) $r_now->cart, [ 'recovery_discount' => [ 'code' => $code, 'discount_id' => $discount_id ] ] );
	$r_now->save();
	[ , $laptop_cookie ] = $browser( '', null, function () use ( $no_redirect, $tracker, $r_now ) {
		$no_redirect( function () use ( $tracker, $r_now ) { $tracker->restoreCart( [ 'fc_ab_hash' => $r_now->checkout_key ] ); } );
	} );
	$tracker->syncCart( $device, 'Dee Vice' );
	$link_rec = $sign_in_link( $device );
	$check( "the reminder link's browser gets a signed cookie and asks for a link to the same cart", 0 === strpos( $laptop_cookie, $r->id . '.' ) && (int) $r->id === $link_cart( $link_rec ), $link_rec );
	wp_set_current_user( $dev_id );
	$browser( '', null, function () use ( $use_link, $link_rec ) { $use_link( $link_rec ); } );
	$phone_codes = array_map( 'strtoupper', (array) edd_get_cart_discounts() );
	$phone_items = $in_session();
	$order       = $buy( $device, $dev_id );
	wp_set_current_user( 0 );
	$r_done = $row( $r );
	$check(
		'a sign-in after a reminder keeps the cart and its discount code, and the purchase recovers it',
		[ $product . ':0' ] === $phone_items && in_array( $code, $phone_codes, true ) && $r_done && 'recovered' === $r_done->status && $order === (int) $r_done->order_id,
		[ 'items' => $phone_items, 'codes' => $phone_codes, 'status' => $r_done ? $r_done->status : null ]
	);

	// 7. Back from a reminder under one address, then signed in under another account and bought: both carts close.
	$personal = $email( 'personal' );
	$work     = $email( 'work' );
	$work_id  = $user( $work );
	$reminder_link = function ( $cart ) use ( $browser, $no_redirect, $tracker, $row ) {
		$emailed = $row( $cart );
		return $browser( '', null, function () use ( $no_redirect, $tracker, $emailed ) {
			$no_redirect( function () use ( $tracker, $emailed ) { $tracker->restoreCart( [ 'fc_ab_hash' => $emailed->checkout_key ] ); } );
		} );
	};
	[ $x ] = $browser( '', 0, function () use ( $tracker, $personal ) { return $tracker->syncCart( $personal, 'Pat Two' ); } );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'processing' ], [ 'id' => $x->id ] );
	[ , $x_cookie, $x_handoff ] = $reminder_link( $x );
	$bystander = $make_cart( $email( 'bystander' ), 'processing' );
	wp_set_current_user( $work_id );
	[ $n ] = $browser( [ $x_cookie, $x_handoff ], 0, function () use ( $tracker, $work ) { return $tracker->syncCart( $work, 'Pat Two' ); } );
	$order_w = $buy( $work, $work_id );
	wp_set_current_user( 0 );
	$x_done = $row( $x );
	$check(
		'a purchase under another account also closes the reminder cart the browser left',
		$n && (int) $n->id !== (int) $x->id && (int) $x->id === (int) ( $n->cart['from_cart_id'] ?? 0 ) && $x_done && 'recovered' === $x_done->status && $order_w === (int) $x_done->order_id,
		[ 'new' => $n ? $n->id : null, 'from' => $n ? ( $n->cart['from_cart_id'] ?? null ) : null, 'left' => $x_done ? $x_done->status : null ]
	);
	$check( "control: a reminder cart no browser left stays open", 'processing' === $row( $bystander )->status );

	// A cookie left from an earlier visit, with no reminder link opened: on a shared computer the next
	// shopper's purchase leaves that reminder running.
	$sharer = $email( 'sharer' );
	$next   = $email( 'next-shopper' );
	[ $y, $y_cookie ] = $browser( '', 0, function () use ( $tracker, $sharer ) { return $tracker->syncCart( $sharer, 'Sha Rer' ); } );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'processing' ], [ 'id' => $y->id ] );
	[ $z ] = $browser( $y_cookie, 0, function () use ( $tracker, $next ) { return $tracker->syncCart( $next, 'Nex Tone' ); } );
	$buy( $next, 0 );
	$check(
		"a cookie left from an earlier visit does not let the next shopper's purchase close that reminder",
		$z && 0 === (int) ( $z->cart['from_cart_id'] ?? 0 ) && 'processing' === $row( $y )->status && ! $row( $y )->order_id
	);

	// When the cart bought under the new address was emailed too, it alone counts as recovered.
	$personal2 = $email( 'personal2' );
	$work2     = $email( 'work2' );
	[ $x2 ] = $browser( '', 0, function () use ( $tracker, $personal2 ) { return $tracker->syncCart( $personal2, 'Pat Three' ); } );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'processing' ], [ 'id' => $x2->id ] );
	[ , $x2_cookie, $x2_handoff ] = $reminder_link( $x2 );
	[ $n2, $n2_cookie ] = $browser( [ $x2_cookie, $x2_handoff ], 0, function () use ( $tracker, $work2 ) { return $tracker->syncCart( $work2, 'Pat Three' ); } );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'status' => 'processing' ], [ 'id' => $n2->id ] );
	[ $order_n2 ] = $browser( $n2_cookie, 0, function () use ( $buy, $work2 ) { return $buy( $work2, 0 ); } );
	$n2_done = $row( $n2 );
	$check(
		'one purchase counts as one recovery when both addresses were emailed',
		$n2_done && 'recovered' === $n2_done->status && $order_n2 === (int) $n2_done->order_id && ! $row( $x2 ),
		[ 'bought' => $n2_done ? $n2_done->status : null, 'left' => $row( $x2 ) ? $row( $x2 )->status : 'deleted' ]
	);

	// A renewal reminder closes only when its license is renewed.
	$renewal     = $make_cart( $email( 'renewer' ), 'processing', 'edd_renewal' );
	$renewer_new = $email( 'renewer-new' );
	[ $fresh, $fresh_cookie ] = $browser( [ $cookie_for( $renewal ), $handoff_for( $renewal ) ], 0, function () use ( $tracker, $renewer_new ) { return $tracker->syncCart( $renewer_new, 'Ren Ewer' ); } );
	$browser( $fresh_cookie, 0, function () use ( $buy, $renewer_new ) { return $buy( $renewer_new, 0 ); } );
	$check(
		'a renewal reminder stays open when its browser buys something else under another address',
		$fresh && 0 === (int) ( $fresh->cart['from_cart_id'] ?? 0 ) && 'processing' === $row( $renewal )->status && ! $row( $renewal )->order_id
	);

	// 8. A purchase closes the cart of the browser that made it, even before a new address is saved;
	// someone else's purchase under an address never counts as recovering its owner's cart.
	$shared   = $email( 'shared' );
	$victim   = $make_cart( $shared, 'processing' );
	[ $mine ] = $browser( '', 1, function () use ( $tracker, $shared ) { return $tracker->syncCart( $shared, 'Andy Other' ); } );
	$order_b  = $buy( $shared, 0 );
	$victim_after = $row( $victim );
	$check(
		"another browser's purchase under the address is not credited to the owner's cart",
		(int) $mine->id !== (int) $victim->id && ( ! $victim_after || ( 'recovered' !== $victim_after->status && $order_b !== (int) $victim_after->order_id ) ),
		$victim_after ? [ 'status' => $victim_after->status, 'order' => $victim_after->order_id ] : 'deleted'
	);
	$reminded = $make_cart( $email( 'reminded' ), 'processing' );
	$unsaved  = $email( 'unsaved' );
	[ $order_r ] = $browser( $cookie_for( $reminded ), 1, function () use ( $buy, $unsaved ) { return $buy( $unsaved, 0 ); } );
	$check( 'a purchase made before the new address was saved still closes the reminder cart', 'recovered' === $row( $reminded )->status && $order_r === (int) $row( $reminded )->order_id );
} catch ( Throwable $error ) {
	$check( 'no exception', false, get_class( $error ) . ': ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
} finally {
	unset( $_COOKIE[ $cookie_name ], $_COOKIE[ $handoff_name ] );
	$_GET = [];
	wp_set_current_user( 0 );
	edd_empty_cart();
	null === $allowed ? delete_option( AllowedDomains::OPTION ) : update_option( AllowedDomains::OPTION, $allowed );
	null === $signed_since ? delete_option( $since_option ) : update_option( $since_option, $signed_since );
	foreach ( $wpdb->get_col( "SELECT id FROM {$p}edd_adjustments WHERE type = 'discount' AND code LIKE 'T498%'" ) as $discount ) {
		edd_delete_discount( (int) $discount );
	}
	$emails = array_unique( array_merge(
		$emails,
		$wpdb->get_col( "SELECT email FROM {$p}fc_abandoned_carts WHERE email LIKE 't498-%'" ),
		$wpdb->get_col( "SELECT email FROM {$p}fc_subscribers WHERE email LIKE 't498-%'" ),
		$wpdb->get_col( "SELECT email FROM {$p}edd_customers WHERE email LIKE 't498-%'" )
	) );
	$orders = array_unique( array_merge( $orders, array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}edd_orders WHERE email LIKE 't498-%'" ) ) ) );
	foreach ( $emails as $address ) {
		$sub_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_subscribers WHERE email=%s", $address ) );
		if ( $sub_ids ) {
			$in = implode( ',', array_map( 'intval', $sub_ids ) );
			$wpdb->query( "DELETE FROM {$p}fc_funnel_subscribers WHERE subscriber_id IN ($in)" );
			$wpdb->query( "DELETE FROM {$p}fc_campaign_emails WHERE subscriber_id IN ($in)" );
			\FluentCrm\App\Services\Helper::deleteContacts( $sub_ids );
		}
		$wpdb->delete( "{$p}fc_abandoned_carts", [ 'email' => $address ] );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}edd_customers WHERE email=%s", $address ) ) as $customer ) {
			edd_delete_customer( (int) $customer );
		}
	}
	foreach ( $orders as $order_id ) {
		edd_destroy_order( (int) $order_id );
	}
	// The accounts the checks made, plus the ones EDD made for guest orders.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$users = array_unique( array_merge( array_filter( $users, 'is_int' ), array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE 't498-%'" ) ) ) );
	foreach ( $users as $user_id ) {
		wp_delete_user( $user_id );
	}
	if ( $product ) {
		wp_delete_post( $product, true );
	}
	$left = [
		'carts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_abandoned_carts WHERE email LIKE 't498-%'" ),
		'contacts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email LIKE 't498-%'" ),
		'orders'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_orders WHERE email LIKE 't498-%'" ),
		'customers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_customers WHERE email LIKE 't498-%'" ),
		'users'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE 't498-%'" ),
		'discounts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_adjustments WHERE type = 'discount' AND code LIKE 'T498%'" ),
	];
	$check( 'cleanup left nothing', ! array_filter( $left ), $left );
}

$failed = array_filter( $results, function ( $r ) { return ! $r['pass']; } );
foreach ( $results as $r ) {
	echo ( $r['pass'] ? 'PASS ' : 'FAIL ' ) . $r['check'] . ( $r['pass'] ? '' : '  ' . wp_json_encode( $r['detail'] ) ) . "\n";
}
foreach ( $skips as $skip ) {
	echo "SKIP {$skip}\n";
}
echo count( $results ) - count( $failed ) . '/' . count( $results ) . " passed\n";
echo 'outbound HTTP blocked: ' . wp_json_encode( array_count_values( $blocked_http ) ) . "\n";
