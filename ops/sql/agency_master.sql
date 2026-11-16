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

-- Lot AG-2 (15/11/2026, docs/agences-et-import.md § 8) : création d'un site par l'agence, par une file. Idempotent.
-- Retour arrière : DROP TABLE agency_site_requests ; ALTER TABLE agencies DROP COLUMN daily_site_requests (les colonnes max_sites
-- redevenues obligatoires seulement si aucune ligne n'est vide).
-- Le nombre de sites d'un forfait peut valoir « illimité » : NULL.
ALTER TABLE agencies ALTER COLUMN max_sites DROP NOT NULL;
ALTER TABLE agency_plans ALTER COLUMN max_sites DROP NOT NULL;
-- Garde-fou technique : nombre de demandes de création par 24 heures, réglable par agence par le super administrateur.
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS daily_site_requests INT NOT NULL DEFAULT 5;

-- La file des demandes de création. status : pending (en attente) | building (en création) | ready (prêt) | failed (en échec).
-- options : ce que la demande précise (langue, pays, province, devise, fuseau, modules, nom de l'administrateur) ; result : ce que la
-- fabrication a fait (invitation, droits, signalements). Une demande en cours (pending, building) retient son code de site : l'index
-- unique partiel garantit qu'un code n'est demandé qu'une fois à la fois, quelles que soient les demandes simultanées.
CREATE TABLE IF NOT EXISTS agency_site_requests (
    id SERIAL PRIMARY KEY,
    agency_id INT NOT NULL REFERENCES agencies (id),
    tenant_code VARCHAR(50) NOT NULL,
    name VARCHAR(160) NOT NULL,
    admin_email VARCHAR(180) NOT NULL,
    options JSONB DEFAULT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    error_code VARCHAR(40) DEFAULT NULL,
    result JSONB DEFAULT NULL,
    requested_by VARCHAR(255) DEFAULT NULL,
    requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_agency_site_request_open ON agency_site_requests (tenant_code) WHERE status IN ('pending', 'building');
CREATE INDEX IF NOT EXISTS idx_agency_site_requests_agency ON agency_site_requests (agency_id, id);
CREATE INDEX IF NOT EXISTS idx_agency_site_requests_status ON agency_site_requests (status, id);

-- ============================================================================================================================
-- Lot AG-3b (16/11/2026, docs/ecrans-et-contextes.md § 7) : JOURNAL DES ENTRÉES dans le site d'un autre. Une ligne par jeton
-- d'entrée remis : qui (compte du site d'attache, son prénom ; agence), d'où (site d'attache), vers où (site visé), quand, dans
-- quel mode (agency | super_admin), jusqu'à quand. ended_at : l'entrée est fermée avant son échéance (déconnexion du site
-- d'attache, rôle retiré, agence suspendue, site détaché) ; la garde relit cette ligne à chaque requête (cache d'une minute au plus).
-- Le journal ne dépend d'aucune autre ligne (pas de clé étrangère) : il garde le nom de l'agence tel qu'il était à l'entrée.
-- Retour arrière : DROP TABLE site_entries (aucune autre table n'en dépend).
CREATE TABLE IF NOT EXISTS site_entries (
    id BIGSERIAL PRIMARY KEY,
    mode VARCHAR(20) NOT NULL,
    home_tenant_code VARCHAR(50) NOT NULL,
    target_tenant_code VARCHAR(50) NOT NULL,
    account_id INT NOT NULL,
    account_email VARCHAR(180) NOT NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    agency_id INT DEFAULT NULL,
    agency_name VARCHAR(160) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    ended_reason VARCHAR(30) DEFAULT NULL,
    CONSTRAINT site_entries_mode CHECK (mode IN ('agency', 'super_admin')),
    CONSTRAINT site_entries_agency CHECK ((mode = 'agency') = (agency_id IS NOT NULL)),
    CONSTRAINT site_entries_elsewhere CHECK (home_tenant_code <> target_tenant_code)
);
CREATE INDEX IF NOT EXISTS idx_site_entries_account ON site_entries (home_tenant_code, account_id) WHERE ended_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_site_entries_target ON site_entries (target_tenant_code, id);
CREATE INDEX IF NOT EXISTS idx_site_entries_agency ON site_entries (agency_id, id);
