<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * Shoppers another system (Recapture) sent cart emails to before the switch-over. The resend cap
 * treats them like FluentCRM's own recipients, so nobody gets a second sequence on launch day.
 */
class PriorRecipients {

	public const OPTION = 'customcrm_edd_ab_cart_prior_recipients';

	/**
	 * When this email last got a cart email from the previous system.
	 *
	 * @param string $email Shopper email, any case.
	 * @return int Unix time; 0 when not on the list.
	 */
	public static function lastSentAt( string $email ): int {
		$list = (array) get_option( self::OPTION, [] );

		return (int) ( $list[ self::key( $email ) ] ?? 0 );
	}

	/**
	 * Replaces the list from a CSV with `email` and `last_sent_at` columns (ISO 8601 or Unix time, UTC).
	 *
	 * @param string $csv_path Path to the CSV.
	 * @return int Rows stored.
	 * @throws \RuntimeException When the file cannot be read, lacks the columns, or the list cannot be saved.
	 */
	public static function import( string $csv_path ): int {
		$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot read the CSV file.' );
		}

		$header = array_map( 'strtolower', array_map( 'trim', (array) fgetcsv( $handle ) ) );
		$email  = array_search( 'email', $header, true );
		$sent   = array_search( 'last_sent_at', $header, true );

		if ( false === $email || false === $sent ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new \RuntimeException( 'The CSV needs "email" and "last_sent_at" columns.' );
		}

		$list = [];

		while ( true ) {
			$row = fgetcsv( $handle );

			if ( false === $row ) {
				break;
			}

			$address = trim( (string) ( $row[ $email ] ?? '' ) );
			$value   = trim( (string) ( $row[ $sent ] ?? '' ) );
			$time    = ctype_digit( $value ) ? (int) $value : (int) strtotime( $value );

			if ( ! is_email( $address ) || $time <= 0 ) {
				continue;
			}

			$key          = self::key( $address );
			$list[ $key ] = max( $time, $list[ $key ] ?? 0 );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		update_option( self::OPTION, $list, false );

		// update_option() also returns false for an unchanged value, so read back instead.
		if ( get_option( self::OPTION ) !== $list ) {
			throw new \RuntimeException( 'The recipient list could not be saved.' );
		}

		return count( $list );
	}

	/**
	 * Keyed with the site's salt, so the stored list cannot be checked against guessed addresses.
	 *
	 * @param string $email Shopper email, any case.
	 */
	private static function key( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}
}
