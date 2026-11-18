<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\FunnelCampaign;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Models\UrlStores;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;

/**
 * Records every visit to a cart email's recovery link: one `customcrm_cart_clicks` row per visit
 * (time, cart, contact, email, cart type, suspected bot), a `cart_recovery_click` PostHog event
 * when the theme's sender is loaded, and `fc_ab_click` on the checkout URL to join the visit.
 *
 * FluentCRM's tracker (`/?ns_url=`) already writes the `fc_campaign_url_metrics` row for a tracked
 * click, and its redirect gets a signed `fc_ab_mid`. A person's visit without one (a test send, a
 * copied link) gets that row written here, so attribution reading FluentCRM's table sees it.
 */
class CartRecoveryClick {

	public const TABLE_SUFFIX = 'customcrm_cart_clicks';

	public const EVENT_NAME = 'cart_recovery_click';

	private const VERSION_OPTION = 'customcrm_cart_clicks_db_version';

	private const VERSION = '1';

	/** Theme cron hook that posts a prepared PostHog event, with retries (posthog-purchases.php). */
	private const POSTHOG_SEND_HOOK = 'gk_posthog_send_started';

	private const POSTHOG_NAMESPACE = '\\GravityKit\\Store\\PostHogPurchases\\';

	/** A visit this soon after the email was sent is a scanner opening every link. */
	private const TOO_FAST_SECONDS = 15;

	private const BOT_USER_AGENT = '/bot|crawl|spider|slurp|preview|scan|python|curl|wget|java\/|go-http|okhttp|httpclient|libwww|headless|phantom|barracuda|proofpoint|mimecast|safelinks|urldefense|ironport|symantec|trendmicro|forcepoint|sophos|fortinet|ms-office|microsoft office|cisco|appengine|facebookexternalhit|slackbot|whatsapp/i';

	/** Click ID of the visit being handled, added to the checkout URL. */
	private static $click_id = '';

	public static function register(): void {
		add_action( 'fluent_crm/email_url_clicked', [ self::class, 'markTrackedClick' ], 5, 2 );
		add_action( 'fluentcrm_before_subscribers_deleted', [ self::class, 'forgetContacts' ] );
	}

