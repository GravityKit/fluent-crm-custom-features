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

	// 13. The new-purchase automation is 3 emails, with the code on day 4.
	$funnel_json = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/fluent-crm-custom-features/funnels/edd-abandoned-cart.json' ), true );
	$steps       = array_map( function ( $s ) { return $s['action_name'] . ':' . ( $s['settings']['wait_time_amount'] ?? '' ) . ( $s['settings']['wait_time_unit'] ?? '' ); }, (array) ( $funnel_json['sequences'] ?? [] ) );
	t402_check( 'automation JSON: wait 15m, email, wait 24h, email, wait 3d, email', [ 'fluentcrm_wait_times:15minutes', 'send_custom_email:', 'fluentcrm_wait_times:1425minutes', 'send_custom_email:', 'fluentcrm_wait_times:3days', 'send_custom_email:' ] === $steps, $steps );
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
