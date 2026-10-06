<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelSequence;

/**
 * Send-time backstop for internal-only mode: cancels any cart-automation email addressed outside
 * the allowed domains, however the contact reached the automation (the drivers hold such carts back
 * before an automation starts; this covers a contact added some other way).
 */
class CartEmailGuard {

	private const CART_TRIGGERS = [
		'fc_ab_cart_simulation_edd',
		'fc_ab_cart_simulation_edd_renewal',
		'fc_ab_cart_simulation_edd_upgrade',
	];

	/**
	 * Hooks ahead of both of FluentCRM's senders, like the collision rules.
	 */
	public static function register(): void {
		add_filter( 'fluent_crm/disable_email_processing', [ self::class, 'beforeBatchSend' ], 1 );
		add_action( 'fluentcrm_process_contact_jobs', [ self::class, 'beforeContactSend' ], 9, 1 );
	}

	/**
	 * Runs before a batch send. Returns the filter value unchanged.
	 *
	 * @param bool $disabled Whether FluentCRM email processing is switched off.
	 * @return bool
	 */
	public static function beforeBatchSend( $disabled ) {
		static $done = false;

		if ( ! $disabled && ! $done ) {
			$done = true;
			self::cancelOutsideEmails();
		}

		return $disabled;
	}

	/**
	 * Runs before FluentCRM sends one contact's due emails.
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Contact whose emails are about to send.
	 */
	public static function beforeContactSend( $subscriber ): void {
		$contact_id = is_object( $subscriber ) ? (int) $subscriber->id : 0;

		if ( $contact_id ) {
			self::cancelOutsideEmails( $contact_id );
		}
	}

	/**
	 * The email campaigns the cart automations' send-email steps use.
	 *
	 * Read from the steps, not from `parent_id`: FluentCRM reuses automation IDs, so on live a cart
	 * automation shares its `parent_id` with emails from deleted automations such as old onboarding.
	 *
	 * @return int[]
	 */
	public static function cartCampaignIds(): array {
		$funnel_ids = Funnel::whereIn( 'trigger_name', self::CART_TRIGGERS )->pluck( 'id' )->toArray();

		return self::campaignIdsFor( array_map( 'intval', $funnel_ids ) );
	}

	/**
	 * The email campaigns these automations' email steps send, by each step's `reference_campaign`.
	 *
	 * Never by `parent_id`: stores keep leftover campaigns whose parent ID matches a reused automation ID.
	 *
	 * @param int[] $funnel_ids Automation IDs.
	 * @return int[]
	 */
	public static function campaignIdsFor( array $funnel_ids ): array {
		if ( ! $funnel_ids ) {
			return [];
		}

		$ids = [];

		foreach ( FunnelSequence::whereIn( 'funnel_id', $funnel_ids )->where( 'action_name', 'send_custom_email' )->get() as $step ) {
			$settings    = is_array( $step->settings ) ? $step->settings : [];
			$campaign_id = (int) ( $settings['reference_campaign'] ?? 0 );

			if ( $campaign_id ) {
				$ids[] = $campaign_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Cancels unsent cart-automation emails to addresses outside the allowed domains.
	 *
	 * @param int $contact_id Limit to one contact; 0 for all.
	 * @return int Emails cancelled.
	 */
	public static function cancelOutsideEmails( int $contact_id = 0 ): int {
		try {
			if ( ! AllowedDomains::get() ) {
				return 0;
			}

			$campaign_ids = self::cartCampaignIds();

			if ( ! $campaign_ids ) {
				return 0;
			}

			$query = CampaignEmail::whereIn( 'campaign_id', $campaign_ids )->whereIn( 'status', [ 'pending', 'scheduled' ] );

			if ( $contact_id ) {
				$query->where( 'subscriber_id', $contact_id );
			}

			$outside = [];

			foreach ( $query->get() as $email ) {
				if ( ! AllowedDomains::allows( (string) $email->email_address ) ) {
					$outside[] = (int) $email->id;
				}
			}

			if ( ! $outside ) {
				return 0;
			}

			CampaignEmail::whereIn( 'id', $outside )
				->whereIn( 'status', [ 'pending', 'scheduled' ] )
				->update(
					[
						'status' => 'cancelled',
						'note'   => __( 'Cancelled: cart emails are limited to the Cart Recovery domains', 'fluent-crm-custom-features' ),
					]
				);

			return count( $outside );
		} catch ( \Throwable $e ) {
			// Runs inside FluentCRM's sender: an error must never stop email sending.
			EddCartTracking::logError( 'CartEmailGuard', $e );
			return 0;
		}
	}
}
