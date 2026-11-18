-- Paiements des offres, lot PROVISION-3 (18/11/2026, docs/console-pilotage.md, « Paiements des offres »). Webhook Stripe solide :
-- journal des événements, périodes payées, file de reprise des droits. Idempotent. Base MAÎTRE seulement : aucune base de site touchée,
-- aucune table existante modifiée. Relu par tests/bootstrap.php. Retour arrière : DROP TABLE des trois tables (rien ne les référence).
-- AUCUN contenu d'événement n'est gardé (données de carte, coordonnées) : seulement des identifiants, des dates et des états. Dates en UTC.

-- Un événement Stripe des abonnements : reçu une fois, traité une fois ; un événement déjà traité répond 200 sans rien refaire.
-- Conservation 90 jours (app:platform:offers:reconcile --all).
CREATE TABLE IF NOT EXISTS stripe_event_log (
    event_id VARCHAR(255) PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    account VARCHAR(64) DEFAULT NULL,           -- compte Stripe connecté, s'il y en a un
    tenant_code VARCHAR(50) DEFAULT NULL,       -- site qui porte l'abonnement (métadonnées)
    object_id VARCHAR(64) DEFAULT NULL,         -- abonnement Stripe visé
    event_created TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, -- date de l'événement chez Stripe
    received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    status VARCHAR(20) NOT NULL,                -- received | processed | ignored | failed
    detail VARCHAR(120) DEFAULT NULL,           -- court, sans donnée personnelle (ex. out_of_order)
    attempts INT NOT NULL DEFAULT 1,
    last_error_class VARCHAR(160) DEFAULT NULL,
    processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_stripe_event_log_received ON stripe_event_log (received_at);
CREATE INDEX IF NOT EXISTS idx_stripe_event_log_object ON stripe_event_log (object_id);

-- Une facture payée d'un abonnement, une seule fois : l'événement « période payée » n'est émis qu'à l'insertion (invoice.paid et
-- invoice.payment_succeeded de la même facture n'en font qu'un). Registre gardé : les droits nés d'une période (lot ABO-1) s'y rattachent.
CREATE TABLE IF NOT EXISTS subscription_period_paid (
    tenant_code VARCHAR(50) NOT NULL,
    invoice_id VARCHAR(255) NOT NULL,
    subscription_id INT NOT NULL,
    period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    amount_paid INT NOT NULL DEFAULT 0,         -- centimes
    billing_reason VARCHAR(40) DEFAULT NULL,    -- subscription_create, subscription_cycle, subscription_update (prorata)…
    recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, -- l'événement « période payée » a été traité par ses écouteurs
    PRIMARY KEY (tenant_code, invoice_id)
);
CREATE INDEX IF NOT EXISTS idx_subscription_period_paid_sub ON subscription_period_paid (tenant_code, subscription_id);

-- File de reprise : un abonnement branché dont les droits n'ont pas pu être posés. La reprise relit l'état chez Stripe et recalcule les
-- droits VOULUS, sans rejouer d'événement ; paliers 5 min, 15 min, 1 h, 3 h, puis 6 h ; incident de la plateforme au 5e échec.
CREATE TABLE IF NOT EXISTS platform_offer_retry (
    id SERIAL PRIMARY KEY,
    tenant_code VARCHAR(50) NOT NULL,           -- site boutique qui porte l'abonnement
    subscription_id INT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    last_error_class VARCHAR(160) DEFAULT NULL,
    alerted BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    UNIQUE (tenant_code, subscription_id)
);
CREATE INDEX IF NOT EXISTS idx_platform_offer_retry_next ON platform_offer_retry (next_retry_at);
