<?php

namespace CustomCRM\AbandonCart\Edd;

use CustomCRM\EddLicenseActivationTracker;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Modules\AbandonCart\AbandonCartModel;
use FluentCrm\App\Modules\AbandonCart\AbCartHelper;
use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Services\Helper;

/**
 * Records when an upgrade or renewal starts and completes, on the FluentCRM contact, apart from
 * the abandoned-cart emails. It reads the same saved carts, so a cart FluentCRM does not save
 * (its provider switched off, the shopper opted out) starts nothing.
 *
 * Each record is a FluentCRM tracking event (`upgrade_started`, `upgrade_completed`,
 * `renewal_started`, `renewal_completed`). The contact page lists them, and automations can start
 * on them ("Tracking Event Recorded") or check them (Event Tracking conditions).
 *
 * This class owns "started", because it sees the cart: it records the event, then fires
 * `customcrm/edd_cart/journey_started`. The theme's mu-plugin owns "completed", because it sees the
 * order: it fires `gk/store/journey-completed`, and this class records that. Both are plain hooks,
 * so either side keeps working without the other.
 *
 * A cart row can't say whether a cart already started: the checkout's upgrade offer empties a
 * one-item cart when it swaps the item, which deletes the row and makes a new one. So a start is
 * claimed per license, in a transient that outlives the row. Undoing a swap leaves the claim in
 * place: the customer did start, and choosing the upgrade again is not a second start.
 *
 * Events stay stored as JSON, because Event Tracking conditions read their properties. Staff see
 * them as short sentences: in FluentCRM's Event Tracking panel, and on the contact's Journey tab,
 * which Conversion Bridge owns and otherwise only knows about website visits.
 */
class CartJourney {

	private const JOURNEYS = [
		EddUpgradeCartDriver::PROVIDER => 'upgrade',
		EddRenewalCartDriver::PROVIDER => 'renewal',
	];

	private const TITLES = [
		'upgrade_started'   => 'Upgrade started',
		'upgrade_completed' => 'Upgrade completed',
		'renewal_started'   => 'Renewal started',
		'renewal_completed' => 'Renewal completed',
	];

	private const CLAIM_PREFIX = 'customcrm_cart_journey_';

	/** The value and event key of one of these events in FluentCRM's Event Tracking panel. */
	private const PANEL_VALUE = '~<p class="fcrm_event_tracking_value">([^<]*)</p>(<div class="fcrm_event_tracking_footer"><div class="fcrm_event_tracking_badge">(?:upgrade|renewal)_(?:started|completed)\b)~';

	/** Days a license stays claimed. Matches the cart cookie, so an older cart counts as a new one. */
	private const CLAIM_DAYS = 30;

	/**
	 * Hooks the theme's mu-plugin calls. Nothing happens if it is not there.
	 */
	public static function register(): void {
		add_action( 'gk/store/journey-completed', [ self::class, 'orderCompleted' ], 10, 1 );
		add_filter( 'gk/store/is-staff-session', [ self::class, 'addStaffChecks' ], 10, 2 );
		add_filter( 'fluent_crm/subscriber_info_widgets', [ self::class, 'describePanelEvents' ], 12, 1 );
		add_filter( 'fluent_crm/subscriber_info_widget_event_tracking', [ self::class, 'describePanelEvents' ], 12, 1 );
		add_filter( 'fluencrm_profile_section_conversion_bridge', [ self::class, 'addToJourneyTab' ], 20, 2 );
	}

	/**
	 * Shows these events as a short sentence in the Event Tracking panel, not as JSON. Other events,
	 * and a panel whose markup has changed, are left as they are.
	 *
	 * @param array<string,mixed> $widgets The contact page's sidebar widgets.
	 * @return array<string,mixed>
	 */
	public static function describePanelEvents( $widgets ): array {
		$widgets = (array) $widgets;
		$content = (string) ( $widgets['event_tracking']['content'] ?? '' );

		if ( '' === $content ) {
			return $widgets;
		}

		$described = preg_replace_callback(
			self::PANEL_VALUE,
			static function ( array $match ): string {
				$summary = self::summary( html_entity_decode( $match[1], ENT_QUOTES ) );

				return '' === $summary ? $match[0] : '<p class="fcrm_event_tracking_value">' . esc_html( $summary ) . '</p>' . $match[2];
			},
			$content
		);

		$widgets['event_tracking']['content'] = $described ?? $content;

		return $widgets;
	}

