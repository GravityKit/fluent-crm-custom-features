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
restored. It then calls the sign-in return route as a temporary account and
checks that a made-up signature, an expired link and another account's link
leave the cart empty, while the signed link restores it. The account and its
saved cart are deleted in `finally`. It creates no orders and sends no mail.

The theme integration listens for `gk/magic-login/request-redirect`. Deploy this
CRM change before the matching Block-Theme checkout change. The emailed link
returns to checkout with the saved cart's row ID, an expiry and a signature tied
to the account's email; the cart key never appears in it. The route rebuilds the
cart only for that signed-in account. Normal cart-recovery URLs keep their
existing processing-only rules, click counts, offers and attribution.
