<?php
/**
 * RandomWaitTimeAction
 *
 * @package CustomCRM\Actions
 */

namespace CustomCRM\Actions;

use FluentCrm\Framework\Support\Arr;

/**
 * Class RandomWaitTimeAction
 *
 * @package CustomCRM\Actions
 */
class RandomWaitTimeAction extends \FluentCrm\App\Services\Funnel\Actions\WaitTimeAction {

	/**
	 * RandomWaitTimeAction constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
		$this->priority = 11;

		add_filter( 'fluent_crm/funnel_seq_delay_in_seconds', [ $this, 'setDelayInSeconds' ], 10, 4 );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		add_filter( 'fluentcrm_funnel_sequence_saving_' . $this->actionName, [ $this, 'savingAction' ], 10, 2 );
	}

	/**
	 * Get the block for the action.
	 *
	 * @return array<string,string|array>
	 */
	public function getBlock() {
		$block = parent::getBlock();

		$customize_block = [
			'settings' => [
				'wait_time_amount'     => 1,
				'wait_time_amount_min' => '',
				'wait_time_amount_max' => '',
			],
		];

		return array_replace_recursive( $block, $customize_block );
	}

	/**
	 * Save the sequence settings.
	 *
	 * @param array<string,string|array> $sequence The sequence settings.
	 * @param array<string,string|array> $funnel The funnel settings.
	 *
	 * @return array<string,string|array>
	 */
	public function savingAction( $sequence, $funnel ) {
		$min  = Arr::get( $sequence, 'settings.wait_time_amount_min' );
		$max  = Arr::get( $sequence, 'settings.wait_time_amount_max' );
		$unit = Arr::get( $sequence, 'settings.wait_time_unit' );

		// Ensure numeric types before arithmetic.
		$min = is_numeric( $min ) ? (float) $min : 0;
		$max = is_numeric( $max ) ? (float) $max : 0;

		if ( $min >= 0 && $max > 0 ) {
			$sequence['settings']['wait_time_amount'] = $max;

			$max_delay = 0;

			if ( 'hours' === $unit ) {
				$max_delay = $max * 60 * 60;
			} elseif ( 'minutes' === $unit ) {
				$max_delay = $max * 60;
			} elseif ( 'days' === $unit ) {
				$max_delay = $max * 60 * 60 * 24;
			}

			$sequence['delay'] = $max_delay;
		}

		return $sequence;
	}

	/**
	 * Get the block fields for the action.
	 *
	 * @return array<string,string|array>
	 */
	public function getBlockFields() {
		$block_fields = parent::getBlockFields();

		$block_fields['fields']['wait_type']['options'][0]['title'] = __( 'Wait for fixed or random period', 'fluent-crm-custom-features' );

		$block_fields['fields']['wait_time_unit']['options'] = array_merge(
			[
				[
					'id'    => 'months',
					'title' => __( 'Months', 'fluent-crm-custom-features' ),
				],
				[
					'id'    => 'weeks',
					'title' => __( 'Weeks', 'fluent-crm-custom-features' ),
				],
			],
			$block_fields['fields']['wait_time_unit']['options'],
			[
				[
					'id'    => 'seconds',
					'title' => __( 'Seconds', 'fluent-crm-custom-features' ),
				],
			]
		);

		// Insert our min/max fields after the first field using array_splice
		$block_fields['fields'] = array_merge(
			array_slice( $block_fields['fields'], 0, 2 ), // First part, up to the first item inclusive
			[
				'wait_time_amount_min' => [
					'label'         => __( 'Random Delay - Min', 'fluent-crm-custom-features' ),
					'type'          => 'input-number',
					'wrapper_class' => 'fc_2col_inline pad-r-20',
					'inline_help'   => __( 'Set min for random delay.', 'fluent-crm-custom-features' ),
					'dependency'    => [
						'depends_on' => 'wait_type',
						'value'      => 'unit_wait',
						'operator'   => '=',
					],
				],
				'wait_time_amount_max' => [
					'label'         => __( 'Random Delay - Max', 'fluent-crm-custom-features' ),
					'type'          => 'input-number',
					'wrapper_class' => 'fc_2col_inline pad-r-20',
					'inline_help'   => __( 'Max required for random delay.', 'fluent-crm-custom-features' ),
					'dependency'    => [
						'depends_on' => 'wait_type',
						'value'      => 'unit_wait',
						'operator'   => '=',
					],
				],
			],
			array_slice( $block_fields['fields'], 2 ) // Remaining part, from the second item to the end
		);

		return $block_fields;
	}

	/**
	 * Set the delay in seconds for the sequence.
	 *
	 * @param int                                  $delay_in_seconds The delay in seconds.
	 * @param array<string,string|int>             $settings The settings array.
	 * @param \FluentCrm\App\Models\FunnelSequence $sequence The funnel sequence.
	 * @param int                                  $funnel_subscriber_id The funnel subscriber ID.
	 *
	 * @return int
	 */
	public function setDelayInSeconds( $delay_in_seconds, $settings, $sequence, $funnel_subscriber_id ) {
		// Only process sequences that have random wait settings.
		if ( ! Arr::get( $settings, 'wait_time_amount_min' ) && ! Arr::get( $settings, 'wait_time_amount_max' ) ) {
			return $delay_in_seconds;
		}

		$delay = Arr::get( $settings, 'wait_time_amount', null );
		$min   = Arr::get( $settings, 'wait_time_amount_min', null );
		$max   = Arr::get( $settings, 'wait_time_amount_max', 0 );
		$unit  = Arr::get( $settings, 'wait_time_unit' );

		// Ensure numeric types — array_merge_recursive can turn scalars into arrays.
		$delay = is_numeric( $delay ) ? (float) $delay : 0;
		$min   = is_numeric( $min ) ? (float) $min : null;
		$max   = is_numeric( $max ) ? (float) $max : 0;

		$wait_times = $delay;

		if ( $min >= 0 && $max > 0 ) {
			if ( 'minutes' === $unit || 'seconds' === $unit ) {
				// Crons run at minute intervals, so we need to stick with whole minutes.
				$wait_times = wp_rand( $min, $max );
			} else {
				// Everything else can be more granular.
				$wait_times = wp_rand( $min * 100, $max * 100 ) / 100;
			}
		}

		if ( 'hours' === $unit ) {
			$wait_times = $wait_times * 60 * 60;
		} elseif ( 'minutes' === $unit ) {
			$wait_times = $wait_times * 60;
		} elseif ( 'days' === $unit ) {
			$wait_times = $wait_times * 60 * 60 * 24;
		} elseif ( 'weeks' === $unit ) {
			$wait_times = $wait_times * 60 * 60 * 24 * 7;
		} elseif ( 'months' === $unit ) {
			$wait_times = $wait_times * 60 * 60 * 24 * ( 365 / 12 );
		}

		return $wait_times;
	}
}
