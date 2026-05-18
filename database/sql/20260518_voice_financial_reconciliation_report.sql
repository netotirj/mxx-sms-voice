SELECT
    c.tenancy_id,
    c.user_id,
    COALESCE(NULLIF(c.call_id, ''), c.channel_id) AS call_key,
    MAX(c.call_id) AS call_id,
    MAX(c.channel_id) AS channel_id,
    MAX(c.destination) AS destination,
    MAX(c.trunk) AS trunk_name,
    MAX(c.trunk_id) AS trunk_id,
    MAX(c.trunk_billing_type) AS trunk_billing_type,
    MAX(c.dialstatus) AS dialstatus,
    MAX(COALESCE(c.billsec, c.duration, 0)) AS billed_seconds,
    MAX(COALESCE(c.final_price, c.value, 0)) AS cdr_amount,
    COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0) AS ledger_amount,
    COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) AS committed_ledger_rows
FROM cdr c
LEFT JOIN financial_transaction_ledger l
    ON l.related_type = 'voice_call'
   AND l.related_id = COALESCE(NULLIF(c.call_id, ''), c.channel_id)
WHERE COALESCE(c.final_price, c.value, 0) > 0
  AND LOWER(COALESCE(c.type, 'voice')) IN ('normal', 'voice', 'outbound', 'inbound')
GROUP BY c.tenancy_id, c.user_id, COALESCE(NULLIF(c.call_id, ''), c.channel_id)
HAVING ROUND(MAX(COALESCE(c.final_price, c.value, 0)), 4) <> ROUND(COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0), 4)
ORDER BY c.tenancy_id, call_key;