	/**
	 * While FluentCRM handles a tracked click on a recovery link, add the email ID to its redirect.
	 *
	 * @param object $campaign_email FluentCrm\App\Models\CampaignEmail.
	 * @param object $url_data       FluentCrm\App\Models\UrlStores row.
	 */
	public static function markTrackedClick( $campaign_email, $url_data ): void {
		$url = is_object( $url_data ) ? (string) $url_data->url : '';
		$key = self::cartKeyFromUrl( $url );

		if ( '' === $key || ! is_object( $campaign_email ) || empty( $campaign_email->id ) ) {
			return;
		}

		$email_id = (int) $campaign_email->id;

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $key, $email_id ) {
				if ( self::cartKeyFromUrl( (string) $location ) !== $key ) {
					return $location;
				}

				return add_query_arg(
					[
						'fc_ab_mid' => $email_id,
						'fc_ab_sig' => self::signature( $email_id, $key ),
					],
					$location
				);
			}
		);
	}

	/**
	 * Records a visit to a recovery link. Never throws: the shopper's redirect must not depend on it.
	 *
	 * @param AbandonCartModel    $cart Cart the link restores.
	 * @param array<string,mixed> $data Query vars of the request.
	 * @return array<string,mixed> The stored click, or empty when nothing was stored.
	 */
	public static function record( AbandonCartModel $cart, array $data ): array {
		self::$click_id = '';

		try {
			return self::recordClick( $cart, array_merge( wp_unslash( $_GET ), $data ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM CartRecoveryClick: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return [];
		}
	}

	/** Click ID of the visit being handled, or empty. */
	public static function currentClickId(): string {
		return self::$click_id;
	}

	private static function recordClick( AbandonCartModel $cart, array $data ): array {
		$contact_id = self::contactId( $cart );
		$tracked    = self::trackedEmail( $cart, $contact_id, $data );
		$email      = $tracked ?: self::latestCartEmail( $cart, $contact_id );
		$bot_reason = self::botReason( $_SERVER, $email ? (string) $email->scheduled_at : '' );

		$click = [
			'click_id'    => wp_generate_uuid4(),
			'cart_id'     => (int) $cart->id,
			'contact_id'  => $contact_id,
			'campaign_id' => $email ? (int) $email->campaign_id : 0,
			'email_id'    => $email ? (int) $email->id : 0,
			'cart_type'   => self::cartType( (string) $cart->provider ),
			'cart_status' => (string) $cart->status,
			'via'         => $tracked ? 'tracked' : 'direct',
			'is_bot'      => '' !== $bot_reason,
			'bot_reason'  => $bot_reason,
			'user_agent'  => self::userAgent(),
			'clicked_at'  => gmdate( 'Y-m-d H:i:s' ),
		];

		self::ensureTable();

		global $wpdb;

		$wpdb->insert(
			self::tableName(),
			[
				'click_id'       => $click['click_id'],
				'cart_id'        => $click['cart_id'],
				'subscriber_id'  => $click['contact_id'],
				'campaign_id'    => $click['campaign_id'],
				'email_id'       => $click['email_id'],
				'cart_type'      => $click['cart_type'],
				'via'            => $click['via'],
				'is_bot'         => $click['is_bot'] ? 1 : 0,
				'bot_reason'     => $click['bot_reason'],
				'user_agent'     => $click['user_agent'],
				'clicked_at_utc' => $click['clicked_at'],
			],
			[ '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		// FluentCRM already counted a tracked click; a bot's visit must not become one.
		if ( ! $tracked && ! $click['is_bot'] && $email ) {
			self::addFluentCrmClick( $cart, $email );
		}

		self::$click_id = $click['click_id'];

		/**
		 * A cart email's recovery link was visited.
		 *
		 * @param array $click See recordClick().
		 */
		do_action( 'customcrm/edd_cart/recovery_clicked', $click );

		self::queuePostHog( $click );

		return $click;
	}

	/**
	 * The email of a tracked click, from the signed `fc_ab_mid` on FluentCRM's redirect.
	 */
	private static function trackedEmail( AbandonCartModel $cart, int $contact_id, array $data ): ?CampaignEmail {
		$email_id  = isset( $data['fc_ab_mid'] ) && is_scalar( $data['fc_ab_mid'] ) ? (int) $data['fc_ab_mid'] : 0;
		$signature = isset( $data['fc_ab_sig'] ) && is_scalar( $data['fc_ab_sig'] ) ? (string) $data['fc_ab_sig'] : '';

		if ( ! $email_id || ! hash_equals( self::signature( $email_id, (string) $cart->checkout_key ), $signature ) ) {
			return null;
		}

		$email = CampaignEmail::find( $email_id );

		return $email && (int) $email->subscriber_id === $contact_id ? $email : null;
	}

	/**
	 * The last cart email sent to this contact for this cart's automation run, for a visit that
	 * skipped FluentCRM's tracker.
	 */
	private static function latestCartEmail( AbandonCartModel $cart, int $contact_id ): ?CampaignEmail {
		if ( ! $contact_id ) {
			return null;
		}

		$funnel_ids = FunnelSubscriber::where( 'source_ref_id', $cart->id )
			->where( 'subscriber_id', $contact_id )
			->where( 'source_trigger_name', self::triggerName( (string) $cart->provider ) )
			->pluck( 'funnel_id' )
			->all();

		if ( ! $funnel_ids ) {
			return null;
		}

		$campaign_ids = FunnelCampaign::whereIn( 'parent_id', $funnel_ids )->pluck( 'id' )->all();

		if ( ! $campaign_ids ) {
			return null;
		}

		return CampaignEmail::where( 'subscriber_id', $contact_id )
			->whereIn( 'campaign_id', array_map( 'intval', $campaign_ids ) )
			->where( 'status', 'sent' )
			->orderBy( 'id', 'DESC' )
			->first();
	}

	/**
	 * The click row FluentCRM's tracker writes, for a visit that skipped it. Its `created_at` is the
	 * first click on the link from this email.
	 */
	private static function addFluentCrmClick( AbandonCartModel $cart, CampaignEmail $email ): void {
		$url_id = self::recoveryUrlId( $cart );

		if ( ! $url_id ) {
			return;
		}

		CampaignUrlMetric::maybeInsert(
			[
				'url_id'        => $url_id,
				'campaign_id'   => (int) $email->campaign_id,
				'subscriber_id' => (int) $email->subscriber_id,
				'type'          => 'click',
				'ip_address'    => function_exists( 'fluentCrmWillTrackIp' ) && fluentCrmWillTrackIp() && function_exists( 'fluentCrmWillAnonymizeIp' ) ? FluentCrm( 'request' )->getIp( fluentCrmWillAnonymizeIp() ) : '',
			]
		);

		if ( ! $email->is_open ) {
			$email->is_open = 1;
		}
		$email->click_counter = (int) $email->click_counter + 1;
		$email->save();
	}

	/**
	 * ID of the stored URL for this cart's recovery link, as FluentCRM saved it when the email went
	 * out, else a new one in the same form.
	 */
	private static function recoveryUrlId( AbandonCartModel $cart ): int {
		global $wpdb;

		$key = (string) $cart->checkout_key;

		if ( ! preg_match( '/^[0-9a-f]{16,64}$/', $key ) ) {
			return 0;
		}

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}fc_url_stores WHERE url LIKE %s ORDER BY id ASC LIMIT 1",
				'%fc_ab_hash=' . $wpdb->esc_like( $key ) . '%'
			)
		);

		if ( $id ) {
			return $id;
		}

		$url = add_query_arg(
			[
				FLUENTCRM_EXTERNAL_URL_PARAM => 1,
				'route'                      => 'general',
				'handler'                    => self::handlerName( (string) $cart->provider ),
				'fc_ab_hash'                 => $key,
			],
			home_url()
		);

		$row = UrlStores::getRowByShort( UrlStores::getUrlSlug( $url ) );

		return $row ? (int) $row->id : 0;
	}

	/**
	 * Why this request looks like a mail scanner rather than a person, or empty.
	 *
	 * @param array  $server    $_SERVER.
	 * @param string $sent_at   The email's scheduled time in site time, or empty.
	 */
	public static function botReason( array $server, string $sent_at = '' ): string {
		$method     = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		$user_agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
		$language   = (string) ( $server['HTTP_ACCEPT_LANGUAGE'] ?? '' );
		$reason     = '';

		if ( 'HEAD' === $method ) {
			$reason = 'head_request';
		} elseif ( '' === trim( $user_agent ) ) {
			$reason = 'no_user_agent';
		} elseif ( preg_match( self::BOT_USER_AGENT, $user_agent ) ) {
			$reason = 'user_agent';
		} elseif ( '' === trim( $language ) ) {
			$reason = 'no_accept_language';
		} elseif ( '' !== $sent_at && strtotime( $sent_at ) && current_time( 'timestamp' ) - strtotime( $sent_at ) < self::TOO_FAST_SECONDS ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- FluentCRM stores site time.
			$reason = 'too_fast';
		}

		/**
		 * Filters why a recovery-link visit is treated as a bot.
		 *
		 * @param string $reason Empty for a person.
		 * @param array  $server Request server vars.
		 */
		return (string) apply_filters( 'customcrm/edd_cart/recovery_click_bot_reason', $reason, $server );
	}

	/**
	 * Queue the PostHog event through the theme's sender. Without the theme nothing is sent.
	 */
	private static function queuePostHog( array $click ): void {
		$event_body = self::POSTHOG_NAMESPACE . 'event_body';

		if ( ! function_exists( $event_body ) ) {
			return;
		}

		$consent    = self::POSTHOG_NAMESPACE . 'has_analytics_consent';
		$browser    = self::POSTHOG_NAMESPACE . 'browser_distinct_id';
		$browser_id = function_exists( $consent ) && function_exists( $browser ) && $consent() ? (string) $browser() : '';

		$event = $event_body(
			self::EVENT_NAME,
			$click['click_id'],
			gmdate( 'c', strtotime( $click['clicked_at'] . ' UTC' ) ),
			$browser_id,
			'cart-recovery-' . $click['cart_id'],
			[
				'cart_id'     => $click['cart_id'],
				'cart_type'   => $click['cart_type'],
				'cart_status' => $click['cart_status'],
				'contact_id'  => $click['contact_id'],
				'campaign_id' => $click['campaign_id'],
				'email_id'    => $click['email_id'],
				'via'         => $click['via'],
				'is_bot'      => $click['is_bot'],
				'bot_reason'  => $click['bot_reason'],
				'click_id'    => $click['click_id'],
			]
		);

		wp_schedule_single_event( time(), self::POSTHOG_SEND_HOOK, [ $event ] );
	}

	/**
	 * A deleted contact leaves no click rows behind.
	 *
	 * @param int[] $contact_ids FluentCRM contact IDs.
	 */
	public static function forgetContacts( $contact_ids ): void {
		global $wpdb;

		$contact_ids = array_filter( array_map( 'intval', (array) $contact_ids ) );

		if ( ! $contact_ids || get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			return;
		}

		$table = self::tableName();
		$wpdb->query( "DELETE FROM {$table} WHERE subscriber_id IN (" . implode( ',', $contact_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function ensureTable(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			click_id char(36) NOT NULL DEFAULT '',
			cart_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cart_type varchar(20) NOT NULL DEFAULT '',
			via varchar(10) NOT NULL DEFAULT '',
			is_bot tinyint(1) NOT NULL DEFAULT 0,
			bot_reason varchar(30) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			clicked_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY cart_id (cart_id),
			KEY subscriber_id (subscriber_id),
			KEY campaign_id (campaign_id)
		) {$charset};"
		);

		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}
	}

	/** `fc_ab_hash` of a recovery link, or empty for any other URL. */
	public static function cartKeyFromUrl( string $url ): string {
		$url = str_replace( '&amp;', '&', $url );

		if ( false === strpos( $url, 'handler=fc_cart_edd' ) ) {
			return '';
		}

		return preg_match( '/[?&]fc_ab_hash=([0-9a-f]+)/', $url, $m ) ? $m[1] : '';
	}

	private static function signature( int $email_id, string $cart_key ): string {
		return hash_hmac( 'sha256', 'customcrm-cart-click|' . $email_id . '|' . $cart_key, wp_salt( 'auth' ) );
	}

	private static function contactId( AbandonCartModel $cart ): int {
		return CartEmailStop::contactId( $cart );
	}

	/** `new`, `renewal` or `upgrade`. */
	public static function cartType( string $provider ): string {
		$types = [
			EddCartDriver::PROVIDER        => 'new',
			EddRenewalCartDriver::PROVIDER => 'renewal',
			EddUpgradeCartDriver::PROVIDER => 'upgrade',
		];

		return $types[ $provider ] ?? $provider;
	}

	private static function driver( string $provider ): EddCartDriver {
		return EddCartTracking::driverFor( $provider );
	}

	private static function triggerName( string $provider ): string {
		return (string) self::driver( $provider )->getTriggerName();
	}

	private static function handlerName( string $provider ): string {
		return (string) self::driver( $provider )->getHandlerName();
	}

	private static function userAgent(): string {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return mb_substr( sanitize_text_field( (string) $user_agent ), 0, 255 );
	}
}
