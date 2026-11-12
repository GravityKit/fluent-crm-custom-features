#!/usr/bin/env python3
"""Builds the two agency automations (WEBSITE-578).

agency-onboarding.json  Starts when a contact joins the "Agency" list. Each email checks what the
                        agency has done (AgencyProgressTracker's gk_agency_* fields) and is skipped
                        once the step is done.
agency-reward.json      Starts when an agency first assigns a license to a client. Makes a
                        single-use $100 code, sends it, and sends one reminder two weeks before it
                        expires unless the code was used.

Import each in FluentCRM > Automations > Import. Both import as drafts.

FluentCRM's import re-parents only one level of branch steps, so the onboarding automation is a
straight run of checks, each with a yes or no branch and no nesting.

Re-run after editing the copy below: python3 funnels/build-agency-onboarding.py
"""
import json
import os

from email_blocks import bullets, button, greeting, header, para, signature

ACCOUNT = "https://www.gravitykit.com/account/"
AGENCY = ACCOUNT + "agency/"
CLIENTS = AGENCY + "clients"
LICENSES = AGENCY + "licenses"
SETTINGS = AGENCY + "settings"
ADD_CLIENT = AGENCY + "add-client"
SHOP = "https://www.gravitykit.com/pricing/"
NAME = "{{contact.first_name|there}}"
AGENCY_NAME = "{{contact.custom.gk_agency_name|your agency}}"
CODE = "{{contact.custom.gk_agency_reward_code}}"
EXPIRES = "{{contact.custom.gk_agency_reward_expires}}"
REWARD = "$100"

SIGN = signature(["Zack Katz", "Founder, GravityKit"])

AGENCY_LIST_ID = 24  # "Agency" (slug `agency`), the same id on staging and live.


def field(slug, operator, value):
    return {"source": ["custom_fields", slug], "operator": operator, "value": str(value)}


# --- Email copy -------------------------------------------------------------------------------

WELCOME = (
    "Your agency tools are on",
    "Keep each client's licenses in one place, and let clients pay their own renewals.",
    header(AGENCY)
    + greeting("Hi " + NAME + ",")
    + para("Your GravityKit account is now set up for an agency. That means you can:")
    + bullets([
        "<strong>Add your clients</strong>, so each one has an account with you as their agency.",
        "<strong>Assign licenses to clients.</strong> They can download the plugins and see their "
        "licenses, and you still own and pay for them.",
        "<strong>Email a client a payment link</strong> when a license is due, so they pay the renewal "
        "themselves. They don't need an account or your password.",
    ])
    + para("Two minutes to start: give your agency the name your clients know. It signs every email "
           "we send them for you.")
    + button("Name My Agency", SETTINGS)
    + para("When you assign your first license to a client, we'll send you a " + REWARD + " code toward "
           "your next license.")
    + para("Questions? Just reply. I read every email.")
    + SIGN,
    "agency-welcome",
)

ADD_FIRST_CLIENT = (
    "Add your first client",
    "Name, email, done. You choose whether we email them.",
    header(ADD_CLIENT)
    + greeting("Hi " + NAME + ",")
    + para("Your agency tools are ready, and the first step is adding a client. You need their first "
           "name, last name and email address.")
    + button("Add a Client", ADD_CLIENT)
    + para("We email them an invitation to sign in, signed with " + AGENCY_NAME + ". If you'd rather "
           "they never hear from us, tick \"Don't email this client unless I ask you to\" and we'll send "
           "their notices to you instead.")
    + para("Then assign them a license and we'll send you a " + REWARD + " code toward your next one.")
    + SIGN,
    "agency-add-client",
)

ASSIGN_LICENSE = (
    "Assign a license to your client, get " + REWARD + " off your next one",
    "Your client can download the plugins. You keep the license.",
    header(LICENSES)
    + greeting("Hi " + NAME + ",")
    + para("You've added a client. Next, assign them a license:")
    + bullets([
        "Open <strong>Agency &gt; Licenses</strong>.",
        "Pick a license and choose <strong>Assign to a client</strong>.",
    ], ordered=True)
    + button("Assign a License", LICENSES)
    + para("Your client can then download the plugins and see the license in their own account. You "
           "still own it, pay for it, and can take it back at any time.")
    + para("Once you've assigned your first license, we'll email you a " + REWARD + " code toward your "
           "next license.")
    + SIGN,
    "agency-assign",
)

