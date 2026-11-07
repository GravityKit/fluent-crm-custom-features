<?php
use CustomCRM\AbandonCart\Edd\EddCartTracking;
use CustomCRM\AbandonCart\Edd\EddCartDriver;
use CustomCRM\AbandonCart\Edd\EddRenewalCartDriver;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
// Run through WP-CLI on a disposable Siteminter site with EDD and this plugin active.
$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$is_local = in_array( $host, [ 'localhost', '127.0.0.1' ], true ) || '.smint.orb.local' === substr( $host, -16 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! $is_local ) {
	throw new RuntimeException( 'Checkout restoration checks require a disposable local WP-CLI site.' );
}
edd_install_component_database_tables();
if ( edd_get_cart_contents() ) {
	throw new RuntimeException( 'Use an empty disposable test cart.' );
}

$assert          = static function ( $value, $message ) {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	} };
$id              = 0;
$discountId      = 0;
$quantitySetting = edd_get_option( 'item_quantities' );
$code            = 'WE494' . strtoupper( wp_generate_password( 10, false ) );
try {
	$id = wp_insert_post( [
		'post_type'   => 'download',
		'post_status' => 'publish',
		'post_title'  => 'WEBSITE494 fixture',
	] );
	update_post_meta( $id, '_variable_pricing', 1 );
	update_post_meta( $id, 'edd_variable_prices', [
		0 => [
			'name'   => 'One site',
			'amount' => 19,
		],
		1 => [
			'name'   => 'Three sites',
			'amount' => 39,
		],
	] );
	edd_update_option( 'item_quantities', 1 );
	$discountId           = edd_add_discount( [
		'name'   => 'WEBSITE494 local fixture',
		'code'   => $code,
		'type'   => 'percent',
		'amount' => 25,
		'status' => 'active',
	] );
	$record               = new AbandonCartModel();
	$record->currency     = 'USD';
	$record->provider     = EddCartDriver::PROVIDER;
	$record->status       = 'draft';
	$record->click_counts = 7;
	$record->cart         = [
		'cart_contents' => [
			[
				'product_id' => $id,
				'price_id'   => 1,
				'quantity'   => 2,
			],
		],
		'coupons'       => [ $code ],
	];
	$tracker              = new EddCartTracking( new EddCartDriver() );
	$restore              = new ReflectionMethod( $tracker, 'restoreRecord' );
	$restore->setAccessible( true );
	$result = $restore->invoke( $tracker, $record, [], false );
	$cart   = edd_get_cart_contents();
	$assert( count( $cart ) === 1 && (int) $cart[0]['id'] === $id, 'Product not restored' );
	$assert( (int) $cart[0]['options']['price_id'] === 1, 'Price variation not restored' );
	$assert( (int) $cart[0]['quantity'] === 2, 'Quantity not restored' );
	$assert( in_array( strtolower( $code ), array_map( 'strtolower', (array) edd_get_cart_discounts() ), true ), 'Original coupon not restored' );
	$assert( $record->click_counts === 7 && $record->status === 'draft', 'Sign-in altered recovery metrics' );
	$assert( $result === [ [],[] ], 'Unexpected restore errors' );
	$flag = new ReflectionProperty( EddCartTracking::class, 'restoring' );
	$flag->setAccessible( true );
	$assert( $flag->getValue() === false, 'Restoration flag leaked' );
	$fail = static function () {
		throw new RuntimeException( 'fixture-native-failure' );
	};
	add_filter( 'edd_add_to_cart_item', $fail, 999 );
	try {
		$restore->invoke( $tracker, $record, [], false );
		throw new RuntimeException( 'Fault injection did not run' ); } catch ( RuntimeException $error ) {
		$assert( $error->getMessage() === 'fixture-native-failure', 'Wrong injected failure' ); } finally {
			remove_filter( 'edd_add_to_cart_item', $fail, 999 ); }
		$assert( $flag->getValue() === false, 'Restoration flag leaked after native failure' );

	// The signed handoff: only the emailed link restores a saved cart, and only for its account.
	$email  = 'we494-' . strtolower( wp_generate_password( 10, false ) ) . '@example.test';
	$userId = wp_insert_user( [
		'user_login' => 'we494-' . strtolower( wp_generate_password( 10, false ) ),
		'user_email' => $email,
		'user_pass'  => wp_generate_password( 24 ),
	] );
	$assert( is_int( $userId ), 'Fixture account not created' );
	$saved = AbandonCartModel::create( [
		'email'    => $email,
		'provider' => EddCartDriver::PROVIDER,
		'status'   => 'draft',
		'currency' => 'USD',
		'cart'     => [ 'cart_contents' => [ [ 'product_id' => $id, 'price_id' => 1, 'quantity' => 1, 'key' => $id . '|1|1|' ] ] ],
	] );
	$sign = new ReflectionMethod( EddCartTracking::class, 'signInSignature' );
	$sign->setAccessible( true );
	// Signed as the link is: from the stored row.
	$signFor = static function ( string $address, int $expires, $row = null ) use ( $sign, $saved ) {
		return $sign->invoke( null, AbandonCartModel::find( ( $row ?: $saved )->id ), $address, $expires );
	};
	wp_set_current_user( $userId );
	// restoreSignInCart() ends in a redirect and exit; the filter stops it first, before WP-CLI's redirect warning.
	// Returns the cart as "product:price" pairs. A cookie makes this the browser that asked for the
	// link, holding a live cart (the one-site price) that differs from the saved row.
	$handoff = static function ( int $expires, string $signature, string $cookie = '', $row = null ) use ( $saved, $id ) {
		$_GET     = [
			'gk_checkout_cart'    => (string) ( $row ?: $saved )->id,
			'gk_checkout_expires' => (string) $expires,
			'gk_checkout_sig'     => $signature,
		];
		$redirect = static function () {
			throw new RuntimeException( 'fixture-redirect' );
		};
		unset( $_COOKIE['fc_ab_edd_cart_token'] );
		edd_empty_cart();
		if ( '' !== $cookie ) {
			$_COOKIE['fc_ab_edd_cart_token'] = $cookie;
			edd_add_to_cart( $id, [ 'price_id' => 0 ] );
		}
		add_filter( 'wp_redirect', $redirect, -1000 );
		try {
			EddCartTracking::restoreSignInCart();
		} catch ( RuntimeException $error ) {
			if ( 'fixture-redirect' !== $error->getMessage() ) {
				throw $error;
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect, -1000 );
			$_GET = [];
			unset( $_COOKIE['fc_ab_edd_cart_token'] );
		}
		return array_map(
			static function ( $item ) {
				return (int) $item['id'] . ':' . (int) ( $item['options']['price_id'] ?? -1 );
			},
			(array) edd_get_cart_contents()
		);
	};
	$future = time() + 600;
	$past   = time() - 60;
	$assert( [] === $handoff( $future, str_repeat( 'a', 64 ) ), 'A made-up signature restored a cart' );
	$assert( [] === $handoff( $past, $signFor( $email, $past ) ), 'An expired link restored a cart' );
	$assert( [] === $handoff( $future, $signFor( 'someone-else@example.test', $future ) ), "Another account's link restored a cart" );
	$valid = $signFor( strtoupper( $email ), $future );
	$assert( [ $id . ':1' ] === $handoff( $future, $valid ), 'The emailed link did not restore the cart' );
	$cookieValue = new ReflectionMethod( EddCartTracking::class, 'cookieValue' );
	$cookieValue->setAccessible( true );
	$assert( [ $id . ':0' ] === $handoff( $future, $valid, $cookieValue->invoke( null, AbandonCartModel::find( $saved->id ) ) ), 'The browser that asked for the link lost its live cart' );
	$assert( [ $id . ':1' ] === $handoff( $future, $valid, 'another-browsers-key' ), 'A browser holding another cart was not given the saved cart' );
	// Anyone can save a cart under the address after the link is sent; the link must not restore it.
	$changed       = AbandonCartModel::find( $saved->id );
	$changed->cart = [ 'cart_contents' => [ [ 'product_id' => $id, 'price_id' => 0, 'quantity' => 1, 'key' => $id . '|0|1|' ] ] ];
	$changed->save();
	$assert( [] === $handoff( $future, $valid ), 'The link restored a cart saved over its row after it was sent' );

	// Anyone can ask for a link to any address, so a sign-in never rebuilds someone else's renewal.
	global $wpdb;
	$license = function_exists( 'edd_sl_add_renewal_to_cart' ) ? $wpdb->get_row( "SELECT id, download_id, price_id, user_id FROM {$wpdb->prefix}edd_licenses WHERE status <> 'disabled' AND user_id > 0 ORDER BY id DESC LIMIT 1" ) : null;
	if ( $license ) {
		$owned = new ReflectionMethod( EddCartTracking::class, 'isOwnLicense' );
		$owned->setAccessible( true );
		wp_set_current_user( (int) $license->user_id );
		$assert( true === $owned->invoke( null, (int) $license->id ), "The license owner's renewal was refused" );
		wp_set_current_user( $userId );
		$assert( false === $owned->invoke( null, (int) $license->id ), "Another account's renewal was accepted" );
		$price      = null === $license->price_id ? '' : (int) $license->price_id;
		$renewalRow = AbandonCartModel::create( [
			'email'    => $email,
			'provider' => EddRenewalCartDriver::PROVIDER,
			'status'   => 'draft',
			'currency' => 'USD',
			'cart'     => [
				'cart_contents' => [
					[ 'product_id' => $id, 'price_id' => 1, 'quantity' => 1, 'key' => $id . '|1|1|' ],
					[ 'product_id' => (int) $license->download_id, 'price_id' => $license->price_id, 'quantity' => 1, 'license_id' => (int) $license->id, 'is_renewal' => true, 'key' => $license->download_id . '|' . $price . '|1|' . $license->id ],
				],
			],
		] );
		$assert( [ $id . ':1' ] === $handoff( $future, $signFor( $email, $future, $renewalRow ), '', $renewalRow ), "A sign-in rebuilt another account's renewal" );
		$renewalNote = 'other-account renewal skipped';
	} else {
		$renewalNote = 'other-account renewal NOT CHECKED (no Software Licensing license on this site)';
	}
} finally {
	edd_empty_cart();
	wp_set_current_user( 0 );
	if ( ! empty( $saved ) ) {
		$saved->delete(); }
	if ( ! empty( $renewalRow ) ) {
		$renewalRow->delete(); }
	if ( ! empty( $userId ) && is_int( $userId ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $userId ); }
	if ( $id ) {
		wp_delete_post( $id, true ); }
	if ( $discountId ) {
		edd_delete_discount( $discountId ); }
	edd_update_option( 'item_quantities', $quantitySetting );
}
echo "PASS native checkout sign-in restoration: product, variation, quantity, coupon, draft, metrics, result and finally flag including native fault; signed handoff refuses forged, expired, other-account and overwritten-cart links, keeps the requesting browser's live cart; {$renewalNote}\n";
