<?php
/**
 * Confirmation page for Smart Links that change a contact.
 *
 * @package CustomCRM
 */

namespace CustomCRM;

use FluentCampaign\App\Models\SmartLink;
use FluentCrm\App\Models\Subscriber;

/**
 * A Smart Link that adds or removes a tag or list changes nothing when opened: it opens this page,
 * and the change happens on the button's POST. Mail scanners open every link in a message, so
 * acting on a GET tags people who never clicked.
 *
 * Served from `/wp-json/` because the CDN caches any 200 on the home URL (see CartEmailStop).
 */
class SmartLinkConfirm {

	public const REST_NAMESPACE = 'gk-smart-link/v1';

	public const REST_ROUTE = '/confirm';

	/**
	 * Hooks the page.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					self::REST_NAMESPACE,
					self::REST_ROUTE,
					[
						'methods'             => [ 'GET', 'POST' ],
						'callback'            => [ self::class, 'handleRequest' ],
						'permission_callback' => '__return_true',
					]
				);
			}
		);
	}

	/**
	 * The confirmation page for one link and one contact.
	 *
	 * @param SmartLink $smart_link The Smart Link.
	 * @param int       $contact_id FluentCRM contact ID, or 0 when the click did not say who it was.
	 * @param array     $forward    Query parameters to pass on to the link's target page.
	 */
	public static function url( SmartLink $smart_link, int $contact_id, array $forward = [] ): string {
		$args = [
			'slug' => $smart_link->short,
			'c'    => $contact_id,
			'k'    => self::key( (string) $smart_link->short, $contact_id ),
		];

		if ( $forward ) {
			$args['fw'] = rawurlencode( http_build_query( $forward ) );
		}

		return add_query_arg( $args, rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
	}

	/**
	 * Shows the page, or makes the change on its form post.
	 */
	public static function handleRequest(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The signed key authorizes the request.
		$slug       = sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) );
		$contact_id = absint( $_GET['c'] ?? 0 );
		$key        = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) );
		$forward    = [];
		parse_str( rawurldecode( sanitize_text_field( wp_unslash( $_GET['fw'] ?? '' ) ) ), $forward );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		nocache_headers();

		$smart_link = '' !== $slug ? SmartLink::where( 'short', $slug )->first() : null;
		$is_valid   = $smart_link && hash_equals( self::key( $slug, $contact_id ), $key );

		if ( ! $is_valid ) {
			self::render(
				__( 'This link has expired', 'fluent-crm-custom-features' ),
				__( 'Please use the link in the most recent email we sent you, or reply to that email and we’ll take care of it.', 'fluent-crm-custom-features' ),
				'',
				'',
				404
			);
		}

		$contact = $contact_id ? Subscriber::find( $contact_id ) : null;

		if ( ! $contact ) {
			self::render(
				__( 'Please open this link from your email', 'fluent-crm-custom-features' ),
				__( 'This link didn’t tell us which email address it’s for, so nothing has changed. Open it from the email we sent you, or reply to that email and we’ll do it for you.', 'fluent-crm-custom-features' )
			);
		}

		$copy    = self::copy( $smart_link );
		$is_post = 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );

		if ( $is_post ) {
			$handler = new SmartLinkHandler();
			$handler->applyConfirmed( $smart_link, $contact );

			nocache_headers();
			wp_safe_redirect( $handler->getTargetUrl( $smart_link, $contact, map_deep( $forward, 'sanitize_text_field' ) ), 303 );
			exit;
		}

		$message = $copy['message'] . ' ' . sprintf(
			/* translators: %s: masked email address */
			__( 'This is for %s.', 'fluent-crm-custom-features' ),
			self::maskEmail( (string) $contact->email )
		);

		self::render( $copy['title'], $message, $copy['button'], esc_url( self::url( $smart_link, $contact_id, $forward ) ) );
	}

	/**
	 * Wording for a link's page. Add an entry here for every new Smart Link that changes a contact,
	 * or it gets the plain fallback. Filter: `customcrm/smart_link_confirm_copy`.
	 *
	 * @param SmartLink $smart_link The Smart Link.
	 *
	 * @return array{title:string,message:string,button:string}
	 */
	public static function copy( SmartLink $smart_link ): array {
		$by_slug = [
			// BFCM26: Opt out of Black Friday emails (tag 215).
			'37mmx7'  => [
				'title'   => __( 'Stop Black Friday emails?', 'fluent-crm-custom-features' ),
				'message' => __( 'We’ll stop sending you Black Friday sale emails this year. You’ll still get receipts, license emails and anything else you signed up for.', 'fluent-crm-custom-features' ),
				'button'  => __( 'Stop Black Friday emails', 'fluent-crm-custom-features' ),
			],
			// BFCM26: Get early access (tag 211).
			'dqw0cp'  => [
				'title'   => __( 'Join the Black Friday early access list?', 'fluent-crm-custom-features' ),
				'message' => __( 'We’ll email you a private link to the Black Friday deals before everyone else.', 'fluent-crm-custom-features' ),
				'button'  => __( 'Join the early access list', 'fluent-crm-custom-features' ),
			],
			// Add to newsletter list (list 12).
			'cn0a1n6' => [
				'title'   => __( 'Get the GravityKit newsletter?', 'fluent-crm-custom-features' ),
				'message' => __( 'We’ll add you to our newsletter: product news, tips and what we’re working on. You can unsubscribe from any issue.', 'fluent-crm-custom-features' ),
				'button'  => __( 'Subscribe to the newsletter', 'fluent-crm-custom-features' ),
			],
			// GravityKit Live - Interested (tag 162, list 14).
			'cndvnd5' => [
				'title'   => __( 'Hear about GravityKit Live?', 'fluent-crm-custom-features' ),
				'message' => __( 'We’ll email you about upcoming GravityKit Live sessions.', 'fluent-crm-custom-features' ),
				'button'  => __( 'Yes, keep me posted', 'fluent-crm-custom-features' ),
			],
			// Early access - GravityMigrate (tag 185).
			'cx7uss6' => [
				'title'   => __( 'Get early access to GravityMigrate?', 'fluent-crm-custom-features' ),
				'message' => __( 'We’ll email you when GravityMigrate early access opens.', 'fluent-crm-custom-features' ),
				'button'  => __( 'Get early access', 'fluent-crm-custom-features' ),
			],
		];

		$fallback = [
			'title'   => __( 'Please confirm', 'fluent-crm-custom-features' ),
			'message' => __( 'Press the button to finish what you started in our email.', 'fluent-crm-custom-features' ),
			'button'  => __( 'Confirm', 'fluent-crm-custom-features' ),
		];

		$copy = $by_slug[ (string) $smart_link->short ] ?? $fallback;

		return (array) apply_filters( 'customcrm/smart_link_confirm_copy', $copy, $smart_link ) + $fallback;
	}

	/**
	 * An unguessable key tying the page to one link and one contact.
	 *
	 * @param string $slug       Smart Link slug.
	 * @param int    $contact_id FluentCRM contact ID.
	 */
	private static function key( string $slug, int $contact_id ): string {
		return substr( hash_hmac( 'sha256', 'smart-link|' . $slug . '|' . $contact_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * "j•••@example.com", so the reader can tell the link is theirs without the page showing the
	 * whole address to whoever holds the link.
	 *
	 * @param string $email Email address.
	 */
	private static function maskEmail( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || $at < 1 ) {
			return '•••';
		}

		return substr( $email, 0, 1 ) . '•••' . substr( $email, $at );
	}

	/**
	 * Prints the page and exits.
	 *
	 * @param string $title       Heading.
	 * @param string $message     Paragraph under it.
	 * @param string $button      Button label; empty for no button.
	 * @param string $form_action Escaped URL the button posts to.
	 * @param int    $status      HTTP status.
	 */
	private static function render( string $title, string $message, string $button = '', string $form_action = '', int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		include __DIR__ . '/Views/SmartLinkConfirmPage.php';
		exit;
	}
}
