<?php
/**
 * Smart Link confirmation checks against a gkclone copy of the store: opening a link that adds a
 * tag changes nothing, and only the confirmation page's button does. Creates t-slc-* contacts, a
 * tag and two Smart Links, and deletes them. Blocks all outbound HTTP while it runs.
 *
 *   docker cp tests/gkclone/smart-link-confirm-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/smart-link-confirm-checks.php
 *
 * The requests are made in this process: the click through FluentCRM's own hooks, the page through
 * the REST route. So this does not cover the CDN, or REST logging out a visitor who sends no nonce.
 */

use CustomCRM\SmartLinkConfirm;
use FluentCampaign\App\Models\SmartLink;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;

$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
// staging.gravitykit.com counts: it is rebuilt from production every night.
$is_local = in_array( $host, [ 'localhost', '127.0.0.1', 'staging.gravitykit.com' ], true ) || '.smint.orb.local' === substr( $host, -16 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! $is_local ) {
	throw new RuntimeException( 'Smart Link confirmation checks require a disposable local WP-CLI site.' );
}
if ( ! class_exists( SmartLinkConfirm::class ) || ! class_exists( SmartLink::class ) || ! has_action( 'fluentcrm_smartlink_clicked' ) ) {
	throw new RuntimeException( 'Smart Links are not available: FluentCampaign Pro and this plugin must be active.' );
}

$run      = substr( md5( microtime() ), 0, 6 );
$results  = [];
$finished = false;
$button   = "t-slc-button-{$run}";
$no_form  = 'Go to GravityKit.com';
$target   = home_url( "/t-slc-target-{$run}/" );
$page_url = home_url( '/' . SmartLinkConfirm::PRETTY_PATH . '/' );
$link_ids = [];
$tag_id   = 0;
$fired    = [];

add_filter( 'pre_http_request', static function () { return new WP_Error( 't-slc', 'blocked' ); }, 1 );
add_action(
	'fluent_crm/smart_link_clicked_by_contact',
	static function ( $smart_link, $contact ) use ( &$fired ) {
		$fired[] = [ (int) $smart_link->id, $contact ? (int) $contact->id : 0 ];
	},
	10,
	2
);
// The test links get the fallback wording; this names its button so the page can be stopped there.
add_filter(
	'customcrm/smart_link_confirm_copy',
	static function ( $copy ) use ( $button ) {
		$copy['button'] = $button;

		return $copy;
	}
);

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$results ) {
	$results[] = [ $name, $pass, $detail ];
};

$cleanup = static function () use ( $run, &$link_ids, &$tag_id ) {
	global $wpdb;

	$ids = Subscriber::where( 'email', 'like', "t-slc-%-{$run}@gravitykit-t402.dev" )->pluck( 'id' )->toArray();
	foreach ( $ids as $id ) {
		$wpdb->delete( $wpdb->prefix . 'fc_subscriber_pivot', [ 'subscriber_id' => $id ] );
		$wpdb->delete( $wpdb->prefix . 'fc_subscriber_meta', [ 'subscriber_id' => $id ] );
	}
	if ( $ids ) {
		Subscriber::whereIn( 'id', $ids )->delete();
	}
	if ( $link_ids ) {
		SmartLink::whereIn( 'id', $link_ids )->delete();
	}
	if ( $tag_id ) {
		Tag::where( 'id', $tag_id )->delete();
	}
};

// A page that is not stopped below ends in exit, which skips the report and the cleanup.
register_shutdown_function(
	static function () use ( &$finished, $cleanup ) {
		if ( ! $finished ) {
			$cleanup();
			echo "FAIL the run ended early: a page or redirect exited before the checks could stop it\n";
		}
	}
);

/**
 * Runs one request and returns what it answered: a redirect (location and status), or a page
 * (status, the HTML up to its button or home link, and whether it has the button).
 */
