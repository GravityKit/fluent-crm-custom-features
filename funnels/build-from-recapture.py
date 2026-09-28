#!/usr/bin/env python3
"""Converts every Recapture campaign into draft FluentCRM automations (funnels/recapture/*.json).

    python3 funnels/build-from-recapture.py <recapture export dir>

The export dir is what intellikit-mcp's scripts/recapture/export-config.mjs writes. Campaigns are
grouped into one automation per series: the bracketed tag in the title plus the [Lifetime] suffix
("[BFCM25] Email #3 [Lifetime]" belongs to "BFCM25 Lifetime"). The live "*[Campaign]" series is
skipped; edd-abandoned-cart.json already replaces it.

What carries over, and how:
- Send delays become wait steps (each measured from the previous email).
- Segment rules become the automation's cart conditions: product in cart, cart total.
- A store-wide code (BFCM50) is applied by the email's links through recovery_url_code.
- A unique-code discount maps to the matching discount profile; the email shows the code, which
  also creates it, and the recovery link applies it.
- Recapture merge tags map to FluentCRM smart codes.
- Recapture's run window (start/end dates) has no FluentCRM equivalent; it is written into the
  automation's note, so publish and unpublish the automation on those dates.

Every automation imports as a draft.

Email images are hosted on Recapture's CDN, which goes away with the account. After uploading the
images from the export's images/ folder, set RECAPTURE_IMAGE_BASE to their new folder URL and re-run.
"""
import json
import os
import re
import sys
from collections import defaultdict

if len(sys.argv) != 2:
    sys.exit(__doc__)

SRC = sys.argv[1]
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "recapture")
os.makedirs(OUT, exist_ok=True)


def lines(name):
    with open(os.path.join(SRC, name), encoding="utf-8") as fh:
        return [json.loads(l) for l in fh if l.strip()]


items = lines("program_items.jsonl")
pages = {(p["program"], p["object_id"], p["page"]): json.loads(p["blobs"]) for p in lines("config_pages.jsonl") if p["object_id"]}
segments = {s["segment_id"]: json.loads(s["segmentation"]) for s in lines("saved_segments.jsonl")}

# Recapture discount profile ID -> discount profile slug in EddRecoveryDiscount.
PROFILES = {
    "6107fd37e4c5920033cd3ee7": "pct40_3d",
    "61785f5325947e0032143359": "pct20",
    "61785f647afb9600336a64dc": "usd20",
    "6391e97807c8670028eaae13": "pct10_1d",
    "6a0bd44c67928e4319e653dd": "pct40_7d",
}
UNIT_MINUTES = {"minute": 1, "hour": 60, "day": 1440, "week": 10080}
PRODUCT_BY_NAME = {"lifetime all access pass": "843058", "all access pass": "808301", "gravityview": "17"}
report = []
IMAGE_BASE = os.environ.get("RECAPTURE_IMAGE_BASE", "").rstrip("/")


def minutes(cfg):
    if cfg.get("send_minutes"):
        return int(cfg["send_minutes"])
    return int(float(cfg.get("send_number") or 0) * UNIT_MINUTES.get(str(cfg.get("send_descriptor", "day")).rstrip("s"), 1440))


def wait(amount_minutes):
    """A FluentCRM wait step in the largest whole unit."""
    for unit, size in (("days", 1440), ("hours", 60)):
        if amount_minutes and amount_minutes % size == 0:
            return amount_minutes // size, unit
    return amount_minutes, "minutes"


def merge_tags(html, group, url):
    def attr(m):
        tag = m.group(0)
        prop = (re.search(r'data-property="([^"]+)"', tag) or [None, ""])[1]
        fallback = (re.search(r'data-fallback="([^"]*)"', tag) or [None, ""])[1]
        return token(prop, fallback)

    def token(prop, fallback):
        prop = prop.strip()
        if prop in ("cart.first_name", "customer.first_name", "order.first_name"):
            return "{{contact.first_name|%s}}" % fallback.strip() if fallback.strip() else "{{contact.first_name}}"
        if prop.replace(" ", "") == "cart|cartFirstProductName":
            return "{{%s.first_product_name}}" % group
        if prop == "order.email":
            return "{{contact.email}}"
        report.append("unmapped merge tag: " + prop)
        return ""

    def liquid(m):
        parts = [p.strip() for p in m.group(1).split("|", 1)]
        fallback = ""
        if len(parts) > 1:
            d = re.search(r"default:\s*\\?[\"']([^\"'\\]*)", parts[1])
            fallback = d.group(1) if d else ""
            if "cartFirstProductName" in parts[1]:
                return token("cart | cartFirstProductName", "")
        return token(parts[0], fallback)

    # Recapture's own tags first, so the FluentCRM tags written below are not read back as Recapture's.
    html = re.sub(r"\{\{\s*action_url\s*\}\}", "\x00URL\x00", html)
    # Some Recapture emails link to its own dashboard with the tag URL-encoded; those were meant as cart links.
    html = re.sub(r'https?://app\.recapture\.io/[^"\s]*%7B%7B%20action_url%20%7D%7D', "\x00URL\x00", html)
    html = re.sub(r"\{\{\s*([^}]+?)\s*\}\}", liquid, html)
    html = re.sub(r"<rc-attribute[\s\S]*?</rc-attribute>", attr, html)
    return html.replace("\x00URL\x00", url)


