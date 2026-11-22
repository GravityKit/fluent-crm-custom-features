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
 *
 * A tracked email click puts its contact in the address, signed. A direct link's address names
 * nobody (`c=0`): the page reads the contact from FluentCRM's cookie or the login, so the redirect
 * from the link, which is the same address for every visitor, never carries one contact's page.
 */
class SmartLinkConfirm {

	public const REST_NAMESPACE = 'gk-smart-link/v1';

	public const REST_ROUTE = '/confirm';

	/**
	 * The address in emails. It loads the REST route through a rewrite rule; the `/wp-json/` address
	 * keeps working for links already sent.
	 */
	public const PRETTY_PATH = 'email/confirm';

	/**
	 * Hooks the page.
	 */
	public static function register(): void {
		// Called on `init`, so the rule is added now. A missing rule means the site's saved rules
		// predate it: flush once so the address works without a visit to Settings > Permalinks.
		$rule = '^' . self::PRETTY_PATH . '/?$';
		add_rewrite_rule( $rule, 'index.php?rest_route=/' . self::REST_NAMESPACE . self::REST_ROUTE, 'top' );

		$saved_rules = (array) get_option( 'rewrite_rules', [] );
		if ( ! isset( $saved_rules[ $rule ] ) && ! get_transient( 'customcrm_smart_link_rewrite_flushed' ) ) {
			set_transient( 'customcrm_smart_link_rewrite_flushed', 1, HOUR_IN_SECONDS );
			flush_rewrite_rules( false );
		}

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
	 * @param int       $contact_id FluentCRM contact ID from a tracked email click, or 0 for the
	 *                              page to read the contact from the visitor's cookie or login.
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

		return add_query_arg( $args, home_url( '/' . self::PRETTY_PATH . '/' ) );
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
		$forwarded  = wp_unslash( $_GET['fw'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by forwardable(), after parsing: sanitizing first strips encoded characters such as the @ in an address.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( is_string( $forwarded ) ) {
			parse_str( $forwarded, $forward );
		}

		// Anyone can write this part of the address, so FluentCRM's own parameters are dropped again.
		$forward = SmartLinkHandler::forwardable( $forward );

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

		$contact = $contact_id ? Subscriber::find( $contact_id ) : self::currentContact();

		if ( ! $contact ) {
			self::render(
				__( 'Nothing has changed yet', 'fluent-crm-custom-features' ),
				__( 'We couldn’t tell which email address this link is for. Please reply to the email we sent you and we’ll take care of it.', 'fluent-crm-custom-features' )
			);
		}

		$copy       = self::copy( $smart_link );
		$is_post    = 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
		$is_pressed = $is_post && self::isButtonPress( $slug, (int) $contact->id );

		if ( $is_pressed ) {
			$handler = new SmartLinkHandler();
			$handler->applyConfirmed( $smart_link, $contact );
			self::track( $smart_link );

			nocache_headers();
			wp_safe_redirect( $handler->getTargetUrl( $smart_link, $contact, $forward ), 303 );
			exit;
		}

		$message = $copy['message'] . ' ' . sprintf(
			/* translators: %s: masked email address */
			__( 'This is for %s.', 'fluent-crm-custom-features' ),
			self::maskEmail( (string) $contact->email )
		);

		self::render( $copy['title'], $message, $copy['button'], esc_url( self::url( $smart_link, $contact_id, $forward ) ), 200, self::formFields( $slug, (int) $contact->id ) );
	}

	/**
	 * Records the button press in analytics through the site's Conversion Bridge wrapper (Block-Theme
	 * mu-plugin `inc/plugins/conversion-bridge.php`). The event is queued for the target page the
	 * press redirects to, which sends it to GA4, PostHog and Google Ads. Page views are not tracked:
	 * scanners open this page. Nothing happens where the wrapper is not loaded.
	 *
	 * @param SmartLink $smart_link The Smart Link.
	 */
	private static function track( SmartLink $smart_link ): void {
		do_action(
			'gk/email_link/confirmed',
			[
				'link_type'  => 'smart_link',
				'link'       => (string) $smart_link->short,
				'link_title' => (string) $smart_link->title,
			],
			true
		);
	}

	/**
	 * The hidden fields the page's form posts: when the page was made, signed for this link and
	 * contact. A post counts only with them, so a bare POST to the address does nothing, and nor
	 * does a form on another site posting with the visitor's cookies.
	 *
	 * @param string   $slug       Smart Link slug.
	 * @param int      $contact_id FluentCRM contact ID.
	 * @param int|null $time       Unix time the page was made; null for now.
	 *
	 * @return array{t:int,s:string}
	 */
	public static function formFields( string $slug, int $contact_id, ?int $time = null ): array {
		$time = $time ?? time();

		return [
			't' => $time,
			's' => self::sign( 'smart-link-form|' . $slug . '|' . $contact_id . '|' . $time ),
		];
	}

	/**
	 * Whether this POST came from the page's own form, made within the last day. No minimum wait:
	 * scanners open links but don't submit forms, and a wait would bounce people who click fast.
	 *
	 * @param string $slug       Smart Link slug.
	 * @param int    $contact_id FluentCRM contact ID.
	 */
	private static function isButtonPress( string $slug, int $contact_id ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The signed fields are the check.
		$time      = absint( $_POST['t'] ?? 0 );
		$signature = sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$is_ours = hash_equals( self::formFields( $slug, $contact_id, $time )['s'], $signature );
		$age     = time() - $time;

		return $is_ours && $age >= 0 && $age <= DAY_IN_SECONDS;
	}

	/**
	 * The visitor's contact, from the login or FluentCRM's cookie.
	 *
	 * @return Subscriber|null
	 */
	private static function currentContact() {
		// REST logs out a cookie login that sends no REST nonce, and a plain link sends none.
		if ( ! get_current_user_id() ) {
			$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );

			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		return fluentcrm_get_current_contact();
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
		return self::sign( 'smart-link|' . $slug . '|' . $contact_id );
	}

	/**
	 * Signs a string with the site's secret.
	 *
	 * @param string $data What to sign.
	 */
	private static function sign( string $data ): string {
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 32 );
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
	 * @param array  $fields      Hidden form fields, name => value.
	 */
	private static function render( string $title, string $message, string $button = '', string $form_action = '', int $status = 200, array $fields = [] ): void {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// The address can act for a contact, so it is not sent on as a referrer (analytics on the
		// target page would store it), and no other site can put the button in a frame.
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );

		include __DIR__ . '/Views/SmartLinkConfirmPage.php';
		exit;
	}
}
