<?php
/**
 * Local-time sending checks against a gkclone copy of the store (FluentCRM 3.2).
 *
 * The two plugin tables are created first (CREATE TABLE commits, so it cannot run inside the
 * transaction); everything else runs inside one transaction that is rolled back. The script then
 * counts what it created by its marker, deletes anything left, and drops the two tables and their
 * version options when it created them. Outbound HTTP is blocked and wp_mail() is answered by
 * `pre_wp_mail`, so no email is sent.
 *
 *   docker cp tests/gkclone/local-time-checks.php <cli>:/tmp/ && docker exec <cli> wp eval-file /tmp/local-time-checks.php
 *
 * When the clone's copy of this plugin predates the LocalTime classes, also copy them in
 * (`docker cp classes/LocalTime <cli>:/tmp/LocalTime`); they are loaded from there.
 *
 * Set LT_SCALE=0 to skip the 20,000-contact run; LT_KEEP_TABLES=1 to leave the two tables.
 */

use CustomCRM\LocalTime\AssignmentLog;
use CustomCRM\LocalTime\BrowserTimezone;
use CustomCRM\LocalTime\ClickLog;
use CustomCRM\LocalTime\LocalTimePanel;
use CustomCRM\LocalTime\LocalTimeSending;
use CustomCRM\LocalTime\SendTime;
use CustomCRM\LocalTime\Spreader;
use FluentCrm\App\Hooks\Handlers\RedirectionHandler;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\UrlStores;
use FluentCrm\App\Services\CampaignProcessor;
use FluentCrm\App\Services\Helper;

global $wpdb, $lt_results, $lt_mail;
$lt_results = [];
$lt_mail    = [];
$run        = substr( md5( microtime() ), 0, 6 );
$marker     = "lt-check-{$run}";
$p          = $wpdb->prefix;

if ( ! class_exists( SendTime::class ) ) {
	foreach ( [ 'SendTime', 'AssignmentLog', 'Spreader', 'LocalTimeSending', 'LocalTimePanel', 'BrowserTimezone', 'ClickLog' ] as $class ) {
		require_once "/tmp/LocalTime/{$class}.php";
	}
}

function lt_check( string $name, bool $pass, $detail = '' ) {
	global $lt_results;
	$lt_results[] = [ 'check' => $name, 'pass' => $pass, 'detail' => is_string( $detail ) ? $detail : wp_json_encode( $detail ) ];
}

function lt_at( string $time, string $zone ): DateTimeImmutable {
	return new DateTimeImmutable( $time, new DateTimeZone( $zone ) );
}

function lt_call( $object, string $method, ...$args ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );

	return $reflection->invoke( $object, ...$args );
}

/*
 * Time math.
 */

$not_before = lt_at( '2026-11-05 12:00', 'America/Denver' );

$cases = [
	[ 'America/New_York', 9 * 60, '2026-11-06 09:00 -05:00', 'the slot time on the delivery date' ],
	[ 'Australia/Sydney', 9 * 60, '2026-11-06 09:00 +11:00', 'a zone ahead of the site keeps its own date' ],
	[ 'Pacific/Auckland', 7 * 60, '2026-11-05 12:00 -07:00', 'a slot before the campaign start goes out at the start' ],
];

foreach ( $cases as [ $zone, $slot, $expected, $why ] ) {
	$got = SendTime::sendOn( '2026-11-06', new DateTimeZone( $zone ), $slot, $not_before )->format( 'Y-m-d H:i P' );
	lt_check( "sendOn {$zone}: {$why}", $expected === $got, $got );
}

// US clocks go forward on Sunday 2026-03-08.
$got = SendTime::sendOn( '2026-03-08', new DateTimeZone( 'America/New_York' ), 9 * 60, lt_at( '2026-03-07 12:00', 'America/Denver' ) )->format( 'Y-m-d H:i P' );
lt_check( 'sendOn on a DST change day keeps the local hour', '2026-03-08 09:00 -04:00' === $got, $got );

$got = SendTime::firstWindowOpens( '2026-11-06', 8 * 60 )->setTimezone( new DateTimeZone( 'America/Denver' ) )->format( 'Y-m-d H:i' );
lt_check( 'firstWindowOpens: 8:00 Friday first arrives Thursday 11:00 Mountain', '2026-11-05 11:00' === $got, $got );

$zones    = DateTimeZone::listIdentifiers();
$failures = [];

for ( $i = 0; $i < 2000; $i++ ) {
	$date  = gmdate( 'Y-m-d', wp_rand( 1735689600, 1830297600 ) );
	$zone  = new DateTimeZone( $zones[ array_rand( $zones ) ] );
	$slot  = SendTime::slotMinutes( wp_rand( 1, 99999 ), wp_rand( 1, 999999 ), 8 * 60, 11 * 60 );
	$first = SendTime::firstWindowOpens( $date, 8 * 60 );
	$send  = SendTime::sendOn( $date, $zone, $slot, $first );

	$local_minutes = (int) $send->format( 'G' ) * 60 + (int) $send->format( 'i' );

	if ( $send < $first || $send->format( 'Y-m-d' ) !== $date || $local_minutes !== $slot ) {
		$failures[] = [ $date, $zone->getName(), $slot, $send->format( 'c' ) ];
	}
}

lt_check( 'sendOn for 2,000 random dates and zones: on the date, at the slot, never before the window first opens', ! $failures, array_slice( $failures, 0, 3 ) );

$fallback = new DateTimeZone( 'America/Denver' );
$resolve  = [
	[ 'Asia/Calcutta', null, null, 'Asia/Calcutta', 'contact', 'an older alias is still a zone' ],
	[ 'Mars/Base', 'US', null, 'America/New_York', 'country', 'an unknown zone with no state falls back to the country' ],
	[ '', 'us', 'CA', 'America/Los_Angeles', 'state', 'a US state code, lower-case country' ],
	[ '', 'US', 'california', 'America/Los_Angeles', 'state', 'a US state name, any case' ],
	[ '', 'US', 'New  York ', 'America/New_York', 'state', 'a state name with extra spaces' ],
	[ '', 'US', 'Narnia', 'America/New_York', 'country', 'an unknown state uses the country zone' ],
	[ '', 'CA', 'BC', 'America/Vancouver', 'state', 'a Canadian province code' ],
	[ '', 'CA', '', 'America/Toronto', 'country', 'Canada without a province' ],
	[ '', 'AU', 'Western Australia', 'Australia/Perth', 'state', 'an Australian state name' ],
	[ '', 'AU', null, 'Australia/Sydney', 'country', 'Australia without a state' ],
	[ '', 'BR', null, 'America/Sao_Paulo', 'country', 'Brazil' ],
	[ '', 'RU', null, 'Europe/Moscow', 'country', 'Russia' ],
	[ '', 'MX', null, 'America/Mexico_City', 'country', 'Mexico' ],
	[ '', 'es', null, 'Europe/Madrid', 'country', 'a multi-zone country uses the mapped zone, case-insensitively' ],
	[ '', 'FR', 'CA', 'Europe/Paris', 'country', 'a state is ignored outside US, CA and AU' ],
	[ '', 'ZZ', null, 'America/Denver', 'site', 'an unknown country falls back to the site zone' ],
	[ null, null, null, 'America/Denver', 'site', 'nothing known falls back to the site zone' ],
];

$country_names = SendTime::countryNameMap( apply_filters( 'fluent_crm/countries', [] ) );
$resolve       = array_merge( $resolve, [
	[ '', 'USA', null, 'America/New_York', 'country', 'USA is the United States' ],
	[ '', 'U.S.A.', 'Texas', 'America/Chicago', 'state', 'U.S.A. with a state name still uses the state' ],
	[ '', 'United States', 'CA', 'America/Los_Angeles', 'state', 'the full country name with a state code' ],
	[ '', 'United Kingdom', null, 'Europe/London', 'country', 'the United Kingdom by name' ],
	[ '', 'Germany', null, 'Europe/Berlin', 'country', 'a country name from the FluentCRM list' ],
	[ '', 'Narnia', null, 'America/Denver', 'site', 'an unknown country name falls back to the site zone' ],
] );

