<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique réglable : modes explicites d\'un produit (vente, location, abonnement, personnalisable), prix de variante en cents, réglages de réservation étendus, devise de l\'entreprise (équivalent de scripts/migrate_all_v2_product_modes.sh, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS sale_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS rental_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS subscription_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS customizable BOOLEAN NOT NULL DEFAULT FALSE');
        // Reprise de l'ancien mode unique, une seule fois : un produit déjà passé en location (ou en vente + location) n'est pas retouché
        $this->addSql("UPDATE product SET rental_enabled = TRUE, sale_enabled = FALSE WHERE mode = 'booking' AND rental_enabled = FALSE AND sale_enabled = TRUE");
        $this->addSql('ALTER TABLE IF EXISTS product_variant ADD COLUMN IF NOT EXISTS price INT DEFAULT NULL');
        foreach ([
            'max_duration INT DEFAULT NULL', 'opening_start VARCHAR(5) DEFAULT NULL', 'opening_end VARCHAR(5) DEFAULT NULL', 'half_days JSON DEFAULT NULL',
            'allowed_dates JSON DEFAULT NULL', 'evening_slot JSON DEFAULT NULL', 'min_days_standard INT DEFAULT NULL', 'deposit INT DEFAULT NULL',
            'extra_passenger_fee INT DEFAULT NULL', 'arrival_lead_minutes INT DEFAULT NULL', 'cancellation_policy TEXT DEFAULT NULL',
            'included JSON DEFAULT NULL', 'excluded JSON DEFAULT NULL', 'notes TEXT DEFAULT NULL',
        ] as $column) {
            $this->addSql("ALTER TABLE IF EXISTS booking_configuration ADD COLUMN IF NOT EXISTS $column");
        }
        $this->addSql("ALTER TABLE IF EXISTS entreprise ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'CAD'");
    }

    public function down(Schema $schema): void
    {
        foreach (['sale_enabled', 'rental_enabled', 'subscription_enabled', 'customizable'] as $column) {
            $this->addSql("ALTER TABLE IF EXISTS product DROP COLUMN IF EXISTS $column");
        }
        $this->addSql('ALTER TABLE IF EXISTS product_variant DROP COLUMN IF EXISTS price');
        foreach (['max_duration', 'opening_start', 'opening_end', 'half_days', 'allowed_dates', 'evening_slot', 'min_days_standard', 'deposit',
            'extra_passenger_fee', 'arrival_lead_minutes', 'cancellation_policy', 'included', 'excluded', 'notes'] as $column) {
            $this->addSql("ALTER TABLE IF EXISTS booking_configuration DROP COLUMN IF EXISTS $column");
        }
        $this->addSql('ALTER TABLE IF EXISTS entreprise DROP COLUMN IF EXISTS currency');
    }
}
