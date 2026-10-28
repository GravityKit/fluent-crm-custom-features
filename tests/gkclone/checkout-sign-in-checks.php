<?php
use CustomCRM\AbandonCart\Edd\EddCartTracking;
use CustomCRM\AbandonCart\Edd\EddCartDriver;
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
} finally {
	edd_empty_cart();
	if ( $id ) {
		wp_delete_post( $id, true ); }
	if ( $discountId ) {
		edd_delete_discount( $discountId ); }
	edd_update_option( 'item_quantities', $quantitySetting );
}
echo "PASS native checkout sign-in restoration: product, variation, quantity, coupon, draft, metrics, result and finally flag including native fault\n";
