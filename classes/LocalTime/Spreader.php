<?php

namespace CustomCRM\LocalTime;

use DateTimeImmutable;
use DateTimeZone;
use FluentCrm\App\Models\Campaign;

/**
 * Moves a released local-time campaign's waiting rows from the campaign's send time to each
 * recipient's local send time, and records every assignment in `AssignmentLog`.
 *
 * Works in pages so a large campaign never loads every row at once. Every UPDATE is guarded on
 * the row still waiting at the campaign's time, so a row a sender has already claimed is left
 * alone. Called by `LocalTimeSending`; registers no hooks.
 */
final class Spreader {

	/**
	 * Rows read per SELECT.
	 */
	private const PAGE = 1000;

	/**
	 * Row ids per UPDATE.
	 */
	private const CHUNK = 500;

	/**
	 * Rows held in memory waiting for an UPDATE before all of them are written.
	 */
	private const MAX_PENDING = 5000;

	/**
	 * Resolved zones kept per (timezone, country, state); `state` is free text, so this is capped.
	 */
	private const MAX_ZONES = 5000;

	/**
	 * Stop this many seconds before the campaign's send time. A row still waiting then would go out
	 * at that time, hours early for most people, so the caller falls back for the rest instead.
	 */
	private const DEADLINE_MARGIN = 15;