def body(blocks, group, url, code_line):
    out = []
    for b in blocks or []:
        t = b.get("block_type")
        if t == "title":
            out.append('<h2 style="margin:16px 0 8px;">%s</h2>' % merge_tags(b.get("content", ""), group, url))
        elif t in ("html", "raw-html"):
            out.append(merge_tags(b.get("content") or b.get("html") or "", group, url))
        elif t == "button":
            # Recapture button targets: "cart" (the recovery link), "home" (the store), or "url" with custom_url.
            target = {"cart": url, "home": "https://www.gravitykit.com/"}.get(b.get("url"), b.get("custom_url") or url)
            bg = b.get("background_color") or "#4f46e5"
            fg = b.get("text_color") or "#ffffff"
            if fg.lower() == bg.lower():
                report.append("button text and background are both %s; set a readable text color" % bg)
            out.append(
                '<p style="margin:24px 0;"><a class="fc_button" href="%s" style="display:inline-block;background:%s;color:%s;'
                'padding:12px 22px;border-radius:6px;font-weight:600;text-decoration:none;">%s</a></p>'
                % (target, bg, fg, re.sub(r"<[^>]+>", "", merge_tags(b.get("content", ""), group, url)).strip())
            )
        elif t == "abandoned-products":
            out.append("{{%s.cart_items_table}}" % group)
        elif t == "ordered-products":
            out.append("<p><em>(Your order)</em></p>")
            report.append("ordered-products block has no FluentCRM equivalent; left a placeholder")
        elif t in ("image", "image-and-html"):
            src = b.get("source") or b.get("url") or ""
            if src:
                img = '<img src="%s" alt="%s" style="max-width:100%%;height:auto;">' % (src, (b.get("alt") or "").replace('"', ""))
                out.append('<p><a href="%s">%s</a></p>' % (b["link"], img) if b.get("link") else "<p>%s</p>" % img)
            if t == "image-and-html":
                out.append(merge_tags(b.get("content", ""), group, url))
        elif t == "divider":
            out.append('<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">')
        elif t == "spacer":
            out.append('<div style="height:%dpx"></div>' % int(b.get("height") or 20))
        else:
            report.append("unconverted block type: %s" % t)
    if code_line:
        out.append(code_line)
    html = "\n".join(x for x in out if x)
    if IMAGE_BASE:
        html = re.sub(r"https://stockpile\.recapture\.io/(?:uploads|logos)/", IMAGE_BASE + "/", html)
    elif "stockpile.recapture.io" in html:
        report.append("images still point at Recapture's CDN; set RECAPTURE_IMAGE_BASE after re-hosting them")
    return html


def conditions(seg, group):
    """Recapture segment -> FluentCRM cart_conditions (groups are OR-ed, rules in a group AND-ed)."""
    if not seg:
        return [[]], []
    notes = []
    if seg.get("saved_segment_id"):
        saved = segments.get(seg["saved_segment_id"])
        if saved:
            seg = {**saved, "groups": (saved.get("groups") or []) + (seg.get("groups") or [])}
    rules = [r for g in (seg.get("groups") or []) for r in g]
    if not rules:
        return [[]], notes

    def convert(r):
        sel = r.get("selection", {})
        d, v = sel.get("directive"), sel.get("value")
        if r["key"] == "product-id":
            return {"source": [group, "cart_items"], "operator": "not_in" if d == "not-equal-to" else "in", "value": [str(x) for x in (v if isinstance(v, list) else [v])]}
        if r["key"] == "product-name":
            ids = [PRODUCT_BY_NAME.get(str(x).lower().strip()) for x in (v if isinstance(v, list) else [v])]
            if all(ids):
                return {"source": [group, "cart_items"], "operator": "not_in" if d == "not-equal-to" else "in", "value": ids}
        if r["key"] == "cart-total":
            op = {"greater-than": ">", "less-than": "<", "equal-to": "="}.get(d)
            if op:
                return {"source": [group, "cart_total"], "operator": op, "value": str(v)}
        notes.append("Recapture rule not converted: %s %s %s" % (r["key"], d, v))
        return None

    converted = [c for c in map(convert, rules) if c]
    if seg.get("match") == "any":
        return [[c] for c in converted] or [[]], notes
    return [converted], notes


