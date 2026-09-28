<?php

namespace CustomCRM\AbandonCart\Edd;

use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\Framework\Support\Arr;

/**
 * License renewals left at an EDD checkout, as their own FluentCRM cart provider, so they get
 * their own automation and never the new-customer sequence or its discount.
 */
class EddRenewalCartDriver extends EddCartDriver {

	public const PROVIDER = 'edd_renewal';

	public function getProviderSlug() {
		return self::PROVIDER;
	}

	public function getProviderLabel() {
		return __( 'Easy Digital Downloads (license renewals)', 'fluent-crm-custom-features' );
	}

	public function isAvailable() {
		return parent::isAvailable() && function_exists( 'edd_software_licensing' );
	}

	public function registerAutomationTrigger() {
		new EddRenewalCartAutomationTrigger();
	}

	/**
	 * Skip a renewal whose licenses were all renewed before the sequence could start, or a contact
	 * who already got this sequence recently.
	 *
	 * A recent unrelated purchase does not skip it: the renewal is still outstanding.
	 *
	 * @param AbandonCartModel $cart
	 */
	public function isWithinCoolOffPeriod( AbandonCartModel $cart ) {
		return $this->allLicensesRenewed( $cart ) || $this->sentRecently( $cart );
	}

	/**
	 * Whether every license in the cart now expires later than it did when the cart was saved.
	 *
	 * @param int[] $settled_license_ids Licenses already settled another way (upgraded), counted as renewed.
	 */
	public function allLicensesRenewed( AbandonCartModel $cart, array $settled_license_ids = [] ): bool {
		$renewals = array_filter(
			Arr::get( $cart->cart, 'cart_contents', [] ),
			function ( $item ) {
				return ! empty( $item['license_id'] );
			}
		);

		if ( ! $renewals ) {
			return false;
		}

		foreach ( $renewals as $item ) {
			if ( in_array( (int) $item['license_id'], $settled_license_ids, true ) ) {
				continue;
			}

			$license = edd_software_licensing()->get_license( (int) $item['license_id'] );

			if ( ! $license ) {
				continue;
			}

			$renewed = $license->is_lifetime || (int) $license->expiration > (int) Arr::get( $item, 'license_expiration', 0 );

			if ( ! $renewed ) {
				return false;
			}
		}

		return true;
	}
}
