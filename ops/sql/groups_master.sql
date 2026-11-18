-- Lot G-1a (18/11/2026, docs/plan-entree-et-groupe.md § 4, docs/ecrans-et-contextes.md § 12) : multi-boutiques, base maître.
-- Un GROUPE réunit des boutiques (sites) sous un SIÈGE (le site qui l'a créé). Une boutique est dans un groupe au plus (index unique sur
-- les liens ouverts). Une boutique existante n'entre que sur invitation ACCEPTÉE par son administrateur (jamais d'ajout silencieux).
-- Quitter ne supprime rien : le lien se ferme (left_at). Le journal ne dépend d'aucune autre ligne.
-- Idempotent ; retour arrière : DROP TABLE shop_group_events, shop_group_invitations, shop_group_sites, shop_groups.
CREATE TABLE IF NOT EXISTS shop_groups (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    head_tenant_code VARCHAR(50) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    max_shops INT NOT NULL DEFAULT 10,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    created_by VARCHAR(255) DEFAULT NULL,
    dissolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT shop_groups_status CHECK (status IN ('active', 'dissolved'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_groups_head ON shop_groups (head_tenant_code) WHERE status = 'active';

CREATE TABLE IF NOT EXISTS shop_group_sites (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    tenant_code VARCHAR(50) NOT NULL,
    origin VARCHAR(12) NOT NULL,
    joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    joined_by VARCHAR(255) DEFAULT NULL,
    left_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    left_by VARCHAR(255) DEFAULT NULL,
    CONSTRAINT shop_group_sites_origin CHECK (origin IN ('head', 'created', 'associated'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_group_sites_open ON shop_group_sites (tenant_code) WHERE left_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_shop_group_sites_group ON shop_group_sites (group_id) WHERE left_at IS NULL;

CREATE TABLE IF NOT EXISTS shop_group_invitations (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    tenant_code VARCHAR(50) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    invited_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    refused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    answered_by VARCHAR(255) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_shop_group_invitations_site ON shop_group_invitations (tenant_code) WHERE accepted_at IS NULL AND refused_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_shop_group_invitations_group ON shop_group_invitations (group_id, created_at);

CREATE TABLE IF NOT EXISTS shop_group_events (
    id BIGSERIAL PRIMARY KEY,
    group_id INT NOT NULL,
    event VARCHAR(40) NOT NULL,
    tenant_code VARCHAR(50) DEFAULT NULL,
    payload JSON DEFAULT NULL,
    actor VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_shop_group_events_group ON shop_group_events (group_id, id);

-- Lot G-1b : l'entrée du DIRECTEUR du groupe (un administrateur du siège) dans une boutique du groupe, tracée dans site_entries en mode
-- `group`. Le nom du groupe se range dans agency_name (« l'organisation qui entre » : agence ou groupe), son numéro dans group_id.
-- Retour arrière : remettre site_entries_mode à ('agency', 'super_admin') après avoir fermé les entrées `group`, puis DROP COLUMN group_id.
ALTER TABLE site_entries ADD COLUMN IF NOT EXISTS group_id INT DEFAULT NULL;
ALTER TABLE site_entries DROP CONSTRAINT IF EXISTS site_entries_mode;
ALTER TABLE site_entries ADD CONSTRAINT site_entries_mode CHECK (mode IN ('agency', 'super_admin', 'group'));
ALTER TABLE site_entries DROP CONSTRAINT IF EXISTS site_entries_group;
ALTER TABLE site_entries ADD CONSTRAINT site_entries_group CHECK ((mode = 'group') = (group_id IS NOT NULL));
CREATE INDEX IF NOT EXISTS idx_site_entries_group ON site_entries (group_id, target_tenant_code) WHERE ended_at IS NULL;