foreach ( $resolve as [ $zone, $country, $state, $expected, $source, $why ] ) {
	$got = SendTime::resolveZone( $zone, $country, $state, $fallback, $country_names );
	lt_check( "resolveZone: {$why}", $expected === $got['zone']->getName() && $source === $got['source'], [ $got['zone']->getName(), $got['source'] ] );
}

lt_check( 'the FluentCRM country list is available to name countries', isset( $country_names['germany'] ) && 'DE' === $country_names['germany'], count( $country_names ) );

$slots = [];
for ( $id = 1; $id <= 1000; $id++ ) {
	$slots[] = SendTime::slotMinutes( 42, $id, 8 * 60, 11 * 60 );
}
lt_check(
	'slotMinutes stays in 8:00-11:00 and spreads across it',
	min( $slots ) >= 480 && max( $slots ) < 660 && min( $slots ) < 490 && max( $slots ) > 650,
	[ min( $slots ), max( $slots ) ]
);
lt_check( 'slotMinutes is the same on every call', SendTime::slotMinutes( 42, 7, 480, 660 ) === SendTime::slotMinutes( 42, 7, 480, 660 ) );
lt_check( 'slotMinutes respects a custom window', SendTime::slotMinutes( 42, 7, 9 * 60, 10 * 60 ) >= 540 && SendTime::slotMinutes( 42, 7, 9 * 60, 10 * 60 ) < 600 );

$held = 0;
for ( $id = 1; $id <= 10000; $id++ ) {
	$held += SendTime::isHoldout( 42, $id, 20 ) ? 1 : 0;
}
lt_check( 'isHoldout at 20% holds out about a fifth', $held >= 1900 && $held <= 2100, $held );
lt_check( 'isHoldout at 0% holds out nobody', ! SendTime::isHoldout( 42, 7, 0 ) && ! SendTime::isHoldout( 42, 8, 0 ) );
lt_check( 'isHoldout is the same on every call', SendTime::isHoldout( 42, 99, 20 ) === SendTime::isHoldout( 42, 99, 20 ) );

// DST fold: Denver clocks go back from 02:00 MDT to 01:00 MST on 2026-11-01 (08:00 UTC).
$denver = new DateTimeZone( 'America/Denver' );
$first  = SendTime::siteTimeNotBefore( new DateTimeImmutable( '2026-11-01 07:30:00 UTC' ), $denver );
$second = SendTime::siteTimeNotBefore( new DateTimeImmutable( '2026-11-01 08:30:00 UTC' ), $denver );
$plain  = SendTime::siteTimeNotBefore( new DateTimeImmutable( '2026-11-05 18:00:00 UTC' ), $denver );
lt_check( 'siteTimeNotBefore: first 01:30 of the fold stays 01:30', '2026-11-01 01:30:00' === $first, $first );
lt_check( 'siteTimeNotBefore: second 01:30 of the fold becomes 02:00, never claimable in the first pass', '2026-11-01 02:00:00' === $second, $second );
lt_check( 'siteTimeNotBefore: an ordinary instant is its wall time', '2026-11-05 11:00:00' === $plain, $plain );

/*
 * Against FluentCRM.
 */

$table_existed = [
	'sends'  => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AssignmentLog::table() ) ),
	'clicks' => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ClickLog::tableName() ) ),
];

// CREATE TABLE commits implicitly, so the tables are made before the transaction starts.
AssignmentLog::ensureTable();
$click_log = new ClickLog();
$click_log->ensureTable();

lt_check(
	'AssignmentLog and ClickLog tables exist',
	(bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AssignmentLog::table() ) ) && (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ClickLog::tableName() ) )
);

add_filter( 'pre_http_request', static function () {
	return new WP_Error( 'lt_check_blocked', 'Outbound HTTP is blocked during local-time checks.' );
}, 1 );

// Every wp_mail() is recorded and answered here; nothing reaches a mailer.
add_filter( 'pre_wp_mail', static function ( $short, $atts ) {
	global $lt_mail;
	$lt_mail[] = $atts;
	return false;
}, 1, 2 );

$wpdb->query( 'START TRANSACTION' );
register_shutdown_function( static function () use ( $wpdb ) {
	$wpdb->query( 'ROLLBACK' );
} );

$now  = current_time( 'timestamp' );
$ts   = current_time( 'mysql' );
$site = wp_timezone();

$sending = new LocalTimeSending();
$sending->register();
$click_log->register();

// What the bootstrap does at load time, before any ability exists.
add_filter( 'wp_register_ability_args', [ LocalTimeSending::class, 'guardMcpResume' ], 10, 2 );

$bootstrap_path = is_readable( '/tmp/fluent-crm-custom-features.php' ) ? '/tmp/fluent-crm-custom-features.php' : '';
$bootstrap_text = '' !== $bootstrap_path ? (string) file_get_contents( $bootstrap_path ) : '';
$filter_at      = strpos( $bootstrap_text, "add_filter( 'wp_register_ability_args'" );
$init_at        = strpos( $bootstrap_text, "'init'," );
lt_check(
	'bootstrap adds the MCP resume guard at load time, before the init callback (the MCP adapter registers abilities on init)',
	'' === $bootstrap_path || ( false !== $filter_at && false !== $init_at && $filter_at < $init_at ),
	'' === $bootstrap_path ? 'not checked: docker cp fluent-crm-custom-features.php to /tmp' : [ $filter_at, $init_at ]
);

$sending_source = (string) file_get_contents( '/tmp/LocalTime/LocalTimeSending.php' );
preg_match( '/public function register\(\): void \{.*?\n\t\}/s', $sending_source, $register_body );
lt_check( 'register() does not add the MCP resume guard (a filter added on init is too late)', ! empty( $register_body[0] ) && false === strpos( $register_body[0], 'wp_register_ability_args' ), $register_body[0] ?? 'register() not found' );

lt_check( 'tick runs on fluentcrm_process_scheduled_tasks_init (every scheduler path)', false !== has_action( 'fluentcrm_process_scheduled_tasks_init', [ $sending, 'tick' ] ) );
lt_check( 'send-now is handled by LocalTimeSending, not the panel', false !== has_action( 'fluent_crm/campaign_set_send_now', [ $sending, 'forgetDate' ] ) && ! method_exists( LocalTimePanel::class, 'forgetDate' ) );

$make_tag = static function ( string $suffix ) use ( $wpdb, $p, $marker, $ts ) {
	$wpdb->insert( "{$p}fc_tags", [ 'title' => "{$marker}-{$suffix}", 'slug' => "{$marker}-{$suffix}", 'created_at' => $ts, 'updated_at' => $ts ] );
	return (int) $wpdb->insert_id;
};

