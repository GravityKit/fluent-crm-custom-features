<?php

namespace CustomCRM\Conditions;

use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

/**
 * Registers custom automation condition groups for the funnel condition block.
 *
 * Adds:
 * - Event Tracking conditions (check if contact has performed a specific event)
 * - Automation Completion conditions (check if contact has completed a specific funnel)
 * - EDD License conditions (check if contact has an active license for a product)
 */
class AutomationConditions {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Add condition groups to the funnel condition block UI.
		add_filter( 'fluentcrm_automation_condition_groups', [ $this, 'addConditionGroups' ], 10, 2 );

		// Handle evaluation of our custom "automation completed" condition.
		add_filter( 'fluentcrm_automation_conditions_assess_automations', [ $this, 'assessAutomationConditions' ], 10, 5 );

		// Handle evaluation of our custom "EDD license" condition.
		add_filter( 'fluentcrm_automation_conditions_assess_edd_licenses', [ $this, 'assessEddLicenseConditions' ], 10, 5 );
	}

	/**
	 * Add Event Tracking and Automation Completion condition groups to the funnel condition block.
	 *
	 * @param array<string,mixed> $groups Existing condition groups.
	 * @param mixed               $funnel The current funnel.
	 *
	 * @return array<string,mixed>
	 */
	public function addConditionGroups( array $groups, $funnel ): array {
		// Add Event Tracking group (evaluation handled by FunnelConditionHelper::assessEventTrackingConditions).
		if ( Helper::isExperimentalEnabled( 'event_tracking' ) ) {
			$groups['event_tracking'] = [
				'label'    => __( 'Event Tracking', 'fluent-crm-custom-features' ),
				'value'    => 'event_tracking',
				'children' => $this->getEventTrackingChildren(),
			];
		}

		// Add Automation Completion group.
		$groups['automations'] = [
			'label'    => __( 'Automations', 'fluent-crm-custom-features' ),
			'value'    => 'automations',
			'children' => $this->getAutomationChildren(),
		];

		// Add EDD License group.
		if ( function_exists( 'edd_software_licensing' ) ) {
			$groups['edd_licenses'] = [
				'label'    => __( 'EDD Licenses', 'fluent-crm-custom-features' ),
				'value'    => 'edd_licenses',
				'children' => $this->getEddLicenseChildren(),
			];
		}

		return $groups;
	}

	/**
	 * Get event tracking condition options.
	 *
	 * Provides a list of tracked event keys that can be used as conditions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getEventTrackingChildren(): array {
		$events = fluentCrmDb()->table( 'fc_event_tracking' )
			->select( 'event_key', 'title' )
			->groupBy( 'event_key' )
			->get();

		$children = [];
		foreach ( $events as $event ) {
			$children[] = [
				'label'             => $event->title ?: $event->event_key,
				'value'             => $event->event_key,
				'type'              => 'selections',
				'options'           => [
					'yes' => __( 'Yes - Has performed', 'fluent-crm-custom-features' ),
					'no'  => __( 'No - Has not performed', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => false,
				'is_singular_value' => true,
			];
		}

		return $children;
	}

	/**
	 * Get automation completion condition options.
	 *
	 * Provides a list of funnels that can be checked for completion.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getAutomationChildren(): array {
		$funnels = fluentCrmDb()->table( 'fc_funnels' )
			->select( 'id', 'title' )
			->orderBy( 'title', 'ASC' )
			->get();

		$children = [];
		foreach ( $funnels as $funnel_item ) {
			$children[] = [
				'label'             => $funnel_item->title,
				'value'             => 'funnel_completed_' . $funnel_item->id,
				'type'              => 'selections',
				'options'           => [
					'yes' => __( 'Yes - Has completed', 'fluent-crm-custom-features' ),
					'no'  => __( 'No - Has not completed', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => false,
				'is_singular_value' => true,
			];
		}

		return $children;
	}

	/**
	 * Get EDD license condition options.
	 *
	 * Lists all EDD downloads that have at least one license, so the condition
	 * UI shows "Has active license for [Product Name]" yes/no.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getEddLicenseChildren(): array {
		$products = fluentCrmDb()->table( 'edd_licenses' )
			->select( 'download_id' )
			->groupBy( 'download_id' )
			->get();

		$children = [];
		foreach ( $products as $product ) {
			$download = get_post( $product->download_id );
			if ( ! $download ) {
				continue;
			}

			$children[] = [
				'label'             => $download->post_title,
				'value'             => 'edd_license_' . $product->download_id,
				'type'              => 'selections',
				'options'           => [
					'yes' => __( 'Yes - Has active license', 'fluent-crm-custom-features' ),
					'no'  => __( 'No - Does not have active license', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => false,
				'is_singular_value' => true,
			];
		}

		return $children;
	}

	/**
	 * Assess EDD license conditions.
	 *
	 * Checks if the subscriber has an active (or inactive) license for a specific
	 * EDD product by querying the edd_licenses table via customer_id.
	 *
	 * @param bool   $result              Current result.
	 * @param array  $conditions           Condition rules to evaluate.
	 * @param object $subscriber           The subscriber being evaluated.
	 * @param object $sequence             The current funnel sequence.
	 * @param int    $funnelSubscriberId   The funnel subscriber ID.
	 *
	 * @return bool
	 */
	public function assessEddLicenseConditions( $result, $conditions, $subscriber, $sequence, $funnelSubscriberId ): bool {
		if ( ! function_exists( 'edd_software_licensing' ) ) {
			return false;
		}

		// Resolve the EDD customer from the subscriber's email or user_id.
		$customer = null;
		if ( $subscriber->user_id ) {
			$customer = new \EDD_Customer( $subscriber->user_id, true );
		}
		if ( ( ! $customer || ! $customer->id ) && $subscriber->email ) {
			$customer = new \EDD_Customer( $subscriber->email );
		}
		if ( ! $customer || ! $customer->id ) {
			// No EDD customer — all "has active license" conditions fail.
			foreach ( $conditions as $condition ) {
				$value = $condition['data_value'] ?? 'yes';
				if ( $value === 'yes' ) {
					return false;
				}
			}
			return $result;
		}

		foreach ( $conditions as $condition ) {
			$prop     = $condition['data_key'];
			$operator = $condition['operator'] ?? '=';
			$value    = $condition['data_value'] ?? 'yes';

			if ( strpos( $prop, 'edd_license_' ) !== 0 ) {
				continue;
			}

			$download_id = (int) str_replace( 'edd_license_', '', $prop );
			if ( ! $download_id ) {
				continue;
			}

			$has_active_license = fluentCrmDb()->table( 'edd_licenses' )
				->where( 'customer_id', $customer->id )
				->where( 'download_id', $download_id )
				->whereIn( 'status', [ 'active', 'inactive' ] )
				->exists();

			$expects_active = ( $value === 'yes' );

			if ( $operator === '=' || $operator === 'in' ) {
				if ( $has_active_license !== $expects_active ) {
					return false;
				}
			} elseif ( $operator === '!=' || $operator === 'not_in' ) {
				if ( $has_active_license === $expects_active ) {
					return false;
				}
			} else {
				return false;
			}
		}

		return $result;
	}

	/**
	 * Assess automation completion conditions.
	 *
	 * @param bool   $result              Current result.
	 * @param array  $conditions           Condition rules to evaluate.
	 * @param object $subscriber           The subscriber being evaluated.
	 * @param object $sequence             The current funnel sequence.
	 * @param int    $funnelSubscriberId   The funnel subscriber ID.
	 *
	 * @return bool
	 */
	public function assessAutomationConditions( $result, $conditions, $subscriber, $sequence, $funnelSubscriberId ): bool {
		foreach ( $conditions as $condition ) {
			$prop     = $condition['data_key'];
			$operator = $condition['operator'] ?? '=';
			$value    = $condition['data_value'] ?? 'yes';

			// Extract funnel ID from the property name (funnel_completed_{id}).
			if ( strpos( $prop, 'funnel_completed_' ) !== 0 ) {
				continue;
			}

			$funnel_id = (int) str_replace( 'funnel_completed_', '', $prop );
			if ( ! $funnel_id ) {
				continue;
			}

			$has_completed = FunnelSubscriber::where( 'subscriber_id', $subscriber->id )
				->where( 'funnel_id', $funnel_id )
				->where( 'status', 'completed' )
				->exists();

			$expects_completed = ( $value === 'yes' );

			if ( $operator === '=' || $operator === 'in' ) {
				if ( $has_completed !== $expects_completed ) {
					return false;
				}
			} elseif ( $operator === '!=' || $operator === 'not_in' ) {
				if ( $has_completed === $expects_completed ) {
					return false;
				}
			} else {
				// Unknown operator — fail safe.
				return false;
			}
		}

		return $result;
	}
}
