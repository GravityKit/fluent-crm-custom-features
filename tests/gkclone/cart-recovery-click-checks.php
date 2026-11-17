<?php
/**
 * Recovery-link click recording checks (CartRecoveryClick). Needs FluentCRM's abandoned cart
 * module with the EDD provider on. Creates tclk-* fixtures and deletes them; blocks outbound HTTP.
 *
 *   docker cp tests/gkclone/cart-recovery-click-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/cart-recovery-click-checks.php
 */

use CustomCRM\AbandonCart\Edd\CartRecoveryClick;
use CustomCRM\AbandonCart\Edd\EddCartDriver;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

global $wpdb;
$p       = $wpdb->prefix;
$run     = substr( md5( microtime() ), 0, 6 );
$results = [];
$cleanup = [];

add_filter( 'pre_http_request', static function () { return new WP_Error( 'tclk', 'blocked' ); }, 1 );

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$results ) {
	$results[] = [ $name, $pass, $detail ];
};

$browser = [
	'REQUEST_METHOD'       => 'GET',
	'HTTP_USER_AGENT'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
	'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
];

// Bot detection.
$check( 'browser is a person', '' === CartRecoveryClick::botReason( $browser ) );
$check( 'HEAD is a bot', 'head_request' === CartRecoveryClick::botReason( [ 'REQUEST_METHOD' => 'HEAD' ] + $browser ) );
$check( 'no user agent is a bot', 'no_user_agent' === CartRecoveryClick::botReason( [ 'HTTP_USER_AGENT' => '' ] + $browser ) );
$check( 'scanner user agent is a bot', 'user_agent' === CartRecoveryClick::botReason( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Barracuda Sentinel)' ] + $browser ) );
$check( 'Office link check is a bot', 'user_agent' === CartRecoveryClick::botReason( [ 'HTTP_USER_AGENT' => 'Microsoft Office Existence Discovery' ] + $browser ) );
$check( 'no Accept-Language is a bot', 'no_accept_language' === CartRecoveryClick::botReason( [ 'HTTP_ACCEPT_LANGUAGE' => '' ] + $browser ) );
$check( 'visit 3s after send is a bot', 'too_fast' === CartRecoveryClick::botReason( $browser, current_time( 'mysql' ) ) );
$check( 'visit an hour after send is a person', '' === CartRecoveryClick::botReason( $browser, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ) ) );

// Link parsing.
$check( 'restore link key', 'abc123' === CartRecoveryClick::cartKeyFromUrl( 'https://x.test?fluentcrm=1&amp;route=general&amp;handler=fc_cart_edd_upgrade&amp;fc_ab_hash=abc123' ) );
$check( 'stop link is not a restore link', '' === CartRecoveryClick::cartKeyFromUrl( 'https://x.test/wp-json/gk-cart/v1/stop?gk_cart_stop=1&key=abc' ) );
$check( 'cart types', 'new' === CartRecoveryClick::cartType( 'edd' ) && 'renewal' === CartRecoveryClick::cartType( 'edd_renewal' ) && 'upgrade' === CartRecoveryClick::cartType( 'edd_upgrade' ) );

