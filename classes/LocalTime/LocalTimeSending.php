<?php

namespace CustomCRM\LocalTime;

use DateTimeImmutable;
use DateTimeZone;
use FluentCrm\App\Hooks\Handlers\Scheduler;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Modules\MCP\Tools\CampaignTools;
use FluentCrm\App\Services\CampaignProcessor;
use FluentCrm\App\Services\Helper;

/**
 * Delivers a campaign on its scheduled date inside a window (8:00-11:00 by default) in each
 * recipient's own zone. Scheduling moves the FluentCRM send time back to when that window first
 * opens anywhere (the anchor), since no row may go out before the campaign's time.
 *
 * FluentCRM 3.2 has no hook for a row's send time, so rows are created here an hour early with its
 * own CampaignProcessor and moved by `Spreader` before the anchor. If that cannot finish in time,
 * the campaign sends normally at the picked time and the site admin is emailed.
 */
class LocalTimeSending {

	/**
	 * Campaign meta holding the plan, see getPlan().
	 */
	public const PLAN_META = '_gk_local_time';

	/**
	 * Holds the plan generation that has been fully handled (spread or fallen back).
	 */
	public const DONE_META = '_gk_local_time_done';

	/**
	 * What last happened to the campaign: planned, moved, fell back, not scheduled, and why.
	 */
	public const RESULT_META = '_gk_local_time_result';

	/**
	 * Holds the plan generation the fallback email was sent for, so it goes out once.
	 */
	public const ALERT_META = '_gk_local_time_alerted';

	/**
	 * How far ahead of the anchor a campaign's rows are created.
	 */
	private const LEAD_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Closer than this to the anchor, rows are no longer moved: a row claimed mid-move would go
	 * out at the anchor, hours early for most people.
	 */
	private const MIN_MARGIN_SECONDS = 2 * MINUTE_IN_SECONDS;

	/**
	 * A delivery date works only if its window first opens at least this far ahead, which leaves
	 * the hour of row creation plenty of room.
	 */
	private const MIN_NOTICE_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * wp_options lock that keeps two scheduler runs from advancing campaigns at the same time.
	 */
	private const LOCK_KEY = '_customcrm_lock_local_time';

	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	public function register(): void {
		// Fired by FluentCRM's Scheduler::process() on the Action Scheduler tick, the WP-Cron
		// fallback and the admin-ping fallback alike, inside its minute lock and before sending.
		add_action( 'fluentcrm_process_scheduled_tasks_init', [ $this, 'tick' ] );
		add_action( 'fluent_crm/campaign_scheduled', [ $this, 'onScheduled' ], 10, 1 );
		add_action( 'fluent_crm/campaign_set_send_now', [ $this, 'forgetDate' ] );
	}

	/**
	 * The campaign's plan, with defaults filled in. `start`/`end`: minutes after local midnight;
	 * `holdout`: percent sent on the normal schedule; `date`: local delivery date; `picked_at`,
	 * `anchor`, `fallback_at`: site times (picked, set as `scheduled_at`, fallen back to);
	 * `generation`: unique per scheduling attempt.
	 *
	 * @return array{enabled: bool, start: int, end: int, holdout: int, date: string, picked_at: string, anchor: string, generation: string, fallback_at: string}
	 */
	public static function getPlan( int $campaign_id ): array {
		$plan = fluentcrm_get_campaign_meta( $campaign_id, self::PLAN_META, true );
		$plan = is_array( $plan ) ? $plan : [];

		return [
			'enabled'     => ! empty( $plan['enabled'] ),
			'start'       => isset( $plan['start'] ) ? (int) $plan['start'] : SendTime::DEFAULT_WINDOW_START,
			'end'         => isset( $plan['end'] ) ? (int) $plan['end'] : SendTime::DEFAULT_WINDOW_END,
			'holdout'     => isset( $plan['holdout'] ) ? (int) $plan['holdout'] : 0,
			'date'        => isset( $plan['date'] ) ? (string) $plan['date'] : '',
			'picked_at'   => isset( $plan['picked_at'] ) ? (string) $plan['picked_at'] : '',
			'anchor'      => isset( $plan['anchor'] ) ? (string) $plan['anchor'] : '',
			'generation'  => isset( $plan['generation'] ) ? (string) $plan['generation'] : '',
			'fallback_at' => isset( $plan['fallback_at'] ) ? (string) $plan['fallback_at'] : '',
		];
	}