$request = static function ( callable $act, string $method, array $get, array $post = [] ) use ( $button, $no_form ) {
	$answer = [ 'redirect' => '', 'status' => 0, 'html' => '', 'has_button' => false ];

	$on_redirect = static function ( $location, $status ) {
		throw new RuntimeException( 't-slc-redirect|' . $status . '|' . $location );
	};
	$on_status   = static function ( $header, $code ) use ( &$answer ) {
		$answer['status'] = (int) $code;

		return $header;
	};
	// The view prints the button label, or the home link's text, last. Stopping there keeps the HTML before it.
	$on_text = static function ( $safe_text, $text ) use ( $button, $no_form ) {
		if ( $button === $text || $no_form === $text ) {
			throw new RuntimeException( 't-slc-page|' . $text );
		}

		return $safe_text;
	};

	$_GET                      = $get;
	$_POST                     = $post;
	$_REQUEST                  = array_merge( $get, $post );
	$_SERVER['REQUEST_METHOD'] = $method;

	add_filter( 'wp_redirect', $on_redirect, -1000, 2 );
	add_filter( 'status_header', $on_status, 10, 2 );
	add_filter( 'esc_html', $on_text, 10, 2 );
	ob_start();

	try {
		$act();
	} catch ( RuntimeException $stop ) {
		$parts = explode( '|', $stop->getMessage(), 3 );

		if ( 't-slc-redirect' === $parts[0] ) {
			$answer['status']   = (int) $parts[1];
			$answer['redirect'] = $parts[2];
		} elseif ( 't-slc-page' === $parts[0] ) {
			$answer['has_button'] = $button === $parts[1];
		} else {
			throw $stop;
		}
	} finally {
		$answer['html'] = (string) ob_get_clean();
		remove_filter( 'wp_redirect', $on_redirect, -1000 );
		remove_filter( 'status_header', $on_status, 10 );
		remove_filter( 'esc_html', $on_text, 10 );
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
	}

	return $answer;
};

/** Opens a Smart Link directly, as `/?fluentcrm=1&route=smart_url&slug=…` does. */
$open_link = static function ( string $slug, array $extra = [] ) use ( $request ) {
	$get = [ 'fluentcrm' => '1', 'route' => 'smart_url', 'slug' => $slug ] + $extra;

	return $request(
		static function () use ( $slug ) {
			do_action( 'fluentcrm_smartlink_clicked', $slug );
		},
		'GET',
		$get
	);
};

/** A tracked email click, as FluentCRM's RedirectionHandler fires it once it knows the contact. */
$click_in_email = static function ( string $slug, $contact, array $extra = [] ) use ( $request ) {
	$get = [ 'fluentcrm' => '1', 'route' => 'uc', 'mid' => '1', 'ns_url' => 'abc', 'fch' => 'deadbeef' ] + $extra;

	return $request(
		static function () use ( $slug, $contact ) {
			do_action( 'fluentcrm_smartlink_clicked_direct', $slug, $contact, null );
		},
		'GET',
		$get
	);
};

/** Requests the confirmation page at the address a redirect gave. */
$open_page = static function ( string $url, string $method = 'GET', array $post = [] ) use ( $request ) {
	$get = [];
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $get );
	unset( $get['rest_route'] );

	return $request(
		static function () use ( $method ) {
			rest_do_request( new WP_REST_Request( $method, '/' . SmartLinkConfirm::REST_NAMESPACE . SmartLinkConfirm::REST_ROUTE ) );
		},
		$method,
		$get,
		$post
	);
};

/** The hidden fields of a page's form. */
$fields_of = static function ( string $html ) {
	$fields = [];
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $found, PREG_SET_ORDER );
	foreach ( $found as $input ) {
		$fields[ $input[1] ] = html_entity_decode( $input[2], ENT_QUOTES );
	}

	return $fields;
};

$query_of = static function ( string $url ) {
	$query = [];
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

	return $query;
};

$has_tag = static function ( $contact ) use ( &$tag_id ) {
	return Subscriber::find( $contact->id )->hasAnyTagId( [ $tag_id ] );
};

$clicks = static function ( $smart_link ) {
	$row = SmartLink::find( $smart_link->id );

	return [ (int) $row->all_clicks, (int) $row->contact_clicks ];
};

$contact_for = static function ( string $name ) use ( $run ) {
	return FluentCrmApi( 'contacts' )->createOrUpdate( [ 'email' => "t-slc-{$name}-{$run}@gravitykit-t402.dev", 'status' => 'subscribed' ] );
};

