<?php
/**
 * Checks for FluentCRM open and click tracking on EDD emails (EddEmailTracking), against a local
 * store with EDD 3.3+, EDD Recurring and FluentCRM: a gkclone copy or a Siteminter site. Never run it
 * on production. Sends real EDD emails through EDD's own classes, catches them before they leave
 * (`pre_wp_mail`), and deletes every row, contact, order, subscription and campaign it made.
 *
 *   docker cp tests/gkclone/edd-email-tracking-checks.php <cli>:/tmp/
 *   docker exec <cli> wp eval-file /tmp/edd-email-tracking-checks.php            # tracking on
 *   docker exec <cli> wp eval-file /tmp/edd-email-tracking-checks.php off        # both settings off
 *   docker exec <cli> wp eval-file /tmp/edd-email-tracking-checks.php anonymous  # both anonymous
 *
 * Each mode needs its own process: FluentCRM reads its tracking settings once per request.
 */

use CustomCRM\Email\EddEmailTracking;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\Subscriber;

if ( false !== strpos( home_url(), 'gravitykit.com' ) ) {
	WP_CLI::error( 'Refusing to run against ' . home_url() . '. Use a local copy.' );
}

$mode = $args[0] ?? 'on';

if ( 'off' === $mode || 'anonymous' === $mode ) {
	$value = 'off' === $mode ? 'no' : 'anonymous';
	add_filter(
		'pre_option__fluentcrm_compliance_settings',
		static function () use ( $value ) {
			return [ 'email_open_tracking' => $value, 'email_click_tracking' => $value ];
		}
	);
}

$run     = substr( md5( microtime() ), 0, 6 );
$results = [];
$made    = [ 'orders' => [], 'customers' => [], 'contacts' => [], 'subs' => [], 'notices' => [], 'downloads' => [] ];

add_filter( 'pre_http_request', static function () { return new WP_Error( 'edd-track-checks', 'blocked' ); }, 1 );

// EDD reports some errors with wp_die(), which would end the run before the cleanup below.
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message ) {
			throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) ) );
		};
	}
);

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$results ) {
	$results[] = [ $name, $pass, $detail ];
};

// Every email is caught here, after the `wp_mail` filter and before anything is sent.
$outbox = [];
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$outbox ) {
		$outbox[] = $atts;
		return true;
	},
	10,
	2
);

// What each email looked like just before tracking, to prove untracked emails are left as they were.
$before = [];
add_filter(
	'wp_mail',
	static function ( $atts ) use ( &$before ) {
		$before[] = $atts['message'];
		return $atts;
	},
	999
);

$site = home_url( '/' );

// A link to track and a download link that must stay as it is, in every EDD email body.
add_filter(
	'edd_email_message',
	static function ( $message ) use ( $site ) {
		return str_replace(
			'</body>',
			'<p><a href="https://example.test/account/?ref=check">Your account</a> <a href="' . esc_url( $site . '?eddfile=123%3A45%3A0&ttl=1&token=abc' ) . '">Download</a></p></body>',
			$message
		);
	}
);

$stats = static function ( string $reason ) {
	$all = get_option( EddEmailTracking::STATS_OPTION, [] );
	return (int) ( $all[ $reason ]['count'] ?? 0 );
};

$rows_for = static function ( string $address ) {
	return CampaignEmail::where( 'email_address', $address )->orderBy( 'id' )->get();
};

$pixels = static function ( string $html ) {
	return preg_match_all( '/route=open/', $html );
};

$address_for = static function ( string $tag ) use ( $run ) {
	return "t-edd-track-{$tag}-{$run}@example.test";
};

$customer = static function ( string $address, bool $with_contact ) use ( &$made ) {
	$customer_id          = edd_add_customer( [ 'email' => $address, 'name' => 'Check Person' ] );
	$made['customers'][] = $customer_id;

	if ( $with_contact ) {
		$contact            = FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => $address, 'status' => 'subscribed' ] );
		$made['contacts'][] = (int) $contact->id;
	}

	return $customer_id;
};

$download_id         = wp_insert_post( [ 'post_type' => 'download', 'post_status' => 'publish', 'post_title' => "Check product {$run}" ] );
$made['downloads'][] = $download_id;
update_post_meta( $download_id, 'edd_price', '99.00' );

