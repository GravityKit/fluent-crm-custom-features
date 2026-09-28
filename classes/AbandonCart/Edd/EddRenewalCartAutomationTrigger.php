<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * "Renewal Abandoned - Easy Digital Downloads" automation trigger.
 */
class EddRenewalCartAutomationTrigger extends EddCartAutomationTrigger {

	protected const PROVIDER = EddRenewalCartDriver::PROVIDER;

	/**
	 * Trigger name shown in the automation editor.
	 */
	protected function getLabel(): string {
		return __( 'License Renewal Abandoned - Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	/**
	 * Trigger description shown in the automation editor.
	 */
	protected function getDescription(): string {
		return __( 'This Funnel will be initiated when a license renewal has been left at checkout in Easy Digital Downloads', 'fluent-crm-custom-features' );
	}
}