	/**
	 * Give every waiting row of the campaign its recipient's send time.
	 *
	 * A holdout row goes out at the window start in the site's zone on the delivery date, the time
	 * the campaign would have used without local-time delivery. Its record keeps the contact's own
	 * zone, so its local hour can still be compared with the others.
	 *
	 * @param Campaign $campaign A campaign whose rows have been released for sending.
	 * @param array    $plan     The campaign's plan, from LocalTimeSending::getPlan().
	 *
	 * @return int|false Rows moved, or false on a database error, an unusable plan, or too little time
	 *                   left before the campaign's send time. Rows not moved yet are still at that time.
	 */
	public static function spread( Campaign $campaign, array $plan ) {
		global $wpdb;

		$campaign_id = (int) $campaign->id;
		$anchor      = (string) $campaign->scheduled_at;
		$date        = isset( $plan['date'] ) ? (string) $plan['date'] : '';

		if ( '' === $anchor || ! self::isDate( $date ) ) {
			self::logError( $campaign_id, 'no campaign send time or delivery date' );
			return false;
		}

		$site_zone = wp_timezone();
		$utc       = new DateTimeZone( 'UTC' );

		$context = [
			'campaign_id'  => $campaign_id,
			'anchor'       => $anchor,
			'date'         => $date,
			'site_zone'    => $site_zone,
			'utc'          => $utc,
			'not_before'   => new DateTimeImmutable( $anchor, $site_zone ),
			'window_start' => isset( $plan['start'] ) ? (int) $plan['start'] : SendTime::DEFAULT_WINDOW_START,
			'window_end'   => isset( $plan['end'] ) ? (int) $plan['end'] : SendTime::DEFAULT_WINDOW_END,
			'holdout'      => max( 0, min( 50, isset( $plan['holdout'] ) ? (int) $plan['holdout'] : 0 ) ),
			'deadline'     => (int) strtotime( $anchor ) - self::DEADLINE_MARGIN,
			'countries'    => SendTime::countryNameMap( apply_filters( 'fluent_crm/countries', [] ) ),
		];

		AssignmentLog::ensureTable();

		if ( ! self::forgetStale( $campaign_id, $anchor ) ) {
			return false;
		}

		$emails  = $wpdb->prefix . 'fc_campaign_emails';
		$people  = $wpdb->prefix . 'fc_subscribers';
		$zones   = [];
		$pending = [];
		$held    = 0;
		$moved   = 0;
		$after   = 0;

		do {
			if ( (int) current_time( 'timestamp' ) >= $context['deadline'] ) {
				self::logError( $campaign_id, 'ran out of time before the campaign send time' );
				return false;
			}

			$wpdb->last_error = '';

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.id, e.subscriber_id, s.timezone, s.country, s.state
				FROM {$emails} e
				LEFT JOIN {$people} s ON s.id = e.subscriber_id
				WHERE e.campaign_id = %d AND e.status = 'scheduled' AND e.scheduled_at = %s AND e.id > %d
				ORDER BY e.id ASC
				LIMIT %d",
				$campaign_id,
				$anchor,
				$after,
				self::PAGE
			) );

			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				self::logError( $campaign_id, 'reading rows failed: ' . $wpdb->last_error );
				return false;
			}

			foreach ( $rows as $row ) {
				$after = (int) $row->id;

				$zone_key = $row->timezone . '|' . $row->country . '|' . $row->state;

				if ( ! isset( $zones[ $zone_key ] ) ) {
					if ( count( $zones ) >= self::MAX_ZONES ) {
						$zones = [];
					}

					$zones[ $zone_key ] = SendTime::resolveZone( $row->timezone, $row->country, $row->state, $site_zone, $context['countries'] );
				}

				$entry  = self::assign( $context, (int) $row->id, (int) $row->subscriber_id, $zones[ $zone_key ] );
				$target = $entry['site_scheduled_at'];

				$pending[ $target ][] = $entry;
				++$held;

				if ( count( $pending[ $target ] ) >= self::CHUNK ) {
					$result = self::writeAll( $context, [ $target => $pending[ $target ] ] );

					if ( false === $result ) {
						return false;
					}

					$moved += $result;
					$held  -= count( $pending[ $target ] );
					unset( $pending[ $target ] );
				}
			}

			if ( $held >= self::MAX_PENDING ) {
				$result = self::writeAll( $context, $pending );

				if ( false === $result ) {
					return false;
				}

				$moved  += $result;
				$pending = [];
				$held    = 0;
			}
		} while ( count( $rows ) === self::PAGE );

		$result = self::writeAll( $context, $pending );

		if ( false === $result ) {
			return false;
		}

		return $moved + $result;
	}

	/**
	 * One row's send time and its assignment record.
	 *
	 * @param array $context  Values shared by every row of the campaign, from spread().
	 * @param array $resolved From SendTime::resolveZone().
	 *
	 * @return array<string, mixed> An AssignmentLog row.
	 */
	private static function assign( array $context, int $email_id, int $subscriber_id, array $resolved ): array {
		$holdout = SendTime::isHoldout( $context['campaign_id'], $subscriber_id, $context['holdout'] );

		if ( $holdout ) {
			$slot = null;
			$send = SendTime::sendOn( $context['date'], $context['site_zone'], $context['window_start'], $context['not_before'] );
		} else {
			$slot = SendTime::slotMinutes( $context['campaign_id'], $subscriber_id, $context['window_start'], $context['window_end'] );
			$send = SendTime::sendOn( $context['date'], $resolved['zone'], $slot, $context['not_before'] );
		}

		return [
			'campaign_id'       => $context['campaign_id'],
			'email_id'          => $email_id,
			'subscriber_id'     => $subscriber_id,
			'zone'              => $resolved['zone']->getName(),
			'zone_source'       => $resolved['source'],
			'slot_minutes'      => $slot,
			'holdout'           => $holdout ? 1 : 0,
			'intended_at_utc'   => $send->setTimezone( $context['utc'] )->format( 'Y-m-d H:i:s' ),
			'site_scheduled_at' => SendTime::siteTimeNotBefore( $send, $context['site_zone'] ),
		];
	}

	/**
	 * Records every pending row, then moves each group to its send time.
	 *
	 * The records go in first: if a move then fails, the next spread finds those rows still at the
	 * campaign's time and replaces their records, whereas a moved row with no record would never
	 * be recorded, because it is no longer selected.
	 *
	 * @param array                            $context From spread().
	 * @param array<string, array<int, array>> $pending Assignment rows by target send time.
	 *
	 * @return int|false Rows moved, or false on a database error.
	 */
	private static function writeAll( array $context, array $pending ) {
		global $wpdb;

		if ( ! $pending ) {
			return 0;
		}

		if ( ! AssignmentLog::insertMany( array_merge( ...array_values( $pending ) ) ) ) {
			self::logError( $context['campaign_id'], 'recording assignments failed: ' . $wpdb->last_error );
			return false;
		}

		$moved = 0;

		foreach ( $pending as $target => $entries ) {
			$result = self::move( $context, (string) $target, $entries );

			if ( false === $result ) {
				return false;
			}

			$moved += $result;
		}

		return $moved;
	}

	/**
	 * Moves a group of rows that share a send time to it.
	 *
	 * @param array $context From spread().
	 * @param array $entries Assignment rows, all with this target, already recorded.
	 *
	 * @return int|false Rows moved, or false on a database error.
	 */
	private static function move( array $context, string $target, array $entries ) {
		global $wpdb;

		$campaign_id = $context['campaign_id'];

		if ( ! $entries || $target === $context['anchor'] ) {
			return 0;
		}

		$ids          = array_map( 'intval', array_column( $entries, 'email_id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$emails       = $wpdb->prefix . 'fc_campaign_emails';

		$wpdb->last_error = '';

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$emails} SET scheduled_at = %s
			WHERE id IN ($placeholders) AND campaign_id = %d AND status = 'scheduled' AND scheduled_at = %s",
			array_merge( [ $target ], $ids, [ $campaign_id, $context['anchor'] ] )
		) );

		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			self::logError( $campaign_id, 'moving rows failed: ' . $wpdb->last_error );
			return false;
		}

		$updated = (int) $updated;

		// A sender claimed some of these between the SELECT and the UPDATE: drop the records of
		// the rows that did not move, so the log only says where rows actually went.
		if ( $updated !== count( $ids ) && ! self::forgetUnmoved( $campaign_id, $ids, $target ) ) {
			return false;
		}

		return $updated;
	}

	/**
	 * Deletes the campaign's records except those whose row still sits, in this campaign, at the
	 * time recorded and is not waiting at the campaign's time (those are recorded again). A retried
	 * half-finished spread keeps the records of rows it already moved; those rows are not selected
	 * again.
	 */
	private static function forgetStale( int $campaign_id, string $anchor ): bool {
		global $wpdb;

		$log    = AssignmentLog::table();
		$emails = $wpdb->prefix . 'fc_campaign_emails';

		$wpdb->last_error = '';

		$wpdb->query( $wpdb->prepare(
			"DELETE l FROM {$log} l
			LEFT JOIN {$emails} e ON e.id = l.email_id
			WHERE l.campaign_id = %d
			AND (
				e.id IS NULL
				OR e.campaign_id IS NULL
				OR e.campaign_id <> l.campaign_id
				OR e.scheduled_at IS NULL
				OR e.scheduled_at <> l.site_scheduled_at
				OR ( e.status = 'scheduled' AND e.scheduled_at = %s )
			)",
			$campaign_id,
			$anchor
		) );

		if ( '' !== (string) $wpdb->last_error ) {
			self::logError( $campaign_id, 'clearing earlier assignments failed: ' . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Deletes the records of rows in $ids that are not at $target.
	 *
	 * @param int[] $ids Row ids.
	 */
	private static function forgetUnmoved( int $campaign_id, array $ids, string $target ): bool {
		global $wpdb;

		$log          = AssignmentLog::table();
		$emails       = $wpdb->prefix . 'fc_campaign_emails';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->last_error = '';

		$wpdb->query( $wpdb->prepare(
			"DELETE l FROM {$log} l
			LEFT JOIN {$emails} e ON e.id = l.email_id
			WHERE l.campaign_id = %d AND l.email_id IN ($placeholders)
			AND ( e.id IS NULL OR e.scheduled_at IS NULL OR e.scheduled_at <> %s )",
			array_merge( [ $campaign_id ], $ids, [ $target ] )
		) );

		if ( '' !== (string) $wpdb->last_error ) {
			self::logError( $campaign_id, 'clearing records of unmoved rows failed: ' . $wpdb->last_error );
			return false;
		}

		return true;
	}

	private static function isDate( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	private static function logError( int $campaign_id, string $message ): void {
		error_log( 'CustomCRM local time: spreading campaign ' . $campaign_id . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
