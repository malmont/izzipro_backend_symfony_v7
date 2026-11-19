-- Lot G-1d (19/11/2026, docs/ecrans-et-contextes.md § 17 bis) : le lien « même entreprise » d'une boutique ASSOCIÉE, base maître.
-- L'état courant est porté par le lien ouvert de la boutique (`shop_group_sites`) : un lien fermé (boutique sortie) garde sa valeur, un
-- lien neuf repart à faux. Les demandes du directeur et leurs réponses vivent dans `shop_group_company_requests` (une seule EN ATTENTE
-- par boutique et par groupe : index unique partiel). Le journal reste `shop_group_events`.
-- Idempotent ; aucune ligne existante n'est modifiée (colonnes ajoutées avec leur défaut). Retour arrière :
--   DROP TABLE shop_group_company_requests;
--   ALTER TABLE shop_group_sites DROP COLUMN same_company, DROP COLUMN same_company_since, DROP COLUMN same_company_consent_version;
ALTER TABLE shop_group_sites ADD COLUMN IF NOT EXISTS same_company BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE shop_group_sites ADD COLUMN IF NOT EXISTS same_company_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE shop_group_sites ADD COLUMN IF NOT EXISTS same_company_consent_version VARCHAR(16) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS shop_group_company_requests (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    tenant_code VARCHAR(50) NOT NULL,
    requested_by VARCHAR(255) DEFAULT NULL,
    requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    state VARCHAR(12) NOT NULL DEFAULT 'pending',
    decided_by VARCHAR(255) DEFAULT NULL,
    decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    consent_version VARCHAR(16) DEFAULT NULL,
    CONSTRAINT shop_group_company_requests_state CHECK (state IN ('pending', 'confirmed', 'refused', 'expired', 'cancelled'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_group_company_requests_pending ON shop_group_company_requests (group_id, tenant_code) WHERE state = 'pending';
CREATE INDEX IF NOT EXISTS idx_shop_group_company_requests_site ON shop_group_company_requests (tenant_code) WHERE state = 'pending';
