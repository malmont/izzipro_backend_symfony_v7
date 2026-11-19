-- Lot G-4 (19/11/2026, docs/ecrans-et-contextes.md § 15) : copie du catalogue entre boutiques d'un groupe, base maître.
-- `shop_group_copies` : l'index des copies lancées par le directeur (la tâche elle-même est un `import_job` de la boutique CIBLE, source
-- `group` : la cible la voit dans ses imports). `shop_group_item_links` : la correspondance article et variante source → copie, qui
-- servira au transfert (G-5) et aux prix sur plusieurs boutiques (G-6). Une copie annulée retire les liens des articles retirés.
-- Idempotent ; aucune table existante n'est touchée. Retour arrière : DROP TABLE shop_group_item_links, shop_group_copies.
CREATE TABLE IF NOT EXISTS shop_group_copies (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    source_tenant_code VARCHAR(50) NOT NULL,
    target_tenant_code VARCHAR(50) NOT NULL,
    target_job_id INT NOT NULL,
    with_cost BOOLEAN NOT NULL DEFAULT FALSE,
    source_prices_include_tax BOOLEAN NOT NULL DEFAULT FALSE,
    articles INT NOT NULL DEFAULT 0,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT shop_group_copies_sites CHECK (source_tenant_code <> target_tenant_code)
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_group_copies_job ON shop_group_copies (target_tenant_code, target_job_id);
CREATE INDEX IF NOT EXISTS idx_shop_group_copies_group ON shop_group_copies (group_id, created_at DESC);

CREATE TABLE IF NOT EXISTS shop_group_item_links (
    id BIGSERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES shop_groups (id),
    copy_id INT NOT NULL REFERENCES shop_group_copies (id) ON DELETE CASCADE,
    source_tenant_code VARCHAR(50) NOT NULL,
    source_product_id INT NOT NULL,
    source_variant_id INT DEFAULT NULL,
    target_tenant_code VARCHAR(50) NOT NULL,
    target_product_id INT NOT NULL,
    target_variant_id INT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shop_group_item_links_target ON shop_group_item_links (target_tenant_code, target_product_id, COALESCE(target_variant_id, 0));
CREATE INDEX IF NOT EXISTS idx_shop_group_item_links_source ON shop_group_item_links (source_tenant_code, source_product_id);
