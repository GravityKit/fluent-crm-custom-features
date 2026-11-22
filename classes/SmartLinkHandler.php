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
	 * A link that changes the contact (tags or lists) never acts here: this runs on a GET, which
	 * mail scanners send for every link in a message. It sends the reader to SmartLinkConfirm,
	 * whose button post calls applyConfirmed().
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

		++$smart_link->all_clicks;
		$smart_link->save();

		if ( self::changesContact( $smart_link ) ) {
			$confirm_url = SmartLinkConfirm::url( $smart_link, $contact ? (int) $contact->id : 0, $this->forwardedParams() );

			nocache_headers();
			wp_safe_redirect( $confirm_url, 303 );
			exit();
		}

		if ( $contact ) {
			++$smart_link->contact_clicks;
			$smart_link->save();

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
	 * Whether the link adds or removes a tag or list.
	 *
	 * @param SmartLink $smart_link The smart link object.
	 */
	public static function changesContact( $smart_link ): bool {
		foreach ( [ 'tags', 'lists', 'remove_tags', 'remove_lists' ] as $action ) {
			if ( Arr::get( $smart_link->actions, $action ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Applies the link's tag and list changes after the reader pressed the confirmation button.
	 * Auto-login is not run here; it needs the verified email click, which is a separate request.
	 *
	 * @param SmartLink                         $smart_link The smart link object.
	 * @param \FluentCrm\App\Models\Subscriber $contact    The contact.
	 */
	public function applyConfirmed( $smart_link, $contact ): void {
		$tags         = Arr::get( $smart_link->actions, 'tags' );
		$lists        = Arr::get( $smart_link->actions, 'lists' );
		$remove_tags  = Arr::get( $smart_link->actions, 'remove_tags' );
		$remove_lists = Arr::get( $smart_link->actions, 'remove_lists' );

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

		++$smart_link->contact_clicks;
		$smart_link->save();

		do_action( 'fluent_crm/smart_link_clicked_by_contact', $smart_link, $contact );
	}

	/**
	 * Query parameters of this request that go on to the target page.
	 *
	 * @return array
	 */
	private function forwardedParams(): array {
		$ignored_params = [ 'fluentcrm', 'route', 'slug' ];

		// Forwarding a tracked click's parameters makes FluentCRM's tracker rerun this link on the
		// target page, in an endless loop (WEBSITE-567). Direct links keep them, e.g. their own `mid`.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for smart link redirect, nonce not applicable.
		if ( isset( $_GET['ns_url'] ) ) {
			$ignored_params = array_merge( $ignored_params, [ 'ns_url', 'mid', 'fch', 'signed_hash', 'ano' ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query params for smart link redirect, nonce not applicable.
		return map_deep( array_diff_key( wp_unslash( $_GET ), array_flip( $ignored_params ) ), 'sanitize_text_field' );
	}

	/**
	 * Get the target URL for the smart link with query parameters preserved.
	 *
	 * @param SmartLink                     $smart_link   The smart link object.
	 * @param \FluentCrm\App\Models\Contact $contact      The contact object.
	 * @param array|null                    $query_params Parameters to forward; null reads this request.
	 *
	 * @return string The target URL with query parameters preserved.
	 */
	public function getTargetUrl( $smart_link, $contact, $query_params = null ) {
		if ( null === $query_params ) {
			$query_params = $this->forwardedParams();
		}

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