// Fixtures: contact, cart, automation run, campaign, sent email.
$contact = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => "tclk-{$run}@gravitykit-t402.dev", 'status' => 'subscribed' ] );
$key     = md5( 'tclk' . $run );
$cart    = AbandonCartModel::create(
	[
		'checkout_key' => $key,
		'provider'     => EddCartDriver::PROVIDER,
		'email'        => $contact->email,
		'contact_id'   => $contact->id,
		'status'       => 'processing',
		'cart'         => [ 'cart_contents' => [] ],
		'currency'     => 'USD',
	]
);
$key     = (string) AbandonCartModel::find( $cart->id )->checkout_key; // The model may assign its own key.
$wpdb->insert( "{$p}fc_funnels", [ 'title' => "tclk {$run}", 'trigger_name' => ( new EddCartDriver() )->getTriggerName(), 'status' => 'draft', 'type' => 'funnels', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
$funnel_id = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}fc_campaigns", [ 'title' => "tclk {$run}", 'slug' => "tclk-{$run}", 'type' => 'funnel_email_campaign', 'parent_id' => $funnel_id, 'status' => 'published', 'email_body' => '', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
$campaign_id = (int) $wpdb->insert_id;
FunnelSubscriber::create( [ 'funnel_id' => $funnel_id, 'subscriber_id' => $contact->id, 'source_ref_id' => $cart->id, 'source_trigger_name' => ( new EddCartDriver() )->getTriggerName(), 'status' => 'active' ] );
$wpdb->insert( "{$p}fc_campaign_emails", [ 'campaign_id' => $campaign_id, 'subscriber_id' => $contact->id, 'email_address' => $contact->email, 'status' => 'sent', 'email_type' => 'funnel_email_campaign', 'email_hash' => wp_generate_uuid4(), 'scheduled_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
$email_id = (int) $wpdb->insert_id;

$metrics = static function () use ( $wpdb, $p, $campaign_id, $contact ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT m.counter, u.url FROM {$p}fc_campaign_url_metrics m JOIN {$p}fc_url_stores u ON u.id = m.url_id WHERE m.type = 'click' AND m.campaign_id = %d AND m.subscriber_id = %d", $campaign_id, $contact->id ) );
};

try {
	// 1. A person opens the bare link.
	$_SERVER = $browser + $_SERVER;
	$click   = CartRecoveryClick::record( AbandonCartModel::find( $cart->id ), [ 'fc_ab_hash' => $key ] );
	$check( 'direct: click stored', ! empty( $click['click_id'] ) && 'direct' === $click['via'] && ! $click['is_bot'], $click );
	$check( 'direct: email step found', $email_id === ( $click['email_id'] ?? 0 ) && $campaign_id === ( $click['campaign_id'] ?? 0 ) );
	$check( 'direct: cart type', 'new' === ( $click['cart_type'] ?? '' ) );
	$m = $metrics();
	$check( 'direct: FluentCRM click row written for the restore link', 1 === count( $m ) && false !== strpos( $m[0]->url, 'fc_ab_hash=' . $key ), $m );
	$check( 'direct: email click counter', 1 === (int) CampaignEmail::find( $email_id )->click_counter );
	$check( 'direct: checkout URL gets the click ID', false !== strpos( CustomCRM\AbandonCart\Edd\EddCartTracking::emailCheckoutUrl( AbandonCartModel::find( $cart->id ) ), 'fc_ab_click=' . $click['click_id'] ) );

	// PostHog: queued through the theme's sender when the theme is loaded.
	if ( function_exists( '\\GravityKit\\Store\\PostHogPurchases\\event_body' ) ) {
		$queued = array_filter(
			(array) _get_cron_array(),
			static function ( $hooks ) use ( $click ) {
				foreach ( (array) ( $hooks['gk_posthog_send_started'] ?? [] ) as $job ) {
					$event = $job['args'][0] ?? [];
					if ( 'cart_recovery_click' === ( $event['event'] ?? '' ) && $click['click_id'] === ( $event['uuid'] ?? '' ) ) {
						return true;
					}
				}
				return false;
			}
		);
		$check( 'direct: PostHog event queued', (bool) $queued );
		foreach ( $queued as $time => $hooks ) {
			foreach ( $hooks['gk_posthog_send_started'] as $job ) {
				if ( $click['click_id'] === ( $job['args'][0]['uuid'] ?? '' ) ) {
					wp_unschedule_event( $time, 'gk_posthog_send_started', $job['args'] );
				}
			}
		}
	}

	// 2. A scanner opens it.
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; proofpoint url defense)';
	$bot                         = CartRecoveryClick::record( AbandonCartModel::find( $cart->id ), [] );
	$check( 'bot: stored and marked', ! empty( $bot['is_bot'] ) && 'user_agent' === $bot['bot_reason'] );
	$check( 'bot: no FluentCRM click added', 1 === (int) $metrics()[0]->counter );
	$_SERVER = $browser + $_SERVER;

	// 3. A click through FluentCRM's tracker: the redirect gets a signed email ID.
	$restore = add_query_arg( [ 'fluentcrm' => 1, 'route' => 'general', 'handler' => 'fc_cart_edd', 'fc_ab_hash' => $key ], home_url() );
	remove_all_filters( 'wp_redirect' ); // WP-CLI's own handler warns on any redirect.
	CartRecoveryClick::markTrackedClick( CampaignEmail::find( $email_id ), (object) [ 'url' => $restore ] );
	$location = apply_filters( 'wp_redirect', $restore, 307 );
	parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
	$check( 'tracked: redirect gets fc_ab_mid', (string) $email_id === ( $q['fc_ab_mid'] ?? '' ) && ! empty( $q['fc_ab_sig'] ), $location );
	$tracked = CartRecoveryClick::record( AbandonCartModel::find( $cart->id ), $q );
	$check( 'tracked: via tracked', 'tracked' === ( $tracked['via'] ?? '' ) && $email_id === $tracked['email_id'] );
	$check( 'tracked: FluentCRM row not double-counted', 1 === (int) $metrics()[0]->counter );

	// 4. A forged signature is a direct visit.
	$forged = CartRecoveryClick::record( AbandonCartModel::find( $cart->id ), [ 'fc_ab_mid' => $email_id, 'fc_ab_sig' => 'nope' ] );
	$check( 'forged: treated as direct', 'direct' === ( $forged['via'] ?? '' ) );

	// 5. A closed cart's visit is still recorded.
	AbandonCartModel::where( 'id', $cart->id )->update( [ 'status' => 'recovered' ] );
	$closed = CartRecoveryClick::record( AbandonCartModel::find( $cart->id ), [] );
	$check( 'closed cart: recorded', 'recovered' === ( $closed['cart_status'] ?? '' ) );

	$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CartRecoveryClick::tableName() . ' WHERE cart_id = %d', $cart->id ) );
	$check( 'five rows in customcrm_cart_clicks', 5 === $rows, $rows );

	CartRecoveryClick::forgetContacts( [ $contact->id ] );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CartRecoveryClick::tableName() . ' WHERE subscriber_id = %d', $contact->id ) );
	$check( 'deleting the contact deletes its clicks', 0 === $left );
} finally {
	$wpdb->delete( "{$p}fc_campaign_url_metrics", [ 'campaign_id' => $campaign_id ] );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}fc_url_stores WHERE url LIKE %s", '%fc_ab_hash=' . $key . '%' ) );
	$wpdb->delete( "{$p}fc_campaign_emails", [ 'campaign_id' => $campaign_id ] );
	$wpdb->delete( "{$p}fc_campaigns", [ 'id' => $campaign_id ] );
	$wpdb->delete( "{$p}fc_funnel_subscribers", [ 'funnel_id' => $funnel_id ] );
	$wpdb->delete( "{$p}fc_funnels", [ 'id' => $funnel_id ] );
	$wpdb->delete( "{$p}fc_abandoned_carts", [ 'id' => $cart->id ] );
	$wpdb->delete( CartRecoveryClick::tableName(), [ 'cart_id' => $cart->id ] );
	$contact->delete();
}

$failed = 0;
foreach ( $results as [ $name, $pass, $detail ] ) {
	$failed += $pass ? 0 : 1;
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( $pass || '' === $detail ? '' : ' :: ' . wp_json_encode( $detail ) ) . "\n";
}
echo sprintf( "\n%d checks, %d failed\n", count( $results ), $failed );
if ( $failed ) {
	exit( 1 );
}
