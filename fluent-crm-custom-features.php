<?php
/**
 * Plugin Name: FluentCRM - Custom events, actions and conditionals.
 * Plugin URI: https://github.com/code-atlantic/fluent-crm-custom-features
 * Description: Custom FluentCRM features: EDD subscription filtering, JSON event tracking, custom automation actions.
 * Version: 1.0.0
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/code-atlantic/fluent-crm-custom-features
 * Primary Branch: master
 * Requires PHP: 7.4
 * Requires at least: 6.2
 *
 * @package    FluentCRM\CustomFeatures
 * @author     Code Atlantic
 * @copyright  Copyright (c) 2024, Code Atlantic LLC.
 */

// Register autoloader.
require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'init',
	function () {
		( new \CustomCRM\JSONEventTrackingHandler() )->register();

		$edd_rules = new \CustomCRM\EDDSubscriptionRules();
		$edd_rules->register();

		( new \CustomCRM\Actions\RandomWaitTimeAction() )->register();

		// Remove the default update contact property action (broken).
		remove_all_actions( 'fluentcrm_funnel_sequence_handle_update_contact_property' );
		// Register our custom update contact property action.
		( new \CustomCRM\Actions\UpdateContactPropertyAction() )->register();

		// Enable our custom webhook handler.
		( new \CustomCRM\Webhooks() );

		// Register custom automation conditions (event tracking + automation completion).
		( new \CustomCRM\Conditions\AutomationConditions() )->register();

		// Track EDD license activations as FluentCRM events.
		( new \CustomCRM\EddLicenseActivationTracker() )->register();

		// Remove the default smart link handler.
		remove_all_actions( 'fluentcrm_smartlink_clicked' );
		remove_all_actions( 'fluentcrm_smartlink_clicked_direct' );
		// Register our custom smart link handler.
		$fix_smart_link_redirects = new \CustomCRM\SmartLinkHandler();

		add_action( 'fluentcrm_smartlink_clicked', [ $fix_smart_link_redirects, 'handleClick' ], 9, 1 );
		add_action( 'fluentcrm_smartlink_clicked_direct', [ $fix_smart_link_redirects, 'handleClick' ], 9, 2 );
	},
	99
);

// Hook to register a custom REST API endpoint.
add_action( 'rest_api_init', function () {
	register_rest_route( 'fluent-crm/v1', '/list-growth', [
		'methods'             => 'GET',
		'callback'            => 'customcrm_get_list_growth',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args'                => [
			'from' => [
				'required'          => false,
				'validate_callback' => 'customcrm_validate_date_param',
			],
			'to'   => [
				'required'          => false,
				'validate_callback' => 'customcrm_validate_date_param',
			],
		],
	] );
} );

/**
 * Validate a date parameter for the REST API.
 *
 * @param string $value The parameter value.
 *
 * @return bool
 */
function customcrm_validate_date_param( $value ) {
	// Allow empty values (defaults will be used).
	if ( empty( $value ) ) {
		return true;
	}

	// Must match YYYY-MM-DD format.
	return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
}

/**
 * Get List Growth metrics.
 *
 * @param WP_REST_Request $request The REST request object.
 *
 * @return WP_REST_Response
 */
function customcrm_get_list_growth( WP_REST_Request $request ) {
	$from = $request->get_param( 'from' );
	$to   = $request->get_param( 'to' );

	// Default to current month if not provided.
	$from = ! empty( $from ) ? sanitize_text_field( $from ) : gmdate( 'Y-m-01' );
	$to   = ! empty( $to ) ? sanitize_text_field( $to ) : gmdate( 'Y-m-t' );

	// Count new subscribers.
	$new_subscribers = fluentCrmDb()->table( 'fc_subscribers' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'status', 'subscribed' )
		->count();

	// Count unsubscribed.
	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'key', 'unsubscribe_reason' )
		->count();

	// Calculate net growth.
	$net_growth = $new_subscribers - $unsubscribed;

	return new WP_REST_Response( [
		'new_subscribers' => $new_subscribers,
		'unsubscribed'    => $unsubscribed,
		'net_growth'      => $net_growth,
	], 200 );
}

// Hook to add custom metrics to the dashboard.
add_filter( 'fluent_crm/dashboard_data', 'customcrm_add_dashboard_list_growth_metrics' );

/**
 * Add custom dashboard metrics for list growth.
 *
 * @param array<string,mixed> $data The dashboard data.
 *
 * @return array<string,mixed>
 */
function customcrm_add_dashboard_list_growth_metrics( $data ) {
	// Get the date range from the request or set default values.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
	$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-t' );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Calculate new subscribers and unsubscribes.
	$new_subscribers = fluentCrmDb()->table( 'fc_subscribers' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'status', 'subscribed' )
		->count();

	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $from, $to ] )
		->where( 'key', 'unsubscribe_reason' )
		->count();

	// Calculate net growth.
	$net_growth = $new_subscribers - $unsubscribed;

	// Add the new metrics to the dashboard data.
	$data['list_growth'] = [
		'title'           => __( 'List Growth', 'fluent-crm-custom-features' ),
		'new_subscribers' => $new_subscribers,
		'unsubscribed'    => $unsubscribed,
		'net_growth'      => $net_growth,
	];

	return $data;
}
