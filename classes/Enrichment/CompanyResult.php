<?php
/**
 * CompanyResult DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized company enrichment result from any provider.
 *
 * All fields are nullable — providers populate what they can.
 */
class CompanyResult {

	// --- Native Company fields ---

	/**
	 * Company display name.
	 *
	 * @var string|null
	 */
	public ?string $name = null;

	/**
	 * Industry sector.
	 *
	 * @var string|null
	 */
	public ?string $industry = null;

	/**
	 * Company type (e.g. public, private).
	 *
	 * @var string|null
	 */
	public ?string $type = null;

	/**
	 * Company website URL.
	 *
	 * @var string|null
	 */
	public ?string $website = null;

	/**
	 * Company contact email.
	 *
	 * @var string|null
	 */
	public ?string $email = null;

	/**
	 * Company phone number.
	 *
	 * @var string|null
	 */
	public ?string $phone = null;

	/**
	 * Street address line 1.
	 *
	 * @var string|null
	 */
	public ?string $address_line_1 = null;

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
	 * Number of employees.
	 *
	 * @var int|null
	 */
	public ?int $employees_number = null;

	/**
	 * Company description.
	 *
	 * @var string|null
	 */
	public ?string $description = null;

	/**
	 * Logo URL.
	 *
	 * @var string|null
	 */
	public ?string $logo = null;

	/**
	 * LinkedIn company page URL.
	 *
	 * @var string|null
	 */
	public ?string $linkedin_url = null;

	/**
	 * Facebook company page URL.
	 *
	 * @var string|null
	 */
	public ?string $facebook_url = null;

	/**
	 * Twitter/X company profile URL.
	 *
	 * @var string|null
	 */
	public ?string $twitter_url = null;

	/**
	 * Founded year or date.
	 *
	 * @var string|null
	 */
	public ?string $date_of_start = null;

	// --- Extra metadata (stored in Company meta JSON) ---

	/**
	 * Total funding raised (USD).
	 *
	 * @var int|null
	 */
	public ?int $funding_raised = null;

	/**
	 * Latest funding stage (e.g. Series B).
	 *
	 * @var string|null
	 */
	public ?string $funding_stage = null;

	/**
	 * Inferred annual revenue range.
	 *
	 * @var string|null
	 */
	public ?string $inferred_revenue = null;

	/**
	 * Employee growth rate over the past 12 months.
	 *
	 * @var float|null
	 */
	public ?float $employee_growth_rate_12mo = null;

	/**
	 * Stock ticker symbol.
	 *
	 * @var string|null
	 */
	public ?string $ticker = null;

	/**
	 * NAICS industry codes.
	 *
	 * @var array<mixed>|null
	 */
	public ?array $naics_codes = null;

	// --- Enrichment metadata ---

	/**
	 * Match likelihood score (0-10).
	 *
	 * @var int|null
	 */
	public ?int $likelihood = null;

	/**
	 * Return fields that map to native Company columns.
	 *
	 * @return array<string,mixed> Only non-null values.
	 */
	public function toCompanyFields(): array {
		$map = [
			'name'             => $this->name,
			'industry'         => $this->industry,
			'type'             => $this->type,
			'website'          => $this->website,
			'email'            => $this->email,
			'phone'            => $this->phone,
			'address_line_1'   => $this->address_line_1,
			'city'             => $this->city,
			'state'            => $this->state,
			'country'          => $this->country,
			'postal_code'      => $this->postal_code,
			'employees_number' => $this->employees_number,
			'description'      => $this->description,
			'logo'             => $this->logo,
			'linkedin_url'     => $this->linkedin_url,
			'facebook_url'     => $this->facebook_url,
			'twitter_url'      => $this->twitter_url,
			'date_of_start'    => $this->date_of_start,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}

	/**
	 * Return extra fields for the Company meta JSON column.
	 *
	 * @return array<string,mixed> Only non-null values.
	 */
	public function toCompanyMeta(): array {
		$map = [
			'funding_raised'            => $this->funding_raised,
			'funding_stage'             => $this->funding_stage,
			'inferred_revenue'          => $this->inferred_revenue,
			'employee_growth_rate_12mo' => $this->employee_growth_rate_12mo,
			'ticker'                    => $this->ticker,
			'naics_codes'               => $this->naics_codes,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}
}
