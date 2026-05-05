-- Corrige planos em que Torpedo ficou mais barato que Voz e remove destaque ficticio.
-- A partir do codigo, "Popular" passa a ser calculado por pagamentos recebidos em webhook_pix.

UPDATE mxx_plans p
JOIN (
    SELECT id, value_torpedo AS old_torpedo, value_voice AS old_voice
    FROM mxx_plans
    WHERE value_torpedo > 0
      AND value_voice > 0
      AND value_torpedo < value_voice
) src ON src.id = p.id
SET
    p.value_torpedo = src.old_voice,
    p.value_voice = src.old_torpedo;

UPDATE mxx_plans
SET is_popular = 0;
