#!/usr/bin/env python3
"""Builds edd-renewal-cart.json, the FluentCRM automation for license renewals left at checkout.

Import it in FluentCRM > Automations > Import. It imports as a draft; nothing sends until it is
published and the "Easy Digital Downloads (license renewals)" abandoned-cart provider is enabled.

Two emails, no discount. The run stops when the license is renewed by any route.

Re-run after editing the copy below: python3 funnels/build-edd-renewal-cart.py
"""
import json
import os

G = "ab_cart_edd_renewal"
NAME = "{{contact.first_name|there}}"
CART = "{{" + G + ".cart_items_table}}"
URL = "##" + G + ".recovery_url##"
PRODUCTS = "{{" + G + ".product_names|your GravityKit products}}"
STATUS = "{{" + G + ".license_status|is due for renewal}}"
ACCOUNT = "https://www.gravitykit.com/account/"


def button(label, href=URL):
    return (
        '<p style="margin:24px 0;"><a class="fc_button" href="' + href + '" style="display:inline-block;background:#4f46e5;'
        'color:#ffffff;padding:12px 22px;border-radius:6px;font-weight:600;text-decoration:none;">'
        + label + "</a></p>"
    )


def p(text):
    return "<p>" + text + "</p>"


# The first wait follows the "capture after" minutes in Abandoned Cart settings (30), so email 1
# arrives about an hour after the shopper left checkout.
EMAILS = [
    (
        "Renewal email 1: Finish renewing",
        30, "minutes",
        "Your GravityKit renewal is waiting",
        "Your license " + STATUS + ".",
        p("Hi " + NAME + ",")
        + p("You started renewing your license for " + PRODUCTS + " but didn’t finish checking out. "
            "Your license " + STATUS + ".")
        + button("Finish Renewing")
        + p("An active license keeps these coming:")
        + "<ul>"
        + "<li>Plugin updates in your WordPress dashboard, including security and compatibility fixes</li>"
        + "<li>Help from GravityKit support</li>"
        + "<li>New features as we release them</li>"
        + "</ul>"
        + p("Here is what’s waiting at checkout:")
        + CART
        + p("If something went wrong at checkout, or you have a question about your renewal, just reply to this "
            "email and we’ll help.")
        + p("All the best,<br>The GravityKit team"),
        "renewal-cart-1",
    ),
    (
        "Renewal email 2: Reminder",
        2, "days",
        "Still planning to renew?",
        "Your renewal is still at checkout.",
        p("Hi " + NAME + ",")
        + p("Your renewal for " + PRODUCTS + " is still waiting at checkout. Your license " + STATUS + ".")
        + button("Renew My License")
        + p('You can see every license and when it expires on your <a href="' + ACCOUNT + '">Account page</a>.')
        + p("If you’ve decided not to renew, we’d like to know why. Reply to this email; we read every reply.")
        + p("All the best,<br>The GravityKit team"),
        "renewal-cart-2",
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
    "title": "Abandoned renewal (EDD) – 2 emails",
    "trigger_name": "fc_ab_cart_simulation_edd_renewal",
    "status": "draft",
    "conditions": {"cart_conditions": [[]], "active_once": "no", "require_subscribed": "no"},
    "settings": {"priority": 10},
    "sticky_note": {"content": "License renewals left at checkout. No discount. The run is cancelled as soon as the "
                               "license is renewed, whether through this link, another checkout, auto-renew or an admin."},
    "sequences": sequences,
}

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "edd-renewal-cart.json")
with open(out, "w", encoding="utf-8") as fh:
    json.dump(funnel, fh, ensure_ascii=False, indent=1)
print("wrote", out, len(sequences), "steps")
