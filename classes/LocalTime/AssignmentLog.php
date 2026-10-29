<?php

namespace CustomCRM\LocalTime;

/**
 * One row per recipient of a local-time campaign: the zone they were put in, how that zone was
 * worked out, and when their email was meant to go out. Written once when the campaign is spread
 * and never updated, so later analysis can compare what was intended with what happened.
 */
class AssignmentLog {

	public const VERSION = '1';

	public const VERSION_OPTION = 'customcrm_local_time_sends_db_version';

	/**
	 * Rows per INSERT, to stay under MySQL's packet limit.
	 */
	private const CHUNK = 200;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'customcrm_local_time_sends';
	}

	/**
	 * Creates or upgrades the table. One option read when it is already current.
	 */
	public static function ensureTable(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  email_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subscriber_id bigint(20) unsigned NOT NULL,
  zone varchar(64) NOT NULL DEFAULT '',
  zone_source varchar(16) NOT NULL DEFAULT '',
  slot_minutes smallint(5) unsigned DEFAULT NULL,
  holdout tinyint(1) unsigned NOT NULL DEFAULT 0,
  intended_at_utc datetime NOT NULL,
  site_scheduled_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY campaign_id (campaign_id),
  KEY email_id (email_id)
) {$charset};";

		dbDelta( $sql );

		if ( '' !== (string) $wpdb->last_error ) {
			return;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Adds assignment rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Each with campaign_id, email_id, subscriber_id,
	 *     zone, zone_source, slot_minutes (null for a holdout), holdout, intended_at_utc and
	 *     site_scheduled_at. created_at defaults to now.
	 *
	 * @return bool False if any insert failed.
	 */
	public static function insertMany( array $rows ): bool {
		if ( ! $rows ) {
			return true;
		}

		global $wpdb;

		self::ensureTable();

		$table   = self::table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$columns = '(campaign_id, email_id, subscriber_id, zone, zone_source, slot_minutes, holdout, intended_at_utc, site_scheduled_at, created_at)';

		foreach ( array_chunk( array_values( $rows ), self::CHUNK ) as $chunk ) {
			$values = [];

			foreach ( $chunk as $row ) {
				$has_slot = isset( $row['slot_minutes'] ) && null !== $row['slot_minutes'];

				// $wpdb->prepare() cannot bind NULL, so it goes in the template.
				$template = $has_slot ? '(%d, %d, %d, %s, %s, %d, %d, %s, %s, %s)' : '(%d, %d, %d, %s, %s, NULL, %d, %s, %s, %s)';
				$args     = [
					(int) $row['campaign_id'],
					(int) ( $row['email_id'] ?? 0 ),
					(int) $row['subscriber_id'],
					(string) ( $row['zone'] ?? '' ),
					(string) ( $row['zone_source'] ?? '' ),
				];

				if ( $has_slot ) {
					$args[] = (int) $row['slot_minutes'];
				}

				$args[] = empty( $row['holdout'] ) ? 0 : 1;
				$args[] = (string) $row['intended_at_utc'];
				$args[] = (string) $row['site_scheduled_at'];
				$args[] = (string) ( $row['created_at'] ?? $now );

				$values[] = $wpdb->prepare( $template, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

			$wpdb->last_error = '';
			$wpdb->query( "INSERT INTO {$table} {$columns} VALUES " . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
		}

		return true;
	}

	public static function deleteForCampaign( int $campaign_id ): void {
		global $wpdb;

		self::ensureTable();

		$table = self::table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE campaign_id = %d", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array<int, array<string, mixed>> Rows in insertion order, empty on a database error.
	 */
	public static function forCampaign( int $campaign_id ): array {
		global $wpdb;

		self::ensureTable();

		$table = self::table();

		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY id ASC", $campaign_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return [];
		}

		return $rows;
	}
}
