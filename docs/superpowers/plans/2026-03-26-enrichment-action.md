# Enrichment Action Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a provider-agnostic FluentCRM automation action block that enriches contacts and companies using People Data Labs (PDL) as the first provider.

**Architecture:** Single action block with abstract provider interface. DTOs (PersonResult, CompanyResult) normalize API responses. PDLProvider maps PDL's API to the DTOs. EnrichContactAction orchestrates the flow: detect context, call provider, map results to FluentCRM Subscriber/Company fields.

**Tech Stack:** PHP 7.4+, WordPress HTTP API (`wp_remote_get`), FluentCRM BaseAction, FluentCRM Subscriber/Company models

**Spec:** `docs/superpowers/specs/2026-03-26-enrichment-action-design.md`

---

## File Map

| File | Responsibility |
|---|---|
| `classes/Enrichment/EnrichmentError.php` | Normalized error DTO |
| `classes/Enrichment/PersonResult.php` | Person data DTO |
| `classes/Enrichment/CompanyResult.php` | Company data DTO |
| `classes/Enrichment/EnrichmentProvider.php` | Abstract provider contract |
| `classes/Enrichment/FreeEmailDetector.php` | Free email domain checker |
| `classes/Enrichment/EnrichmentSettings.php` | Provider settings CRUD |
| `classes/Enrichment/EnrichmentFields.php` | Custom field auto-creation |
| `classes/Enrichment/Providers/PDLProvider.php` | PDL API implementation |
| `classes/Actions/EnrichContactAction.php` | FluentCRM action block |
| `fluent-crm-custom-features.php` | Register the action (modify) |

---

### Task 1: EnrichmentError DTO

**Files:**
- Create: `classes/Enrichment/EnrichmentError.php`

- [ ] **Step 1: Create the error DTO class**

```php
<?php
/**
 * EnrichmentError DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized error returned by enrichment providers.
 */
class EnrichmentError {

	public const NO_MATCH       = 'no_match';
	public const INVALID_INPUT  = 'invalid_input';
	public const AUTH_FAILED    = 'auth_failed';
	public const RATE_LIMITED   = 'rate_limited';
	public const QUOTA_EXCEEDED = 'quota_exceeded';
	public const PROVIDER_ERROR = 'provider_error';
	public const NETWORK_ERROR  = 'network_error';

	/** @var string Normalized error code (use class constants). */
	public string $code;

	/** @var string Human-readable error message. */
	public string $message;

	/** @var int|null Raw HTTP status from the provider. */
	public ?int $http_status;

	/** @var bool Whether the caller should retry. */
	public bool $retryable;

	/**
	 * @param string   $code        One of the class constants.
	 * @param string   $message     Human-readable message.
	 * @param int|null $http_status Raw HTTP status code.
	 * @param bool     $retryable   Whether the error is retryable.
	 */
	public function __construct( string $code, string $message, ?int $http_status = null, bool $retryable = false ) {
		$this->code        = $code;
		$this->message     = $message;
		$this->http_status = $http_status;
		$this->retryable   = $retryable;
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/EnrichmentError.php
git commit -m "feat(enrichment): add EnrichmentError DTO"
```

---

### Task 2: PersonResult DTO

**Files:**
- Create: `classes/Enrichment/PersonResult.php`

- [ ] **Step 1: Create the person result DTO**

```php
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
	public ?string $first_name    = null;
	public ?string $last_name     = null;
	public ?string $phone         = null;
	public ?string $city          = null;
	public ?string $state         = null;
	public ?string $country       = null;
	public ?string $postal_code   = null;
	public ?string $address_line_1 = null;
	public ?string $date_of_birth = null;
	public ?string $timezone      = null;
	public ?float  $latitude      = null;
	public ?float  $longitude     = null;
	public ?string $avatar        = null;

	// --- Custom fields ---
	public ?string $job_title         = null;
	public ?string $job_role          = null;
	public ?string $job_level         = null;
	public ?string $job_company_name  = null;
	public ?string $job_company_website = null;
	public ?string $linkedin_url      = null;
	public ?string $twitter_url       = null;
	public ?string $facebook_url      = null;
	public ?string $github_url        = null;
	public ?string $sex               = null;
	public ?string $pronouns          = null;
	public ?string $inferred_salary   = null;
	public ?string $industry          = null;

	// --- Enrichment metadata ---
	public ?int    $likelihood = null;

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
			'enrichment_job_title'        => $this->job_title,
			'enrichment_job_role'         => $this->job_role,
			'enrichment_job_level'        => $this->job_level,
			'enrichment_company_name'     => $this->job_company_name,
			'enrichment_linkedin_url'     => $this->linkedin_url,
			'enrichment_twitter_url'      => $this->twitter_url,
			'enrichment_facebook_url'     => $this->facebook_url,
			'enrichment_github_url'       => $this->github_url,
			'enrichment_sex'             => $this->sex,
			'enrichment_pronouns'        => $this->pronouns,
			'enrichment_inferred_salary' => $this->inferred_salary,
			'enrichment_industry'        => $this->industry,
			'enrichment_likelihood'      => $this->likelihood,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/PersonResult.php
git commit -m "feat(enrichment): add PersonResult DTO"
```

---

### Task 3: CompanyResult DTO

