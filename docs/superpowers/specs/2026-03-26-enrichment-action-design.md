# Enrich Contact Action Block — Design Spec

## Overview

A provider-agnostic FluentCRM automation action block that enriches contacts and companies using external data providers. The first provider implementation is People Data Labs (PDL). The action maps enrichment data to FluentCRM Subscriber fields, custom fields, and Company records.

## Goals

- Automatically enrich contacts with job title, company, social profiles, location, and demographic data
- Auto-create and link FluentCRM Companies from enrichment data or email domain
- Support both contact-triggered and company-triggered automations
- Design for swappable providers — PDL is the first, but the architecture supports Clearbit, Apollo, ZoomInfo, etc.
- Store enrichment metadata generically (no provider-specific prefixes in field names)

## Architecture: Approach A — Single Action Block

One action block handles both person and company enrichment. When scope includes both, it calls Person Enrichment first, then Company Enrichment using data from the person response (or email domain). Two sequential API calls in a single action step.

**Why this approach:**
- The common case (enrich both) is the default and easiest to configure
- Sequential API calls add <1s total — invisible in FluentCRM's async cron execution
- Company enrichment benefits from person enrichment data (job_company_* fields)
- Simpler automation flows — one block instead of two

## Action Block Settings

**Action Name:** `enrich_contact`
**Category:** CRM
**Title:** Enrich Contact

| Setting | Type | Default | Description |
|---|---|---|---|
| Provider | Dropdown | First configured | Which enrichment provider to use |
| Enrichment Scope | Radio | `both` | "Person + Company" / "Person Only" / "Company Only" |
| Min Likelihood | Number (1-10) | `6` | Minimum provider confidence score to accept a match |
| Data Behavior | Radio | `fill_empty` | "Fill empty fields only" / "Overwrite all fields" |
| Reprocess | Checkbox | unchecked | "Re-enrich contacts that have already been enriched" |
| Company Handling | Radio | `create_or_update` | "Create or update company" / "Update existing only" / "Don't touch companies" |
| Tag on Success | Tag selector | empty | Optional tag to apply after successful enrichment |
| Tag on No Match | Tag selector | empty | Optional tag to apply when provider returns no match |

### Trigger Context Behavior

The action adapts based on the automation trigger:

| Trigger Context | Scope = "Person + Company" | Scope = "Person Only" | Scope = "Company Only" |
|---|---|---|---|
| Contact-triggered | Enrich person, then company | Enrich person only | Enrich company from email domain |
| Company-triggered | Degrades to Company Only | Marks sequence as skipped | Enrich company from name/website |

**Context detection:** FluentCRM's `handle()` always receives a `$subscriber`. For company-triggered funnels, the subscriber may be a placeholder or the company owner. The action detects company context by checking: (1) whether `$subscriber->company_id` is set and `$subscriber->email` is empty or a placeholder, or (2) by inspecting the funnel trigger type via `$sequence->funnel->trigger_name`. If the trigger is company-based (e.g., `company_created`), the action loads the Company model from the subscriber's `company_id` and uses it as the primary enrichment target. This detection logic should be verified against FluentCRM's actual company-trigger implementation during development.

### Company Match Confidence

When a company is identified and linked to a contact:

- **Confirmed**: Email domain matches the company's website domain (`jane@acme.com` -> company website is `acme.com`)
- **Inferred**: Free email provider (`jane@gmail.com`) but enrichment says she works at Acme Corp. Company is created/linked but marked as inferred.

Stored in the `enrichment_company_match` custom field on the contact and in the company's `meta` JSON column.

## Provider Abstraction

### Interface: EnrichmentProvider

```php
abstract class EnrichmentProvider {
    abstract public function getSlug(): string;
    abstract public function getName(): string;
    abstract public function enrichPerson(array $params): PersonResult|EnrichmentError;
    abstract public function enrichCompany(array $params): CompanyResult|EnrichmentError;
    abstract public function getSettingsFields(): array;
    abstract public function validateSettings(array $settings): bool|string;
    abstract protected function mapError(int $httpStatus, array $responseBody): EnrichmentError;
}
```

### Error Handling

All providers map their HTTP responses to a normalized `EnrichmentError`:

