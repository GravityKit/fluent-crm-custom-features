<?php
/**
 * AgencyFleetTagger
 *
 * @package CustomCRM
 */

namespace CustomCRM;

/**
 * Daily: tags customers with many activated sites "Likely agency" and saves each count to the contact.
 *
 * The count is distinct non-local sites with an active EDD license activation. It includes sites
 * that have since gone offline, so it runs higher than telemetry's 30-day count; the threshold is
 * set with that in mind. No automation uses the tag: it marks contacts for campaigns and review.
 *
 * Never creates contacts and never changes a contact's subscription status.
 */
class AgencyFleetTagger {

	public const TAG_SLUG    = 'likely-agency';
	public const TAG_TITLE   = 'Likely agency';
	public const FIELD       = 'gk_sites_activated';
	public const CRON_HOOK   = 'customcrm_agency_fleet_tag';
	public const REPORT      = 'customcrm_agency_fleet_report';
	public const MIN_TRACKED = 2;

	/**
	 * Sites needed for the tag. Filter `customcrm/agency_fleet/threshold`.
	 */
	public const THRESHOLD = 10;

	/**
	 * Refuse a run that would untag more than this share of the tagged contacts, so a broken query
	 * cannot empty the tag without anyone seeing it.
	 */
	private const MAX_REMOVAL_SHARE = 0.3;

	/**
	 * Register the daily event and, in admin and CLI, the contact field.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// 09:30 UTC: after the night's renewals, before the US workday.
			wp_schedule_event( strtotime( 'tomorrow 09:30 UTC' ), 'daily', self::CRON_HOOK );
		}

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::registerField();
		}
	}

	/**
	 * Register the site-count contact field. Additive: fields edited in the FluentCRM UI are left alone.
	 */
	public static function registerField(): void {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\CustomContactField' ) ) {
			return;
		}

		$model  = new \FluentCrm\App\Models\CustomContactField();
		$fields = (array) ( $model->getGlobalFields()['fields'] ?? [] );

		if ( in_array( self::FIELD, wp_list_pluck( $fields, 'slug' ), true ) ) {
			return;
		}

		$fields[] = [
			'type'  => 'number',
			'label' => 'Sites activated (EDD)',
			'slug'  => self::FIELD,
		];

