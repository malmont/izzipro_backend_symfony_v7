<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique : un PaymentIntent Stripe ne peut valider qu\'une seule commande (index unique partiel sur payments.stripe_payment_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_payments_stripe_payment_id ON payments (stripe_payment_id) WHERE stripe_payment_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_payments_stripe_payment_id');
    }
}
