<?php
/**
 * AgencyProgressCommand
 *
 * @package CustomCRM
 */

namespace CustomCRM;

/**
 * Fills in agency progress fields for agencies that existed before AgencyProgressTracker.
 */
class AgencyProgressCommand {

	private const COLUMNS = [ 'agency', 'email', 'name', 'profile', 'clients', 'joined', 'assigned', 'paylinks', 'paid', 'result' ];

	/**
	 * Recount every agency and write the owner's FluentCRM progress fields.
	 *
	 * Records no events and starts no automation by itself. Writing custom fields can start an
	 * automation whose trigger is a field change; none exists for these fields.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print what would be written, and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gk-agency-crm sync --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( ! $dry_run ) {
			AgencyProgressTracker::registerFields();
		}

		$tracker = new AgencyProgressTracker();
		$rows    = [];

		foreach ( AgencyProgressTracker::allAgencyIds() as $agency_id ) {
			$state = AgencyProgressTracker::state( $agency_id );

			if ( ! $state ) {
				$rows[] = array_merge( array_fill_keys( self::COLUMNS, '' ), [
					'agency' => $agency_id,
					'email'  => '(no owner)',
					'result' => 'skipped',
				] );
				continue;
			}

			$has_contact = function_exists( 'FluentCrmApi' ) && FluentCrmApi( 'contacts' )->getContact( $state['email'] );
			$written     = ( $dry_run || ! $has_contact ) ? null : $tracker->sync( $agency_id );

			if ( ! $has_contact ) {
				$result = 'no FluentCRM contact';
			} elseif ( $dry_run ) {
				$result = 'would write';
			} else {
				$result = null === $written ? 'FAILED' : 'written';
			}

			$rows[] = [
				'agency'   => $agency_id,
				'email'    => $state['email'],
				'name'     => $state['fields']['gk_agency_name'],
				'profile'  => $state['fields']['gk_agency_profile_done'],
				'clients'  => $state['fields']['gk_agency_clients'],
				'joined'   => $state['fields']['gk_agency_clients_joined'],
				'assigned' => $state['fields']['gk_agency_licenses_assigned'],
				'paylinks' => $state['fields']['gk_agency_pay_links'],
				'paid'     => $state['fields']['gk_agency_pay_links_paid'],
				'result'   => $result,
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, self::COLUMNS );

		$failed = count( array_filter( $rows, static fn( $row ) => 'FAILED' === $row['result'] ) );

		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d agencies failed to sync.', $failed ) );
		}

		\WP_CLI::success( $dry_run ? 'Dry run: nothing written.' : 'Synced.' );
	}
}