// Contact fixtures and the zone each must land in, written out rather than worked out with
// resolveZone(), so the rows are checked against an independent answer.
$contacts = [
	'new-york'  => [ 'timezone' => 'America/New_York', 'country' => null, 'state' => null, 'zone' => 'America/New_York', 'source' => 'contact' ],
	'sydney'    => [ 'timezone' => 'Australia/Sydney', 'country' => null, 'state' => null, 'zone' => 'Australia/Sydney', 'source' => 'contact' ],
	'germany'   => [ 'timezone' => null, 'country' => 'DE', 'state' => null, 'zone' => 'Europe/Berlin', 'source' => 'country' ],
	'us-ca'     => [ 'timezone' => null, 'country' => 'US', 'state' => 'CA', 'zone' => 'America/Los_Angeles', 'source' => 'state' ],
	'us-name'   => [ 'timezone' => null, 'country' => 'US', 'state' => 'Washington', 'zone' => 'America/Los_Angeles', 'source' => 'state' ],
	'us-none'   => [ 'timezone' => null, 'country' => 'US', 'state' => null, 'zone' => 'America/New_York', 'source' => 'country' ],
	'canada-bc' => [ 'timezone' => null, 'country' => 'CA', 'state' => 'BC', 'zone' => 'America/Vancouver', 'source' => 'state' ],
	'au-wa'     => [ 'timezone' => null, 'country' => 'AU', 'state' => 'Western Australia', 'zone' => 'Australia/Perth', 'source' => 'state' ],
	'unknown'   => [ 'timezone' => null, 'country' => null, 'state' => null, 'zone' => 'America/Denver', 'source' => 'site' ],
	'bad-zone'  => [ 'timezone' => 'Mars/Base', 'country' => 'US', 'state' => null, 'zone' => 'America/New_York', 'source' => 'country' ],
	'usa-state' => [ 'timezone' => null, 'country' => 'USA', 'state' => 'Texas', 'zone' => 'America/Chicago', 'source' => 'state' ],
	'germany-n' => [ 'timezone' => null, 'country' => 'Germany', 'state' => null, 'zone' => 'Europe/Berlin', 'source' => 'country' ],
];

$add_contact = static function ( string $key, array $fields, int $tag_id ) use ( $wpdb, $p, $marker, $ts ) {
	$wpdb->insert( "{$p}fc_subscribers", [
		'email'      => "{$marker}-{$key}@gravitykit-lt.dev",
		'first_name' => $key,
		'status'     => 'subscribed',
		'timezone'   => $fields['timezone'],
		'country'    => $fields['country'],
		'state'      => $fields['state'],
		'hash'       => md5( $marker . $key ),
		'created_at' => $ts,
		'updated_at' => $ts,
	] );
	$id = (int) $wpdb->insert_id;

	$wpdb->insert( "{$p}fc_subscriber_pivot", [
		'subscriber_id' => $id,
		'object_id'     => $tag_id,
		'object_type'   => 'FluentCrm\App\Models\Tag',
		'status'        => 'subscribed',
		'created_at'    => $ts,
		'updated_at'    => $ts,
	] );

	return $id;
};

$tag_id      = $make_tag( 'main' );
$contact_ids = [];
$expect_of   = [];

foreach ( $contacts as $key => $fields ) {
	$contact_ids[ $key ]            = $add_contact( $key, $fields, $tag_id );
	$expect_of[ $contact_ids[ $key ] ] = $fields;
}

// A larger audience for the holdout check, so a 20% share is not left to chance.
$holdout_tag = $make_tag( 'holdout' );
$holdout_ids = [];
$keys        = array_keys( $contacts );

for ( $i = 0; $i < 60; $i++ ) {
	$fields                      = $contacts[ $keys[ $i % count( $keys ) ] ];
	$id                          = $add_contact( "h{$i}", $fields, $holdout_tag );
	$holdout_ids[]               = $id;
	$expect_of[ $id ]            = $fields;
}

$make_campaign = static function ( string $suffix, string $scheduled_at, int $tag, ?array $plan = null, string $status = 'pending-scheduled' ) use ( $marker ) {
	$campaign = Campaign::create( [
		'title'            => "{$marker} {$suffix}",
		'email_subject'    => "{$marker} {$suffix}",
		'email_body'       => '<p>Local-time check.</p>',
		'status'           => $status,
		'scheduled_at'     => $scheduled_at,
		'recipients_count' => 0,
		'settings'         => [
			'subscribers'         => [ [ 'list' => 'all', 'tag' => (string) $tag ] ],
			'excludedSubscribers' => [],
			'sending_filter'      => 'list_tag',
			'sending_type'        => 'schedule',
			'mailer_settings'     => [ 'from_name' => '', 'from_email' => '', 'reply_to_name' => '', 'reply_to_email' => '', 'is_custom' => 'no' ],
		],
	] );

	if ( null !== $plan ) {
		LocalTimeSending::savePlan( (int) $campaign->id, array_merge( [ 'enabled' => true, 'start' => 8 * 60, 'end' => 11 * 60, 'holdout' => 0, 'date' => '' ], $plan ) );
	}

	return Campaign::withoutGlobalScope( 'type' )->find( $campaign->id );
};

$reload = static function ( $campaign ) {
	return Campaign::withoutGlobalScope( 'type' )->find( is_object( $campaign ) ? $campaign->id : $campaign );
};

$rows_of = static function ( int $campaign_id ) use ( $wpdb, $p ) {
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT id, subscriber_id, status, scheduled_at, email_hash FROM {$p}fc_campaign_emails WHERE campaign_id = %d",
		$campaign_id
	), OBJECT_K );
};

$meta = static function ( int $campaign_id, string $key ) {
	return fluentcrm_get_campaign_meta( $campaign_id, $key, true );
};

// What FluentCRM's schedule step does before firing campaign_scheduled.
$schedule = static function ( $campaign, string $picked_at ) use ( $reload ) {
	Campaign::withoutGlobalScope( 'type' )->where( 'id', $campaign->id )->update( [
		'status'           => 'pending-scheduled',
		'scheduled_at'     => $picked_at,
		'recipients_count' => 0,
	] );
	$campaign = $reload( $campaign );
	do_action( 'fluent_crm/campaign_scheduled', $campaign, $campaign->scheduled_at );

	return $reload( $campaign );
};

