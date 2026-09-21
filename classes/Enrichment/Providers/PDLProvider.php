<?php
/**
 * PDLProvider
 *
 * @package CustomCRM\Enrichment\Providers
 */

namespace CustomCRM\Enrichment\Providers;

use CustomCRM\Enrichment\CompanyResult;
use CustomCRM\Enrichment\EnrichmentError;
use CustomCRM\Enrichment\EnrichmentProvider;
use CustomCRM\Enrichment\EnrichmentSettings;
use CustomCRM\Enrichment\PersonResult;

/**
 * People Data Labs enrichment provider.
 *
 * @see https://docs.peopledatalabs.com/docs/person-enrichment-api
 * @see https://docs.peopledatalabs.com/docs/company-enrichment-api
 */
class PDLProvider extends EnrichmentProvider {

	private const PERSON_ENDPOINT  = 'https://api.peopledatalabs.com/v5/person/enrich';
	private const COMPANY_ENDPOINT = 'https://api.peopledatalabs.com/v5/company/enrich';

	/**
	 * {@inheritDoc}
	 */
	public function getSlug(): string {
		return 'pdl';
	}

	/**
	 * {@inheritDoc}
	 */
	public function getName(): string {
		return 'People Data Labs';
	}

	/**
	 * {@inheritDoc}
	 */
	public function getSettingsFields(): array {
		return [
			'api_key'  => [
				'label'       => __( 'API Key', 'fluent-crm-custom-features' ),
				'type'        => 'password',
				'placeholder' => __( 'Your People Data Labs API key', 'fluent-crm-custom-features' ),
				'help'        => __( 'Get your key at dashboard.peopledatalabs.com', 'fluent-crm-custom-features' ),
			],
			'api_tier' => [
				'label'   => __( 'Plan Tier', 'fluent-crm-custom-features' ),
				'type'    => 'select',
				'options' => [
					'free' => __( 'Free (100 req/min)', 'fluent-crm-custom-features' ),
					'paid' => __( 'Paid (1,000 req/min)', 'fluent-crm-custom-features' ),
				],
				'default' => 'free',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $settings Provider settings to validate.
	 */
	public function validateSettings( array $settings ) {
		$api_key = $settings['api_key'] ?? '';

		if ( '' === $api_key ) {
			return __( 'API key is required.', 'fluent-crm-custom-features' );
		}

		// Make a lightweight test call — search for a known test entity.
		$url = add_query_arg(
			[
				'email'          => 'sean@peopledatalabs.com',
				'min_likelihood' => 1,
				'data_include'   => 'full_name',
				'pretty'         => 'false',
			],
			self::PERSON_ENDPOINT
		);

		$result = $this->httpGet(
			$url,
			[ 'X-Api-Key' => $api_key ]
		);

		if ( $result instanceof EnrichmentError ) {
			if ( EnrichmentError::AUTH_FAILED === $result->code ) {
				return __( 'Invalid API key.', 'fluent-crm-custom-features' );
			}

			return $result->message;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $params Enrichment parameters (email, first_name, etc.).
	 */
	public function enrichPerson( array $params ) {
		$api_key = $this->getApiKey();

		if ( '' === $api_key ) {
			return new EnrichmentError(
				EnrichmentError::AUTH_FAILED,
				'PDL API key not configured.',
				null,
				false
			);
		}

		$query = array_filter(
			[
				'email'              => $params['email'] ?? '',
				'first_name'         => $params['first_name'] ?? '',
				'last_name'          => $params['last_name'] ?? '',
				'company'            => $params['company'] ?? '',
				'location'           => $params['location'] ?? '',
				'min_likelihood'     => $params['min_likelihood'] ?? 6,
				'include_if_matched' => 'true',
				'titlecase'          => 'true',
				'pretty'             => 'false',
			],
			static fn( $v ) => '' !== $v && null !== $v
		);

			$url    = add_query_arg( $query, self::PERSON_ENDPOINT );
			$result = $this->httpGet( $url, [ 'X-Api-Key' => $api_key ] );

			if ( $result instanceof EnrichmentError ) {
				return $result;
			}

			return $this->mapPersonResponse( $result['body'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $params Enrichment parameters (website, name, linkedin_url, etc.).
	 */
	public function enrichCompany( array $params ) {
		$api_key = $this->getApiKey();

		if ( '' === $api_key ) {
			return new EnrichmentError(
				EnrichmentError::AUTH_FAILED,
				'PDL API key not configured.',
				null,
				false
			);
		}

		$query = array_filter(
			[
				'website'            => $params['website'] ?? '',
				'name'               => $params['name'] ?? '',
				'profile'            => $params['linkedin_url'] ?? '',
				'min_likelihood'     => $params['min_likelihood'] ?? 2,
				'include_if_matched' => 'true',
				'titlecase'          => 'true',
				'pretty'             => 'false',
			],
			static fn( $v ) => '' !== $v && null !== $v
		);

			$url    = add_query_arg( $query, self::COMPANY_ENDPOINT );
			$result = $this->httpGet( $url, [ 'X-Api-Key' => $api_key ] );

			if ( $result instanceof EnrichmentError ) {
				return $result;
			}

			return $this->mapCompanyResponse( $result['body'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                 $http_status   HTTP status code from the provider.
	 * @param array<string,mixed> $response_body Decoded response body.
	 */
	protected function mapError( int $http_status, array $response_body ): EnrichmentError {
		$message = $response_body['error']['message'] ?? "HTTP {$http_status}";

		return match ( $http_status ) {
			400     => new EnrichmentError( EnrichmentError::INVALID_INPUT, $message, 400, false ),
			401     => new EnrichmentError( EnrichmentError::AUTH_FAILED, $message, 401, false ),
			402, 403 => new EnrichmentError( EnrichmentError::QUOTA_EXCEEDED, $message, $http_status, false ),
			404     => new EnrichmentError( EnrichmentError::NO_MATCH, 'No matching record found.', 404, false ),
			429     => new EnrichmentError( EnrichmentError::RATE_LIMITED, $message, 429, true ),
			default => new EnrichmentError(
				$http_status >= 500 ? EnrichmentError::PROVIDER_ERROR : EnrichmentError::PROVIDER_ERROR,
				$message,
				$http_status,
				$http_status >= 500
			),
		};
	}

	/**
	 * Map PDL person response to PersonResult DTO.
	 *
	 * @param array<string,mixed> $body Decoded PDL response body.
	 *
	 * @return PersonResult
	 */
	private function mapPersonResponse( array $body ): PersonResult {
		$data   = $body['data'] ?? $body;
		$result = new PersonResult();

		$result->first_name      = $data['first_name'] ?? null;
		$result->last_name       = $data['last_name'] ?? null;
		$result->phone           = $data['mobile_phone'] ?? ( $data['phone_numbers'][0] ?? null );
		$result->city            = $data['location_locality'] ?? null;
		$result->state           = $data['location_region'] ?? null;
		$result->country         = $data['location_country'] ?? null;
		$result->postal_code     = $data['location_postal_code'] ?? null;
		$result->address_line_1  = $data['location_street_address'] ?? null;
		$result->date_of_birth   = $data['birth_date'] ?? null;
		$result->linkedin_url    = $data['linkedin_url'] ?? null;
		$result->twitter_url     = $data['twitter_url'] ?? null;
		$result->facebook_url    = $data['facebook_url'] ?? null;
		$result->github_url      = $data['github_url'] ?? null;
		$result->job_title       = $data['job_title'] ?? null;
		$result->job_role        = $data['job_title_role'] ?? null;
		$result->industry        = $data['industry'] ?? ( $data['job_company_industry'] ?? null );
		$result->inferred_salary = $data['inferred_salary'] ?? null;
		$result->sex             = $data['sex'] ?? null;

		// Derive pronouns from sex if available.
		if ( $result->sex && null === $result->pronouns ) {
			$result->pronouns = match ( strtolower( $result->sex ) ) {
				'male'   => 'he/him',
				'female' => 'she/her',
				default  => null,
			};
		}

		// Job level: PDL returns an array, take the first.
		$levels            = $data['job_title_levels'] ?? [];
		$result->job_level = is_array( $levels ) && $levels ? $levels[0] : null;

		// Company data from person response.
		$result->job_company_name    = $data['job_company_name'] ?? null;
		$result->job_company_website = $data['job_company_website'] ?? null;

		// Geo coordinates.
		$geo = $data['location_geo'] ?? null;
		if ( $geo && is_string( $geo ) && str_contains( $geo, ',' ) ) {
			$parts             = explode( ',', $geo );
			$result->latitude  = (float) trim( $parts[0] );
			$result->longitude = (float) trim( $parts[1] );
		}

		$result->likelihood = $body['likelihood'] ?? null;

		return apply_filters( 'custom_crm/enrichment_person_result', $result, $body );
	}

	/**
	 * Map PDL company response to CompanyResult DTO.
	 *
	 * @param array<string,mixed> $body Decoded PDL response body.
	 *
	 * @return CompanyResult
	 */
	private function mapCompanyResponse( array $body ): CompanyResult {
		// PDL company responses put fields at root level (not nested under 'data').
		$data   = $body;
		$result = new CompanyResult();

		$result->name             = $data['display_name'] ?? ( $data['name'] ?? null );
		$result->industry         = $data['industry'] ?? null;
		$result->type             = $data['type'] ?? null;
		$result->website          = $data['website'] ?? null;
		$result->phone            = $data['phone'] ?? null;
		$result->linkedin_url     = $data['linkedin_url'] ?? null;
		$result->facebook_url     = $data['facebook_url'] ?? null;
		$result->twitter_url      = $data['twitter_url'] ?? null;
		$result->employees_number = $data['employee_count'] ?? null;
		$result->description      = $data['summary'] ?? null;
		$result->logo             = $data['logo'] ?? null;
		$result->ticker           = $data['ticker'] ?? null;
		$result->inferred_revenue = $data['inferred_revenue'] ?? null;
		$result->funding_raised   = isset( $data['total_funding_raised'] ) ? (int) $data['total_funding_raised'] : null;
		$result->funding_stage    = $data['latest_funding_stage'] ?? null;

		// Founded year -> date_of_start.
		if ( ! empty( $data['founded'] ) ) {
			$result->date_of_start = (string) $data['founded'];
		}

		// Location fields.
		$location = $data['location'] ?? [];
		if ( is_array( $location ) ) {
			$result->address_line_1 = $location['street_address'] ?? null;
			$result->city           = $location['locality'] ?? null;
			$result->state          = $location['region'] ?? null;
			$result->country        = $location['country'] ?? null;
			$result->postal_code    = $location['postal_code'] ?? null;
		}

		// Employee growth rate.
		$growth = $data['employee_growth_rate'] ?? [];
		if ( is_array( $growth ) && isset( $growth['12_month'] ) ) {
			$result->employee_growth_rate_12mo = (float) $growth['12_month'];
		}

		// NAICS codes.
		$result->naics_codes = $data['naics'] ?? null;

		$result->likelihood = $body['likelihood'] ?? null;

		return apply_filters( 'custom_crm/enrichment_company_result', $result, $body );
	}

	/**
	 * Get the decrypted API key for this provider.
	 *
	 * @return string
	 */
	private function getApiKey(): string {
		return EnrichmentSettings::getApiKey( $this->getSlug() );
	}
}
