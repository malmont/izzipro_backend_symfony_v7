-- Prévention des pertes, lot L3-a2 (16/11/2026, docs/prevention-pertes.md § 16.16) : demandes de jumelage des boîtiers. Idempotent.
-- Base MAÎTRE : un boîtier qui attend son jumelage n'a pas encore de site, sa demande ne peut pas vivre dans la base d'un site.
-- C'est la seule table du module hors des bases de site. Relu par tests/bootstrap.php pour la base maître de test.
-- Aucune table existante n'est modifiée ; aucune base de site n'est touchée.
-- Retour arrière : DROP TABLE loss_box_pairing (aucun autre objet ne la référence).

-- Une demande : l'empreinte du code que le boîtier a créé (jamais le code), l'empreinte de sa machine (la preuve qu'il présente à
-- chaque appel), son état (waiting : en attente d'un administrateur ; paired : jumelé à un site ; closed : détaché, le code ne
-- vaut plus rien), le site et le boîtier du site une fois jumelé, et le lien tiré au hasard à chaque jumelage (avec le code et le
-- site, il désigne le boîtier créé par CE jumelage-là). Dates en UTC, sans fuseau.
-- Une demande n'est jamais supprimée par le code : tant qu'elle existe, aucune autre machine ne peut déposer son code.
CREATE TABLE IF NOT EXISTS loss_box_pairing (
    id BIGSERIAL PRIMARY KEY,
    code_hash VARCHAR(64) NOT NULL UNIQUE,
    machine_hash VARCHAR(64) NOT NULL,
    version VARCHAR(40) NOT NULL,
    arch VARCHAR(12) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'waiting',
    tenant_code VARCHAR(50) DEFAULT NULL,
    box_id INT DEFAULT NULL,
    link VARCHAR(32) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    paired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT chk_loss_box_pairing_status CHECK (status IN ('waiting', 'paired', 'closed')),
    -- en attente : aucun site ; jumelée : un site, un boîtier et leur lien, pour de bon (une demande fermée garde les siens, s'il y en a eu)
    CONSTRAINT chk_loss_box_pairing_site CHECK ((status = 'waiting' AND tenant_code IS NULL AND box_id IS NULL AND link IS NULL)
        OR (status = 'paired' AND tenant_code IS NOT NULL AND box_id IS NOT NULL AND link IS NOT NULL) OR status = 'closed')
);

-- Les demandes jumelées d'un site (lecture de contrôle)
CREATE INDEX IF NOT EXISTS idx_loss_box_pairing_site ON loss_box_pairing (tenant_code, box_id) WHERE tenant_code IS NOT NULL;