```php
class EnrichmentError {
    public string $code;       // Normalized error code
    public string $message;    // Human-readable message
    public ?int $httpStatus;   // Raw HTTP status from provider
    public bool $retryable;    // Whether the action should retry
}
```

**Normalized error codes:**

| Code | Meaning | PDL HTTP Status | Retryable |
|---|---|---|---|
| `no_match` | Entity not found | 404 | No |
| `invalid_input` | Bad/insufficient input params | 400 | No |
| `auth_failed` | Bad API key | 401 | No |
| `rate_limited` | Too many requests | 429 | Yes |
| `quota_exceeded` | Billing/credit limit hit | 402, 403 | No |
| `provider_error` | Upstream API failure | 5xx | Yes |
| `network_error` | Connection timeout, DNS failure | N/A | Yes |

**Action behavior per error:**

| Error code | Action behavior |
|---|---|
| `no_match` | Apply no-match tag, continue to next step |
| `invalid_input` | Log warning, mark sequence as skipped |
| `auth_failed` | Log error, mark skipped permanently |
| `rate_limited` | Log warning, mark skipped (FluentCRM retries on next cron) |
| `quota_exceeded` | Log error, mark skipped |
| `provider_error` | Log error, mark skipped (retryable on next run) |
| `network_error` | Log warning, mark skipped (retryable) |

The action never retries inline. For retryable errors, FluentCRM's cron-based retry handles re-execution. For non-retryable errors, the contact is marked skipped with a logged note.

All API calls and errors are logged via `fluentcrm_log()`.

### PDL Provider Implementation

**Person Enrichment:**
- Endpoint: `GET https://api.peopledatalabs.com/v5/person/enrich`
- Input: subscriber email (primary), plus first_name, last_name, company, location if available
- Min input: email alone is sufficient
- Returns 100+ fields including `job_company_*` data

**Company Enrichment:**
- Endpoint: `GET https://api.peopledatalabs.com/v5/company/enrich`
- Input: website (primary), name (fallback), linkedin_url (fallback)
- Min input: one of name, website, ticker, or profile

**Billing:** PDL charges per match (200 response only). 404s are free.

**Rate Limits:**
- Free tier: 100/min (person), 10/min (company)
- Paid tier: 1,000/min both

## Field Mapping

### PersonResult DTO -> Subscriber (native fields)

| PersonResult field | Subscriber field | PDL source field |
|---|---|---|
| `first_name` | `first_name` | `first_name` |
| `last_name` | `last_name` | `last_name` |
| `phone` | `phone` | `mobile_phone` or `phone_numbers[0]` |
| `city` | `city` | `location_locality` |
| `state` | `state` | `location_region` |
| `country` | `country` | `location_country` |
| `postal_code` | `postal_code` | `location_postal_code` |
| `address_line_1` | `address_line_1` | `location_street_address` |
| `date_of_birth` | `date_of_birth` | `birth_date` |
| `timezone` | `timezone` | Inferred from location |
| `latitude` | `latitude` | From `location_geo` |
| `longitude` | `longitude` | From `location_geo` |
| `avatar` | `avatar` | Profile photo URL if available |

### PersonResult DTO -> Custom Fields

| PersonResult field | Custom field slug | Type |
|---|---|---|
| `job_title` | `enrichment_job_title` | text |
| `job_role` | `enrichment_job_role` | text |
| `job_level` | `enrichment_job_level` | text |
| `job_company_name` | `enrichment_company_name` | text |
| `linkedin_url` | `enrichment_linkedin_url` | text |
| `twitter_url` | `enrichment_twitter_url` | text |
| `facebook_url` | `enrichment_facebook_url` | text |
| `github_url` | `enrichment_github_url` | text |
| `sex` | `enrichment_sex` | select-one |
| `pronouns` | `enrichment_pronouns` | text |
| `inferred_salary` | `enrichment_inferred_salary` | text |
| `industry` | `enrichment_industry` | text |
| `enriched_at` | `enriched_at` | date_time |
| `enrichment_provider` | `enrichment_provider` | text |
| `enrichment_likelihood` | `enrichment_likelihood` | number |
| `enrichment_company_match` | `enrichment_company_match` | select-one |

The `enrichment_pronouns` field can be auto-populated by the provider from `sex` data (he/him, she/her) or directly if the provider returns pronoun data. This mapping is a provider-level decision, customizable via the `custom_crm/enrichment_person_result` filter.

