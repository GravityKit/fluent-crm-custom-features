<?php

namespace CustomCRM\Email;

/**
 * Keeps FluentCRM emails from piling up on one contact.
 *
 * - Campaigns (newsletters, launch and sale sends) are cancelled for a contact while an
 *   abandoned-cart automation is running for them.
 * - Onboarding and activation-reminder automation emails wait until 24 hours after the
 *   contact's last cart or pre-renewal email.
 *
 * Cart, renewal and all other emails are never held.
 */
class CollisionGate {

	private const CART_TRIGGERS = [ 'fc_ab_cart_simulation_edd', 'fc_ab_cart_simulation_edd_renewal' ];

	private const HOLD_HOURS = 24;

	/**
	 * Hooks the gate ahead of FluentCRM's senders.
	 *
	 * `fluent_crm/disable_email_processing` is read at the start of every batch run (cron, send-now,
	 * multi-thread and CLI); `fluentcrm_process_contact_jobs` sends one contact's due emails at
	 * priority 999, so priority 10 runs first.
	 */
	public static function register(): void {
		add_filter( 'fluent_crm/disable_email_processing', [ self::class, 'beforeBatchSend' ], 1 );
		add_action( 'fluentcrm_process_contact_jobs', [ self::class, 'beforeContactSend' ], 10, 1 );
	}

	/**
	 * Cancels due campaign emails for every contact in a cart automation. Returns the filter value unchanged.
	 *
	 * @param bool $disabled
	 * @return bool
	 */
	public static function beforeBatchSend( $disabled ) {
		static $done = false;

		if ( $disabled || $done ) {
			return $disabled;
		}

		$done = true;

		self::cancelCampaignEmails( self::contactsInCartAutomation() );

		return $disabled;
	}

	/**
	 * Applies both rules to one contact before FluentCRM sends their due emails.
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber
	 */
	public static function beforeContactSend( $subscriber ): void {
		$contact_id = is_object( $subscriber ) ? (int) $subscriber->id : 0;

		if ( ! $contact_id ) {
			return;
		}

		self::cancelCampaignEmails( self::contactsInCartAutomation( $contact_id ) );
		self::holdLowPriorityEmails( $contact_id );
	}

	/**
	 * Contacts with a running abandoned-cart automation.
	 *
	 * @param int $contact_id Limit to one contact; 0 for all.
	 * @return int[]
	 */
	private static function contactsInCartAutomation( int $contact_id = 0 ): array {
		global $wpdb;

		$triggers = implode( ',', array_fill( 0, count( self::CART_TRIGGERS ), '%s' ) );
		$sql      = "SELECT DISTINCT fs.subscriber_id FROM {$wpdb->prefix}fc_funnel_subscribers fs
			INNER JOIN {$wpdb->prefix}fc_funnels f ON f.id = fs.funnel_id
			WHERE fs.status = 'active' AND f.trigger_name IN ($triggers)";
		$args     = self::CART_TRIGGERS;

		if ( $contact_id ) {
			$sql   .= ' AND fs.subscriber_id = %d';
			$args[] = $contact_id;
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cancels these contacts' campaign emails that are due now. Automation emails are untouched.
	 *
	 * @param int[] $contact_ids
	 */
	private static function cancelCampaignEmails( array $contact_ids ): void {
		if ( ! $contact_ids ) {
			return;
		}

		global $wpdb;

		$ids  = implode( ',', array_map( 'intval', $contact_ids ) );
		$note = __( 'Skipped: the contact was receiving abandoned-cart emails', 'fluent-crm-custom-features' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids is a list of integers.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}fc_campaign_emails ce
			INNER JOIN {$wpdb->prefix}fc_campaigns c ON c.id = ce.campaign_id
			SET ce.status = 'cancelled', ce.note = %s
			WHERE ce.subscriber_id IN ($ids) AND ce.status IN ('pending', 'scheduled')
				AND ce.scheduled_at <= %s AND c.type = 'campaign'", $note, current_time( 'mysql' ) ) );
	}

	/**
	 * Moves this contact's due onboarding emails to 24 hours after their last cart or pre-renewal email.
	 */
	private static function holdLowPriorityEmails( int $contact_id ): void {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$since = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - self::HOLD_HOURS * HOUR_IN_SECONDS );

		// Automation emails for this contact sent or due in the last 24 hours, with the automation they came from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ce.id, ce.status, ce.scheduled_at, f.id AS funnel_id, f.title, f.trigger_name
				FROM {$wpdb->prefix}fc_campaign_emails ce
				INNER JOIN {$wpdb->prefix}fc_campaigns c ON c.id = ce.campaign_id AND c.type = 'funnel_email_campaign'
				INNER JOIN {$wpdb->prefix}fc_funnels f ON f.id = c.parent_id
				WHERE ce.subscriber_id = %d AND ce.scheduled_at >= %s AND ce.scheduled_at <= %s
					AND ce.status IN ('sent', 'processing', 'pending', 'scheduled')",
				$contact_id,
				$since,
				$now
			)
		);

		$last_priority = '';
		$held          = [];

		foreach ( (array) $rows as $row ) {
			if ( self::isPriorityAutomation( $row ) ) {
				$last_priority = max( $last_priority, (string) $row->scheduled_at );
			} elseif ( in_array( $row->status, [ 'pending', 'scheduled' ], true ) && self::isHoldableAutomation( $row ) ) {
				$held[] = (int) $row->id;
			}
		}

		if ( ! $last_priority || ! $held ) {
			return;
		}

		$until = gmdate( 'Y-m-d H:i:s', strtotime( $last_priority ) + self::HOLD_HOURS * HOUR_IN_SECONDS );
		$ids   = implode( ',', $held );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids is a list of integers.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}fc_campaign_emails SET scheduled_at = %s WHERE id IN ($ids) AND status IN ('pending', 'scheduled')", $until ) );
	}

	/**
	 * Cart, renewal-cart and pre-renewal automations: never held, and they hold the others.
	 *
	 * @param object $automation Row with `funnel_id`, `title` and `trigger_name`.
	 */
	private static function isPriorityAutomation( $automation ): bool {
		$is_priority = in_array( $automation->trigger_name, self::CART_TRIGGERS, true )
			|| 1 === preg_match( '/^Pre-renewal/i', (string) $automation->title );

		return (bool) apply_filters( 'customcrm/email_gate/is_priority_automation', $is_priority, $automation );
	}

	/**
	 * Onboarding and activation reminders: the automations whose emails can wait a day.
	 *
	 * @param object $automation Row with `funnel_id`, `title` and `trigger_name`.
	 */
	private static function isHoldableAutomation( $automation ): bool {
		$is_holdable = 1 === preg_match( '/^(Onboarding|Activation reminder)/i', (string) $automation->title );

		return (bool) apply_filters( 'customcrm/email_gate/is_holdable_automation', $is_holdable, $automation );
	}
}
