<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417175814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entreprise ADD gemsuite_payment_method_id INT DEFAULT NULL');
        $this->addSql('ALTER INDEX idx_d34a04ad48e7c567 RENAME TO IDX_D34A04AD8DA3B29F');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER INDEX idx_d34a04ad8da3b29f RENAME TO idx_d34a04ad48e7c567');
        $this->addSql('ALTER TABLE entreprise DROP gemsuite_payment_method_id');
    }
}
