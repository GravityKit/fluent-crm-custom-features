-- Recapture cart-email recipients in the 21 days before the switch-over, for
-- `wp customcrm cart prior-recipients import <csv>`. Run after the final Recapture export
-- is loaded (WEBSITE-406), save the result as CSV.
SELECT LOWER(c.email) AS email, FORMAT_TIMESTAMP('%Y-%m-%dT%H:%M:%SZ', MAX(e.occurred_at)) AS last_sent_at
FROM `monokit-475016.recapture.cart_events_utc` e
JOIN `monokit-475016.recapture.carts` c ON c.cart_id = e.cart_id
WHERE e.event = 'send'
  AND e.occurred_at >= TIMESTAMP_SUB(CURRENT_TIMESTAMP(), INTERVAL 21 DAY)
  AND c.email IS NOT NULL AND c.email != ''
GROUP BY 1
ORDER BY 2 DESC
