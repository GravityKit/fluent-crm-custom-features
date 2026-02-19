<?php
/**
 * UpdateContactPropertyAction
 *
 * @package CustomCRM
 */

namespace CustomCRM\Actions;

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

/**
 * Class UpdateContactPropertyAction
 *
 * @package CustomCRM\Actions
 */
class UpdateContactPropertyAction extends BaseAction {

	/**
	 * UpdateContactPropertyAction constructor.
	 *
	 * @return bool|void
	 */
	public function __construct() {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->actionName = 'update_contact_property';
		$this->priority   = 100;
		parent::__construct();
	}

	/**
	 * Get the block
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	public function getBlock() {
		return [
			'category'    => __( 'CRM', 'fluent-crm-custom-features' ),
			'title'       => __( 'Update Contact Property (Fixed)', 'fluent-crm-custom-features' ),
			'description' => __( 'Update custom fields or few main property of a contact', 'fluent-crm-custom-features' ),
			'icon'        => 'fc-icon-wp_user_meta',
			'settings'    => [
				'contact_properties' => [
					[
						'data_key'   => '',
						'data_value' => '',
					],
				],
			],
		];
	}

	/**
	 * Get the block fields
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	public function getBlockFields() {
		return [
			'title'     => __( 'Update Contact Property', 'fluent-crm-custom-features' ),
			'sub_title' => __( 'Update custom fields or few main property of a contact', 'fluent-crm-custom-features' ),
			'fields'    => [
				'contact_properties' => [
					'type'               => 'input_value_pair_properties',
					'support_operations' => 'yes',
					'label'              => __( 'Setup contact properties that you want to update', 'fluent-crm-custom-features' ),
					'data_key_label'     => __( 'Contact Property', 'fluent-crm-custom-features' ),
					'data_value_label'   => __( 'Property Value', 'fluent-crm-custom-features' ),
					'property_options'   => $this->getContactProperties(),
				],
			],
		];
	}

	/**
	 * Handle the action
	 *
	 * @param FluentCrm\App\Models\Subscriber $subscriber The subscriber object.
	 * @param FluentCrm\App\Models\Funnel     $sequence The funnel sequence.
	 * @param int                             $funnel_subscriber_id The funnel subscriber ID.
	 * @param mixed                           $funnel_metric The funnel metric.
	 *
	 * @return bool|void
	 */
	public function handle( $subscriber, $sequence, $funnel_subscriber_id, $funnel_metric ) {
		$value_key_pairs = [];
		$input_values    = $sequence->settings['contact_properties'];

		$field_operations = [];
		foreach ( $input_values as $pair ) {
			if ( empty( $pair['data_key'] ) ) {
				continue;
			}
			if ( ! empty( $pair['data_operation'] ) ) {
				$field_operations[ $pair['data_key'] ] = [
					'value'     => $pair['data_value'],
					'operation' => $pair['data_operation'],
				];
			} else {
				$value_key_pairs[ $pair['data_key'] ] = $pair['data_value'];
			}
		}

		if ( ! $value_key_pairs && ! $field_operations ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
			return false;
		}

		$custom_values     = [];
		$custom_field_keys = [];
		$custom_fields     = ( new CustomContactField() )->getGlobalFields()['fields'];

		foreach ( $custom_fields as $field ) {
			$slug                = $field['slug'];
			$custom_field_keys[] = $slug;

			if ( $field_operations && isset( $field_operations[ $slug ] ) ) {
				// We have to validate if it's number or not
				if ( ! in_array( $field['type'], [ 'number', 'checkbox', 'select-multi' ], true ) ) {
					$value_key_pairs[ $slug ] = $field_operations[ $slug ]['value'];
					unset( $field_operations[ $slug ] );
				} elseif ( 'number' === $field['type'] ) {
						$field_operations[ $slug ]['data_type'] = 'number';
				} else {
					$field_operations[ $slug ]['data_type']     = 'array';
					$field_operations[ $slug ]['valid_options'] = $field['options'];
				}
			}
		}

		if ( $field_operations ) {
			foreach ( $field_operations as $key => $operation ) {
				if ( ! in_array( $key, $custom_field_keys, true ) ) {
					continue;
				}

				$existing_value = $subscriber->getMeta( $key, 'custom_field' );
				if ( false === $existing_value || is_null( $existing_value ) || '' === $existing_value ) {
					$value_key_pairs[ $key ] = $field_operations[ $key ]['value'];
					continue;
				}

				if ( 'number' === $operation['data_type'] ) {
					if ( 'subtract' === $operation['operation'] ) {
						$new_value = $existing_value - intval( $operation['value'] );
					} else {
						$new_value = $existing_value + intval( $operation['value'] );
					}
				} else {
					$options          = (array) $operation['valid_options'];
					$provided_options = (array) $operation['value'];
					$provided_options = array_intersect( $options, $provided_options );
					$existing_options = (array) $existing_value;

					if ( 'subtract' === $operation['operation'] ) {
						$new_value = array_diff( $existing_options, $provided_options );
					} else {
						$new_value = array_unique( array_merge( $existing_options, $provided_options ) );
					}
				}

				$value_key_pairs[ $key ] = $new_value;
			}
		}

		if ( $custom_field_keys ) {
			$custom_fields_data = Arr::only( $value_key_pairs, $custom_field_keys );
			if ( $custom_fields ) {
				$custom_values = $this->formatCustomFieldValues( $custom_fields_data );
			}
		}

		$main_fields = array_filter( Arr::except( $value_key_pairs, $custom_field_keys ) );

		$custom_values_updates = [];
		if ( $custom_values ) {
			$custom_values_updates = $subscriber->syncCustomFieldValues( $custom_values, false );
		}

		$update_fields = [];

		if ( $main_fields ) {
			$subscriber->fill( $main_fields );
			$update_fields = $subscriber->getDirty();

			if ( $update_fields ) {
				$subscriber->save();
			}
		}

		if ( $custom_values_updates || $update_fields ) {
			do_action( 'fluentcrm_contact_updated', $subscriber, $update_fields );
			do_action( 'fluent_crm/contact_updated', $subscriber, $update_fields );
		}
	}

