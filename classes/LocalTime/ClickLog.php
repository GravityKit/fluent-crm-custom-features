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

	private const EXPORT_PAGE = 100;

	public function register(): void {
		add_action( 'fluent_crm/email_url_clicked', [ $this, 'logClick' ], 10, 2 );
		add_action( 'fluentcrm_before_subscribers_deleted', [ $this, 'forgetContacts' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'addExporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'addEraser' ] );
	}

	/**
	 * A deleted contact leaves no clicks or local-time records behind.
	 *
	 * @param int[] $contact_ids FluentCRM contact IDs.
	 *
	 * @return bool False if a delete failed.
	 */
	public function forgetContacts( $contact_ids ): bool {
		global $wpdb;

		$contact_ids = array_filter( array_map( 'intval', (array) $contact_ids ) );

		if ( ! $contact_ids ) {
			return true;
		}

		$ok           = true;
		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );

		foreach ( [ self::tableName(), AssignmentLog::table() ] as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE subscriber_id IN ({$placeholders})", $contact_ids ) );

			if ( false === $deleted ) {
				$ok = false;
				$this->reportOnce( 'deleting contact records failed: ' . $wpdb->last_error );
			}
		}

		return $ok;
	}

	/**
	 * @param array $exporters WordPress's personal data exporters.
	 */
	public function addExporter( $exporters ): array {
		$exporters['customcrm-email-clicks'] = [
			'exporter_friendly_name' => __( 'Campaign email clicks', 'fluent-crm-custom-features' ),
			'callback'               => [ $this, 'exportClicks' ],
		];

		return $exporters;
	}

	/**
	 * @param array $erasers WordPress's personal data erasers.
	 */
	public function addEraser( $erasers ): array {
		$erasers['customcrm-email-clicks'] = [
			'eraser_friendly_name' => __( 'Campaign email clicks', 'fluent-crm-custom-features' ),
			'callback'             => [ $this, 'eraseClicks' ],
		];

		return $erasers;
	}

	/**
	 * @return array{data: array, done: bool}
	 */
	public function exportClicks( string $email, int $page = 1 ): array {
		global $wpdb;

		$contact_id = $this->contactId( $email );

		if ( ! $contact_id ) {
			return [ 'data' => [], 'done' => true ];
		}

		$table = self::tableName();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, campaign_id, ip, user_agent, clicked_at_utc FROM {$table} WHERE subscriber_id = %d ORDER BY id LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$contact_id,
			self::EXPORT_PAGE,
			( max( 1, $page ) - 1 ) * self::EXPORT_PAGE
		) );
		$data  = [];

		foreach ( (array) $rows as $row ) {
			$data[] = [
				'group_id'    => 'customcrm-email-clicks',
				'group_label' => __( 'Campaign email clicks', 'fluent-crm-custom-features' ),
				'item_id'     => 'customcrm-email-click-' . $row->id,
				'data'        => [
					[ 'name' => __( 'Campaign ID', 'fluent-crm-custom-features' ), 'value' => $row->campaign_id ],
					[ 'name' => __( 'IP address', 'fluent-crm-custom-features' ), 'value' => $row->ip ],
					[ 'name' => __( 'User agent', 'fluent-crm-custom-features' ), 'value' => $row->user_agent ],
					[ 'name' => __( 'Clicked at (UTC)', 'fluent-crm-custom-features' ), 'value' => $row->clicked_at_utc ],
				],
			];
		}

		return [ 'data' => $data, 'done' => count( $data ) < self::EXPORT_PAGE ];
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function eraseClicks( string $email, int $page = 1 ): array {
		global $wpdb;

		$contact_id = $this->contactId( $email );
		$table      = self::tableName();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$had_clicks = $contact_id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE subscriber_id = %d", $contact_id ) ) > 0;

		$erased = $this->forgetContacts( [ $contact_id ] );

		return [
			'items_removed'  => $erased && $had_clicks,
			'items_retained' => ! $erased,
			'messages'       => $erased ? [] : [ __( 'Campaign email clicks could not be erased because of a database error.', 'fluent-crm-custom-features' ) ],
			'done'           => true,
		];
	}

	private function contactId( string $email ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email = %s", $email ) );
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
			if ( function_exists( 'FluentCrm' ) && function_exists( 'fluentCrmWillAnonymizeIp' ) && function_exists( 'fluentCrmWillTrackIp' ) && fluentCrmWillTrackIp() ) {
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
				$this->reportOnce( 'click not logged: ' . $wpdb->last_error );
			}
		} catch ( \Throwable $e ) {
			$this->reportOnce( 'click not logged: ' . $e->getMessage() );
		}
	}

	/**
	 * One log line per request, so a broken table cannot flood the error log. The message says what failed.
	 */
	private function reportOnce( string $message ): void {
		static $reported = false;

		if ( $reported ) {
			return;
		}

		$reported = true;
		error_log( 'CustomCRM ClickLog: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
