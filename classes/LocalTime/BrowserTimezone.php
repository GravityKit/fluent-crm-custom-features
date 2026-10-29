<?php

namespace CustomCRM\LocalTime;

use FluentCrm\App\Models\Subscriber;
use WP_User;

/**
 * Saves the browser's time zone to the signed-in person's FluentCRM contact.
 *
 * Logged-in pages and the login page set a cookie with the browser's zone; the next logged-in
 * request, or the login itself, copies it to the contact. Limited to pages that are never cached,
 * so the cookie cannot change how cached pages are served.
 */
class BrowserTimezone {

	public const COOKIE = 'gk_tz';

	/**
	 * The zone last saved for this user, so an unchanged zone costs no contact query.
	 */
	public const USER_META = 'customcrm_browser_timezone';

	/**
	 * Runs during `init`, so the current request is handled here rather than on a later hook.
	 */
	public function register(): void {
		add_action( 'wp_footer', [ $this, 'printScript' ], 99 );
		add_action( 'login_footer', [ $this, 'printScript' ], 99 );
		add_action( 'wp_login', [ $this, 'saveOnLogin' ], 10, 2 );

		if ( is_user_logged_in() ) {
			$this->save( wp_get_current_user() );
		}
	}

	public function printScript(): void {
		if ( 'login_footer' !== current_action() && ! is_user_logged_in() ) {
			return;
		}

		$cookie = esc_js( self::COOKIE );
		?>
		<script>
		(function () {
			try {
				var zone = encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone || '');
				if (zone && document.cookie.indexOf('<?php echo $cookie; ?>=' + zone) === -1) {
					document.cookie = '<?php echo $cookie; ?>=' + zone + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
				}
			} catch (e) {}
		})();
		</script>
		<?php
	}

	public function saveOnLogin( $user_login, $user ): void {
		if ( $user instanceof WP_User ) {
			$this->save( $user );
		}
	}

	public function save( WP_User $user ): void {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}

		$zone = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );

		if ( ! SendTime::isZoneName( $zone ) || get_user_meta( $user->ID, self::USER_META, true ) === $zone ) {
			return;
		}

		$contact = Subscriber::where( 'user_id', $user->ID )->first();

		if ( ! $contact && $user->user_email ) {
			$contact = Subscriber::where( 'email', $user->user_email )->first();
		}

		// No contact yet: leave the user meta unset so the zone is saved once one exists.
		if ( ! $contact ) {
			return;
		}

		// Query-builder update: skips model events, so a time zone change runs no FluentCRM contact hooks.
		Subscriber::where( 'id', $contact->id )->update( [ 'timezone' => $zone ] );

		update_user_meta( $user->ID, self::USER_META, $zone );
	}
}