	/**
	 * Get the contact properties
	 *
	 * @return array<string,string|array<string,mixed>>
	 */
	private function getContactProperties() {
		$types                   = \fluentcrm_contact_types();
		$formatted_contact_types = [];

		foreach ( $types as $type => $label ) {
			$formatted_contact_types[] = [
				'id'    => $type,
				'slug'  => $type,
				'title' => $label,
			];
		}

		$fields = [
			'contact_type' => [
				'label'   => __( 'Contact Type', 'fluent-crm-custom-features' ),
				'type'    => 'select',
				'options' => $formatted_contact_types,
			],
			'source'       => [
				'label'       => __( 'Contact Source', 'fluent-crm-custom-features' ),
				'type'        => 'text',
				'placeholder' => __( 'Contact Source', 'fluent-crm-custom-features' ),
			],
			'country'      => [
				'label'      => __( 'Country', 'fluent-crm-custom-features' ),
				'type'       => 'option_selector',
				'option_key' => 'countries',
				'multiple'   => false,
			],
		];

		$custom_fields = fluentcrm_get_option( 'contact_custom_fields', [] );

		$valid_types      = [ 'text', 'date', 'textarea', 'date_time', 'number', 'select-one', 'select-multi', 'radio', 'checkbox' ];
		$formatted_fields = [];
		foreach ( $custom_fields as $custom_field ) {
			$custom_type = $custom_field['type'];

			if ( ! in_array( $custom_type, $valid_types, true ) ) {
				continue;
			}

			$field_type = $custom_type;

			$options = [];

			if ( in_array( $custom_type, [ 'select-one', 'select-multi', 'radio', 'checkbox' ], true ) ) {
				$field_type = 'select';
				$options    = [];
				foreach ( $custom_field['options'] as $option ) {
					$options[] = [
						'id'    => $option,
						'slug'  => $option,
						'title' => $option,
					];
				}
			}

			$formatted_fields[ $custom_field['slug'] ] = [
				'label' => $custom_field['label'],
				'type'  => $field_type,
			];

			if ( 'select' === $field_type ) {
				$formatted_fields[ $custom_field['slug'] ]['options']  = $options;
				$formatted_fields[ $custom_field['slug'] ]['multiple'] = 'select-multi' === $custom_type || 'checkbox' === $custom_type;
			}
		}

		if ( $formatted_fields ) {
			return $fields + $formatted_fields;
		}

		return $fields;
	}


	/**
	 * Fixes for current version of FluentCRM instance of this method
	 *
	 * @var string
	 */
	protected $global_meta_name = 'contact_custom_fields';

	/**
	 * Format custom field values
	 *
	 * @param array<string,mixed> $values The values to format.
	 * @param array<string,mixed> $fields The custom fields array.
	 *
	 * @return array<string,mixed>
	 */
	public function formatCustomFieldValues( $values, $fields = [] ) {
		if ( ! $values ) {
			return $values;
		}
		if ( ! $fields ) {
			$raw_fields = fluentcrm_get_option( $this->global_meta_name, [] );
			foreach ( $raw_fields as $field ) {
				$fields[ $field['slug'] ] = $field;
			}
		}

		foreach ( $values as $value_key => $value ) {
			$is_array_type = Arr::get( $fields, $value_key . '.type' ) === 'checkbox' || Arr::get( $fields, $value_key . '.type' ) === 'select-multi';

			if ( ! is_array( $value ) && $is_array_type ) {
				$item_values    = explode( ',', $value );
				$trimmed_values = array_map( 'trim', $item_values );
				if ( $trimmed_values ) {
					$values[ $value_key ] = $trimmed_values;
				}
			}
		}

		return array_filter($values, function ( $item ) {
			return null !== $item;
		});
	}
}
