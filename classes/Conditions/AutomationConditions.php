<?php

namespace CustomCRM\Conditions;

use FluentCrm\App\Models\FunnelSubscriber;
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
		// No Event Tracking group here on purpose. This class used to add one, registered after
		// core's EventTrackingHandler at the same priority, so it replaced core's. Its children
		// used the raw event key as the condition property, which no assessor understands:
		// FunnelConditionHelper::assessEventTrackingConditions() runs the
		// fluentcrm_contacts_filter_event_tracking filter, whose handlers recognise only
		// event_tracking_key / _title / _value / _key_count / _json_prop. An unrecognised property
		// left the query unfiltered, so the contact always came back and the condition evaluated
		// TRUE — for "has performed" and "has not performed" alike. Core's group is evaluated and
		// richer, so it is left to win.

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
					'valid'    => __( 'Valid license (active or inactive)', 'fluent-crm-custom-features' ),
					'active'   => __( 'Active (activated on a site)', 'fluent-crm-custom-features' ),
					'inactive' => __( 'Inactive (not activated)', 'fluent-crm-custom-features' ),
					'expired'  => __( 'Expired', 'fluent-crm-custom-features' ),
					'disabled' => __( 'Disabled', 'fluent-crm-custom-features' ),
				],
				'is_multiple'       => true,
				'is_singular_value' => false,
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
			// No EDD customer — no license can match.
			return false;
		}

		foreach ( $conditions as $condition ) {
			$prop     = $condition['data_key'];
			$operator = $condition['operator'] ?? '=';
			$value    = $condition['data_value'] ?? [];

			if ( strpos( $prop, 'edd_license_' ) !== 0 ) {
				continue;
			}

			$download_id = (int) str_replace( 'edd_license_', '', $prop );
			if ( ! $download_id ) {
				continue;
			}

			// Backward compatibility: convert old yes/no values.
			if ( $value === 'yes' ) {
				$value = [ 'valid' ];
			} elseif ( $value === 'no' ) {
				$value    = [ 'valid' ];
				$operator = ( $operator === '=' || $operator === 'in' ) ? '!=' : '=';
			}

			// Normalize value to an array of selected options.
			if ( ! is_array( $value ) ) {
				$value = [ $value ];
			}

			// Map selected options to EDD license statuses.
			$statuses = $this->mapLicenseOptionToStatuses( $value );

			if ( empty( $statuses ) ) {
				return false;
			}

			$has_matching_license = fluentCrmDb()->table( 'edd_licenses' )
				->where( 'customer_id', $customer->id )
				->where( 'download_id', $download_id )
				->whereIn( 'status', $statuses )
				->exists();

			if ( $operator === '=' || $operator === 'in' ) {
				if ( ! $has_matching_license ) {
					return false;
				}
			} elseif ( $operator === '!=' || $operator === 'not_in' ) {
				if ( $has_matching_license ) {
					return false;
				}
			} else {
				return false;
			}
		}

		return $result;
	}

	/**
	 * Map UI option values to EDD license status strings.
	 *
	 * @param array<string> $options Selected option values (e.g. ['valid', 'expired']).
	 *
	 * @return array<string> EDD license statuses to query.
	 */
	private function mapLicenseOptionToStatuses( array $options ): array {
		$status_map = [
			'valid'    => [ 'active', 'inactive' ],
			'active'   => [ 'active' ],
			'inactive' => [ 'inactive' ],
			'expired'  => [ 'expired' ],
			'disabled' => [ 'disabled' ],
		];

		$statuses = [];
		foreach ( $options as $option ) {
			if ( isset( $status_map[ $option ] ) ) {
				$statuses = array_merge( $statuses, $status_map[ $option ] );
			}
		}

		return array_unique( $statuses );
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
