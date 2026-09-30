<?php
/**
 * EDD abandoned-cart checks (WEBSITE-402, license upgrades WEBSITE-425) against a gkclone copy of
 * the store. Creates t402-* fixtures and deletes them; blocks all outbound HTTP while it runs.
 *
 *   docker cp tests/gkclone/abandoned-cart-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/abandoned-cart-checks.php
 */

use CustomCRM\AbandonCart\Edd\EddCartDriver;
use CustomCRM\AbandonCart\Edd\EddCartTracking;
use CustomCRM\AbandonCart\Edd\EddRenewalCartDriver;
use CustomCRM\AbandonCart\Edd\EddUpgradeCartDriver;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

global $wpdb, $results, $run, $emails, $orders, $licenses;
$p        = $wpdb->prefix;
$results  = [];
$run      = substr( md5( microtime() ), 0, 6 );
$emails   = [];
$orders   = [];
$licenses = [];
$ab_backup_set = false;
$ab_backup     = null;

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

function t402_order( string $email, int $download_id, ?int $price_id, ?string $date = null, array $extra_options = [] ): int {
	global $orders;
	$opts     = array_merge( null === $price_id ? [] : [ 'price_id' => $price_id ], $extra_options );
	$order_id = edd_build_order( [
		'price' => 99, 'date' => $date ?: current_time( 'mysql' ), 'user_email' => $email,
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

/** Completes an order for whatever is in the EDD cart, as checkout would, and returns its ID. */
function t402_order_from_cart( string $email ): int {
	global $orders;
	$order_id = edd_build_order( [
		'price' => (float) edd_get_cart_total(), 'date' => current_time( 'mysql' ), 'user_email' => $email,
		'purchase_key' => strtolower( md5( uniqid( '', true ) ) ), 'currency' => edd_get_currency(),
		'downloads' => (array) edd_get_cart_contents(), 'cart_details' => (array) edd_get_cart_content_details(),
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
function t402_restore( AbandonCartModel $cart, string $provider = 'edd', array $link = [] ): void {
	$drivers = [ 'edd' => EddCartDriver::class, 'edd_renewal' => EddRenewalCartDriver::class, 'edd_upgrade' => EddUpgradeCartDriver::class ];
	$tracker = new EddCartTracking( new $drivers[ $provider ]() );
	$stop    = function () { throw new RuntimeException( 't402-redirect' ); };
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		$tracker->restoreCart( array_merge( [ 'fc_ab_hash' => $cart->checkout_key ], $link ) );
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

	// 3b. Typing someone else's email never hands over their cart once it is being emailed.
	$victim_email = t402_email( 'victim' );
	$victim       = t402_cart( $victim_email, 'processing' );
	$victim_key   = $victim->checkout_key;
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	edd_empty_cart();
	edd_add_to_cart( $download_id, null === $price_id ? [] : [ 'price_id' => $price_id ] );
	$other  = $tracker->syncCart( $victim_email );
	$victim = AbandonCartModel::find( $victim->id );
	t402_check(
		'email match does not take over a cart being emailed',
		$other && (int) $other->id !== (int) $victim->id && ( $_COOKIE['fc_ab_edd_cart_token'] ?? '' ) !== $victim_key && 'processing' === $victim->status,
		[ 'new' => $other ? $other->id : null, 'victim' => $victim->id ]
	);
	$draft_again = $tracker->syncCart( $victim_email );
	t402_check( 'control: same email still updates its own draft', $draft_again && (int) $draft_again->id === (int) $other->id, [ $draft_again ? $draft_again->id : null, $other->id ] );

	// 3c. An error inside a cart hook never breaks add-to-cart.
	$_COOKIE['fc_ab_edd_cart_token'] = $other->checkout_key;
	$boom = function () { throw new RuntimeException( 't402 boom' ); };
	add_filter( 'customcrm/edd_ab_cart/ignored_email_domains', $boom );
	$threw = null;
	try {
		edd_empty_cart();
		edd_add_to_cart( $download_id, null === $price_id ? [] : [ 'price_id' => $price_id ] );
	} catch ( Throwable $e ) {
		$threw = $e->getMessage();
	}
	remove_filter( 'customcrm/edd_ab_cart/ignored_email_domains', $boom );
	t402_check( 'a failing cart hook does not break add-to-cart', null === $threw && 1 === count( (array) edd_get_cart_contents() ), $threw );
	unset( $_COOKIE['fc_ab_edd_cart_token'] );

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

	// 7b. Renewing a license recovers its recently lost renewal cart, not one lost long ago. The renewal is
	// recorded straight on the tracker, so no other plugin's renewal listeners run against gkclone data.
	$more_lics = $wpdb->get_results( "SELECT id, expiration FROM {$p}edd_licenses WHERE status IN ('active','expired') AND expiration > 0 ORDER BY id DESC LIMIT 2 OFFSET 2" );
	$lost_cart = function ( object $lic, string $tag, int $days_ago ) {
		$c  = t402_cart( t402_email( $tag ), 'lost', 'edd_renewal' );
		$cc = $c->cart;
		// Saved before the renewal, so the license's current expiration is later: it was renewed.
		$cc['cart_contents'][0] = array_merge( $cc['cart_contents'][0], [ 'license_id' => (int) $lic->id, 'is_renewal' => true, 'license_expiration' => (int) $lic->expiration - DAY_IN_SECONDS ] );
		$c->cart = $cc;
		$c->save();
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}fc_abandoned_carts", [ 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days_ago * DAY_IN_SECONDS ) ], [ 'id' => $c->id ] );
		return $c;
	};
	$recent_lost = $lost_cart( $more_lics[0], 'lostrecent', 15 );
	$old_lost    = $lost_cart( $more_lics[1], 'lostold', 60 );
	$renewed     = new ReflectionProperty( EddCartTracking::class, 'renewed_licenses' );
	$renewed->setAccessible( true );
	$renewed->setValue( null, [ (int) $more_lics[0]->id, (int) $more_lics[1]->id ] );
	$tracker->closeRenewedCarts();
	$renewed->setValue( null, [] );
	$recent_lost = AbandonCartModel::find( $recent_lost->id );
	$old_lost    = AbandonCartModel::find( $old_lost->id );
	t402_check( 'renewal recovers a renewal cart lost 5 days ago', 'recovered' === $recent_lost->status, $recent_lost->status );
	t402_check( 'renewal leaves a renewal cart lost 50 days ago alone', 'lost' === $old_lost->status, $old_lost->status );

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

	// 11. Collision rules. Emails are never really sent: FluentCRM's mailer is simulated for this run.
	add_filter( 'fluent_crm/is_simulated_mail', '__return_true', 1 );
	$gate_contact  = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'gate' ), 'status' => 'subscribed' ] );
	$other_contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'gatecontrol' ), 'status' => 'subscribed' ] );
	$cart_funnel   = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_funnels WHERE trigger_name='fc_ab_cart_simulation_edd' ORDER BY id LIMIT 1" );
	$cart_camps    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_campaigns WHERE type='funnel_email_campaign' AND parent_id=%d ORDER BY id LIMIT 2", $cart_funnel ) ) );
	$cart_camp     = $cart_camps[0] ?? 0;
	$cart_camp_2   = $cart_camps[1] ?? 0;
	$onb_camp      = (int) $wpdb->get_var( "SELECT c.id FROM {$p}fc_campaigns c JOIN {$p}fc_funnels f ON f.id=c.parent_id WHERE c.type='funnel_email_campaign' AND f.title LIKE 'Onboarding:%' LIMIT 1" );
	$news_camp     = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_campaigns WHERE type='campaign' ORDER BY id DESC LIMIT 1" );
	t402_check( 'gate fixtures found', $cart_camp && $cart_camp_2 && $onb_camp && $news_camp, compact( 'cart_camp', 'cart_camp_2', 'onb_camp', 'news_camp' ) );

	$now      = current_time( 'mysql' );
	$add_mail = function ( int $contact, int $campaign, string $status, string $at ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}fc_campaign_emails", [ 'campaign_id' => $campaign, 'subscriber_id' => $contact, 'email_address' => 't402@invalid', 'status' => $status, 'scheduled_at' => $at, 'email_type' => 'campaign', 'created_at' => $at, 'updated_at' => $at ] );
		return (int) $wpdb->insert_id;
	};
	$mail_status = function ( int $id ) use ( $wpdb, $p ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT status, scheduled_at FROM {$p}fc_campaign_emails WHERE id=%d", $id ) );
	};

	$wpdb->insert( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $cart_funnel, 'subscriber_id' => $gate_contact->id, 'status' => 'active', 'starting_sequence_id' => 0, 'created_at' => $now, 'updated_at' => $now ] );
	$news_in   = $add_mail( (int) $gate_contact->id, $news_camp, 'pending', $now );
	$news_out  = $add_mail( (int) $other_contact->id, $news_camp, 'pending', $now );
	\CustomCRM\Email\CollisionGate::beforeBatchSend( false );
	t402_check( 'newsletter cancelled for a contact in a cart automation', 'cancelled' === $mail_status( $news_in )->status, $mail_status( $news_in ) );
	t402_check( 'control: newsletter kept for a contact not in one', 'pending' === $mail_status( $news_out )->status, $mail_status( $news_out ) );

	$an_hour_ago = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - HOUR_IN_SECONDS );
	$cart_mail   = $add_mail( (int) $gate_contact->id, $cart_camp, 'sent', $an_hour_ago );
	$onb_held    = $add_mail( (int) $gate_contact->id, $onb_camp, 'scheduled', $now );
	$onb_free    = $add_mail( (int) $other_contact->id, $onb_camp, 'scheduled', $now );
	$cart_due    = $add_mail( (int) $gate_contact->id, $cart_camp_2, 'scheduled', $now );
	$wpdb->query( "UPDATE {$p}fc_campaign_emails SET email_type='funnel_email_campaign' WHERE id IN ($cart_mail,$onb_held,$onb_free,$cart_due)" );
	\CustomCRM\Email\CollisionGate::beforeContactSend( $gate_contact );
	\CustomCRM\Email\CollisionGate::beforeContactSend( $other_contact );
	// Held from the latest cart email: the one due now, not the one sent an hour ago.
	$expected = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + DAY_IN_SECONDS );
	t402_check( 'onboarding email held until 24h after the latest cart email', $expected === $mail_status( $onb_held )->scheduled_at, [ 'got' => $mail_status( $onb_held )->scheduled_at, 'expected' => $expected ] );
	t402_check( 'control: onboarding email for a contact with no cart email is not held', $now === $mail_status( $onb_free )->scheduled_at, $mail_status( $onb_free ) );
	t402_check( 'a cart email is never held', $now === $mail_status( $cart_due )->scheduled_at, $mail_status( $cart_due ) );

	// Through FluentCRM's own per-contact send path: the held email must not go out.
	$wpdb->update( "{$p}fc_campaign_emails", [ 'status' => 'cancelled' ], [ 'id' => $cart_due ] );
	// One row per campaign per contact: reuse the newsletter row, due again.
	$wpdb->update( "{$p}fc_campaign_emails", [ 'status' => 'pending', 'scheduled_at' => $now ], [ 'id' => $news_in ] );
	$news_again = $news_in;
	// FluentCRM sends for one contact per request (it guards on `fluent_crm/sending_emails_starting`), so the control goes first.
	do_action( 'fluentcrm_process_contact_jobs', $other_contact );
	t402_check( 'control: send path sends the unheld onboarding email', 'sent' === $mail_status( $onb_free )->status, $mail_status( $onb_free ) );
	do_action( 'fluentcrm_process_contact_jobs', $gate_contact );
	t402_check( 'send path: held onboarding email stays scheduled', 'scheduled' === $mail_status( $onb_held )->status, $mail_status( $onb_held ) );
	t402_check( 'send path: newsletter cancelled, not sent', 'cancelled' === $mail_status( $news_again )->status, $mail_status( $news_again ) );
	$gate_boom  = function () { throw new RuntimeException( 't402 gate boom' ); };
	add_filter( 'customcrm/email_gate/is_priority_automation', $gate_boom );
	$gate_threw = null;
	try {
		\CustomCRM\Email\CollisionGate::beforeContactSend( $gate_contact );
	} catch ( Throwable $e ) {
		$gate_threw = $e->getMessage();
	}
	remove_filter( 'customcrm/email_gate/is_priority_automation', $gate_boom );
	t402_check( 'a failing send rule does not stop sending', null === $gate_threw, $gate_threw );
	remove_filter( 'fluent_crm/is_simulated_mail', '__return_true', 1 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}fc_campaign_emails WHERE subscriber_id IN (%d, %d)", $gate_contact->id, $other_contact->id ) );

	// 12. Shoppers Recapture emailed count toward the resend cap.
	$csv = tempnam( sys_get_temp_dir(), 't402' );
	$recent_email = t402_email( 'recapture-recent' );
	$old_email    = t402_email( 'recapture-old' );
	file_put_contents( $csv, "email,last_sent_at\n" . strtoupper( $recent_email ) . ',' . gmdate( 'Y-m-d\TH:i:s\Z', time() - 5 * DAY_IN_SECONDS ) . "\n{$old_email}," . ( time() - 30 * DAY_IN_SECONDS ) . "\nnot-an-email,2026-01-01\n" );
	$prior_backup = get_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION, null );
	$stored       = \CustomCRM\AbandonCart\Edd\PriorRecipients::import( $csv );
	unlink( $csv );
	$sent_check   = new ReflectionMethod( EddCartDriver::class, 'sentRecently' );
	$sent_check->setAccessible( true );
	$recent_cart  = t402_cart( $recent_email, 'draft' );
	$old_cart     = t402_cart( $old_email, 'draft' );
	t402_check( 'prior recipients: 2 valid rows stored', 2 === $stored, $stored );
	t402_check( 'emailed by Recapture 5 days ago: capped', true === $sent_check->invoke( new EddCartDriver(), $recent_cart ) );
	t402_check( 'control: emailed 30 days ago: not capped', false === $sent_check->invoke( new EddCartDriver(), $old_cart ) );
	if ( null === $prior_backup ) {
		delete_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION );
	} else {
		update_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION, $prior_backup, false );
	}

	// 12b. Cart Recovery page: renders for an admin, saves edits, keeps the default profile.
	$discount_backup = get_option( \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount::OPTION, null );
	$admin_id        = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );
	$previous_user   = get_current_user_id();
	wp_set_current_user( $admin_id );
	$page = new \CustomCRM\AbandonCart\Edd\DiscountSettingsPage();
	ob_start();
	$page->renderPage();
	$html = (string) ob_get_clean();
	t402_check( 'Cart Recovery page lists the profiles', false !== strpos( $html, 'name="profiles[pct40_3d][amount]"' ) && false !== strpos( $html, 'name="profiles[__new][slug]"' ) && false === strpos( $html, 'name="profiles[default][delete]"' ), strlen( $html ) );

	$_POST    = [
		'_wpnonce'         => wp_create_nonce( 'customcrm_cart_discounts_save' ),
		'_wp_http_referer' => '/wp-admin/admin.php?page=fluentcrm-cart-discounts',
		'allowed_domains'  => "gravitykit.com
katz.co",
		'profiles'         => [
			'default'  => [ 'label' => 'Default', 'type' => 'percent', 'amount' => '35', 'expiry_hours' => '48', 'min_amount' => '0', 'prefix' => 'cart', 'delete' => '1' ],
			'pct40_3d' => [ 'label' => '40% off, 3 days', 'type' => 'percent', 'amount' => '40', 'expiry_hours' => '72', 'min_amount' => '1', 'prefix' => 'CART' ],
			'pct20'    => [ 'label' => '20% off', 'type' => 'percent', 'amount' => '20', 'expiry_hours' => '0', 'min_amount' => '0', 'prefix' => 'CART', 'delete' => '1' ],
			'__new'    => [ 'slug' => 'Flat 15', 'label' => '$15 off', 'type' => 'flat', 'amount' => '15', 'expiry_hours' => '24', 'min_amount' => '0', 'prefix' => 'save!' ],
		],
	];
	$_REQUEST = $_POST;
	$stop     = function () { throw new RuntimeException( 't402-redirect' ); };
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		$page->handleSave();
	} catch ( RuntimeException $e ) {
		if ( 't402-redirect' !== $e->getMessage() ) {
			throw $e;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop, 1 );
		$_POST    = [];
		$_REQUEST = [];
		wp_set_current_user( $previous_user );
	}
	// The page's own permission check, without the site's wp-admin redirect in front of it.
	$subscriber_id = wp_insert_user( [ 'user_login' => 't402-subscriber', 'user_email' => t402_email( 'subscriber' ), 'user_pass' => wp_generate_uuid4(), 'role' => 'subscriber' ] );
	wp_set_current_user( (int) $subscriber_id );
	$_POST    = [ '_wpnonce' => wp_create_nonce( 'customcrm_cart_discounts_save' ), 'profiles' => [ 'default' => [ 'amount' => '1' ] ] ];
	$_REQUEST = $_POST;
	$died     = null;
	$die_handler = function () {
		return function ( $message, $title, $args ) {
			throw new RuntimeException( 'wp_die:' . ( $args['response'] ?? '' ) );
		};
	};
	add_filter( 'wp_die_handler', $die_handler );
	try {
		$page->handleSave();
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $die_handler );
		$_POST    = [];
		$_REQUEST = [];
		wp_set_current_user( $previous_user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $subscriber_id );
	}
	t402_check( 'subscriber with a valid nonce is refused with 403', 'wp_die:403' === $died, $died );

	$saved = ( new \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount() )->getProfiles();
	t402_check(
		'saving: default kept and edited, pct20 deleted, new profile added',
		35.0 === $saved['default']['amount'] && 'CART' === $saved['default']['prefix'] && ! isset( $saved['pct20'] ) && isset( $saved['flat15'] ) && 'SAVE' === $saved['flat15']['prefix'] && 'flat' === $saved['flat15']['type'],
		array_keys( $saved )
	);
	t402_check( 'page saves the allowed domains', [ 'gravitykit.com', 'katz.co' ] === \CustomCRM\AbandonCart\Edd\AllowedDomains::get(), \CustomCRM\AbandonCart\Edd\AllowedDomains::get() );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( '' );
	t402_check( 'amount labels follow the saved profiles', '35%' === ( new \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount() )->getAmountLabel() && '$15.00' === ( new \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount() )->getAmountLabel( 'flat15' ) );
	if ( null === $discount_backup ) {
		delete_option( \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount::OPTION );
	} else {
		update_option( \CustomCRM\AbandonCart\Edd\EddRecoveryDiscount::OPTION, $discount_backup, false );
	}

	// 12c. Internal-only mode: only listed email domains reach an automation.
	$domains_backup = get_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, null );
	$stored_domains = \CustomCRM\AbandonCart\Edd\AllowedDomains::save( "  @GravityKit.com, katz.co\n not_a_domain  gravitykit-t402.io " );
	t402_check( 'domains parsed and cleaned', [ 'gravitykit.com', 'katz.co', 'gravitykit-t402.io' ] === $stored_domains, $stored_domains );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( 'gravitykit.com, katz.co' );
	$domain_cases = [ 'zack@katz.co' => true, 'Casey@GravityKit.com' => true, 'a@mail.gravitykit.com' => true, 'x@notkatz.co' => false, 'x@katz.co.evil.com' => false, 'x@gmail.com' => false, 'no-at-sign' => false ];
	$domain_wrong = [];
	foreach ( $domain_cases as $address => $expected ) {
		if ( \CustomCRM\AbandonCart\Edd\AllowedDomains::allows( $address ) !== $expected ) {
			$domain_wrong[] = $address;
		}
	}
	t402_check( 'allowed-domain matching (7 cases)', ! $domain_wrong, $domain_wrong );

	$outside = t402_cart( t402_email( 'outside' ), 'draft' );
	( new \FluentCrm\App\Modules\AbandonCart\AbandonCartRunner() )->runAbandonCart( AbandonCartModel::find( $outside->id ) );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::writeNotes();
	$outside       = AbandonCartModel::find( $outside->id );
	$outside_made  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email = %s", $outside->email ) );
	t402_check(
		'outside domain: skipped with the reason, no contact created',
		'skipped' === $outside->status && 0 === $outside_made && 0 === strpos( (string) $outside->note, 'Held back' ) && ! empty( $outside->cart['held_back'] ),
		[ 'status' => $outside->status, 'note' => $outside->note, 'contacts' => $outside_made ]
	);

	$inside_email = 't402-inside-' . $run . '@katz.co';
	$emails[]     = $inside_email;
	$inside       = t402_cart( $inside_email, 'draft' );
	( new \FluentCrm\App\Modules\AbandonCart\AbandonCartRunner() )->runAbandonCart( AbandonCartModel::find( $inside->id ) );
	$inside = AbandonCartModel::find( $inside->id );
	t402_check( 'control: allowed domain starts the automation', 'processing' === $inside->status && $inside->contact_id, [ 'status' => $inside->status, 'note' => $inside->note ] );

	$renewal_outside = t402_cart( t402_email( 'renewoutside' ), 'draft', 'edd_renewal' );
	t402_check( 'renewal carts are held back too', true === ( new EddRenewalCartDriver() )->isWithinCoolOffPeriod( $renewal_outside ) );

	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( '' );
	t402_check( 'empty list: every domain allowed', \CustomCRM\AbandonCart\Edd\AllowedDomains::allows( 'x@gmail.com' ) );
	if ( null === $domains_backup ) {
		delete_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION );
	} else {
		update_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, $domains_backup, true );
	}

	// 12d. Send-time guard: a non-team contact put straight into the cart automation (as an admin
	// could by hand, skipping the drivers) never gets the email. Control: a team contact does.
	$guard_backup = get_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, null );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( 'gravitykit.com, katz.co' );
	$guard_sent = [];
	$capture    = function ( $simulated, $data ) use ( &$guard_sent ) {
		$guard_sent[] = $data['to']['email'] ?? '';
		return true;
	};
	add_filter( 'fluent_crm/is_simulated_mail', $capture, 1, 2 );
	$cart_funnel_model = \FluentCrm\App\Models\Funnel::where( 'trigger_name', 'fc_ab_cart_simulation_edd' )->where( 'status', 'published' )->orderBy( 'id', 'DESC' )->first();
	$walk = function ( string $address ) use ( $wpdb, $p, $cart_funnel_model ) {
		$contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $address, 'status' => 'subscribed' ] );
		( new \FluentCrm\App\Services\Funnel\FunnelProcessor() )->startFunnelSequence( $cart_funnel_model, [], [], $contact );
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}fc_funnel_subscribers SET next_execution_time = %s WHERE subscriber_id = %d AND funnel_id = %d AND status = 'active'", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 60 ), $contact->id, $cart_funnel_model->id ) );
			( new \FluentCrm\App\Services\Funnel\FunnelProcessor() )->followUpSequenceActions();
		}
		return $wpdb->get_col( $wpdb->prepare( "SELECT ce.status FROM {$p}fc_campaign_emails ce JOIN {$p}fc_campaigns c ON c.id = ce.campaign_id WHERE ce.subscriber_id = %d AND c.parent_id = %d", $contact->id, $cart_funnel_model->id ) );
	};
	$outside_address  = t402_email( 'guardoutside' );
	$outside_statuses = $walk( $outside_address );
	t402_check(
		'guard: non-team contact inside the automation gets no email',
		$outside_statuses && ! in_array( 'sent', $outside_statuses, true ) && in_array( 'cancelled', $outside_statuses, true ) && ! in_array( $outside_address, $guard_sent, true ),
		[ 'statuses' => $outside_statuses, 'sent_to' => $guard_sent ]
	);
	$team_address  = 't402-guardteam-' . $run . '@katz.co';
	$emails[]      = $team_address;
	$team_statuses = $walk( $team_address );
	// FluentCRM sends once per request and an earlier section already sent, so "left alone" is the check.
	t402_check( 'control: team contact\'s cart emails are left alone', $team_statuses && ! in_array( 'cancelled', $team_statuses, true ), [ 'statuses' => $team_statuses ] );

	// Scope: with domains listed, emails outside the cart automations are never touched.
	$scope_contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'scopeoutside' ), 'status' => 'subscribed' ] );
	$onboarding    = (int) $wpdb->get_var( "SELECT c.id FROM {$p}fc_campaigns c JOIN {$p}fc_funnels f ON f.id = c.parent_id WHERE c.type = 'funnel_email_campaign' AND f.title LIKE 'Onboarding:%' LIMIT 1" );
	$newsletter    = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_campaigns WHERE type = 'campaign' ORDER BY id DESC LIMIT 1" );
	// A leftover email campaign sharing the cart automation's parent_id but used by none of its steps,
	// as FluentCRM leaves behind when it reuses an automation ID (live has about 70 of these).
	$stale = \FluentCrm\App\Models\FunnelCampaign::create( [ 'title' => 'T402 leftover campaign', 'parent_id' => $cart_funnel_model->id, 'email_subject' => 'T402 leftover', 'email_body' => '<p>t402</p>', 'status' => 'published' ] );
	$scope_rows    = [];
	foreach ( [ $onboarding, $newsletter, (int) $stale->id ] as $campaign_id ) {
		$wpdb->insert( "{$p}fc_campaign_emails", [ 'campaign_id' => $campaign_id, 'subscriber_id' => $scope_contact->id, 'email_address' => $scope_contact->email, 'status' => 'scheduled', 'scheduled_at' => current_time( 'mysql' ), 'email_type' => 'campaign', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		$scope_rows[] = (int) $wpdb->insert_id;
	}
	\CustomCRM\AbandonCart\Edd\CartEmailGuard::cancelOutsideEmails();
	$scope_statuses = $wpdb->get_col( 'SELECT status FROM ' . $p . 'fc_campaign_emails WHERE id IN (' . implode( ',', $scope_rows ) . ')' );
	t402_check( 'scope: onboarding, newsletter and a leftover campaign sharing the cart parent ID are untouched', [ 'scheduled', 'scheduled', 'scheduled' ] === $scope_statuses, $scope_statuses );
	$wpdb->query( 'DELETE FROM ' . $p . 'fc_campaign_emails WHERE id IN (' . implode( ',', $scope_rows ) . ')' );
	$wpdb->delete( "{$p}fc_campaigns", [ 'id' => (int) $stale->id ] );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( '' );
	t402_check( 'guard: does nothing when no domains are listed', 0 === \CustomCRM\AbandonCart\Edd\CartEmailGuard::cancelOutsideEmails() );
	remove_filter( 'fluent_crm/is_simulated_mail', $capture, 1 );
	if ( null === $guard_backup ) {
		delete_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION );
	} else {
		update_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, $guard_backup, true );
	}

	// 13. The new-purchase automation is 3 emails, with the code on day 4.
	$funnel_json = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/fluent-crm-custom-features/funnels/edd-abandoned-cart.json' ), true );
	$steps       = array_map( function ( $s ) { return $s['action_name'] . ':' . ( $s['settings']['wait_time_amount'] ?? '' ) . ( $s['settings']['wait_time_unit'] ?? '' ); }, (array) ( $funnel_json['sequences'] ?? [] ) );
	t402_check( 'automation JSON: wait 15m, email, wait 24h, email, wait 3d, email', [ 'fluentcrm_wait_times:15minutes', 'send_custom_email:', 'fluentcrm_wait_times:1425minutes', 'send_custom_email:', 'fluentcrm_wait_times:3days', 'send_custom_email:' ] === $steps, $steps );


	// 14. License upgrades left at checkout (WEBSITE-425). The upgrade provider is switched on for
	// this run only; the settings are restored in the cleanup below.
	$ab_backup     = get_option( '_fc_ab_cart_settings', null );
	$ab_backup_set = true;
	$ab_settings   = (array) $ab_backup;
	$ab_settings['enabled_providers'] = array_values( array_unique( array_merge( (array) ( $ab_settings['enabled_providers'] ?? [] ), [ 'edd_upgrade' ] ) ) );
	update_option( '_fc_ab_cart_settings', $ab_settings );
	\FluentCrm\App\Modules\AbandonCart\AbCartHelper::getSettings( false );

	$up_driver = \FluentCrm\App\Modules\AbandonCart\Drivers\DriverManager::getDriver( 'edd_upgrade' );
	t402_check( 'upgrade provider registered and available', $up_driver instanceof EddUpgradeCartDriver && $up_driver->isAvailable() && 'fc_ab_cart_simulation_edd_upgrade' === $up_driver->getTriggerName() );
	// FluentCRM boots enabled drivers at init, before this run enabled the provider.
	$up_driver->register();
	$up_driver->registerAutomationTrigger();
	$up_tracker = new EddCartTracking( $up_driver );
	$statics    = [];
	foreach ( [ 'renewed_licenses', 'upgraded_licenses' ] as $static ) {
		$statics[ $static ] = new ReflectionProperty( EddCartTracking::class, $static );
		$statics[ $static ]->setAccessible( true );
	}
	$reset_statics = function () use ( $statics ) {
		$statics['renewed_licenses']->setValue( null, [] );
		$statics['upgraded_licenses']->setValue( null, [] );
	};

	// The upgrade automation, imported the way FluentCRM > Automations > Import does it.
	$upgrade_json = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/fluent-crm-custom-features/funnels/edd-upgrade-cart.json' ), true );
	$import       = new ReflectionMethod( \FluentCrm\App\Http\Controllers\FunnelController::class, 'createFunnelFromData' );
	$import->setAccessible( true );
	$up_funnel    = $import->invoke( new \FluentCrm\App\Http\Controllers\FunnelController(), $upgrade_json, $upgrade_json['sequences'] );
	$up_funnel->title  = 'T402 upgrade automation';
	$up_funnel->status = 'published';
	$up_funnel->save();
	// The automation's own emails, by the campaign each email step points at. Never by parent_id:
	// gkclone has leftover campaigns whose parent_id matches a reused automation ID.
	$up_campaigns = [];
	$up_steps_db  = \FluentCrm\App\Models\FunnelSequence::where( 'funnel_id', $up_funnel->id )->where( 'action_name', 'send_custom_email' )->orderBy( 'sequence' )->get();
	foreach ( $up_steps_db as $step ) {
		$campaign = \FluentCrm\App\Models\FunnelCampaign::find( (int) ( $step->settings['reference_campaign'] ?? 0 ) );
		if ( $campaign ) {
			$up_campaigns[] = $campaign;
		}
	}
	t402_check( 'upgrade automation JSON imports: 3 emails on the upgrade trigger', 'fc_ab_cart_simulation_edd_upgrade' === $up_funnel->trigger_name && 3 === count( $up_campaigns ), [ 'trigger' => $up_funnel->trigger_name, 'emails' => count( $up_campaigns ) ] );
	$up_steps = array_map( function ( $s ) { return $s['action_name'] . ':' . ( $s['settings']['wait_time_amount'] ?? '' ) . ( $s['settings']['wait_time_unit'] ?? '' ); }, (array) ( $upgrade_json['sequences'] ?? [] ) );
	t402_check( 'upgrade automation JSON: wait 60m, email, wait 2d, email, wait 3d, email', [ 'fluentcrm_wait_times:60minutes', 'send_custom_email:', 'fluentcrm_wait_times:2days', 'send_custom_email:', 'fluentcrm_wait_times:3days', 'send_custom_email:' ] === $up_steps, $up_steps );
	$all_copy = implode( ' ', array_map( function ( $c ) { return $c->email_subject . ' ' . $c->email_body; }, $up_campaigns ) );
	t402_check( 'upgrade emails carry no discount code', false === stripos( $all_copy, 'discount' ) && false === stripos( $all_copy, 'coupon' ) );

	// A customer with a GravityImport Single Site license, bought two hours ago, renewing in 200
	// days, so Software Licensing prorates the upgrade by time.
	$owner_email = t402_email( 'upowner' );
	$lic_order   = t402_order( $owner_email, $download_id, 1, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * HOUR_IN_SECONDS ) );
	$license     = edd_software_licensing()->get_license_by_purchase( $lic_order, $download_id );
	if ( ! $license ) {
		( new EDD_SL_License() )->create( $download_id, $lic_order, 1, 0 );
		$license = edd_software_licensing()->get_license_by_purchase( $lic_order, $download_id );
	}
	$licenses[]          = (int) $license->ID;
	$license->expiration = current_time( 'timestamp' ) + 200 * DAY_IN_SECONDS;
	$license             = edd_software_licensing()->get_license( $license->ID );
	$paths               = (array) edd_sl_get_license_upgrades( $license->ID );
	$path_key            = function ( int $to_download, ?int $to_price ) use ( $paths ) {
		foreach ( $paths as $key => $path ) {
			if ( (int) $path['download_id'] === $to_download && ( null === $to_price || (int) $path['price_id'] === $to_price ) ) {
				return (int) $key;
			}
		}
		return null;
	};
	$tier_id = $path_key( $download_id, 2 );
	$life_id = $path_key( $download_id, 5 );
	$aa_id   = $path_key( 808301, null );
	t402_check( 'fixture license can take tier, lifetime and All Access upgrades', $license && ! in_array( $license->status, [ 'revoked', 'disabled', 'expired' ], true ) && null !== $tier_id && null !== $life_id && null !== $aa_id, [ 'license' => $license ? $license->ID : null, 'status' => $license ? $license->status : null, 'paths' => array_keys( $paths ) ] );

	// The EDD cart built by Software Licensing's own upgrade action, stopped at its redirect.
	$build_upgrade = function ( int $license_id, int $upgrade_id ) {
		edd_empty_cart();
		unset( $_COOKIE['fc_ab_edd_cart_token'] );
		$stop = function () { throw new RuntimeException( 't402-redirect' ); };
		add_filter( 'wp_redirect', $stop, 1 );
		try {
			edd_sl_add_upgrade_to_cart( [ 'license_id' => $license_id, 'upgrade_id' => $upgrade_id ] );
		} catch ( RuntimeException $e ) {
			if ( 't402-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $stop, 1 );
		}
	};

	// 14a. Capture.
	$build_upgrade( (int) $license->ID, $tier_id );
	$sl_item  = ( (array) edd_get_cart_contents() )[0] ?? [];
	$captured = $tracker->syncCart( t402_email( 'upcapture' ) );
	$up_item  = $captured ? ( $captured->cart['cart_contents'][0] ?? [] ) : [];
	t402_check( 'SL upgrade action put the upgrade in the EDD cart', ! empty( $sl_item['options']['is_upgrade'] ) && (int) $sl_item['options']['license_id'] === (int) $license->ID, $sl_item );
	t402_check(
		'upgrade cart captured as edd_upgrade with license, path, expiration, lifetime flag and plan',
		$captured && 'edd_upgrade' === $captured->provider && (int) ( $up_item['license_id'] ?? 0 ) === (int) $license->ID && $tier_id === ( $up_item['upgrade_id'] ?? null )
			&& (int) $license->expiration === ( $up_item['license_expiration'] ?? null ) && false === ( $up_item['license_lifetime'] ?? null )
			&& $download_id === ( $up_item['license_download_id'] ?? null ) && 1 === ( $up_item['license_price_id'] ?? null ) && null === $captured->cart['recovery_discount'],
		[ 'provider' => $captured ? $captured->provider : null, 'item' => $up_item ]
	);
	$captured->delete();

	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	edd_add_to_cart( $download_id, [ 'price_id' => 1, 'is_renewal' => 1, 'license_id' => (int) $license->ID ] );
	edd_add_to_cart( $download_id, [ 'price_id' => 2, 'is_upgrade' => true, 'upgrade_id' => $tier_id, 'license_id' => (int) $license->ID, 'cost' => 50 ] );
	$mixed = $tracker->syncCart( t402_email( 'upmixed' ) );
	t402_check( 'a cart with a renewal and an upgrade stays edd_renewal', $mixed && 'edd_renewal' === $mixed->provider, $mixed ? $mixed->provider : null );
	if ( $mixed ) {
		$mixed->delete();
	}
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );

	// Upgrade carts in the captured shape.
	$make = function ( string $tag, string $status, array $item_over = [], ?string $email = null ) use ( $up_item ) {
		return AbandonCartModel::create( [
			'email' => $email ?: t402_email( $tag ), 'full_name' => 'T Four', 'provider' => 'edd_upgrade', 'status' => $status,
			'subtotal' => 44, 'total' => 44, 'currency' => 'USD',
			'cart' => [ 'cart_contents' => [ array_merge( $up_item, $item_over ) ], 'coupons' => [] ],
		] );
	};
	// A running automation for a cart, as the runner starts it.
	$start_run = function ( AbandonCartModel $cart ) use ( $wpdb, $p, $up_funnel ) {
		$contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $cart->email, 'status' => 'transactional' ] );
		$cart->contact_id    = $contact->id;
		$cart->automation_id = $up_funnel->id;
		$cart->save();
		$wpdb->insert( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $up_funnel->id, 'subscriber_id' => $contact->id, 'status' => 'active', 'starting_sequence_id' => 0, 'source_trigger_name' => 'fc_ab_cart_simulation_edd_upgrade', 'source_ref_id' => $cart->id, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		return (int) $wpdb->insert_id;
	};
	$run_row = function ( int $id ) use ( $wpdb, $p ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT status, notes FROM {$p}fc_funnel_subscribers WHERE id = %d", $id ) );
	};

	// 14b. Skip rules, one at a time, against a control that passes all of them.
	$control = $make( 'skipcontrol', 'draft' );
	t402_check(
		'control: an open upgrade cart is not skipped by any rule',
		false === $up_driver->isWithinCoolOffPeriod( $control ) && 1 === count( $up_driver->openUpgradeItems( $control ) ) && ! $up_driver->paidOrderSince( $control ) && ! $up_driver->renewalUnderWay( $control ),
		[ 'open' => count( $up_driver->openUpgradeItems( $control ) ), 'paid' => $up_driver->paidOrderSince( $control ), 'renewal' => $up_driver->renewalUnderWay( $control ) ]
	);

	$domains_backup_up = get_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, null );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( 'gravitykit.com, katz.co' );
	$held = $make( 'upheld', 'draft' );
	( new \FluentCrm\App\Modules\AbandonCart\AbandonCartRunner() )->runAbandonCart( AbandonCartModel::find( $held->id ) );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::writeNotes();
	$held      = AbandonCartModel::find( $held->id );
	$held_made = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email = %s", $held->email ) );
	t402_check( 'skip: upgrade cart outside the allowlist is held back with the note, no contact', 'skipped' === $held->status && 0 === strpos( (string) $held->note, 'Held back' ) && 0 === $held_made, [ 'status' => $held->status, 'note' => $held->note, 'contacts' => $held_made ] );
	$inside_up_email = 't402-upinside-' . $run . '@katz.co';
	$emails[]        = $inside_up_email;
	$inside_up       = $make( 'upinside', 'draft', [], $inside_up_email );
	t402_check( 'control: an allowlisted upgrade cart is not held back', false === $up_driver->isWithinCoolOffPeriod( $inside_up ) );
	null === $domains_backup_up ? delete_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION ) : update_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, $domains_backup_up, true );

	// Runs FluentCRM's runner on a draft that a skip rule stops, then stores the skip notes.
	$run_skipped = function ( AbandonCartModel $cart ) {
		( new \FluentCrm\App\Modules\AbandonCart\AbandonCartRunner() )->runAbandonCart( AbandonCartModel::find( $cart->id ) );
		EddUpgradeCartDriver::writeSkipNotes();
		return AbandonCartModel::find( $cart->id );
	};
	$skip_noted = function ( string $label, AbandonCartModel $cart, string $note ) {
		t402_check( "skip note: {$label}", 'skipped' === $cart->status && $note === $cart->note && $note === ( $cart->cart['skipped_because'] ?? null ), [ 'status' => $cart->status, 'note' => $cart->note ] );
	};

	$license->price_id = 2;
	$on_path           = $make( 'skiponpath', 'draft' );
	$skip_on_path      = $up_driver->isWithinCoolOffPeriod( $on_path );
	$on_path           = $run_skipped( $on_path );
	$license->price_id = 1;
	$license           = edd_software_licensing()->get_license( $license->ID );
	t402_check( 'skip: the license is already on the upgrade plan', true === $skip_on_path && 1 === (int) $license->price_id );
	$skip_noted( 'already upgraded', $on_path, 'Skipped: the license was already upgraded' );

	$no_path = function ( $offered ) use ( $tier_id ) {
		unset( $offered[ $tier_id ] );
		return $offered;
	};
	add_filter( 'edd_sl_get_license_upgrade_paths', $no_path );
	$gone      = $make( 'skipnopath', 'draft' );
	$skip_gone = $up_driver->isWithinCoolOffPeriod( $gone );
	$gone_run  = $run_skipped( $gone );
	remove_filter( 'edd_sl_get_license_upgrade_paths', $no_path );
	$skip_noted( 'path no longer offered', $gone_run, 'Skipped: the upgrade is no longer available for this license' );
	t402_check( 'skip: the license no longer allows that upgrade', true === $skip_gone && false === $up_driver->isWithinCoolOffPeriod( $gone ) );

	$paid_email = t402_email( 'skippaid' );
	t402_order( $paid_email, $download_id, 1 );
	$paid_after  = $make( 'skippaid', 'draft', [], $paid_email );
	$paid_before = $make( 'skippaid', 'draft', [], $paid_email );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ) ], [ 'id' => $paid_before->id ] );
	$wpdb->update( "{$p}fc_abandoned_carts", [ 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + HOUR_IN_SECONDS ) ], [ 'id' => $paid_after->id ] );
	$paid_before = AbandonCartModel::find( $paid_before->id );
	$paid_after  = AbandonCartModel::find( $paid_after->id );
	t402_check( 'skip: the contact completed a paid order after leaving the cart', true === $up_driver->isWithinCoolOffPeriod( $paid_before ) && $up_driver->paidOrderSince( $paid_before ) );
	t402_check( 'control: a paid order before the cart does not skip it', false === $up_driver->paidOrderSince( $paid_after ) );
	$skip_noted( 'paid order since', $run_skipped( $paid_before ), 'Skipped: the contact completed a paid order after leaving the cart' );

	$renewal_cart = t402_cart( t402_email( 'skiprenewalcart' ), 'draft', 'edd_renewal' );
	$rc           = $renewal_cart->cart;
	$rc['cart_contents'][0] = array_merge( $rc['cart_contents'][0], [ 'license_id' => (int) $license->ID, 'is_renewal' => true, 'license_expiration' => (int) $license->expiration ] );
	$renewal_cart->cart     = $rc;
	$renewal_cart->save();
	$with_renewal = $make( 'skipwithrenewal', 'draft' );
	t402_check( 'skip: the license has an open renewal cart', true === $up_driver->isWithinCoolOffPeriod( $with_renewal ) && $up_driver->renewalUnderWay( $with_renewal ) );
	$skip_noted( 'renewal cart open', $run_skipped( $with_renewal ), 'Skipped: a renewal for this license is under way' );
	$renewal_cart->delete();
	t402_check( 'control: without the renewal cart it is not skipped', false === $up_driver->isWithinCoolOffPeriod( $with_renewal ) );

	$renewed_since = $make( 'skiprenewed', 'draft', [ 'license_expiration' => (int) $license->expiration - DAY_IN_SECONDS ] );
	t402_check( 'skip: the license was renewed after the cart was saved', true === $up_driver->isWithinCoolOffPeriod( $renewed_since ) && $up_driver->renewalUnderWay( $renewed_since ) );
	$skip_noted( 'renewed since', $run_skipped( $renewed_since ), 'Skipped: a renewal for this license is under way' );

	$cap_cart    = $make( 'skipcap', 'draft' );
	$cap_contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $cap_cart->email, 'status' => 'transactional' ] );
	$wpdb->insert( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $up_funnel->id, 'subscriber_id' => $cap_contact->id, 'status' => 'completed', 'starting_sequence_id' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ), 'updated_at' => current_time( 'mysql' ) ] );
	$cap_run   = (int) $wpdb->insert_id;
	$cap_30    = $up_driver->isWithinCoolOffPeriod( $cap_cart );
	$wpdb->update( "{$p}fc_funnel_subscribers", [ 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 70 * DAY_IN_SECONDS ) ], [ 'id' => $cap_run ] );
	$cap_70    = $up_driver->isWithinCoolOffPeriod( $cap_cart );
	$cap_short = function () { return 20; };
	$wpdb->update( "{$p}fc_funnel_subscribers", [ 'created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ], [ 'id' => $cap_run ] );
	add_filter( 'customcrm/edd_ab_cart/upgrade_resend_cap_days', $cap_short );
	$cap_filtered = $up_driver->isWithinCoolOffPeriod( $cap_cart );
	remove_filter( 'customcrm/edd_ab_cart/upgrade_resend_cap_days', $cap_short );
	t402_check( 'skip: the contact entered the upgrade automation 30 days ago (cap 60)', true === $cap_30 );
	t402_check( 'control: 70 days ago is outside the 60-day cap', false === $cap_70 );
	t402_check( 'control: the upgrade_resend_cap_days filter shortens the cap', false === $cap_filtered );
	$skip_noted( 'sent within 60 days', $run_skipped( $cap_cart ), 'Skipped: the contact got the upgrade emails within the last 60 days' );

	// Recapture's list of shoppers it emailed covers new-purchase carts only.
	$prior_backup_up = get_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION, null );
	$prior_email     = t402_email( 'upprior' );
	$prior_csv       = tempnam( sys_get_temp_dir(), 't402' );
	file_put_contents( $prior_csv, "email,last_sent_at\n{$prior_email}," . gmdate( 'Y-m-d\TH:i:s\Z', time() - 5 * DAY_IN_SECONDS ) . "\n" );
	\CustomCRM\AbandonCart\Edd\PriorRecipients::import( $prior_csv );
	unlink( $prior_csv );
	$prior_cart = $make( 'upprior', 'draft', [], $prior_email );
	$sent_up    = new ReflectionMethod( EddUpgradeCartDriver::class, 'sentRecently' );
	$sent_up->setAccessible( true );
	$sent_new   = new ReflectionMethod( EddCartDriver::class, 'sentRecently' );
	$sent_new->setAccessible( true );
	t402_check( 'resend cap: Recapture\'s new-cart recipients do not cap the upgrade automation', false === $sent_up->invoke( $up_driver, $prior_cart ) && false === $up_driver->isWithinCoolOffPeriod( $prior_cart ) );
	t402_check( 'control: the same recipient does cap the new-purchase automation', true === $sent_new->invoke( new EddCartDriver(), $prior_cart ) );
	null === $prior_backup_up ? delete_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION ) : update_option( \CustomCRM\AbandonCart\Edd\PriorRecipients::OPTION, $prior_backup_up, false );

	// 14c. Stop rules.
	global $wp_filter;
	$upgrade_hook = function ( int $license_id, int $order_id ) use ( &$wp_filter ) {
		// EDD Recurring cancels subscriptions on this hook; keep it off gkclone's data.
		$detached = [];
		foreach ( $wp_filter['edd_sl_license_upgraded']->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && 'cancel_subscription_on_upgrade' === $cb['function'][1] ) {
					remove_action( 'edd_sl_license_upgraded', $cb['function'], $priority );
					$detached[] = [ $cb['function'], $priority, $cb['accepted_args'] ];
				}
			}
		}
		do_action( 'edd_sl_license_upgraded', $license_id, [ 'payment_id' => $order_id, 'old_payment_id' => 0, 'old_download_id' => 0, 'old_price_id' => 0 ] );
		foreach ( $detached as [ $fn, $priority, $args ] ) {
			add_action( 'edd_sl_license_upgraded', $fn, $priority, $args );
		}
	};

	$stop_up     = $make( 'stopupgraded', 'processing' );
	$stop_up_run = $start_run( $stop_up );
	$stop_link   = $make( 'stoplinked', 'processing' );
	$stop_other  = $make( 'stopother', 'processing', [ 'license_id' => 999999998 ] );
	$up_order    = t402_order( t402_email( 'upgradeorder' ), $download_id, 2 );
	$stop_link->order_id = $up_order;
	$stop_link->save();
	$upgrade_hook( (int) $license->ID, $up_order );
	$tracker->closeRenewedCarts();
	$reset_statics();
	$stop_up    = AbandonCartModel::find( $stop_up->id );
	$stop_link  = AbandonCartModel::find( $stop_link->id );
	$stop_other = AbandonCartModel::find( $stop_other->id );
	t402_check( 'stop: an upgrade by any route marks the cart recovered with a note', 'recovered' === $stop_up->status && 'Upgraded outside the recovery link' === $stop_up->note && (int) $stop_up->order_id === $up_order, [ 'status' => $stop_up->status, 'note' => $stop_up->note ] );
	t402_check( 'stop: ... and cancels its automation run', 'cancelled' === $run_row( $stop_up_run )->status && 'Cancelled because the license was upgraded' === $run_row( $stop_up_run )->notes, $run_row( $stop_up_run ) );
	t402_check( 'stop: an upgrade through the recovery link is recovered without the "outside" note', 'recovered' === $stop_link->status && 'Upgraded outside the recovery link' !== $stop_link->note, [ 'status' => $stop_link->status, 'note' => $stop_link->note ] );
	t402_check( 'control: an upgrade cart for another license stays open', 'processing' === $stop_other->status, $stop_other->status );

	// An email FluentCRM already queued for a run, and a newsletter for the same contact as the control.
	$newsletter_id = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_campaigns WHERE type = 'campaign' ORDER BY id DESC LIMIT 1" );
	$queue_email   = function ( string $address, int $campaign_id ) use ( $wpdb, $p ) {
		$contact = FluentCrmApi( 'contacts' )->getContact( $address );
		$wpdb->insert( "{$p}fc_campaign_emails", [ 'campaign_id' => $campaign_id, 'subscriber_id' => $contact->id, 'email_address' => $address, 'status' => 'scheduled', 'scheduled_at' => current_time( 'mysql' ), 'email_type' => 'funnel_email_campaign', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		return (int) $wpdb->insert_id;
	};
	$email_status  = function ( int $id ) use ( $wpdb, $p ) {
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}fc_campaign_emails WHERE id = %d", $id ) );
	};
	$up_email_ids  = \CustomCRM\AbandonCart\Edd\CartEmailGuard::campaignIdsFor( [ (int) $up_funnel->id ] );

	$stop_paid       = $make( 'stoppaid', 'processing' );
	$stop_paid_run   = $start_run( $stop_paid );
	$stop_paid_mail  = $queue_email( $stop_paid->email, (int) $up_email_ids[0] );
	$stop_paid_news  = $queue_email( $stop_paid->email, $newsletter_id );
	$stop_paid_draft = $make( 'stoppaid', 'draft', [], $stop_paid->email );
	$bystander       = $make( 'stopbystander', 'processing' );
	t402_order( $stop_paid->email, $download_id, 1 );
	$reset_statics();
	$stop_paid_now = AbandonCartModel::find( $stop_paid->id );
	t402_check( 'stop: another paid order marks the running upgrade cart lost with the reason', $stop_paid_now && 'lost' === $stop_paid_now->status && 'Cancelled because the contact completed another purchase' === $stop_paid_now->note, $stop_paid_now ? [ $stop_paid_now->status, $stop_paid_now->note ] : 'deleted' );
	t402_check( 'stop: ... and deletes the upgrade cart still in draft', null === AbandonCartModel::find( $stop_paid_draft->id ) );
	t402_check( 'stop: ... and cancels its run with the reason', 'cancelled' === $run_row( $stop_paid_run )->status && 'Cancelled because the contact completed another purchase' === $run_row( $stop_paid_run )->notes, $run_row( $stop_paid_run ) );
	t402_check( 'stop: ... and cancels the upgrade email FluentCRM already queued', 'cancelled' === $email_status( $stop_paid_mail ), $email_status( $stop_paid_mail ) );
	t402_check( 'control: a newsletter queued for the same contact is untouched', 'scheduled' === $email_status( $stop_paid_news ), $email_status( $stop_paid_news ) );
	t402_check( 'control: another shopper\'s upgrade cart is untouched', 'processing' === AbandonCartModel::find( $bystander->id )->status );

	// A lost cart nobody stopped still counts as recovered by an upgrade; the control for the stopped cart.
	$lost_plain = $make( 'lostplain', 'lost' );

	// The paid order is this license's own upgrade: left for the shutdown close, then recovered.
	$own        = $make( 'stopown', 'processing' );
	$own_run    = $start_run( $own );
	$statics['upgraded_licenses']->setValue( null, [ (int) $license->ID => 0 ] );
	$own_order  = t402_order( $own->email, $download_id, 2 );
	$own_mid    = AbandonCartModel::find( $own->id );
	$statics['upgraded_licenses']->setValue( null, [ (int) $license->ID => $own_order ] );
	$tracker->closeRenewedCarts();
	$reset_statics();
	$own = AbandonCartModel::find( $own->id );
	$stopped_later = AbandonCartModel::find( $stop_paid->id );
	t402_check( 'a stopped cart stays lost when its license is upgraded later', 'lost' === $stopped_later->status && ! empty( $stopped_later->cart['stopped_because'] ), [ $stopped_later->status, $stopped_later->cart['stopped_because'] ?? null ] );
	t402_check( 'control: a lost cart that was not stopped is recovered by the same upgrade', 'recovered' === AbandonCartModel::find( $lost_plain->id )->status, AbandonCartModel::find( $lost_plain->id )->status );
	t402_check( 'an order that upgrades the cart\'s own license leaves it for the upgrade close', $own_mid && 'processing' === $own_mid->status && 'recovered' === $own->status && (int) $own->order_id === $own_order, [ 'mid' => $own_mid ? $own_mid->status : null, 'end' => $own->status ] );

	$stop_renew     = $make( 'stoprenewed', 'processing' );
	$stop_renew_run = $start_run( $stop_renew );
	$statics['renewed_licenses']->setValue( null, [ (int) $license->ID ] );
	$tracker->closeRenewedCarts();
	$reset_statics();
	$stop_renew = AbandonCartModel::find( $stop_renew->id );
	t402_check( 'stop: renewing the license marks the upgrade cart lost and cancels its run', $stop_renew && 'lost' === $stop_renew->status && 'Cancelled because the license was renewed' === $stop_renew->note && 'cancelled' === $run_row( $stop_renew_run )->status && 'Cancelled because the license was renewed' === $run_row( $stop_renew_run )->notes, [ $stop_renew ? $stop_renew->status : 'deleted', $run_row( $stop_renew_run ) ] );

	$stop_rc     = $make( 'stoprenewalcart', 'processing' );
	$stop_rc_run = $start_run( $stop_rc );
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	$renew_added = edd_sl_add_renewal_to_cart( (int) $license->ID );
	if ( is_wp_error( $renew_added ) ) {
		edd_add_to_cart( $download_id, [ 'price_id' => 1, 'is_renewal' => 1, 'license_id' => (int) $license->ID ] );
	}
	$renewer = $tracker->syncCart( t402_email( 'renewer' ) );
	$stop_rc_now = AbandonCartModel::find( $stop_rc->id );
	t402_check( 'stop: a renewal cart for the license marks the upgrade cart lost and cancels its run', $renewer && 'edd_renewal' === $renewer->provider && $stop_rc_now && 'lost' === $stop_rc_now->status && 'cancelled' === $run_row( $stop_rc_run )->status && 'Cancelled because a renewal for this license was started' === $run_row( $stop_rc_run )->notes, [ 'renewal_added' => is_wp_error( $renew_added ) ? $renew_added->get_error_code() : 'sl', 'renewer' => $renewer ? $renewer->provider : null, 'run' => $run_row( $stop_rc_run ) ] );
	if ( $renewer ) {
		$renewer->delete();
	}
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );

	// New-purchase paths leave upgrade carts to the upgrade rules.
	$np_email = t402_email( 'npupgrade' );
	$np_up    = $make( 'npupgrade', 'processing', [], $np_email );
	edd_add_to_cart( $download_id, [ 'price_id' => 1 ] );
	$np_cart  = $tracker->syncCart( $np_email );
	t402_check( 'a new-purchase cart for the same email leaves a running upgrade cart alone', $np_cart && 'edd' === $np_cart->provider && 'processing' === AbandonCartModel::find( $np_up->id )->status );
	$np_cart->delete();
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );

	// Queued emails are cancelled for the new-purchase and renewal automations too.
	$np_funnel_id = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_funnels WHERE trigger_name = 'fc_ab_cart_simulation_edd' AND status = 'published' ORDER BY id DESC LIMIT 1" );
	$rn_funnel_id = (int) $wpdb->get_var( "SELECT id FROM {$p}fc_funnels WHERE trigger_name = 'fc_ab_cart_simulation_edd_renewal' AND status = 'published' ORDER BY id DESC LIMIT 1" );
	$np_mail_ids  = \CustomCRM\AbandonCart\Edd\CartEmailGuard::campaignIdsFor( [ $np_funnel_id ] );
	$rn_mail_ids  = \CustomCRM\AbandonCart\Edd\CartEmailGuard::campaignIdsFor( [ $rn_funnel_id ] );
	t402_check( 'queued-email fixtures: published new-purchase and renewal automations with emails', $np_mail_ids && $rn_mail_ids, [ $np_funnel_id, $rn_funnel_id ] );

	$np_buyer   = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'npqueued' ), 'status' => 'transactional' ] );
	$wpdb->insert( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $np_funnel_id, 'subscriber_id' => $np_buyer->id, 'status' => 'active', 'starting_sequence_id' => 0, 'source_trigger_name' => 'fc_ab_cart_simulation_edd', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
	$np_run     = (int) $wpdb->insert_id;
	$np_mail    = $queue_email( $np_buyer->email, (int) $np_mail_ids[0] );
	t402_order( $np_buyer->email, $download_id, 1 );
	t402_check( 'new-purchase: a completed order cancels the run and its queued cart email', 'cancelled' === $run_row( $np_run )->status && 'cancelled' === $email_status( $np_mail ), [ $run_row( $np_run ), $email_status( $np_mail ) ] );

	$rn_cart = t402_cart( t402_email( 'rnqueued' ), 'processing', 'edd_renewal' );
	$rn_cc   = $rn_cart->cart;
	$rn_cc['cart_contents'][0] = array_merge( $rn_cc['cart_contents'][0], [ 'license_id' => (int) $license->ID, 'is_renewal' => true, 'license_expiration' => (int) $license->expiration - DAY_IN_SECONDS ] );
	$rn_cart->cart = $rn_cc;
	$rn_cart->save();
	$rn_contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $rn_cart->email, 'status' => 'transactional' ] );
	$wpdb->insert( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $rn_funnel_id, 'subscriber_id' => $rn_contact->id, 'status' => 'active', 'starting_sequence_id' => 0, 'source_trigger_name' => 'fc_ab_cart_simulation_edd_renewal', 'source_ref_id' => $rn_cart->id, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
	$rn_run  = (int) $wpdb->insert_id;
	$rn_mail = $queue_email( $rn_cart->email, (int) $rn_mail_ids[0] );
	$statics['renewed_licenses']->setValue( null, [ (int) $license->ID ] );
	$tracker->closeRenewedCarts();
	$reset_statics();
	t402_check( 'renewal: a renewal cancels the run and its queued cart email', 'recovered' === AbandonCartModel::find( $rn_cart->id )->status && 'cancelled' === $run_row( $rn_run )->status && 'cancelled' === $email_status( $rn_mail ), [ AbandonCartModel::find( $rn_cart->id )->status, $run_row( $rn_run ), $email_status( $rn_mail ) ] );

	// 14d. Recovery link.
	$restore = $make( 'restore', 'processing' );
	t402_restore( $restore, 'edd_upgrade' );
	$restored_items = (array) edd_get_cart_contents();
	$restored_opts  = $restored_items[0]['options'] ?? [];
	$expected_cost  = (float) edd_sl_get_license_upgrade_cost( (int) $license->ID, $tier_id );
	$restore        = AbandonCartModel::find( $restore->id );
	t402_check(
		'recovery link rebuilds the upgrade at today\'s price',
		1 === count( $restored_items ) && (int) $restored_items[0]['id'] === $download_id && ! empty( $restored_opts['is_upgrade'] ) && (int) $restored_opts['license_id'] === (int) $license->ID
			&& (int) $restored_opts['upgrade_id'] === $tier_id && 2 === (int) $restored_opts['price_id'] && abs( (float) $restored_opts['cost'] - $expected_cost ) < 0.01 && 1 === (int) $restore->click_counts && ! $restore->note,
		[ 'items' => $restored_items, 'expected_cost' => $expected_cost, 'note' => $restore->note ]
	);
	t402_check( 'the rebuilt upgrade is priced by Software Licensing at checkout', abs( (float) edd_get_cart_total() - $expected_cost ) < 0.01, [ 'total' => edd_get_cart_total(), 'expected' => $expected_cost ] );
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );

	// An upgrade that can no longer be bought is never sold as a new purchase of the new plan.
	$failed_restore = function ( string $tag, array $item_over ) use ( $make ) {
		edd_clear_errors();
		$cart = $make( $tag, 'processing', $item_over );
		t402_restore( $cart, 'edd_upgrade' );
		$result = [
			'items'  => (array) edd_get_cart_contents(),
			'errors' => (array) edd_get_errors(),
			'note'   => (string) AbandonCartModel::find( $cart->id )->note,
		];
		edd_clear_errors();
		edd_empty_cart();
		unset( $_COOKIE['fc_ab_edd_cart_token'] );
		return $result;
	};
	$restore_bad   = $failed_restore( 'restorebad', [ 'license_price_id' => 3 ] );
	$expected_note = 'Recovery link could not upgrade license #' . $license->ID . ': upgrade_unavailable; nothing added';
	t402_check(
		'recovery link, upgrade no longer possible: nothing added, checkout error and note',
		! $restore_bad['items'] && 'This license was already upgraded, or the upgrade is no longer available.' === ( $restore_bad['errors']['customcrm_upgrade_unavailable'] ?? null ) && $expected_note === $restore_bad['note'],
		$restore_bad
	);
	$license->price_id = 2;
	$restore_on_plan   = $failed_restore( 'restoreonplan', [] );
	$license->price_id = 1;
	$license           = edd_software_licensing()->get_license( $license->ID );
	t402_check(
		'recovery link, license already on the new plan: nothing added, checkout error and note',
		! $restore_on_plan['items'] && isset( $restore_on_plan['errors']['customcrm_upgrade_unavailable'] ) && $expected_note === $restore_on_plan['note'] && 1 === (int) $license->price_id,
		$restore_on_plan
	);

	// No discount through a recovery link for an upgrade, even a store-wide code named in the link.
	$link_code    = 'T402UP' . strtoupper( $run );
	$link_code_id = edd_add_discount( [ 'code' => $link_code, 'name' => 'T402 link code', 'status' => 'active', 'type' => 'percent', 'amount' => 10 ] );
	$code_restore = $make( 'restorecode', 'processing' );
	t402_restore( $code_restore, 'edd_upgrade', [ 'fc_ab_code' => $link_code ] );
	$up_discounts = (array) edd_get_cart_discounts();
	$up_rebuilt   = 1 === count( (array) edd_get_cart_contents() );
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	$np_restore = t402_cart( t402_email( 'restorecodenp' ), 'processing' );
	t402_restore( $np_restore, 'edd', [ 'fc_ab_code' => $link_code ] );
	$np_discounts = array_map( 'strtoupper', (array) edd_get_cart_discounts() );
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	if ( $link_code_id ) {
		edd_delete_discount( $link_code_id );
	}
	t402_check( 'recovery link: fc_ab_code is not applied to an upgrade', $link_code_id && $up_rebuilt && ! $up_discounts, [ 'discounts' => $up_discounts, 'code_id' => $link_code_id ] );
	t402_check( 'control: the same link code is applied to a new-purchase cart', in_array( $link_code, $np_discounts, true ), $np_discounts );

	// 14e. Smart codes, worked out when the email is sent.
	$code_cart = $make( 'codes', 'processing' );
	$code      = function ( AbandonCartModel $cart, string $key, string $default = 'DEFAULT' ) use ( $up_tracker ) {
		$subscriber = (object) [ 'email' => $cart->email ];
		return (string) $up_tracker->parseSmartCode( '{{ab_cart_edd_upgrade.' . $key . '}}', $key, $default, $subscriber );
	};
	$plan_name = function ( int $download, int $price ) {
		return esc_html( wp_specialchars_decode( wp_strip_all_tags( (string) edd_get_download_name( $download, $price ) ), ENT_QUOTES ) );
	};
	$full      = (float) edd_get_price_option_amount( $download_id, 2 );
	$today     = (float) edd_sl_get_license_upgrade_cost( (int) $license->ID, $tier_id );
	$fmt       = function ( float $amount ) use ( $up_driver ) { return $up_driver->formatPrice( $amount, 'USD' ); };
	$date_fmt  = get_option( 'date_format' );
	$codes_now = [
		'current_plan' => $code( $code_cart, 'current_plan' ),
		'new_plan'     => $code( $code_cart, 'new_plan' ),
		'full_price'   => $code( $code_cart, 'full_price' ),
		'today_price'  => $code( $code_cart, 'today_price' ),
		'credit'       => $code( $code_cart, 'credit' ),
		'breakdown'    => $code( $code_cart, 'price_breakdown' ),
		'change'       => $code( $code_cart, 'price_change_line' ),
		'renewal'      => $code( $code_cart, 'renewal_line' ),
		'soon'         => $code( $code_cart, 'renewal_soon_line' ),
		'extras'       => $code( $code_cart, 'new_plan_extras' ),
	];
	t402_check( 'code: current_plan and new_plan', $plan_name( $download_id, 1 ) === $codes_now['current_plan'] && $plan_name( $download_id, 2 ) === $codes_now['new_plan'], [ $codes_now['current_plan'], $codes_now['new_plan'] ] );
	t402_check( 'code: full_price is the new plan\'s regular price', $fmt( $full ) === $codes_now['full_price'], [ $codes_now['full_price'], $full ] );
	t402_check( 'code: today_price is SL\'s prorated cost now, below the full price', $fmt( $today ) === $codes_now['today_price'] && $today > 0 && $today < $full, [ $codes_now['today_price'], $today ] );

	// The breakdown's three lines, as label and cents, read from the rendered HTML.
	$parse_breakdown = function ( string $html ): array {
		$rows = [];
		foreach ( explode( '<br>', $html ) as $row ) {
			$text = trim( html_entity_decode( wp_strip_all_tags( $row ), ENT_QUOTES, 'UTF-8' ) );
			if ( preg_match( '/^(.*): (−?)([^0-9\s]*)([0-9,]+)\.([0-9]{2})$/u', $text, $m ) ) {
				$rows[] = [ 'label' => $m[1], 'symbol' => $m[3], 'cents' => (int) str_replace( ',', '', $m[4] ) * 100 + (int) $m[5], 'text' => $text ];
			}
		}
		return $rows;
	};
	$breakdown_examples = [];
	// Line 1 − line 2 = line 3 to the cent, line 3 = SL's cost, and the labels for the case.
	$check_breakdown = function ( string $case, AbandonCartModel $cart, string $line1_label, string $credit_label, float $sl_cost ) use ( $code, $parse_breakdown, &$breakdown_examples ) {
		$html  = $code( $cart, 'price_breakdown' );
		$rows  = $parse_breakdown( $html );
		$adds  = 3 === count( $rows ) && $rows[0]['cents'] - $rows[1]['cents'] === $rows[2]['cents'];
		$is_sl = 3 === count( $rows ) && $rows[2]['cents'] === (int) round( $sl_cost * 100 );
		$named = 3 === count( $rows ) && $line1_label === $rows[0]['label'] && $credit_label === $rows[1]['label'] && 'You pay today' === $rows[2]['label'];
		$breakdown_examples[ $case ] = wp_list_pluck( $rows, 'text' );
		t402_check( "breakdown ({$case}): line 1 − line 2 = line 3 to the cent", $adds, $rows );
		t402_check( "breakdown ({$case}): line 3 is SL's cost", $is_sl, [ 'rows' => $rows, 'sl_cost' => $sl_cost ] );
		t402_check( "breakdown ({$case}): labels", $named, [ 'got' => wp_list_pluck( $rows, 'label' ), 'expected' => [ $line1_label, $credit_label ] ] );
		return $rows;
	};
	$current_label = $plan_name( $download_id, 1 );

	// Time-based, same-term target: the new plan until the current renewal date.
	$rows_term = $check_breakdown( 'time-based, same term', $code_cart, $plan_name( $download_id, 2 ) . ' until ' . date_i18n( $date_fmt, (int) $license->expiration ), 'Credit for the unused time on ' . $current_label, $today );
	// Same term length, so SL's credit and the new plan's share are the same fraction of each price.
	$same_share = 3 === count( $rows_term ) && abs( $rows_term[1]['cents'] * $full - $rows_term[0]['cents'] * 99 ) <= 3 * $full;
	t402_check( 'breakdown (time-based, same term): line 1 is the new plan\'s share of the term and the credit is below the $99 paid', $same_share && $rows_term[0]['cents'] < 17900 && $rows_term[1]['cents'] > 0 && $rows_term[1]['cents'] < 9900, $rows_term );
	t402_check( 'code: credit matches line 2 of the breakdown', 3 === count( $rows_term ) && '−' . html_entity_decode( $codes_now['credit'], ENT_QUOTES, 'UTF-8' ) === substr( $rows_term[1]['text'], strrpos( $rows_term[1]['text'], ': ' ) + 2 ), [ $codes_now['credit'], $rows_term[1]['text'] ?? null ] );
	t402_check( 'code: price_change_line says a term upgrade gets lower each day', false !== strpos( $codes_now['change'], 'lower each day' ), $codes_now['change'] );
	t402_check( 'code: renewal_line, same term: the date stays', 'Your renewal date stays ' . date_i18n( $date_fmt, (int) $license->expiration ) . '. You won’t be charged twice.' === $codes_now['renewal'], $codes_now['renewal'] );
	t402_check( 'code: renewal_soon_line is empty 200 days out', '' === $codes_now['soon'], $codes_now['soon'] );
	t402_check( 'code: new_plan_extras for a bigger tier of the same product', false !== strpos( $codes_now['extras'], '<li>Up to 3 Sites, instead of Single Site</li>' ), $codes_now['extras'] );

	$life_cart = $make( 'codeslife', 'processing', [ 'upgrade_id' => $life_id ] );
	// Time-based, lifetime target: the full lifetime price, less the credit for the unused time.
	$rows_life = $check_breakdown( 'time-based, lifetime target', $life_cart, $plan_name( $download_id, 5 ), 'Credit for the unused time on ' . $current_label, (float) edd_sl_get_license_upgrade_cost( (int) $license->ID, $life_id ) );
	t402_check( 'breakdown (time-based, lifetime target): line 1 is the full $399 and the credit is below the $99 paid', 3 === count( $rows_life ) && 39900 === $rows_life[0]['cents'] && $rows_life[1]['cents'] > 0 && $rows_life[1]['cents'] < 9900, $rows_life );

	// Cost-based fallback: a license bought an hour ago is inside SL's one-day minimum.
	$fresh_order = t402_order( t402_email( 'freshowner' ), $download_id, 1 );
	$fresh       = edd_software_licensing()->get_license_by_purchase( $fresh_order, $download_id );
	if ( ! $fresh ) {
		( new EDD_SL_License() )->create( $download_id, $fresh_order, 1, 0 );
		$fresh = edd_software_licensing()->get_license_by_purchase( $fresh_order, $download_id );
	}
	$licenses[]        = (int) $fresh->ID;
	$midnight          = strtotime( 'today midnight' );
	$term_seconds      = strtotime( $fresh->license_length(), $midnight ) - $midnight;
	$fresh->expiration = time() - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) + $term_seconds - HOUR_IN_SECONDS;
	$fresh             = edd_software_licensing()->get_license( $fresh->ID );
	$fresh_cart        = $make( 'codesfresh', 'processing', [ 'license_id' => (int) $fresh->ID ] );
	$fresh_cost        = (float) edd_sl_get_license_upgrade_cost( (int) $fresh->ID, $tier_id );
	$rows_fresh        = $check_breakdown( 'cost-based, bought within the minimum time', $fresh_cart, $plan_name( $download_id, 2 ), 'Credit for ' . $current_label, $fresh_cost );
	t402_check( 'breakdown (cost-based, minimum time): $179.00 − $99.00 = $80.00', 3 === count( $rows_fresh ) && [ 17900, 9900, 8000 ] === wp_list_pluck( $rows_fresh, 'cents' ), $rows_fresh );

	// Cost-based fallback: the proration method switched to cost-based.
	$cost_based = function () { return 'cost-based'; };
	add_filter( 'edd_sl_proration_method', $cost_based );
	$method_cost = (float) edd_sl_get_license_upgrade_cost( (int) $license->ID, $tier_id );
	$rows_method = $check_breakdown( 'cost-based method', $code_cart, $plan_name( $download_id, 2 ), 'Credit for ' . $current_label, $method_cost );
	remove_filter( 'edd_sl_proration_method', $cost_based );
	t402_check( 'breakdown (cost-based method): $179.00 − $99.00 = $80.00', 3 === count( $rows_method ) && [ 17900, 9900, 8000 ] === wp_list_pluck( $rows_method, 'cents' ), $rows_method );
	t402_check( 'code: renewal_line, lifetime plan: no more renewals', 'Your new plan is a lifetime license, so there are no more renewals. You won’t be charged twice.' === $code( $life_cart, 'renewal_line' ), $code( $life_cart, 'renewal_line' ) );
	t402_check( 'code: price_change_line says a lifetime upgrade goes up each day', false !== strpos( $code( $life_cart, 'price_change_line' ), 'goes up' ), $code( $life_cart, 'price_change_line' ) );

	// A different term length: SL counts the new length from the license's latest payment.
	$other_term = function ( $length, $payment_id, $download, $license_id ) use ( $license ) {
		return (int) $license_id === (int) $license->ID ? '+1 months' : $length;
	};
	add_filter( 'edd_sl_license_exp_length', $other_term, 10, 4 );
	$term_line = $code( $code_cart, 'renewal_line' );
	remove_filter( 'edd_sl_license_exp_length', $other_term, 10 );
	$payment_ids   = (array) edd_software_licensing()->get_license( $license->ID )->payment_ids;
	$latest_order  = edd_get_order( (int) end( $payment_ids ) );
	$sl_new_expiry = strtotime( '+1 years', strtotime( $latest_order->date_created ) );
	t402_check( 'code: renewal_line, different term: the date SL\'s upgrade handler would set', 'Your license will renew on ' . date_i18n( $date_fmt, $sl_new_expiry ) . '. You won’t be charged twice.' === $term_line, [ 'got' => $term_line, 'expected_date' => date_i18n( $date_fmt, $sl_new_expiry ) ] );

	$aa_cart = $make( 'codesaa', 'processing', [ 'upgrade_id' => $aa_id ] );
	t402_check( 'code: new_plan_extras for All Access', false !== strpos( $code( $aa_cart, 'new_plan_extras' ), 'every GravityKit plugin, with all updates and support' ), $code( $aa_cart, 'new_plan_extras' ) );

	$saved_expiration    = (int) $license->expiration;
	$soon                = current_time( 'timestamp' ) + 10 * DAY_IN_SECONDS;
	$license->expiration = $soon;
	$soon_line           = $code( $code_cart, 'renewal_soon_line' );
	$license->expiration = $saved_expiration;
	$license             = edd_software_licensing()->get_license( $license->ID );
	t402_check( 'code: renewal_soon_line within 30 days', 'Your license renews on ' . date_i18n( $date_fmt, $soon ) . '. If you’d rather upgrade then, reply and we’ll set it up.' === $soon_line, $soon_line );

	$closed_cart = $make( 'codesclosed', 'processing', [ 'license_price_id' => 3 ] );
	t402_check(
		'code: price codes fall back to their default once the upgrade is not possible',
		'DEFAULT' === $code( $closed_cart, 'today_price' ) && 'DEFAULT' === $code( $closed_cart, 'price_breakdown' ) && 'DEFAULT' === $code( $closed_cart, 'renewal_line' ) && $plan_name( $download_id, 1 ) === $code( $closed_cart, 'current_plan' )
	);
	$no_code_url = $code( $code_cart, 'recovery_url_code.BFCM50' );
	t402_check( 'code: recovery_url_code gives the plain recovery link for an upgrade', false !== strpos( $no_code_url, 'fc_cart_edd_upgrade' ) && false === strpos( $no_code_url, 'fc_ab_code' ), $no_code_url );

	$seen_prices = [];
	$spy         = function ( $method, $license_id, $old_price, $new_price ) use ( &$seen_prices ) {
		$seen_prices[] = [ (float) $old_price, (float) $new_price ];
		return $method;
	};
	add_filter( 'edd_sl_proration_method', $spy, 10, 4 );
	$code( $code_cart, 'price_change_line' );
	remove_filter( 'edd_sl_proration_method', $spy, 10 );
	// Other code on gkclone calls this filter too (once per upgrade path), so look for this call and for no zero prices.
	$real_prices = in_array( [ 99.0, $full ], $seen_prices, true ) && ! in_array( [ 0.0, 0.0 ], $seen_prices, true );
	t402_check( 'code: price_change_line passes the real old and new prices to edd_sl_proration_method', $real_prices, $seen_prices );
	t402_check( 'upgrade JSON: the renewal paragraph carries no fixed sentence', false === strpos( (string) file_get_contents( WP_PLUGIN_DIR . '/fluent-crm-custom-features/funnels/edd-upgrade-cart.json' ), 'charged twice' ) );

	$discounts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_adjustments WHERE type = 'discount'" );
	$no_code          = $code( $code_cart, 'recovery_discount_code', 'NOCODE' );
	$discounts_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_adjustments WHERE type = 'discount'" );
	t402_check( 'code: no discount code is ever created for an upgrade cart', 'NOCODE' === $no_code && $discounts_before === $discounts_after, [ $no_code, $discounts_before, $discounts_after ] );

	// The three imported emails, rendered by FluentCRM's own parser for a contact with a running upgrade cart.
	$render_contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => t402_email( 'render' ), 'first_name' => 'Tess', 'status' => 'subscribed' ] );
	$render_cart    = $make( 'render', 'processing', [], $render_contact->email );
	$rendered       = [];
	foreach ( $up_campaigns as $campaign ) {
		$rendered[] = [
			'subject' => \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign->email_subject, $render_contact ),
			'body'    => \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign->email_body, $render_contact ),
		];
	}
	// T402_DUMP=1 saves the rendered emails to /tmp/t402-rendered.json for reading.
	if ( getenv( 'T402_DUMP' ) ) {
		file_put_contents( '/tmp/t402-rendered.json', wp_json_encode( [ 'emails' => $rendered, 'breakdowns' => $breakdown_examples ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
	$unparsed = array_filter( $rendered, function ( $r ) { return false !== strpos( $r['subject'] . $r['body'], 'ab_cart_edd_upgrade' ); } );
	t402_check( 'render: no upgrade smart code left unparsed in the 3 emails', 3 === count( $rendered ) && ! $unparsed, array_map( function ( $r ) { return $r['subject']; }, $rendered ) );
	t402_check( 'render: email 1 subject names the new plan', 'Your upgrade to ' . $plan_name( $download_id, 2 ) . ' is saved' === $rendered[0]['subject'], $rendered[0]['subject'] );
	t402_check(
		'render: email 1 body has the plans, the breakdown, the renewal line and a recovery link',
		false !== strpos( $rendered[0]['body'], 'Hi Tess,' ) && false !== strpos( $rendered[0]['body'], 'You pay today: ' . $fmt( $today ) ) && false !== strpos( $rendered[0]['body'], 'Your renewal date stays' ) && false !== strpos( $rendered[0]['body'], 'fc_cart_edd_upgrade' ) && false !== strpos( $rendered[0]['body'], $render_cart->checkout_key ),
		substr( wp_strip_all_tags( $rendered[0]['body'] ), 0, 600 )
	);
	t402_check( 'render: email 2 lists what the new plan adds and the refund policy', false !== strpos( $rendered[1]['body'], 'Up to 3 Sites, instead of Single Site' ) && false !== strpos( $rendered[1]['body'], '30-day refund policy' ) );
	t402_check( 'render: email 3 repeats the price for an approver', false !== strpos( $rendered[2]['body'], 'You pay today: ' . $fmt( $today ) ) && false !== strpos( $rendered[2]['body'], 'This is our last email about it.' ) );

	// 14f. Send-time guard: an outside contact walked through the upgrade automation gets no email.
	$guard_up_backup = get_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, null );
	\CustomCRM\AbandonCart\Edd\AllowedDomains::save( 'gravitykit.com, katz.co' );
	$up_sent    = [];
	$up_capture = function ( $simulated, $data ) use ( &$up_sent ) {
		$up_sent[] = $data['to']['email'] ?? '';
		return true;
	};
	add_filter( 'fluent_crm/is_simulated_mail', $up_capture, 1, 2 );
	$up_walk = function ( string $address ) use ( $wpdb, $p, $up_funnel ) {
		$contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $address, 'status' => 'subscribed' ] );
		( new \FluentCrm\App\Services\Funnel\FunnelProcessor() )->startFunnelSequence( $up_funnel, [], [], $contact );
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}fc_funnel_subscribers SET next_execution_time = %s WHERE subscriber_id = %d AND funnel_id = %d AND status = 'active'", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 60 ), $contact->id, $up_funnel->id ) );
			( new \FluentCrm\App\Services\Funnel\FunnelProcessor() )->followUpSequenceActions();
		}
		return $wpdb->get_col( $wpdb->prepare( "SELECT ce.status FROM {$p}fc_campaign_emails ce JOIN {$p}fc_campaigns c ON c.id = ce.campaign_id WHERE ce.subscriber_id = %d AND c.parent_id = %d", $contact->id, $up_funnel->id ) );
	};
	$up_outside          = t402_email( 'upguardoutside' );
	$up_outside_statuses = $up_walk( $up_outside );
	t402_check(
		'guard: an outside contact inside the upgrade automation gets no email',
		$up_outside_statuses && ! in_array( 'sent', $up_outside_statuses, true ) && in_array( 'cancelled', $up_outside_statuses, true ) && ! in_array( $up_outside, $up_sent, true ),
		[ 'statuses' => $up_outside_statuses, 'sent_to' => $up_sent ]
	);
	$up_team          = 't402-upguardteam-' . $run . '@katz.co';
	$emails[]         = $up_team;
	$up_team_statuses = $up_walk( $up_team );
	t402_check( 'control: a team contact\'s upgrade emails are left alone', $up_team_statuses && ! in_array( 'cancelled', $up_team_statuses, true ), [ 'statuses' => $up_team_statuses ] );
	remove_filter( 'fluent_crm/is_simulated_mail', $up_capture, 1 );
	null === $guard_up_backup ? delete_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION ) : update_option( \CustomCRM\AbandonCart\Edd\AllowedDomains::OPTION, $guard_up_backup, true );

	$gate_triggers = new ReflectionClassConstant( \CustomCRM\Email\CollisionGate::class, 'CART_TRIGGERS' );
	t402_check( 'collision gate holds onboarding emails for the upgrade automation too', in_array( 'fc_ab_cart_simulation_edd_upgrade', (array) $gate_triggers->getValue(), true ) );

	// 14h. All Access: what the email says, checked against what SL does when the upgrade is bought.
	$without_recurring = function ( callable $buy ) use ( &$wp_filter ) {
		// EDD Recurring cancels subscriptions on this hook; keep it off gkclone's data.
		$detached = [];
		foreach ( $wp_filter['edd_sl_license_upgraded']->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && 'cancel_subscription_on_upgrade' === $cb['function'][1] ) {
					remove_action( 'edd_sl_license_upgraded', $cb['function'], $priority );
					$detached[] = [ $cb['function'], $priority, $cb['accepted_args'] ];
				}
			}
		}
		try {
			return $buy();
		} finally {
			foreach ( $detached as [ $fn, $priority, $args ] ) {
				add_action( 'edd_sl_license_upgraded', $fn, $priority, $args );
			}
		}
	};
	$aa_order_first = t402_order( t402_email( 'aaowner' ), $download_id, 1, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * HOUR_IN_SECONDS ) );
	$aa_license     = edd_software_licensing()->get_license_by_purchase( $aa_order_first, $download_id );
	if ( ! $aa_license ) {
		( new EDD_SL_License() )->create( $download_id, $aa_order_first, 1, 0 );
		$aa_license = edd_software_licensing()->get_license_by_purchase( $aa_order_first, $download_id );
	}
	$licenses[]             = (int) $aa_license->ID;
	$aa_license->expiration = current_time( 'timestamp' ) + 200 * DAY_IN_SECONDS;
	$aa_license             = edd_software_licensing()->get_license( $aa_license->ID );
	$aa_path                = edd_sl_get_upgrade_path( $download_id, $aa_id );
	$aa_price_id            = is_numeric( $aa_path['price_id'] ?? null ) ? (int) $aa_path['price_id'] : null;
	$aa_cart                = $make( 'aareal', 'processing', [ 'license_id' => (int) $aa_license->ID, 'upgrade_id' => $aa_id ] );
	$aa_sl_cost             = (float) edd_sl_get_license_upgrade_cost( (int) $aa_license->ID, $aa_id );
	$aa_rows                = $check_breakdown( 'All Access, time-based', $aa_cart, $plan_name( 808301, (int) $aa_price_id ) . ' until ' . date_i18n( $date_fmt, (int) $aa_license->expiration ), 'Credit for the unused time on ' . $current_label, $aa_sl_cost );
	$aa_renewal             = $code( $aa_cart, 'renewal_line' );
	$aa_expiry_before       = (int) $aa_license->expiration;

	t402_restore( $aa_cart, 'edd_upgrade' );
	$aa_checkout = (float) edd_get_cart_total();
	$aa_bought   = $without_recurring( function () use ( $aa_cart ) {
		return t402_order_from_cart( $aa_cart->email );
	} );
	$tracker->closeRenewedCarts();
	$reset_statics();
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	$aa_after       = edd_software_licensing()->get_license( $aa_license->ID );
	$aa_order_total = (float) edd_get_order( $aa_bought )->total;
	if ( $aa_after->is_lifetime ) {
		$aa_expected_line = 'Your new plan is a lifetime license, so there are no more renewals. You won’t be charged twice.';
	} elseif ( (int) $aa_after->expiration === $aa_expiry_before ) {
		$aa_expected_line = 'Your renewal date stays ' . date_i18n( $date_fmt, $aa_expiry_before ) . '. You won’t be charged twice.';
	} else {
		$aa_expected_line = 'Your license will renew on ' . date_i18n( $date_fmt, (int) $aa_after->expiration ) . '. You won’t be charged twice.';
	}
	t402_check( 'All Access: SL moved the license to All Access', 808301 === (int) $aa_after->download_id && $aa_price_id === (int) $aa_after->price_id, [ 'download' => $aa_after->download_id, 'price' => $aa_after->price_id ] );
	t402_check( 'All Access: checkout and the order charge line 3 of the email', 3 === count( $aa_rows ) && (int) round( $aa_checkout * 100 ) === $aa_rows[2]['cents'] && (int) round( $aa_order_total * 100 ) === $aa_rows[2]['cents'], [ 'checkout' => $aa_checkout, 'order' => $aa_order_total, 'email' => $aa_rows[2]['text'] ?? null ] );
	t402_check( 'All Access: renewal_line matches the expiration SL set', $aa_expected_line === $aa_renewal, [ 'email' => $aa_renewal, 'sl' => $aa_expected_line, 'before' => $aa_expiry_before, 'after' => (int) $aa_after->expiration, 'lifetime' => (bool) $aa_after->is_lifetime ] );
	$aa_evidence = [ 'renewal_line' => $aa_renewal, 'expiry_before' => date_i18n( $date_fmt, $aa_expiry_before ), 'expiry_after' => date_i18n( $date_fmt, (int) $aa_after->expiration ), 'charged' => $aa_order_total ];

	// 14i. A EUR cart: the email's price is what the recovered checkout charges in euros.
	unset( $_GET['currency'] );
	EDD()->session->set( 'currency', null );
	$eur_cart           = $make( 'eurcart', 'processing' );
	$eur_cart->currency = 'EUR';
	$eur_cart->save();
	$eur_today   = html_entity_decode( $code( $eur_cart, 'today_price' ), ENT_QUOTES, 'UTF-8' );
	$eur_rows    = $parse_breakdown( $code( $eur_cart, 'price_breakdown' ) );
	$store_after = edd_get_currency();
	$usd_after   = $code( $code_cart, 'today_price' );
	t402_restore( $eur_cart, 'edd_upgrade' );
	$eur_checkout_currency = edd_get_currency();
	$eur_checkout_total    = (float) edd_get_cart_total();
	$eur_checkout_text     = html_entity_decode( edd_currency_filter( edd_format_amount( $eur_checkout_total ), 'EUR' ), ENT_QUOTES, 'UTF-8' );
	edd_empty_cart();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	EDD()->session->set( 'currency', null );
	$store_back = edd_get_currency();
	$naive_eur  = round( (float) \EDD_Multi_Currency\Utils\Currency::convert( $today, 'EUR' ), 2 );
	$breakdown_examples['EUR cart, time-based'] = wp_list_pluck( $eur_rows, 'text' );
	t402_check( 'EUR: the email prices the upgrade in euros and the lines add up', 3 === count( $eur_rows ) && '€' === $eur_rows[2]['symbol'] && $eur_rows[0]['cents'] - $eur_rows[1]['cents'] === $eur_rows[2]['cents'], $eur_rows );
	t402_check(
		'EUR: the recovered checkout is in EUR and charges the email\'s today_price',
		'EUR' === $eur_checkout_currency && 3 === count( $eur_rows ) && (int) round( $eur_checkout_total * 100 ) === $eur_rows[2]['cents'] && $eur_today === $eur_checkout_text,
		[ 'email' => $eur_today, 'checkout' => $eur_checkout_text, 'checkout_currency' => $eur_checkout_currency, 'usd_cost_converted' => $naive_eur ]
	);
	t402_check( 'EUR: pricing a EUR email leaves the store currency and USD emails alone', 'USD' === $store_after && 'USD' === $store_back && $fmt( $today ) === $usd_after, [ $store_after, $store_back, $usd_after ] );
	$eur_evidence = [ 'email' => $eur_today, 'checkout' => $eur_checkout_text, 'usd_cost_converted' => $naive_eur ];

	// Where Multi Currency ignores ?currency= (wp-admin), the prices fall back to the selected
	// currency and say so, instead of USD amounts under a euro sign.
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	$previous_screen = $GLOBALS['current_screen'] ?? null;
	WP_Screen::get( 'dashboard' )->set_current_screen();
	$admin_context = is_admin();
	$admin_rows    = $parse_breakdown( $code( $eur_cart, 'price_breakdown' ) );
	$admin_today   = html_entity_decode( $code( $eur_cart, 'today_price' ), ENT_QUOTES, 'UTF-8' );
	$GLOBALS['current_screen'] = $previous_screen;
	t402_check(
		'EUR cart where ?currency= is ignored: priced and labelled in the store currency',
		$admin_context && ! is_admin() && 3 === count( $admin_rows ) && '$' === $admin_rows[2]['symbol'] && $admin_rows[0]['cents'] - $admin_rows[1]['cents'] === $admin_rows[2]['cents'] && html_entity_decode( $fmt( $today ), ENT_QUOTES, 'UTF-8' ) === $admin_today,
		[ 'admin' => $admin_context, 'rows' => wp_list_pluck( $admin_rows, 'text' ), 'today' => $admin_today ]
	);
	if ( getenv( 'T402_DUMP' ) ) {
		file_put_contents( '/tmp/t402-evidence.json', wp_json_encode( [ 'breakdowns' => $breakdown_examples, 'all_access' => $aa_evidence, 'eur' => $eur_evidence ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	// 14g. A real upgrade order through the recovery link: SL upgrades the license, and the linked cart
	// is recovered. Runs last: it moves the fixture license to the new plan.
	$sibling   = $make( 'realsibling', 'processing' );
	$real      = $make( 'realupgrade', 'processing' );
	$real_run  = $start_run( $real );
	$_COOKIE['fc_ab_edd_cart_token'] = $real->checkout_key;
	$recurring = [];
	foreach ( $wp_filter['edd_sl_license_upgraded']->callbacks ?? [] as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( is_array( $cb['function'] ) && 'cancel_subscription_on_upgrade' === $cb['function'][1] ) {
				remove_action( 'edd_sl_license_upgraded', $cb['function'], $priority );
				$recurring[] = [ $cb['function'], $priority, $cb['accepted_args'] ];
			}
		}
	}
	$real_order = t402_order( $real->email, $download_id, 2, null, [ 'is_upgrade' => true, 'upgrade_id' => $tier_id, 'license_id' => (int) $license->ID, 'cost' => $today ] );
	foreach ( $recurring as [ $fn, $priority, $args ] ) {
		add_action( 'edd_sl_license_upgraded', $fn, $priority, $args );
	}
	$tracker->closeRenewedCarts();
	$reset_statics();
	unset( $_COOKIE['fc_ab_edd_cart_token'] );
	$real        = AbandonCartModel::find( $real->id );
	$license_now = edd_software_licensing()->get_license( $license->ID );
	t402_check(
		'end to end: SL upgraded the license and the linked cart is recovered by its own order',
		2 === (int) $license_now->price_id && 'recovered' === $real->status && (int) $real->order_id === $real_order && 'cancelled' === $run_row( $real_run )->status && 'Cancelled because the contact upgraded' === $run_row( $real_run )->notes,
		[ 'license_price' => $license_now->price_id, 'status' => $real->status, 'order' => $real->order_id, 'expected_order' => $real_order, 'run' => $run_row( $real_run ) ]
	);
	t402_check( 'end to end: the same-term upgrade kept the renewal date', (int) $license_now->expiration === (int) $license->expiration, [ (int) $license_now->expiration, (int) $license->expiration ] );
	$sibling = AbandonCartModel::find( $sibling->id );
	t402_check( 'end to end: another running upgrade cart for the license is recovered as upgraded elsewhere', 'recovered' === $sibling->status && 'Upgraded outside the recovery link' === $sibling->note, [ $sibling->status, $sibling->note ] );
} catch ( Throwable $e ) {
	t402_check( 'no exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	edd_empty_cart();
	edd_clear_errors();
	unset( $_COOKIE['fc_ab_edd_cart_token'], $_GET['currency'] );
	EDD()->session->set( 'currency', null );
	foreach ( $wpdb->get_col( "SELECT id FROM {$p}edd_adjustments WHERE type = 'discount' AND code LIKE 'T402%'" ) as $discount_id ) {
		edd_delete_discount( (int) $discount_id );
	}
	foreach ( [ 'renewed_licenses', 'upgraded_licenses' ] as $static ) {
		$prop = new ReflectionProperty( EddCartTracking::class, $static );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}
	if ( $ab_backup_set ) {
		null === $ab_backup ? delete_option( '_fc_ab_cart_settings' ) : update_option( '_fc_ab_cart_settings', $ab_backup );
		\FluentCrm\App\Modules\AbandonCart\AbCartHelper::getSettings( false );
	}
	// Licenses: the ones the checks made, plus any issued for a t402 order.
	$licenses = array_unique( array_merge(
		array_map( 'intval', $licenses ),
		array_map( 'intval', $wpdb->get_col( "SELECT l.id FROM {$p}edd_licenses l JOIN {$p}edd_orders o ON o.id = l.payment_id WHERE o.email LIKE 't402-%'" ) )
	) );
	foreach ( $licenses as $license_id ) {
		$license = edd_software_licensing()->get_license( $license_id );
		if ( $license ) {
			$license->delete();
		}
	}
	// Automations imported by the checks, with only the email campaigns their own steps point at.
	foreach ( array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}fc_funnels WHERE title LIKE 'T402 %'" ) ) as $funnel_id ) {
		$campaign_ids = [];
		foreach ( \FluentCrm\App\Models\FunnelSequence::where( 'funnel_id', $funnel_id )->where( 'action_name', 'send_custom_email' )->get() as $step ) {
			$campaign_ids[] = (int) ( $step->settings['reference_campaign'] ?? 0 );
		}
		$campaign_ids = array_values( array_filter( array_unique( $campaign_ids ) ) );
		if ( $campaign_ids ) {
			$wpdb->query( "DELETE FROM {$p}fc_campaign_emails WHERE campaign_id IN (" . implode( ',', $campaign_ids ) . ')' );
			$wpdb->query( "DELETE FROM {$p}fc_campaigns WHERE id IN (" . implode( ',', $campaign_ids ) . ')' );
		}
		foreach ( [ 'fc_funnel_sequences', 'fc_funnel_subscribers', 'fc_funnel_metrics' ] as $table ) {
			$wpdb->delete( $p . $table, [ 'funnel_id' => $funnel_id ] );
		}
		$wpdb->delete( "{$p}fc_meta", [ 'object_type' => 'FluentCrm\\App\\Models\\Funnel', 'object_id' => $funnel_id ] );
		$wpdb->delete( "{$p}fc_funnels", [ 'id' => $funnel_id ] );
	}
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
			$wpdb->query( "DELETE FROM {$p}fc_campaign_emails WHERE subscriber_id IN ($in)" );
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
		'carts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_abandoned_carts WHERE email LIKE 't402-%'" ),
		'contacts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email LIKE 't402-%'" ),
		'orders'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_orders WHERE email LIKE 't402-%'" ),
		'customers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_customers WHERE email LIKE 't402-%'" ),
		'licenses'  => $licenses ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_licenses WHERE id IN (" . implode( ',', $licenses ) . ')' ) : 0,
		'funnels'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_funnels WHERE title LIKE 'T402 %'" ),
		'discounts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}edd_adjustments WHERE type = 'discount' AND code LIKE 'T402%'" ),
		'emails'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_campaign_emails WHERE email_address LIKE 't402%'" ),
	];
	t402_check( 'cleanup left nothing', ! array_filter( $left ), $left );
}

$failed = array_filter( $results, function ( $r ) { return ! $r['pass']; } );
foreach ( $results as $r ) {
	echo ( $r['pass'] ? 'PASS ' : 'FAIL ' ) . $r['check'] . ( $r['pass'] ? '' : '  ' . wp_json_encode( $r['detail'] ) ) . "\n";
}
echo count( $results ) - count( $failed ) . '/' . count( $results ) . " passed\n";
echo 'outbound HTTP blocked: ' . wp_json_encode( array_count_values( $blocked_http ) ) . "\n";
