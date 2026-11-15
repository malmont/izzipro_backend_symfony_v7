-- Agences (lot AG-1, 15/11/2026, docs/agences-et-import.md § 3) : tables de la base maître. Idempotent.
-- Appliqué par scripts/migrate_master_v2_agencies.sh ; relu par tests/bootstrap.php pour la base maître de test.
-- Aucune table existante n'est modifiée ; aucune base de site n'est touchée.
-- Retour arrière : DROP TABLE agency_events, agency_sites, agency_plans, agencies (aucun autre objet ne les référence).

-- Une agence : son site d'attache (unique : un site n'est le site d'attache que d'une agence), son état (trial | active | suspended |
-- closed), la fin de son essai, le nombre de sites qu'elle peut gérer (le site d'attache n'est pas compté). Dates en UTC, sans fuseau.
CREATE TABLE IF NOT EXISTS agencies (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    home_tenant_code VARCHAR(50) NOT NULL UNIQUE,
    status VARCHAR(12) NOT NULL DEFAULT 'trial',
    trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    max_sites INT NOT NULL DEFAULT 3,
    plan_code VARCHAR(40) DEFAULT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'CAD',
    contact_email VARCHAR(180) DEFAULT NULL,
    is_test BOOLEAN NOT NULL DEFAULT FALSE,
    suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    created_by VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- Les sites gérés par une agence. origin : attached (rattaché par la plateforme) | created (créé par l'agence, lot AG-2). Un site n'a
-- qu'une agence à la fois : l'index unique partiel le garantit, quelles que soient les demandes simultanées.
CREATE TABLE IF NOT EXISTS agency_sites (
    id SERIAL PRIMARY KEY,
    agency_id INT NOT NULL REFERENCES agencies (id),
    tenant_code VARCHAR(50) NOT NULL,
    origin VARCHAR(12) NOT NULL DEFAULT 'attached',
    attached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    attached_by VARCHAR(255) DEFAULT NULL,
    detached_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    detached_by VARCHAR(255) DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_agency_site_live ON agency_sites (tenant_code) WHERE detached_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_agency_sites_agency ON agency_sites (agency_id, detached_at);

-- Forfaits d'agence. Une ligne sans agence = la grille commune ; une ligne portant une agence = son forfait négocié. Les prix (prix de
-- gros par site, options, unités IA) arrivent au lot AG-5 : ici seulement les limites de l'essai.
CREATE TABLE IF NOT EXISTS agency_plans (
    id SERIAL PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    agency_id INT DEFAULT NULL REFERENCES agencies (id),
    label VARCHAR(160) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'CAD',
    trial_days INT NOT NULL DEFAULT 60,
    max_sites INT NOT NULL DEFAULT 3,
    prices JSONB DEFAULT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_by VARCHAR(255) DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_agency_plan_common ON agency_plans (code) WHERE agency_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_agency_plan_own ON agency_plans (code, agency_id) WHERE agency_id IS NOT NULL;
INSERT INTO agency_plans (code, label, trial_days, max_sites) SELECT 'essai', 'Essai d''agence', 60, 3 WHERE NOT EXISTS (SELECT 1 FROM agency_plans WHERE code = 'essai' AND agency_id IS NULL);

-- Journal d'une agence, en ajout seul : created, site_attached, site_detached, suspended, resumed, role_granted, role_revoked.
CREATE TABLE IF NOT EXISTS agency_events (
    id BIGSERIAL PRIMARY KEY,
    agency_id INT NOT NULL REFERENCES agencies (id),
    tenant_code VARCHAR(50) DEFAULT NULL,
    event VARCHAR(40) NOT NULL,
    payload JSONB DEFAULT NULL,
    actor VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_agency_events_agency ON agency_events (agency_id, id);
