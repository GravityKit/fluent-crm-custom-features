<?php

namespace CustomCRM\Email;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CustomEmailCampaign;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;

/**
 * Adds FluentCRM's open pixel and click links to the store's customer emails from EDD, so opens and
 * clicks record on the contact like FluentCRM's own emails.
 *
 * - Only a wp_mail() call between `edd_email_send_before` and `edd_email_send_after` is touched, so
 *   FluentCRM's emails and other plugins' emails never change.
 * - Each tracked send gets a `sent` row in `fc_campaign_emails` on one `custom_email_campaign` per EDD
 *   email. FluentCRM never mails those: its sender only picks up `campaign` rows, and the rows are sent.
 * - Not tracked, and counted in STATS_OPTION: admin emails, account emails (context `user`, which hold
 *   one-time sign-in links), test sends, recipients with no contact
 *   (none is created, since that can start automations), several recipients, plain text, and emails
 *   that already hold FluentCRM tracking.
 * - Download links (`eddfile=`) keep their URL, so their signed token is not stored in `fc_url_stores`.
 * - Any error sends the email exactly as EDD built it.
 */
class EddEmailTracking {

	/** Maps an EDD email ID (such as `sub_2` or `order_receipt`) to its FluentCRM campaign ID. */
	public const CAMPAIGNS_OPTION = 'customcrm_edd_email_campaigns';

	/** Counts of tracked and skipped sends, by reason, with the time of the last one. */
	public const STATS_OPTION = 'customcrm_edd_email_tracking_stats';

	/** Campaign type and row email_type. FluentCRM labels it "Custom Email" in its reports. */
	public const CAMPAIGN_TYPE = 'custom_email_campaign';

	/** Campaign setting that names the EDD email a campaign belongs to. */
	public const SETTING_KEY = 'gk_edd_email_id';

	/** Put in front of a link's URL so FluentCRM's link rewriting passes over it. */
	private const SKIP_MARK = '__customcrm_untracked__';

	/**
	 * One entry per EDD send in progress. EDD does not nest sends, but a stack costs nothing.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $sends = [];

	/**
	 * Hooks into EDD's send and into wp_mail().
	 */
	public static function register(): void {
		add_action( 'edd_email_send_before', [ self::class, 'startSend' ], 10, 1 );
		add_action( 'edd_email_send_after', [ self::class, 'endSend' ], 10, 0 );
		// Late, so the message is final. Gravity SMTP can run this filter more than once per email.
		add_filter( 'wp_mail', [ self::class, 'filterMail' ], 1000 );
		add_action( 'wp_mail_failed', [ self::class, 'markFailed' ], 10, 0 );
	}

	/**
	 * Remembers which EDD email is about to go out.
	 *
	 * @param object $processor EDD's email processor (`EDD\Emails\Base`).
	 */
	public static function startSend( $processor ): void {
		$email = null;

		if ( is_object( $processor ) && method_exists( $processor, 'get_email_object' ) ) {
			$email = $processor->get_email_object();
		}

		self::$sends[] = [
			'email'   => is_object( $email ) ? $email : null,
			'tracked' => [],
			'row_ids' => [],
		];
	}

	/**
	 * Forgets the EDD email that just went out.
	 */
	public static function endSend(): void {
		array_pop( self::$sends );
	}

	/**
	 * Adds tracking to an EDD email on its way out.
	 *
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return array<string,mixed>
	 */
	public static function filterMail( $atts ) {
		if ( ! self::$sends || ! is_array( $atts ) || ! isset( $atts['message'] ) || ! is_string( $atts['message'] ) ) {
			return $atts;
		}

		$index = count( self::$sends ) - 1;

		try {
			$atts['message'] = self::track( $atts, $index );
		} catch ( \Throwable $e ) {
			\CustomCRM\AbandonCart\Edd\EddCartTracking::logError( 'EddEmailTracking::filterMail', $e );
			self::forgetRows( $index );
			self::count( 'error' );
		}

		return $atts;
	}

