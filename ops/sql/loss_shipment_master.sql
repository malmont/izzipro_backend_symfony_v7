-- Prévention des pertes, lot L6-b (17/11/2026, docs/prevention-pertes.md § 19.5) : boîtiers vendus, expédiés, reconnus au premier
-- branchement. Idempotent. Base MAÎTRE : une expédition relie un code d'étiquette à un site AVANT que le boîtier ait un site.
-- Relu par tests/bootstrap.php pour la base maître de test. Aucune base de site n'est touchée ; une seule table existante change :
-- loss_box_pairing reçoit la colonne `serial` (nullable, sans toucher à ses lignes).
-- Retour arrière : DROP TABLE loss_box_shipment_event ; DROP TABLE loss_box_shipment (aucun autre objet ne les référence) ;
-- ALTER TABLE loss_box_pairing DROP COLUMN serial.

-- Une expédition : le numéro de série du boîtier, l'EMPREINTE du code de son étiquette (la même que celle du jumelage : jamais le code,
-- rien de réversible), le site acheteur et la commande. Au premier branchement (le boîtier dépose sa demande de jumelage avec ce code),
-- le serveur prépare ce qui reliera le futur boîtier du site, lié au site et à l'aléa du jumelage (`key_hash`, `ref`, `link`), comme le
-- fait la saisie du code ; ces empreintes s'effacent au retour à `reserved` (72 h sans confirmation), à la confirmation et à
-- l'annulation. Dates en UTC, sans fuseau.
CREATE TABLE IF NOT EXISTS loss_box_shipment (
    id BIGSERIAL PRIMARY KEY,
    serial VARCHAR(64) NOT NULL,
    code_hash VARCHAR(64) NOT NULL,
    tenant_code VARCHAR(50) NOT NULL,
    order_ref VARCHAR(80) NOT NULL DEFAULT '',
    status VARCHAR(12) NOT NULL DEFAULT 'reserved',
    key_hash VARCHAR(64) DEFAULT NULL,
    ref VARCHAR(64) DEFAULT NULL,
    link VARCHAR(32) DEFAULT NULL,
    box_id INT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    plugged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    confirmed_by INT DEFAULT NULL,
    cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    cancelled_by VARCHAR(255) DEFAULT NULL,
    CONSTRAINT chk_loss_box_shipment_status CHECK (status IN ('reserved', 'plugged', 'confirmed', 'cancelled')),
    -- branchée : les empreintes préparées et l'heure du branchement ; sinon, aucune empreinte gardée
    CONSTRAINT chk_loss_box_shipment_prepared CHECK ((status = 'plugged' AND key_hash IS NOT NULL AND ref IS NOT NULL AND link IS NOT NULL AND plugged_at IS NOT NULL)
        OR (status <> 'plugged' AND key_hash IS NULL AND ref IS NULL AND link IS NULL)),
    CONSTRAINT chk_loss_box_shipment_confirmed CHECK (status <> 'confirmed' OR (confirmed_at IS NOT NULL AND box_id IS NOT NULL)),
    CONSTRAINT chk_loss_box_shipment_cancelled CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_loss_box_shipment_tenant ON loss_box_shipment (tenant_code, status);
-- un code, un numéro de série : une seule expédition vivante à la fois ; une expédition annulée les libère
CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_box_shipment_code ON loss_box_shipment (code_hash) WHERE status <> 'cancelled';
CREATE UNIQUE INDEX IF NOT EXISTS uniq_loss_box_shipment_serial ON loss_box_shipment (serial) WHERE status <> 'cancelled';

-- La série du boîtier, dite par sa machine dans sa demande de jumelage (décision du chef de projet sur la relecture, B2) : à la
-- réservation, la série saisie doit être celle de la demande liée au code. La première dite est gardée (comme le code). Colonne
-- ajoutée à la table du lot L3-a2 (base maître), sans rien changer à ses lignes.
ALTER TABLE loss_box_pairing ADD COLUMN IF NOT EXISTS serial VARCHAR(64) DEFAULT NULL;

-- Le journal des expéditions (base maître) : réservée, branchée, revenue à reserved, confirmée, refusée, annulée, code envoyé (lot L6-c :
-- le nombre d'administrateurs prévenus, jamais le code) ; avec l'auteur.
-- Jamais le code, jamais une empreinte.
CREATE TABLE IF NOT EXISTS loss_box_shipment_event (
    id BIGSERIAL PRIMARY KEY,
    shipment_id BIGINT NOT NULL REFERENCES loss_box_shipment (id),
    type VARCHAR(20) NOT NULL,
    actor VARCHAR(255) DEFAULT NULL,
    tenant_code VARCHAR(50) DEFAULT NULL,
    data JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT chk_loss_box_shipment_event_type CHECK (type IN ('reserved', 'plugged', 'unplugged', 'confirmed', 'refused', 'cancelled', 'code_sent'))
);
CREATE INDEX IF NOT EXISTS idx_loss_box_shipment_event ON loss_box_shipment_event (shipment_id, id);
