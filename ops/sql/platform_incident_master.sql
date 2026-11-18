-- Incidents de la plateforme, lot INC-1a (18/11/2026, docs/incidents-et-signalements.md) : erreurs remontées par toutes les applications
-- (POST /api/incidents/report) et pannes du serveur (5xx), regroupées par une empreinte calculée par le serveur. Idempotent. Base MAÎTRE
-- seulement : aucune base de site n'est touchée, aucune table existante n'est modifiée. Relu par tests/bootstrap.php pour la base
-- maître de test.
-- Retour arrière : DROP TABLE platform_incident_event ; DROP TABLE platform_incident (aucun autre objet ne les référence).
-- Aucune donnée personnelle : le message et le contexte sont masqués avant l'écriture (App\Services\Privacy\PersonalDataMask), le
-- compte d'un utilisateur n'est gardé que par une empreinte. Dates en UTC, sans fuseau.

-- Un incident : une empreinte (site, application, écran ou route, message normalisé), son compte, ses dates, son état.
CREATE TABLE IF NOT EXISTS platform_incident (
    id BIGSERIAL PRIMARY KEY,
    fingerprint VARCHAR(64) NOT NULL,
    tenant_code VARCHAR(50) DEFAULT NULL,
    app VARCHAR(16) NOT NULL,
    app_version VARCHAR(40) DEFAULT NULL,
    screen VARCHAR(160) DEFAULT NULL,
    route VARCHAR(160) DEFAULT NULL,
    level VARCHAR(10) NOT NULL,
    message VARCHAR(200) NOT NULL,
    context TEXT DEFAULT NULL,
    count INT NOT NULL DEFAULT 1,
    anonymous BOOLEAN NOT NULL DEFAULT false,
    first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    status VARCHAR(14) NOT NULL DEFAULT 'new',
    reopened BOOLEAN NOT NULL DEFAULT false,
    note TEXT DEFAULT NULL,
    external_url VARCHAR(500) DEFAULT NULL,
    fixed_in VARCHAR(80) DEFAULT NULL,
    status_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    status_changed_by VARCHAR(255) DEFAULT NULL,
    CONSTRAINT chk_platform_incident_status CHECK (status IN ('new', 'acknowledged', 'fixed', 'ignored')),
    CONSTRAINT chk_platform_incident_level CHECK (level IN ('error', 'crash', 'warning')),
    CONSTRAINT chk_platform_incident_count CHECK (count >= 1)
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_platform_incident_fingerprint ON platform_incident (fingerprint);
CREATE INDEX IF NOT EXISTS idx_platform_incident_list ON platform_incident (status, last_seen_at);
CREATE INDEX IF NOT EXISTS idx_platform_incident_tenant ON platform_incident (tenant_code, first_seen_at);

-- Les dernières occurrences d'un incident (50 au plus par incident, les plus anciennes effacées ; 90 jours au plus).
CREATE TABLE IF NOT EXISTS platform_incident_event (
    id BIGSERIAL PRIMARY KEY,
    incident_id BIGINT NOT NULL REFERENCES platform_incident (id) ON DELETE CASCADE,
    occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    app_version VARCHAR(40) DEFAULT NULL,
    user_hash VARCHAR(16) DEFAULT NULL,
    request_id VARCHAR(64) DEFAULT NULL,
    repeats INT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_platform_incident_event_incident ON platform_incident_event (incident_id, id);
CREATE INDEX IF NOT EXISTS idx_platform_incident_event_date ON platform_incident_event (occurred_at);
