#!/usr/bin/env bash
# HTTP checks for FluentCRM > Cart Discounts on gkclone, as an admin, a subscriber and a logged-out visitor.
#
#   GKCLONE_CLI=<wp-env cli container> tests/gkclone/discount-page-http-checks.sh
#
# Mints login cookies with login-cookies.php (which creates a throwaway `t402sub` subscriber),
# restores the discount setting afterwards, and deletes the subscriber.
set -u
C=${GKCLONE_CLI:?set GKCLONE_CLI to the gkclone wp-env cli container name}
B=${GKCLONE_URL:-http://localhost:7701}
S=$(mktemp -d)
trap 'rm -rf "$S"' EXIT
docker cp "$(dirname "$0")/login-cookies.php" "$C:/tmp/login-cookies.php"
docker exec "$C" wp eval-file /tmp/login-cookies.php 2>/dev/null | grep -E '^(ADMIN|SUB)=' > "$S/cookies.txt"
docker exec "$C" rm -f /tmp/login-cookies.php
PAGE="$B/wp-admin/admin.php?page=fluentcrm-cart-discounts"
POST="$B/wp-admin/admin-post.php"
ADMIN=$(sed -n 's/^ADMIN=//p' "$S/cookies.txt")
SUB=$(sed -n 's/^SUB=//p' "$S/cookies.txt")
pass=0; fail=0
check() { if [ "$2" = "1" ]; then echo "PASS $1"; pass=$((pass+1)); else echo "FAIL $1 :: $3"; fail=$((fail+1)); fi; }
opt() { docker exec "$C" wp option get customcrm_edd_ab_cart_discount_profiles --format=json 2>/dev/null; }

BACKUP=$(opt)
LOGMARK=$(docker exec "$C" sh -c 'wc -l < /var/www/html/wp-content/debug.log')

# 1. Admin sees the page and the FluentCRM menu entry.
code=$(curl -s -o "$S/page.html" -w '%{http_code}' -H "Cookie: $ADMIN" "$PAGE")
has_form=$(grep -c 'name="profiles\[pct40_3d\]\[amount\]"' "$S/page.html")
has_menu=$(grep -c 'page=fluentcrm-cart-discounts' "$S/page.html")
check "admin gets the page (200, form, menu link)" "$([ "$code" = 200 ] && [ "$has_form" -ge 1 ] && [ "$has_menu" -ge 1 ] && echo 1)" "code=$code form=$has_form menu=$has_menu"
NONCE=$(grep -o 'name="_wpnonce" value="[a-f0-9]*"' "$S/page.html" | head -1 | sed 's/.*value="//; s/"//')

# 2. Subscriber and logged-out visitors cannot open it.
code=$(curl -s -o "$S/sub.html" -w '%{http_code}' -H "Cookie: $SUB" "$PAGE")
check "subscriber is refused the page" "$([ "$code" = 403 ] && echo 1)" "code=$code"
code=$(curl -s -o /dev/null -w '%{http_code}' "$PAGE")
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$PAGE")
check "logged-out visitor is sent to login" "$([ "$code" = 302 ] && echo "$loc" | grep -q 'wp-login.php' && echo 1)" "code=$code loc=$loc"

# 3. Saving with a bad or missing nonce is refused and changes nothing.
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $ADMIN" --data-urlencode "action=customcrm_save_cart_discounts" --data-urlencode "_wpnonce=deadbeef00" --data-urlencode "profiles[default][amount]=1" "$POST")
check "bad nonce is refused (403) and nothing saved" "$([ "$code" = 403 ] && [ "$(opt)" = "$BACKUP" ] && echo 1)" "code=$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $ADMIN" --data-urlencode "action=customcrm_save_cart_discounts" --data-urlencode "profiles[default][amount]=1" "$POST")
check "missing nonce is refused (403) and nothing saved" "$([ "$code" = 403 ] && [ "$(opt)" = "$BACKUP" ] && echo 1)" "code=$code"

# 4. A subscriber with their own valid nonce is still refused.
SUBNONCE=$(docker exec "$C" wp eval 'wp_set_current_user( (int) username_exists( "t402sub" ) ); echo wp_create_nonce( "customcrm_cart_discounts_save" );' 2>/dev/null | tail -1)
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $SUB" --data-urlencode "action=customcrm_save_cart_discounts" --data-urlencode "_wpnonce=$SUBNONCE" --data-urlencode "profiles[default][amount]=1" "$POST")
# gravitykit.com sends non-admins from wp-admin to /account/ before plugins run; either refusal counts.
check "subscriber with a valid nonce is refused and nothing saved" "$( { [ "$code" = 403 ] || [ "$code" = 302 ]; } && [ "$(opt)" = "$BACKUP" ] && echo 1)" "code=$code"