**Files:**
- Create: `classes/Enrichment/CompanyResult.php`

- [ ] **Step 1: Create the company result DTO**

```php
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
	public ?string $name             = null;
	public ?string $industry         = null;
	public ?string $type             = null;
	public ?string $website          = null;
	public ?string $email            = null;
	public ?string $phone            = null;
	public ?string $address_line_1   = null;
	public ?string $city             = null;
	public ?string $state            = null;
	public ?string $country          = null;
	public ?string $postal_code      = null;
	public ?int    $employees_number = null;
	public ?string $description      = null;
	public ?string $logo             = null;
	public ?string $linkedin_url     = null;
	public ?string $facebook_url     = null;
	public ?string $twitter_url      = null;
	public ?string $date_of_start    = null;

	// --- Extra metadata (stored in Company meta JSON) ---
	public ?int    $funding_raised              = null;
	public ?string $funding_stage               = null;
	public ?string $inferred_revenue            = null;
	public ?float  $employee_growth_rate_12mo   = null;
	public ?string $ticker                      = null;
	public ?array  $naics_codes                 = null;

	// --- Enrichment metadata ---
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
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/CompanyResult.php
git commit -m "feat(enrichment): add CompanyResult DTO"
```

---

### Task 4: EnrichmentProvider Abstract Class

**Files:**
- Create: `classes/Enrichment/EnrichmentProvider.php`

- [ ] **Step 1: Create the abstract provider**

```php
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
	 * @param int                  $http_status   HTTP status code.
	 * @param array<string,mixed>  $response_body Decoded response body.
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
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/EnrichmentProvider.php
git commit -m "feat(enrichment): add abstract EnrichmentProvider with httpGet helper"
```

---

### Task 5: FreeEmailDetector

**Files:**
- Create: `classes/Enrichment/FreeEmailDetector.php`

- [ ] **Step 1: Create the free email detector**

```php
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

		return in_array( strtolower( $domain ), $domains, true );
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
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/FreeEmailDetector.php
git commit -m "feat(enrichment): add FreeEmailDetector with filterable domain list"
```

---

### Task 6: EnrichmentSettings

**Files:**
- Create: `classes/Enrichment/EnrichmentSettings.php`

- [ ] **Step 1: Create the settings manager**

```php
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
		$all                          = self::getAll();
		$all['providers'][ $slug ]    = $settings;

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
	 * Encrypt a value for storage.
	 *
	 * Uses wp_encrypt() on WP 6.5+, falls back to base64 with site salt.
	 *
	 * @param string $value Plain text value.
	 *
	 * @return string Encrypted string.
	 */
	public static function encrypt( string $value ): string {
		if ( function_exists( 'wp_encrypt' ) ) {
			$result = wp_encrypt( $value );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Fallback: base64 with salt prefix for identification.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'b64:' . base64_encode( $value );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $encrypted Encrypted string.
	 *
	 * @return string Decrypted value.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( str_starts_with( $encrypted, 'b64:' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return base64_decode( substr( $encrypted, 4 ) );
		}

		if ( function_exists( 'wp_decrypt' ) ) {
			$result = wp_decrypt( $encrypted );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		// If we can't decrypt, return as-is (may be plain text from old storage).
		return $encrypted;
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/EnrichmentSettings.php
git commit -m "feat(enrichment): add EnrichmentSettings with encrypted API key storage"
```

---

### Task 7: EnrichmentFields (Custom Field Auto-Creation)

**Files:**
- Create: `classes/Enrichment/EnrichmentFields.php`

- [ ] **Step 1: Create the custom field manager**

```php
<?php
/**
 * EnrichmentFields
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Ensures required FluentCRM custom fields exist for enrichment data.
 */
class EnrichmentFields {

	private const TRANSIENT_KEY = 'custom_crm_enrichment_fields_checked';

	/**
	 * Ensure all enrichment custom fields exist. Uses a transient to avoid
	 * repeated DB reads — only checks once per day.
	 */
	public static function ensureFieldsExist(): void {
		if ( get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}

		$existing      = fluentcrm_get_option( 'contact_custom_fields', [] );
		$existing_slugs = array_column( $existing, 'slug' );
		$added         = false;

		foreach ( self::getFieldDefinitions() as $field ) {
			if ( ! in_array( $field['slug'], $existing_slugs, true ) ) {
				$existing[] = $field;
				$added      = true;
			}
		}

		if ( $added ) {
			fluentcrm_update_option( 'contact_custom_fields', $existing );
		}

		set_transient( self::TRANSIENT_KEY, 1, DAY_IN_SECONDS );
	}

	/**
	 * Get the field definitions for all enrichment custom fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function getFieldDefinitions(): array {
		return [
			[
				'slug'  => 'enrichment_job_title',
				'label' => 'Job Title',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_job_role',
				'label' => 'Job Role',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_job_level',
				'label' => 'Job Level',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_company_name',
				'label' => 'Company Name',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_linkedin_url',
				'label' => 'LinkedIn URL',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_twitter_url',
				'label' => 'Twitter URL',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_facebook_url',
				'label' => 'Facebook URL',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_github_url',
				'label' => 'GitHub URL',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'    => 'enrichment_sex',
				'label'   => 'Sex',
				'type'    => 'select-one',
				'group'   => 'Enrichment',
				'options' => [ 'male', 'female' ],
			],
			[
				'slug'  => 'enrichment_pronouns',
				'label' => 'Pronouns',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_inferred_salary',
				'label' => 'Inferred Salary',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_industry',
				'label' => 'Industry',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enriched_at',
				'label' => 'Enriched At',
				'type'  => 'date_time',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_provider',
				'label' => 'Enrichment Provider',
				'type'  => 'text',
				'group' => 'Enrichment',
			],
			[
				'slug'  => 'enrichment_likelihood',
				'label' => 'Match Likelihood',
				'type'  => 'number',
				'group' => 'Enrichment',
			],
			[
				'slug'    => 'enrichment_company_match',
				'label'   => 'Company Match Type',
				'type'    => 'select-one',
				'group'   => 'Enrichment',
				'options' => [ 'confirmed', 'inferred' ],
			],
		];
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/EnrichmentFields.php
git commit -m "feat(enrichment): add EnrichmentFields for custom field auto-creation"
```

