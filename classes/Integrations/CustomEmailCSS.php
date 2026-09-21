<?php

namespace CustomCRM\Integrations;

/**
 * Adds a Custom CSS admin page under FluentCRM and injects the CSS into all outgoing emails.
 */
class CustomEmailCSS {

	/**
	 * Option key used to store the custom CSS.
	 */
	private const OPTION_KEY = '_customcrm_custom_email_css';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'fluentcrm-custom-css';

	/**
	 * Template types that FluentCRM filters.
	 */
	private const TEMPLATE_TYPES = [
		'simple',
		'plain',
		'classic',
		'raw_classic',
		'web_preview',
	];

	/**
	 * Register all hooks.
	 */
	public function register(): void {
		// Inject CSS into email templates.
		foreach ( self::TEMPLATE_TYPES as $type ) {
			add_filter( "fluent_crm/email-design-template-{$type}", [ $this, 'inject_css' ], 999, 3 );
		}

		// Admin menu.
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 100 );

		// Form save handler.
		add_action( 'admin_post_save_fluentcrm_custom_css', [ $this, 'handle_save' ] );

		// Enqueue CodeMirror on our page.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Inject custom CSS into email HTML before </head>.
	 *
	 * @param string              $html           The rendered email HTML.
	 * @param string              $email_body     The email body content.
	 * @param array<string,mixed> $template_config Template configuration.
	 *
	 * @return string
	 */
	public function inject_css( string $html, $email_body = '', $template_config = [] ): string {
		$css = $this->get_css();

		if ( empty( $css ) ) {
			return $html;
		}

		$css = $this->add_important( $css );

		return str_replace(
			'</head>',
			'<style>' . $css . '</style>' . "\n" . '</head>',
			$html
		);
	}

	/**
	 * Add submenu page under FluentCRM.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'fluentcrm-admin',
			esc_html__( 'Custom Email CSS', 'fluent-crm-custom-features' ),
			esc_html__( 'Custom CSS', 'fluent-crm-custom-features' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue CodeMirror assets on our admin page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		$settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );

		if ( false === $settings ) {
			return;
		}

		wp_add_inline_script(
			'code-editor',
			sprintf(
				'jQuery( function() { wp.codeEditor.initialize( "fluentcrm-custom-css", %s ); } );',
				wp_json_encode( $settings )
			)
		);
	}

	/**
	 * Handle the form save.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'fluent-crm-custom-features' ),
				esc_html__( 'Forbidden', 'fluent-crm-custom-features' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'fluentcrm_custom_css_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_css() below.
		$css = isset( $_POST['custom_css'] ) ? wp_unslash( $_POST['custom_css'] ) : '';
		$css = $this->sanitize_css( $css );

		fluentcrm_update_option( self::OPTION_KEY, $css );

		wp_safe_redirect( add_query_arg(
			[
				'page'    => self::PAGE_SLUG,
				'updated' => '1',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'fluent-crm-custom-features' ),
				esc_html__( 'Forbidden', 'fluent-crm-custom-features' ),
				[ 'response' => 403 ]
			);
		}

		$css = $this->get_css();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Custom Email CSS', 'fluent-crm-custom-features' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible" role="alert">
					<p><?php esc_html_e( 'Custom CSS saved successfully.', 'fluent-crm-custom-features' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: <code>!important</code> markup */
					esc_html__( 'Add custom CSS that will be injected into all FluentCRM email templates. %s is automatically added to every declaration so your styles override inline styles and template defaults.', 'fluent-crm-custom-features' ),
					'<code>!important</code>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fluentcrm_custom_css_save' ); ?>
				<input type="hidden" name="action" value="save_fluentcrm_custom_css">

				<label for="fluentcrm-custom-css" class="screen-reader-text">
					<?php esc_html_e( 'Custom email CSS', 'fluent-crm-custom-features' ); ?>
				</label>
				<textarea
					id="fluentcrm-custom-css"
					name="custom_css"
					rows="20"
					class="large-text code"
				><?php echo esc_textarea( $css ); ?></textarea>

				<?php submit_button( esc_html__( 'Save CSS', 'fluent-crm-custom-features' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get the stored custom CSS.
	 *
	 * @return string
	 */
	private function get_css(): string {
		return (string) fluentcrm_get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Append !important to every CSS declaration that doesn't already have it.
	 *
	 * This ensures custom styles override both inline styles and template
	 * defaults that use !important. Users write clean CSS; the flag is
	 * added automatically at injection time.
	 *
	 * @param string $css The CSS to process.
	 *
	 * @return string CSS with !important on every declaration.
	 */
	private function add_important( string $css ): string {
		// Strip any existing !important to avoid doubling.
		$css = preg_replace( '/\s*!important\b/', '', $css );

		// Match: "property: value;" — insert !important before the semicolon.
		// Uses a negative lookbehind for { and } to skip selectors/at-rules.
		// The pattern matches "anything : anything ;" which covers all declarations.
		$css = preg_replace(
			'/(:[^;{}]+?)\s*(;)/',
			'$1 !important$2',
			$css
		);

		// Handle the last declaration before } which may omit the semicolon.
		$css = preg_replace(
			'/(:[^;{}]+?)\s*(})/',
			'$1 !important;$2',
			$css
		);

		return $css;
	}

	/**
	 * Sanitize CSS input by stripping tags and dangerous CSS constructs.
	 *
	 * @param string $css Raw CSS input.
	 *
	 * @return string Sanitized CSS.
	 */
	private function sanitize_css( string $css ): string {
		$css = wp_strip_all_tags( $css );

		// Remove dangerous CSS constructs.
		$dangerous = [
			'/expression\s*\(/i',
			'/@import/i',
			'/javascript\s*:/i',
			'/-moz-binding\s*:/i',
			'/behavior\s*:/i',
		];

		$css = preg_replace( $dangerous, '', $css ) ?? '';

		// Prevent style tag breakout.
		$css = str_replace( '</', '<\/', $css );

		return trim( $css );
	}
}