def family_of(title):
    t = title.lstrip("*").strip()
    tag = re.match(r"\[([^\]]+)\]", t)
    lifetime = " Lifetime" if re.search(r"\[Lifetime\]\s*$", t) else ""
    return (tag.group(1) + lifetime) if tag else t


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", s.lower()).strip("-")


def sequence(emails, group, first_wait_from=0):
    steps, sid, prev = [], 1, first_wait_from
    for e in emails:
        amount, unit = wait(max(0, e["minutes"] - prev))
        prev = e["minutes"]
        steps.append({"id": sid, "action_name": "fluentcrm_wait_times", "type": "action", "title": "Wait %d %s" % (amount, unit), "description": "",
                      "parent_id": 0, "condition_type": None,
                      "settings": {"wait_type": "unit_wait", "wait_time_amount": str(amount), "wait_time_unit": unit, "is_timestamp_wait": "", "wait_date_time": "", "to_day": [], "to_day_time": ""}})
        sid += 1
        steps.append({"id": sid, "action_name": "send_custom_email", "type": "action", "title": e["title"], "description": e["subject"], "parent_id": 0, "condition_type": None,
                      "settings": {"reference_campaign": "", "send_email_to_type": "contact", "send_email_custom": "", "is_scheduled": "no", "scheduled_at": "", "skip_if_overdue": "no",
                                   "mailer_settings": {"from_name": "", "from_email": "", "reply_to_name": "", "reply_to_email": "", "is_custom": "no"},
                                   "campaign": {"title": e["title"], "email_subject": e["subject"], "email_pre_header": e["preview"], "email_body": e["body"], "design_template": "simple",
                                                "utm_status": 1, "utm_source": "fluentcrm", "utm_medium": "email", "utm_campaign": e["utm"] or slug(e["title"]), "utm_term": "", "utm_content": "",
                                                "settings": {"mailer_settings": {"from_name": "", "from_email": "", "reply_to_name": "", "reply_to_email": "", "is_custom": "no"}}}}})
        sid += 1
    return steps


families = defaultdict(list)
for it in items:
    if it["program"] == "email_collectors":
        continue
    cfg_blob = pages.get((it["program"], it["object_id"], "configuration"), {})
    cfg = cfg_blob.get("config", {})
    seg = pages.get((it["program"], it["object_id"], "segmentation"), {}).get("SegmentationController")
    title = it["title"] or it["object_id"]
    # Recapture's renewals campaign was a placeholder whose only rule said it was waiting for renewal
    # support; it goes onto the renewal trigger, which now provides that.
    is_renewal = "Is Renewal" in json.dumps(seg or {})
    group = ("ab_cart_edd_renewal" if is_renewal else "ab_cart_edd") if it["program"] == "abandoned_carts" else None
    if it["program"] == "abandoned_carts" and title.startswith("*[Campaign]"):
        continue

    recovery = "##%s.recovery_url##" % (group or "ab_cart_edd")
    code_line = ""
    profile = PROFILES.get(cfg.get("one_time_discount_id") or "")
    if group and cfg.get("discount_code"):
        recovery = "##ab_cart_edd.recovery_url_code.%s##" % cfg["discount_code"]
    elif group and profile:
        code_line = ('<p>Your code: <strong>{{ab_cart_edd.discount.%s.code}}</strong> (%s off, applied for you when you use the button above).</p>'
                     % (profile, "{{ab_cart_edd.discount.%s.amount}}" % profile))
    elif cfg.get("one_time_discount_id"):
        report.append("%s: unknown discount profile %s" % (title, cfg["one_time_discount_id"]))
    if not group:
        recovery = "https://www.gravitykit.com/account/"

    blocks = pages.get((it["program"], it["object_id"], "designer"), {}).get("__TEMPLATE_BLOCKS__")
    if is_renewal:
        seg = None
    families[(it["program"], family_of(title))].append({
        "title": title, "minutes": minutes(cfg), "subject": cfg.get("subject", ""), "preview": cfg.get("preview_text", ""),
        "utm": cfg.get("utm_campaign", ""), "body": body(blocks, group or "ab_cart_edd", recovery, code_line), "segment": seg,
        "active": it["is_active"], "schedule": cfg_blob.get("schedule") or {}, "group_label": it.get("group"), "code": cfg.get("discount_code") or profile or "",
        "recapture_id": it["object_id"], "group": group,
    })