HOW_DO_YOU_WORK = (
    "How do you handle client licenses today?",
    "One question, and your answer goes straight to me.",
    greeting("Hi " + NAME + ",")
    + para("A couple of weeks ago your account was set up for an agency, but you haven't assigned a "
           "license to a client yet. That's fine. I'd like to learn why.")
    + para("How do you handle plugin licenses for your clients today? Do you buy them and keep them, "
           "have clients buy their own, or something else?")
    + para("Please just reply. I read every answer, and it decides what we build next for agencies.")
    + SIGN,
    "agency-question",
)

PAY_LINKS = (
    "Let clients pay their own renewals",
    "Email a payment link: your client pays, the license stays with you.",
    header(LICENSES)
    + greeting("Hi " + NAME + ",")
    + para("When a client's license is up for renewal, you don't have to pay it and bill them. Send them "
           "a payment link instead:")
    + bullets([
        "Open the license in <strong>Agency &gt; Licenses</strong>. Under <strong>Payment link</strong>, "
        "choose <strong>Email the client</strong>.",
        "We email your client a link to pay the renewal by card. It works once and lasts 30 days.",
        "You get an email when they've paid. The license, receipt and renewal stay in your account.",
    ])
    + button("Go to Licenses", LICENSES)
    + para("Your client doesn't need an account or your password to pay.")
    + SIGN,
    "agency-pay-links",
)

WHAT_NEXT = (
    "What would make this work better for your agency?",
    "One question about your agency.",
    greeting("Hi " + NAME + ",")
    + para("You've been using GravityKit's agency tools for a few weeks. Thank you.")
    + para("What's still harder than it should be when you manage plugins for clients? Anything from "
           "billing to support to handing a site over.")
    + para("Please just reply. I read every answer.")
    + SIGN,
    "agency-feedback",
)

REWARD_EMAIL = (
    "Your " + REWARD + " code, for assigning your first client license",
    "Use it on your next license. It works once and expires " + EXPIRES + ".",
    header(SHOP)
    + greeting("Hi " + NAME + ",")
    + para("You assigned your first license to a client. Thank you for trying the agency tools. Here's "
           + REWARD + " toward your next license:")
    + para('<strong style="font-size:22px;letter-spacing:2px;">' + CODE + "</strong>")
    + para("Enter it at checkout. It works once and expires on " + EXPIRES + ".")
    + button("Buy a License", SHOP)
    + para("Buying for a client? Add their name and email at checkout and the license is assigned to "
           "them for you.")
    + SIGN,
    "agency-reward",
)

REWARD_REMINDER = (
    "Your " + REWARD + " code expires on " + EXPIRES,
    "Code " + CODE + ", for your next license.",
    greeting("Hi " + NAME + ",")
    + para("A reminder that your " + REWARD + " code expires on " + EXPIRES + ":")
    + para('<strong style="font-size:22px;letter-spacing:2px;">' + CODE + "</strong>")
    + button("Buy a License", SHOP)
    + SIGN,
    "agency-reward-reminder",
)


# --- Step helpers -------------------------------------------------------------------------------