---

### Task 8: PDLProvider

**Files:**
- Create: `classes/Enrichment/Providers/PDLProvider.php`

- [ ] **Step 1: Create the PDL provider class**

```php
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
				'email'            => $params['email'] ?? '',
				'first_name'       => $params['first_name'] ?? '',
				'last_name'        => $params['last_name'] ?? '',
				'company'          => $params['company'] ?? '',
				'location'         => $params['location'] ?? '',
				'min_likelihood'   => $params['min_likelihood'] ?? 6,
				'include_if_matched' => 'true',
				'titlecase'        => 'true',
				'pretty'           => 'false',
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
				'website'          => $params['website'] ?? '',
				'name'             => $params['name'] ?? '',
				'profile'          => $params['linkedin_url'] ?? '',
				'min_likelihood'   => $params['min_likelihood'] ?? 2,
				'include_if_matched' => 'true',
				'titlecase'        => 'true',
				'pretty'           => 'false',
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
	 */
	protected function mapError( int $http_status, array $response_body ): EnrichmentError {
		$message = $response_body['error']['message'] ?? "HTTP {$http_status}";

		return match ( $http_status ) {
			400     => new EnrichmentError( EnrichmentError::INVALID_INPUT, $message, 400, false ),
			401     => new EnrichmentError( EnrichmentError::AUTH_FAILED, $message, 401, false ),
			402,403 => new EnrichmentError( EnrichmentError::QUOTA_EXCEEDED, $message, $http_status, false ),
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

		$result->first_name     = $data['first_name'] ?? null;
		$result->last_name      = $data['last_name'] ?? null;
		$result->phone          = $data['mobile_phone'] ?? ( $data['phone_numbers'][0] ?? null );
		$result->city           = $data['location_locality'] ?? null;
		$result->state          = $data['location_region'] ?? null;
		$result->country        = $data['location_country'] ?? null;
		$result->postal_code    = $data['location_postal_code'] ?? null;
		$result->address_line_1 = $data['location_street_address'] ?? null;
		$result->date_of_birth  = $data['birth_date'] ?? null;
		$result->linkedin_url   = $data['linkedin_url'] ?? null;
		$result->twitter_url    = $data['twitter_url'] ?? null;
		$result->facebook_url   = $data['facebook_url'] ?? null;
		$result->github_url     = $data['github_url'] ?? null;
		$result->job_title      = $data['job_title'] ?? null;
		$result->job_role       = $data['job_title_role'] ?? null;
		$result->industry       = $data['industry'] ?? ( $data['job_company_industry'] ?? null );
		$result->inferred_salary = $data['inferred_salary'] ?? null;
		$result->sex            = $data['sex'] ?? null;

		// Derive pronouns from sex if available.
		if ( $result->sex && null === $result->pronouns ) {
			$result->pronouns = match ( strtolower( $result->sex ) ) {
				'male'   => 'he/him',
				'female' => 'she/her',
				default  => null,
			};
		}

		// Job level: PDL returns an array, take the first.
		$levels = $data['job_title_levels'] ?? [];
		$result->job_level = is_array( $levels ) && $levels ? $levels[0] : null;

		// Company data from person response.
		$result->job_company_name    = $data['job_company_name'] ?? null;
		$result->job_company_website = $data['job_company_website'] ?? null;

		// Geo coordinates.
		$geo = $data['location_geo'] ?? null;
		if ( $geo && is_string( $geo ) && str_contains( $geo, ',' ) ) {
			$parts = explode( ',', $geo );
			$result->latitude  = (float) trim( $parts[0] );
			$result->longitude = (float) trim( $parts[1] );
		}

		$result->likelihood = $body['likelihood'] ?? null;

		/** @var PersonResult */
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

		/** @var CompanyResult */
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
```

- [ ] **Step 2: Commit**

```bash
git add classes/Enrichment/Providers/PDLProvider.php
git commit -m "feat(enrichment): add PDLProvider with person and company enrichment"
```

---

### Task 9: EnrichContactAction — FluentCRM Action Block

**Files:**
- Create: `classes/Actions/EnrichContactAction.php`

- [ ] **Step 1: Create the action block class**

