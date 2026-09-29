<?php

namespace CustomCRM\AbandonCart\Edd;

/**
 * FluentCRM > Cart Discounts: edits the recovery discount profiles that abandoned-cart emails use.
 */
class DiscountSettingsPage {

	private const PAGE_SLUG = 'fluentcrm-cart-discounts';

	private const ACTION = 'customcrm_save_cart_discounts';

	private const NONCE = 'customcrm_cart_discounts_save';

	/**
	 * Hooks the admin page and its save handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPage' ], 100 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handleSave' ] );
	}

	/**
	 * Adds the page under the FluentCRM menu.
	 */
	public function addMenuPage(): void {
		add_submenu_page(
			'fluentcrm-admin',
			esc_html__( 'Cart Recovery Discounts', 'fluent-crm-custom-features' ),
			esc_html__( 'Cart Discounts', 'fluent-crm-custom-features' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Saves the submitted profiles and returns to the page.
	 */
	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'fluent-crm-custom-features' ),
				esc_html__( 'Forbidden', 'fluent-crm-custom-features' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized by EddRecoveryDiscount::saveProfiles().
		$posted = isset( $_POST['profiles'] ) ? (array) wp_unslash( $_POST['profiles'] ) : [];

		EddRecoveryDiscount::saveProfiles( self::profilesFromForm( $posted ) );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Turns the submitted rows into profiles keyed by slug: rows marked for deletion are dropped
	 * (never the default), and the blank row adds a profile when it has a slug.
	 *
	 * @param array<string,array<string,string>> $rows Keyed by slug; `__new` is the blank row.
	 * @return array<string,array<string,string>>
	 */
	public static function profilesFromForm( array $rows ): array {
		$profiles = [];

		foreach ( $rows as $key => $row ) {
			$row   = (array) $row;
			$typed = is_scalar( $row['slug'] ?? '' ) ? (string) ( $row['slug'] ?? '' ) : '';
			$slug  = sanitize_key( '__new' === $key ? $typed : (string) $key );

			// `__new` names the blank row itself, so it cannot be a profile's slug.
			$is_unusable = '' === $slug || '__new' === $slug;
			$is_deleted  = ! empty( $row['delete'] ) && EddRecoveryDiscount::DEFAULT_PROFILE !== $slug;

			if ( $is_unusable || $is_deleted || isset( $profiles[ $slug ] ) ) {
				continue;
			}

			$profiles[ $slug ] = $row;
		}

		return $profiles;
	}

	/**
	 * Renders the profiles table.
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'fluent-crm-custom-features' ),
				esc_html__( 'Forbidden', 'fluent-crm-custom-features' ),
				[ 'response' => 403 ]
			);
		}

		$profiles = EddRecoveryDiscount::savedProfiles();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cart Recovery Discounts', 'fluent-crm-custom-features' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible" role="alert">
					<p><?php esc_html_e( 'Discounts saved.', 'fluent-crm-custom-features' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: smart code for the default profile, 2: smart code for a named profile */
					esc_html__( 'Each abandoned cart gets its own single-use EDD code, created when an email first shows it. The default profile backs %1$s; the others back %2$s.', 'fluent-crm-custom-features' ),
					'<code>{{ab_cart_edd.recovery_discount_code}}</code>',
					'<code>{{ab_cart_edd.discount.&lt;slug&gt;.code}}</code>'
				);
				?>
			</p>
			<p><?php esc_html_e( 'Changes apply to codes created from now on. Before deleting a profile, check that no automation email still uses its smart codes.', 'fluent-crm-custom-features' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Slug', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Label', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Type', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Expires after (hours, 0 = never)', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Minimum order', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Code prefix', 'fluent-crm-custom-features' ); ?></th>
							<th><?php esc_html_e( 'Delete', 'fluent-crm-custom-features' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $profiles as $slug => $profile ) : ?>
							<?php $this->renderRow( $slug, $profile ); ?>
						<?php endforeach; ?>
						<?php $this->renderRow( '__new', [] ); ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save discounts', 'fluent-crm-custom-features' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One profile row; `__new` renders the blank row for adding a profile.
	 *
	 * @param string              $slug    Profile slug, or `__new`.
	 * @param array<string,mixed> $profile Saved values; empty for the blank row.
	 */
	private function renderRow( string $slug, array $profile ): void {
		$is_new     = '__new' === $slug;
		$is_default = EddRecoveryDiscount::DEFAULT_PROFILE === $slug;
		$name       = 'profiles[' . $slug . ']';
		$type       = $profile['type'] ?? 'percent';
		?>
		<tr>
			<td>
				<?php if ( $is_new ) : ?>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[slug]" value="" placeholder="<?php esc_attr_e( 'new_profile', 'fluent-crm-custom-features' ); ?>" class="regular-text" style="width:10em">
				<?php else : ?>
					<code><?php echo esc_html( $slug ); ?></code>
				<?php endif; ?>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $profile['label'] ?? '' ); ?>" class="regular-text"></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[type]">
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percent', 'fluent-crm-custom-features' ); ?></option>
					<option value="flat" <?php selected( $type, 'flat' ); ?>><?php esc_html_e( 'Flat amount', 'fluent-crm-custom-features' ); ?></option>
				</select>
			</td>
			<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[amount]" value="<?php echo esc_attr( $profile['amount'] ?? '' ); ?>" style="width:6em"></td>
			<td><input type="number" step="1" min="0" name="<?php echo esc_attr( $name ); ?>[expiry_hours]" value="<?php echo esc_attr( $profile['expiry_hours'] ?? '' ); ?>" style="width:6em"></td>
			<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $name ); ?>[min_amount]" value="<?php echo esc_attr( $profile['min_amount'] ?? '' ); ?>" style="width:6em"></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[prefix]" value="<?php echo esc_attr( $profile['prefix'] ?? 'CART' ); ?>" style="width:6em"></td>
			<td>
				<?php if ( ! $is_new && ! $is_default ) : ?>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: profile slug */ __( 'Delete %s', 'fluent-crm-custom-features' ), $slug ) ); ?>">
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
