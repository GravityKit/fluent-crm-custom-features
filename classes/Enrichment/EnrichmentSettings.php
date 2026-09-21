<?php
/**
 * EnrichmentSettings
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Reads and writes enrichment provider settings.
 */
class EnrichmentSettings {

	private const OPTION_KEY = 'custom_crm_enrichment_settings';

	/**
	 * Get all settings.
	 *
	 * @return array{active_provider:string,providers:array<string,array<string,mixed>>}
	 */
	public static function getAll(): array {
		$defaults = [
			'active_provider' => '',
			'providers'       => [],
		];

		$settings = get_option( self::OPTION_KEY, $defaults );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Save all settings.
	 *
	 * @param array<string,mixed> $settings Full settings array.
	 */
	public static function saveAll( array $settings ): void {
		update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Get settings for a specific provider.
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return array<string,mixed>
	 */
	public static function getProviderSettings( string $slug ): array {
		$all = self::getAll();

		return $all['providers'][ $slug ] ?? [];
	}

	/**
	 * Save settings for a specific provider.
	 *
	 * @param string              $slug     Provider slug.
	 * @param array<string,mixed> $settings Provider-specific settings.
	 */
	public static function saveProviderSettings( string $slug, array $settings ): void {
		$all                       = self::getAll();
		$all['providers'][ $slug ] = $settings;

		// Auto-set active provider if none set.
		if ( empty( $all['active_provider'] ) ) {
			$all['active_provider'] = $slug;
		}

		self::saveAll( $all );
	}

	/**
	 * Get the active provider slug.
	 *
	 * @return string
	 */
	public static function getActiveProvider(): string {
		$all = self::getAll();

		return $all['active_provider'] ?? '';
	}

	/**
	 * Get the API key for a provider, decrypted.
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return string API key or empty string.
	 */
	public static function getApiKey( string $slug ): string {
		$settings = self::getProviderSettings( $slug );

		$encrypted = $settings['api_key'] ?? '';

		if ( '' === $encrypted ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * Encrypt a value for storage using OpenSSL with WordPress AUTH_KEY as the key.
	 *
	 * Requires the OpenSSL extension. Returns empty string on failure.
	 *
	 * @param string $value Plain text value.
	 *
	 * @return string Encrypted string (base64-encoded with 'enc:' prefix), or empty on failure.
	 */
	public static function encrypt( string $value ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[CustomCRM Enrichment] OpenSSL extension required for API key encryption.' );
			return '';
		}

		$key = self::getEncryptionKey();
		$iv  = openssl_random_pseudo_bytes( 16 );

		if ( false === $iv ) {
			return '';
		}

		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Returns empty string if decryption fails (corrupted data, missing OpenSSL, etc.).
	 *
	 * @param string $encrypted Encrypted string.
	 *
	 * @return string Decrypted value, or empty string on failure.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( 0 === strpos( $encrypted, 'enc:' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CustomCRM Enrichment] OpenSSL extension required to decrypt API key.' );
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw = base64_decode( substr( $encrypted, 4 ), true );

			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}

			$iv   = substr( $raw, 0, 16 );
			$data = substr( $raw, 16 );
			$key  = self::getEncryptionKey();

			$decrypted = openssl_decrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return ( false !== $decrypted ) ? $decrypted : '';
		}

		if ( 0 === strpos( $encrypted, 'b64:' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$decoded = base64_decode( substr( $encrypted, 4 ), true );
			return ( false !== $decoded ) ? $decoded : '';
		}

		// Plain text from old storage.
		return $encrypted;
	}

	/**
	 * Derive an encryption key from WordPress salts.
	 *
	 * Logs a warning if AUTH_KEY is not defined (shared fallback key is insecure).
	 *
	 * @return string 32-byte key.
	 */
	private static function getEncryptionKey(): string {
		if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[CustomCRM Enrichment] AUTH_KEY not defined; using weak fallback for encryption.' );
			$salt = 'custom-crm-fallback-key';
		} else {
			$salt = AUTH_KEY;
		}

		return hash( 'sha256', $salt, true );
	}
}