// Each moved row, checked in its expected zone: on the delivery date, at its slot, inside the
// window, and recorded with the same zone, source, slot and site time.
$verify_rows = static function ( $campaign, array $plan ) use ( $rows_of, &$expect_of, $site ) {
	$rows    = $rows_of( (int) $campaign->id );
	$records = [];

	foreach ( AssignmentLog::forCampaign( (int) $campaign->id ) as $record ) {
		$records[ (int) $record['email_id'] ][] = $record;
	}

	$problems = [];

	foreach ( $rows as $row ) {
		$expect  = $expect_of[ (int) $row->subscriber_id ] ?? null;
		$record  = $records[ (int) $row->id ] ?? [];
		$holdout = SendTime::isHoldout( (int) $campaign->id, (int) $row->subscriber_id, (int) $plan['holdout'] );
		$slot    = SendTime::slotMinutes( (int) $campaign->id, (int) $row->subscriber_id, $plan['start'], $plan['end'] );
		$issue   = [];

		if ( null === $expect ) {
			$problems[ $row->id ] = 'row for a contact this run did not create: ' . $row->subscriber_id;
			continue;
		}

		$local   = ( new DateTimeImmutable( $row->scheduled_at, $site ) )->setTimezone( new DateTimeZone( $holdout ? 'America/Denver' : $expect['zone'] ) );
		$minutes = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );

		if ( 'scheduled' !== $row->status ) {
			$issue[] = 'status ' . $row->status;
		}
		if ( $local->format( 'Y-m-d' ) !== $plan['date'] ) {
			$issue[] = 'local date ' . $local->format( 'Y-m-d' );
		}
		if ( $holdout ? $minutes !== $plan['start'] : $minutes !== $slot ) {
			$issue[] = "local minutes {$minutes}, slot {$slot}";
		}
		if ( $minutes < $plan['start'] || $minutes >= $plan['end'] ) {
			$issue[] = 'outside the window';
		}
		if ( 1 !== count( $record ) ) {
			$issue[] = count( $record ) . ' records';
		} else {
			$r = $record[0];
			if ( $r['zone'] !== $expect['zone'] || $r['zone_source'] !== $expect['source'] ) {
				$issue[] = "recorded {$r['zone']}/{$r['zone_source']}";
			}
			if ( (int) $r['holdout'] !== ( $holdout ? 1 : 0 ) || ( $holdout ? null !== $r['slot_minutes'] : (int) $r['slot_minutes'] !== $slot ) ) {
				$issue[] = "recorded holdout {$r['holdout']} slot " . var_export( $r['slot_minutes'], true );
			}
			if ( $r['site_scheduled_at'] !== $row->scheduled_at ) {
				$issue[] = "recorded site time {$r['site_scheduled_at']}";
			}
			$intended = ( new DateTimeImmutable( $r['intended_at_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $site )->format( 'Y-m-d H:i:s' );
			if ( $intended !== $row->scheduled_at ) {
				$issue[] = "intended {$r['intended_at_utc']} UTC is not the row time";
			}
		}

		if ( $issue ) {
			$problems[ $row->id ] = implode( '; ', $issue );
		}
	}

	return [ 'rows' => count( $rows ), 'records' => array_sum( array_map( 'count', $records ) ), 'problems' => array_slice( $problems, 0, 5, true ) ];
};

$week_out  = ( new DateTimeImmutable( '+7 days', $site ) )->format( 'Y-m-d' );
$week_out2 = ( new DateTimeImmutable( '+8 days', $site ) )->format( 'Y-m-d' );
$anchor_of = static function ( string $date, int $start = 480 ) use ( $site ) {
	return SendTime::siteTimeNotBefore( SendTime::firstWindowOpens( $date, $start ), $site );
};

/*
 * 1. Scheduling: picked_at, anchor and generation are stored; the campaign moves to the anchor.
 */

$main = $make_campaign( 'main', "{$week_out} 15:30:00", $tag_id, [] );
$main = $schedule( $main, "{$week_out} 15:30:00" );
$plan = LocalTimeSending::getPlan( (int) $main->id );

lt_check( 'scheduling stores the delivery date and picked time', $week_out === $plan['date'] && "{$week_out} 15:30:00" === $plan['picked_at'], $plan );
lt_check( 'scheduling stores a generation', 36 === strlen( $plan['generation'] ), $plan['generation'] );
lt_check( 'scheduling moves the campaign to when the window first opens anywhere', $anchor_of( $week_out ) === $plan['anchor'] && $plan['anchor'] === $main->scheduled_at && 'pending-scheduled' === $main->status, [ $anchor_of( $week_out ), $plan['anchor'], $main->scheduled_at, $main->status ] );
lt_check( 'scheduling records the plan', 0 === strpos( (string) substr( (string) $meta( (int) $main->id, LocalTimeSending::RESULT_META ), 20 ), 'planned:' ), $meta( (int) $main->id, LocalTimeSending::RESULT_META ) );

/*
 * 2. advance() queues the rows early and Spreader moves every one.
 */

for ( $i = 0; $i < 5 && 'scheduled' !== $reload( $main )->status; $i++ ) {
	$sending->advance( (int) $main->id );
}

$main     = $reload( $main );
$rows     = $rows_of( (int) $main->id );
$verified = $verify_rows( $main, $plan );

lt_check( 'a local-time campaign is queued early and released', 'scheduled' === $main->status && $main->scheduled_at === $plan['anchor'], [ $main->status, $main->scheduled_at ] );
lt_check( 'every tagged contact got a row', count( $rows ) === count( $contacts ), count( $rows ) );
lt_check( 'every row moved to its local time, recorded with zone, source and slot', count( $contacts ) === $verified['rows'] && $verified['rows'] === $verified['records'] && ! $verified['problems'], $verified );
lt_check( 'no row is at or before the campaign time', ! array_filter( $rows, static function ( $row ) use ( $main ) { return $row->scheduled_at <= $main->scheduled_at; } ) );
lt_check( 'the attempt is marked done with its generation', $plan['generation'] === $meta( (int) $main->id, LocalTimeSending::DONE_META ) );
lt_check( 'the result says how many moved', false !== strpos( (string) $meta( (int) $main->id, LocalTimeSending::RESULT_META ), 'moved ' . count( $contacts ) . ' emails' ), $meta( (int) $main->id, LocalTimeSending::RESULT_META ) );

$before = $rows_of( (int) $main->id );
$sending->advance( (int) $main->id );
lt_check( 'a spread campaign is not spread again', $before == $rows_of( (int) $main->id ) );
lt_check( 'moved rows are not moved a second time', 0 === Spreader::spread( $main, $plan ) && count( $contacts ) === count( AssignmentLog::forCampaign( (int) $main->id ) ) );

/*
 * 3. Holdout 20%: held rows go out at the window start in the site zone.
 */

$held_campaign = $make_campaign( 'holdout', "{$week_out} 15:30:00", $holdout_tag, [ 'holdout' => 20 ] );
$held_campaign = $schedule( $held_campaign, "{$week_out} 15:30:00" );
$held_plan     = LocalTimeSending::getPlan( (int) $held_campaign->id );

for ( $i = 0; $i < 5 && 'scheduled' !== $reload( $held_campaign )->status; $i++ ) {
	$sending->advance( (int) $held_campaign->id );
}

$held_campaign = $reload( $held_campaign );
$held_rows     = $rows_of( (int) $held_campaign->id );
$expected_held = array_filter( $held_rows, static function ( $row ) use ( $held_campaign ) { return SendTime::isHoldout( (int) $held_campaign->id, (int) $row->subscriber_id, 20 ); } );
$at_start      = array_filter( $expected_held, static function ( $row ) use ( $week_out ) { return "{$week_out} 08:00:00" === $row->scheduled_at; } );
$held_records  = array_filter( AssignmentLog::forCampaign( (int) $held_campaign->id ), static function ( $r ) { return 1 === (int) $r['holdout']; } );
$verified      = $verify_rows( $held_campaign, $held_plan );

lt_check( 'holdout 20%: the held rows exist (5-20 of 60)', count( $expected_held ) >= 5 && count( $expected_held ) <= 20, count( $expected_held ) );
lt_check( 'holdout 20%: every held row is at 8:00 Denver on the delivery date', count( $at_start ) === count( $expected_held ), [ count( $at_start ), count( $expected_held ) ] );
lt_check( 'holdout 20%: held rows are recorded with holdout = 1 and no slot', count( $held_records ) === count( $expected_held ) && ! array_filter( $held_records, static function ( $r ) { return null !== $r['slot_minutes']; } ) );
lt_check( 'holdout 20%: every row matches its expected time and record', 60 === $verified['rows'] && 60 === $verified['records'] && ! $verified['problems'], $verified );

/*
 * 4. A date too close to promise: back to draft, with the reason and the recipient count restored.
 */

$late         = $make_campaign( 'late', gmdate( 'Y-m-d H:i:s', $now + 30 * MINUTE_IN_SECONDS ), $tag_id, [] );
$mail_before  = count( $lt_mail );
$late         = $schedule( $late, gmdate( 'Y-m-d H:i:s', $now + 30 * MINUTE_IN_SECONDS ) );
$late_mail    = array_slice( $lt_mail, $mail_before );
$late_plan   = LocalTimeSending::getPlan( (int) $late->id );
$late_result = (string) $meta( (int) $late->id, LocalTimeSending::RESULT_META );
$feasible    = LocalTimeSending::feasibility( gmdate( 'Y-m-d', $now ), $late_plan );

lt_check( 'too close: the campaign is back to draft', 'draft' === $late->status, $late->status );
lt_check( 'too close: the site admin is emailed once that the campaign is back to draft and will not send', 1 === count( $late_mail ) && get_option( 'admin_email' ) === $late_mail[0]['to'] && false !== strpos( $late_mail[0]['subject'], 'back to draft' ) && false !== strpos( $late_mail[0]['message'], 'will not send' ), array_map( static function ( $m ) { return [ $m['to'], $m['subject'] ]; }, $late_mail ) );
lt_check( 'too close: the recipient count is restored', count( $contacts ) === (int) $late->recipients_count, (int) $late->recipients_count );
lt_check( 'too close: no rows are left', ! $rows_of( (int) $late->id ) );
lt_check( 'too close: the plan keeps its settings but no attempt', $late_plan['enabled'] && '' === $late_plan['date'] && '' === $late_plan['anchor'] && '' === $late_plan['generation'], $late_plan );
lt_check( 'too close: the reason is recorded and names the earliest date', false !== strpos( $late_result, 'not scheduled, back to draft' ) && false !== strpos( $late_result, 'The earliest date that works is' ), $late_result );
lt_check( 'feasibility() refuses today and offers a later date', ! $feasible['ok'] && $feasible['earliest_date'] > gmdate( 'Y-m-d', $now ), $feasible );
lt_check( 'feasibility() accepts a week out', LocalTimeSending::feasibility( $week_out, $late_plan )['ok'] );

/*
 * 5. Send now clears the delivery date.
 */

$sent_now = $make_campaign( 'send-now', "{$week_out} 15:30:00", $tag_id, [ 'date' => $week_out, 'anchor' => $anchor_of( $week_out ), 'generation' => wp_generate_uuid4(), 'picked_at' => "{$week_out} 15:30:00" ] );
do_action( 'fluent_crm/campaign_set_send_now', $sent_now );
$sent_plan = LocalTimeSending::getPlan( (int) $sent_now->id );
lt_check( 'send now clears the date, anchor and generation but keeps the settings', $sent_plan['enabled'] && '' === $sent_plan['date'] && '' === $sent_plan['anchor'] && '' === $sent_plan['generation'] && '' === $sent_plan['picked_at'], $sent_plan );

/*
 * 6. A stale generation cannot mark a newer attempt done.
 */

$stale = $make_campaign( 'stale', "{$week_out} 15:30:00", $tag_id, [] );
$stale = $schedule( $stale, "{$week_out} 15:30:00" );
$old   = LocalTimeSending::getPlan( (int) $stale->id );

// Cancelled the way CampaignController::unSchedule() does, then scheduled again for another day.
Campaign::withoutGlobalScope( 'type' )->where( 'id', $stale->id )->update( [ 'status' => 'draft' ] );
CampaignEmail::where( 'campaign_id', $stale->id )->delete();
$stale = $schedule( $stale, "{$week_out2} 15:30:00" );
$new   = LocalTimeSending::getPlan( (int) $stale->id );

lt_call( $sending, 'finish', (int) $stale->id, $old['generation'], 'stale finish from the old worker' );
lt_check( 'a re-schedule starts a new generation', $old['generation'] !== $new['generation'] && $week_out2 === $new['date'], [ $old['generation'], $new['generation'] ] );
lt_check( 'the old generation cannot mark the new attempt done', $old['generation'] !== $meta( (int) $stale->id, LocalTimeSending::DONE_META ) && false === strpos( (string) $meta( (int) $stale->id, LocalTimeSending::RESULT_META ), 'stale finish' ), [ $meta( (int) $stale->id, LocalTimeSending::DONE_META ), $meta( (int) $stale->id, LocalTimeSending::RESULT_META ) ] );

$mail_before = count( $lt_mail );
lt_call( $sending, 'fallBack', $reload( $stale ), $old, 'stale fallback from the old worker' );
lt_check( 'the old generation cannot fall the new attempt back', $new['anchor'] === $reload( $stale )->scheduled_at && '' === LocalTimeSending::getPlan( (int) $stale->id )['fallback_at'] && count( $lt_mail ) === $mail_before, [ $reload( $stale )->scheduled_at, count( $lt_mail ) - $mail_before ] );

lt_call( $sending, 'finish', (int) $stale->id, $new['generation'], 'current finish' );
lt_check( 'control: the current generation can mark it done', $new['generation'] === $meta( (int) $stale->id, LocalTimeSending::DONE_META ) );

/*
 * 7. An outside schedule edit is re-planned; the tick lock keeps a second tick out.
 */

// CampaignController::updateCampaignTitle() on a `scheduled` campaign: the campaign and every
// unsent row take the new time, and campaign_scheduled does not fire.
$edited_at = "{$week_out2} 10:00:00";
Campaign::withoutGlobalScope( 'type' )->where( 'id', $main->id )->update( [ 'scheduled_at' => $edited_at ] );
CampaignEmail::where( 'campaign_id', $main->id )->whereNotIn( 'status', [ 'sent', 'failed', 'bounced' ] )->update( [ 'status' => 'scheduled', 'scheduled_at' => $edited_at ] );

$foreign = wp_generate_uuid4();
$held_by_other = Helper::acquireDbLock( '_customcrm_lock_local_time', 300, $foreign );
$sending->tick();
lt_check( 'tick lock: a tick that finds the lock held changes nothing', $held_by_other && $edited_at === $reload( $main )->scheduled_at && $plan['generation'] === LocalTimeSending::getPlan( (int) $main->id )['generation'] );
Helper::deleteDbLock( '_customcrm_lock_local_time', $foreign );

$sending->tick();
$main  = $reload( $main );
$plan2 = LocalTimeSending::getPlan( (int) $main->id );
$rows  = $rows_of( (int) $main->id );

lt_check( 'outside edit: the tick re-plans for the new date', $week_out2 === $plan2['date'] && $edited_at === $plan2['picked_at'] && $plan2['generation'] !== $plan['generation'], $plan2 );
lt_check( 'outside edit: the campaign moves to the new anchor', $anchor_of( $week_out2 ) === $main->scheduled_at && $main->scheduled_at === $plan2['anchor'], [ $main->scheduled_at, $anchor_of( $week_out2 ) ] );
lt_check( 'outside edit: every row waits at the new anchor', count( $contacts ) === count( array_filter( $rows, static function ( $row ) use ( $main ) { return $row->scheduled_at === $main->scheduled_at; } ) ) );
lt_check( 'outside edit: the old done marker is cleared', '' === (string) $meta( (int) $main->id, LocalTimeSending::DONE_META ) );
lt_check( 'tick lock is released after a tick', '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_customcrm_lock_local_time' ) ) );

