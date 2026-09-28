<?php
/**
 * EDD abandoned-cart checks (WEBSITE-402) against a gkclone copy of the store. Creates t402-*
 * fixtures and deletes them; blocks all outbound HTTP while it runs.
 *
 *   docker cp tests/gkclone/abandoned-cart-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/abandoned-cart-checks.php
 */

use CustomCRM\AbandonCart\Edd\EddCartDriver;
use CustomCRM\AbandonCart\Edd\EddCartTracking;
use CustomCRM\AbandonCart\Edd\EddRenewalCartDriver;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

global $wpdb, $results, $run, $emails, $orders;
$p       = $wpdb->prefix;
$results = [];
$run     = substr( md5( microtime() ), 0, 6 );
$emails  = [];
$orders  = [];

function t402_check( string $name, bool $pass, $detail = '' ) {
	global $results;
	$results[] = [ 'check' => $name, 'pass' => $pass, 'detail' => $detail ];
}

function t402_email( string $tag ): string {
	global $run, $emails;
	$e        = "t402-{$tag}-{$run}@gravitykit-t402.dev";
	$emails[] = $e;
	return $e;
}

function t402_product(): array {
	global $wpdb;
	$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='download' AND post_status='publish' AND post_title='GravityImport' LIMIT 1" );
	$prices = (array) edd_get_variable_prices( $id );
	$pid    = $prices ? (int) array_key_first( $prices ) : null;
	return [ $id, $pid ];
}

function t402_order( string $email, int $download_id, ?int $price_id ): int {
	global $orders;
	$opts     = null === $price_id ? [] : [ 'price_id' => $price_id ];
	$order_id = edd_build_order( [
		'price' => 99, 'date' => current_time( 'mysql' ), 'user_email' => $email,
		'purchase_key' => strtolower( md5( uniqid( '', true ) ) ), 'currency' => 'USD',
		'downloads' => [ [ 'id' => $download_id, 'quantity' => 1, 'options' => $opts ] ],
		'cart_details' => [ [ 'name' => 'T402', 'id' => $download_id, 'item_number' => [ 'id' => $download_id, 'quantity' => 1, 'options' => $opts ], 'item_price' => 99, 'quantity' => 1, 'discount' => 0, 'subtotal' => 99, 'tax' => 0, 'fees' => [], 'price' => 99 ] ],
		'user_info' => [ 'id' => 0, 'email' => $email, 'first_name' => 'T', 'last_name' => 'Four', 'discount' => 'none', 'address' => [] ],
		'status' => 'pending', 'gateway' => 'manual', 'mode' => 'test',
	] );
	$orders[] = $order_id;
	edd_update_order_status( $order_id, 'complete' );
	return (int) $order_id;
}

function t402_cart( string $email, string $status, string $provider = 'edd', array $extra = [] ): AbandonCartModel {
	[ $download_id, $price_id ] = t402_product();
	return AbandonCartModel::create( [
		'email' => $email, 'full_name' => 'T Four', 'provider' => $provider, 'status' => $status,
		'subtotal' => 99, 'total' => 99, 'currency' => 'USD',
		'cart' => array_merge( [ 'cart_contents' => [ [ 'key' => "{$download_id}|{$price_id}|1|0", 'is_renewal' => false, 'license_id' => null, 'product_id' => $download_id, 'price_id' => $price_id, 'quantity' => 1, 'title' => 'GravityImport', 'line_total' => 99, 'item_price' => 99 ] ], 'coupons' => [] ], $extra ),
	] );
}

/** Calls restoreCart() and stops at its redirect instead of exiting. */
function t402_restore( AbandonCartModel $cart, string $provider = 'edd' ): void {
	$tracker = new EddCartTracking( EddCartDriver::PROVIDER === $provider ? new EddCartDriver() : new EddRenewalCartDriver() );
	$stop    = function () { throw new RuntimeException( 't402-redirect' ); };
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		$tracker->restoreCart( [ 'fc_ab_hash' => $cart->checkout_key ] );
	} catch ( RuntimeException $e ) {
		if ( 't402-redirect' !== $e->getMessage() ) {
			throw $e;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop, 1 );
	}
}

// No outbound calls: #41 (PDL enrichment) and other automations are published on gkclone.
$blocked_http = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$blocked_http ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
		$blocked_http[] = $host;
		return new WP_Error( 't402_blocked', 'blocked by t402' );
	}
	return $pre;
}, 1, 3 );