	/**
	 * Marks this send's row failed when wp_mail() reports a failure.
	 */
	public static function markFailed(): void {
		if ( ! self::$sends ) {
			return;
		}

		$row_ids = self::$sends[ count( self::$sends ) - 1 ]['row_ids'];

		if ( ! $row_ids ) {
			return;
		}

		try {
			CampaignEmail::whereIn( 'id', $row_ids )->update( [ 'status' => 'failed' ] );
		} catch ( \Throwable $e ) {
			\CustomCRM\AbandonCart\Edd\EddCartTracking::logError( 'EddEmailTracking::markFailed', $e );
		}
	}

	/**
	 * The tracked message, or the message unchanged when this email is not tracked.
	 *
	 * @param array<string,mixed> $atts  wp_mail() arguments.
	 * @param int                 $index This send's place in self::$sends.
	 */
	private static function track( array $atts, int $index ): string {
		$message = $atts['message'];
		$send    = &self::$sends[ $index ];
		$key     = md5( $message );

		// The same email through the filter again: give back the copy already tracked, with its row.
		if ( isset( $send['tracked'][ $key ] ) ) {
			return $send['tracked'][ $key ];
		}

		// Tracked on an earlier pass: never a second pixel.
		if ( in_array( $message, $send['tracked'], true ) ) {
			return $message;
		}

		if ( self::hasFluentTracking( $message ) ) {
			self::count( 'already_tracked' );

			return $message;
		}

		$email  = $send['email'];
		$reason = self::skipReason( $email, $atts );

		if ( $reason ) {
			self::count( $reason );

			return $message;
		}

		$address = self::singleRecipient( $atts['to'] ?? '' );

		if ( ! $address ) {
			self::count( 'multiple_recipients' );

			return $message;
		}

		if ( ! self::isHtml( $message ) ) {
			self::count( 'not_html' );

			return $message;
		}

		$contact = Subscriber::where( 'email', $address )->first();

		if ( ! $contact ) {
			self::count( 'no_contact' );

			return $message;
		}

		$campaign = self::campaignFor( $email );

		if ( ! $campaign ) {
			self::count( 'no_campaign' );

			return $message;
		}

		$now = current_time( 'mysql' );

		$row = CampaignEmail::create(
			[
				'campaign_id'   => $campaign->id,
				'email_type'    => self::CAMPAIGN_TYPE,
				'subscriber_id' => $contact->id,
				'email_address' => $address,
				'email_subject' => wp_strip_all_tags( (string) ( $atts['subject'] ?? '' ) ),
				'email_hash'    => Helper::generateEmailHash(),
				'status'        => 'sent',
				'is_parsed'     => 1,
				'email_body'    => '',
				'scheduled_at'  => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);

		$send['row_ids'][] = (int) $row->id;

		$tracked = self::addTracking( $message, $row );

		fluentCrmDb()->table( 'fc_campaigns' )->where( 'id', $campaign->id )->increment( 'recipients_count' );

		$send['tracked'][ $key ] = $tracked;
		self::count( 'tracked' );

		return $tracked;
	}

	/**
	 * Why this email is not tracked, or '' to track it.
	 *
	 * @param object|null         $email EDD email object (`EDD\Emails\Types\Email`), null for older sends.
	 * @param array<string,mixed> $atts  wp_mail() arguments.
	 */
	private static function skipReason( $email, array $atts ): string {
		if ( ! $email || ! method_exists( $email, 'get_id' ) ) {
			return 'unknown_email';
		}

		if ( ! empty( $email->is_test ) || ! empty( $email->is_preview ) ) {
			return 'test';
		}

		$recipient = method_exists( $email, 'get_recipient_type' ) ? (string) $email->get_recipient_type() : '';

		if ( 'customer' !== $recipient ) {
			return 'not_customer';
		}

		// Account emails (login details, verification, sign-in links): their links are one-time sign-ins.
		// EDD marks some of them `customer`, so the context is checked as well.
		if ( method_exists( $email, 'get_context' ) && 'user' === $email->get_context() ) {
			return 'account_email';
		}

		/**
		 * Filters whether an EDD email gets FluentCRM tracking.
		 *
		 * @param bool                $track Default true.
		 * @param string              $id    EDD email ID, such as `sub_2` or `order_receipt`.
		 * @param object              $email EDD email object.
		 * @param array<string,mixed> $atts  wp_mail() arguments.
		 */
		if ( ! apply_filters( 'customcrm/edd_email_tracking/track_email', true, (string) $email->get_id(), $email, $atts ) ) {
			return 'filtered';
		}

		if ( ! fluentcrmTrackEmailOpen() && ! fluentcrmTrackClicking() ) {
			return 'tracking_off';
		}

		return '';
	}

	/**
	 * The pixel and the link rewrites, using FluentCRM's helpers and its global settings.
	 *
	 * @param string        $message Email HTML.
	 * @param CampaignEmail $row     The row opens and clicks record on.
	 */
	private static function addTracking( string $message, $row ): string {
		$click_tracking = fluentcrmTrackClicking();

		if ( $click_tracking ) {
			$message = self::markUntrackedLinks( $message );
			$urls    = Helper::urlReplaces( $message );

			if ( $urls ) {
				$message = 'anonymous' === $click_tracking
					? Helper::attachAnonymousUrls( $message, $urls, $row->id, $row->email_hash )
					: Helper::attachUrls( $message, $urls, $row->id, $row->email_hash );
			}

			$message = str_replace( self::SKIP_MARK, '', $message );
		}

		// Checks FluentCRM's open-tracking setting itself, and adds nothing when it is off.
		return Helper::injectTrackerPixel( $message, $row->email_hash, $row->id );
	}

	/**
	 * Hides the links that must keep their own URL from Helper::urlReplaces(), which only picks up
	 * `href="http…`. The mark is taken out again after the rewrite.
	 *
	 * @param string $message Email HTML.
	 */
	private static function markUntrackedLinks( string $message ): string {
		return (string) preg_replace_callback(
			'/(<a\b[^>]*?\bhref=)(["\'])(http[^"\']*)\2/i',
			static function ( $link ) {
				$url = html_entity_decode( $link[3] );

				if ( ! self::isUntrackedUrl( $url ) ) {
					return $link[0];
				}

				return $link[1] . $link[2] . self::SKIP_MARK . $link[3] . $link[2];
			},
			$message
		);
	}

	/**
	 * Links left as EDD wrote them: file downloads and FluentCRM's own routes.
	 *
	 * @param string $url Link URL.
	 */
	private static function isUntrackedUrl( string $url ): bool {
		$untracked = false !== strpos( $url, 'eddfile=' )
			|| false !== strpos( $url, 'fluentcrm=1' )
			|| false !== strpos( $url, 'ns_url=' );

		/**
		 * Filters whether a link in an EDD email keeps its own URL instead of FluentCRM's click link.
		 *
		 * @param bool   $untracked Default: download links and FluentCRM routes.
		 * @param string $url       Link URL.
		 */
		return (bool) apply_filters( 'customcrm/edd_email_tracking/skip_url', $untracked, $url );
	}

	/**
	 * Whether the email already has FluentCRM's open pixel or click links.
	 *
	 * @param string $message Email HTML.
	 */
	private static function hasFluentTracking( string $message ): bool {
		return 1 === preg_match( '/[?&](amp;)?route=open(&|&amp;)[^"\']*_e_hash=/', $message )
			|| ( false !== strpos( $message, 'ns_url=' ) && false !== strpos( $message, 'fch=' ) );
	}

	/**
	 * Whether the email is HTML, so a pixel and links can go in.
	 *
	 * @param string $message Email body.
	 */
	private static function isHtml( string $message ): bool {
		return 1 === preg_match( '/<(html|body|table|div|p|a|br)\b/i', $message );
	}

	/**
	 * The one address an email goes to, or '' when there are none or several.
	 *
	 * @param string|string[] $to wp_mail() `to`.
	 */
	private static function singleRecipient( $to ): string {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		$list = array_values( array_filter( array_map( 'trim', $list ) ) );

		if ( 1 !== count( $list ) ) {
			return '';
		}

		$address = $list[0];

		if ( preg_match( '/<([^>]+)>/', $address, $match ) ) {
			$address = $match[1];
		}

		$address = sanitize_email( $address );

		return is_email( $address ) ? strtolower( $address ) : '';
	}

	/**
	 * The campaign this EDD email's rows belong to, made the first time the email is tracked.
	 *
	 * @param object $email EDD email object.
	 * @return Campaign|null
	 */
	private static function campaignFor( $email ) {
		$email_id = (string) $email->get_id();

		if ( '' === $email_id ) {
			return null;
		}

		$map = get_option( self::CAMPAIGNS_OPTION, [] );
		$map = is_array( $map ) ? $map : [];

		if ( ! empty( $map[ $email_id ] ) ) {
			$campaign = Campaign::withoutGlobalScopes()
				->where( 'id', (int) $map[ $email_id ] )
				->where( 'type', self::CAMPAIGN_TYPE )
				->first();

			if ( $campaign ) {
				return $campaign;
			}
		}

		$title = self::titleFor( $email );

		$campaign = CustomEmailCampaign::create(
			[
				'title'           => $title,
				'slug'            => 'edd-email-' . $email_id,
				'status'          => 'published',
				'email_subject'   => $title,
				'email_body'      => sprintf(
					/* translators: %s: EDD email ID. */
					'<p>Easy Digital Downloads sends this email (EDD email ID <code>%s</code>). This record only collects FluentCRM opens and clicks for it; FluentCRM does not send it.</p>',
					esc_html( $email_id )
				),
				'design_template' => 'raw_html',
				'created_by'      => 0,
				'settings'        => [
					self::SETTING_KEY  => $email_id,
					'is_transactional' => 'yes',
					'sending_type'     => 'instant',
				],
			]
		);

		$map[ $email_id ] = (int) $campaign->id;
		update_option( self::CAMPAIGNS_OPTION, $map, false );

		return $campaign;
	}

	/**
	 * "EDD: <email name> (<when>) [<email ID>]", from EDD's own email list.
	 *
	 * @param object $email EDD email object.
	 */
	private static function titleFor( $email ): string {
		$email_id = (string) $email->get_id();
		$name     = '';
		$label    = '';

		try {
			if ( function_exists( 'edd_get_email_registry' ) ) {
				$template = edd_get_email_registry()->get_email_by_id( $email_id );

				if ( $template ) {
					$name  = method_exists( $template, 'get_name' ) ? (string) $template->get_name() : '';
					$label = method_exists( $template, 'get_context_label' ) ? (string) $template->get_context_label() : '';
				}
			}
			// A template EDD cannot find still has its row, whose subject names it well enough.
			if ( ! $name && function_exists( 'edd_get_email' ) ) {
				$row  = edd_get_email( $email_id );
				$name = $row && ! empty( $row->subject ) ? (string) $row->subject : '';
			}
		} catch ( \Throwable $e ) {
			$name = '';
		}

		$context = method_exists( $email, 'get_context' ) ? (string) $email->get_context() : '';
		$title   = 'EDD: ' . ( $name ? $name : $email_id );

		if ( $label && strtolower( $label ) !== strtolower( $context ) ) {
			$title .= ' (' . $label . ')';
		}

		return sanitize_text_field( $title . ' [' . $email_id . ']' );
	}

	/**
	 * Deletes rows made for a send that then failed to get its tracking.
	 *
	 * @param int $index This send's place in self::$sends.
	 */
	private static function forgetRows( int $index ): void {
		$row_ids = self::$sends[ $index ]['row_ids'] ?? [];

		if ( ! $row_ids ) {
			return;
		}

		try {
			CampaignEmail::whereIn( 'id', $row_ids )->delete();
		} catch ( \Throwable $e ) {
			\CustomCRM\AbandonCart\Edd\EddCartTracking::logError( 'EddEmailTracking::forgetRows', $e );
		}

		self::$sends[ $index ]['row_ids'] = [];
	}

	/**
	 * Adds one to a tracked or skipped count.
	 *
	 * @param string $reason `tracked` or why the email was skipped.
	 */
	private static function count( string $reason ): void {
		$stats = get_option( self::STATS_OPTION, [] );
		$stats = is_array( $stats ) ? $stats : [];

		$stats[ $reason ] = [
			'count' => (int) ( $stats[ $reason ]['count'] ?? 0 ) + 1,
			'last'  => current_time( 'mysql' ),
		];

		update_option( self::STATS_OPTION, $stats, false );
	}
}