	/**
	 * Lists these events on the contact's Journey tab, above any website visits. Replaces the tab's
	 * "No journey found", which would read as if nothing had been recorded.
	 *
	 * @param array<string,mixed> $section    The tab's content.
	 * @param Subscriber          $subscriber The contact.
	 * @return array<string,mixed>
	 */
	public static function addToJourneyTab( $section, $subscriber ): array {
		$section = (array) $section;
		$events  = EventTracker::where( 'subscriber_id', $subscriber->id )
			->whereIn( 'event_key', array_keys( self::TITLES ) )
			->orderBy( 'updated_at', 'DESC' )
			->get();

		if ( $events->isEmpty() ) {
			return $section;
		}

		$html = '<div style="padding:0 20px 20px"><h3>' . esc_html__( 'Upgrades and renewals', 'fluent-crm-custom-features' ) . '</h3><ul class="fcrm_event_tracking_lists">';

		foreach ( $events as $event ) {
			$html .= '<li><p class="fcrm_event_tracking_title">' . esc_html( $event->title ) . '</p>';
			$html .= '<p class="fcrm_event_tracking_value">' . esc_html( self::summary( (string) $event->value ) ) . '</p>';
			$html .= '<div class="fcrm_event_tracking_footer"><div class="fcrm_event_tracking_badge">' . esc_html( $event->event_key );
			$html .= '<span class="fcrm_event_tracking_count">' . (int) $event->counter . '</span></div>';
			$html .= '<span class="fcrm_event_tracking_date">' . esc_html( Helper::formatDateTime( $event->updated_at ) ) . '</span></div></li>';
		}

		$visits   = (string) ( $section['content_html'] ?? '' );
		$is_empty = __( 'No journey found', 'conversion-bridge' ) === $visits; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

		$section['content_html'] = $html . '</ul></div>' . ( $is_empty ? '' : $visits );

		return $section;
	}

	/**
	 * Says what a stored event value holds, such as "Order 795053, license 22985".
	 *
	 * @param string $value The event's JSON value.
	 * @return string Empty when the value isn't one of these events'.
	 */
	private static function summary( string $value ): string {
		$data        = json_decode( $value, true );
		$data        = is_array( $data ) ? $data : [];
		$license_ids = array_filter( explode( ',', (string) ( $data['license_ids'] ?? '' ) ) );
		$order_id    = (int) ( $data['order_id'] ?? 0 );
		$cart_id     = (int) ( $data['cart_id'] ?? 0 );

		if ( ! $license_ids || ( ! $order_id && ! $cart_id ) ) {
			return '';
		}

		/* translators: %s: comma-separated license IDs. */
		$licenses = sprintf( _n( 'license %s', 'licenses %s', count( $license_ids ), 'fluent-crm-custom-features' ), implode( ', ', $license_ids ) );

		/* translators: 1: order ID, 2: the licenses, such as "license 22985". */
		$order = __( 'Order %1$d, %2$s', 'fluent-crm-custom-features' );

		/* translators: 1: cart ID, 2: the licenses, such as "license 22985". */
		$cart = __( 'Cart %1$d, %2$s', 'fluent-crm-custom-features' );

		return $order_id ? sprintf( $order, $order_id, $licenses ) : sprintf( $cart, $cart_id, $licenses );
	}

	/**
	 * Starts the journey of a saved cart, the first time its licenses are seen.
	 *
	 * Runs inside add-to-cart and the checkout's AJAX, so an error is logged and swallowed.
	 *
	 * @param AbandonCartModel               $cart  The saved cart.
	 * @param array<int,array<string,mixed>> $items The cart's items.
	 */
	public static function cartSaved( AbandonCartModel $cart, array $items ): void {
		try {
			$journey     = self::JOURNEYS[ (string) $cart->provider ] ?? '';
			$license_ids = self::licenseIds( $items );

			$is_staff    = (bool) apply_filters( 'gk/store/is-staff-session', false, (string) $cart->email );

			if ( '' === $journey || ! $license_ids || $is_staff || ! self::claim( $journey, $license_ids ) ) {
				return;
			}

			self::record(
				$journey . '_started',
				(string) $cart->email,
				(int) $cart->user_id,
				[
					'cart_id'     => (int) $cart->id,
					'license_ids' => implode( ',', $license_ids ),
				]
			);

			/**
			 * Fires once when a cart becomes an upgrade or renewal cart.
			 *
			 * @param array $started {
			 *     @type string $journey     `upgrade` or `renewal`.
			 *     @type int    $cart_id     Cart row ID.
			 *     @type int[]  $license_ids Licenses in the cart.
			 * }
			 */
			do_action(
				'customcrm/edd_cart/journey_started',
				[
					'journey'     => $journey,
					'cart_id'     => (int) $cart->id,
					'license_ids' => $license_ids,
				]
			);
		} catch ( \Throwable $e ) {
			EddCartTracking::logError( 'cartSaved', $e );
		}
	}

	/**
	 * Records a finished upgrade or renewal, and frees its licenses for a later cart.
	 *
	 * @param array<string,mixed> $completed See `gk/store/journey-completed`.
	 */
	public static function orderCompleted( $completed ): void {
		$completed   = (array) $completed;
		$journey     = (string) ( $completed['journey'] ?? '' );
		$license_ids = array_map( 'intval', (array) ( $completed['license_ids'] ?? [] ) );

		self::release( $license_ids );

		self::record(
			$journey . '_completed',
			(string) ( $completed['email'] ?? '' ),
			(int) ( $completed['user_id'] ?? 0 ),
			[
				'order_id'    => (int) ( $completed['order_id'] ?? 0 ),
				'order_type'  => (string) ( $completed['order_type'] ?? '' ),
				'license_ids' => implode( ',', $license_ids ),
			]
		);
	}

