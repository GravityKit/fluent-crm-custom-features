<?php
/**
 * Cart items for abandoned-cart emails ({{ab_cart_edd.cart_items_table}}).
 *
 * Inline styles and a single table, so it renders the same in Outlook and Gmail.
 *
 * @var array<int,array<string,mixed>>      $cart_items
 * @var string                              $currency
 * @var \CustomCRM\AbandonCart\Edd\EddCartDriver $driver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $cart_items ) ) {
	return;
}
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;">
	<?php foreach ( $cart_items as $cart_item ) : ?>
		<tr>
			<?php if ( ! empty( $cart_item['product_image'] ) ) : ?>
				<?php // The email template inlines box-sizing:border-box and img{max-width:100%}, so the cell width includes its padding. ?>
				<td width="76" style="width:76px;min-width:76px;padding:12px 12px 12px 0;border-bottom:1px solid #e5e7eb;vertical-align:middle;">
					<img src="<?php echo esc_url( $cart_item['product_image'] ); ?>" width="64" height="64" alt="" style="display:block;width:64px;min-width:64px;max-width:64px;height:64px;border-radius:6px;object-fit:cover;">
				</td>
			<?php endif; ?>
			<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;vertical-align:middle;">
				<?php if ( ! empty( $cart_item['product_url'] ) ) : ?>
					<a href="<?php echo esc_url( $cart_item['product_url'] ); ?>" style="font-weight:600;text-decoration:none;"><?php echo esc_html( $cart_item['title'] ); ?></a>
				<?php else : ?>
					<strong><?php echo esc_html( $cart_item['title'] ); ?></strong>
				<?php endif; ?>
				<?php if ( (int) $cart_item['quantity'] > 1 ) : ?>
					<span style="color:#6b7280;"> &times; <?php echo (int) $cart_item['quantity']; ?></span>
				<?php endif; ?>
			</td>
			<td align="right" style="padding:12px 0 12px 12px;border-bottom:1px solid #e5e7eb;vertical-align:middle;white-space:nowrap;">
				<?php echo esc_html( html_entity_decode( wp_strip_all_tags( $driver->formatPrice( $cart_item['line_total'], $currency ) ) ) ); ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>
