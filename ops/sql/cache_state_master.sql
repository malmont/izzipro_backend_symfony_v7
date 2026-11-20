-- Lot CACHE-ETAT (20/11/2026, contrat validé par le chef) : ce qui gardait un ÉTAT dans le cache Redis et ne doit jamais se perdre passe
-- en base maître. Idempotent. Retour arrière : DROP TABLE IF EXISTS stripe_object_ref; DROP TABLE IF EXISTS essai_job;
--
-- Identifiants des objets Stripe créés à la demande (taux de taxe, produit et prix de livraison des abonnements) : une ligne par compte
-- Stripe (identifiant du compte connecté, ou « platform »), MODE (test / live), genre et clé (sha256 de TOUS les paramètres de création).
-- Création sous verrou consultatif (StripeObjectRefStore) : jamais de doublon chez Stripe, même pour deux appels parallèles. Un objet
-- disparu ou archivé chez Stripe est recréé sous le même verrou : generation + 1 (préfixe de la clé d'idempotence).
CREATE TABLE IF NOT EXISTS stripe_object_ref (
    id SERIAL PRIMARY KEY,
    account VARCHAR(255) NOT NULL,
    mode VARCHAR(4) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    ref_key CHAR(64) NOT NULL,
    stripe_id VARCHAR(255) NOT NULL,
    generation INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT stripe_object_ref_unique UNIQUE (account, mode, kind, ref_key)
);

-- État du dernier remplissage d'un site d'essai (lot ESSAI-DONNÉES ; avant : cache partagé, 7 jours) : une ligne par site, état JSON,
-- relu 7 jours au plus.
CREATE TABLE IF NOT EXISTS essai_job (
    tenant_code VARCHAR(64) PRIMARY KEY,
    state JSONB NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
);
