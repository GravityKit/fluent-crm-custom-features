<?php
/**
 * AgencyRewardCodeAction
 *
 * @package CustomCRM
 */

namespace CustomCRM\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

/**
 * Automation step "Create agency reward code": a single-use EDD discount for one agency, saved to
 * the contact's `gk_agency_reward_code` and `gk_agency_reward_expires` fields for the next email.
 *
 * One code per contact, ever: a contact who already has one is skipped, so re-running the
 * automation never mints a second.
 */
class AgencyRewardCodeAction extends BaseAction {

	public const CODE_FIELD    = 'gk_agency_reward_code';
	public const EXPIRES_FIELD = 'gk_agency_reward_expires';

	/**
	 * Registers the step with FluentCRM (BaseAction's constructor calls register()).
	 */
	public function __construct() {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->actionName = 'gk_agency_reward_code';
		$this->priority   = 100;
		parent::__construct();
	}

	/**
	 * The step as it appears in the automation editor.
	 *
	 * @return array<string,mixed>
	 */
	public function getBlock() {
		return [
			'category'    => __( 'EDD', 'fluent-crm-custom-features' ),
			'title'       => __( 'Create agency reward code', 'fluent-crm-custom-features' ),
			'description' => __( 'Make a single-use discount code for this contact and save it to their agency reward fields', 'fluent-crm-custom-features' ),
			'icon'        => 'fc-icon-edd',
			'settings'    => [
				'amount'      => 100,
				'expiry_days' => 60,
				'min_amount'  => 0,
				'prefix'      => 'AGENCY',
			],
		];
	}

	/**
	 * The step's settings form.
	 *
	 * @return array<string,mixed>
	 */
	public function getBlockFields() {
		return [
			'title'     => __( 'Create agency reward code', 'fluent-crm-custom-features' ),
			'sub_title' => __( 'One code per contact. Use {{contact.custom.gk_agency_reward_code}} and {{contact.custom.gk_agency_reward_expires}} in the next email.', 'fluent-crm-custom-features' ),
			'fields'    => [
				'amount'      => [
					'type'  => 'input-number',
					'label' => __( 'Amount off, in dollars', 'fluent-crm-custom-features' ),
				],
				'expiry_days' => [
					'type'  => 'input-number',
					'label' => __( 'Days until the code expires', 'fluent-crm-custom-features' ),
				],
				'min_amount'  => [
					'type'  => 'input-number',
					'label' => __( 'Minimum order, in dollars (0 for none)', 'fluent-crm-custom-features' ),
				],
				'prefix'      => [
					'type'  => 'input-text',
					'label' => __( 'Code prefix', 'fluent-crm-custom-features' ),
				],
			],
		];
	}

	/**
	 * Makes the code, unless this contact already has one.
	 *
	 * @param \FluentCrm\App\Models\Subscriber     $subscriber           Contact.
	 * @param \FluentCrm\App\Models\FunnelSequence $sequence             This step.
	 * @param int                                  $funnel_subscriber_id Run id.
	 * @param mixed                                $funnel_metric        Metric row.
	 *
	 * @return bool|void
	 */
	public function handle( $subscriber, $sequence, $funnel_subscriber_id, $funnel_metric ) {
		$existing = (string) $subscriber->getMeta( self::CODE_FIELD, 'custom_field' );

		if ( '' !== $existing ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
			return false;
		}

		$created = self::createCode( $subscriber->email, (array) $sequence->settings );

		if ( ! $created ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
			return false;
		}

		$subscriber->syncCustomFieldValues(
			[
				self::CODE_FIELD    => $created['code'],
				self::EXPIRES_FIELD => $created['expires_label'],
			],
			false
		);
	}

	/**
	 * Create the EDD discount.
	 *
	 * @param string              $email    Contact email, for the discount's admin name.
	 * @param array<string,mixed> $settings Step settings.
	 *
	 * @return array{code:string,discount_id:int,expires_label:string}|null
	 */
	public static function createCode( string $email, array $settings ): ?array {
		if ( ! function_exists( 'edd_add_discount' ) ) {
			return null;
		}

		$amount = (float) ( $settings['amount'] ?? 100 );
		$days   = max( 1, (int) ( $settings['expiry_days'] ?? 60 ) );
		$min    = max( 0, (float) ( $settings['min_amount'] ?? 0 ) );
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $settings['prefix'] ?? 'AGENCY' ) ) ) ?: 'AGENCY';

		if ( $amount <= 0 ) {
			return null;
		}

		$code    = self::uniqueCode( $prefix );
		$expires = time() + $days * DAY_IN_SECONDS;
		$args    = [
			'name'              => sprintf( 'Agency reward: %s', $email ),
			'code'              => $code,
			'status'            => 'active',
			'type'              => 'discount',
			'scope'             => 'global',
			'amount_type'       => 'flat',
			'amount'            => $amount,
			'max_uses'          => 1,
			'once_per_customer' => 1,
			'start_date'        => gmdate( 'Y-m-d H:i:s' ),
			'expiration'        => gmdate( 'Y-m-d H:i:s', $expires ),
		];

		if ( $min > 0 ) {
			$args['min_charge_amount'] = $min;
		}

		$discount_id = edd_add_discount( $args );

		if ( ! $discount_id ) {
			return null;
		}

		return [
			'code'          => $code,
			'discount_id'   => (int) $discount_id,
			'expires_label' => wp_date( 'F j, Y', $expires ),
		];
	}

	/**
	 * A code no existing discount uses, without look-alike characters.
	 *
	 * @param string $prefix Code prefix.
	 */
	private static function uniqueCode( string $prefix ): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

		do {
			$suffix = '';

			for ( $i = 0; $i < 6; $i++ ) {
				$suffix .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}

			$code = $prefix . '-' . $suffix;
		} while ( function_exists( 'edd_get_discount_by_code' ) && edd_get_discount_by_code( $code ) );

		return $code;
	}
}
