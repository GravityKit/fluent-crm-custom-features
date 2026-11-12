<?php
/**
 * AgencyProgressTracker
 *
 * @package CustomCRM
 */

namespace CustomCRM;

/**
 * Keeps each agency owner's FluentCRM contact in step with what the agency has done.
 *
 * Listens to the agency package's actions (Block-Theme `mu-plugin/gravitykit/Agency`), records each
 * step as a FluentCRM event and rewrites the contact's progress fields for the onboarding automation.
 *
 * Fields are recounted from the agency tables on every step, so a missed hook, a retry and the
 * backfill command all end at the same numbers.
 */
class AgencyProgressTracker {

	/**
	 * Contact custom fields this writes, slug => [ type, label ].
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	public const FIELDS = [
		'gk_agency_name'              => [ 'text', 'Agency name' ],
		'gk_agency_profile_done'      => [ 'number', 'Agency profile filled in (1 = yes)' ],
		'gk_agency_clients'           => [ 'number', 'Agency clients added' ],
		'gk_agency_clients_joined'    => [ 'number', 'Agency clients who signed in' ],
		'gk_agency_licenses_assigned' => [ 'number', 'Agency licenses assigned to clients' ],
		'gk_agency_pay_links'         => [ 'number', 'Agency pay links sent' ],
		'gk_agency_pay_links_paid'    => [ 'number', 'Agency pay links paid' ],
		'gk_agency_reward_code'       => [ 'text', 'Agency reward code' ],
		'gk_agency_reward_expires'    => [ 'text', 'Agency reward code expires' ],
		'gk_agency_reward_used'       => [ 'number', 'Agency reward code used (1 = yes)' ],
	];

	/**
	 * Event keys, one per step an agency can take.
	 */
	public const EVENT_PROFILE     = 'agency_profile_saved';
	public const EVENT_CLIENT      = 'agency_client_added';
	public const EVENT_JOINED      = 'agency_client_joined';
	public const EVENT_ASSIGNED    = 'agency_license_assigned';
	public const EVENT_PAY_LINK    = 'agency_pay_link_sent';
	public const EVENT_PAID        = 'agency_client_paid';
	public const EVENT_HANDOVER    = 'agency_license_handed_over';
	public const EVENT_REWARD_USED = 'agency_reward_used';