index = []
for (program, family), emails in sorted(families.items()):
    # Recapture's "[COPY]" campaigns are duplicates made while editing; keep the original when both exist.
    originals = {e["subject"] for e in emails if not re.search(r"\[COPY\]\s*$", e["title"])}
    emails[:] = [e for e in emails if not (re.search(r"\[COPY\]\s*$", e["title"]) and e["subject"] in originals)]
    emails.sort(key=lambda e: e["minutes"])
    group = emails[0]["group"] or "ab_cart_edd"
    conds, cond_notes = conditions(emails[0]["segment"], group)
    differing = [e["title"] for e in emails[1:] if json.dumps(conditions(e["segment"], group)[0]) != json.dumps(conds)]
    windows = sorted({"%s to %s" % (e["schedule"].get("start_at", "")[:10], e["schedule"].get("end_at", "")[:10]) for e in emails if e["schedule"].get("start_at")})
    note = ["Converted from Recapture (%s). Recapture IDs: %s." % (program.replace("_", " "), ", ".join(e["recapture_id"] for e in emails))]
    if windows:
        note.append("Recapture ran this series in the window %s (last configured); publish and unpublish on the matching dates." % "; ".join(windows))
    if cond_notes:
        note.append(" ".join(cond_notes))
    if program != "abandoned_carts" and conds == [[]]:
        note.append("Recapture had no conditions on this campaign, so it applies to every order.")
    if differing:
        note.append("These emails had different conditions in Recapture and use the first email's here: " + ", ".join(differing))
    placeholders = sorted({m for e in emails for m in re.findall(r"\b[A-Z]+(?:_[A-Z]+)*_CODE\b", e["body"])})
    if placeholders:
        note.append("The emails contain the placeholder text %s; replace it with a real code before publishing." % ", ".join(placeholders))
    codes = sorted({e["code"] for e in emails if e["code"]})
    if codes:
        note.append("Discounts: " + ", ".join(codes) + ". Check that store-wide codes exist and are active in EDD before publishing.")

    if program == "abandoned_carts":
        funnel = {"title": "Recapture: %s" % family, "trigger_name": "fc_ab_cart_simulation_" + group.replace("ab_cart_", ""), "status": "draft",
                  # Conditional series outrank the default sequence (priority 10), as they did in Recapture.
                  "conditions": {"cart_conditions": conds, "active_once": "no", "require_subscribed": "no"},
                  "settings": {"priority": 20 if conds != [[]] else 5},
                  "sticky_note": {"content": " ".join(note)}, "sequences": sequence(emails, group)}
    else:
        product_ids = sorted({v for g in conds for c in g if c.get("source", [None, None])[1] == "cart_items" and c["operator"] == "in" for v in c["value"]})
        funnel = {"title": "Recapture %s: %s" % (program.replace("_", "-"), family), "trigger_name": "edd_update_payment_status", "status": "draft",
                  "conditions": {"update_type": "update", "product_ids": product_ids, "product_categories": [], "purchase_type": "all",
                                 # A new order restarts the run, so emails count from the LAST order, as Recapture's did.
                                 "run_multiple": "yes"},
                  "settings": {"subscription_status": "subscribed"},
                  "sticky_note": {"content": " ".join(note)}, "sequences": sequence(emails, group)}
    name = "%s--%s.json" % (program.replace("_", "-"), slug(family))
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as fh:
        json.dump(funnel, fh, ensure_ascii=False, indent=1)
    index.append((name, len(emails), sum(e["active"] for e in emails), json.dumps(conds)[:120]))

for row in index:
    print("%-58s emails=%d active=%d conditions=%s" % row)
for r in sorted(set(report)):
    print("NOTE:", r)