$order = static function ( string $address, int $customer_id ) use ( &$made, $download_id ) {
	$order_id = edd_add_order(
		[
			'status'      => 'complete',
			'email'       => $address,
			'customer_id' => $customer_id,
			'currency'    => 'USD',
			'subtotal'    => 99,
			'total'       => 99,
			'gateway'     => 'manual',
		]
	);
	edd_add_order_item(
		[
			'order_id'     => $order_id,
			'product_id'   => $download_id,
			'product_name' => 'Check product',
			'status'       => 'complete',
			'quantity'     => 1,
			'amount'       => 99,
			'subtotal'     => 99,
			'total'        => 99,
		]
	);
	$made['orders'][] = $order_id;

	return edd_get_order( $order_id );
};

$send_receipt = static function ( $order ) {
	return \EDD\Emails\Registry::get( 'order_receipt', [ $order ] )->send();
};

try {
	$tracking_on = 'on' === $mode;

	// A. Receipt to a contact.
	$address_a = $address_for( 'a' );
	$order_a   = $order( $address_a, $customer( $address_a, true ) );
	$outbox    = [];
	$before    = [];
	$send_receipt( $order_a );
	$rows = $rows_for( $address_a );
	$mail = $outbox[0]['message'] ?? '';

	if ( 'off' === $mode ) {
		$check( 'off: no row when both settings are off', 0 === count( $rows ), $rows->toArray() );
		$check( 'off: email left as EDD built it', $mail === ( $before[0] ?? null ) );
	} else {
		$row = $rows[0] ?? null;
		$check( 'one row for the receipt', 1 === count( $rows ), $rows->toArray() );
		$check(
			'row is sent, Custom Email, on the contact, with the subject',
			$row && 'sent' === $row->status && EddEmailTracking::CAMPAIGN_TYPE === $row->email_type
				&& (int) $row->subscriber_id === (int) Subscriber::where( 'email', $address_a )->value( 'id' )
				&& $row->email_subject === $outbox[0]['subject'],
			$row ? $row->toArray() : null
		);
		$check( 'exactly one pixel', 1 === $pixels( $mail ), substr_count( $mail, 'route=open' ) );
		$check(
			'pixel points at the row',
			$row && false !== strpos( $mail, '_e_hash=' . $row->email_hash ) && false !== strpos( $mail, '_e_id=' . $row->id )
				&& ( $tracking_on ? false === strpos( $mail, 'ano=1' ) : false !== strpos( $mail, 'ano=1' ) )
		);
		$check(
			'account link goes through FluentCRM with the row and token',
			$row && 1 === preg_match( '/href="[^"]*\?ns_url=[^"&]+&mid=' . $row->id . ( $tracking_on ? '' : '&ano=1' ) . '&fch=' . substr( $row->email_hash, 0, 8 ) . '"/', $mail )
				&& false === strpos( $mail, 'href="https://example.test/account/' ),
			$mail
		);
		$check( 'download link is not rewritten', false !== strpos( $mail, '?eddfile=123' ) && false === strpos( $mail, 'untracked' ) );

		$campaign = $row ? Campaign::withoutGlobalScopes()->find( $row->campaign_id ) : null;
		$check(
			'campaign is a published one-off named after the EDD email',
			$campaign && 'custom_email_campaign' === $campaign->type && 'published' === $campaign->status
				&& 0 === strpos( $campaign->title, 'EDD: ' ) && false !== strpos( $campaign->title, '[order_receipt]' )
				&& 'order_receipt' === ( $campaign->settings[ EddEmailTracking::SETTING_KEY ] ?? '' ),
			$campaign ? $campaign->toArray() : null
		);
		$check( 'campaign is not a FluentCRM campaign FluentCRM could send', $campaign && ! Campaign::find( $campaign->id ) );

		// B. A second receipt reuses the campaign.
		$order_b = $order( $address_a, (int) $order_a->customer_id );
		$send_receipt( $order_b );
		$rows = $rows_for( $address_a );
		$check(
			'second receipt: new row, same campaign',
			2 === count( $rows ) && (int) $rows[0]->campaign_id === (int) $rows[1]->campaign_id && $rows[0]->email_hash !== $rows[1]->email_hash,
			$rows->toArray()
		);
	}

	// C. No contact: nothing made, email untouched, counted.
	$address_c = $address_for( 'c' );
	$skipped   = $stats( 'no_contact' );
	$order_c   = $order( $address_c, $customer( $address_c, false ) );
	$outbox    = [];
	$before    = [];
	$send_receipt( $order_c );
	if ( 'off' !== $mode ) {
		$check( 'no contact: email left as EDD built it', ( $outbox[0]['message'] ?? '' ) === ( $before[0] ?? null ) );
		$check( 'no contact: no row and no contact created', 0 === count( $rows_for( $address_c ) ) && ! Subscriber::where( 'email', $address_c )->exists() );
		$check( 'no contact: counted', $stats( 'no_contact' ) === $skipped + 1 );
	}

	// D. Admin notice: untouched.
	$outbox = [];
	$before = [];
	$admin  = \EDD\Emails\Registry::get( 'admin_order_notice', [ $order_a ] );
	$admin->send_to = $address_a;
	$admin->send();
	$check( 'admin notice: untouched (or not sent)', ! $outbox || $outbox[0]['message'] === $before[0] );

	// D2. Account email (EDD marks verification `customer`, context `user`): untouched.
	if ( 'off' !== $mode && \EDD\Emails\Registry::is_registered( 'user_verification' ) ) {
		$user_id = wp_insert_user( [ 'user_login' => 't-edd-track-' . $run, 'user_email' => $address_a, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
		$account = $stats( 'account_email' );
		$outbox  = [];
		$before  = [];
		$rows_was = count( $rows_for( $address_a ) );
		\EDD\Emails\Registry::get( 'user_verification', [ $user_id ] )->send();
		$check(
			'account email: untouched, no row, counted',
			1 === count( $outbox ) && $outbox[0]['message'] === $before[0] && count( $rows_for( $address_a ) ) === $rows_was
				&& $stats( 'account_email' ) === $account + 1,
			[ count( $outbox ), $stats( 'account_email' ) - $account ]
		);
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}

	// E. FluentCRM's own email, or any email outside an EDD send: untouched.
	$outbox  = [];
	$fluent  = '<html><body><p><a href="https://example.test/x">x</a></p><img src="' . esc_url( $site . '?fluentcrm=1&route=open&_e_hash=abc&_e_id=1' ) . '"></body></html>';
	wp_mail( $address_a, 'FluentCRM email', $fluent, [ 'Content-Type: text/html' ] );
	$check( 'email outside an EDD send: untouched', ( $outbox[0]['message'] ?? '' ) === $fluent );

	if ( 'on' === $mode ) {
		// F. The wp_mail filter run twice in one send, as Gravity SMTP does: one row, one pixel.
		$address_f = $address_for( 'f' );
		$order_f   = $order( $address_f, $customer( $address_f, true ) );
		$again     = [];
		$repeat    = static function ( $short, $atts ) use ( &$again, &$before ) {
			$again[] = apply_filters( 'wp_mail', $atts )['message'];
			$again[] = apply_filters( 'wp_mail', array_merge( $atts, [ 'message' => $before[0] ] ) )['message'];
			return null;
		};
		add_filter( 'pre_wp_mail', $repeat, 5, 2 );
		$outbox = [];
		$before = [];
		$send_receipt( $order_f );
		remove_filter( 'pre_wp_mail', $repeat, 5 );
		$mail = $outbox[0]['message'] ?? '';
		$check(
			'filter run again: same tracked email, one row, one pixel',
			1 === count( $rows_for( $address_f ) ) && 1 === $pixels( $mail ) && [ $mail, $mail ] === $again,
			[ count( $rows_for( $address_f ) ), $pixels( $mail ) ]
		);

		// G. An error after the row is made: email sent as EDD built it, row removed.
		$address_g = $address_for( 'g' );
		$order_g   = $order( $address_g, $customer( $address_g, true ) );
		$errors    = $stats( 'error' );
		$boom      = static function () {
			throw new RuntimeException( 'check' );
		};
		add_filter( 'customcrm/edd_email_tracking/skip_url', $boom );
		$outbox = [];
		$before = [];
		$send_receipt( $order_g );
		remove_filter( 'customcrm/edd_email_tracking/skip_url', $boom );
		$check( 'error: email left as EDD built it', ( $outbox[0]['message'] ?? '' ) === ( $before[0] ?? null ) && 0 === $pixels( $outbox[0]['message'] ?? '' ) );
		$check( 'error: no row left behind, counted', 0 === count( $rows_for( $address_g ) ) && $stats( 'error' ) === $errors + 1 );

		// H. Renewal reminder sent the way EDD Recurring's cron sends it.
		if ( function_exists( 'edd_recurring' ) && class_exists( 'EDD_Subscription' ) ) {
			$address_h   = $address_for( 'h' );
			$customer_h  = $customer( $address_h, true );
			$order_h     = $order( $address_h, $customer_h );
			$sub         = new EDD_Subscription();
			$sub->create(
				[
					'customer_id'       => $customer_h,
					'period'            => 'year',
					'initial_amount'    => 99,
					'recurring_amount'  => 99,
					'bill_times'        => 0,
					'parent_payment_id' => $order_h->id,
					'product_id'        => $download_id,
					'created'           => gmdate( 'Y-m-d H:i:s', strtotime( '-50 weeks' ) ),
					'expiration'        => gmdate( 'Y-m-d H:i:s', strtotime( '+2 weeks' ) ),
					'status'            => 'active',
					'profile_id'        => 'check-' . $address_h,
				]
			);
			$made['subs'][] = $sub->id;

			$notice_row = edd_add_email(
				[
					'email_id'  => 'sub_check' . $run,
					'context'   => 'subscription',
					'sender'    => 'recurring',
					'recipient' => 'customer',
					'subject'   => 'Your subscription renews in 2 weeks',
					'heading'   => 'Renewal reminder',
					'content'   => '<p>Hi {name}, your subscription renews on {expiration}.</p>',
					'status'    => 1,
				]
			);
			edd_add_email_meta( $notice_row, 'type', 'renewal' );
			edd_add_email_meta( $notice_row, 'period', '+2weeks' );
			$made['notices'][] = $notice_row;

			// EDD Recurring registers its notices when the request starts.
			if ( ! \EDD\Emails\Registry::is_registered( 'sub_check' . $run ) ) {
				\EDD\Emails\Registry::register( 'sub_check' . $run, \EDD\Recurring\Emails\Types\Notices::class );
			}

			$outbox = [];
			edd_recurring()::$reminders->send( new EDD_Subscription( $sub->id ), edd_get_email_by( 'id', $notice_row ) );
			$rows = $rows_for( $address_h );
			$mail = $outbox[0]['message'] ?? '';
			$camp = isset( $rows[0] ) ? Campaign::withoutGlobalScopes()->find( $rows[0]->campaign_id ) : null;
			$check(
				'renewal reminder: tracked on its own campaign',
				1 === count( $rows ) && 1 === $pixels( $mail ) && $camp && false !== strpos( $camp->title, '[sub_check' . $run . ']' )
					&& ( false !== stripos( $camp->title, 'Two weeks before' ) || false !== strpos( $camp->title, 'renews in 2 weeks' ) ),
				$camp ? $camp->title : $rows->toArray()
			);
		}
	}
} catch ( \Throwable $e ) {
	$check( 'no exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	$map = get_option( EddEmailTracking::CAMPAIGNS_OPTION, [] );

	foreach ( array_merge( $made['contacts'] ) as $contact_id ) {
		CampaignEmail::where( 'subscriber_id', $contact_id )->delete();
	}
	if ( $made['contacts'] ) {
		FluentCrmApi( 'contacts' )->getInstance()->whereIn( 'id', $made['contacts'] )->delete();
	}
	foreach ( $made['notices'] as $notice_id ) {
		$notice = edd_get_email_by( 'id', $notice_id );
		if ( $notice && isset( $map[ $notice->email_id ] ) ) {
			Campaign::withoutGlobalScopes()->where( 'id', $map[ $notice->email_id ] )->delete();
			unset( $map[ $notice->email_id ] );
		}
		edd_delete_email( $notice_id );
	}
	update_option( EddEmailTracking::CAMPAIGNS_OPTION, $map, false );
	foreach ( $made['subs'] as $sub_id ) {
		( new EDD_Subscription( $sub_id ) )->delete();
	}
	foreach ( $made['orders'] as $order_id ) {
		edd_destroy_order( $order_id );
	}
	foreach ( $made['customers'] as $customer_id ) {
		edd_delete_customer( $customer_id );
	}
	foreach ( $made['downloads'] as $download_id ) {
		wp_delete_post( $download_id, true );
	}
}

$failed = 0;
foreach ( $results as [ $name, $pass, $detail ] ) {
	WP_CLI::log( ( $pass ? 'PASS ' : 'FAIL ' ) . "[{$mode}] {$name}" . ( $pass ? '' : ' :: ' . wp_json_encode( $detail ) ) );
	$failed += $pass ? 0 : 1;
}

WP_CLI::log( sprintf( '%d checks, %d failed', count( $results ), $failed ) );

if ( $failed ) {
	WP_CLI::halt( 1 );
}
