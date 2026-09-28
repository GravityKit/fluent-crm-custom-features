<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * "Renewal Abandoned - Easy Digital Downloads" automation trigger.
 */
class EddRenewalCartAutomationTrigger extends EddCartAutomationTrigger {

	protected const PROVIDER = EddRenewalCartDriver::PROVIDER;

	protected function getLabel(): string {
		return __( 'License Renewal Abandoned - Easy Digital Downloads', 'fluent-crm-custom-features' );
	}

	protected function getDescription(): string {
		return __( 'This Funnel will be initiated when a license renewal has been left at checkout in Easy Digital Downloads', 'fluent-crm-custom-features' );
	}
}
