-- Signalements de la plateforme, lot INC-3 (18/11/2026, docs/incidents-et-signalements.md) : un membre du personnel d'un site signale un
-- problème (« Signaler un problème »), ou un agent de l'Assistance transmet un ticket à l'équipe technique (escalade). Idempotent. Base
-- MAÎTRE seulement : aucune base de site n'est touchée, aucune table existante n'est modifiée. Relu par tests/bootstrap.php.
-- Retour arrière : DROP TABLE platform_bug_report (aucun autre objet ne la référence).
-- Aucune donnée personnelle dans le texte (masqué à l'écriture, App\Services\Privacy\PersonalDataMask) ; le signaleur est gardé par son
-- identifiant de compte sur son site (pour « Mes signalements » et le courriel de réponse) et une empreinte. La capture d'écran, qui peut
-- montrer des données de clients, est rangée dans le stockage privé et servie au seul super administrateur ; effacée après la durée
-- réglée (platform_settings, clé bug_report_screenshot_days, 180 jours par défaut). Dates en UTC, sans fuseau.
CREATE TABLE IF NOT EXISTS platform_bug_report (
    id BIGSERIAL PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,
    app VARCHAR(16) NOT NULL,
    app_version VARCHAR(40) DEFAULT NULL,
    screen VARCHAR(160) DEFAULT NULL,
    description TEXT NOT NULL,
    context TEXT DEFAULT NULL,
    screenshot_path VARCHAR(255) DEFAULT NULL,
    screenshot_deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    reporter_kind VARCHAR(16) NOT NULL,
    reporter_user_id INT DEFAULT NULL,
    reporter_hash VARCHAR(16) DEFAULT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'received',
    incident_id BIGINT DEFAULT NULL REFERENCES platform_incident (id) ON DELETE SET NULL,
    answer TEXT DEFAULT NULL,
    note TEXT DEFAULT NULL,
    support_ticket_id INT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    status_changed_by VARCHAR(255) DEFAULT NULL,
    CONSTRAINT chk_platform_bug_report_status CHECK (status IN ('received', 'in_progress', 'fixed', 'wont_fix')),
    CONSTRAINT chk_platform_bug_report_kind CHECK (reporter_kind IN ('site_admin', 'manager', 'cashier', 'support_agent'))
);
CREATE INDEX IF NOT EXISTS idx_platform_bug_report_reporter ON platform_bug_report (tenant_code, reporter_user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_platform_bug_report_list ON platform_bug_report (status, created_at);
-- un seul signalement ouvert par ticket de l'Assistance
CREATE UNIQUE INDEX IF NOT EXISTS uniq_platform_bug_report_open_ticket ON platform_bug_report (tenant_code, support_ticket_id)
    WHERE support_ticket_id IS NOT NULL AND status IN ('received', 'in_progress');