		$model->saveGlobalFields( $fields );
	}

	/**
	 * Recount, then tag, untag and update the site-count field.
	 *
	 * @param bool $dry_run Work out the changes and write nothing.
	 *
	 * @return array<string,mixed> What changed, or would change; also saved as the last report.
	 */
	public function run( $dry_run = false ): array {
		$dry_run   = (bool) $dry_run;
		$threshold = (int) apply_filters( 'customcrm/agency_fleet/threshold', self::THRESHOLD );
		$report    = [
			'ran_at'    => gmdate( 'c' ),
			'dry_run'   => $dry_run,
			'threshold' => $threshold,
		];

		if ( ! function_exists( 'FluentCrmApi' ) ) {
			$report['error'] = 'FluentCRM is not active.';
			return $this->finish( $report, $dry_run );
		}

		$counts   = self::siteCounts();
		$contacts = self::contactsByEmail( array_keys( $counts ) );
		$tag      = self::tag( $dry_run );
		$tagged   = $tag ? self::taggedContactIds( (int) $tag->id ) : [];

		$want = [];

		foreach ( $counts as $email => $sites ) {
			if ( $sites >= $threshold && isset( $contacts[ $email ] ) ) {
				$want[ $contacts[ $email ]['id'] ] = true;
			}
		}

		$add    = array_diff( array_keys( $want ), $tagged );
		$remove = array_diff( $tagged, array_keys( $want ) );

		$report['customers_at_threshold'] = count( array_filter( $counts, static fn( $n ) => $n >= $threshold ) );
		$report['without_contact']        = $report['customers_at_threshold'] - count( $want );
		$report['tagged_before']          = count( $tagged );
		$report['to_tag']                 = count( $add );
		$report['to_untag']               = count( $remove );

		$draining = ! $want || ( $tagged && count( $remove ) / count( $tagged ) > self::MAX_REMOVAL_SHARE );

		if ( $tagged && $draining ) {
			$report['error'] = sprintf( 'Refused: would untag %d of %d contacts. Check the site counts before running again.', count( $remove ), count( $tagged ) );
			return $this->finish( $report, $dry_run );
		}

		$field_changes = [];

		foreach ( $counts as $email => $sites ) {
			$contact = $contacts[ $email ] ?? null;

			if ( $contact && (string) $sites !== (string) $contact['sites'] ) {
				$field_changes[ $contact['id'] ] = $sites;
			}
		}

		$report['field_updates'] = count( $field_changes );

		if ( $dry_run ) {
			return $this->finish( $report, $dry_run );
		}

		foreach ( $field_changes as $contact_id => $sites ) {
			$contact = \FluentCrm\App\Models\Subscriber::find( $contact_id );

			if ( $contact ) {
				$contact->syncCustomFieldValues( [ self::FIELD => $sites ], false );
			}
		}

		foreach ( array_chunk( $add, 200 ) as $ids ) {
			foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'id', $ids )->get() as $contact ) {
				$contact->attachTags( [ (int) $tag->id ] );
			}
		}

		foreach ( array_chunk( $remove, 200 ) as $ids ) {
			foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'id', $ids )->get() as $contact ) {
				$contact->detachTags( [ (int) $tag->id ] );
			}
		}

		return $this->finish( $report, $dry_run );
	}

	/**
	 * Save a real run's report and hand it back.
	 *
	 * @param array<string,mixed> $report  Run report.
	 * @param bool                $dry_run Whether anything was written.
	 *
	 * @return array<string,mixed>
	 */
	private function finish( array $report, bool $dry_run ): array {
		if ( ! $dry_run ) {
			update_option( self::REPORT, $report, false );
		}

		return $report;
	}

	/**
	 * Distinct non-local activated sites per customer email, for customers with at least MIN_TRACKED.
	 * Staff addresses are left out.
	 *
	 * @return array<string,int> Lowercased email => site count.
	 */
	public static function siteCounts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(c.email) AS email, COUNT(DISTINCT a.site_name) AS sites
				FROM {$wpdb->prefix}edd_license_activations a
				JOIN {$wpdb->prefix}edd_licenses l ON l.id = a.license_id
				JOIN {$wpdb->prefix}edd_customers c ON c.id = l.customer_id
				WHERE a.activated = 1 AND a.is_local = 0
				GROUP BY c.id
				HAVING sites >= %d",
				self::MIN_TRACKED
			)
		);

		$counts = [];

		foreach ( (array) $rows as $row ) {
			$is_staff = (bool) preg_match( '/@(gravitykit\.com|gravityview\.co|katzwebservices\.com|katz\.co)$/', $row->email );

			if ( ! $is_staff ) {
				$counts[ $row->email ] = (int) $row->sites;
			}
		}

		return $counts;
	}

	/**
	 * FluentCRM contacts for these emails, with their saved site count.
	 *
	 * @param string[] $emails Lowercased emails.
	 *
	 * @return array<string,array{id:int,sites:string}>
	 */
	private static function contactsByEmail( array $emails ): array {
		global $wpdb;

		$found = [];

		foreach ( array_chunk( $emails, 1000 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is only %s markers.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, LOWER(s.email) AS email, m.value AS sites
					FROM {$wpdb->prefix}fc_subscribers s
					LEFT JOIN {$wpdb->prefix}fc_subscriber_meta m ON m.subscriber_id = s.id AND m.object_type = 'custom_field' AND m.`key` = %s
					WHERE s.email IN ({$placeholders})",
					array_merge( [ self::FIELD ], $chunk )
				)
			);
			// phpcs:enable

			foreach ( (array) $rows as $row ) {
				$found[ $row->email ] = [
					'id'    => (int) $row->id,
					'sites' => (string) $row->sites,
				];
			}
		}

		return $found;
	}

	/**
	 * Contact ids that have the tag now.
	 *
	 * @param int $tag_id Tag.
	 *
	 * @return int[]
	 */
	private static function taggedContactIds( int $tag_id ): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT subscriber_id FROM {$wpdb->prefix}fc_subscriber_pivot WHERE object_id = %d AND object_type = %s",
					$tag_id,
					'FluentCrm\\App\\Models\\Tag'
				)
			)
		);
	}

	/**
	 * The tag, created on a real run if missing. Null on a dry run before it exists.
	 *
	 * @param bool $dry_run Whether to avoid creating it.
	 *
	 * @return \FluentCrm\App\Models\Tag|null
	 */
	private static function tag( bool $dry_run ) {
		$tag = \FluentCrm\App\Models\Tag::where( 'slug', self::TAG_SLUG )->first();

		if ( $tag || $dry_run ) {
			return $tag;
		}

		return \FluentCrm\App\Models\Tag::create(
			[
				'slug'        => self::TAG_SLUG,
				'title'       => self::TAG_TITLE,
				'description' => 'Set daily by fluent-crm-custom-features: customer has 10+ sites with an active license activation (EDD). No automation uses it.',
			]
		);
	}
}