try {
	[ $download_id, $price_id ] = t402_product();
	t402_check( 'fixture product found', $download_id > 0, compact( 'download_id', 'price_id' ) );

	// 1. Test and throwaway domains.
	$cases = [
		'agency-test@gkqa.test' => true, 'gke2e-a@test.local' => true, 'a@example.com' => true, 'b@mail.example.org' => true,
		'kuxyl@mailinator.com' => true, 'devtest@yopmail.com' => true, 'x@foo.invalid' => true, 'Y@EXAMPLE.COM' => true,
		'zack@gravitykit.com' => false, 'someone@gmail.com' => false, 'a@testing.com' => false, 'a@notexample.com' => false, 'a@latest.dev' => false,
	];
	$wrong = [];
	foreach ( $cases as $email => $expected ) {
		if ( EddCartTracking::isIgnoredEmail( $email ) !== $expected ) {
			$wrong[] = $email;
		}
	}
	t402_check( 'isIgnoredEmail matches 13 cases', ! $wrong, $wrong );

	$filter = function ( $d ) { $d[] = 'gravitykit-t402.dev'; return $d; };
	add_filter( 'customcrm/edd_ab_cart/ignored_email_domains', $filter );
	t402_check( 'ignored-domain filter extends the list', EddCartTracking::isIgnoredEmail( 'x@gravitykit-t402.dev' ) );
	remove_filter( 'customcrm/edd_ab_cart/ignored_email_domains', $filter );

	// 2. syncCart skips ignored emails, stores real ones (positive control).
	$tracker = new EddCartTracking( new EddCartDriver() );
	edd_empty_cart();
	edd_add_to_cart( $download_id, null === $price_id ? [] : [ 'price_id' => $price_id ] );
	$ignored = $tracker->syncCart( "t402-{$run}@gkqa.test" );
	$ign_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_abandoned_carts WHERE email=%s", "t402-{$run}@gkqa.test" ) );
	t402_check( 'syncCart stores nothing for a .test email', null === $ignored && 0 === $ign_row, compact( 'ign_row' ) );

	$real_email = t402_email( 'sync' );
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	$stored = $tracker->syncCart( $real_email );
	t402_check( 'syncCart stores a real email (control)', $stored instanceof AbandonCartModel, $stored ? $stored->id : null );

	// 3. recovery_discounts survives a later sync of a started cart.
	$stored->status = 'processing';
	$stored->cart   = array_merge( $stored->cart, [ 'recovery_discount' => [ 'code' => 'CART-T402-A' ], 'recovery_discounts' => [ 'bfcm' => [ 'code' => 'CART-T402-B' ] ] ] );
	$stored->save();
	$_COOKIE['fc_ab_edd_cart_token'] = $stored->checkout_key;
	$tracker->syncCart( $real_email );
	$after = AbandonCartModel::find( $stored->id );
	t402_check(
		'recovery_discounts kept after sync',
		'CART-T402-B' === ( $after->cart['recovery_discounts']['bfcm']['code'] ?? null ) && 'CART-T402-A' === ( $after->cart['recovery_discount']['code'] ?? null ),
		$after->cart['recovery_discounts'] ?? null
	);

	// 4. A restore does not re-sync the cart item by item. Control: a normal add-to-cart does.
	$after->cart_hash = 'sentinel';
	$after->save();
	$_COOKIE['fc_ab_edd_cart_token'] = $after->checkout_key;
	edd_empty_cart();
	edd_add_to_cart( $download_id, null === $price_id ? [] : [ 'price_id' => $price_id ] );
	$control_hash = AbandonCartModel::find( $after->id )->cart_hash;
	t402_check( 'control: add-to-cart re-syncs a known cart', 'sentinel' !== $control_hash, $control_hash );

	$after = AbandonCartModel::find( $after->id );
	$after->cart_hash = 'sentinel';
	$after->save();
	t402_restore( $after );
	$restored = AbandonCartModel::find( $after->id );
	t402_check( 'restore does not re-sync the cart', 'sentinel' === $restored->cart_hash && 1 === (int) $restored->click_counts, [ 'hash' => $restored->cart_hash, 'clicks' => $restored->click_counts ] );
	t402_check( 'restore rebuilt the EDD cart', 1 === count( (array) edd_get_cart_contents() ) );

	// 5. A renewal that can no longer be added falls back to a new purchase and leaves a note.
	$bad_email = t402_email( 'badrenewal' );
	$bad       = t402_cart( $bad_email, 'processing', 'edd_renewal' );
	$contents  = $bad->cart;
	$contents['cart_contents'][0]['license_id'] = 999999999;
	$contents['cart_contents'][0]['is_renewal'] = true;
	$bad->cart = $contents;
	$bad->save();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	t402_restore( $bad, 'edd_renewal' );
	$bad   = AbandonCartModel::find( $bad->id );
	$items = (array) edd_get_cart_contents();
	t402_check(
		'failed renewal added as a new purchase with a note',
		1 === count( $items ) && (int) $items[0]['id'] === $download_id && empty( $items[0]['options']['is_renewal'] ) && false !== strpos( (string) $bad->note, '999999999: missing_license' ),
		[ 'note' => $bad->note, 'items' => $items ]
	);
	edd_empty_cart();

	// 6. latestRenewalOrder finds EDD Recurring's automatic renewal payments.
	$license_id = (int) $wpdb->get_var( "SELECT m.edd_license_id FROM {$p}edd_licensemeta m JOIN {$p}edd_orders o ON o.id=m.meta_value WHERE m.meta_key='_edd_sl_payment_id' AND o.status='edd_subscription' ORDER BY o.id DESC LIMIT 1" );
	$sub_order  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(o.id) FROM {$p}edd_licensemeta m JOIN {$p}edd_orders o ON o.id=m.meta_value WHERE m.meta_key='_edd_sl_payment_id' AND m.edd_license_id=%d AND o.status='edd_subscription'", $license_id ) );
	$method     = new ReflectionMethod( EddCartTracking::class, 'latestRenewalOrder' );
	$method->setAccessible( true );
	$latest = $method->invoke( $tracker, [ $license_id ] );
	t402_check( 'latestRenewalOrder returns the edd_subscription order', $latest && (int) $latest->id === $sub_order, [ 'license' => $license_id, 'expected' => $sub_order, 'got' => $latest ? $latest->id : null ] );

	// 7. An upgrade closes the renewal cart for that license. Control: a cart for another license stays open.
	$lic_rows   = $wpdb->get_results( "SELECT id, expiration FROM {$p}edd_licenses WHERE status IN ('active','expired') AND expiration > 0 ORDER BY id DESC LIMIT 2" );
	$renew_cart = function ( object $lic, string $tag ) use ( $download_id ) {
		$c   = t402_cart( t402_email( $tag ), 'processing', 'edd_renewal' );
		$cc  = $c->cart;
		$cc['cart_contents'][0] = array_merge( $cc['cart_contents'][0], [ 'license_id' => (int) $lic->id, 'is_renewal' => true, 'license_expiration' => (int) $lic->expiration ] );
		$c->cart = $cc;
		$c->save();
		return $c;
	};
	$up_cart    = $renew_cart( $lic_rows[0], 'upgrade' );
	$other_cart = $renew_cart( $lic_rows[1], 'other' );
	$up_order   = t402_order( t402_email( 'upgradeorder' ), $download_id, $price_id );
	// EDD Recurring cancels the old subscription on this hook; keep it off real gkclone subscriptions.
	global $wp_filter;
	$detached = [];
	foreach ( $wp_filter['edd_sl_license_upgraded']->callbacks ?? [] as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( is_array( $cb['function'] ) && 'cancel_subscription_on_upgrade' === $cb['function'][1] ) {
				remove_action( 'edd_sl_license_upgraded', $cb['function'], $priority );
				$detached[] = [ $cb['function'], $priority, $cb['accepted_args'] ];
			}
		}
	}
	t402_check( 'EDD Recurring upgrade listener detached', 1 === count( $detached ), count( $detached ) );
	do_action( 'edd_sl_license_upgraded', (int) $lic_rows[0]->id, [ 'payment_id' => $up_order, 'old_payment_id' => 0, 'old_download_id' => 0, 'old_price_id' => 0 ] );
	foreach ( $detached as [ $fn, $priority, $args ] ) {
		add_action( 'edd_sl_license_upgraded', $fn, $priority, $args );
	}
	$tracker->closeRenewedCarts();
	$up_cart    = AbandonCartModel::find( $up_cart->id );
	$other_cart = AbandonCartModel::find( $other_cart->id );
	t402_check( 'upgraded license closes its renewal cart', 'recovered' === $up_cart->status && 'Upgraded instead of renewed' === $up_cart->note && (int) $up_cart->order_id === $up_order, [ 'status' => $up_cart->status, 'note' => $up_cart->note, 'order' => $up_cart->order_id ] );
	t402_check( 'control: other license cart stays open', 'processing' === $other_cart->status, $other_cart->status );

	// 8. sentRecently ignores cancelled runs. Control: a completed run still counts.
	$contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'resend' ), 'status' => 'transactional' ] );
	$funnel  = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_funnels WHERE trigger_name='fc_ab_cart_simulation_edd' ORDER BY id LIMIT 1" );
	$fs      = FunnelSubscriber::create( [ 'funnel_id' => $funnel, 'subscriber_id' => $contact->id, 'status' => 'cancelled', 'starting_sequence_id' => 0, 'created_at' => current_time( 'mysql' ) ] );
	$probe   = t402_cart( $contact->email, 'draft' );
	$sent    = new ReflectionMethod( EddCartDriver::class, 'sentRecently' );
	$sent->setAccessible( true );
	$when_cancelled = $sent->invoke( new EddCartDriver(), $probe );
	$fs->status = 'completed';
	$fs->save();
	$when_completed = $sent->invoke( new EddCartDriver(), $probe );
	t402_check( 'sentRecently: cancelled run does not count', false === $when_cancelled );
	t402_check( 'control: completed run counts', true === $when_completed );
	$fs->delete();

	// 9. A purchase does not mark an old lost cart recovered. Control: an open processing cart is.
	$lost_email = t402_email( 'lost' );
	$lost       = t402_cart( $lost_email, 'lost' );
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	t402_order( $lost_email, $download_id, $price_id );
	$lost = AbandonCartModel::find( $lost->id );
	t402_check( 'purchase leaves a lost cart lost', 'lost' === $lost->status && ! $lost->order_id, [ 'status' => $lost->status, 'order' => $lost->order_id ] );

	$open_email = t402_email( 'open' );
	$open       = t402_cart( $open_email, 'processing' );
	t402_order( $open_email, $download_id, $price_id );
	$open = AbandonCartModel::find( $open->id );
	t402_check( 'control: purchase recovers a processing cart', 'recovered' === $open->status, $open->status );

	// 10. Indexes on email and user_id, added once.
	delete_option( 'customcrm_ab_cart_indexes' );
	foreach ( [ 'customcrm_email', 'customcrm_user_id' ] as $key ) {
		if ( $wpdb->get_var( "SHOW INDEX FROM {$p}fc_abandoned_carts WHERE Key_name='{$key}'" ) ) {
			$wpdb->query( "ALTER TABLE {$p}fc_abandoned_carts DROP INDEX {$key}" );
		}
	}
	EddCartTracking::ensureIndexes();
	EddCartTracking::ensureIndexes();
	$keys = $wpdb->get_col( "SHOW INDEX FROM {$p}fc_abandoned_carts", 2 );
	t402_check( 'email and user_id indexes exist, option set', in_array( 'customcrm_email', $keys, true ) && in_array( 'customcrm_user_id', $keys, true ) && 1 === (int) get_option( 'customcrm_ab_cart_indexes' ), array_values( array_unique( $keys ) ) );
	$explain = $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT * FROM {$p}fc_abandoned_carts WHERE email=%s", 'x@y.z' ), ARRAY_A );
	t402_check( 'email lookup uses the index', 'customcrm_email' === ( $explain['key'] ?? '' ) || 'customcrm_email' === ( $explain['possible_keys'] ?? '' ), $explain );
} catch ( Throwable $e ) {
	t402_check( 'no exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	edd_empty_cart();
	// Sweep by marker, so a crashed earlier run is cleaned up too.
	$emails = array_unique( array_merge(
		$emails,
		$wpdb->get_col( "SELECT email FROM {$p}fc_abandoned_carts WHERE email LIKE 't402-%'" ),
		$wpdb->get_col( "SELECT email FROM {$p}fc_subscribers WHERE email LIKE 't402-%'" ),
		$wpdb->get_col( "SELECT email FROM {$p}edd_customers WHERE email LIKE 't402-%'" )
	) );
	$orders = array_unique( array_merge( $orders, array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}edd_orders WHERE email LIKE 't402-%'" ) ) ) );
	foreach ( $emails as $email ) {
		$sub_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_subscribers WHERE email=%s", $email ) );
		if ( $sub_ids ) {
			$in = implode( ',', array_map( 'intval', $sub_ids ) );
			$wpdb->query( "DELETE FROM {$p}fc_funnel_subscribers WHERE subscriber_id IN ($in)" );
			\FluentCrm\App\Services\Helper::deleteContacts( $sub_ids );
		}
		$wpdb->delete( "{$p}fc_abandoned_carts", [ 'email' => $email ] );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}edd_customers WHERE email=%s", $email ) ) as $cid ) {
			edd_delete_customer( (int) $cid );
		}
	}
	foreach ( $orders as $order_id ) {
		edd_destroy_order( (int) $order_id );
	}
	$left = [
		'carts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_abandoned_carts WHERE email LIKE 't402-%'" ),
		'contacts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email LIKE 't402-%'" ),
		'orders'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_orders WHERE email LIKE 't402-%'" ),
	];
	t402_check( 'cleanup left nothing', ! array_filter( $left ), $left );
}

$failed = array_filter( $results, function ( $r ) { return ! $r['pass']; } );
foreach ( $results as $r ) {
	echo ( $r['pass'] ? 'PASS ' : 'FAIL ' ) . $r['check'] . ( $r['pass'] ? '' : '  ' . wp_json_encode( $r['detail'] ) ) . "\n";
}
echo count( $results ) - count( $failed ) . '/' . count( $results ) . " passed\n";
echo 'outbound HTTP blocked: ' . wp_json_encode( array_count_values( $blocked_http ) ) . "\n";
