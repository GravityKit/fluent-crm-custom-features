<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * "License Upgrade Abandoned - Easy Digital Downloads" automation trigger.
 */
class EddUpgradeCartAutomationTrigger extends EddCartAutomationTrigger {

	protected const PROVIDER = EddUpgradeCartDriver::PROVIDER;

	/**
	 * Trigger name shown in the automation editor.
	 */
	protected function getLabel(): string {
		return __( 'License Upgrade Abandoned - Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	/**
	 * Trigger description shown in the automation editor.
	 */
	protected function getDescription(): string {
		return __( 'This Funnel will be initiated when a license upgrade has been left at checkout in Easy Digital Downloads', 'fluent-crm-custom-features' );
	}
}