$sending->advance( (int) $main->id );
$verified = $verify_rows( $reload( $main ), $plan2 );
lt_check( 'outside edit: the next advance spreads to the new date and replaces the records', count( $contacts ) === $verified['rows'] && $verified['rows'] === $verified['records'] && ! $verified['problems'], $verified );

/*
 * 8. Fallback: anchor a minute away with the rows not yet spread.
 */

$lt_mail     = [];
$fb_anchor   = gmdate( 'Y-m-d H:i:s', $now + MINUTE_IN_SECONDS );
$fb_picked   = gmdate( 'Y-m-d H:i:s', $now + 3 * HOUR_IN_SECONDS );
$fb_gen      = wp_generate_uuid4();
$fb_campaign = $make_campaign( 'fallback', $fb_anchor, $tag_id, [
	'date'       => gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ),
	'picked_at'  => $fb_picked,
	'anchor'     => $fb_anchor,
	'generation' => $fb_gen,
] );

$sending->advance( (int) $fb_campaign->id );
$fb_campaign = $reload( $fb_campaign );
$fb_plan     = LocalTimeSending::getPlan( (int) $fb_campaign->id );
$fb_rows     = $rows_of( (int) $fb_campaign->id );
$fb_result   = (string) $meta( (int) $fb_campaign->id, LocalTimeSending::RESULT_META );

lt_check( 'fallback: the campaign moves to the picked time', $fb_picked === $fb_campaign->scheduled_at && $fb_picked === $fb_plan['fallback_at'], [ $fb_campaign->scheduled_at, $fb_plan['fallback_at'] ] );
lt_check( 'fallback: every row moves to the picked time', count( $contacts ) === count( array_filter( $fb_rows, static function ( $row ) use ( $fb_picked ) { return $row->scheduled_at === $fb_picked; } ) ), array_count_values( array_map( static function ( $row ) { return $row->scheduled_at; }, $fb_rows ) ) );
lt_check( 'fallback: the attempt is marked done', $fb_gen === $meta( (int) $fb_campaign->id, LocalTimeSending::DONE_META ) );
lt_check( 'fallback: the reason is recorded', false !== strpos( $fb_result, 'fell back:' ) && false !== strpos( $fb_result, 'before the local-time send began' ), $fb_result );
lt_check( 'fallback: no assignment records are written', ! AssignmentLog::forCampaign( (int) $fb_campaign->id ) );
lt_check( 'fallback: wp_mail is called once, to the site admin', 1 === count( $lt_mail ) && get_option( 'admin_email' ) === $lt_mail[0]['to'] && false !== strpos( $lt_mail[0]['subject'], $marker ), array_map( static function ( $m ) { return [ $m['to'], $m['subject'] ]; }, $lt_mail ) );

