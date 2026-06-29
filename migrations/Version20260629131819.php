<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629131819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // 1. Migrate product categories link to the kept category (minimum ID)
        $this->addSql("
            INSERT INTO product_categories (product_id, categories_id)
            SELECT pc.product_id, kept.kept_id
            FROM product_categories pc
            JOIN categories c ON c.id = pc.categories_id
            JOIN (
                SELECT MIN(id) as kept_id, gemsuite_category_id
                FROM categories
                WHERE gemsuite_category_id IS NOT NULL
                GROUP BY gemsuite_category_id
            ) kept ON kept.gemsuite_category_id = c.gemsuite_category_id
            WHERE c.id > kept.kept_id
            ON CONFLICT DO NOTHING
        ");

        // 2. Migrate rental pack categories link to the kept category (minimum ID)
        $this->addSql("
            INSERT INTO rental_pack_categories (rental_pack_id, categories_id)
            SELECT rpc.rental_pack_id, kept.kept_id
            FROM rental_pack_categories rpc
            JOIN categories c ON c.id = rpc.categories_id
            JOIN (
                SELECT MIN(id) as kept_id, gemsuite_category_id
                FROM categories
                WHERE gemsuite_category_id IS NOT NULL
                GROUP BY gemsuite_category_id
            ) kept ON kept.gemsuite_category_id = c.gemsuite_category_id
            WHERE c.id > kept.kept_id
            ON CONFLICT DO NOTHING
        ");

        // 3. Delete translations associated with duplicate categories
        $this->addSql("
            DELETE FROM categories_translation 
            WHERE category_id IN (
                SELECT c.id 
                FROM categories c
                JOIN (
                    SELECT MIN(id) as kept_id, gemsuite_category_id
                    FROM categories
                    WHERE gemsuite_category_id IS NOT NULL
                    GROUP BY gemsuite_category_id
                ) kept ON kept.gemsuite_category_id = c.gemsuite_category_id
                WHERE c.id > kept.kept_id
            )
        ");

        // 4. Delete the duplicate categories
        $this->addSql("
            DELETE FROM categories c
            WHERE c.id > (
                SELECT MIN(c2.id) 
                FROM categories c2 
                WHERE c2.gemsuite_category_id = c.gemsuite_category_id
            ) AND c.gemsuite_category_id IS NOT NULL
        ");

        // 5. Create unique index
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF34668F19FC82A ON categories (gemsuite_category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3AF34668F19FC82A');
    }
}
