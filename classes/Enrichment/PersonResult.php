<?php
/**
 * PersonResult DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized person enrichment result from any provider.
 *
 * All fields are nullable — providers populate what they can.
 */
class PersonResult {

	// --- Native Subscriber fields ---

	/**
	 * First name.
	 *
	 * @var string|null
	 */
	public ?string $first_name = null;

	/**
	 * Last name.
	 *
	 * @var string|null
	 */
	public ?string $last_name = null;

	/**
	 * Phone number.
	 *
	 * @var string|null
	 */
	public ?string $phone = null;

	/**
	 * City.
	 *
	 * @var string|null
	 */
	public ?string $city = null;

	/**
	 * State or region.
	 *
	 * @var string|null
	 */
	public ?string $state = null;

	/**
	 * Country.
	 *
	 * @var string|null
	 */
	public ?string $country = null;

	/**
	 * Postal code.
	 *
	 * @var string|null
	 */
	public ?string $postal_code = null;

	/**
	 * Street address line 1.
	 *
	 * @var string|null
	 */
	public ?string $address_line_1 = null;

	/**
	 * Date of birth (YYYY-MM-DD).
	 *
	 * @var string|null
	 */
	public ?string $date_of_birth = null;

	/**
	 * Timezone identifier.
	 *
	 * @var string|null
	 */
	public ?string $timezone = null;

	/**
	 * Latitude coordinate.
	 *
	 * @var float|null
	 */
	public ?float $latitude = null;

	/**
	 * Longitude coordinate.
	 *
	 * @var float|null
	 */
	public ?float $longitude = null;

	/**
	 * Avatar URL.
	 *
	 * @var string|null
	 */
	public ?string $avatar = null;

	// --- Custom fields ---

	/**
	 * Job title.
	 *
	 * @var string|null
	 */
	public ?string $job_title = null;

	/**
	 * Job role category.
	 *
	 * @var string|null
	 */
	public ?string $job_role = null;

	/**
	 * Job seniority level.
	 *
	 * @var string|null
	 */
	public ?string $job_level = null;

	/**
	 * Employer company name.
	 *
	 * @var string|null
	 */
	public ?string $job_company_name = null;

	/**
	 * Employer company website.
	 *
	 * @var string|null
	 */
	public ?string $job_company_website = null;

	/**
	 * LinkedIn profile URL.
	 *
	 * @var string|null
	 */
	public ?string $linkedin_url = null;

	/**
	 * Twitter/X profile URL.
	 *
	 * @var string|null
	 */
	public ?string $twitter_url = null;

	/**
	 * Facebook profile URL.
	 *
	 * @var string|null
	 */
	public ?string $facebook_url = null;

	/**
	 * GitHub profile URL.
	 *
	 * @var string|null
	 */
	public ?string $github_url = null;

	/**
	 * Sex (male/female).
	 *
	 * @var string|null
	 */
	public ?string $sex = null;

	/**
	 * Pronouns (e.g. he/him).
	 *
	 * @var string|null
	 */
	public ?string $pronouns = null;

	/**
	 * Inferred salary range.
	 *
	 * @var string|null
	 */
	public ?string $inferred_salary = null;

	/**
	 * Industry.
	 *
	 * @var string|null
	 */
	public ?string $industry = null;

	// --- Enrichment metadata ---

	/**
	 * Match likelihood score (0-10).
	 *
	 * @var int|null
	 */
	public ?int $likelihood = null;

	/**
	 * Return fields that map to native Subscriber columns.
	 *
	 * @return array<string,mixed> Only non-null values.
	 */
	public function toSubscriberFields(): array {
		$map = [
			'first_name'     => $this->first_name,
			'last_name'      => $this->last_name,
			'phone'          => $this->phone,
			'city'           => $this->city,
			'state'          => $this->state,
			'country'        => $this->country,
			'postal_code'    => $this->postal_code,
			'address_line_1' => $this->address_line_1,
			'date_of_birth'  => $this->date_of_birth,
			'timezone'       => $this->timezone,
			'latitude'       => $this->latitude,
			'longitude'      => $this->longitude,
			'avatar'         => $this->avatar,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}

	/**
	 * Return fields that map to FluentCRM custom fields.
	 *
	 * @return array<string,mixed> Only non-null values, keyed by custom field slug.
	 */
	public function toCustomFields(): array {
		$map = [
			'enrichment_job_title'       => $this->job_title,
			'enrichment_job_role'        => $this->job_role,
			'enrichment_job_level'       => $this->job_level,
			'enrichment_company_name'    => $this->job_company_name,
			'enrichment_linkedin_url'    => $this->linkedin_url,
			'enrichment_twitter_url'     => $this->twitter_url,
			'enrichment_facebook_url'    => $this->facebook_url,
			'enrichment_github_url'      => $this->github_url,
			'enrichment_sex'             => $this->sex,
			'enrichment_pronouns'        => $this->pronouns,
			'enrichment_inferred_salary' => $this->inferred_salary,
			'enrichment_industry'        => $this->industry,
			'enrichment_likelihood'      => $this->likelihood,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}
}
