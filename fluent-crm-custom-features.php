<?php
/**
 * Plugin Name: FluentCRM - Custom events, actions and conditionals.
 * Plugin URI: https://github.com/GravityKit/fluent-crm-custom-features
 * Description: Custom FluentCRM features: EDD subscription filtering, JSON event tracking, custom automation actions.
 * Version: 1.0.0
 * Author: Code Atlantic LLC
 * Author URI: https://code-atlantic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/GravityKit/fluent-crm-custom-features
 * Primary Branch: main
 * Requires PHP: 7.4
 * Requires at least: 6.2
 *
 * @package    FluentCRM\CustomFeatures
 * @author     Code Atlantic
 * @copyright  Copyright (c) 2024, Code Atlantic LLC.
 */

// PSR-4 autoloader for CustomCRM namespace.
spl_autoload_register( function ( $class ) {
	$prefix = 'CustomCRM\\';
	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative_class = substr( $class, strlen( $prefix ) );
	$file           = __DIR__ . '/classes/' . str_replace( '\\', '/', $relative_class ) . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Whether FluentCampaign Pro's Commerce service is available.
 *
 * Some features here extend or call Pro classes. Without this guard, deactivating Pro — during
 * an upgrade, a failed activation, or a lapsed licence — turns a missing feature into a fatal on
 * every request, because `class X extends \FluentCampaign\...` is resolved at class-load time.
 * That took gravitykit.com down on 2026-09-18.
 *
 * Checked by class rather than by `did_action( 'fluentcampaign_loaded' )`, because this runs on
 * `init` and only the class actually being extended matters.
 */
function customcrm_commerce_is_available() {
	return class_exists( '\\FluentCampaign\\App\\Services\\Commerce\\Commerce' );
}

/**
 * Whether FluentCampaign Pro's smart-link handler is available.
 *
 * Checked separately from Commerce above: the two Pro integrations extend different classes, so
 * ANDing them would disable a working one whenever the other is missing.
 */
function customcrm_smart_link_is_available() {
	return class_exists( '\\FluentCampaign\\App\\Hooks\\Handlers\\SmartLinkHandler' );
}

/**
 * Whether FluentCRM core's classes are available.
 *
 * Everything this plugin registers is an extension of FluentCRM: the actions extend
 * `BaseAction`/`WaitTimeAction`, the rest call core models and helpers. With core deactivated
 * there is nothing to extend, and `class X extends \FluentCrm\...` is resolved at class-load
 * time — so the plugin fatals on every request instead of simply having nothing to do.
 *
 * `BaseAction` is the check because it is the parent of the classes registered below; if it is
 * present, the funnel API this plugin builds on is present.
 */
function customcrm_core_is_available() {
	return function_exists( 'FluentCrmApi' )
		&& class_exists( '\\FluentCrm\\App\\Services\\Funnel\\BaseAction' )
		&& class_exists( '\\FluentCrm\\App\\Services\\Funnel\\Actions\\WaitTimeAction' );
}

add_action(
	'init',
	function () {
		// Nothing here can run without FluentCRM core; see customcrm_core_is_available().
		if ( ! customcrm_core_is_available() ) {
			return;
		}

		( new \CustomCRM\JSONEventTrackingHandler() )->register();

		// Needs FluentCampaign Pro's Commerce service.
		if ( customcrm_commerce_is_available() ) {
			$edd_rules = new \CustomCRM\EDDSubscriptionRules();
			$edd_rules->register();
		}

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

		// Extends a Pro class, so it cannot even be loaded without Pro. Leaving Pro's own handler
		// in place is the right fallback: the redirect still works, it just loses the query
		// parameters this override exists to preserve.
		if ( customcrm_smart_link_is_available() ) {
			// Remove the default smart link handler.
			remove_all_actions( 'fluentcrm_smartlink_clicked' );
			remove_all_actions( 'fluentcrm_smartlink_clicked_direct' );
			// Register our custom smart link handler.
			$fix_smart_link_redirects = new \CustomCRM\SmartLinkHandler();

			add_action( 'fluentcrm_smartlink_clicked', [ $fix_smart_link_redirects, 'handleClick' ], 9, 1 );
			add_action( 'fluentcrm_smartlink_clicked_direct', [ $fix_smart_link_redirects, 'handleClick' ], 9, 2 );
		}

		// Custom CSS editor for FluentCRM email templates.
		( new \CustomCRM\Integrations\CustomEmailCSS() )->register();

		// Resolves any palette colour token FluentCRM left unreplaced. Logs loudly when it fires,
		// because a firing means the theme-level editor-color-palette fix stopped working.
		( new \CustomCRM\Integrations\EmailPaletteFallback() )->register();

		// FluentCRM 3.2.0 stopped auto-padding core/group blocks, which our campaigns rely on for
		// the header band's inner spacing. Restores it until the padding is authored into blocks.
		( new \CustomCRM\Integrations\EmailGroupSpacing() )->register();

		// FluentCRM 3.2.0 writes theme font sizes into email as rem, which follows the mail client's
		// root size rather than the email's. Converts them to px so every client renders the same.
		( new \CustomCRM\Integrations\EmailRemToPx() )->register();

		// Contact and company enrichment via external providers (PDL, etc.).
		( new \CustomCRM\Actions\EnrichContactAction() );
	},
	99
);

// Hook to register custom REST API endpoints.
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

	// Must match YYYY-MM-DD format and be a valid date.
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
		return false;
	}

	return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
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
		->whereBetween( 'created_at', [ $from . ' 00:00:00', $to . ' 23:59:59' ] )
		->where( 'status', 'subscribed' )
		->count();

	// Count unsubscribed.
	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $from . ' 00:00:00', $to . ' 23:59:59' ] )
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
		->whereBetween( 'created_at', [ $from . ' 00:00:00', $to . ' 23:59:59' ] )
		->where( 'status', 'subscribed' )
		->count();

	$unsubscribed = fluentCrmDb()->table( 'fc_subscriber_meta' )
		->whereBetween( 'created_at', [ $from . ' 00:00:00', $to . ' 23:59:59' ] )
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
