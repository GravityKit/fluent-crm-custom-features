<?php

namespace CustomCRM\LocalTime;

/**
 * Append-only log of every tracked click in a campaign email.
 *
 * FluentCRM keeps one aggregate row per recipient and link: the first click's time and IP, plus a
 * counter. A scanner click followed by a person's click on the same link cannot be told apart
 * there, so each click is stored here as its own row, with the user agent.
 *
 * Hooked to `fluent_crm/email_url_clicked` (RedirectionHandler::trackUrlClick), which fires once
 * per click after the email's token is verified. Smart links in a campaign email use the same
 * redirect first, so they are covered by it too. Opens are not logged.
 */
class ClickLog {

	public const TABLE_SUFFIX = 'customcrm_email_clicks';

	private const VERSION_OPTION = 'customcrm_email_clicks_db_version';

	private const VERSION = '1';

	public function register(): void {
		add_action( 'fluent_crm/email_url_clicked', [ $this, 'logClick' ], 10, 2 );
	}

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public function ensureTable(): void {
		$current = get_option( self::VERSION_OPTION );

		if ( self::VERSION === $current ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			clicked_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id),
			KEY email_id (email_id),
			KEY subscriber_id (subscriber_id)
		) {$charset};"
		);

		$table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table_exists ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}
	}

	/**
	 * @param object $campaign_email FluentCrm\App\Models\CampaignEmail.
	 * @param object $url_data       FluentCrm\App\Models\UrlStores row.
	 */
	public function logClick( $campaign_email, $url_data ): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- FluentCRM's own tracking-link flag.
			$is_anonymous = isset( $_REQUEST['ano'] );

			// Anonymous tracking is the sender's promise not to record who clicked.
			if ( $is_anonymous || ! is_object( $campaign_email ) ) {
				return;
			}

			$this->ensureTable();

			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
			$user_agent = mb_substr( sanitize_text_field( $user_agent ), 0, 255 );

			$ip = '';
			if ( function_exists( 'FluentCrm' ) && function_exists( 'fluentCrmWillAnonymizeIp' ) ) {
				$ip = (string) FluentCrm( 'request' )->getIp( fluentCrmWillAnonymizeIp() );
			}

			global $wpdb;

			$inserted = $wpdb->insert(
				self::tableName(),
				[
					'campaign_id'    => (int) $campaign_email->campaign_id,
					'email_id'       => (int) $campaign_email->id,
					'subscriber_id'  => (int) $campaign_email->subscriber_id,
					'url_id'         => is_object( $url_data ) ? (int) $url_data->id : 0,
					'ip'             => substr( $ip, 0, 45 ),
					'user_agent'     => $user_agent,
					'clicked_at_utc' => gmdate( 'Y-m-d H:i:s' ),
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				$this->reportOnce( $wpdb->last_error );
			}
		} catch ( \Throwable $e ) {
			$this->reportOnce( $e->getMessage() );
		}
	}

	/**
	 * One log line per request, so a broken table cannot flood the error log.
	 */
	private function reportOnce( string $message ): void {
		static $reported = false;

		if ( $reported ) {
			return;
		}

		$reported = true;
		error_log( 'CustomCRM ClickLog: click not logged: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