$sending->advance( (int) $fb_campaign->id );
$sending->tick();
lt_check( 'fallback: later advances and ticks send no second email and keep the time', 1 === count( $lt_mail ) && $fb_picked === $reload( $fb_campaign )->scheduled_at, count( $lt_mail ) );

/*
 * 8b. An exception in one campaign's step never escapes the tick, never stops the other campaigns,
 * and near the anchor falls the campaign back instead of leaving its rows to go out early.
 */

$throwing = new class() extends LocalTimeSending {
	public $throw_for = [];

	protected function check( int $campaign_id ): void {
		if ( in_array( $campaign_id, $this->throw_for, true ) ) {
			throw new RuntimeException( 'simulated database failure' );
		}

		parent::check( $campaign_id );
	}
};

$ex_picked = gmdate( 'Y-m-d H:i:s', $now + 3 * HOUR_IN_SECONDS );
$ex_anchor = gmdate( 'Y-m-d H:i:s', $now + MINUTE_IN_SECONDS );
$ex_a      = $make_campaign( 'throws-near-anchor', $ex_anchor, $tag_id, [
	'date'       => gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ),
	'picked_at'  => $ex_picked,
	'anchor'     => $ex_anchor,
	'generation' => wp_generate_uuid4(),
] );
$ex_b_edit = "{$week_out2} 10:00:00";
$ex_b      = $make_campaign( 'edited-outside', $ex_b_edit, $tag_id, [
	'date'       => $week_out,
	'picked_at'  => "{$week_out} 15:30:00",
	'anchor'     => $anchor_of( $week_out ),
	'generation' => wp_generate_uuid4(),
] );
$ex_b_gen  = LocalTimeSending::getPlan( (int) $ex_b->id )['generation'];

$throwing->throw_for = [ (int) $ex_a->id ];
$mail_before         = count( $lt_mail );
$escaped             = '';

try {
	$throwing->tick();
} catch ( Throwable $e ) {
	$escaped = $e->getMessage();
}

$throwing->throw_for = [];
lt_check( 'tick: an exception inside a campaign step does not escape (it would skip the minute\'s sending)', '' === $escaped, $escaped );
lt_check( 'tick: one campaign throwing does not stop another from being handled', LocalTimeSending::getPlan( (int) $ex_b->id )['generation'] !== $ex_b_gen && $anchor_of( $week_out2 ) === $reload( $ex_b )->scheduled_at, [ $reload( $ex_b )->scheduled_at, $anchor_of( $week_out2 ) ] );
lt_check( 'tick: a campaign that throws within 2 minutes of its anchor falls back to the picked time and the admin is emailed', $ex_picked === $reload( $ex_a )->scheduled_at && $ex_picked === LocalTimeSending::getPlan( (int) $ex_a->id )['fallback_at'] && 1 === count( $lt_mail ) - $mail_before, [ $reload( $ex_a )->scheduled_at, count( $lt_mail ) - $mail_before ] );
lt_check( 'tick: the lock is released after an exception', '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_customcrm_lock_local_time' ) ) );

$ex_far_anchor = gmdate( 'Y-m-d H:i:s', $now + 40 * MINUTE_IN_SECONDS );
$ex_c          = $make_campaign( 'throws-far-from-anchor', $ex_far_anchor, $tag_id, [
	'date'       => gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ),
	'picked_at'  => $ex_picked,
	'anchor'     => $ex_far_anchor,
	'generation' => wp_generate_uuid4(),
] );
$throwing->throw_for = [ (int) $ex_c->id ];

try {
	$throwing->tick();
} catch ( Throwable $e ) {
	$escaped = $e->getMessage();
}

$throwing->throw_for = [];
lt_check( 'tick: a campaign that throws with time to spare is left to try again next minute', $ex_far_anchor === $reload( $ex_c )->scheduled_at && '' === LocalTimeSending::getPlan( (int) $ex_c->id )['fallback_at'] );

/*
 * 8c. A spread that runs out of time stops, so the rows it did not reach can be moved off the
 * campaign time by the fallback instead of going out at it.
 */

$dl = $make_campaign( 'deadline', "{$week_out} 15:30:00", $tag_id, [] );
$dl = $schedule( $dl, "{$week_out} 15:30:00" );
Campaign::withoutGlobalScope( 'type' )->where( 'id', $dl->id )->update( [ 'status' => 'processing' ] );

for ( $i = 0; $i < 20 && 'processing' === $reload( $dl )->status; $i++ ) {
	( new CampaignProcessor( (int) $dl->id ) )->processEmails();
}

$dl_plan   = LocalTimeSending::getPlan( (int) $dl->id );
$dl_close  = gmdate( 'Y-m-d H:i:s', $now + 10 );
$dl_far    = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
$set_times = static function ( string $at ) use ( $wpdb, $p, $dl ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}fc_campaign_emails SET scheduled_at = %s, status = 'scheduled' WHERE campaign_id = %d", $at, $dl->id ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}fc_campaigns SET scheduled_at = %s, status = 'scheduled' WHERE id = %d", $at, $dl->id ) );
};

$set_times( $dl_close );
$ran_out = Spreader::spread( $reload( $dl ), $dl_plan );
$at_time = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_campaign_emails WHERE campaign_id = %d AND scheduled_at = %s", $dl->id, $dl_close ) );
lt_check( 'spread: 10 seconds before the campaign time it stops and moves nothing', false === $ran_out && count( $contacts ) === $at_time, [ var_export( $ran_out, true ), $at_time ] );

$set_times( $dl_far );
$moved_dl = Spreader::spread( $reload( $dl ), $dl_plan );
lt_check( 'spread: control, with an hour to spare it moves every row', count( $contacts ) === $moved_dl, var_export( $moved_dl, true ) );

/*
 * 9. FluentCRM's MCP resume of a local-time campaign keeps each email's time.
 */

$has_abilities = function_exists( 'wp_register_ability' ) && function_exists( 'wp_get_ability' ) && null !== wp_get_ability( 'fluent-crm/change-campaign-status' );
lt_check( 'Abilities API present in the clone, with fluent-crm/change-campaign-status registered', $has_abilities );

if ( $has_abilities ) {
	// The filter WP_Abilities_Registry::register() applies, with FluentCRM's own callback.
	$args = apply_filters( 'wp_register_ability_args', [ 'execute_callback' => [ \FluentCrm\App\Modules\MCP\Tools\CampaignTools::class, 'changeCampaignStatus' ] ], 'fluent-crm/change-campaign-status' );

	$paused_at = "{$week_out} 14:00:00";
	$paused    = $make_campaign( 'paused', $anchor_of( $week_out ), $tag_id, [ 'date' => $week_out, 'anchor' => $anchor_of( $week_out ), 'generation' => wp_generate_uuid4() ], 'paused' );
	$plain     = $make_campaign( 'paused-plain', $anchor_of( $week_out ), $tag_id, null, 'paused' );

	foreach ( [ $paused, $plain ] as $c ) {
		foreach ( $contact_ids as $sid ) {
			$wpdb->insert( "{$p}fc_campaign_emails", [ 'campaign_id' => $c->id, 'email_type' => 'campaign', 'subscriber_id' => $sid, 'email_address' => "{$marker}-row-{$sid}@gravitykit-lt.dev", 'status' => 'paused', 'scheduled_at' => $paused_at, 'created_at' => $ts, 'updated_at' => $ts ] );
		}
	}

	$resumed = call_user_func( $args['execute_callback'], [ 'campaign_id' => (int) $paused->id, 'action' => 'resume' ] );
	$kept    = array_filter( $rows_of( (int) $paused->id ), static function ( $row ) use ( $paused_at ) { return 'scheduled' === $row->status && $row->scheduled_at === $paused_at; } );
	lt_check( 'MCP resume of a local-time campaign resumes it and keeps every email\'s time', is_array( $resumed ) && ! empty( $resumed['ok'] ) && 'working' === $reload( $paused )->status && count( $kept ) === count( $contact_ids ), is_wp_error( $resumed ) ? $resumed->get_error_message() : [ 'status' => $reload( $paused )->status, 'kept' => count( $kept ) ] );

	$allowed = call_user_func( $args['execute_callback'], [ 'campaign_id' => (int) $plain->id, 'action' => 'resume' ] );
	lt_check( 'control: MCP resume of a plain campaign still reaches FluentCRM', ! is_wp_error( $allowed ) && 'working' === $reload( $plain )->status, is_wp_error( $allowed ) ? $allowed->get_error_message() : $reload( $plain )->status );
}

