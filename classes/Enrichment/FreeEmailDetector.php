<?php
/**
 * FreeEmailDetector
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Detects whether an email address belongs to a free email provider.
 */
class FreeEmailDetector {

	/**
	 * Default list of free email provider domains.
	 *
	 * @var string[]
	 */
	private const DOMAINS = [
		'gmail.com',
		'googlemail.com',
		'yahoo.com',
		'yahoo.co.uk',
		'yahoo.co.jp',
		'yahoo.fr',
		'yahoo.de',
		'hotmail.com',
		'hotmail.co.uk',
		'outlook.com',
		'live.com',
		'msn.com',
		'icloud.com',
		'me.com',
		'mac.com',
		'aol.com',
		'protonmail.com',
		'proton.me',
		'zoho.com',
		'yandex.com',
		'yandex.ru',
		'mail.com',
		'mail.ru',
		'gmx.com',
		'gmx.de',
		'web.de',
		'fastmail.com',
		'tutanota.com',
		'tuta.io',
		'hey.com',
		'inbox.com',
	];

	/**
	 * Check if an email belongs to a free provider.
	 *
	 * @param string $email Email address.
	 *
	 * @return bool
	 */
	public static function isFreeEmail( string $email ): bool {
		$domain = self::extractDomain( $email );

		if ( '' === $domain ) {
			return false;
		}

		/**
		 * Filter the list of free email provider domains.
		 *
		 * @param string[] $domains Array of domain strings (e.g., 'gmail.com').
		 */
		$domains = apply_filters( 'custom_crm/free_email_domains', self::DOMAINS );
		$domains = array_map( 'strtolower', $domains );

		return in_array( $domain, $domains, true );
	}

	/**
	 * Extract the domain portion from an email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return string Domain or empty string.
	 */
	public static function extractDomain( string $email ): string {
		$at_pos = strrpos( $email, '@' );

		if ( false === $at_pos ) {
			return '';
		}

		return strtolower( substr( $email, $at_pos + 1 ) );
	}
}
