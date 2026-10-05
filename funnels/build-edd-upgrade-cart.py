#!/usr/bin/env python3
"""Builds edd-upgrade-cart.json, the FluentCRM automation for license upgrades left at checkout.

Import it in FluentCRM > Automations > Import. It imports as a draft; nothing sends until it is
published and the "Easy Digital Downloads (license upgrades)" abandoned-cart provider is enabled.

Three emails, no discount. The price comes from a smart code worked out when the email is sent,
because the upgrade price changes every day. The run stops when the license is upgraded or
renewed, when a renewal cart for it starts, or when the contact completes another paid order.

Re-run after editing the copy below: python3 funnels/build-edd-upgrade-cart.py
"""
import json
import os

G = "ab_cart_edd_upgrade"
NAME = "{{contact.first_name|there}}"
URL = "##" + G + ".recovery_url##"
# Product names without the site count; NEW_NAME is "All Access Pass", or for the same product what the
# upgrade adds ("a GravityImport plan with more sites", "a lifetime GravityImport plan"), written to sit
# mid-sentence.
CURRENT_PRODUCT = "{{" + G + ".current_product|GravityKit}}"
NEW_NAME = "{{" + G + ".new_plan_name|your new plan}}"
TODAY = "{{" + G + ".today_price}}"
RENEWAL = "{{" + G + ".renewal_line}}"
RENEWAL_SOON = "{{" + G + ".renewal_soon_line}}"
EXTRAS = "{{" + G + ".new_plan_extras}}"
REFUNDS = "https://www.gravitykit.com/refund-policy/"


from email_blocks import button, greeting, header, para, raw, signature

SIGN = signature(["Zack Katz", "Founder, GravityKit"])
CALL = "https://www.gravitykit.com/consultation/"
PS_CALL = ("Not sure the bigger plan is right for you? "
           '<a href="' + CALL + '">Book a free 1-on-1 call with me</a> and we’ll work out what fits.')


# The first wait follows the "capture after" minutes in Abandoned Cart settings (30), so email 1
# arrives about an hour and a half after the shopper left checkout.
EMAILS = [
    (
        "Upgrade email 1: Your upgrade is saved",
        60, "minutes",
        "Your upgrade to " + NEW_NAME + " is saved",
        "Pick up right where you left off.",
        header()
        + greeting("Hey " + NAME + ",")
        + para("Looks like you didn’t finish upgrading to " + NEW_NAME + ". It’s saved, so you can pick up where you "
               "left off:")
        + button("Finish My Upgrade", URL)
        + para("You get credit for your current " + CURRENT_PRODUCT + " license, so the upgrade is just " + TODAY
               + " today. " + RENEWAL)
        + para("If anything went wrong at checkout, just reply and we’ll sort it out.")
        + SIGN
        + para("P.S. " + PS_CALL),
        "upgrade-cart-1",
    ),
    (
        "Upgrade email 2: Is it right for you?",
        2, "days",
        "Is " + NEW_NAME + " right for you?",
        "A quick question about your upgrade.",
        header()
        + greeting("Hey " + NAME + ",")
        + para("You started upgrading to " + NEW_NAME + " but didn’t finish. Here’s what it adds to your "
               + CURRENT_PRODUCT + " license:")
        + raw(EXTRAS)
        + para("Not sure it fits what you’re building? Reply and tell us about your project. We’ll give you a "
               "straight answer, even if the answer is “you don’t need it.”")
        + para('And upgrades are covered by our <a href="' + REFUNDS + '">30-day money-back guarantee</a>.')
        + button("See My Upgrade", URL)
        + SIGN
        + para("P.S. " + PS_CALL),
        "upgrade-cart-2",
    ),
    (
        "Upgrade email 3: Run it by someone",
        3, "days",
        "Last reminder: You were about to upgrade to " + NEW_NAME,
        "Your upgrade is still saved. Easy to forward to whoever approves purchases.",
        header()
        + greeting("Hey " + NAME + ",")
        # The email may be forwarded to whoever approves purchases, so it opens by saying what was left unfinished.
        + para("A few days ago, you started upgrading your " + CURRENT_PRODUCT + " license to " + NEW_NAME
               + " but didn’t finish checking out. Your upgrade is still saved.")
        + para("Upgrading is " + TODAY + " today, with credit for your current " + CURRENT_PRODUCT + " license.")
        # Its own <p> when the license renews within 30 days; empty otherwise, so nothing is left behind.
        + raw(RENEWAL_SOON)
        + para("If someone else approves purchases, please forward them this email. They can finish the upgrade with the "
               "button below.")
        + button("Finish My Upgrade", URL)
        # The fallback for a reader who is not ready to buy; it replaces the P.S. the other emails carry.
        + para("Not ready yet, or have questions first? Just reply to this email, or "
               '<a href="' + CALL + '">book a free 1-on-1 call with me</a>.')
        + para("This is the last email we’ll send about this upgrade.")
        + SIGN,
        "upgrade-cart-3",
    ),
]

sequences = []
seq_id = 1
for title, amount, unit, subject, preheader, body, utm in EMAILS:
    sequences.append({
        "id": seq_id,
        "action_name": "fluentcrm_wait_times",
        "type": "action",
        "title": "Wait %d %s" % (amount, unit),
        "description": "",
        "parent_id": 0,
        "condition_type": None,
        "settings": {
            "wait_type": "unit_wait",
            "wait_time_amount": str(amount),
            "wait_time_unit": unit,
            "is_timestamp_wait": "",
            "wait_date_time": "",
            "to_day": [],
            "to_day_time": "",
        },
    })
    seq_id += 1
    sequences.append({
        "id": seq_id,
        "action_name": "send_custom_email",
        "type": "action",
        "title": title,
        "description": subject,
        "parent_id": 0,
        "condition_type": None,
        "settings": {
            "reference_campaign": "",
            "send_email_to_type": "contact",
            "send_email_custom": "",
            "is_scheduled": "no",
            "scheduled_at": "",
            "skip_if_overdue": "no",
            "mailer_settings": {"from_name": "", "from_email": "", "reply_to_name": "", "reply_to_email": "", "is_custom": "no"},
            "campaign": {
                "title": title,
                "email_subject": subject,
                "email_pre_header": preheader,
                "email_body": body,
                "design_template": "simple",
                "utm_status": 1,
                "utm_source": "fluentcrm",
                "utm_medium": "email",
                "utm_campaign": utm,
                "utm_term": "",
                "utm_content": "",
                "settings": {"mailer_settings": {"from_name": "", "from_email": "", "reply_to_name": "", "reply_to_email": "", "is_custom": "no"}},
            },
        },
    })
    seq_id += 1

funnel = {
    "title": "Abandoned upgrade (EDD) – 3 emails",
    "trigger_name": "fc_ab_cart_simulation_edd_upgrade",
    "status": "draft",
    "conditions": {"cart_conditions": [[]], "active_once": "no", "require_subscribed": "no"},
    "settings": {"priority": 10},
    "sticky_note": {"content": "License upgrades left at checkout. No discount. Prices are worked out when each email "
                               "is sent, because the upgrade price changes every day. The run is cancelled when the "
                               "license is upgraded or renewed by any route, when a renewal for it starts, or when "
                               "the contact completes another paid order."},
    "sequences": sequences,
}

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "edd-upgrade-cart.json")
with open(out, "w", encoding="utf-8") as fh:
    json.dump(funnel, fh, ensure_ascii=False, indent=1)
print("wrote", out, len(sequences), "steps")