	/**
	 * Adds this site's own rules for who counts as staff: anyone switched to a customer, FluentCRM's
	 * roles excluded from cart tracking, and internal email domains, which a team member testing
	 * while logged out still uses.
	 *
	 * @param bool   $is_staff Whether an earlier check already said so.
	 * @param string $email    The cart or order email, when there is one.
	 */
	public static function addStaffChecks( $is_staff, $email = '' ): bool {
		$is_switched    = function_exists( 'current_user_switched' ) && (bool) current_user_switched();
		$excluded_roles = (array) AbCartHelper::getSetting( 'disabled_user_roles', [] );
		$has_role       = (bool) array_intersect( (array) wp_get_current_user()->roles, $excluded_roles );

		return $is_staff || $is_switched || $has_role || EddLicenseActivationTracker::isInternalCustomer( (string) $email );
	}

	/**
	 * License IDs of a cart's upgrade and renewal items.
	 *
	 * @param array<int,array<string,mixed>> $items The cart's items.
	 * @return int[] Sorted.
	 */
	private static function licenseIds( array $items ): array {
		$license_ids = array_map( 'intval', wp_list_pluck( $items, 'license_id' ) );
		$license_ids = array_unique( array_filter( $license_ids ) );

		sort( $license_ids );

		return $license_ids;
	}

	/**
	 * Claims these licenses for a journey. Every license is claimed, so adding one to a started
	 * cart is not a new start.
	 *
	 * Two requests in the same instant can both see no claim. PostHog drops the repeat, because
	 * its uuid comes from the licenses and the day; the contact's counter would read 2.
	 *
	 * @param string $journey     `upgrade` or `renewal`.
	 * @param int[]  $license_ids Licenses in the cart.
	 * @return bool True when none of them was claimed, so this cart is a new start.
	 */
	private static function claim( string $journey, array $license_ids ): bool {
		$is_new = true;

		foreach ( $license_ids as $license_id ) {
			$key        = self::claimKey( $journey, $license_id );
			$is_claimed = false !== get_transient( $key );

			if ( $is_claimed ) {
				$is_new = false;
				continue;
			}

			set_transient( $key, time(), self::CLAIM_DAYS * DAY_IN_SECONDS );
		}

		return $is_new;
	}

	/**
	 * Frees licenses after an order, so the next cart for them is a new start.
	 *
	 * @param int[] $license_ids Licenses the order upgraded or renewed.
	 */
	private static function release( array $license_ids ): void {
		foreach ( $license_ids as $license_id ) {
			foreach ( self::JOURNEYS as $journey ) {
				delete_transient( self::claimKey( $journey, $license_id ) );
			}
		}
	}

	/**
	 * The transient that claims a license.
	 *
	 * @param string $journey    `upgrade` or `renewal`.
	 * @param int    $license_id License ID.
	 */
	private static function claimKey( string $journey, int $license_id ): string {
		return self::CLAIM_PREFIX . $journey . '_' . $license_id;
	}

	/**
	 * Adds a tracking event to the contact. The event's date is when it last happened and its
	 * counter how many times; `value` holds the latest cart or order.
	 *
	 * Never creates a contact: that could start other automations.
	 *
	 * @param string              $event_key One of the TITLES keys.
	 * @param string              $email     Cart or order email.
	 * @param int                 $user_id   Cart or order user; 0 for a guest.
	 * @param array<string,mixed> $value     Stored with the event, as JSON. Scalars only: the contact
	 *                                       page runs each value through wp_kses_post().
	 */
	private static function record( string $event_key, string $email, int $user_id, array $value ): void {
		$contact = self::findContact( $email, $user_id );
		$tracker = EddLicenseActivationTracker::eventTracker();

		if ( ! isset( self::TITLES[ $event_key ] ) || ! $contact || ! $tracker ) {
			return;
		}

		$result = $tracker->track(
			[
				'subscriber' => $contact,
				'provider'   => 'edd',
				'event_key'  => $event_key,
				'title'      => self::TITLES[ $event_key ],
				'value'      => wp_json_encode( $value ),
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( sprintf( '[fluent-crm-custom-features] %s not recorded: %s', $event_key, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * The contact with this email, or else the one linked to this user: a customer may type
	 * another address at checkout.
	 *
	 * @param string $email   Cart or order email.
	 * @param int    $user_id Cart or order user; 0 for a guest.
	 */
	private static function findContact( string $email, int $user_id ): ?Subscriber {
		$contacts = FluentCrmApi( 'contacts' );
		$contact  = '' === $email ? null : $contacts->getContact( $email );

		if ( ! $contact && $user_id ) {
			$contact = $contacts->getContactByUserRef( $user_id );
		}

		return $contact ?: null;
	}
}
