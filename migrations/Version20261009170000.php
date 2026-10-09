<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avis clients : reviews_product complétée (statut, titre, achat vérifié, réponse, dates), product.rating_average et rating_count, table review_setting ; équivalent de scripts/migrate_all_v2_reviews.sh, idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS title VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS order_id INT DEFAULT NULL REFERENCES "order" (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT \'pending\'');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS verified_purchase BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS author_name VARCHAR(60) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NOT NULL DEFAULT \'fr\'');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS reply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD COLUMN IF NOT EXISTS published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_review_product_user ON reviews_product (product_reviews_id, user_review_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_review_product_status ON reviews_product (product_reviews_id, status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_review_order ON reviews_product (order_id)');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product DROP CONSTRAINT IF EXISTS fk_e0851d6c13f58654');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD CONSTRAINT fk_e0851d6c13f58654 FOREIGN KEY (product_reviews_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product DROP CONSTRAINT IF EXISTS fk_e0851d6c3ece1b7f');
        $this->addSql('ALTER TABLE IF EXISTS reviews_product ADD CONSTRAINT fk_e0851d6c3ece1b7f FOREIGN KEY (user_review_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS rating_average DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE IF EXISTS product ADD COLUMN IF NOT EXISTS rating_count INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE TABLE IF NOT EXISTS review_setting (
    id SERIAL PRIMARY KEY,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    verified_only BOOLEAN NOT NULL DEFAULT TRUE,
    moderation VARCHAR(10) NOT NULL DEFAULT \'manual\',
    min_length INT NOT NULL DEFAULT 20,
    policy JSON DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS review_setting');
        $this->addSql('ALTER TABLE IF EXISTS product DROP COLUMN IF EXISTS rating_count');
        $this->addSql('ALTER TABLE IF EXISTS product DROP COLUMN IF EXISTS rating_average');
    }
}