### CompanyResult DTO -> Company (native fields)

| CompanyResult field | Company field | PDL source field |
|---|---|---|
| `name` | `name` | `display_name` |
| `industry` | `industry` | `industry` |
| `type` | `type` | `type` (public/private/nonprofit) |
| `website` | `website` | `website` |
| `email` | `email` | If available |
| `phone` | `phone` | If available |
| `address_line_1` | `address_line_1` | `location.street_address` |
| `city` | `city` | `location.locality` |
| `state` | `state` | `location.region` |
| `country` | `country` | `location.country` |
| `postal_code` | `postal_code` | `location.postal_code` |
| `employees_number` | `employees_number` | `employee_count` |
| `description` | `description` | `summary` |
| `logo` | `logo` | If available |
| `linkedin_url` | `linkedin_url` | `linkedin_url` |
| `facebook_url` | `facebook_url` | `facebook_url` |
| `twitter_url` | `twitter_url` | `twitter_url` |
| `date_of_start` | `date_of_start` | `founded` (year) |

### CompanyResult DTO -> Company meta (JSON column)

| Field | PDL source |
|---|---|
| `enrichment_company_match` | `confirmed` or `inferred` |
| `enrichment_provider` | Provider slug (e.g., "pdl") |
| `enriched_at` | Timestamp |
| `funding_raised` | `total_funding_raised` |
| `funding_stage` | `latest_funding_stage` |
| `inferred_revenue` | `inferred_revenue` |
| `employee_growth_rate_12mo` | `employee_growth_rate.12_month` |
| `ticker` | Stock ticker if public |
| `naics_codes` | Industry classification codes |

## Execution Flow

```
1. DETECT CONTEXT
   +-- Contact-triggered? -> have subscriber email
   +-- Company-triggered? -> have company name/website

2. CHECK SKIP CONDITIONS
   +-- Reprocess disabled + contact has enriched_at? -> skip
   +-- No API key configured for selected provider? -> skip + log warning

3. RESOLVE PROVIDER
   +-- Instantiate provider from setting (default: PDL)

4. PERSON ENRICHMENT (if scope includes person + have subscriber)
   +-- Call provider.enrichPerson(email, first_name, last_name, ...)
   +-- Error or below min_likelihood?
   |   +-- Apply "no match" tag if configured
   |   +-- Continue to company step (may still work from email domain)
   +-- Success + above threshold?
       +-- Map PersonResult -> Subscriber fields (respecting fill_empty/overwrite)
       +-- Set enriched_at and enrichment_provider custom fields
       +-- Stash job_company_name, job_company_website for company step

5. COMPANY ENRICHMENT (if scope includes company)
   +-- Determine company identifier:
   |   +-- Company-triggered? -> use company.website or company.name
   |   +-- Contact has business email? -> extract domain -> use as website
   |   +-- Free email? -> use job_company_website from step 4 (if available)
   +-- No identifier found? -> skip company enrichment
   +-- Call provider.enrichCompany(name, website, ...)
   +-- Error? -> skip
   +-- Success?
       +-- Determine match confidence:
       |   +-- Email domain == company website domain? -> "confirmed"
       |   +-- Otherwise -> "inferred"
       +-- Based on Company Handling setting:
           +-- "Create or update" ->
           |   +-- Find existing Company by website domain
           |   +-- Create if not found, update if found
           |   +-- Store match confidence in company meta
           |   +-- Link subscriber <-> company (pivot + company_id)
           +-- "Update existing only" ->
           |   +-- Update company if subscriber already linked to one
           +-- "Don't touch companies" ->
               +-- Store company data in contact custom fields only

6. APPLY TAGS
   +-- Enrichment succeeded? -> apply success tag if configured
   +-- Both person + company failed? -> apply no-match tag if configured

7. FIRE HOOKS
   +-- do_action('custom_crm/contact_enriched', $subscriber, $personResult, $companyResult)
   +-- do_action('custom_crm/company_enriched', $company, $companyResult)
```

### Free Email Detection

A helper checks the email domain against a list of known free providers (gmail.com, yahoo.com, hotmail.com, outlook.com, icloud.com, aol.com, protonmail.com, etc.). The list is filterable:

```php
apply_filters('custom_crm/free_email_domains', $domains)
```

### Company Matching

When looking for an existing FluentCRM Company, match by normalized website domain (strip protocol, `www.`, trailing slash). If no website match, fall back to exact name match.

## Settings Page & API Key Management

A settings section for provider configuration. Each registered provider contributes its own fields.

### Database Structure

```php
// Option key: custom_crm_enrichment_settings
[
    'active_provider' => 'pdl',
    'providers' => [
        'pdl' => [
            'api_key'  => '(encrypted)',
            'api_tier' => 'free',       // free | paid
        ],
    ],
]
```

### Provider Settings Registration

Each provider declares its settings fields and validation:

```php
// In EnrichmentProvider (abstract)
abstract public function getSettingsFields(): array;
abstract public function validateSettings(array $settings): bool|string;
```

PDL declares: `api_key` (password field) and `api_tier` (select: free/paid).

### Settings Page Features

- **Test Connection** button per provider — calls `validateSettings()`, shows inline success/failure
- API key stored encrypted via `wp_encrypt()` (WP 6.5+) with fallback to site-salted encoding for older installs
- Provider dropdown in action block only shows providers with valid configured API keys
- If no providers configured, the action block shows a notice linking to settings

## Custom Field Auto-Creation

On plugin activation and lazily on first enrichment run, ensure all required FluentCRM custom fields exist:

| Slug | Label | Type | Group |
|---|---|---|---|
| `enrichment_job_title` | Job Title | text | Enrichment |
| `enrichment_job_role` | Job Role | text | Enrichment |
| `enrichment_job_level` | Job Level | text | Enrichment |
| `enrichment_company_name` | Company Name | text | Enrichment |
| `enrichment_linkedin_url` | LinkedIn URL | text | Enrichment |
| `enrichment_twitter_url` | Twitter URL | text | Enrichment |
| `enrichment_facebook_url` | Facebook URL | text | Enrichment |
| `enrichment_github_url` | GitHub URL | text | Enrichment |
| `enrichment_sex` | Sex | select-one | Enrichment |
| `enrichment_pronouns` | Pronouns | text | Enrichment |
| `enrichment_inferred_salary` | Inferred Salary | text | Enrichment |
| `enrichment_industry` | Industry | text | Enrichment |
| `enriched_at` | Enriched At | date_time | Enrichment |
| `enrichment_provider` | Enrichment Provider | text | Enrichment |
| `enrichment_likelihood` | Match Likelihood | number | Enrichment |
| `enrichment_company_match` | Company Match Type | select-one | Enrichment |

Uses `fluentcrm_get_option('contact_custom_fields')` / `fluentcrm_update_option()`. A transient prevents repeated DB reads after initial check.

## File Structure

```
classes/
  Enrichment/
    EnrichmentProvider.php          # Abstract provider contract
    EnrichmentError.php             # Normalized error DTO
    PersonResult.php                # Person data DTO
    CompanyResult.php               # Company data DTO
    EnrichmentFields.php            # Auto-creates FluentCRM custom fields
    EnrichmentSettings.php          # Read/write provider settings, encrypt API keys
    FreeEmailDetector.php           # Free email domain checker (filterable)
    Providers/
      PDLProvider.php               # People Data Labs implementation
  Actions/
    EnrichContactAction.php         # FluentCRM action block
```

## Hooks & Filters

| Hook | Type | Purpose |
|---|---|---|
| `custom_crm/enrichment_providers` | Filter | Register additional providers |
| `custom_crm/free_email_domains` | Filter | Add/remove free email domains |
| `custom_crm/enrichment_person_result` | Filter | Modify person DTO before saving (e.g., pronouns mapping) |
| `custom_crm/enrichment_company_result` | Filter | Modify company DTO before saving |
| `custom_crm/contact_enriched` | Action | Fires after contact enrichment completes |
| `custom_crm/company_enriched` | Action | Fires after company enrichment completes |

## Stretch Goals (not in initial implementation)

- Daily/monthly budget cap for API credits
- Cooldown period before re-enrichment
- Bulk enrichment UI (process existing contacts outside of automation flows)
- Additional providers (Clearbit, Apollo, ZoomInfo)
- Enrichment history log per contact (track changes over time)