	public static function savePlan( int $campaign_id, array $plan ): void {
		fluentcrm_update_campaign_meta( $campaign_id, self::PLAN_META, $plan );
	}

	/**
	 * Whether local-time delivery can still be promised on $date.
	 *
	 * @param string $date Local delivery date, Y-m-d.
	 * @param array  $plan The plan; only `start` is read.
	 *
	 * @return array{ok: bool, message: string, earliest_date: string}
	 */
	public static function feasibility( string $date, array $plan ): array {
		$start    = isset( $plan['start'] ) ? (int) $plan['start'] : SendTime::DEFAULT_WINDOW_START;
		$earliest = self::earliestDate( $start );
		$utc      = new DateTimeZone( 'UTC' );
		$parsed   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $utc );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return [
				'ok'            => false,
				'message'       => sprintf(
					/* translators: %s: a date such as "Nov 7". */
					__( 'Pick a delivery date. The earliest date that works is %s.', 'fluent-crm-custom-features' ),
					self::dateLabel( $earliest )
				),
				'earliest_date' => $earliest,
			];
		}

		$lead = SendTime::firstWindowOpens( $date, $start )->getTimestamp() - time();

		if ( $lead >= self::MIN_NOTICE_SECONDS ) {
			return [
				'ok'            => true,
				'message'       => '',
				'earliest_date' => $earliest,
			];
		}

		$reason = $lead <= 0
			/* translators: %s: a time such as "8:00 AM". */
			? __( '%s on that date has already started somewhere.', 'fluent-crm-custom-features' )
			/* translators: %s: a time such as "8:00 AM". */
			: __( '%s on that date starts somewhere in less than 2 hours.', 'fluent-crm-custom-features' );

		return [
			'ok'            => false,
			'message'       => sprintf(
				/* translators: 1: a date such as "Nov 6", 2: why it is too late, 3: a date such as "Nov 7". */
				__( 'Too late for local-time delivery on %1$s: %2$s The earliest date that works is %3$s.', 'fluent-crm-custom-features' ),
				self::dateLabel( $date ),
				sprintf( $reason, gmdate( 'g:i A', $start * MINUTE_IN_SECONDS ) ),
				self::dateLabel( $earliest )
			),
			'earliest_date' => $earliest,
		];
	}

	/**
	 * The first date whose window opens at least MIN_NOTICE_SECONDS from now.
	 */
	private static function earliestDate( int $start ): string {
		$day = new DateTimeImmutable( 'yesterday', new DateTimeZone( 'UTC' ) );

		for ( $i = 0; $i < 7; $i++ ) {
			$date = $day->format( 'Y-m-d' );

			if ( SendTime::firstWindowOpens( $date, $start )->getTimestamp() - time() >= self::MIN_NOTICE_SECONDS ) {
				return $date;
			}

			$day = $day->modify( '+1 day' );
		}

		return $day->format( 'Y-m-d' );
	}

	/**
	 * "Nov 6", with the year when it is not this year.
	 */
	private static function dateLabel( string $date ): string {
		$utc       = new DateTimeZone( 'UTC' );
		$timestamp = ( new DateTimeImmutable( $date . ' 12:00:00', $utc ) )->getTimestamp();
		$format    = gmdate( 'Y', $timestamp ) === gmdate( 'Y' ) ? 'M j' : 'M j, Y';

		return wp_date( $format, $timestamp, $utc );
	}

	/**
	 * Turn the time FluentCRM was given into a delivery date, and start the campaign when that
	 * date's window first opens anywhere. A date too close to promise puts the campaign back to
	 * draft.
	 *
	 * @param Campaign $campaign The campaign FluentCRM just scheduled.
	 */
	public function onScheduled( $campaign ): void {
		$campaign_id = (int) $campaign->id;
		$plan        = self::getPlan( $campaign_id );

		if ( ! $plan['enabled'] ) {
			return;
		}

		$settings = $campaign->settings;

		if ( is_array( $settings ) && isset( $settings['sending_type'] ) && 'range_schedule' === $settings['sending_type'] ) {
			self::savePlan( $campaign_id, self::cleared( $plan ) );
			$this->record( $campaign_id, 'skipped: the campaign uses a sending time range' );
			return;
		}

		$picked_at = (string) $campaign->scheduled_at;
		$date      = ( new DateTimeImmutable( $picked_at, wp_timezone() ) )->format( 'Y-m-d' );
		$check     = self::feasibility( $date, $plan );

		if ( ! $check['ok'] ) {
			self::savePlan( $campaign_id, self::cleared( $plan ) );
			$reverted = $this->revertToDraft( $campaign_id );
			$this->record( $campaign_id, ( $reverted ? 'not scheduled, back to draft: ' : 'not scheduled: ' ) . $check['message'] );

			if ( $reverted ) {
				$this->alertReverted( $campaign, $picked_at, $check['message'] );
			}

			return;
		}

		$plan = $this->startGeneration( $plan, $date, $picked_at );

		// Saved before the campaign moves, so a tick in between re-plans from the picked time.
		self::savePlan( $campaign_id, $plan );
		fluentcrm_delete_campaign_meta( $campaign_id, self::DONE_META );

		$moved = Campaign::withoutGlobalScope( 'type' )
			->where( 'id', $campaign_id )
			->where( 'status', 'pending-scheduled' )
			->update( [ 'scheduled_at' => $plan['anchor'] ] );

		$this->record(
			$campaign_id,
			$moved
				? sprintf( 'planned: delivering on %s; FluentCRM starts at %s', $date, $plan['anchor'] )
				: sprintf( 'planned for %s, but the campaign was no longer waiting to start, so its send time was not moved', $date )
		);
	}

	/**
	 * "Send now" sends to everyone at once, so a stored delivery date no longer applies.
	 *
	 * @param Campaign $campaign The campaign FluentCRM is sending now.
	 */
	public function forgetDate( $campaign ): void {
		$campaign_id = (int) $campaign->id;
		$plan        = self::getPlan( $campaign_id );

		if ( '' !== $plan['date'] || '' !== $plan['generation'] ) {
			self::savePlan( $campaign_id, self::cleared( $plan ) );
		}
	}

	/**
	 * Advance every active local-time campaign. Runs once a minute; one query when there is
	 * nothing to do.
	 */
	public function tick(): void {
		// Runs inside FluentCRM's per-minute Scheduler::process(), before its sender: an exception
		// that got out of here would skip that minute's sending for every campaign.
		try {
			$this->run();
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM local time: tick failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function run(): void {
		global $wpdb;

		$campaign_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT c.id FROM {$wpdb->prefix}fc_campaigns c
			INNER JOIN {$wpdb->prefix}fc_meta m ON m.object_id = c.id AND m.object_type = %s AND m.`key` = %s
			WHERE c.status IN ('pending-scheduled', 'processing', 'scheduled', 'working')",
			Campaign::class,
			self::PLAN_META
		) );

		if ( $wpdb->last_error ) {
			error_log( 'CustomCRM local time: campaign lookup failed: ' . $wpdb->last_error );
			return;
		}

		if ( ! $campaign_ids ) {
			return;
		}

		$token = wp_generate_uuid4();

		if ( ! Helper::acquireDbLock( self::LOCK_KEY, self::LOCK_TTL, $token ) ) {
			return;
		}

		try {
			foreach ( $campaign_ids as $campaign_id ) {
				if ( ! Helper::refreshDbLock( self::LOCK_KEY, $token ) ) {
					break;
				}

				try {
					$this->check( (int) $campaign_id );
				} catch ( \Throwable $e ) {
					$this->failSafe( (int) $campaign_id, $e );
				}
			}
		} finally {
			Helper::deleteDbLock( self::LOCK_KEY, $token );
		}
	}

	/**
	 * One campaign's step threw. The next minute tries again; but once the anchor is close, retrying
	 * is no longer safe, since rows still waiting at the anchor would go out hours early. Send
	 * normally at the picked time instead.
	 *
	 * @param int        $campaign_id FluentCRM campaign ID.
	 * @param \Throwable $error       What was thrown.
	 */
	private function failSafe( int $campaign_id, \Throwable $error ): void {
		error_log( 'CustomCRM local time: campaign ' . $campaign_id . ' failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		try {
			$plan = self::getPlan( $campaign_id );

			$is_open = $plan['enabled'] && '' !== $plan['date'] && '' !== $plan['anchor'] && '' === $plan['fallback_at'];
			$is_done = fluentcrm_get_campaign_meta( $campaign_id, self::DONE_META, true ) === $plan['generation'];

			if ( ! $is_open || $is_done || $this->secondsLeft( $plan ) >= self::MIN_MARGIN_SECONDS ) {
				return;
			}

			$campaign = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

			if ( $campaign ) {
				$this->fallBack( $campaign, $plan, 'a server error stopped the emails being given local send times before the local-time send began' );
			}
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM local time: the fallback for campaign ' . $campaign_id . ' failed too: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Decide what one campaign needs this minute.
	 */
	protected function check( int $campaign_id ): void {
		$plan = self::getPlan( $campaign_id );

		if ( ! $plan['enabled'] || '' === $plan['date'] ) {
			return;
		}

		$campaign = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		$scheduled_at   = (string) $campaign->scheduled_at;
		$is_fallen_back = '' !== $plan['fallback_at'] && $scheduled_at === $plan['fallback_at'];

		if ( $scheduled_at !== $plan['anchor'] && ! $is_fallen_back ) {
			$this->replan( $campaign, $plan );
			return;
		}

		$is_done = fluentcrm_get_campaign_meta( $campaign_id, self::DONE_META, true ) === $plan['generation'];

		if ( $is_done ) {
			if ( '' !== $plan['fallback_at'] ) {
				$this->keepFallback( $campaign, $plan );
			}
			return;
		}

		if ( strtotime( $scheduled_at ) - current_time( 'timestamp' ) > self::LEAD_SECONDS ) {
			return;
		}

		$this->advance( $campaign_id );
	}

	/**
	 * Move one local-time campaign a step along: queue its rows, then spread them once released.
	 *
	 * @param int $campaign_id FluentCRM campaign ID.
	 */
	public function advance( int $campaign_id ): void {
		$plan       = self::getPlan( $campaign_id );
		$generation = $plan['generation'];

		if ( ! $plan['enabled'] || '' === $plan['date'] || '' === $generation ) {
			return;
		}

		if ( fluentcrm_get_campaign_meta( $campaign_id, self::DONE_META, true ) === $generation ) {
			return;
		}

		$campaign = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

		// A campaign whose time moved since the plan was made is re-planned by check() first.
		if ( ! $campaign || (string) $campaign->scheduled_at !== $plan['anchor'] ) {
			return;
		}

		if ( 'pending-scheduled' === $campaign->status ) {
			// The same guarded flip FluentCRM's scheduler makes 6 minutes before the send time.
			$started = Campaign::withoutGlobalScope( 'type' )
				->where( 'id', $campaign_id )
				->where( 'status', 'pending-scheduled' )
				->update( [ 'status' => 'processing' ] );

			if ( ! $started ) {
				return;
			}

			$campaign->status = 'processing';
		}

		if ( 'processing' === $campaign->status ) {
			$processed = ( new CampaignProcessor( $campaign_id ) )->processEmails();

			if ( $processed ) {
				$campaign = $processed;
			}

			// Rows are released only once the last chunk is in; until then, try again next minute.
			if ( 'scheduled' !== $campaign->status ) {
				if ( 'processing' === $campaign->status && $this->secondsLeft( $plan ) < self::MIN_MARGIN_SECONDS ) {
					$this->fallBack( $campaign, $plan, 'FluentCRM had not finished queueing the recipients before the local-time send began' );
				}
				return;
			}
		}

		if ( ! in_array( $campaign->status, [ 'scheduled', 'working' ], true ) ) {
			return;
		}

		if ( $this->secondsLeft( $plan ) < self::MIN_MARGIN_SECONDS ) {
			$this->fallBack( $campaign, $plan, 'the emails had not been given local send times before the local-time send began' );
			return;
		}

		$moved = Spreader::spread( $campaign, $plan );

		if ( false === $moved ) {
			if ( $this->secondsLeft( $plan ) < self::MIN_MARGIN_SECONDS ) {
				$this->fallBack( $campaign, $plan, 'giving the emails their local send times did not finish before the local-time send began' );
			}
			return;
		}

		$this->finish( $campaign_id, $generation, sprintf( 'moved %d emails to local time', $moved ) );
	}

	/**
	 * Seconds until the plan's anchor, in FluentCRM's site-time arithmetic.
	 */
	private function secondsLeft( array $plan ): int {
		return (int) strtotime( $plan['anchor'] ) - (int) current_time( 'timestamp' );
	}

	/**
	 * Re-plan a campaign whose `scheduled_at` was changed without `campaign_scheduled` firing
	 * (FluentCRM's CampaignController::updateCampaignTitle() does this for a `scheduled` campaign,
	 * moving every unsent row to the new time). The new time is the picked time.
	 *
	 * @param Campaign $campaign The campaign.
	 * @param array    $plan     Its plan.
	 */
	private function replan( Campaign $campaign, array $plan ): void {
		global $wpdb;

		$campaign_id = (int) $campaign->id;
		$observed    = (string) $campaign->scheduled_at;
		$old_anchor  = $plan['anchor'];

		// A plan saved before anchors were recorded: the campaign already sits at its anchor.
		if ( '' === $old_anchor ) {
			$plan['anchor']     = $observed;
			$plan['generation'] = wp_generate_uuid4();
			self::savePlan( $campaign_id, $plan );
			return;
		}

		$date  = ( new DateTimeImmutable( $observed, wp_timezone() ) )->format( 'Y-m-d' );
		$check = self::feasibility( $date, $plan );

		if ( ! $check['ok'] ) {
			if ( 'pending-scheduled' === $campaign->status ) {
				self::savePlan( $campaign_id, self::cleared( $plan ) );
				$reverted = $this->revertToDraft( $campaign_id );
				$this->record( $campaign_id, ( $reverted ? 'not scheduled, back to draft: ' : 'not scheduled: ' ) . $check['message'] );

				if ( $reverted ) {
					$this->alertReverted( $campaign, $observed, $check['message'] );
				}

				return;
			}

			// Its rows already wait at the new time, so it sends there as FluentCRM would.
			$plan                = $this->startGeneration( $plan, $date, $observed );
			$plan['anchor']      = $observed;
			$plan['fallback_at'] = $observed;
			self::savePlan( $campaign_id, $plan );
			$this->finish( $campaign_id, $plan['generation'], sprintf( 'fell back: the send time was changed to %s outside the planner. %s Sending normally at that time.', $observed, $check['message'] ) );
			$this->alert( $campaign, $plan, sprintf( 'The send time was changed to %s outside the local-time planner. %s', $observed, $check['message'] ), $observed );
			return;
		}

		$plan = $this->startGeneration( $plan, $date, $observed );
		self::savePlan( $campaign_id, $plan );
		fluentcrm_delete_campaign_meta( $campaign_id, self::DONE_META );

		$moved = Campaign::withoutGlobalScope( 'type' )
			->where( 'id', $campaign_id )
			->where( 'status', $campaign->status )
			->where( 'scheduled_at', $observed )
			->update( [ 'scheduled_at' => $plan['anchor'] ] );

		if ( ! $moved ) {
			return;
		}

		// Released rows hold the new time; rows still being queued hold the old anchor.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}fc_campaign_emails SET scheduled_at = %s
			WHERE campaign_id = %d AND status IN ('scheduling', 'scheduled') AND scheduled_at IN (%s, %s)",
			$plan['anchor'],
			$campaign_id,
			$observed,
			$old_anchor
		) );

		if ( false === $updated ) {
			error_log( 'CustomCRM local time: re-planning emails of campaign ' . $campaign_id . ' failed: ' . $wpdb->last_error );
		}

		$this->record( $campaign_id, sprintf( 'planned again: the send time was changed to %s; delivering on %s; FluentCRM starts at %s', $observed, $date, $plan['anchor'] ) );
	}

	/**
	 * Give up on local times for this attempt and send at the time the person picked (never
	 * before the anchor), then email the site admin.
	 *
	 * @param Campaign $campaign The campaign.
	 * @param array    $plan     The plan captured when this attempt started.
	 * @param string   $reason   Why, in plain words.
	 */
	private function fallBack( Campaign $campaign, array $plan, string $reason ): void {
		global $wpdb;

		$campaign_id = (int) $campaign->id;

		if ( ! $this->isCurrent( $campaign_id, $plan['generation'] ) ) {
			return;
		}

		$fallback_at = max( $plan['picked_at'], $plan['anchor'] );

		// FluentCRM's per-campaign materialization lock: while it is held here no chunk is in
		// flight, and the next chunk re-reads the campaign and takes its new scheduled_at.
		$lock_key = '_fluentcrm_lock_campaign_processing_' . $campaign_id;
		$token    = wp_generate_uuid4();
		$locked   = Helper::acquireDbLock( $lock_key, 90, $token );

		try {
			Campaign::withoutGlobalScope( 'type' )
				->where( 'id', $campaign_id )
				->whereIn( 'status', [ 'pending-scheduled', 'processing', 'scheduled' ] )
				->where( 'scheduled_at', $plan['anchor'] )
				->update( [ 'scheduled_at' => $fallback_at ] );

			$updated = $this->moveAnchorRows( $campaign_id, $plan['anchor'], $fallback_at );
		} finally {
			if ( $locked ) {
				Helper::deleteDbLock( $lock_key, $token );
			}
		}

		$plan['fallback_at'] = $fallback_at;

		if ( ! $this->isCurrent( $campaign_id, $plan['generation'] ) ) {
			return;
		}

		self::savePlan( $campaign_id, $plan );

		$note = false === $updated ? ' Moving the emails failed with a database error; they may still send at the earlier time.' : '';

		$this->finish( $campaign_id, $plan['generation'], sprintf( 'fell back: %s. Sending normally at %s.%s', $reason, $fallback_at, $note ) );
		$this->alert( $campaign, $plan, ucfirst( $reason ) . '.' . $note, $fallback_at );
	}

	/**
	 * After a fallback, move any row still at the anchor (a chunk that was being inserted while
	 * the fallback ran) and keep a still-queueing campaign moving.
	 */
	private function keepFallback( Campaign $campaign, array $plan ): void {
		if ( $plan['fallback_at'] === $plan['anchor'] ) {
			return;
		}

		$this->moveAnchorRows( (int) $campaign->id, $plan['anchor'], $plan['fallback_at'] );

		if ( 'processing' === $campaign->status ) {
			( new CampaignProcessor( (int) $campaign->id ) )->processEmails();
		}
	}

	/**
	 * @return int|false Rows moved, or false on a database error.
	 */
	private function moveAnchorRows( int $campaign_id, string $anchor, string $to ) {
		global $wpdb;

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}fc_campaign_emails SET scheduled_at = %s
			WHERE campaign_id = %d AND status IN ('scheduling', 'scheduled') AND scheduled_at = %s",
			$to,
			$campaign_id,
			$anchor
		) );

		if ( false === $updated ) {
			error_log( 'CustomCRM local time: moving emails of campaign ' . $campaign_id . ' to the fallback time failed: ' . $wpdb->last_error );
		}

		return $updated;
	}

	/**
	 * Email the site admin that a campaign fell back, once per attempt.
	 *
	 * @param Campaign $campaign The campaign.
	 * @param array    $plan     The plan of this attempt.
	 * @param string   $reason   Why, in plain words.
	 * @param string   $send_at  Site time it now sends, Y-m-d H:i:s.
	 */
	private function alert( Campaign $campaign, array $plan, string $reason, string $send_at ): void {
		$campaign_id = (int) $campaign->id;

		if ( fluentcrm_get_campaign_meta( $campaign_id, self::ALERT_META, true ) === $plan['generation'] ) {
			return;
		}

		fluentcrm_update_campaign_meta( $campaign_id, self::ALERT_META, $plan['generation'] );

		try {
			$when  = ( new DateTimeImmutable( $send_at, wp_timezone() ) )->format( 'M j, Y g:i A' ) . ' (' . wp_timezone_string() . ')';
			$title = (string) $campaign->title;

			$this->mailAdmin( $campaign_id, 'Local-time delivery fell back for campaign: ' . $title, [
				sprintf( 'Local-time delivery could not be set up for the campaign "%s", so it will go to everyone at the time it was scheduled for.', $title ),
				'Why: ' . $reason,
				'It will now send at: ' . $when,
			] );
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM local time: the fallback email for campaign ' . $campaign_id . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Email the site admin that a campaign was put back to draft and will not send, once per picked
	 * time. FluentCRM has already told the person it was scheduled, so without this the only trace
	 * is the campaign's status.
	 *
	 * @param Campaign $campaign  The campaign.
	 * @param string   $picked_at Site time it was scheduled for, Y-m-d H:i:s.
	 * @param string   $reason    Why, in plain words.
	 */
	private function alertReverted( Campaign $campaign, string $picked_at, string $reason ): void {
		$campaign_id = (int) $campaign->id;
		$token       = 'draft:' . $picked_at;

		if ( fluentcrm_get_campaign_meta( $campaign_id, self::ALERT_META, true ) === $token ) {
			return;
		}

		fluentcrm_update_campaign_meta( $campaign_id, self::ALERT_META, $token );

		try {
			$title = (string) $campaign->title;

			$this->mailAdmin( $campaign_id, 'Campaign not scheduled, back to draft: ' . $title, [
				sprintf( 'The campaign "%s" was scheduled for %s with local-time delivery, but that date is too close to deliver in each person\'s local time. It is back to draft and will not send.', $title, $picked_at ),
				'Why: ' . $reason,
				'Pick a later date and schedule it again, or turn off local-time delivery.',
			] );
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM local time: the back-to-draft email for campaign ' . $campaign_id . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param int      $campaign_id FluentCRM campaign ID, for the link.
	 * @param string   $subject     Email subject.
	 * @param string[] $paragraphs  Body paragraphs; the campaign link is added.
	 */
	private function mailAdmin( int $campaign_id, string $subject, array $paragraphs ): void {
		$paragraphs[] = 'Campaign: ' . admin_url( 'admin.php?page=fluentcrm-admin#/email/campaigns/' . $campaign_id . '/view' );

		$sent = wp_mail( get_option( 'admin_email' ), $subject, implode( "\n\n", $paragraphs ) );

		if ( ! $sent ) {
			error_log( 'CustomCRM local time: the email about campaign ' . $campaign_id . ' was not sent' );
		}
	}

	/**
	 * Put a campaign that has not started back to draft, the way FluentCRM's
	 * CampaignController::unSchedule() does.
	 *
	 * @return bool Whether it was put back.
	 */
	private function revertToDraft( int $campaign_id ): bool {
		$reverted = Campaign::withoutGlobalScope( 'type' )
			->where( 'id', $campaign_id )
			->where( 'status', 'pending-scheduled' )
			->update( [ 'status' => 'draft' ] );

		if ( ! $reverted ) {
			return false;
		}

		CampaignEmail::where( 'campaign_id', $campaign_id )->delete();
		CampaignEmail::withoutGlobalScope( 'type' )
			->where( 'campaign_id', $campaign_id )
			->whereIn( 'status', [ 'scheduled', 'scheduling' ] )
			->delete();

		// Scheduling zeroed the count and FluentCRM refuses to schedule a campaign with none.
		try {
			$campaign = Campaign::withoutGlobalScope( 'type' )->find( $campaign_id );

			if ( $campaign ) {
				Campaign::withoutGlobalScope( 'type' )
					->where( 'id', $campaign_id )
					->where( 'status', 'draft' )
					->update( [ 'recipients_count' => (int) $campaign->getSubscribersModel()->count() ] );
			}
		} catch ( \Throwable $e ) {
			error_log( 'CustomCRM local time: recounting recipients of campaign ' . $campaign_id . ' failed: ' . $e->getMessage() );
		}

		return true;
	}

	/**
	 * A plan for a new scheduling attempt on $date.
	 */
	private function startGeneration( array $plan, string $date, string $picked_at ): array {
		$plan['date']        = $date;
		$plan['picked_at']   = $picked_at;
		$plan['anchor']      = SendTime::siteTimeNotBefore( SendTime::firstWindowOpens( $date, $plan['start'] ), wp_timezone() );
		$plan['generation']  = wp_generate_uuid4();
		$plan['fallback_at'] = '';

		return $plan;
	}

	/**
	 * The plan with no scheduling attempt, keeping the settings.
	 */
	private static function cleared( array $plan ): array {
		$plan['date']        = '';
		$plan['picked_at']   = '';
		$plan['anchor']      = '';
		$plan['generation']  = '';
		$plan['fallback_at'] = '';

		return $plan;
	}

	/**
	 * Whether $generation is still the campaign's scheduling attempt. Read fresh, since a cancel
	 * and reschedule can replace it while a worker is still running.
	 */
	private function isCurrent( int $campaign_id, string $generation ): bool {
		return '' !== $generation && self::getPlan( $campaign_id )['generation'] === $generation;
	}

	/**
	 * Mark the attempt handled and record what happened, unless a newer attempt replaced it.
	 */
	private function finish( int $campaign_id, string $generation, string $result ): void {
		if ( ! $this->isCurrent( $campaign_id, $generation ) ) {
			return;
		}

		fluentcrm_update_campaign_meta( $campaign_id, self::DONE_META, $generation );
		$this->record( $campaign_id, $result );
	}

	private function record( int $campaign_id, string $result ): void {
		fluentcrm_update_campaign_meta( $campaign_id, self::RESULT_META, current_time( 'mysql' ) . ' ' . $result );
	}

	/**
	 * FluentCRM's MCP `change-campaign-status` resume (CampaignTools::actionResume()) moves every
	 * paused email to now, which would send all of a local-time campaign's remaining emails at
	 * once. For a local-time campaign, resume it the way the campaign screen does
	 * (CampaignController::resumeCampaign()), keeping each email's time.
	 *
	 * @param array  $args Ability arguments.
	 * @param string $name Ability name.
	 */
	public static function guardMcpResume( $args, $name ) {
		if ( 'fluent-crm/change-campaign-status' !== $name || ! is_array( $args ) || empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$execute = $args['execute_callback'];

		$args['execute_callback'] = static function ( $params ) use ( $execute ) {
			$input       = (array) $params;
			$action      = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';
			$campaign_id = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;

			if ( 'resume' === $action && $campaign_id ) {
				$plan = self::getPlan( $campaign_id );

				if ( $plan['enabled'] && '' !== $plan['date'] ) {
					$resumed = self::resumeKeepingTimes( $campaign_id );

					// Not paused (or gone): FluentCRM's own handler returns the right error.
					if ( null !== $resumed ) {
						return $resumed;
					}
				}
			}

			return call_user_func( $execute, $params );
		};

		return $args;
	}

	/**
	 * Resume a paused campaign without touching its emails' send times.
	 *
	 * @return array|null The MCP resume response, or null when the campaign is not paused.
	 */
	private static function resumeKeepingTimes( int $campaign_id ) {
		$resumed = Campaign::withoutGlobalScope( 'type' )
			->where( 'id', $campaign_id )
			->where( 'status', 'paused' )
			->update( [ 'status' => 'working' ] );

		if ( ! $resumed ) {
			return null;
		}

		CampaignEmail::where( 'campaign_id', $campaign_id )
			->where( 'status', 'paused' )
			->whereNotNull( 'scheduled_at' )
			->update( [ 'status' => 'scheduled' ] );

		CampaignEmail::where( 'campaign_id', $campaign_id )
			->where( 'status', 'paused' )
			->whereNull( 'scheduled_at' )
			->update( [ 'status' => 'scheduled', 'scheduled_at' => current_time( 'mysql' ) ] );

		if ( method_exists( Scheduler::class, 'fireSendNowRequest' ) ) {
			Scheduler::fireSendNowRequest();
		}

		return [
			'ok'              => true,
			'action'          => 'resume',
			'campaign'        => CampaignTools::getCampaign( [ 'campaign_id' => $campaign_id ] ),
			'previous_status' => 'paused',
			'current_status'  => 'working',
		];
	}
}
