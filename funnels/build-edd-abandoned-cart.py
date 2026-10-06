#!/usr/bin/env python3
"""Builds edd-abandoned-cart.json, the FluentCRM automation that replaces Recapture's cart emails with 3.

Import it in FluentCRM > Automations > Import. It imports as a draft; nothing sends until it is
published and the EDD abandoned-cart provider is enabled.

Re-run after editing the copy below: python3 funnels/build-edd-abandoned-cart.py
"""
import json
import os

G = "ab_cart_edd"
NAME = "{{contact.first_name|there}}"
CART = "{{" + G + ".cart_items_table}}"
URL = "##" + G + ".recovery_url##"
PRODUCTS = "{{" + G + ".product_names}}"
CODE = "{{" + G + ".recovery_discount_code}}"
AMOUNT = "{{" + G + ".recovery_discount_amount}}"


from email_blocks import bullets, button, greeting, header, para, raw, signature, stop_line

SIGN = signature(["Zack Katz", "Founder, GravityKit"])
CALL = "https://www.gravitykit.com/consultation/"


# Each email: (title, wait amount, wait unit, subject, preheader, body, utm_campaign)
EMAILS = [
    (
        "Email 1: Reminder",
        15, "minutes",
        "Complete your purchase: your website will thank you!",
        "Everything you picked is still in your cart\u00a0🛒",
        header()
        + greeting("Hi " + NAME + ",")
        + para("Did you forget to check out? Your cart is saved, so you can pick up right where you left off.&nbsp;🛒")
        + para("Here is what you left in your cart:")
        + raw(CART)
        + button("Return and Complete Purchase", URL)
        # Written for Carol (Marketing/Hidden Multipliers): someone asks her for something the entries
        # list can't produce, she values building it without a developer, and she doubts it fits her case.
        + para("A refresher: GravityKit turns your Gravity Forms entries into the things people keep asking you "
               "for. A member directory. A dashboard your team can log in to. A report your boss can open and "
               "share. You build it yourself, without hiring a developer.")
        + para("Not sure it does what you need? Reply and let me know. I’ll give you honest feedback about whether "
               "GravityKit is the right fit.")
        + para("<strong>Need an invoice, a quote, or a W-9 to get this approved?</strong> Reply and we’ll send it.")
        + para('<a href="' + URL + '">Go back and complete your purchase.</a>')
        + SIGN
        + para("P.S. Not sure which plugins you need? "
               '<a href="' + CALL + '">Book a free 1-on-1 call with me</a> and we’ll work it out together.')
        + para("P.P.S. Every purchase comes with a 30-day guarantee: if GravityKit doesn’t do what you need, tell "
               "us within 30 days of buying and we’ll refund you in full."),
        "abandoned-cart-1",
    ),
    (
        "Email 2: Objection handling",
        1425, "minutes",
        "Will GravityKit do what you need?",
        "Try it on a live demo before you buy.",
        header()
        + greeting("Hi " + NAME + ",")
        + para("You still have " + PRODUCTS + " in your cart.")
        + para("If you’re not sure GravityKit will do what you need, here are three ways to find out before you pay:")
        + bullets([
            '<strong><a href="https://site.try.gravitykit.com/">Try it on a live demo site.</a></strong> '
            "Build with real form entries, nothing to install.",
            "<strong>Let me know.</strong> Reply to this email and I’ll tell you whether it fits and which plugins "
            "you need.",
            '<strong>Talk it through with me.</strong> <a href="' + CALL + '">Book a free 1-on-1 call</a> and '
            "we’ll work it out together.",
        ])
        + para("<strong>Need an invoice, a quote, or a W-9 to get this approved?</strong> Reply and we’ll send it.")
        # Hidden Multipliers: the cost of waiting is the inciting event that has not gone away.
        + para("Every week you wait is another week of copying entries into spreadsheets, sending people the "
               "latest list by hand, and logging in to the WordPress dashboard to find the one entry someone "
               "asked about.")
        + button("Go Back and Complete Your Purchase", URL)
        + SIGN
        + para("P.S. Every purchase comes with a 30-day guarantee: if GravityKit doesn’t do what you need, tell "
               "us within 30 days of buying and we’ll refund you in full."),
        "abandoned-cart-2",
    ),
    (
        "Email 3: Discount",
        3, "days",
        AMOUNT + " off your GravityKit order, for 48 hours",
        "Your discount is already applied to your cart.",
        header()
        + greeting("Hi " + NAME + ",")
        + para("If price is what’s holding you back, here’s " + AMOUNT + " off the items in your cart. Use code "
               "<strong>" + CODE + "</strong> at checkout, or use the button below and it’s already applied. "
               "It’s valid for the next 48 hours.")
        + raw(CART)
        + button("Complete Purchase and Save " + AMOUNT, URL)
        + SIGN
        + para("P.S. Not sure which plugins you need? "
               '<a href="' + CALL + '">Book a free 1-on-1 call with me</a> and we’ll work it out together.'),
        "abandoned-cart-5",
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
                "email_body": body + stop_line("##" + G + ".stop_url##"),
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
    "title": "Abandoned cart (EDD) – 3 emails",
    "trigger_name": "fc_ab_cart_simulation_edd",
    "status": "draft",
    "conditions": {"cart_conditions": [[]], "active_once": "no", "require_subscribed": "no"},
    "settings": {"priority": 10},
    "sticky_note": {"content": "Replaces Recapture's abandoned-cart emails (WEBSITE-380). Carts count as abandoned after the "
                               "'capture after' minutes in Abandoned Cart settings (use 30). Email 3 carries a single-use "
                               "40% code per cart, valid 48 hours, created when it sends, on day 4."},
    "sequences": sequences,
}

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "edd-abandoned-cart.json")
with open(out, "w", encoding="utf-8") as fh:
    json.dump(funnel, fh, ensure_ascii=False, indent=1)
print("wrote", out, len(sequences), "steps")
