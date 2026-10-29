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

## Local-time sending (`classes/LocalTime/`)

Delivers a campaign on its picked date, inside a window (8:00-11:00 by default) in each recipient's own zone. It is local-time delivery. It does not send "when each person normally opens"; our data cannot show that.

- **Panel:** in FluentCRM's schedule step, pick "Schedule the emails" and tick "Deliver in each person's local time". `LocalTimePanel` adds it to the DOM, because FluentCRM's admin app has no extension point for campaign fields; it anchors on the `schedule` radio's value and is printed inline, since no-conflict mode drops other plugins' script files. It saves campaign meta `_gk_local_time` through `customcrm/v1/local-time/<id>` (permission `fcrm_manage_emails`).
  - Settings are frozen unless the campaign is a `draft`.
  - The holdout field is the percent sent on the normal schedule (0-50, default 0 = off).
  - The Schedule button is blocked while a save is running or has failed, and when the date is too close. The message names the earliest date that works (`LocalTimeSending::feasibility()`).
  - Saves are queued one at a time, and a stale response never overwrites a newer choice.
- **Scheduling:** `LocalTimeSending::onScheduled()` (on `fluent_crm/campaign_scheduled`) stores `picked_at` (the time the person chose), the delivery `date`, a new `generation` and the `anchor`, then sets the campaign's `scheduled_at` to the anchor: when the window first opens anywhere (UTC+14), via `SendTime::firstWindowOpens()`.
  - Feasibility: the window must open 2 or more hours from now. Otherwise the campaign goes back to `draft` (guarded on `pending-scheduled`) and the reason is saved in `_gk_local_time_result`. The panel blocks the same dates in advance.
  - Campaigns that use a sending time range are skipped.
  - A campaign put back to `draft` here emails the site admin once per picked time (FluentCRM has already told the person it was scheduled). The panel blocks the same dates, so this only fires when the panel did not (MCP, REST, a tab left open, a panel that did not load).
- **Moving the rows:** FluentCRM 3.2 has no hook for a row's send time. Rows are made by bulk INSERTs that skip model events, 6 minutes before the send time, and released together when the last one exists. So `LocalTimeSending::advance()` flips the campaign to `processing` an hour early, runs FluentCRM's `CampaignProcessor`, then `Spreader::spread()` moves each released row's `scheduled_at` to its local time while the anchor is still ahead.
- **Tick:** `LocalTimeSending::tick()` runs on `fluentcrm_process_scheduled_tasks_init`. FluentCRM's `Scheduler::process()` fires it for the Action Scheduler tick, the WP-Cron fallback and the admin-ping fallback alike. It holds the lock `_customcrm_lock_local_time`, so two runs never overlap. With no local-time campaign waiting it costs one query. Nothing thrown inside it gets out (it would skip that minute's sending for every campaign): each campaign's step is caught on its own, logged, and retried next minute; within 2 minutes of the anchor a campaign that throws falls back instead (`failSafe()`).
- **Fallback:** if the rows cannot be given local times before the anchor (queueing unfinished, a `Spreader` error, or under 2 minutes left), `LocalTimeSending::fallBack()` sends the campaign normally at `picked_at` (never before the anchor) and emails the site admin once per generation (`_gk_local_time_alerted`). No pause. Stale workers cannot mark a newer generation done (`_gk_local_time_done`). `Spreader` stops 15 seconds before the anchor and returns false, so rows it did not reach are moved by the fallback instead of going out at the anchor.
- **Outside schedule edits:** FluentCRM's `CampaignController::updateCampaignTitle()` changes `scheduled_at` without firing `campaign_scheduled`. `tick()` sees a time that is not the anchor and calls `replan()`: new date, new generation, rows moved with it; if the new date is too close, a waiting campaign goes back to draft and a queued one falls back.
- **Send now:** `LocalTimeSending::forgetDate()` (on `fluent_crm/campaign_set_send_now`) clears the stored date.
- **MCP resume guard:** FluentCRM's MCP `change-campaign-status` resume sets every paused row to now. For a local-time campaign, `LocalTimeSending::guardMcpResume()` resumes it the way the campaign screen does (`CampaignController::resumeCampaign()`), keeping each row's time. It hooks `wp_register_ability_args`, so it works only on WordPress 6.9+ with the Abilities API and the MCP adapter (without them there is no MCP resume at all). The filter is added by the bootstrap file at load time, not in `register()`: the MCP adapter creates the abilities registry during `init`, before the priority-99 callback, and abilities registered before the filter exists are never wrapped.
- **Zone order** (`SendTime::resolveZone()`, which returns the zone and its source):
  1. The contact's `timezone` field (source `contact`; about 5,300 subscribed contacts, from the 2021 import). `BrowserTimezone` fills it from the browser for logged-in visitors and at login, through cookie `gk_tz`.
  2. State or province for US, Canada and Australia, as a code or a full name (`state`).
  3. A country with several zones and no state gets its most populated zone: US Eastern, CA Toronto, AU Sydney, BR Sao Paulo, RU Moscow, MX Mexico City, and so on (`SendTime::COUNTRY_ZONES`); other countries use PHP's first zone (`country`).
  4. The site zone, America/Denver (`site`).
  - Steps 2 and 3 first turn a country written as a name or three-letter code ("United States", "USA", "Germany") into its two-letter code, from `SendTime::COUNTRY_ALIASES` and FluentCRM's `fluent_crm/countries` list (passed in by `Spreader`).