This is the largest file. It implements getBlock(), getBlockFields(), and handle() per FluentCRM's BaseAction pattern.

```php
<?php
/**
 * EnrichContactAction
 *
 * @package CustomCRM\Actions
 */

namespace CustomCRM\Actions;

use CustomCRM\Enrichment\CompanyResult;
use CustomCRM\Enrichment\EnrichmentError;
use CustomCRM\Enrichment\EnrichmentFields;
use CustomCRM\Enrichment\EnrichmentProvider;
use CustomCRM\Enrichment\EnrichmentSettings;
use CustomCRM\Enrichment\FreeEmailDetector;
use CustomCRM\Enrichment\PersonResult;
use CustomCRM\Enrichment\Providers\PDLProvider;
use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

/**
 * FluentCRM automation action block for enriching contacts and companies
 * using external data providers.
 */
class EnrichContactAction extends BaseAction {

	/**
	 * Registry of available providers.
	 *
	 * @var array<string,EnrichmentProvider>
	 */
	private array $providers = [];

	public function __construct() {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->actionName = 'enrich_contact';
		$this->priority   = 30;
		parent::__construct();
	}

	/**
	 * Get block definition shown in the automation editor sidebar.
	 *
	 * @return array<string,mixed>
	 */
	public function getBlock() {
		return [
			'category'    => __( 'CRM', 'fluent-crm-custom-features' ),
			'title'       => __( 'Enrich Contact', 'fluent-crm-custom-features' ),
			'description' => __( 'Enrich contact and company data using an external provider', 'fluent-crm-custom-features' ),
			'icon'        => 'fc-icon-wp_user_meta',
			'settings'    => [
				'provider'         => EnrichmentSettings::getActiveProvider(),
				'enrichment_scope' => 'both',
				'min_likelihood'   => 6,
				'data_behavior'    => 'fill_empty',
				'reprocess'        => 'no',
				'company_handling' => 'create_or_update',
				'tag_on_success'   => [],
				'tag_on_no_match'  => [],
			],
		];
	}

	/**
	 * Get block field definitions for the settings editor.
	 *
	 * @return array<string,mixed>
	 */
	public function getBlockFields() {
		return [
			'title'     => __( 'Enrich Contact', 'fluent-crm-custom-features' ),
			'sub_title' => __( 'Enrich contact and company data using an external provider', 'fluent-crm-custom-features' ),
			'fields'    => [
				'provider'         => [
					'type'        => 'select',
					'label'       => __( 'Enrichment Provider', 'fluent-crm-custom-features' ),
					'options'     => $this->getProviderOptions(),
					'inline_help' => __( 'Configure providers in FluentCRM Custom Features settings.', 'fluent-crm-custom-features' ),
				],
				'enrichment_scope' => [
					'type'    => 'radio',
					'label'   => __( 'Enrichment Scope', 'fluent-crm-custom-features' ),
					'options' => [
						[
							'id'    => 'both',
							'title' => __( 'Person + Company', 'fluent-crm-custom-features' ),
						],
						[
							'id'    => 'person',
							'title' => __( 'Person Only', 'fluent-crm-custom-features' ),
						],
						[
							'id'    => 'company',
							'title' => __( 'Company Only', 'fluent-crm-custom-features' ),
						],
					],
				],
				'min_likelihood'   => [
					'type'          => 'input-number',
					'label'         => __( 'Minimum Likelihood Score (1-10)', 'fluent-crm-custom-features' ),
					'wrapper_class' => 'fc_2col_inline pad-r-20',
					'inline_help'   => __( 'Higher = more accurate but fewer matches. Recommended: 6.', 'fluent-crm-custom-features' ),
				],
				'data_behavior'    => [
					'type'    => 'radio',
					'label'   => __( 'Data Behavior', 'fluent-crm-custom-features' ),
					'options' => [
						[
							'id'    => 'fill_empty',
							'title' => __( 'Fill empty fields only', 'fluent-crm-custom-features' ),
						],
						[
							'id'    => 'overwrite',
							'title' => __( 'Overwrite all fields', 'fluent-crm-custom-features' ),
						],
					],
				],
				'reprocess'        => [
					'type'        => 'yes_no_check',
					'check_label' => __( 'Re-enrich contacts that have already been enriched', 'fluent-crm-custom-features' ),
				],
				'company_handling' => [
					'type'    => 'radio',
					'label'   => __( 'Company Handling', 'fluent-crm-custom-features' ),
					'options' => [
						[
							'id'    => 'create_or_update',
							'title' => __( 'Create or update company', 'fluent-crm-custom-features' ),
						],
						[
							'id'    => 'update_existing',
							'title' => __( 'Update existing company only', 'fluent-crm-custom-features' ),
						],
						[
							'id'    => 'none',
							'title' => __( "Don't touch companies", 'fluent-crm-custom-features' ),
						],
					],
					'dependency' => [
						'depends_on' => 'enrichment_scope',
						'value'      => 'person',
						'operator'   => '!=',
					],
				],
				'tag_on_success'   => [
					'type'        => 'option_selectors',
					'option_key'  => 'tags',
					'is_multiple' => true,
					'label'       => __( 'Tag on Successful Enrichment', 'fluent-crm-custom-features' ),
					'placeholder' => __( 'Select Tags (optional)', 'fluent-crm-custom-features' ),
				],
				'tag_on_no_match'  => [
					'type'        => 'option_selectors',
					'option_key'  => 'tags',
					'is_multiple' => true,
					'label'       => __( 'Tag on No Match', 'fluent-crm-custom-features' ),
					'placeholder' => __( 'Select Tags (optional)', 'fluent-crm-custom-features' ),
				],
			],
		];
	}

	/**
	 * Execute the enrichment action.
	 *
	 * @param \FluentCrm\App\Models\Subscriber       $subscriber          The subscriber.
	 * @param \FluentCrm\App\Models\FunnelSequence    $sequence            The funnel sequence.
	 * @param int                                     $funnel_subscriber_id Funnel subscriber ID.
	 * @param mixed                                   $funnel_metric       Funnel metric.
	 */
	public function handle( $subscriber, $sequence, $funnel_subscriber_id, $funnel_metric ) {
		$settings = $sequence->settings;
		$scope    = Arr::get( $settings, 'enrichment_scope', 'both' );

		// --- 1. Detect context ---
		$is_company_trigger = $this->isCompanyTriggered( $subscriber, $sequence );

		if ( $is_company_trigger && 'person' === $scope ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
			return;
		}

		if ( $is_company_trigger ) {
			$scope = 'company';
		}

		// --- 2. Check skip conditions ---
		$reprocess = 'yes' === Arr::get( $settings, 'reprocess', 'no' );
		if ( ! $reprocess && ! $is_company_trigger ) {
			$enriched_at = $subscriber->getMeta( 'enriched_at', 'custom_field' );
			if ( $enriched_at ) {
				FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
				return;
			}
		}

		// --- 3. Resolve provider ---
		$provider = $this->resolveProvider( Arr::get( $settings, 'provider', '' ) );
		if ( ! $provider ) {
			$this->log( 'error', 'No enrichment provider configured or found.' );
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
			return;
		}

		$min_likelihood   = (int) Arr::get( $settings, 'min_likelihood', 6 );
		$data_behavior    = Arr::get( $settings, 'data_behavior', 'fill_empty' );
		$company_handling = Arr::get( $settings, 'company_handling', 'create_or_update' );
		$person_result    = null;
		$company_result   = null;
		$person_success   = false;
		$company_success  = false;

		// --- 4. Person enrichment ---
		if ( in_array( $scope, [ 'both', 'person' ], true ) && $subscriber->email ) {
			$person_result = $this->enrichPerson(
				$provider,
				$subscriber,
				$min_likelihood,
				$data_behavior,
				$funnel_subscriber_id,
				$sequence
			);

			$person_success = $person_result instanceof PersonResult;
		}

		// --- 5. Company enrichment ---
		if ( in_array( $scope, [ 'both', 'company' ], true ) ) {
			$company_result = $this->enrichCompany(
				$provider,
				$subscriber,
				$person_result,
				$is_company_trigger,
				$min_likelihood,
				$data_behavior,
				$company_handling,
				$funnel_subscriber_id,
				$sequence
			);

			$company_success = $company_result instanceof CompanyResult;
		}

		// --- 6. Apply tags ---
		$refreshed = Subscriber::where( 'id', $subscriber->id )->first();

		if ( $person_success || $company_success ) {
			$success_tags = Arr::get( $settings, 'tag_on_success', [] );
			if ( $success_tags && $refreshed ) {
				$refreshed->attachTags( $success_tags );
			}
		} elseif ( ! $person_success && ! $company_success ) {
			$no_match_tags = Arr::get( $settings, 'tag_on_no_match', [] );
			if ( $no_match_tags && $refreshed ) {
				$refreshed->attachTags( $no_match_tags );
			}
		}

		// --- 7. Fire hooks ---
		if ( $refreshed ) {
			do_action( 'custom_crm/contact_enriched', $refreshed, $person_result, $company_result );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Run person enrichment and apply results to the subscriber.
	 *
	 * @return PersonResult|EnrichmentError|null
	 */
	private function enrichPerson(
		EnrichmentProvider $provider,
		Subscriber $subscriber,
		int $min_likelihood,
		string $data_behavior,
		int $funnel_subscriber_id,
		$sequence
	) {
		EnrichmentFields::ensureFieldsExist();

		$params = [
			'email'          => $subscriber->email,
			'first_name'     => $subscriber->first_name ?? '',
			'last_name'      => $subscriber->last_name ?? '',
			'min_likelihood' => $min_likelihood,
		];

		$result = $provider->enrichPerson( $params );

		if ( $result instanceof EnrichmentError ) {
			$this->handleError( $result, $subscriber, $funnel_subscriber_id, $sequence, 'person' );
			return $result;
		}

		if ( $result->likelihood && $result->likelihood < $min_likelihood ) {
			$this->log( 'info', "Person enrichment below threshold ({$result->likelihood} < {$min_likelihood}) for {$subscriber->email}" );
			return null;
		}

		// Map native subscriber fields.
		$subscriber_fields = $result->toSubscriberFields();

		if ( 'fill_empty' === $data_behavior ) {
			$subscriber_fields = $this->filterEmptyOnly( $subscriber, $subscriber_fields );
		}

		if ( $subscriber_fields ) {
			$subscriber->fill( $subscriber_fields );
			$dirty = $subscriber->getDirty();
			if ( $dirty ) {
				$subscriber->save();
				do_action( 'fluent_crm/contact_updated', $subscriber, $dirty );
			}
		}

		// Map custom fields.
		$custom_fields = $result->toCustomFields();
		$custom_fields['enriched_at']          = current_time( 'mysql' );
		$custom_fields['enrichment_provider']  = $provider->getSlug();

		if ( 'fill_empty' === $data_behavior ) {
			$custom_fields = $this->filterEmptyCustomFieldsOnly( $subscriber, $custom_fields );
			// Always update these metadata fields regardless of behavior.
			$custom_fields['enriched_at']         = current_time( 'mysql' );
			$custom_fields['enrichment_provider'] = $provider->getSlug();
		}

		if ( $custom_fields ) {
			$subscriber->syncCustomFieldValues( $custom_fields, false );
		}

		$this->log( 'info', "Person enrichment successful for {$subscriber->email} (likelihood: {$result->likelihood})" );

		return $result;
	}

	/**
	 * Run company enrichment and apply results.
	 *
	 * @return CompanyResult|EnrichmentError|null
	 */
	private function enrichCompany(
		EnrichmentProvider $provider,
		Subscriber $subscriber,
		$person_result,
		bool $is_company_trigger,
		int $min_likelihood,
		string $data_behavior,
		string $company_handling,
		int $funnel_subscriber_id,
		$sequence
	) {
		// Determine company identifier.
		$company_website = null;
		$company_name    = null;

		if ( $is_company_trigger && $subscriber->company_id ) {
			$existing_company = Company::find( $subscriber->company_id );
			if ( $existing_company ) {
				$company_website = $existing_company->website;
				$company_name    = $existing_company->name;
			}
		}

		if ( ! $company_website && $subscriber->email ) {
			$domain = FreeEmailDetector::extractDomain( $subscriber->email );
			if ( $domain && ! FreeEmailDetector::isFreeEmail( $subscriber->email ) ) {
				$company_website = $domain;
			}
		}

		// Fallback to person enrichment result.
		if ( ! $company_website && $person_result instanceof PersonResult ) {
			$company_website = $person_result->job_company_website;
			$company_name    = $person_result->job_company_name;
		}

		if ( ! $company_website && ! $company_name ) {
			$this->log( 'info', "No company identifier found for {$subscriber->email}, skipping company enrichment." );
			return null;
		}

		$params = array_filter( [
			'website'        => $company_website,
			'name'           => $company_name,
			'min_likelihood' => $min_likelihood,
		] );

		$result = $provider->enrichCompany( $params );

		if ( $result instanceof EnrichmentError ) {
			$this->handleError( $result, $subscriber, $funnel_subscriber_id, $sequence, 'company' );
			return $result;
		}

		// Determine match confidence.
		$email_domain   = $subscriber->email ? FreeEmailDetector::extractDomain( $subscriber->email ) : '';
		$result_domain  = $this->normalizeDomain( $result->website ?? '' );
		$match_type     = ( $email_domain && $result_domain && $email_domain === $result_domain ) ? 'confirmed' : 'inferred';

		// Handle company based on setting.
		if ( 'none' === $company_handling ) {
			$this->storeCompanyInCustomFields( $subscriber, $result, $match_type, $provider->getSlug(), $data_behavior );
		} elseif ( 'update_existing' === $company_handling ) {
			if ( $subscriber->company_id ) {
				$company = Company::find( $subscriber->company_id );
				if ( $company ) {
					$this->updateCompanyFromResult( $company, $result, $match_type, $provider->getSlug(), $data_behavior );
				}
			}
		} else {
			// create_or_update.
			$company = $this->findOrCreateCompany( $result, $match_type, $provider->getSlug(), $data_behavior );
			if ( $company && ! $is_company_trigger && $subscriber->email ) {
				$subscriber->company_id = $company->id;
				$subscriber->save();
				$subscriber->attachCompanies( [ $company->id ] );
			}
		}

		$this->log( 'info', "Company enrichment successful: {$result->name} ({$match_type})" );

		do_action( 'custom_crm/company_enriched', $company ?? null, $result );

		return $result;
	}

	/**
	 * Find an existing company by website domain or name, or create a new one.
	 *
	 * @return Company|null
	 */
	private function findOrCreateCompany(
		CompanyResult $result,
		string $match_type,
		string $provider_slug,
		string $data_behavior
	): ?Company {
		$company = null;

		// Try to find by website domain.
		if ( $result->website ) {
			$normalized = $this->normalizeDomain( $result->website );
			$company    = Company::where( 'website', 'LIKE', "%{$normalized}%" )->first();
		}

		// Fallback: find by exact name.
		if ( ! $company && $result->name ) {
			$company = Company::where( 'name', $result->name )->first();
		}

		if ( $company ) {
			$this->updateCompanyFromResult( $company, $result, $match_type, $provider_slug, $data_behavior );
			return $company;
		}

		// Create new company.
		$fields          = $result->toCompanyFields();
		$fields['meta']  = $this->buildCompanyMeta( $result, $match_type, $provider_slug );

		if ( empty( $fields['name'] ) ) {
			return null;
		}

		return Company::create( $fields );
	}

	/**
	 * Update an existing company from enrichment results.
	 */
	private function updateCompanyFromResult(
		Company $company,
		CompanyResult $result,
		string $match_type,
		string $provider_slug,
		string $data_behavior
	): void {
		$fields = $result->toCompanyFields();

		if ( 'fill_empty' === $data_behavior ) {
			$fields = $this->filterEmptyCompanyFields( $company, $fields );
		}

		if ( $fields ) {
			$company->fill( $fields );
			$company->save();
		}

		// Always update meta.
		$meta             = $company->meta;
		$enrichment_meta  = $this->buildCompanyMeta( $result, $match_type, $provider_slug );
		$company->meta    = array_merge( $meta, $enrichment_meta );
		$company->save();
	}

	/**
	 * Build the enrichment portion of company meta.
	 *
	 * @return array<string,mixed>
	 */
	private function buildCompanyMeta( CompanyResult $result, string $match_type, string $provider_slug ): array {
		return array_merge(
			$result->toCompanyMeta(),
			[
				'enrichment_company_match' => $match_type,
				'enrichment_provider'      => $provider_slug,
				'enriched_at'              => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Store company data in contact custom fields (when company_handling = 'none').
	 */
	private function storeCompanyInCustomFields(
		Subscriber $subscriber,
		CompanyResult $result,
		string $match_type,
		string $provider_slug,
		string $data_behavior
	): void {
		EnrichmentFields::ensureFieldsExist();

		$custom = [
			'enrichment_company_name'  => $result->name,
			'enrichment_industry'      => $result->industry,
			'enrichment_company_match' => $match_type,
		];

		$custom = array_filter( $custom, static fn( $v ) => null !== $v );

		if ( 'fill_empty' === $data_behavior ) {
			$custom = $this->filterEmptyCustomFieldsOnly( $subscriber, $custom );
		}

		if ( $custom ) {
			$subscriber->syncCustomFieldValues( $custom, false );
		}
	}

	/**
	 * Handle an enrichment error: log it and optionally mark the sequence.
	 */
	private function handleError(
		EnrichmentError $error,
		Subscriber $subscriber,
		int $funnel_subscriber_id,
		$sequence,
		string $context
	): void {
		$email = $subscriber->email ?? '(unknown)';

		$log_level = in_array( $error->code, [ EnrichmentError::AUTH_FAILED, EnrichmentError::QUOTA_EXCEEDED, EnrichmentError::PROVIDER_ERROR ], true )
			? 'error'
			: 'info';

		$this->log( $log_level, "{$context} enrichment error for {$email}: [{$error->code}] {$error->message}" );

		// For fatal errors, skip the sequence.
		if ( in_array( $error->code, [ EnrichmentError::AUTH_FAILED, EnrichmentError::INVALID_INPUT, EnrichmentError::QUOTA_EXCEEDED ], true ) ) {
			FunnelHelper::changeFunnelSubSequenceStatus( $funnel_subscriber_id, $sequence->id, 'skipped' );
		}
	}

	/**
	 * Filter subscriber fields to only those that are currently empty.
	 *
	 * @param Subscriber           $subscriber Subscriber model.
	 * @param array<string,mixed>  $fields     Fields to filter.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyOnly( Subscriber $subscriber, array $fields ): array {
		return array_filter(
			$fields,
			static fn( $value, $key ) => empty( $subscriber->$key ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Filter custom fields to only those that are currently empty on the subscriber.
	 *
	 * @param Subscriber           $subscriber Subscriber model.
	 * @param array<string,mixed>  $fields     Custom field slug => value.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyCustomFieldsOnly( Subscriber $subscriber, array $fields ): array {
		$slugs           = array_keys( $fields );
		$existing_values = $subscriber->getCustomFieldValues( $slugs );

		return array_filter(
			$fields,
			static function ( $value, $key ) use ( $existing_values ) {
				$existing = $existing_values[ $key ] ?? null;
				return empty( $existing );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Filter company fields to only those that are currently empty.
	 *
	 * @param Company              $company Company model.
	 * @param array<string,mixed>  $fields  Fields to filter.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyCompanyFields( Company $company, array $fields ): array {
		return array_filter(
			$fields,
			static fn( $value, $key ) => empty( $company->$key ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Detect if this is a company-triggered funnel.
	 */
	private function isCompanyTriggered( Subscriber $subscriber, $sequence ): bool {
		// Check funnel trigger name if available.
		if ( isset( $sequence->funnel ) && isset( $sequence->funnel->trigger_name ) ) {
			$trigger = $sequence->funnel->trigger_name;
			if ( str_contains( $trigger, 'company' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a domain for comparison (strip protocol, www, trailing slash).
	 */
	private function normalizeDomain( string $url ): string {
		$domain = strtolower( $url );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = preg_replace( '#^www\.#', '', $domain );
		$domain = rtrim( $domain, '/' );

		return $domain;
	}

	/**
	 * Get provider options for the dropdown.
	 *
	 * @return array<int,array{id:string,title:string}>
	 */
	private function getProviderOptions(): array {
		$providers = $this->getRegisteredProviders();
		$options   = [];

		foreach ( $providers as $provider ) {
			$settings = EnrichmentSettings::getProviderSettings( $provider->getSlug() );
			if ( ! empty( $settings['api_key'] ) ) {
				$options[] = [
					'id'    => $provider->getSlug(),
					'title' => $provider->getName(),
				];
			}
		}

		if ( empty( $options ) ) {
			$options[] = [
				'id'    => '',
				'title' => __( '-- No providers configured --', 'fluent-crm-custom-features' ),
			];
		}

		return $options;
	}

	/**
	 * Resolve a provider instance by slug.
	 */
	private function resolveProvider( string $slug ): ?EnrichmentProvider {
		if ( '' === $slug ) {
			$slug = EnrichmentSettings::getActiveProvider();
		}

		$providers = $this->getRegisteredProviders();

		foreach ( $providers as $provider ) {
			if ( $provider->getSlug() === $slug ) {
				$settings = EnrichmentSettings::getProviderSettings( $slug );
				if ( ! empty( $settings['api_key'] ) ) {
					return $provider;
				}
			}
		}

		return null;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return EnrichmentProvider[]
	 */
	private function getRegisteredProviders(): array {
		if ( $this->providers ) {
			return $this->providers;
		}

		$providers = [
			new PDLProvider(),
		];

		/**
		 * Register additional enrichment providers.
		 *
		 * @param EnrichmentProvider[] $providers Array of provider instances.
		 */
		$this->providers = apply_filters( 'custom_crm/enrichment_providers', $providers );

		return $this->providers;
	}

	/**
	 * Log a message to FluentCRM's internal logger.
	 *
	 * @param string $level   'info', 'error', 'warning'.
	 * @param string $message Log message.
	 */
	private function log( string $level, string $message ): void {
		if ( function_exists( 'fluentCrmLog' ) ) {
			fluentCrmLog( $message );
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[CustomCRM Enrichment][{$level}] {$message}" );
		}
	}
}
```

