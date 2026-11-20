-- Lot ABO-CONF C (19/11/2026, décision du propriétaire) : journal des changements de la commission de la plateforme sur les abonnements
-- (réglage de la plateforme `platform_settings.subscription_fee_bp`, réglage d'un site `stripe_config.subscription_fee_bp`), lisible du seul
-- super administrateur. Idempotent. Retour arrière : DROP TABLE IF EXISTS platform_fee_log;
CREATE TABLE IF NOT EXISTS platform_fee_log (
    id SERIAL PRIMARY KEY,
    setting VARCHAR(40) NOT NULL,
    tenant_code VARCHAR(64) DEFAULT NULL,
    old_bp INT DEFAULT NULL,
    new_bp INT DEFAULT NULL,
    changed_by VARCHAR(180) DEFAULT NULL,
    changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_platform_fee_log_at ON platform_fee_log (changed_at);