/*
 * 10. Panel REST route.
 */

$admin = (int) $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = '{$p}capabilities' AND m.meta_value LIKE '%administrator%' ORDER BY u.ID LIMIT 1" );
wp_set_current_user( $admin );
( new LocalTimePanel() )->register();

$rest = static function ( string $method, int $id, array $params = [] ) {
	$request = new WP_REST_Request( $method, '/customcrm/v1/local-time/' . $id );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	return rest_do_request( $request );
};

$draft    = $make_campaign( 'draft', $ts, $tag_id, null, 'draft' );
$response = $rest( 'POST', (int) $draft->id, [ 'enabled' => true, 'start' => 540, 'end' => 720, 'holdout' => 15 ] );
$saved    = LocalTimeSending::getPlan( (int) $draft->id );
lt_check( 'REST: a draft saves window and holdout', 200 === $response->get_status() && $saved['enabled'] && 540 === $saved['start'] && 720 === $saved['end'] && 15 === $saved['holdout'], [ $response->get_status(), $saved ] );
lt_check( 'REST: a half-hour window is refused', 400 === $rest( 'POST', (int) $draft->id, [ 'enabled' => true, 'start' => 510, 'end' => 720 ] )->get_status() );
lt_check( 'REST: a 60% holdout is refused', 400 === $rest( 'POST', (int) $draft->id, [ 'enabled' => true, 'start' => 540, 'end' => 720, 'holdout' => 60 ] )->get_status() );

$check = $rest( 'GET', (int) $draft->id, [ 'date' => gmdate( 'Y-m-d', $now ) ] )->get_data();
lt_check( 'REST: GET with today reports it cannot be met and names the earliest date', isset( $check['feasibility'] ) && ! $check['feasibility']['ok'] && false !== strpos( $check['feasibility']['message'], 'The earliest date that works is' ), $check['feasibility'] ?? $check );

$locked = $rest( 'POST', (int) $main->id, [ 'enabled' => false, 'start' => 540, 'end' => 720 ] );
lt_check( 'REST: a scheduled campaign refuses changes with 409', 409 === $locked->get_status() && LocalTimeSending::getPlan( (int) $main->id )['enabled'], $locked->get_status() );
wp_set_current_user( 0 );

/*
 * 11. ClickLog through FluentCRM's redirect handler.
 */

$click_row = null;
foreach ( $rows_of( (int) $main->id ) as $row ) {
	$click_row = $row;
	break;
}

if ( '' === (string) $click_row->email_hash ) {
	$wpdb->update( "{$p}fc_campaign_emails", [ 'email_hash' => md5( $marker . 'click' ) ], [ 'id' => $click_row->id ] );
	$click_row->email_hash = md5( $marker . 'click' );
}

$short = substr( 'lt' . md5( $marker ), 0, 12 );
$wpdb->insert( "{$p}fc_url_stores", [ 'url' => "https://example.com/{$marker}", 'short' => $short, 'created_at' => $ts, 'updated_at' => $ts ] );

add_filter( 'fluent_crm/will_use_cookie', '__return_false' );
$_SERVER['HTTP_USER_AGENT'] = "{$marker}-agent/1.0 (scanner check)";

$count_clicks = static function () use ( $wpdb, $click_row ) {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ClickLog::tableName() . ' WHERE email_id = %d ORDER BY id', $click_row->id ), ARRAY_A );
};

$url_data            = UrlStores::getRowByShort( $short );
$url_data->url_token = substr( $click_row->email_hash, 0, 8 );
$target              = ( new RedirectionHandler() )->trackUrlClick( (int) $click_row->id, $url_data );
$clicks              = $count_clicks();

lt_check( 'ClickLog: one click through trackUrlClick() stores one row with the user agent', 1 === count( $clicks ) && "{$marker}-agent/1.0 (scanner check)" === $clicks[0]['user_agent'] && (int) $clicks[0]['campaign_id'] === (int) $main->id && (int) $clicks[0]['subscriber_id'] === (int) $click_row->subscriber_id && (int) $clicks[0]['url_id'] === (int) $url_data->id, [ $target, $clicks ] );

$url_data            = UrlStores::getRowByShort( $short );
$url_data->url_token = substr( $click_row->email_hash, 0, 8 );
( new RedirectionHandler() )->trackUrlClick( (int) $click_row->id, $url_data );
$metric_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_campaign_url_metrics WHERE campaign_id = %d AND subscriber_id = %d AND url_id = %d AND type = 'click'", $main->id, $click_row->subscriber_id, $url_data->id ) );
lt_check( 'ClickLog: a second click is a second row while FluentCRM keeps one aggregate', 2 === count( $count_clicks() ) && 1 === $metric_rows, [ count( $count_clicks() ), $metric_rows ] );

$url_data            = UrlStores::getRowByShort( $short );
$url_data->url_token = 'badtoken';
( new RedirectionHandler() )->trackUrlClick( (int) $click_row->id, $url_data );
lt_check( 'ClickLog: a click with a bad token is not logged', 2 === count( $count_clicks() ) );

$_REQUEST['ano']     = '1';
$url_data            = UrlStores::getRowByShort( $short );
$url_data->url_token = substr( $click_row->email_hash, 0, 8 );
( new RedirectionHandler() )->trackUrlClick( (int) $click_row->id, $url_data, true );
unset( $_REQUEST['ano'] );
lt_check( 'ClickLog: an anonymous click is not logged', 2 === count( $count_clicks() ) );

/*
 * 12. Browser time zone capture.
 */

$user_id = 2000000000 + wp_rand( 1, 99999999 );
$wpdb->update( "{$p}fc_subscribers", [ 'user_id' => $user_id ], [ 'id' => $contact_ids['unknown'] ] );

$user             = new WP_User();
$user->ID         = $user_id;
$user->user_email = "{$marker}-nobody@gravitykit-lt.dev";

$zone_of = static function () use ( $wpdb, $p, $contact_ids ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$p}fc_subscribers WHERE id = %d", $contact_ids['unknown'] ) );
};

$_COOKIE[ BrowserTimezone::COOKIE ] = 'Not/AZone';
( new BrowserTimezone() )->save( $user );
lt_check( 'an invalid browser zone is ignored', null === $zone_of(), $zone_of() );

$_COOKIE[ BrowserTimezone::COOKIE ] = 'Europe/Paris';
( new BrowserTimezone() )->save( $user );
lt_check( 'a valid browser zone is saved to the contact', 'Europe/Paris' === $zone_of(), $zone_of() );

unset( $_COOKIE[ BrowserTimezone::COOKIE ] );

/*
 * 13. Scale: 20,000 contacts through materialization and the spread.
 */

$scale = (int) ( getenv( 'LT_SCALE' ) === false ? 20000 : getenv( 'LT_SCALE' ) );