# 5. Admin save round-trips every field, and hostile input is cleaned.
XSS='<script>alert(1)</script>"><img src=x onerror=alert(2)>'
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $ADMIN" \
  --data-urlencode "action=customcrm_save_cart_discounts" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "profiles[default][label]=$XSS" --data-urlencode "profiles[default][type]=percent" --data-urlencode "profiles[default][amount]=500" \
  --data-urlencode "profiles[default][expiry_hours]=-5" --data-urlencode "profiles[default][min_amount]=12.5" --data-urlencode "profiles[default][prefix]=<b>x" \
  --data-urlencode "profiles[default][delete]=1" \
  --data-urlencode "profiles[pct40_3d][label][]=array-injection" --data-urlencode "profiles[pct40_3d][amount]=40" --data-urlencode "profiles[pct40_3d][type]=bogus" \
  --data-urlencode "profiles[usd20][label]=\$20 off" --data-urlencode "profiles[usd20][type]=flat" --data-urlencode "profiles[usd20][amount]=20" --data-urlencode "profiles[usd20][expiry_hours]=0" --data-urlencode "profiles[usd20][min_amount]=0" --data-urlencode "profiles[usd20][prefix]=CART" \
  --data-urlencode "profiles[__new][slug]=__new" --data-urlencode "profiles[__new][label]=collides" --data-urlencode "profiles[__new][amount]=5" \
  "$POST")
AFTER=$(opt)
echo "$AFTER" > "$S/after.json"
check "admin save redirects back (302)" "$([ "$code" = 302 ] && echo 1)" "code=$code"
python3 - "$S/after.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else ' :: ' + str(detail)))
check('default profile survives a delete request', 'default' in d, list(d))
check('label keeps no HTML tags', '<' not in d['default']['label'], d['default']['label'])
check('percent over 100 is capped at 100', d['default']['amount'] <= 100, d['default']['amount'])
check('negative expiry becomes 0 (never expires)', d['default']['expiry_hours'] == 0, d['default']['expiry_hours'])
check('min amount 12.5 kept', d['default']['min_amount'] == 12.5, d['default']['min_amount'])
check('prefix cleaned to letters and digits', d['default']['prefix'].isalnum(), d['default']['prefix'])
check('array label does not break the save', 'pct40_3d' in d and isinstance(d['pct40_3d']['label'], str), d.get('pct40_3d'))
check('unknown type falls back to percent', d['pct40_3d']['type'] == 'percent', d['pct40_3d']['type'])
check('a profile slugged "__new" is not created', '__new' not in d, list(d))
check('profiles left out of the form are removed', 'pct20' not in d and 'pct10_1d' not in d, list(d))
PY

# 6. Rendered page escapes stored values.
curl -s -o "$S/page2.html" -H "Cookie: $ADMIN" "$PAGE&updated=1"
check "saved notice shown" "$(grep -q 'Discounts saved' "$S/page2.html" && echo 1)" ""
check "no raw script or onerror in the page" "$(! grep -qiE '<script>alert|onerror=alert' "$S/page2.html" && echo 1)" "$(grep -oiE '.{20}(script>alert|onerror=alert).{20}' "$S/page2.html" | head -2)"

# 7. PHP warnings or errors logged during these requests.
NEWLOG=$(docker exec "$C" sh -c "tail -n +$((LOGMARK+1)) /var/www/html/wp-content/debug.log" | grep -E 'PHP (Warning|Notice|Fatal|Deprecated)|fluent-crm-custom-features' | grep -iE 'cart|discount|fluent-crm-custom-features' | head -5)
check "no PHP warnings from the page" "$([ -z "$NEWLOG" ] && echo 1)" "$NEWLOG"

# Restore.
docker exec -e B="$BACKUP" "$C" wp eval 'update_option( "customcrm_edd_ab_cart_discount_profiles", json_decode( getenv( "B" ), true ), false );' 2>/dev/null
check "option restored" "$([ "$(opt)" = "$BACKUP" ] && echo 1)" "$(opt | head -c 120)"
docker exec "$C" wp user delete t402sub --yes >/dev/null 2>&1
echo "shell checks: $pass passed, $fail failed"
