<?php
/**
 * Fix SmartLink redirects.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

use FluentCampaign\App\Models\SmartLink;
use FluentCrm\Framework\Support\Arr;

/**
 * Class FixSmartLinkRedirects
 *
 * Purpose: This class is used to fix the SmartLink redirects to pass the query parameters to the target URL.
 *
 * @package CustomCRM
 */
class SmartLinkHandler extends \FluentCampaign\App\Hooks\Handlers\SmartLinkHandler {

	/**
	 * Handle the smart link click.
	 *
	 * @param string $slug The smart link slug.
	 * @param null   $contact The contact object.
	 *
	 * @return void
	 */
	public function handleClick( $slug, $contact = null ) {
		$smart_link = SmartLink::where( 'short', $slug )->first();

		if ( ! $smart_link ) {
			return;
		}

		if ( ! $contact ) {
			$contact = fluentcrm_get_current_contact();
		}

		// Increment click count.
		if ( $contact ) {
			// Here to preserve original order. If this $smart_link->save();
			// doesn't need to be done before tags/lists, then this can be merged,
			// and the save() can be done at the end.
			++$smart_link->contact_clicks;
		}

		++$smart_link->all_clicks;
		$smart_link->save();

		if ( $contact ) {
			$tags         = Arr::get( $smart_link->actions, 'tags' );
			$lists        = Arr::get( $smart_link->actions, 'lists' );
			$remove_tags  = Arr::get( $smart_link->actions, 'remove_tags' );
			$remove_lists = Arr::get( $smart_link->actions, 'remove_lists' );

			// Perform actions based on smart link settings.
			if ( $tags ) {
				$contact->attachTags( $tags );
			}
			if ( $lists ) {
				$contact->attachLists( $lists );
			}
			if ( $remove_tags ) {
				$contact->detachTags( $remove_tags );
			}
			if ( $remove_lists ) {
				$contact->detachLists( $remove_lists );
			}

			if ( 'yes' === Arr::get( $smart_link->actions, 'auto_login' ) ) {
				$this->makeAutoLogin( $contact );
			}
		}

		$target_url = $this->getTargetUrl( $smart_link, $contact );

		do_action( 'fluent_crm/smart_link_clicked_by_contact', $smart_link, $contact );
		nocache_headers();
		wp_safe_redirect( $target_url, 307 );
		exit();
	}

	/**
	 * Get the target URL for the smart link with query parameters preserved.
	 *
	 * @param SmartLink                     $smart_link The smart link object.
	 * @param \FluentCrm\App\Models\Contact $contact The contact object.
	 *
	 * @return string The target URL with query parameters preserved.
	 */
	public function getTargetUrl( $smart_link, $contact ) {
		$ignored_params = [ 'fluentcrm', 'route', 'slug' ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for smart link redirect, nonce not applicable.
		$query_params = array_diff_key( $_GET, array_flip( $ignored_params ) );

		// Sanitize all forwarded query parameters (handles nested arrays).
		$query_params = map_deep( $query_params, 'sanitize_text_field' );
		$query_string = http_build_query( $query_params );

		$target_url = $smart_link->target_url;

		// Apply dynamic content if needed and redirect.
		if ( strpos( $target_url, '{{' ) ) {
			$target_url = apply_filters( 'fluent_crm/parse_campaign_email_text', $target_url, $contact );
			$target_url = esc_url_raw( $target_url );
		}

		// Only append separator and query string if there are params to forward.
		if ( $query_string ) {
			$separator  = ( false === strpos( $target_url, '?' ) ) ? '?' : '&';
			$target_url = $target_url . $separator . $query_string;
		}

		return $target_url;
	}

	/**
	 * Make the contact auto-login.
	 *
	 * @param \FluentCrm\App\Models\Contact $contact The contact object.
	 *
	 * @return bool True if the contact was auto-logged in, false otherwise.
	 */
	private function makeAutoLogin( $contact ) {
		if ( is_user_logged_in() ) {
			return false;
		}

		$user = get_user_by( 'email', $contact->email );

		if ( ! $user ) {
			return false;
		}

		$will_allow_login = apply_filters( 'fluent_crm/will_make_auto_login', did_action( 'fluent_crm/smart_link_verified' ), $contact );
		if ( ! $will_allow_login ) {
			return false;
		}

		if ( $user->has_cap( 'publish_posts' ) && ! apply_filters( 'fluent_crm/enable_high_level_auto_login', false, $contact ) ) {
			return false;
		}

		$current_contact = fluentcrm_get_current_contact();

		if ( ! $current_contact || $current_contact->id !== $contact->id ) {
			return false;
		}

		add_filter( 'authenticate', [ $this, 'allowProgrammaticLogin' ], 10, 3 );    // Hook in earlier than other callbacks to short-circuit them.
		$user = wp_signon(
			[
				'user_login'    => $user->user_login,
				'user_password' => '',
			]
		);
		remove_filter( 'authenticate', [ $this, 'allowProgrammaticLogin' ], 10, 3 );

		if ( $user instanceof \WP_User ) {
			wp_set_current_user( $user->ID, $user->user_login );
			if ( is_user_logged_in() ) {
				return true;
			}
		}

		return false;
	}
}
