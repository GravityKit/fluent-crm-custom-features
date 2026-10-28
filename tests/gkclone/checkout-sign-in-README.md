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
checks that a made-up signature, an expired link, another account's link and a
cart saved over the row after the link was sent all leave the cart empty, while
the signed link restores it. It also checks that the browser that asked for the
link keeps its live cart, and that a sign-in never rebuilds a renewal of a
license the account does not own (skipped, and the PASS line says so, on a site
without a Software Licensing license). The account and its saved carts are
deleted in `finally`. It creates no orders and sends no mail.

The theme integration listens for `gk/magic-login/request-redirect`. Deploy this
CRM change before the matching Block-Theme checkout change. The emailed link
returns to checkout with the saved cart's row ID, an expiry and a signature tied
to the account's email and to the cart's items and coupons when the link was
sent; the cart key never appears in it. Saving the cart for the link sets no
cookie, since the filter runs only for addresses that may use magic links.

The signature proves the link reached that inbox, not that the account owner
built the cart: anyone can type any address at checkout. So the route rebuilds
the cart only for that signed-in account, skips renewals of licenses the account
does not own, and leaves the cart alone in the browser that asked for the link,
which already holds the live cart. Normal cart-recovery URLs keep their existing
processing-only rules, click counts, offers and attribution.
