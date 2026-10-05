# fluent-crm-custom-features

Custom FluentCRM features for www.gravitykit.com: EDD cart tracking (new purchases, renewals, license upgrades), the cart email automations, and related helpers.

## Shipping

- Pushing PHP or `assets/` to `main` deploys to production (`.github/workflows/deploy.yml`). Funnel JSON and Python builders do not deploy; they are imported by hand.
- **Small fixes (a copy tweak, a one-line change) go straight to `main`**, then fast-forward `develop` to `main`. No feature PR or release PR.
- Bigger changes: a feature branch with a PR into `develop`, then an `Upcoming Release` PR from `develop` into `main`.

## Cart email automations

| Automation | Trigger | Builder |
|---|---|---|
| #53 new purchase | abandoned EDD cart | `funnels/build-edd-abandoned-cart.py` |
| #54 renewal | abandoned renewal cart | `funnels/build-edd-renewal-cart.py` |
| #55 "Abandoned upgrade (EDD) – 3 emails" | `fc_ab_cart_simulation_edd_upgrade` | `funnels/build-edd-upgrade-cart.py` |

- **Edit copy in the builder, run it (`python3 funnels/build-*.py`), commit the regenerated JSON.** Never hand-edit the JSON.
- **Live copy is stored twice** in FluentCRM: in the campaign (`wp_fc_campaigns`, e.g. 10940-10942 for #55) and in the automation step's settings (`wp_fc_funnel_sequences.settings['campaign']`). A live change must update both, and the repo builder must change in the same session, or the next re-import restores the old text without any warning.
- To change live copy safely: back up both rows, assert the old text is present exactly once in each, write, then read both back. Do it with `wp eval-file` over the `gk:ssh` wrapper.
- **Check merge tags by rendering, not by reading.** Render each email with `FluentCrm\App\Services\Libs\Parser\Parser::parse()` against a contact that has a cart, and assert no `{{` or `##` remains and no empty `<p>`. To see the real email, send a test with the FluentCRM MCP `send-test-email` (`campaign_id` plus `against_contact_id`); test sends don't enroll anyone or log to email history.
- Test fixture on live: contact 4288 (zack@katz.co) with upgrade cart 32 (GravityImport license 21494 to All Access Pass).

### Who gets cart emails

- `AllowedDomains` (option `customcrm_edd_ab_cart_allowed_domains`, currently `gravitykit.com, katz.co`) holds back every cart from other addresses. `CartEmailGuard` cancels any send to an address outside the list. As of 2026-10-05 no customer has received a cart email.
- Staff roles are excluded from cart tracking, but only when logged in. A cart saved logged out under a team address runs the full sequence.
- Conversion Bridge does not track logged-in admins. Test purchases for tracking must be made logged out.

### Known issues before opening the allowlist to customers

- **Upgrade emails don't check license ownership.** Software Licensing lets any email check out an upgrade for any license key, and the upgrade driver doesn't check the cart email owns the license. An email can tell someone about "your current license" when it isn't theirs.
- On live cart 32 (2026-09-30) the 60-minute wait before upgrade email 1 took 29 seconds. It was probably forced by a test; confirm before relying on the timing.

## Upgrade email wording

- `{{ab_cart_edd_upgrade.new_plan_name}}` names the new plan mid-sentence. Another product: its name ("All Access Pass"). Same product: what the upgrade adds, worked out from Software Licensing's site limits (0 = unlimited) and lifetime flags: "a GravityImport plan with more sites", "a lifetime GravityImport plan", "a lifetime GravityImport plan with more sites", else "a different GravityImport plan". Never "the bigger {product} plan": it doesn't say what is bigger, and is wrong for a same-size lifetime upgrade.
- Upgrade prices are computed when each email sends; with time-based proration they drop a little every day.

## Copy rules (Zack)

- **Ask, don't order.** When the email asks the reader to do something for us, say please: "If someone else approves purchases, please forward them this email." Offers of help stay as written ("just reply and we'll sort it out").
- A subject capitalizes the word after a colon: "Last reminder: You were about to upgrade to …".
- An email that may be forwarded opens by saying what was left unfinished, so a reader with no context knows what it is about.
