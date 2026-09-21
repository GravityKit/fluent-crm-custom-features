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
	 * Contact custom fields this tracker writes, slug => label.
	 *
	 * @var array<string,string>
	 */
	private const USAGE_FIELDS = [
		'gk_sites_used'  => 'Sites Activated',
		'gk_sites_limit' => 'Site Limit',
	];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'gk/store/license-activated', [ $this, 'trackActivation' ], 10, 1 );
		add_action( 'gk/store/license-activation-denied', [ $this, 'trackDenied' ], 10, 1 );
		add_action( 'init', [ $this, 'registerUsageFields' ], 20 );
	}

	/**
	 * Register the two contact custom fields syncUsageFields() writes to.
	 *
	 * Without these registered, syncUsageFields() filters both slugs out and writes nothing — it
	 * had been a silent no-op since it was written. Registering them is what gives MAR-80's copy
	 * its {{contact.custom.gk_sites_used}} / {{contact.custom.gk_sites_limit}} merge tags.
	 *
	 * Idempotent and additive: it only ever appends missing slugs, so fields added or reordered in
	 * the FluentCRM UI are left alone.
	 */
	public function registerUsageFields(): void {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CustomContactField' ) ) {
			return;
		}

		$model    = new \FluentCrm\App\Models\CustomContactField();
		$fields   = (array) ( $model->getGlobalFields()['fields'] ?? [] );
		$existing = wp_list_pluck( $fields, 'slug' );
		$added    = false;

		foreach ( self::USAGE_FIELDS as $slug => $label ) {
			if ( in_array( $slug, $existing, true ) ) {
				continue;
			}

			$fields[] = [
				'type'  => 'number',
				'label' => $label,
				'slug'  => $slug,
			];

			$added = true;
		}

		if ( $added ) {
			// saveGlobalFields() is the model's own writer: it dedupes by slug and generates one
			// for any field missing it, so appending and handing back the whole list is safe.
			$model->saveGlobalFields( $fields );
		}
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

			if ( ! $customer || $this->isInternalCustomer( $customer->email ) ) {
				return;
			}

			$tracker = self::eventTracker();

			if ( ! $tracker ) {
				return;
			}

			$tracker->track( [
				'email'     => $customer->email,
				'provider'  => 'edd',
				'event_key' => self::EVENT_ACTIVATED,
				'title'     => 'Activated license key',
				'value'     => $this->buildEventValue( $license, $context ),
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

			if ( ! $customer || $this->isInternalCustomer( $customer->email ) ) {
				return;
			}

			$tracker = self::eventTracker();

			if ( ! $tracker ) {
				return;
			}

			$tracker->track( [
				'email'     => $customer->email,
				'provider'  => 'edd',
				'event_key' => self::EVENT_DENIED,
				'title'     => 'License activation denied (site limit reached)',
				'value'     => $this->buildEventValue( $license, $context ),
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
	 * Whether this email belongs to a GravityKit-owned account rather than a customer.
	 *
	 * Sixteen @gravitykit.com contacts hold 13,353 of the 64,288 counted activations — 21% — and
	 * one of them alone holds 13,256. Left in, they dominate every count built on these events and
	 * would put internal staff into customer-facing automations.
	 *
	 * @param string $email Contact email.
	 * @return bool
	 */
	private function isInternalCustomer( string $email ): bool {
		$domains = apply_filters( 'gk/fluentcrm/internal_email_domains', [ 'gravitykit.com', 'gravityview.co' ] );
		$at      = strrpos( $email, '@' );

		if ( false === $at ) {
			return false;
		}

		$domain = strtolower( substr( $email, $at + 1 ) );

		return in_array( $domain, array_map( 'strtolower', (array) $domains ), true );
	}

	/**
	 * Build the event's `value` as JSON so funnel conditions can read individual properties.
	 *
	 * FluentCRM's "Event JSON Prop" conditions (JSONEventTrackingHandler) cast every match value
	 * with (float), so every comparable property here is numeric — a string or a boolean would
	 * compare as 0. `progress` is precomputed because those conditions compare a property to a
	 * constant and cannot compare two properties to each other, which is what MAR-80's
	 * "1 < count < limit" test needs.
	 *
	 * `track()` runs this through sanitize_textarea_field(), which leaves JSON structurally intact
	 * but strips %xx sequences and anything between angle brackets. The URL is stored knowing that.
	 *
	 * @param mixed $license License object.
	 * @param array $context Store action payload.
	 * @return string JSON, or the bare product name if encoding fails.
	 */
	private function buildEventValue( $license, array $context ): string {
		$product = $this->getProductName( $license );
		$count   = is_object( $license ) ? (int) $license->activation_count : 0;
		$limit   = is_object( $license ) ? (int) $license->activation_limit : 0;

		// A limit of 0 means unlimited in EDD, so no site is ever "in progress" toward a cap.
		$in_progress = ( $limit > 0 && $count > 1 && $count < $limit ) ? 1 : 0;

		$payload = [
			'product'    => $product,
			'count'      => $count,
			'limit'      => $limit,
			'remaining'  => $limit > 0 ? max( 0, $limit - $count ) : -1,
			'progress'   => $in_progress,
			'is_new'     => ! empty( $context['is_new'] ) ? 1 : 0,
			'license_id' => is_object( $license ) && ! empty( $license->ID ) ? (int) $license->ID : 0,
			'url'        => (string) ( $context['url'] ?? '' ),
		];

		$json = wp_json_encode( $payload );

		return false === $json ? $product : $json;
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
	private static function eventTracker() {
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
