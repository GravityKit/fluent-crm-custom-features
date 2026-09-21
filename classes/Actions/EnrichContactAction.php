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

	/**
	 * EnrichContactAction constructor.
	 */
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
					'type'       => 'radio',
					'label'      => __( 'Company Handling', 'fluent-crm-custom-features' ),
					'options'    => [
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
	 * @param \FluentCrm\App\Models\Subscriber     $subscriber          The subscriber.
	 * @param \FluentCrm\App\Models\FunnelSequence $sequence            The funnel sequence.
	 * @param int                                  $funnel_subscriber_id Funnel subscriber ID.
	 * @param mixed                                $funnel_metric       Funnel metric.
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
	 * @param EnrichmentProvider $provider              The provider.
	 * @param Subscriber         $subscriber             The subscriber.
	 * @param int                $min_likelihood         Minimum likelihood threshold.
	 * @param string             $data_behavior          'fill_empty' or 'overwrite'.
	 * @param int                $funnel_subscriber_id   Funnel subscriber ID.
	 * @param mixed              $sequence               The funnel sequence.
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
		$custom_fields                        = $result->toCustomFields();
		$custom_fields['enriched_at']         = current_time( 'mysql' );
		$custom_fields['enrichment_provider'] = $provider->getSlug();

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
	 * @param EnrichmentProvider $provider              The provider.
	 * @param Subscriber         $subscriber             The subscriber.
	 * @param mixed              $person_result          PersonResult or null.
	 * @param bool               $is_company_trigger     Whether this is a company-triggered funnel.
	 * @param int                $min_likelihood         Minimum likelihood threshold.
	 * @param string             $data_behavior          'fill_empty' or 'overwrite'.
	 * @param string             $company_handling       Company handling mode.
	 * @param int                $funnel_subscriber_id   Funnel subscriber ID.
	 * @param mixed              $sequence               The funnel sequence.
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
		$email_domain  = $subscriber->email ? FreeEmailDetector::extractDomain( $subscriber->email ) : '';
		$result_domain = $this->normalizeDomain( $result->website ?? '' );
		$match_type    = ( $email_domain && $result_domain && $email_domain === $result_domain ) ? 'confirmed' : 'inferred';

		// Handle company based on setting.
		$company = null;

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

		if ( $company ) {
			do_action( 'custom_crm/company_enriched', $company, $result );
		}

		return $result;
	}

	/**
	 * Find an existing company by website domain or name, or create a new one.
	 *
	 * @param CompanyResult $result        The company enrichment result.
	 * @param string        $match_type    'confirmed' or 'inferred'.
	 * @param string        $provider_slug Provider slug.
	 * @param string        $data_behavior 'fill_empty' or 'overwrite'.
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
			$escaped    = addcslashes( $normalized, '%_' );
			$company    = Company::where( 'website', 'LIKE', "%{$escaped}%" )->first();
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
		$fields         = $result->toCompanyFields();
		$fields['meta'] = $this->buildCompanyMeta( $result, $match_type, $provider_slug );

		if ( empty( $fields['name'] ) ) {
			return null;
		}

		return Company::create( $fields );
	}

	/**
	 * Update an existing company from enrichment results.
	 *
	 * @param Company       $company       The company model.
	 * @param CompanyResult $result        The company enrichment result.
	 * @param string        $match_type    'confirmed' or 'inferred'.
	 * @param string        $provider_slug Provider slug.
	 * @param string        $data_behavior 'fill_empty' or 'overwrite'.
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
		$meta            = $company->meta;
		$enrichment_meta = $this->buildCompanyMeta( $result, $match_type, $provider_slug );
		$company->meta   = array_merge( is_array( $meta ) ? $meta : [], $enrichment_meta );
		$company->save();
	}

	/**
	 * Build the enrichment portion of company meta.
	 *
	 * @param CompanyResult $result        The company enrichment result.
	 * @param string        $match_type    'confirmed' or 'inferred'.
	 * @param string        $provider_slug Provider slug.
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
	 *
	 * @param Subscriber    $subscriber     The subscriber.
	 * @param CompanyResult $result         The company enrichment result.
	 * @param string        $match_type     'confirmed' or 'inferred'.
	 * @param string        $_provider_slug Provider slug (reserved for future use).
	 * @param string        $data_behavior  'fill_empty' or 'overwrite'.
	 */
	private function storeCompanyInCustomFields(
		Subscriber $subscriber,
		CompanyResult $result,
		string $match_type,
		string $_provider_slug,
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
	 *
	 * @param EnrichmentError $error                 The error.
	 * @param Subscriber      $subscriber             The subscriber.
	 * @param int             $funnel_subscriber_id   Funnel subscriber ID.
	 * @param mixed           $sequence               The funnel sequence.
	 * @param string          $context                'person' or 'company'.
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
	 * @param Subscriber          $subscriber Subscriber model.
	 * @param array<string,mixed> $fields     Fields to filter.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyOnly( Subscriber $subscriber, array $fields ): array {
		return array_filter(
			$fields,
			static fn( $_value, $key ) => empty( $subscriber->$key ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Filter custom fields to only those that are currently empty on the subscriber.
	 *
	 * Uses Subscriber::getMeta() to check each field individually since FluentCRM
	 * stores custom field values as individual meta rows.
	 *
	 * @param Subscriber          $subscriber Subscriber model.
	 * @param array<string,mixed> $fields     Custom field slug => value.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyCustomFieldsOnly( Subscriber $subscriber, array $fields ): array {
		return array_filter(
			$fields,
			static function ( $_value, $key ) use ( $subscriber ) {
				$existing = $subscriber->getMeta( $key, 'custom_field' );

				// getMeta() returns false when the meta row doesn't exist.
				// Also treat empty strings and null as "empty".
				return false === $existing || '' === $existing || null === $existing;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Filter company fields to only those that are currently empty.
	 *
	 * @param Company             $company Company model.
	 * @param array<string,mixed> $fields  Fields to filter.
	 *
	 * @return array<string,mixed>
	 */
	private function filterEmptyCompanyFields( Company $company, array $fields ): array {
		return array_filter(
			$fields,
			static fn( $_value, $key ) => empty( $company->$key ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Detect if this is a company-triggered funnel.
	 *
	 * @param Subscriber $_subscriber The subscriber (unused, context detection uses sequence).
	 * @param mixed      $sequence    The funnel sequence.
	 *
	 * @return bool
	 */
	private function isCompanyTriggered( Subscriber $_subscriber, $sequence ): bool {
		// Check funnel trigger name if available.
		if ( isset( $sequence->funnel ) && isset( $sequence->funnel->trigger_name ) ) {
			$trigger = $sequence->funnel->trigger_name;
			if ( false !== strpos( $trigger, 'company' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a domain for comparison (strip protocol, www, trailing slash).
	 *
	 * @param string $url The URL or domain to normalize.
	 *
	 * @return string
	 */
	private function normalizeDomain( string $url ): string {
		$domain = strtolower( $url );
		$domain = preg_replace( '#^https?://#', '', $domain ) ?? $domain;
		$domain = preg_replace( '#^www\.#', '', $domain ) ?? $domain;
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
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return EnrichmentProvider|null
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
		if ( function_exists( '\fluentCrmLog' ) ) {
			\fluentCrmLog( $message ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[CustomCRM Enrichment][{$level}] {$message}" );
		}
	}
}
