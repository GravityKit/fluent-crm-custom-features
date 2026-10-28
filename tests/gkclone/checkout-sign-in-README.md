# Checkout sign-in restoration checks

Use a disposable Siteminter site with EDD Pro, FluentCRM and this checkout of
`fluent-crm-custom-features` activated. The test refuses remote hosts and a
nonempty cart. It installs EDD's native component tables if the fresh site has
not created its lazy log tables yet.

Copy `checkout-sign-in-checks.php` into the site's CLI container, then run:

```sh
wp eval-file /tmp/checkout-sign-in-checks.php
```

It exercises the shared cart reconstruction against real EDD: product and price
variation, quantity, original discount, unchanged draft/recovery metrics, and
cleanup of the restoration flag after a native cart-add exception. Its temporary
product and unique coupon are deleted in `finally`; the quantity setting is
restored. It creates no orders and sends no mail.

The theme integration listens for `gk/magic-login/request-redirect`. Deploy this
CRM change before the matching Block-Theme checkout change. The authenticated
`gk_checkout_cart` return route rebuilds only a saved cart matching the signed-in
account; normal cart-recovery URLs retain their existing processing-only rules,
click counts, offers and attribution.
