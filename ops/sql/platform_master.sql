-- Console de pilotage (04/11/2026) : tables de plateforme dans la base maître. Idempotent.
-- Appliqué par scripts/migrate_master_v2_platform.sh ; relu par tests/bootstrap.php pour la base maître de test.
-- Retour arrière : DROP TABLE platform_settings (aucun autre objet ne la référence).

-- Réglages de la plateforme, clé → valeur (lot 1 bis) : `mode` = demo | live (demo par défaut : aucune restriction de module ni de droit d'usage).
CREATE TABLE IF NOT EXISTS platform_settings (
    key VARCHAR(64) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_by VARCHAR(255) DEFAULT NULL
);
INSERT INTO platform_settings (key, value) VALUES ('mode', 'demo') ON CONFLICT (key) DO NOTHING;