- **Daylight saving:** FluentCRM sends a row when `scheduled_at <= current_time('mysql')`, a string compare of site wall-clock time, and that string repeats when clocks go back. `SendTime::siteTimeNotBefore()` picks the string that cannot match before the intended moment. Every stored `scheduled_at` goes through it.
- **Holdout:** `SendTime::isHoldout()` picks recipients by a hash of campaign and contact, separate from the slot hash. They go out at the window start in the site zone on the delivery date (the normal schedule).
- **Tables (never updated or pruned by this plugin):**
  - `customcrm_local_time_sends` (`AssignmentLog`): one row per recipient written by `Spreader`: zone, zone source, slot minutes (NULL for holdout), holdout flag, intended time (UTC), stored site time.
  - `customcrm_email_clicks` (`ClickLog`, on `fluent_crm/email_url_clicked`): one row per click with IP and user agent, kept indefinitely. FluentCRM keeps only the first click per link, so scanner and person cannot be told apart without it. Anonymous-tracking clicks are skipped.
  - Both are created on demand with `dbDelta` behind a version option.
- **After a FluentCRM update,** run the checks below before the next local-time campaign. The plugin relies on `CampaignProcessor::processEmails()`, `Helper::acquireDbLock()`, the `fc_campaign_emails` columns, `fluentcrm_process_scheduled_tasks_init`, `fluent_crm/campaign_scheduled`, `fluent_crm/email_url_clicked` and the MCP ability name `fluent-crm/change-campaign-status`. If the panel's anchors in the schedule step change, the checkbox does not show; a campaign already set to local time still plans normally.
- Checks: `tests/gkclone/local-time-checks.php`, run with `wp eval-file` in a gkclone container, inside a rolled-back transaction. Also `docker cp fluent-crm-custom-features.php` to `/tmp/` in the container, or the check that the bootstrap adds the MCP guard at load time is skipped. The panel itself was checked in a headless browser against FluentCRM 3.2.0's schedule step.

## Copy rules (Zack)

- **Ask, don't order.** When the email asks the reader to do something for us, say please: "If someone else approves purchases, please forward them this email." Offers of help stay as written ("just reply and we'll sort it out").
- A subject capitalizes the word after a colon: "Last reminder: You were about to upgrade to …".
- An email that may be forwarded opens by saying what was left unfinished, so a reader with no context knows what it is about.
- Open with "Hi {first name}", never "Hey". No em dashes, in subjects, preheaders or body; use a colon or a new sentence.
- Write to what the shopper is trying to get done, not a feature list ("toolbox of add-ons", "drag-and-drop" were cut). The new-purchase emails follow the Hidden Multipliers buyer research: someone asked for something the entries list can't produce, she values building it without a developer, and what stops her is doubting it fits her case.
- Anything that must not wrap away from its words (an emoji at the end of a sentence) goes behind `&nbsp;`.

## Email markup (FluentCRM)

- **FluentCRM rebuilds block styles when it sends, so check sizes and spacing in a real send, not in the builder output.** A paragraph's `"fontSize":"small"` preset renders as `font-size: var(--wp--preset--font-size--small)`, which no email defines, so the text stays at the 16px body size; set `"style":{"typography":{"fontSize":"14px"}}` instead. The template also forces `margin-bottom` with `!important` on every `p` (14px) and on unclassed top-level `div`s (10px), so spacing you need exactly goes in a `raw()` block with a classed `div`. Measure in a browser against the Mailpit copy (`getBoundingClientRect`, and CDP `CSS.getMatchedStylesForNode` to find which rule wins).
