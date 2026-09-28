<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * Manages the list of shoppers Recapture emailed before the switch-over.
 */
class PriorRecipientsCommand {

	/**
	 * Replaces the list from a CSV with `email` and `last_sent_at` columns.
	 *
	 * Build the CSV with funnels/recapture/prior-recipients.sql.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV.
	 *
	 * @param string[] $args
	 */
	public function import( $args ): void {
		try {
			$count = PriorRecipients::import( (string) $args[0] );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		\WP_CLI::success( sprintf( '%d recipients stored.', $count ) );
	}

	/**
	 * Prints how many recipients are stored, and how many are still inside the resend cap.
	 */
	public function status(): void {
		$list    = (array) get_option( PriorRecipients::OPTION, [] );
		$cutoff  = time() - 21 * DAY_IN_SECONDS;
		$current = count( array_filter( $list, function ( $time ) use ( $cutoff ) {
			return (int) $time >= $cutoff;
		} ) );

		\WP_CLI::log( sprintf( '%d stored, %d sent in the last 21 days.', count( $list ), $current ) );
	}

	/**
	 * Deletes the list.
	 */
	public function clear(): void {
		delete_option( PriorRecipients::OPTION );
		\WP_CLI::success( 'Cleared.' );
	}
}