try {
	$tag    = Tag::create( [ 'title' => "t-slc-{$run}", 'slug' => "t-slc-{$run}" ] );
	$tag_id = (int) $tag->id;

	$tagging    = SmartLink::create(
		[
			'title'      => "t-slc tagging {$run}",
			'target_url' => $target,
			'actions'    => [ 'tags' => [ $tag_id ], 'lists' => [], 'remove_tags' => [], 'remove_lists' => [], 'auto_login' => 'no' ],
		]
	);
	$plain      = SmartLink::create(
		[
			'title'      => "t-slc plain {$run}",
			'target_url' => $target,
			'actions'    => [ 'tags' => [], 'lists' => [], 'remove_tags' => [], 'remove_lists' => [], 'auto_login' => 'no' ],
		]
	);
	$link_ids   = [ (int) $tagging->id, (int) $plain->id ];
	$in_email   = $contact_for( 'email' );
	$in_browser = $contact_for( 'browser' );
	$bystander  = $contact_for( 'bystander' );

	// FluentCRM keeps the first contact it finds for the whole run, so the visitor nobody knows goes first.
	unset( $_COOKIE['fc_hash_secure'] );

	// A link with no actions still just redirects.
	$answer = $open_link( $plain->short, [ 'utm_source' => 'slc' ] );
	$check( 'a link with no actions redirects to its target (307)', 307 === $answer['status'] && 0 === strpos( $answer['redirect'], $target ), $answer );
	$check( 'and keeps the query parameters', [ 'utm_source' => 'slc' ] === $query_of( $answer['redirect'] ), $answer['redirect'] );
	$check( 'and counts the click', [ 1, 0 ] === $clicks( $plain ), $clicks( $plain ) );

	// A visitor nobody knows.
	$unknown = $open_link( $tagging->short );
	$check( 'an unknown visitor is sent to the page, not the target', 303 === $unknown['status'] && 0 === strpos( $unknown['redirect'], $page_url ), $unknown );
	$answer = $open_page( $unknown['redirect'] );
	$check( 'the page has no button for an unknown visitor', 200 === $answer['status'] && ! $answer['has_button'] && false !== strpos( $answer['html'], 'Nothing has changed yet' ), $answer );
	$answer = $open_page( $unknown['redirect'], 'POST', SmartLinkConfirm::formFields( $tagging->short, 0, time() - 60 ) );
	$check( 'a post for an unknown visitor redirects nowhere', '' === $answer['redirect'] && ! $answer['has_button'], $answer );

	// A tracked email click: the GET changes nothing and sends the reader to the page.
	$clicked = $click_in_email( $tagging->short, $in_email, [ 'utm_source' => 'slc', 'email' => 'a+b@example.com' ] );
	$query   = $query_of( $clicked['redirect'] );
	$check( 'opening a tag link redirects to the page (303)', 303 === $clicked['status'] && 0 === strpos( $clicked['redirect'], $page_url ), $clicked );
	$check( 'the page address names the link and the contact', $tagging->short === ( $query['slug'] ?? '' ) && (int) $in_email->id === (int) ( $query['c'] ?? 0 ), $query );
	$check( 'opening a tag link adds no tag', ! $has_tag( $in_email ), '' );
	$check( 'opening a tag link is not a contact click', 0 === $clicks( $tagging )[1], $clicks( $tagging ) );

	// The page: a GET shows the button and changes nothing.
	$page   = $open_page( $clicked['redirect'] );
	$fields = $fields_of( $page['html'] );
	$check( 'the page shows the button (200)', 200 === $page['status'] && $page['has_button'], $page );
	$check( 'the page masks the address', false !== strpos( $page['html'], 't•••@gravitykit-t402.dev' ) && false === strpos( $page['html'], $in_email->email ), $page['html'] );
	$check( 'the form carries its signed fields', isset( $fields['t'], $fields['s'] ), $fields );
	$check( 'opening the page adds no tag', ! $has_tag( $in_email ), '' );

	// Posts that are not the button.
	$answer = $open_page( $clicked['redirect'], 'POST' );
	$check( 'a bare POST adds no tag and shows the page again', ! $has_tag( $in_email ) && '' === $answer['redirect'] && $answer['has_button'], $answer );
	$answer = $open_page( $clicked['redirect'], 'POST', [ 't' => time() - 60, 's' => $fields['s'] ?? '' ] );
	$check( 'a POST with a changed time adds no tag', ! $has_tag( $in_email ) && '' === $answer['redirect'], $answer );
	$answer = $open_page( $clicked['redirect'], 'POST', SmartLinkConfirm::formFields( $tagging->short, (int) $bystander->id, time() - 60 ) );
	$check( 'a POST with another contact’s fields adds no tag', ! $has_tag( $in_email ) && '' === $answer['redirect'], $answer );

	// A bad key is refused, for a GET and a POST.
	$bad_key = add_query_arg( 'k', str_repeat( '0', 32 ), $clicked['redirect'] );
	$answer  = $open_page( $bad_key );
	$check( 'a bad key gets the expired page (404)', 404 === $answer['status'] && ! $answer['has_button'], $answer );
	$answer = $open_page( $bad_key, 'POST', SmartLinkConfirm::formFields( $tagging->short, (int) $in_email->id, time() - 60 ) );
	$check( 'a POST with a bad key adds no tag', 404 === $answer['status'] && ! $has_tag( $in_email ), $answer );
	$swapped = add_query_arg( 'c', (int) $bystander->id, $clicked['redirect'] );
	$answer  = $open_page( $swapped, 'POST', SmartLinkConfirm::formFields( $tagging->short, (int) $bystander->id, time() - 60 ) );
	$check( 'one contact’s key does not work for another', 404 === $answer['status'] && ! $has_tag( $bystander ), $answer );
	$answer = $open_page( add_query_arg( 'slug', $plain->short, $clicked['redirect'] ) );
	$check( 'one link’s key does not work for another', 404 === $answer['status'], $answer );

	// A direct link in a browser FluentCRM knows by its cookie.
	$_COOKIE['fc_hash_secure'] = fluentCrmGetContactSecureHash( $in_browser->id );
	$known                     = $open_link( $tagging->short );
	$check( 'a direct link redirects everyone to the same address', 303 === $known['status'] && $known['redirect'] === $unknown['redirect'], [ $known, $unknown ] );
	$check( 'that address names no contact', false === strpos( $known['redirect'], 'c=' . (int) $in_browser->id ) && 0 === (int) ( $query_of( $known['redirect'] )['c'] ?? -1 ), $known['redirect'] );
	$browser_page   = $open_page( $known['redirect'] );
	$browser_fields = $fields_of( $browser_page['html'] );
	$check( 'the page finds the contact from the cookie', $browser_page['has_button'] && isset( $browser_fields['s'] ), $browser_page );
	$check( 'opening it adds no tag', ! $has_tag( $in_browser ), '' );
	$answer = $open_page( $known['redirect'], 'POST', $fields );
	$check( 'the email contact’s fields do not work for the cookie contact', ! $has_tag( $in_browser ) && '' === $answer['redirect'], $answer );

	// Pressing the button.
	$fired  = [];
	$answer = $open_page( $clicked['redirect'], 'POST', $fields );
	$query  = $query_of( $answer['redirect'] );
	$check( 'pressing the button redirects to the target (303)', 303 === $answer['status'] && 0 === strpos( $answer['redirect'], $target ), $answer );
	$check( 'pressing the button adds the tag', $has_tag( $in_email ), '' );
	$check( 'the target gets the forwarded parameters, whole', [ 'utm_source' => 'slc', 'email' => 'a+b@example.com' ] === $query, $answer['redirect'] );
	$check( 'the press is the contact click', 1 === $clicks( $tagging )[1], $clicks( $tagging ) );
	$check( 'FluentCRM’s clicked-by-contact action fires once, on the press', [ [ (int) $tagging->id, (int) $in_email->id ] ] === $fired, $fired );
	$check( 'nobody else was tagged', ! $has_tag( $bystander ) && ! $has_tag( $in_browser ), '' );

	$answer = $open_page( $known['redirect'], 'POST', $browser_fields );
	$check( 'the cookie contact’s press adds the tag and redirects (303)', 303 === $answer['status'] && 0 === strpos( $answer['redirect'], $target ) && $has_tag( $in_browser ), $answer );

	// FluentCRM's own parameters written into the page address do not reach the target.
	$crafted = add_query_arg( 'fw', rawurlencode( 'fluentcrm=1&route=unsubscribe&slug=x&keep=1' ), remove_query_arg( 'fw', $clicked['redirect'] ) );
	$answer  = $open_page( $crafted, 'POST', SmartLinkConfirm::formFields( $tagging->short, (int) $in_email->id, time() - 60 ) );
	$check( 'FluentCRM’s own parameters are not forwarded', [ 'keep' => '1' ] === $query_of( $answer['redirect'] ), $answer['redirect'] );

	// A link with no actions, for a known contact.
	$fired  = [];
	$answer = $open_link( $plain->short );
	$check( 'a link with no actions still redirects a known contact (307)', 307 === $answer['status'] && 0 === strpos( $answer['redirect'], $target ), $answer );
	$check( 'and counts the contact click', [ 2, 1 ] === $clicks( $plain ), $clicks( $plain ) );
	$check( 'and fires clicked-by-contact', [ [ (int) $plain->id, (int) $in_browser->id ] ] === $fired, $fired );

	$routes = rest_get_server()->get_routes();
	$route  = $routes[ '/' . SmartLinkConfirm::REST_NAMESPACE . SmartLinkConfirm::REST_ROUTE ][0]['methods'] ?? [];
	$check( 'the page answers GET and POST only', [ 'GET', 'POST' ] === array_keys( array_filter( $route ) ), $route );
} catch ( Throwable $e ) {
	$check( 'run finished', false, $e->getMessage() . ' @' . $e->getLine() );
} finally {
	unset( $_COOKIE['fc_hash_secure'] );
	$cleanup();
	$finished = true;
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
