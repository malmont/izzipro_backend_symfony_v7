-- Console de pilotage (04/11/2026) : tables de plateforme dans la base maître. Idempotent.
-- Appliqué par scripts/migrate_master_v2_platform.sh ; relu par tests/bootstrap.php pour la base maître de test.
-- Retour arrière : DROP TABLE platform_settings (aucun autre objet ne la référence).

-- Réglages de la plateforme, clé → valeur (lot 1 bis) : `mode` = demo | live (demo par défaut : aucune restriction de module ni de droit d'usage).
CREATE TABLE IF NOT EXISTS platform_settings (
    key VARCHAR(64) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_by VARCHAR(255) DEFAULT NULL
);
INSERT INTO platform_settings (key, value) VALUES ('mode', 'demo') ON CONFLICT (key) DO NOTHING;

-- Droits d'usage (lot 3, 04/11/2026) : un droit par site et fonctionnalité du registre (module.*, app.*). source : manual | trial | free |
-- subscription | purchase ; status : active | grace | suspended | expired | cancelled ; ends_at nul = sans fin. Le droit effectif d'un
-- site est projeté sur les colonnes is_*_active de sa fiche Entreprise (EntitlementProjector). Retour arrière : DROP TABLE des cinq tables.
CREATE TABLE IF NOT EXISTS tenant_entitlements (
    id BIGSERIAL PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,
    feature_key VARCHAR(64) NOT NULL,
    source VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    grace_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    offer_code VARCHAR(64) DEFAULT NULL,
    reference VARCHAR(255) DEFAULT NULL,
    granted_by VARCHAR(255) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tenant_entitlements_lookup ON tenant_entitlements (tenant_code, feature_key, status);
CREATE INDEX IF NOT EXISTS idx_tenant_entitlements_ends ON tenant_entitlements (status, ends_at);

-- Journal des changements de droits (événements envoyés, courriels) ; `event` : granted | trial_started | expiring_7 | expiring_1 | expired | grace | suspended | resumed | cancelled | extended
CREATE TABLE IF NOT EXISTS tenant_entitlement_events (
    id BIGSERIAL PRIMARY KEY,
    entitlement_id BIGINT NOT NULL,
    tenant_code VARCHAR(50) NOT NULL,
    feature_key VARCHAR(64) NOT NULL,
    event VARCHAR(32) NOT NULL,
    payload JSONB DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tenant_entitlement_events_lookup ON tenant_entitlement_events (entitlement_id, event);

-- Politique par fonctionnalité : essai autorisé et durée, gratuité permanente autorisée, délai de grâce après impayé (jours)
CREATE TABLE IF NOT EXISTS platform_feature_policies (
    feature_key VARCHAR(64) PRIMARY KEY,
    trial_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    trial_days INT NOT NULL DEFAULT 30,
    free_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    grace_days INT NOT NULL DEFAULT 7,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_by VARCHAR(255) DEFAULT NULL
);

-- Demandes d'un site pour une fonctionnalité de la plateforme (bouton « demander » de Mon site) ; status : pending | granted | refused
CREATE TABLE IF NOT EXISTS tenant_feature_requests (
    id BIGSERIAL PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,
    feature_key VARCHAR(64) NOT NULL,
    requested_by VARCHAR(255) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    decided_by VARCHAR(255) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_tenant_feature_requests_lookup ON tenant_feature_requests (tenant_code, feature_key, status);
