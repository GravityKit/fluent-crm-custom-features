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

		$existing       = fluentcrm_get_option( 'contact_custom_fields', [] );
		$existing_slugs = array_column( $existing, 'slug' );
		$added          = false;

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
