<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\Drivers\FluentCart\FluentCartAutomationTrigger;

/**
 * "Cart Abandoned - Easy Digital Downloads" automation trigger.
 *
 * Reuses FluentCRM's FluentCart trigger (settings, priority, "run once", condition UI) and only
 * swaps the provider: the trigger name, the cart condition group key the runner matches on
 * (`ab_cart_edd`), and the product/category pickers.
 */
class EddCartAutomationTrigger extends FluentCartAutomationTrigger {

	protected const PROVIDER = EddCartDriver::PROVIDER;

	/**
	 * Registers the trigger under this provider's name.
	 */
	public function __construct() {
		$this->triggerName  = 'fc_ab_cart_simulation_' . static::PROVIDER;
		$this->priority     = 99;
		$this->actionArgNum = 1;

		// Skip FluentCartAutomationTrigger::__construct(), which would set the FluentCart trigger name.
		\FluentCrm\App\Services\Funnel\BaseTrigger::__construct();
	}

	/**
	 * The trigger as the automation editor lists it.
	 *
	 * @return array<string,mixed>
	 */
	public function getTrigger() {
		$trigger = parent::getTrigger();

		$trigger['category']    = __( 'Easy Digital Downloads', 'fluent-crm-custom-features' );
		$trigger['label']       = $this->getLabel();
		$trigger['description'] = $this->getDescription();
		$trigger['icon']        = 'fc-icon-edd';
		unset( $trigger['svg'] );

		return $trigger;
	}

	/**
	 * Trigger settings fields, titled for this provider.
	 *
	 * @param \FluentCrm\App\Models\Funnel $funnel
	 * @return array<string,mixed>
	 */
	public function getSettingsFields( $funnel ) {
		$fields = parent::getSettingsFields( $funnel );

		$fields['title']     = $this->getLabel();
		$fields['sub_title'] = $this->getDescription();

		return $fields;
	}

	/**
	 * Trigger name shown in the automation editor.
	 */
	protected function getLabel(): string {
		return __( 'Cart Abandoned - Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	/**
	 * Trigger description shown in the automation editor.
	 */
	protected function getDescription(): string {
		return __( 'This Funnel will be initiated when a cart has been abandoned in Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	/**
	 * Condition groups, with the cart group keyed and populated for EDD products and categories.
	 *
	 * @param \FluentCrm\App\Models\Funnel $funnel
	 * @return array<int,array<string,mixed>>
	 */
	public function getConditionGroups( $funnel ) {
		$groups = parent::getConditionGroups( $funnel );
		$group  = 'ab_cart_' . static::PROVIDER;

		foreach ( $groups as &$item ) {
			if ( 'ab_cart_fluent_cart' !== ( $item['value'] ?? '' ) ) {
				continue;
			}

			$item['value'] = $group;

			foreach ( $item['children'] as &$child ) {
				if ( 'cart_items' === $child['value'] ) {
					$child['option_key'] = 'edd_products';
				} elseif ( 'cart_items_categories' === $child['value'] ) {
					$child['taxonomy'] = 'download_category';
				}
			}
			unset( $child );
		}
		unset( $item );

		return $groups;
	}
}
