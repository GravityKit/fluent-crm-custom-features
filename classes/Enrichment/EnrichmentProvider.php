<?php
/**
 * EnrichmentProvider
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Abstract contract for enrichment data providers.
 *
 * Implementations must normalize their API responses into PersonResult/CompanyResult
 * DTOs and map HTTP errors to EnrichmentError instances.
 */
abstract class EnrichmentProvider {

	/**
	 * Unique slug for this provider (e.g. 'pdl').
	 *
	 * @return string
	 */
	abstract public function getSlug(): string;

	/**
	 * Human-readable provider name (e.g. 'People Data Labs').
	 *
	 * @return string
	 */
	abstract public function getName(): string;

	/**
	 * Enrich a person.
	 *
	 * @param array<string,string> $params Keys like 'email', 'first_name', 'last_name', 'company', etc.
	 *
	 * @return PersonResult|EnrichmentError
	 */
	abstract public function enrichPerson( array $params );

	/**
	 * Enrich a company.
	 *
	 * @param array<string,string> $params Keys like 'website', 'name', 'linkedin_url', etc.
	 *
	 * @return CompanyResult|EnrichmentError
	 */
	abstract public function enrichCompany( array $params );

	/**
	 * Declare settings fields this provider needs (shown on settings page).
	 *
	 * @return array<string,array<string,mixed>> Keyed by field name.
	 */
	abstract public function getSettingsFields(): array;

	/**
	 * Validate saved settings (e.g. test an API key).
	 *
	 * @param array<string,mixed> $settings The provider's saved settings.
	 *
	 * @return true|string True on success, error message string on failure.
	 */
	abstract public function validateSettings( array $settings );

	/**
	 * Map a provider-specific HTTP error to a normalized EnrichmentError.
	 *
	 * @param int                 $http_status   HTTP status code.
	 * @param array<string,mixed> $response_body Decoded response body.
	 *
	 * @return EnrichmentError
	 */
	abstract protected function mapError( int $http_status, array $response_body ): EnrichmentError;

	/**
	 * Make an HTTP GET request via WordPress HTTP API.
	 *
	 * @param string               $url     Full URL with query params.
	 * @param array<string,string> $headers Request headers.
	 *
	 * @return array{status:int,body:array<string,mixed>}|EnrichmentError
	 */
	protected function httpGet( string $url, array $headers = [] ) {
		$response = wp_remote_get(
			$url,
			[
				'headers' => $headers,
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new EnrichmentError(
				EnrichmentError::NETWORK_ERROR,
				$response->get_error_message(),
				null,
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			$body = [];
		}

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapError( $status, $body );
		}

		return [
			'status' => $status,
			'body'   => $body,
		];
	}
}
