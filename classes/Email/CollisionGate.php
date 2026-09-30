<?php

namespace CustomCRM\Email;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelCampaign;
use FluentCrm\App\Models\FunnelSubscriber;

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

	private const CART_TRIGGERS = [
		'fc_ab_cart_simulation_edd',
		'fc_ab_cart_simulation_edd_renewal',
		'fc_ab_cart_simulation_edd_upgrade',
	];

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
	 * @param bool $disabled Whether FluentCRM email processing is switched off.
	 * @return bool
	 */
	public static function beforeBatchSend( $disabled ) {
		static $done = false;

		if ( $disabled || $done ) {
			return $disabled;
		}

		$done = true;

		// Runs inside FluentCRM's sender: an error must never stop email sending.
		try {
			self::cancelCampaignEmails( self::contactsInCartAutomation() );
		} catch ( \Throwable $e ) {
			\CustomCRM\AbandonCart\Edd\EddCartTracking::logError( 'CollisionGate::beforeBatchSend', $e );
		}

		return $disabled;
	}

	/**
	 * Applies both rules to one contact before FluentCRM sends their due emails.
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Contact whose emails are about to send.
	 */
	public static function beforeContactSend( $subscriber ): void {
		$contact_id = is_object( $subscriber ) ? (int) $subscriber->id : 0;

		// FluentCRM sends nothing per contact during a bulk import, so there is nothing to hold.
		if ( ! $contact_id || defined( 'FLUENTCRM_DOING_BULK_IMPORT' ) ) {
			return;
		}

		try {
			self::cancelCampaignEmails( self::contactsInCartAutomation( $contact_id ) );
			self::holdLowPriorityEmails( $contact_id );
		} catch ( \Throwable $e ) {
			\CustomCRM\AbandonCart\Edd\EddCartTracking::logError( 'CollisionGate::beforeContactSend', $e );
		}
	}

	/**
	 * Contacts with a running abandoned-cart automation.
	 *
	 * @param int $contact_id Limit to one contact; 0 for all.
	 * @return int[]
	 */
	private static function contactsInCartAutomation( int $contact_id = 0 ): array {
		$runs = FunnelSubscriber::where( 'status', 'active' )
			->whereHas(
				'funnel',
				function ( $funnel ) {
					$funnel->whereIn( 'trigger_name', self::CART_TRIGGERS );
				}
			);

		if ( $contact_id ) {
			$runs->where( 'subscriber_id', $contact_id );
		}

		$contact_ids = array_map( 'intval', $runs->pluck( 'subscriber_id' )->toArray() );

		return array_values( array_unique( $contact_ids ) );
	}

	/**
	 * Cancels these contacts' campaign emails that are due now. Automation emails are untouched.
	 *
	 * @param int[] $contact_ids FluentCRM contact IDs.
	 */
	private static function cancelCampaignEmails( array $contact_ids ): void {
		if ( ! $contact_ids ) {
			return;
		}

		CampaignEmail::whereIn( 'subscriber_id', $contact_ids )
			->whereIn( 'status', [ 'pending', 'scheduled' ] )
			->where( 'scheduled_at', '<=', current_time( 'mysql' ) )
			->whereHas(
				'campaign',
				function ( $campaign ) {
					$campaign->where( 'type', 'campaign' );
				}
			)
			->update(
				[
					'status' => 'cancelled',
					'note'   => __( 'Skipped: the contact was receiving abandoned-cart emails', 'fluent-crm-custom-features' ),
				]
			);
	}

	/**
	 * Moves this contact's due onboarding emails to 24 hours after their last cart or pre-renewal email.
	 *
	 * @param int $contact_id FluentCRM contact ID.
	 */
	private static function holdLowPriorityEmails( int $contact_id ): void {
		$now   = current_time( 'mysql' );
		$since = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - self::HOLD_HOURS * HOUR_IN_SECONDS );

		// Automation emails for this contact sent or due in the last 24 hours.
		$emails = CampaignEmail::where( 'subscriber_id', $contact_id )
			->where( 'email_type', 'funnel_email_campaign' )
			->whereBetween( 'scheduled_at', [ $since, $now ] )
			->whereIn( 'status', [ 'sent', 'processing', 'pending', 'scheduled' ] )
			->get();

		if ( $emails->isEmpty() ) {
			return;
		}

		$automations   = self::automationsFor( $emails->pluck( 'campaign_id' )->toArray() );
		$last_priority = '';
		$held          = [];

		foreach ( $emails as $email ) {
			$automation = $automations[ (int) $email->campaign_id ] ?? null;

			if ( ! $automation ) {
				continue;
			}

			$scheduled_at = (string) $email->scheduled_at;
			$is_due       = in_array( $email->status, [ 'pending', 'scheduled' ], true );

			if ( self::isPriorityAutomation( $automation ) ) {
				$last_priority = max( $last_priority, $scheduled_at );
			} elseif ( $is_due && self::isHoldableAutomation( $automation ) ) {
				$held[] = (int) $email->id;
			}
		}

		if ( ! $last_priority || ! $held ) {
			return;
		}

		$until = gmdate( 'Y-m-d H:i:s', strtotime( $last_priority ) + self::HOLD_HOURS * HOUR_IN_SECONDS );

		CampaignEmail::whereIn( 'id', $held )
			->whereIn( 'status', [ 'pending', 'scheduled' ] )
			->update( [ 'scheduled_at' => $until ] );
	}

	/**
	 * The automation each automation email came from.
	 *
	 * @param int[] $campaign_ids Automation email campaign IDs.
	 * @return array<int,Funnel> Keyed by campaign ID.
	 */
	private static function automationsFor( array $campaign_ids ): array {
		$campaigns  = FunnelCampaign::whereIn( 'id', array_unique( array_map( 'intval', $campaign_ids ) ) )->get();
		$funnel_ids = array_unique( array_map( 'intval', $campaigns->pluck( 'parent_id' )->toArray() ) );
		$funnels    = [];

		foreach ( Funnel::whereIn( 'id', $funnel_ids )->get() as $funnel ) {
			$funnels[ (int) $funnel->id ] = $funnel;
		}

		$by_campaign = [];

		foreach ( $campaigns as $campaign ) {
			$funnel = $funnels[ (int) $campaign->parent_id ] ?? null;

			if ( $funnel ) {
				$by_campaign[ (int) $campaign->id ] = $funnel;
			}
		}

		return $by_campaign;
	}

	/**
	 * Cart, renewal-cart, upgrade-cart and pre-renewal automations: never held, and they hold the others.
	 *
	 * @param Funnel $automation The automation an email came from.
	 */
	private static function isPriorityAutomation( $automation ): bool {
		$is_priority = in_array( $automation->trigger_name, self::CART_TRIGGERS, true )
			|| 1 === preg_match( '/^Pre-renewal/i', (string) $automation->title );

		return (bool) apply_filters( 'customcrm/email_gate/is_priority_automation', $is_priority, $automation );
	}

	/**
	 * Onboarding and activation reminders: the automations whose emails can wait a day.
	 *
	 * @param Funnel $automation The automation an email came from.
	 */
	private static function isHoldableAutomation( $automation ): bool {
		$is_holdable = 1 === preg_match( '/^(Onboarding|Activation reminder)/i', (string) $automation->title );

		return (bool) apply_filters( 'customcrm/email_gate/is_holdable_automation', $is_holdable, $automation );
	}
}
