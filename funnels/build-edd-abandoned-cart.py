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


from email_blocks import bullets, button, greeting, header, para, raw, signature

SIGN = signature(["Zack Katz", "Founder, GravityKit"])

# Each email: (title, wait amount, wait unit, subject, preheader, body, utm_campaign)
EMAILS = [
    (
        "Email 1: Reminder",
        15, "minutes",
        "Complete your purchase—your website will thank you!",
        "You left powerful Gravity Forms add-ons in your cart 🛒",
        header()
        + greeting("Hey " + NAME + ",")
        + para("Did you forget to check out? Your cart is saved, so you can pick up right where you left off. 🛒")
        + para("Here is what you left in your cart:")
        + raw(CART)
        + button("Return and Complete Purchase", URL)
        + para("GravityKit gives you a toolbox of essential add-ons for Gravity Forms, so you can build powerful "
               "applications on your website with a drag-and-drop interface. No coding knowledge required!")
        + para("Ready to extend your website with powerful Gravity Forms add-ons? "
               '<a href="' + URL + '">Go back and complete your purchase!</a>')
        + para("If you need help with something, just reply to this email and we’ll respond 😊")
        + SIGN
        + para("P.S. Do you know about our 30-day money-back guarantee? If you’re not happy with your purchase, "
               "we will always refund you."),
        "abandoned-cart-1",
    ),
    (
        "Email 2: Objection handling",
        1425, "minutes",
        "Why GravityKit is right for you 👌",
        "Your cart is saved—complete your purchase whenever you’re ready.",
        header()
        + greeting("Hey " + NAME + ",")
        + para("You still have " + PRODUCTS + " in your cart. Go back to our site to complete checkout and get "
               "powerful Gravity Forms add-ons working on your site.")
        + para("With our 30-day money-back guarantee and no-hassle refund policy, you can buy with confidence.")
        + button("Go Back and Complete Your Purchase", URL)
        + para("Oh, and just in case you missed this while browsing our website…")
        + bullets([
            'You can <a href="https://site.try.gravitykit.com/">view our live demos</a> and try our plugins before you buy.',
            "With GravityView, you can let users edit the entries they create.",
            "You can upgrade your license at any time from your Account page, and only pay the difference in price.",
            '<a href="https://www.gravitykit.com/pricing/#faq">Get answers to more of your questions here</a>.',
        ])
        + para('Ready to get started? <a href="' + URL + '">Go back and complete your purchase</a>.')
        + SIGN
        + para('P.S. If you still have questions about our plugins, or you need help with something, '
               '<a href="https://www.gravitykit.com/consultation/">book a free consultation with me</a>.'),
        "abandoned-cart-2",
    ),
    (
        "Email 3: Discount",
        3, "days",
        "Get " + AMOUNT + " off your GravityKit order (expires soon)",
        "A special gift, just for you.",
        header()
        + greeting("Hey " + NAME + ",")
        + para("It’s Zack here with a special gift: a coupon for " + AMOUNT + " off your GravityKit order!")
        + para("Use code <strong>" + CODE + "</strong> at checkout to take " + AMOUNT
               + " off the items in your cart. It’s already applied when you use the button below, and it’s valid "
               "for the next 48 hours only.")
        + raw(CART)
        + button("Complete Purchase and Save " + AMOUNT, URL)
        + para("Remember, this coupon expires in 48 hours—so don’t wait!")
        + SIGN,
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