if ( $scale > 0 ) {
	$scale_tag = $make_tag( 'scale' );
	$mix       = array_values( $contacts );
	$t0        = microtime( true );

	for ( $offset = 0; $offset < $scale; $offset += 1000 ) {
		$values = [];
		for ( $i = $offset; $i < min( $scale, $offset + 1000 ); $i++ ) {
			$f        = $mix[ $i % count( $mix ) ];
			$values[] = $wpdb->prepare( '(%s, %s, %s, %s, %s, %s, %s, %s, %s)', "{$marker}-bulk{$i}@gravitykit-lt.dev", "bulk{$i}", 'subscribed', (string) $f['timezone'], (string) $f['country'], (string) $f['state'], md5( $marker . $i ), $ts, $ts );
		}
		$wpdb->query( "INSERT INTO {$p}fc_subscribers (email, first_name, status, timezone, country, state, hash, created_at, updated_at) VALUES " . implode( ',', $values ) );
	}

	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$p}fc_subscriber_pivot (subscriber_id, object_id, object_type, status, created_at, updated_at)
		SELECT id, %d, %s, 'subscribed', %s, %s FROM {$p}fc_subscribers WHERE email LIKE %s",
		$scale_tag,
		'FluentCrm\App\Models\Tag',
		$ts,
		$ts,
		$wpdb->esc_like( "{$marker}-bulk" ) . '%'
	) );

	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, email FROM {$p}fc_subscribers WHERE email LIKE %s", $wpdb->esc_like( "{$marker}-bulk" ) . '%' ) ) as $s ) {
		$i                         = (int) substr( $s->email, strlen( "{$marker}-bulk" ) );
		$expect_of[ (int) $s->id ] = $mix[ $i % count( $mix ) ];
	}

	$fixtures_seconds = microtime( true ) - $t0;

	$big = $make_campaign( 'scale', "{$week_out} 15:30:00", $scale_tag, [ 'holdout' => 20 ] );
	$big = $schedule( $big, "{$week_out} 15:30:00" );
	$big_plan = LocalTimeSending::getPlan( (int) $big->id );

	// advance() does this flip, then CampaignProcessor::processEmails() once per minute; here the
	// processor is called back to back so its time is measured apart from the spread.
	Campaign::withoutGlobalScope( 'type' )->where( 'id', $big->id )->where( 'status', 'pending-scheduled' )->update( [ 'status' => 'processing' ] );

	$t1    = microtime( true );
	$calls = 0;
	while ( 'processing' === $reload( $big )->status && $calls < 200 ) {
		( new CampaignProcessor( (int) $big->id ) )->processEmails();
		++$calls;
	}
	$materialize_seconds = microtime( true ) - $t1;

	$t2 = microtime( true );
	$sending->advance( (int) $big->id );
	$spread_seconds = microtime( true ) - $t2;

	$big      = $reload( $big );
	$verified = $verify_rows( $big, $big_plan );
	$still    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_campaign_emails WHERE campaign_id = %d AND scheduled_at = %s", $big->id, $big->scheduled_at ) );
	$held_n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . AssignmentLog::table() . ' WHERE campaign_id = %d AND holdout = 1', $big->id ) );

	lt_check( "scale: {$scale} rows created and released", $scale === $verified['rows'] && 'scheduled' === $big->status, [ $verified['rows'], $big->status, $calls ] );
	lt_check( "scale: every row moved off the anchor and recorded once, each checked in its own zone", 0 === $still && $scale === $verified['records'] && ! $verified['problems'], [ 'at anchor' => $still, 'records' => $verified['records'], 'problems' => $verified['problems'] ] );
	lt_check( 'scale: the holdout share is about 20%', $held_n >= $scale * 0.18 && $held_n <= $scale * 0.22, $held_n );
	lt_check( 'scale: done with the generation', $big_plan['generation'] === $meta( (int) $big->id, LocalTimeSending::DONE_META ) );

	printf(
		"TIMING scale %d contacts: fixtures %.1fs; materialization (CampaignProcessor, %d calls) %.1fs; spread (advance + Spreader + AssignmentLog) %.1fs; peak memory %.0f MB\n",
		$scale,
		$fixtures_seconds,
		$calls,
		$materialize_seconds,
		$spread_seconds,
		memory_get_peak_usage( true ) / 1048576
	);
}

/*
 * Roll back, then prove nothing is left.
 */

$campaign_ids_seen = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_campaigns WHERE title LIKE %s", $wpdb->esc_like( $marker ) . '%' ) );

$wpdb->query( 'ROLLBACK' );

$like     = $wpdb->esc_like( $marker ) . '%';
$in_ids   = $campaign_ids_seen ? implode( ',', array_map( 'intval', $campaign_ids_seen ) ) : '0';
$leftover = [
	'contacts'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_subscribers WHERE email LIKE %s", $like ) ),
	'campaigns'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_campaigns WHERE title LIKE %s", $like ) ),
	'tags'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_tags WHERE slug LIKE %s", $like ) ),
	'emails'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_campaign_emails WHERE campaign_id IN ({$in_ids})" ),
	'meta'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fc_meta WHERE object_id IN ({$in_ids}) AND `key` LIKE '\\_gk\\_local\\_time%'" ),
	'url_stores'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fc_url_stores WHERE url LIKE %s", '%' . $like ) ),
	'assignments' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AssignmentLog::table() . " WHERE campaign_id IN ({$in_ids})" ),
	'clicks'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ClickLog::tableName() . ' WHERE user_agent LIKE %s', $like ) ),
	'locks'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '_customcrm_lock_local_time' OR option_name IN ('_fluentcrm_lock_campaign_processing_" . implode( "','_fluentcrm_lock_campaign_processing_", array_map( 'intval', $campaign_ids_seen ?: [ 0 ] ) ) . "')" ),
];

if ( array_sum( $leftover ) ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_campaigns WHERE title LIKE %s", $like ) ) as $id ) {
		$wpdb->delete( "{$p}fc_campaign_emails", [ 'campaign_id' => $id ] );
		$wpdb->delete( "{$p}fc_meta", [ 'object_id' => $id, 'object_type' => Campaign::class ] );
		$wpdb->delete( "{$p}fc_campaigns", [ 'id' => $id ] );
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}fc_subscribers WHERE email LIKE %s", $like ) ) as $id ) {
		$wpdb->delete( "{$p}fc_subscriber_pivot", [ 'subscriber_id' => $id ] );
		$wpdb->delete( "{$p}fc_subscribers", [ 'id' => $id ] );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}fc_tags WHERE slug LIKE %s", $like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}fc_url_stores WHERE url LIKE %s", '%' . $like ) );
}

lt_check( 'the rollback left nothing behind', ! array_sum( $leftover ), $leftover );

if ( ! getenv( 'LT_KEEP_TABLES' ) ) {
	foreach ( [ 'sends' => [ AssignmentLog::table(), AssignmentLog::VERSION_OPTION ], 'clicks' => [ ClickLog::tableName(), 'customcrm_email_clicks_db_version' ] ] as $which => [ $table, $option ] ) {
		if ( ! $table_existed[ $which ] ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			delete_option( $option );
		}
	}
	lt_check(
		'tables and version options this run created are gone',
		( $table_existed['sends'] || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AssignmentLog::table() ) ) )
		&& ( $table_existed['clicks'] || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ClickLog::tableName() ) ) )
		&& ( $table_existed['sends'] || false === get_option( AssignmentLog::VERSION_OPTION ) )
		&& ( $table_existed['clicks'] || false === get_option( 'customcrm_email_clicks_db_version' ) )
	);
}

$failed = array_filter( $lt_results, static function ( $r ) { return ! $r['pass']; } );

foreach ( $lt_results as $r ) {
	echo ( $r['pass'] ? 'PASS ' : 'FAIL ' ) . $r['check'] . ( $r['pass'] || '' === $r['detail'] ? '' : '  ' . $r['detail'] ) . "\n";
}

echo sprintf( "\n%d checks, %d failed\n", count( $lt_results ), count( $failed ) );

if ( $failed ) {
	WP_CLI::halt( 1 );
}