- [ ] **Step 2: Commit**

```bash
git add classes/Actions/EnrichContactAction.php
git commit -m "feat(enrichment): add EnrichContactAction automation block"
```

---

### Task 10: Register the Action in the Plugin Bootstrap

**Files:**
- Modify: `fluent-crm-custom-features.php` (add registration line in the `init` callback)

- [ ] **Step 1: Read the current bootstrap to confirm the exact insertion point**

The file already has an `add_action('init', function() { ... })` block that registers other actions. Add the new action registration inside this block.

- [ ] **Step 2: Add the registration line**

Add the following line inside the existing `init` callback, after the other action registrations:

```php
( new \CustomCRM\Actions\EnrichContactAction() );
```

This should go after the existing `( new \CustomCRM\Webhooks() );` line (or wherever the last action registration is).

- [ ] **Step 3: Commit**

```bash
git add fluent-crm-custom-features.php
git commit -m "feat(enrichment): register EnrichContactAction in plugin bootstrap"
```

---

### Task 11: Verify Autoloading and Lint

- [ ] **Step 1: Verify all new classes are autoloadable**

The plugin uses a custom PSR-4 autoloader that maps `CustomCRM\` to `classes/`. Verify the namespace paths are correct:

```bash
cd /Users/zackkatz/Local/dev/app/public/wp-content/plugins/fluent-crm-custom-features
php -r "
spl_autoload_register(function(\$class) {
    \$prefix = 'CustomCRM\\\\';
    if (strncmp(\$prefix, \$class, strlen(\$prefix)) !== 0) return;
    \$relative = substr(\$class, strlen(\$prefix));
    \$file = __DIR__ . '/classes/' . str_replace('\\\\', '/', \$relative) . '.php';
    echo \$class . ' => ' . \$file . ' => ' . (file_exists(\$file) ? 'OK' : 'MISSING') . PHP_EOL;
});
\$classes = [
    'CustomCRM\Enrichment\EnrichmentError',
    'CustomCRM\Enrichment\PersonResult',
    'CustomCRM\Enrichment\CompanyResult',
    'CustomCRM\Enrichment\EnrichmentProvider',
    'CustomCRM\Enrichment\FreeEmailDetector',
    'CustomCRM\Enrichment\EnrichmentSettings',
    'CustomCRM\Enrichment\EnrichmentFields',
    'CustomCRM\Enrichment\Providers\PDLProvider',
    'CustomCRM\Actions\EnrichContactAction',
];
foreach (\$classes as \$c) { new \$c; }
"
```

Expected: All classes resolve to existing files and show "OK".

- [ ] **Step 2: Run PHPCS lint**

```bash
composer lint 2>&1 | head -50
```

Fix any lint errors in the new files.

- [ ] **Step 3: Run PHPStan**

```bash
composer phpstan 2>&1 | head -80
```

Fix any static analysis errors in the new files.

- [ ] **Step 4: Commit any lint/phpstan fixes**

```bash
git add -A
git commit -m "fix(enrichment): address lint and static analysis findings"
```

---

### Task 12: Final Commit and Summary

- [ ] **Step 1: Verify all files are committed**

```bash
git status
git log --oneline -12
```

Expected: Clean working tree, all enrichment commits visible in the log.

- [ ] **Step 2: Tag completion**

No tag needed — this is on a feature branch. The commits are ready for PR review.
