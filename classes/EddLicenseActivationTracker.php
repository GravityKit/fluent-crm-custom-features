<?php

namespace CustomCRM;

/**
 * Tracks EDD Software Licensing activations as FluentCRM events.
 *
 * When a license is activated via the EDD SL API, this records an event in
 * fc_event_tracking so funnel conditions can check "has performed: Activated license key".
 */
class EddLicenseActivationTracker {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'edd_sl_activate_license', [ $this, 'trackActivation' ], 10, 2 );
	}

	/**
	 * Record a FluentCRM event when an EDD license is activated.
	 *
	 * @param int $license_id  The license ID.
	 * @param int $download_id The download/product ID.
	 */
	public function trackActivation( int $license_id, int $download_id ): void {
		if ( ! function_exists( 'FluentCrmApi' ) || ! function_exists( 'edd_software_licensing' ) ) {
			return;
		}

		$license = edd_software_licensing()->get_license( $license_id );

		if ( ! $license || ! $license->customer_id ) {
			return;
		}

		$customer = new \EDD_Customer( $license->customer_id );

		if ( ! $customer->id || ! $customer->email ) {
			return;
		}

		$download     = get_post( $download_id );
		$product_name = $download ? $download->post_title : '';

		$tracker = self::event_tracker();

		if ( ! $tracker ) {
			return;
		}

		$tracker->track( [
			'email'     => $customer->email,
			'provider'  => 'edd',
			'event_key' => 'license_activated',
			'title'     => 'Activated license key',
			'value'     => $product_name,
		] );
	}

	/**
	 * Resolve FluentCRM's event-tracking API across versions.
	 *
	 * The key was renamed `tracker` to `event_tracker` in FluentCRM 3.x
	 * (`fluent-crm/app/Api/config.php`). Asking for a key that does not exist THROWS rather than
	 * returning null, so the old name became an uncaught exception on every license activation
	 * the moment core was upgraded.
	 *
	 * @return object|null The API wrapper, or null when event tracking is unavailable.
	 */
	private static function event_tracker() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return null;
		}

		foreach ( [ 'event_tracker', 'tracker' ] as $key ) {
			try {
				return FluentCrmApi( $key );
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return null;
	}
}
