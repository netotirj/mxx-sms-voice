ALTER TABLE cdr
    ADD COLUMN agent_abandoned TINYINT(1) NOT NULL DEFAULT 0 AFTER value,
    ADD COLUMN agent_abandon_reason VARCHAR(150) NULL AFTER agent_abandoned;
