-- Création d'un site avec son type par le super administrateur (lot TYPE-1, 16/11/2026, docs/agences-et-import.md § 9). Idempotent.
-- Base MAÎTRE. Relu par tests/bootstrap.php pour la base maître de test. Aucune table existante n'est modifiée ; aucune base de site
-- n'est touchée. Retour arrière : DROP TABLE platform_site_requests (aucun autre objet ne la référence).

-- Une demande de création : son type (normal | agency), le code et le nom du site, l'administrateur désigné, ses options (langue,
-- modules, nom de l'administrateur, et pour une agence : nom, contact, test, essai, nombre de sites, rôle de responsable), et ses
-- ÉTAPES : une clé par étape (site, invitation, agency, manager), chacune `todo`, `done`, `failed`, `waiting` ou `skipped`.
-- La demande est écrite AVANT la première étape. status : pending (en attente) | building (en cours) | ready (faite) | incomplete
-- (une étape a échoué APRÈS la création du site : à compléter) | failed (échec avant que le site existe) | abandoned (abandonnée :
-- le site, s'il existe, reste un site normal). Une demande vivante retient son code de site : l'index unique partiel garantit qu'un
-- code n'est demandé qu'une fois à la fois.
CREATE TABLE IF NOT EXISTS platform_site_requests (
    id SERIAL PRIMARY KEY,
    type VARCHAR(10) NOT NULL,
    tenant_code VARCHAR(50) NOT NULL,
    name VARCHAR(160) NOT NULL,
    admin_email VARCHAR(180) NOT NULL,
    options JSONB DEFAULT NULL,
    steps JSONB NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    failed_step VARCHAR(12) DEFAULT NULL,
    error_code VARCHAR(40) DEFAULT NULL,
    agency_id INT DEFAULT NULL,
    result JSONB DEFAULT NULL,
    requested_by VARCHAR(255) DEFAULT NULL,
    requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT chk_platform_site_request_type CHECK (type IN ('normal', 'agency')),
    CONSTRAINT chk_platform_site_request_status CHECK (status IN ('pending', 'building', 'ready', 'incomplete', 'failed', 'abandoned'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_platform_site_request_open ON platform_site_requests (tenant_code) WHERE status IN ('pending', 'building', 'incomplete');
CREATE INDEX IF NOT EXISTS idx_platform_site_requests_status ON platform_site_requests (status, id);
