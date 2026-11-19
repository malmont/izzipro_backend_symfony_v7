-- Lot G-5 (19/11/2026, docs/ecrans-et-contextes.md § 17) : transfert léger entre boutiques d'un groupe, base maître. L'ÉTAT du transfert fait
-- foi (trois bases sans transaction commune) : requested → sending → sent → received, ou send_failed, ou cancelled. Les lignes figent la
-- correspondance des variantes (G-4) et le coût unitaire à l'envoi. Le journal ne dépend d'aucune autre ligne.
-- Idempotent ; aucune table existante n'est touchée. Retour arrière : DROP TABLE shop_group_transfer_events, shop_group_transfer_lines, shop_group_transfers.
CREATE TABLE IF NOT EXISTS shop_group_transfers (
    id SERIAL PRIMARY KEY,
    uid VARCHAR(40) NOT NULL UNIQUE,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    source_tenant_code VARCHAR(50) NOT NULL,
    target_tenant_code VARCHAR(50) NOT NULL,
    state VARCHAR(12) NOT NULL DEFAULT 'requested',
    attempt INT NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT NULL,
    sending_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    target_commande_id INT DEFAULT NULL,
    requested_by VARCHAR(255) DEFAULT NULL,
    requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    sent_by VARCHAR(255) DEFAULT NULL,
    sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    cancelled_by VARCHAR(255) DEFAULT NULL,
    cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT shop_group_transfers_state CHECK (state IN ('requested', 'sending', 'sent', 'send_failed', 'received', 'cancelled'))
);
CREATE INDEX IF NOT EXISTS idx_shop_group_transfers_group ON shop_group_transfers (group_id, id);
CREATE INDEX IF NOT EXISTS idx_shop_group_transfers_source ON shop_group_transfers (source_tenant_code, state);
CREATE INDEX IF NOT EXISTS idx_shop_group_transfers_sending ON shop_group_transfers (sending_since) WHERE state = 'sending';

CREATE TABLE IF NOT EXISTS shop_group_transfer_lines (
    id SERIAL PRIMARY KEY,
    transfer_id INT NOT NULL REFERENCES shop_group_transfers (id) ON DELETE CASCADE,
    line_no INT NOT NULL,
    source_product_id INT NOT NULL,
    source_variant_id INT NOT NULL,
    target_product_id INT NOT NULL,
    target_variant_id INT DEFAULT NULL,
    quantity INT NOT NULL,
    unit_cost_cents INT DEFAULT NULL,
    CONSTRAINT shop_group_transfer_lines_quantity CHECK (quantity > 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_group_transfer_lines ON shop_group_transfer_lines (transfer_id, line_no);

CREATE TABLE IF NOT EXISTS shop_group_transfer_events (
    id BIGSERIAL PRIMARY KEY,
    transfer_id INT NOT NULL,
    event VARCHAR(40) NOT NULL,
    payload JSON DEFAULT NULL,
    actor VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_shop_group_transfer_events_transfer ON shop_group_transfer_events (transfer_id, id);
