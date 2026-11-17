-- Prévention des pertes, lot L6-a (17/11/2026, docs/prevention-pertes.md § 19) : l'offre et les prix du matériel. Idempotent.
-- Base MAÎTRE : les prix et les liens d'achat du matériel sont ceux de la plateforme, les mêmes pour tous les sites.
-- Relu par tests/bootstrap.php pour la base maître de test. Aucune base de site n'est touchée ; aucune ligne existante n'est modifiée.
-- Retour arrière : DROP TABLE loss_hardware_products ; DELETE FROM platform_offers WHERE code IN ('loss_prevention',
-- 'loss_prevention_hardware') (seulement si elles sont encore inactives et sans abonnement).

-- Un article de matériel vendu (une fiche de la boutique de forfaits) : son genre, son niveau dans la gamme (§ 13.10), ses textes,
-- son prix en cents (NULL tant que le propriétaire ne l'a pas fixé), le lien de sa fiche dans la boutique de forfaits (HTTPS), et
-- s'il est montré aux sites. Les lignes posées ici sont INACTIVES et sans prix : le super administrateur les complète.
CREATE TABLE IF NOT EXISTS loss_hardware_products (
    code VARCHAR(64) PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,
    level VARCHAR(10) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    description_fr VARCHAR(600) NOT NULL DEFAULT '',
    description_en VARCHAR(600) NOT NULL DEFAULT '',
    price_cents INT DEFAULT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'CAD',
    buy_url VARCHAR(500) DEFAULT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    position INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_by VARCHAR(255) DEFAULT NULL,
    CONSTRAINT chk_loss_hardware_code CHECK (code ~ '^[a-z0-9_]{2,64}$'),
    CONSTRAINT chk_loss_hardware_kind CHECK (kind IN ('box', 'hand_reader', 'counter_mat', 'zone_antenna', 'printer', 'signal', 'tags')),
    CONSTRAINT chk_loss_hardware_level CHECK (level IN ('pro', 'standard')),
    CONSTRAINT chk_loss_hardware_price CHECK (price_cents IS NULL OR (price_cents >= 0 AND price_cents <= 100000000)),
    CONSTRAINT chk_loss_hardware_currency CHECK (currency ~ '^[A-Z]{3}$'),
    CONSTRAINT chk_loss_hardware_url CHECK (buy_url IS NULL OR buy_url LIKE 'https://%'),
    -- un article montré aux sites a toujours un prix
    CONSTRAINT chk_loss_hardware_active_priced CHECK (NOT is_active OR price_cents IS NOT NULL)
);
INSERT INTO loss_hardware_products (code, kind, level, label_fr, label_en, description_fr, description_en, position) VALUES
    ('box', 'box', 'standard', 'Boîtier prêt à brancher', 'Plug-and-play box',
        'Le mini-ordinateur de la boutique, préinstallé, livré avec sa clé : branchez-le, il se relie à votre site.',
        'The shop''s mini computer, preinstalled, shipped with its key: plug it in, it links to your site.', 10),
    ('hand_reader', 'hand_reader', 'standard', 'Lecteur de puces à main', 'Handheld tag reader',
        'Pour associer une puce à une pièce, vérifier une pièce et faire les comptages.',
        'To associate a tag with a piece, check a piece and run counts.', 20),
    ('counter_mat', 'counter_mat', 'standard', 'Tapis de comptoir', 'Counter mat',
        'Posé sous la caisse : il voit les pièces présentées au paiement.',
        'Placed at the till: it sees the pieces presented for payment.', 30),
    ('zone_antenna', 'zone_antenna', 'pro', 'Antenne de zone', 'Zone antenna',
        'Pour la réserve, le rayon ou la porte arrière : elle dit où une pièce a été vue en dernier.',
        'For the stockroom, the shop floor or the back door: it tells where a piece was last seen.', 40),
    ('printer', 'printer', 'pro', 'Imprimante à puce', 'Tag printer',
        'Imprime l''étiquette et écrit la puce en un seul geste.',
        'Prints the label and writes the tag in one step.', 50),
    ('signal', 'signal', 'pro', 'Avertisseur du portique', 'Exit gate sounder',
        'Branché sur le boîtier : le portique sonne quand un article suivi passe sans être payé (réglage éteint au départ).',
        'Plugged into the box: the gate sounds when a tracked item passes without being paid (setting off at first).', 60),
    ('tags', 'tags', 'standard', 'Étiquettes à puce (rouleau)', 'Tag labels (roll)',
        'Un rouleau d''étiquettes à puce pour l''imprimante.',
        'A roll of tag labels for the printer.', 70)
ON CONFLICT (code) DO NOTHING;

-- Les deux offres du module (décision du propriétaire) : la même clé, le logiciel est le même ; « avec matériel » ajoute le support du
-- matériel vendu. INACTIVES et à 0 tant que le propriétaire n'a pas fixé leur prix dans la console (onglet Offres).
INSERT INTO platform_offers (code, label_fr, label_en, features, monthly_price_cents, trial_days, is_active, position) VALUES
    ('loss_prevention', 'Prévention des pertes, sans matériel', 'Loss prevention, without hardware', '["module.loss_prevention"]', 0, 30, FALSE, 116),
    ('loss_prevention_hardware', 'Prévention des pertes, avec matériel', 'Loss prevention, with hardware', '["module.loss_prevention"]', 0, 30, FALSE, 118)
ON CONFLICT (code) DO NOTHING;
