-- Accès d'assistance d'une agence (lot AG-ASSIST, 16/11/2026, docs/ecrans-et-contextes.md § 8). Idempotent.
-- Base MAÎTRE : la demande relie un site et l'agence qui le gère ; l'agence la lit depuis son site d'attache.
-- Le COMMERÇANT (administrateur du site) ouvre l'assistance pour UNE section fermée à l'agence, en lecture seule (read) ou en lecture
-- et écriture (write) ; la fenêtre d'une heure part de la première requête de l'opérateur de l'agence dans le site ; une demande sans
-- entrée en 24 h expire. L'état se calcule à la lecture (aucun minuteur) ; une ligne n'est jamais supprimée par le code.
-- Relu par tests/bootstrap.php pour la base maître de test. Aucune table existante n'est modifiée ; aucune base de site n'est touchée.
-- Retour arrière : DROP TABLE assistance_grants (aucun autre objet ne la référence).
CREATE TABLE IF NOT EXISTS assistance_grants (
    id SERIAL PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,
    agency_id INT NOT NULL REFERENCES agencies (id),
    section VARCHAR(30) NOT NULL,
    mode VARCHAR(5) NOT NULL,
    requested_by_id INT NOT NULL,
    requested_by VARCHAR(180) NOT NULL,
    requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    request_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    closed_by VARCHAR(180) DEFAULT NULL,
    closed_reason VARCHAR(12) DEFAULT NULL,
    CONSTRAINT chk_assistance_grants_mode CHECK (mode IN ('read', 'write')),
    CONSTRAINT chk_assistance_grants_window CHECK ((started_at IS NULL AND ends_at IS NULL) OR (started_at IS NOT NULL AND ends_at IS NOT NULL)),
    CONSTRAINT chk_assistance_grants_closed CHECK (closed_reason IS NULL OR closed_reason IN ('merchant', 'expired', 'ended', 'agency'))
);
-- une seule demande non fermée par site et par section, quelles que soient les demandes simultanées (une demande échue est fermée
-- d'abord, dans la même transaction, par le code)
CREATE UNIQUE INDEX IF NOT EXISTS uniq_assistance_grants_live ON assistance_grants (tenant_code, section) WHERE closed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_assistance_grants_agency ON assistance_grants (agency_id, closed_at);
