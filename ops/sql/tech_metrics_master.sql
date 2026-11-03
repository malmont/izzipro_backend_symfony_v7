-- Tableau de bord technique (T1, 03/11/2026) : mesures techniques et seuils, base maître. Idempotent.
-- Appliqué par scripts/migrate_master_v2_tech_metrics.sh ; relu par tests/bootstrap.php pour la base maître de test.
-- Retour arrière : DROP TABLE tech_metric_sample, tech_threshold (aucun autre objet ne les référence).

CREATE TABLE IF NOT EXISTS tech_metric_sample (
    id BIGSERIAL PRIMARY KEY,
    metric VARCHAR(64) NOT NULL,
    site VARCHAR(64) NOT NULL DEFAULT '',
    subject VARCHAR(160) NOT NULL DEFAULT '',
    period VARCHAR(8) NOT NULL,
    period_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    value NUMERIC(20,3) NOT NULL,
    detail JSONB DEFAULT NULL,
    measured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT uniq_tech_metric_sample UNIQUE (metric, site, subject, period, period_start)
);
CREATE INDEX IF NOT EXISTS idx_tech_metric_sample_lookup ON tech_metric_sample (metric, period, period_start);
CREATE INDEX IF NOT EXISTS idx_tech_metric_sample_site ON tech_metric_sample (site, period, period_start);

CREATE TABLE IF NOT EXISTS tech_threshold (
    metric VARCHAR(64) PRIMARY KEY,
    label VARCHAR(160) NOT NULL,
    unit VARCHAR(16) NOT NULL DEFAULT '',
    direction VARCHAR(4) NOT NULL DEFAULT 'max',
    good NUMERIC(20,3) NOT NULL,
    warn NUMERIC(20,3) NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
);

-- Seuils par défaut : bon jusqu'à `good`, moyen jusqu'à `warn`, mauvais au-delà (direction max). Modifiables dans EasyAdmin (T2).
INSERT INTO tech_threshold (metric, label, unit, direction, good, warn) VALUES
    ('route_peak_mb_max', 'Pic mémoire d''une route (limite PHP-FPM 1024 Mo)', 'Mo', 'max', 256, 512),
    ('route_ms_max', 'Durée maximale d''une route (délai nginx 300 s)', 'ms', 'max', 2000, 10000),
    ('fpm_active_pct', 'Processus PHP-FPM occupés', '%', 'max', 50, 80),
    ('fpm_queue', 'Requêtes en attente d''un processus PHP-FPM', 'req', 'max', 0, 2),
    ('backup_age_s', 'Âge de la dernière sauvegarde réussie (quotidienne)', 's', 'max', 93600, 180000),
    ('timer_last_age_s', 'Âge du dernier passage d''un timer (réconciliation : période 5 min)', 's', 'max', 600, 900),
    ('disk_used_pct', 'Espace disque utilisé', '%', 'max', 70, 85)
ON CONFLICT (metric) DO NOTHING;