class Steps:
    def __init__(self):
        self.items = []
        self.next_id = 1

    def _add(self, step, parent=0, branch=None):
        step["id"] = self.next_id
        step["parent_id"] = parent
        step["condition_type"] = branch
        self.items.append(step)
        self.next_id += 1
        return step["id"]

    def wait(self, amount, unit, parent=0, branch=None):
        return self._add({
            "action_name": "fluentcrm_wait_times",
            "type": "action",
            "title": "Wait %d %s" % (amount, unit),
            "description": "",
            "settings": {
                "wait_type": "unit_wait",
                "wait_time_amount": str(amount),
                "wait_time_unit": unit,
                "is_timestamp_wait": "",
                "wait_date_time": "",
                "to_day": [],
                "to_day_time": "",
            },
        }, parent, branch)

    def weekday(self, time="09:00", parent=0, branch=None):
        """Hold until the next weekday morning, in the site's timezone."""
        return self._add({
            "action_name": "fluentcrm_wait_times",
            "type": "action",
            "title": "Resume on next weekday at " + time,
            "description": "",
            "settings": {"wait_type": "to_day", "to_day": ["Mon", "Tue", "Wed", "Thu", "Fri"], "to_day_time": time},
        }, parent, branch)

    def condition(self, title, groups):
        return self._add({
            "action_name": "funnel_condition",
            "type": "conditional",
            "title": title,
            "description": "",
            "settings": {"conditions": groups},
        })

    def end(self, parent=0, branch=None):
        return self._add({
            "action_name": "end_this_funnel",
            "type": "action",
            "title": "End This Funnel Here",
            "description": "",
            "settings": {},
        }, parent, branch)

    def reward_code(self):
        return self._add({
            "action_name": "gk_agency_reward_code",
            "type": "action",
            "title": "Create agency reward code",
            "description": REWARD + " off, single use, 60 days",
            "settings": {"amount": 100, "expiry_days": 60, "min_amount": 0, "prefix": "AGENCY"},
        })

    def email(self, label, copy, parent=0, branch=None):
        subject, preheader, body, utm = copy
        return self._add({
            "action_name": "send_custom_email",
            "type": "action",
            "title": label,
            "description": subject,
            "settings": {
                "reference_campaign": "",
                "send_email_to_type": "contact",
                "send_email_custom": "",
                "is_scheduled": "no",
                "scheduled_at": "",
                "skip_if_overdue": "no",
                "mailer_settings": {"from_name": "", "from_email": "", "reply_to_name": "", "reply_to_email": "", "is_custom": "no"},
                "campaign": {
                    "title": label,
                    "email_subject": subject,
                    "email_pre_header": preheader,
                    "email_body": body.rstrip("\n"),
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
        }, parent, branch)


# --- Agency onboarding --------------------------------------------------------------------------

HAS_CLIENT = [[field("gk_agency_clients", ">", 0)]]
HAS_ASSIGNED = [[field("gk_agency_licenses_assigned", ">", 0)]]
HAS_PAY_LINK = [[field("gk_agency_pay_links", ">", 0)]]

s = Steps()
s.email("Agency 1: Your agency tools are on", WELCOME)
s.wait(3, "days")
s.weekday()
c = s.condition("Added a client?", HAS_CLIENT)
s.email("Agency 2: Add your first client", ADD_FIRST_CLIENT, c, "no")
s.wait(4, "days")
s.weekday()
c = s.condition("Added a client, but no license assigned?", [[field("gk_agency_clients", ">", 0), field("gk_agency_licenses_assigned", "=", 0)]])
s.email("Agency 3: Assign a license", ASSIGN_LICENSE, c, "yes")
s.wait(7, "days")
s.weekday()
c = s.condition("Assigned a license?", HAS_ASSIGNED)
s.email("Agency 4: How do you handle client licenses?", HOW_DO_YOU_WORK, c, "no")
s.end(c, "no")
c = s.condition("Sent a payment link?", HAS_PAY_LINK)
s.email("Agency 5: Let clients pay their renewals", PAY_LINKS, c, "no")
s.wait(14, "days")
s.weekday()
s.email("Agency 6: What would make this better?", WHAT_NEXT)
s.end()

onboarding = {
    "title": "Agency onboarding",
    "trigger_name": "fluentcrm_contact_added_to_lists",
    "status": "draft",
    "settings": {"lists": [str(AGENCY_LIST_ID)], "select_type": "any"},
    "conditions": {"run_multiple": "no"},
    "sticky_note": {"content": "Starts when a contact joins the Agency list (an account gets agency tools). "
                               "Each check reads the gk_agency_* fields that fluent-crm-custom-features keeps "
                               "up to date, so a step the agency already did is skipped. Built by "
                               "funnels/build-agency-onboarding.py (WEBSITE-578)."},
    "sequences": s.items,
}

# --- Agency reward ------------------------------------------------------------------------------

r = Steps()
r.reward_code()
r.email("Reward 1: Your $100 code", REWARD_EMAIL)
r.wait(46, "days")
c = r.condition("Used the code?", [[field("gk_agency_reward_used", "=", 1)]])
r.end(c, "yes")
r.email("Reward 2: Your code expires soon", REWARD_REMINDER)
r.end()

reward = {
    "title": "Agency reward",
    "trigger_name": "fluent_crm/event_tracked",
    "status": "draft",
    "settings": {"event_key": "agency_license_assigned", "minimum_event_count": 1},
    "conditions": {"value_conditions": [[]], "run_multiple": "no", "dont_run_on_active": "yes"},
    "sticky_note": {"content": "Starts the first time an agency assigns a license to a client. Creates a "
                               "single-use $100 EDD code (one per contact, ever), emails it, and reminds once "
                               "two weeks before it expires unless it was used. Built by "
                               "funnels/build-agency-onboarding.py (WEBSITE-578)."},
    "sequences": r.items,
}

here = os.path.dirname(os.path.abspath(__file__))
for name, funnel in (("agency-onboarding.json", onboarding), ("agency-reward.json", reward)):
    out = os.path.join(here, name)
    with open(out, "w", encoding="utf-8") as fh:
        json.dump(funnel, fh, ensure_ascii=False, indent=1)
    print("wrote", out, len(funnel["sequences"]), "steps")
