#!/usr/bin/env python3
"""Builds edd-upgrade-cart.json, the FluentCRM automation for license upgrades left at checkout.

Import it in FluentCRM > Automations > Import. It imports as a draft; nothing sends until it is
published and the "Easy Digital Downloads (license upgrades)" abandoned-cart provider is enabled.

Three emails, no discount. Every price comes from a smart code worked out when the email is sent,
because the upgrade price changes every day. The run stops when the license is upgraded or
renewed, when a renewal cart for it starts, or when the contact completes another paid order.

Re-run after editing the copy below: python3 funnels/build-edd-upgrade-cart.py
"""
import json
import os

G = "ab_cart_edd_upgrade"
NAME = "{{contact.first_name|there}}"
URL = "##" + G + ".recovery_url##"
CURRENT = "{{" + G + ".current_plan|your current plan}}"
NEW = "{{" + G + ".new_plan|your new plan}}"
BREAKDOWN = "{{" + G + ".price_breakdown}}"
PRICE_CHANGE = "{{" + G + ".price_change_line}}"
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
        "Your upgrade to " + NEW + " is saved",
        "Here’s how the upgrade price works.",
        header()
        + greeting("Hi " + NAME + ",")
        + para("You started upgrading from " + CURRENT + " to " + NEW + ", and we saved it for you.")
        + button("Finish Upgrading", URL)
        + para("The price can look odd, so here’s how it works:")
        + raw(BREAKDOWN)
        + para(PRICE_CHANGE)
        + para(RENEWAL + " You won’t be charged twice.")
        + para("If something went wrong at checkout, just reply and we’ll fix it.")
        + SIGN
        + para("P.S. " + PS_CALL),
        "upgrade-cart-1",
    ),
    (
        "Upgrade email 2: Is it the right fit?",
        2, "days",
        "Is " + NEW + " the right fit?",
        "What changes for you with the bigger plan.",
        header()
        + greeting("Hi " + NAME + ",")
        + para("Moving to a bigger plan is a real decision, so here’s what changes for you. With " + NEW
               + " you also get:")
        + raw(EXTRAS)
        + para("Not sure it covers what you need? Reply and tell us what you’re building. We’ll tell you whether "
               "the upgrade helps.")
        + para('Upgrades are covered by our <a href="' + REFUNDS + '">30-day refund policy</a>: if the new plan '
               'isn’t right for you, we’ll refund the upgrade and move you back to your current plan.')
        + button("See My Upgrade", URL)
        + SIGN
        + para("P.S. " + PS_CALL),
        "upgrade-cart-2",
    ),
    (
        "Upgrade email 3: Need approval?",
        3, "days",
        "Need someone else to approve your upgrade?",
        "Here’s the price as of today, ready to forward.",
        header()
        + greeting("Hi " + NAME + ",")
        + para("If someone else needs to approve this, forward them this email. Here’s the price as of today:")
        + raw(BREAKDOWN)
        + para(RENEWAL_SOON)
        + para("This is our last email about it. Your saved upgrade is here if you want it:")
        + button("Finish Upgrading", URL)
        + SIGN
        + para("P.S. " + PS_CALL),
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
