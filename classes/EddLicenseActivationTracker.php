<?php

namespace CustomCRM;

/**
 * Records GravityKit Store license activations as FluentCRM events.
 *
 * The Store API (GK\Store) owns the activation path and fires
 * `gk/store/license-activated` and `gk/store/license-activation-denied`. This
 * records matching events in fc_event_tracking so funnels can check
 * "has performed: Activated license key" (or the denied equivalent) and use
 * activation as an exit condition for the chase-to-activate onboarding funnels.
 *
 * Formerly hooked EDD SL's `edd_sl_activate_license`, which the Store API
 * bypasses, so it recorded nothing in production.
 */
class EddLicenseActivationTracker {

	private const EVENT_ACTIVATED = 'license_activated';
	private const EVENT_DENIED    = 'license_activation_denied';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'gk/store/license-activated', [ $this, 'trackActivation' ], 10, 1 );
		add_action( 'gk/store/license-activation-denied', [ $this, 'trackDenied' ], 10, 1 );
	}

	/**
	 * Record an event when a Store API license activation succeeds.
	 *
	 * @param array $context Store action payload: license, url, is_new, is_local, environment.
	 */
	public function trackActivation( $context ): void {
		try {
			// Local/dev activations are not customer milestones.
			if ( ! is_array( $context ) || ! empty( $context['is_local'] ) ) {
				return;
			}

			$license  = $context['license'] ?? null;
			$customer = $this->getCustomer( $license );

			if ( ! $customer ) {
				return;
			}

			FluentCrmApi( 'event_tracker' )->track( [
				'email'     => $customer->email,
				'provider'  => 'edd',
				'event_key' => self::EVENT_ACTIVATED,
				'title'     => 'Activated license key',
				'value'     => $this->getProductName( $license ),
			] );

			// Sites-used / limit power the "active on X of Y sites" progress email.
			// No-ops until those custom fields are registered in FluentCRM.
			$this->syncUsageFields( $customer->email, [
				'gk_sites_used'  => (int) $license->activation_count,
				'gk_sites_limit' => (int) $license->activation_limit,
			] );
		} catch ( \Throwable $e ) {
			$this->logFailure( 'activation', $e );
		}
	}

	/**
	 * Record an event when a Store API activation is denied at the site limit.
	 *
	 * @param array $context Store action payload: license, url, error_code.
	 */
	public function trackDenied( $context ): void {
		try {
			if ( ! is_array( $context ) ) {
				return;
			}

			$license  = $context['license'] ?? null;
			$customer = $this->getCustomer( $license );

			if ( ! $customer ) {
				return;
			}

			FluentCrmApi( 'event_tracker' )->track( [
				'email'     => $customer->email,
				'provider'  => 'edd',
				'event_key' => self::EVENT_DENIED,
				'title'     => 'License activation denied (site limit reached)',
				'value'     => $this->getProductName( $license ),
			] );
		} catch ( \Throwable $e ) {
			$this->logFailure( 'denial', $e );
		}
	}

	/**
	 * Resolve the EDD customer for a license, or null when unavailable.
	 *
	 * @param mixed $license License object from the Store action payload.
	 * @return \EDD_Customer|null
	 */
	private function getCustomer( $license ) {
		if ( ! function_exists( 'FluentCrmApi' ) || ! class_exists( 'EDD_Customer' ) ) {
			return null;
		}

		if ( ! is_object( $license ) || empty( $license->customer_id ) ) {
			return null;
		}

		$customer = new \EDD_Customer( $license->customer_id );

		if ( empty( $customer->id ) || empty( $customer->email ) ) {
			return null;
		}

		return $customer;
	}

	/**
	 * Product name for a license, or an empty string.
	 *
	 * @param mixed $license License object.
	 * @return string
	 */
	private function getProductName( $license ): string {
		$download_id = is_object( $license ) && ! empty( $license->download_id ) ? (int) $license->download_id : 0;
		$download    = $download_id ? get_post( $download_id ) : null;

		return $download ? $download->post_title : '';
	}

	/**
	 * Write site-usage values to the contact, skipping any field not registered
	 * as a FluentCRM custom contact field (so an unconfigured field is a no-op).
	 *
	 * @param string $email  Contact email.
	 * @param array  $values slug => value pairs.
	 */
	private function syncUsageFields( string $email, array $values ): void {
		if ( ! function_exists( 'FluentCrmApi' ) || ! function_exists( 'fluentcrm_get_option' ) ) {
			return;
		}

		$registered_slugs = wp_list_pluck( (array) fluentcrm_get_option( 'contact_custom_fields', [] ), 'slug' );
		$syncable         = array_intersect_key( $values, array_flip( $registered_slugs ) );

		if ( ! $syncable ) {
			return;
		}

		$contact = FluentCrmApi( 'contacts' )->getContact( $email );

		if ( $contact ) {
			$contact->syncCustomFieldValues( $syncable, false );
		}
	}

	/**
	 * Log a tracking failure without disrupting the activation response.
	 *
	 * @param string     $stage Which listener failed.
	 * @param \Throwable $e     The caught error.
	 */
	private function logFailure( string $stage, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'EddLicenseActivationTracker %s failed: %s', $stage, $e->getMessage() ) );
		}
	}
}