	/**
	 * Prefix of the codes AgencyRewardCodeAction makes by default; how a reward is told apart at checkout.
	 */
	public const REWARD_PREFIX = 'AGENCY';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'gk/edd-agency/agency/granted', [ $this, 'onGranted' ], 20, 1 );
		add_action( 'gk/edd-agency/subaccount/created', [ $this, 'onClientAdded' ], 20, 2 );
		add_action( 'gk/edd-agency/subaccount/linked', [ $this, 'onClientAdded' ], 20, 2 );
		add_action( 'gk/edd-agency/subaccount/claimed', [ $this, 'onClientJoined' ], 20, 1 );
		add_action( 'gk/edd-agency/license/bound', [ $this, 'onLicenseAssigned' ], 20, 2 );
		add_action( 'gk/edd-agency/license/unbound', [ $this, 'onLicenseUnassigned' ], 20, 2 );
		add_action( 'gk/edd-agency/pay-link/created', [ $this, 'onPayLinkSent' ], 20, 3 );
		add_action( 'gk/edd-agency/pay-link/paid', [ $this, 'onPayLinkPaid' ], 20, 1 );
		add_action( 'gk/edd-agency/transfer/completed', [ $this, 'onHandedOver' ], 20, 1 );
		add_action( 'gk/edd-agency/audit/logged', [ $this, 'onAudit' ], 20, 3 );
		add_action( 'edd_complete_purchase', [ $this, 'onPurchase' ], 30, 1 );

		// register() runs inside an init callback, so admin_init/CLI is the first chance left. Gated
		// like EddLicenseActivationTracker: front-end requests never need the option write.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::registerFields();
		}
	}

	/**
	 * Register the contact custom fields. Additive: fields edited in the FluentCRM UI are left alone.
	 */
	public static function registerFields(): void {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CustomContactField' ) ) {
			return;
		}

		$model    = new \FluentCrm\App\Models\CustomContactField();
		$fields   = (array) ( $model->getGlobalFields()['fields'] ?? [] );
		$existing = wp_list_pluck( $fields, 'slug' );
		$added    = false;

		foreach ( self::FIELDS as $slug => [ $type, $label ] ) {
			if ( in_array( $slug, $existing, true ) ) {
				continue;
			}

			$fields[] = [
				'type'  => $type,
				'label' => $label,
				'slug'  => $slug,
			];

			$added = true;
		}

		if ( $added ) {
			$model->saveGlobalFields( $fields );
		}
	}

	/**
	 * Writes a new agency's counts as 0, so the automation's first check has a number to compare.
	 *
	 * Runs after ListSync (priority 10) has created the contact and the agency row exists.
	 *
	 * @param int $user_id Granted user.
	 */
	public function onGranted( $user_id ): void {
		$agency_id = self::agencyForUser( (int) $user_id );

		if ( $agency_id ) {
			$this->sync( $agency_id );
		}
	}

	/**
	 * A client was added or linked.
	 *
	 * @param int $subaccount_id New client row.
	 * @param int $agency_id     Agency it belongs to.
	 */
	public function onClientAdded( $subaccount_id, $agency_id ): void {
		$this->sync( (int) $agency_id, self::EVENT_CLIENT, 'Added a client' );
	}

	/**
	 * A client signed in for the first time.
	 *
	 * @param int $subaccount_id Client who claimed their invitation.
	 */
	public function onClientJoined( $subaccount_id ): void {
		$this->sync( self::agencyForClient( (int) $subaccount_id ), self::EVENT_JOINED, 'A client signed in' );
	}

	/**
	 * A license was assigned to a client.
	 *
	 * @param int $license_id    License filed under the client.
	 * @param int $subaccount_id Client it was filed under.
	 */
	public function onLicenseAssigned( $license_id, $subaccount_id ): void {
		$this->sync( self::agencyForClient( (int) $subaccount_id ), self::EVENT_ASSIGNED, 'Assigned a license to a client' );
	}

	/**
	 * A license was taken off a client: recount only, no event.
	 *
	 * @param int $license_id    License that was unfiled.
	 * @param int $subaccount_id Client it was filed under.
	 */
	public function onLicenseUnassigned( $license_id, $subaccount_id ): void {
		$this->sync( self::agencyForClient( (int) $subaccount_id ) );
	}

	/**
	 * A payment link was made.
	 *
	 * @param int $link_id    Pay link row.
	 * @param int $license_id License it renews.
	 * @param int $created_by User who made it.
	 */
	public function onPayLinkSent( $link_id, $license_id, $created_by ): void {
		$this->sync( self::agencyForUser( (int) $created_by ), self::EVENT_PAY_LINK, 'Sent a client a pay link' );
	}

	/**
	 * A client paid a payment link.
	 *
	 * @param int $link_id Pay link row that was paid.
	 */
	public function onPayLinkPaid( $link_id ): void {
		$created_by = (int) self::db()->get_var(
			self::db()->prepare( 'SELECT created_by FROM ' . self::table( 'gk_pay_links' ) . ' WHERE id = %d', (int) $link_id )
		);

		$this->sync( self::agencyForUser( $created_by ), self::EVENT_PAID, 'A client paid a pay link' );
	}

	/**
	 * A license was handed over to a client for good.
	 *
	 * @param int $transfer_id Completed transfer row.
	 */
	public function onHandedOver( $transfer_id ): void {
		$agency_id = (int) self::db()->get_var(
			self::db()->prepare( 'SELECT agency_id FROM ' . self::table( 'gk_transfers' ) . ' WHERE id = %d', (int) $transfer_id )
		);

		$this->sync( $agency_id, self::EVENT_HANDOVER, 'Handed a license over to a client' );
	}

	/**
	 * The profile form fires no action of its own; its audit entry is the one signal it saved.
	 *
	 * @param string $route    Audited route.
	 * @param int    $actor_id User who made the change.
	 * @param array  $target   Route details; `agency_id` for the profile route.
	 */
	public function onAudit( $route, $actor_id, $target ): void {
		if ( 'POST /agency' !== $route || ! is_array( $target ) || empty( $target['agency_id'] ) ) {
			return;
		}

		$this->sync( (int) $target['agency_id'], self::EVENT_PROFILE, 'Saved the agency profile' );
	}

	/**
	 * Marks the reward code used, which skips the reward automation's reminder.
	 *
	 * @param int $order_id Completed order.
	 */
	public function onPurchase( $order_id ): void {
		try {
			if ( ! function_exists( 'edd_get_order_adjustments' ) || ! function_exists( 'edd_get_order' ) ) {
				return;
			}

			$codes = wp_list_pluck(
				(array) edd_get_order_adjustments( [
					'object_id'   => (int) $order_id,
					'object_type' => 'order',
					'type'        => 'discount',
				] ),
				'description'
			);

			$prefix      = strtoupper( self::REWARD_PREFIX ) . '-';
			$reward_code = '';

			foreach ( $codes as $code ) {
				if ( 0 === strpos( strtoupper( (string) $code ), $prefix ) ) {
					$reward_code = (string) $code;
					break;
				}
			}

			$order   = edd_get_order( (int) $order_id );
			$tracker = $reward_code && $order ? self::eventTracker() : null;

			$contact = $reward_code && $order && function_exists( 'FluentCrmApi' ) ? FluentCrmApi( 'contacts' )->getContact( $order->email ) : null;

			if ( $contact ) {
				$contact->syncCustomFieldValues( [ 'gk_agency_reward_used' => 1 ], false );
			}

			if ( $tracker ) {
				$tracker->track( [
					'email'     => $order->email,
					'provider'  => 'gk_agency',
					'event_key' => self::EVENT_REWARD_USED,
					'title'     => 'Used the agency reward code',
					'value'     => $reward_code,
				] );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only, like EddLicenseActivationTracker.
				sprintf( 'AgencyProgressTracker reward check on order %d failed: %s', (int) $order_id, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Recount one agency, write the owner's contact fields and, when given, record the step.
	 *
	 * Never throws: a CRM failure must not break the agency action that triggered it.
	 *
	 * @param int    $agency_id `wp_gk_agencies.id`.
	 * @param string $event_key Event to record, or '' for a plain recount.
	 * @param string $title     Event title shown on the contact.
	 *
	 * @return array<string,int|string>|null The values written, or null when nothing was synced.
	 */
	public function sync( int $agency_id, string $event_key = '', string $title = '' ): ?array {
		try {
			if ( $agency_id <= 0 || ! function_exists( 'FluentCrmApi' ) ) {
				return null;
			}

			$state = self::state( $agency_id );

			if ( ! $state ) {
				return null;
			}

			$contact = FluentCrmApi( 'contacts' )->getContact( $state['email'] );

			if ( ! $contact ) {
				return null;
			}

			$registered = wp_list_pluck( (array) fluentcrm_get_option( 'contact_custom_fields', [] ), 'slug' );
			$values     = array_intersect_key( $state['fields'], array_flip( $registered ) );

			if ( $values ) {
				$contact->syncCustomFieldValues( $values, false );
			}

			if ( $event_key ) {
				$tracker = self::eventTracker();

				if ( $tracker ) {
					$tracker->track( [
						'email'     => $state['email'],
						'provider'  => 'gk_agency',
						'event_key' => $event_key,
						'title'     => $title,
						'value'     => $state['fields']['gk_agency_name'],
					] );
				}
			}

			return $state['fields'];
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only, like EddLicenseActivationTracker.
				sprintf( 'AgencyProgressTracker sync of agency %d failed: %s', $agency_id, $e->getMessage() ) );
			}

			return null;
		}
	}

	/**
	 * The owner's email and the counts the automation branches on.
	 *
	 * @param int $agency_id `wp_gk_agencies.id`.
	 *
	 * @return array{email:string,fields:array<string,int|string>}|null
	 */
	public static function state( int $agency_id ): ?array {
		$db     = self::db();
		$agency = $db->get_row(
			$db->prepare( 'SELECT id, owner_user_id, name, website_url FROM ' . self::table( 'gk_agencies' ) . ' WHERE id = %d', $agency_id ),
			ARRAY_A
		);

		if ( ! $agency ) {
			return null;
		}

		$owner = get_userdata( (int) $agency['owner_user_id'] );

		if ( ! $owner instanceof \WP_User || ! $owner->user_email ) {
			return null;
		}

		$clients = self::table( 'gk_subaccounts' );
		$members = self::agencyUserIds( $agency_id, (int) $agency['owner_user_id'] );
		$in_list = implode( ',', array_map( 'intval', $members ) );

		$name = trim( (string) $agency['name'] );

		return [
			'email'  => $owner->user_email,
			'fields' => [
				'gk_agency_name'              => $name,
				'gk_agency_profile_done'      => self::profileDone( $name, (string) $agency['website_url'], $owner ) ? 1 : 0,
				'gk_agency_clients'           => (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$clients} WHERE agency_id = %d", $agency_id ) ),
				'gk_agency_clients_joined'    => (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$clients} WHERE agency_id = %d AND user_id IS NOT NULL AND status = 'active'", $agency_id ) ),
				'gk_agency_licenses_assigned' => (int) $db->get_var(
					$db->prepare(
						'SELECT COUNT(DISTINCT b.license_id) FROM ' . self::table( 'gk_license_bindings' ) . " b JOIN {$clients} s ON s.id = b.subaccount_id WHERE s.agency_id = %d",
						$agency_id
					)
				),
				// The ids come from the database as integers, so the IN list needs no placeholders.
				'gk_agency_pay_links'         => (int) $db->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'gk_pay_links' ) . " WHERE created_by IN ({$in_list})" ),
				'gk_agency_pay_links_paid'    => (int) $db->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'gk_pay_links' ) . " WHERE created_by IN ({$in_list}) AND paid_order_id IS NOT NULL AND paid_order_id > 0" ),
			],
		];
	}

	/**
	 * Whether the agency has told us who it is: a name that is not just the owner's own name, or a
	 * website. New agencies are named after the owner's display name, and that name signs the
	 * emails their clients get.
	 *
	 * @param string   $name    Agency name.
	 * @param string   $website Agency website.
	 * @param \WP_User $owner   Agency owner.
	 */
	public static function profileDone( string $name, string $website, \WP_User $owner ): bool {
		if ( '' !== trim( $website ) ) {
			return true;
		}

		if ( '' === $name ) {
			return false;
		}

		$person_names = array_filter( array_map( 'strtolower', array_map( 'trim', [
			(string) $owner->display_name,
			trim( $owner->first_name . ' ' . $owner->last_name ),
			(string) $owner->user_login,
			(string) $owner->user_email,
		] ) ) );

		return ! in_array( strtolower( $name ), $person_names, true );
	}

	/**
	 * Every agency id, for the backfill command.
	 *
	 * @return int[]
	 */
	public static function allAgencyIds(): array {
		return array_map( 'intval', self::db()->get_col( 'SELECT id FROM ' . self::table( 'gk_agencies' ) . ' ORDER BY id' ) );
	}

	/**
	 * The agency a user owns or manages.
	 *
	 * @param int $user_id WordPress user.
	 */
	public static function agencyForUser( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( is_callable( [ '\\GravityKit\\EDDAgency\\Agencies\\AgencyIdentity', 'for_user' ] ) ) {
			return (int) \GravityKit\EDDAgency\Agencies\AgencyIdentity::for_user( $user_id );
		}

		$db = self::db();

		return (int) $db->get_var( $db->prepare( 'SELECT id FROM ' . self::table( 'gk_agencies' ) . ' WHERE owner_user_id = %d ORDER BY id LIMIT 1', $user_id ) );
	}

	/**
	 * The agency a client row belongs to.
	 *
	 * @param int $subaccount_id `wp_gk_subaccounts.id`.
	 */
	public static function agencyForClient( int $subaccount_id ): int {
		$db = self::db();

		return (int) $db->get_var( $db->prepare( 'SELECT agency_id FROM ' . self::table( 'gk_subaccounts' ) . ' WHERE id = %d', $subaccount_id ) );
	}

	/**
	 * The owner plus every active member, who can all make pay links for the agency.
	 *
	 * @param int $agency_id     Agency.
	 * @param int $owner_user_id Its owner.
	 *
	 * @return int[]
	 */
	private static function agencyUserIds( int $agency_id, int $owner_user_id ): array {
		$db  = self::db();
		$ids = $db->get_col(
			$db->prepare( 'SELECT user_id FROM ' . self::table( 'gk_agency_members' ) . " WHERE agency_id = %d AND status = 'active'", $agency_id )
		);

		return array_values( array_unique( array_filter( array_map( 'intval', array_merge( [ $owner_user_id ], (array) $ids ) ) ) ) );
	}

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Table name without the prefix.
	 */
	private static function table( string $name ): string {
		return self::db()->prefix . $name;
	}

	/**
	 * The database connection.
	 *
	 * @return \wpdb
	 */
	private static function db() {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * FluentCRM's event API; the key was renamed in 3.x (see EddLicenseActivationTracker).
	 *
	 * @return object|null
	 */
	private static function eventTracker() {
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
