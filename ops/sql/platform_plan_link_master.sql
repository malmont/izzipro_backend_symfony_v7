-- Lien prouvé entre l'acheteur d'un forfait et le site servi, lot PROVISION-1 (18/11/2026, docs/console-pilotage.md, docs/audit-provision.md).
-- « Mettre à jour mon forfait » (administrateur du site X) émet un lien signé, court (30 minutes), qui ne sert qu'à UN abonnement de la
-- boutique de la plateforme. Idempotent. Base MAÎTRE seulement : aucune base de site n'est touchée, aucune table existante n'est modifiée.
-- Relu par tests/bootstrap.php. Retour arrière : DROP TABLE platform_plan_link (aucun autre objet ne la référence).
-- Le jeton lui-même n'est JAMAIS gardé : seulement son identifiant (jti), le site, le compte administrateur et l'usage. Dates en UTC.
CREATE TABLE IF NOT EXISTS platform_plan_link (
    jti VARCHAR(32) PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,
    user_id INT NOT NULL,
    issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    -- l'acheteur et la formule qui l'ont employé (site boutique, compte client, formule) : un nouvel essai du même achat est accepté
    used_by VARCHAR(120) DEFAULT NULL,
    used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    -- l'abonnement créé (preuve, lue par le super administrateur)
    shop_tenant VARCHAR(50) DEFAULT NULL,
    subscription_id INT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_platform_plan_link_tenant ON platform_plan_link (tenant_code, issued_at);
CREATE INDEX IF NOT EXISTS idx_platform_plan_link_subscription ON platform_plan_link (shop_tenant, subscription_id);
