# fluent-crm-custom-features

Custom FluentCRM features for www.gravitykit.com: EDD cart tracking (new purchases, renewals, license upgrades), the cart email automations, and related helpers.

Team-facing overview (what the flows send, who is held back, how to change copy): the private doc [Abandoned cart emails](https://www.gravitykit.com/docs/internal/email-newsletter/abandoned-cart-emails/). Keep it in step with changes here.

## Shipping

- Pushing PHP or `assets/` to `main` deploys to production (`.github/workflows/deploy.yml`). Funnel JSON and Python builders do not deploy; they are imported by hand.
- **Small fixes (a copy tweak, a one-line change) go straight to `main`**, then fast-forward `develop` to `main`. No feature PR or release PR.
- Bigger changes: a feature branch with a PR into `develop`, then an `Upcoming Release` PR from `develop` into `main`.
- **For about a minute after a deploy, some live requests still run the old code.** Two checks on 2026-10-08 and 2026-10-09 returned the old redirect first and the new one on retry. Repeat a live check a few times with a fresh query string before calling a deploy broken.
- **Recovery links keep the email's `utm_*`.** `EddCartTracking::emailCheckoutUrl()` copies them onto the checkout redirect, filling only missing tags with `fluentcrm` / `email` / `cart-recovery`, and adds `utm_content` (cart type) and `fc_ab_cart` (cart row ID). The link itself only redirects, so analytics first loads on checkout.

## Cart email automations

| Automation | Trigger | Builder |
|---|---|---|
| #53 new purchase | abandoned EDD cart | `funnels/build-edd-abandoned-cart.py` |
| #54 renewal | abandoned renewal cart | `funnels/build-edd-renewal-cart.py` |
| #55 "Abandoned upgrade (EDD) – 3 emails" | `fc_ab_cart_simulation_edd_upgrade` | `funnels/build-edd-upgrade-cart.py` |

- **Edit copy in the builder, run it (`python3 funnels/build-*.py`), commit the regenerated JSON.** Never hand-edit the JSON.
- **Live copy is stored twice** in FluentCRM: in the campaign (`wp_fc_campaigns`: 10933-10935 for #53, 10936-10937 for #54, 10940-10942 for #55) and in the automation step's settings (`wp_fc_funnel_sequences.settings['campaign']`). Sends read the campaign; the step copy is what FluentCRM's editor and a re-import write back. A live change must update both, and the repo builder must change in the same session. On 2026-10-06 all eight step copies were found stale after campaign-only updates and were synced (backup: `MonoKit/Operations/Backups/cart-automation-steps-20261006.json`).
- To change live copy safely: back up both rows, assert the old text is present exactly once in each, write, then read both back. Do it with `wp eval-file` over the `gk:ssh` wrapper.
- **Check merge tags by rendering, not by reading.** Render each email with `FluentCrm\App\Services\Libs\Parser\Parser::parse()` against a contact that has a cart, and assert no `{{` or `##` remains and no empty `<p>`. To see the real email, send a test with the FluentCRM MCP `send-test-email` (`campaign_id` plus `against_contact_id`); test sends don't enroll anyone or log to email history.
- Test fixture on live: contact 4288 (zack@katz.co) with upgrade cart 32 (GravityImport license 21494 to All Access Pass).

### Who gets cart emails

- `AllowedDomains` (option `customcrm_edd_ab_cart_allowed_domains`) holds back every cart from addresses outside the listed domains, and `CartEmailGuard` cancels any send to them. **Empty since 2026-10-05, so every shopper is emailed.** Set it again to go back to internal-only testing.
- Held back before an automation starts (`isWithinCoolOffPeriod` on each driver): the allowlist, `CartEmailStop::holdBack()` (used the stop link within 30 days), FluentCRM's cool-off (bought within `cool_off_period_days`, 10 on live), and the resend cap (21 days; 60 for upgrades). The resend cap also reads `PriorRecipients` (option `customcrm_edd_ab_cart_prior_recipients`), which is **empty on live**, so Recapture's last recipients are not covered.
- Staff roles are excluded from cart tracking, but only when logged in. A cart saved logged out under a team address runs the full sequence.
- Conversion Bridge does not track logged-in admins. Test purchases for tracking must be made logged out.

### Stop link (`CartEmailStop`)

- Every cart email ends with `stop_line()` from `funnels/email_blocks.py`, linking `##ab_cart_*.stop_url##`.
- The link opens a confirmation page; the stop happens on the form post, because mail scanners open every link. Confirming opts out the cart and the contact's other open carts, cancels unsent cart emails, and sets contact meta `ab_cart_emails_stopped_at`; `holdBack()` then holds new carts for 30 days (`customcrm/edd_ab_cart/stop_days`).
- **The page is served from `/wp-json/gk-cart/v1/stop`, never from `/?…`.** On live, any 200 at the home URL comes back `public, max-age=60` with a month-long `Expires` whatever WordPress sends, and Cloudflare serves stale copies. `/wp-json/`, `/checkout/` and `/account/` stay `no-store`. `admin-post.php` is uncached too but prints other plugins' admin notices above the page. Links sent before the fix point at the home URL and are still handled.
- The stop line is a classed `div` in a raw HTML block: the FluentCRM template forces `margin-bottom` with `!important` on `p` and on unclassed top-level `div`.

### Re-running a contact through an automation

Deleting the old `fc_funnel_subscribers` run is not enough. FluentCRM skips any step that already has an `fc_funnel_metrics` row for that funnel, sequence and contact, so the new run advances and sends nothing. Delete the contact's old metrics rows for the funnel too.

### Known issues

- **Upgrade emails don't check license ownership.** Software Licensing lets any email check out an upgrade for any license key, and the upgrade driver doesn't check the cart email owns the license. An email can tell someone about "your current license" when it isn't theirs.
- On live cart 32 (2026-09-30) the 60-minute wait before upgrade email 1 took 29 seconds. It was probably forced by a test; confirm before relying on the timing.

## Upgrade email wording

- `{{ab_cart_edd_upgrade.new_plan_name}}` names the new plan mid-sentence. Another product: its name ("All Access Pass"). Same product: what the upgrade adds, worked out from Software Licensing's site limits (0 = unlimited) and lifetime flags: "a GravityImport plan with more sites", "a lifetime GravityImport plan", "a lifetime GravityImport plan with more sites", else "a different GravityImport plan". Never "the bigger {product} plan": it doesn't say what is bigger, and is wrong for a same-size lifetime upgrade.
- Upgrade prices are computed when each email sends; with time-based proration they drop a little every day.

## Copy rules (Zack)

- **Ask, don't order.** When the email asks the reader to do something for us, say please: "If someone else approves purchases, please forward them this email." Offers of help stay as written ("just reply and we'll sort it out").
- A subject capitalizes the word after a colon: "Last reminder: You were about to upgrade to …".
- An email that may be forwarded opens by saying what was left unfinished, so a reader with no context knows what it is about.
- Open with "Hi {first name}", never "Hey". No em dashes, in subjects, preheaders or body; use a colon or a new sentence.
- Write to what the shopper is trying to get done, not a feature list ("toolbox of add-ons", "drag-and-drop" were cut). The new-purchase emails follow the Hidden Multipliers buyer research: someone asked for something the entries list can't produce, she values building it without a developer, and what stops her is doubting it fits her case.
- Anything that must not wrap away from its words (an emoji at the end of a sentence) goes behind `&nbsp;`.

## Email markup (FluentCRM)

- **FluentCRM rebuilds block styles when it sends, so check sizes and spacing in a real send, not in the builder output.** A paragraph's `"fontSize":"small"` preset renders as `font-size: var(--wp--preset--font-size--small)`, which no email defines, so the text stays at the 16px body size; set `"style":{"typography":{"fontSize":"14px"}}` instead. The template also forces `margin-bottom` with `!important` on every `p` (14px) and on unclassed top-level `div`s (10px), so spacing you need exactly goes in a `raw()` block with a classed `div`. Measure in a browser against the Mailpit copy (`getBoundingClientRect`, and CDP `CSS.getMatchedStylesForNode` to find which rule wins).
