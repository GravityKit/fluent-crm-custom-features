<?php
/**
 * Page for the cart emails' "Stop these emails" link.
 *
 * @var string $title
 * @var string $message
 * @var string $form_action Escaped URL; empty for no button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title ); ?> – GravityKit</title>
	<style>
		body { margin: 0; background: #f4f5f7; color: #1f2937; font: 17px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		.band { background: #001e45; padding: 28px 16px; text-align: center; }
		.band img { width: 200px; height: auto; }
		.card { max-width: 520px; margin: 40px auto; padding: 32px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
		h1 { margin: 0 0 12px; font-size: 24px; line-height: 1.3; }
		p { margin: 0 0 24px; }
		button { appearance: none; border: 0; border-radius: 6px; padding: 12px 22px; background: #1877f2; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
		button:hover, button:focus-visible { background: #0f5fc7; }
		.home { display: inline-block; margin-top: 4px; color: #4b5563; }
		@media (max-width: 560px) { .card { margin: 16px; padding: 24px; } }
	</style>
</head>
<body>
	<div class="band">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="https://www.gravitykit.com/wp-content/uploads/2026/02/logo-5.png" alt="GravityKit"></a>
	</div>
	<main class="card">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<?php if ( $form_action ) : ?>
			<form method="post" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller ?>">
				<button type="submit"><?php esc_html_e( 'Stop these emails', 'fluent-crm-custom-features' ); ?></button>
			</form>
		<?php else : ?>
			<a class="home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to GravityKit.com', 'fluent-crm-custom-features' ); ?></a>
		<?php endif; ?>
	</main>
</body>
</html>
