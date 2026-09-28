#!/usr/bin/env python3
"""Builds edd-abandoned-cart.json, the FluentCRM automation that replaces Recapture's 7 cart emails.

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


def button(label, href=URL):
    return (
        '<p style="margin:24px 0;"><a class="fc_button" href="' + href + '" style="display:inline-block;background:#4f46e5;'
        'color:#ffffff;padding:12px 22px;border-radius:6px;font-weight:600;text-decoration:none;">'
        + label + "</a></p>"
    )


def p(text):
    return "<p>" + text + "</p>"


# Each email: (title, wait amount, wait unit, subject, preheader, body, utm_campaign)
EMAILS = [
    (
        "Email 1: Reminder",
        15, "minutes",
        "Complete your purchase—your website will thank you!",
        "You left powerful Gravity Forms add-ons in your cart 🛒",
        p("Hey " + NAME + ",")
        + p("Did you forget to check out? Your cart is saved, so you can pick up right where you left off. 🛒")
        + p("If you need help with something, just reply to this email and we’ll respond 😊")
        + button("Return and Complete Purchase")
        + p("Here is what you left in your cart:")
        + CART
        + p("GravityKit gives you a toolbox of essential add-ons for Gravity Forms, so you can build powerful "
            "applications on your website with a drag-and-drop interface. No coding knowledge required!")
        + p("Ready to extend your website with powerful Gravity Forms add-ons? "
            '<a href="' + URL + '">Go back and complete your purchase!</a>')
        + p("All the best,<br>Zack from GravityKit")
        + p("P.S. Do you know about our 30-day money-back guarantee? If you’re not happy with your purchase, "
            "we will always refund you."),
        "abandoned-cart-1",
    ),
    (
        "Email 2: Objection handling",
        1425, "minutes",
        "Why GravityKit is right for you 👌",
        "Your cart is saved—complete your purchase whenever you’re ready.",
        p("Hey " + NAME + ",")
        + p("You still have " + PRODUCTS + " in your cart. Go back to our site to complete checkout and get "
            "powerful Gravity Forms add-ons working on your site.")
        + p("With our 30-day money-back guarantee and no-hassle refund policy, you can buy with confidence.")
        + button("Go Back and Complete Your Purchase")
        + p("Oh, and just in case you missed this while browsing our website…")
        + "<ul>"
        + '<li>You can <a href="https://site.try.gravitykit.com/">view our live demos</a> and try our plugins before you buy.</li>'
        + "<li>With GravityView, you can let users edit the entries they create.</li>"
        + "<li>You can upgrade your license at any time from your Account page, and only pay the difference in price.</li>"
        + '<li><a href="https://www.gravitykit.com/pricing/#faq">Get answers to more of your questions here</a>.</li>'
        + "</ul>"
        + p('Ready to get started? <a href="' + URL + '">Go back and complete your purchase</a>.')
        + p("All the best,<br>Zack from GravityKit")
        + p('P.S. If you still have questions about our plugins, or you need help with something, '
            '<a href="https://www.gravitykit.com/consultation/">book a free consultation with me</a>.'),
        "abandoned-cart-2",
    ),
    (
        "Email 3: Social proof",
        1, "days",
        "What people REALLY say about GravityKit",
        "“I can’t imagine not using GravityView on a WordPress site…”",
        p("Hi " + NAME + ",")
        + p("You were getting ready to purchase " + PRODUCTS + ", but didn’t finish. That’s ok—these things happen!")
        + p("Your cart is saved for you. Complete your purchase whenever you’re ready 👇")
        + button("Return and Complete Purchase")
        + p("Our plugins have helped nonprofits, schools, startups, developers, web agencies and designers "
            "extend Gravity Forms and build custom solutions on WordPress.")
        + p("But don’t take our word for it… Here’s what people are really saying:")
        + p("<em>“I can’t imagine not using GravityView on a WordPress site that collects any amount of data at all.”</em><br>"
            "(Dan Muhlenkamp, entrepreneur)")
        + p("<em>“Some of our clients want directories, and that’s where GravityView offers the perfect solution, "
            "as it allows users to edit their listings right on the front end.”</em><br>(Bet Hannon, agency owner)")
        + p("<em>“I am in awe of how easy it was to create a fully functioning system at the fraction of the cost of "
            "other SIS systems (Student Information Systems) on the market.”</em><br>(Rochelle Victor, developer)")
        + p('Ready to take Gravity Forms to the next level? <a href="' + URL + '">Go back and complete checkout</a>.')
        + p("All the best,<br>Zack from GravityKit")
        + p('P.S. If you’d like help deciding whether GravityKit is right for you, '
            '<a href="https://www.gravitykit.com/consultation/">book a free consultation with me</a>.'),
        "abandoned-cart-3",
    ),
    (
        "Email 4: Success story",
        1, "days",
        "Learn from Pieroth’s success story",
        "How this company saved money and time with GravityKit",
        p("Hi " + NAME + ",")
        + p("Pieroth came to GravityKit looking for a way to organize their eCommerce product catalogs and edit "
            "the data when needed.")
        + p("They already used Gravity Forms, so they looked for a tool that could put their form data to work.")
        + p("<em>“I just started searching for a tool that would let us display Gravity Forms data on the front end "
            "and I quickly found GravityView. It was perfect, just what we needed.”</em><br>"
            "(Nicolas Johansson, Digital Manager at Pieroth)")
        + p('<a href="https://www.gravitykit.com/case-study/pieroth/">Read Pieroth’s success story</a> and find out:')
        + "<ul>"
        + "<li>Why GravityKit suits mid-sized companies without a large IT team</li>"
        + "<li>How GravityView makes it easy to display and update Gravity Forms entries with little technical knowledge</li>"
        + "<li>How GravityKit lets you manage data on WordPress for a fraction of the cost of most SaaS tools</li>"
        + "</ul>"
        + p("We love this story. Give it a read—I think you will too.")
        + p("Your cart is still saved when you’re ready:")
        + CART
        + button("Complete Your Purchase")
        + p("All the best,<br>Zack from GravityKit"),
        "abandoned-cart-4",
    ),
    (
        "Email 5: Discount",
        1, "days",
        "Get " + AMOUNT + " off your GravityKit order (expires soon)",
        "A special gift, just for you.",
        p("Hey " + NAME + ", it’s Zack here with a special gift: a coupon for " + AMOUNT + " off your GravityKit order!")
        + p("Use code <strong>" + CODE + "</strong> at checkout to take " + AMOUNT
            + " off the items in your cart. It’s already applied when you use the button below, and it’s valid "
            "for the next 48 hours only.")
        + CART
        + button("Complete Purchase and Save " + AMOUNT)
        + p("Remember, this coupon expires in 48 hours—so don’t wait!")
        + p("– Zack from GravityKit"),
        "abandoned-cart-5",
    ),
    (
        "Email 6: Discount last chance",
        1, "days",
        "Last chance to get " + AMOUNT + " off your GravityKit order!",
        "It’s now or never—claim your special gift before it expires.",
        p("Hi " + NAME + ", your " + AMOUNT + " off coupon expires tomorrow!")
        + p("This is your last chance to take Gravity Forms to the next level, at a discount.")
        + button("Continue Shopping and Save " + AMOUNT)
        + CART
        + p("Use code <strong>" + CODE + "</strong> within the next 24 hours to take " + AMOUNT
            + " off your order. You’ll be glad you did!")
        + p("All the best,<br>Zack from GravityKit"),
        "abandoned-cart-6",
    ),
    (
        "Email 7: Consultation",
        2, "days",
        "A free 20 minutes with me, if it helps",
        "Walk away with a plan, even if it isn’t GravityKit.",
        p("Hi " + NAME + ", quick chat?")
        + p("It’s Zack here, the founder of GravityKit. 👋 You’ve heard from us a few times this week. I noticed "
            "you got close to checking out and then stepped away. That’s completely fine!")
        + p("<strong>💬 But if you’re stuck, let’s just talk</strong>")
        + p("Instead of sending you another sales email, I want to offer something different. Grab 20 minutes on my "
            "calendar (completely free) and we can talk through whatever’s on your mind:")
        + "<ul>"
        + "<li>The project you’re trying to build, and whether GravityKit is actually the right tool for it</li>"
        + "<li>A specific Gravity Forms problem you’ve been wrestling with</li>"
        + "<li>Questions about a plugin, a use case, or how a few of them fit together</li>"
        + "<li>Or anything else you want a second opinion on</li>"
        + "</ul>"
        + button("Book a Free Consultation", "https://www.gravitykit.com/consultation/")
        + p("No pitch, no pressure. If GravityKit ends up being the answer, great. If it doesn’t, you’ll still walk "
            "away with a clearer picture of what to do next.")
        + p("<strong>🤝 And if a call isn’t your thing</strong>")
        + p("Just reply to this email. It comes straight to me, and I read every one. Tell me what you’re working on, "
            "what tripped you up, or what would have made you click “buy.”")
        + p("Either way, thanks for taking a look at GravityKit. I hope our paths cross again.")
        + p("Zack Katz<br>Founder, GravityKit"),
        "abandoned-cart-7",
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
    "title": "Abandoned cart (EDD) – 7 emails",
    "trigger_name": "fc_ab_cart_simulation_edd",
    "status": "draft",
    "conditions": {"cart_conditions": [[]], "active_once": "no", "require_subscribed": "no"},
    "settings": {"priority": 10},
    "sticky_note": {"content": "Replaces Recapture's 7 abandoned-cart emails (WEBSITE-380). Carts count as abandoned after the "
                               "'capture after' minutes in Abandoned Cart settings (use 30). Emails 5–6 share one "
                               "single-use 40% code per cart, valid 48 hours, created when email 5 sends."},
    "sequences": sequences,
}

out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "edd-abandoned-cart.json")
with open(out, "w", encoding="utf-8") as fh:
    json.dump(funnel, fh, ensure_ascii=False, indent=1)
print("wrote", out, len(sequences), "steps")
