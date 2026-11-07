<?php

namespace App\Tests\Functional\Platform;

use App\Services\Platform\SiteDefaultsService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Référentiels automatiques (07/11/2026) : taxes d'après le pays (Canada puis France), expéditeur des courriels pris sur la fiche,
 * idempotence (rien n'est posé deux fois), rien sans fiche ou sans pays.
 */
class SiteDefaultsTest extends KernelTestCase
{
    private \Doctrine\DBAL\Connection $db;
    private array $taxes = [];
    private array $sender = [];
    private ?array $address = null;
    private ?bool $ttc = null;
    private mixed $emailBefore = null;
    /**  list<array<string, mixed>> lignes order_tax laissées par d'autres tests : elles référencent les taxes, mises de côté puis remises */
    private array $orderTaxes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->taxes = $this->db->fetchAllAssociative('SELECT * FROM tax ORDER BY id');
        $this->orderTaxes = $this->db->fetchAllAssociative('SELECT * FROM order_tax ORDER BY id');
        $this->db->executeStatement('DELETE FROM order_tax');
        $this->sender = $this->db->fetchAllAssociative('SELECT id, from_email, from_name FROM email_configuration ORDER BY id');
        $this->address = $this->db->fetchAssociative('SELECT id, country, state FROM address_entreprise ORDER BY id LIMIT 1') ?: null;
        $this->ttc = (bool) $this->db->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1');
        $this->emailBefore = $this->db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1');
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM tax');
        foreach ($this->taxes as $t) {
            $this->db->insert('tax', $t);
        }
        foreach ($this->orderTaxes as $row) {
            $this->db->insert('order_tax', $row);
        }
        $this->db->executeStatement('DELETE FROM email_configuration WHERE id NOT IN (' . (implode(',', array_map('intval', array_column($this->sender, 'id'))) ?: '0') . ')');
        foreach ($this->sender as $s) {
            $this->db->executeStatement('UPDATE email_configuration SET from_email = ?, from_name = ? WHERE id = ?', [$s['from_email'], $s['from_name'], $s['id']]);
        }
        if ($this->address) {
            $this->db->executeStatement('UPDATE address_entreprise SET country = ?, state = ? WHERE id = ?', [$this->address['country'], $this->address['state'], $this->address['id']]);
        }
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = ?', [$this->ttc], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        $this->db->executeStatement('UPDATE entreprise SET email = ?', [$this->emailBefore]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTaxesSenderAndIdempotence(): void
    {
        $service = static::getContainer()->get(SiteDefaultsService::class);
        $this->db->executeStatement('DELETE FROM tax');
        $this->db->executeStatement("UPDATE email_configuration SET from_email = ''");
        $email = 'contact-defaults@example.invalid';
        $this->db->executeStatement('UPDATE entreprise SET email = ?', [$email]); // le modèle de test n'a pas de courriel d'entreprise
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear(); // les écritures SQL ne sont pas vues par les entités déjà chargées

        // Canada (adresse du modèle : Canada / QC) : les sept lignes, l'expéditeur
        $result = $service->apply('test');
        $this->assertSame(7, (int) $this->db->fetchOne("SELECT COUNT(*) FROM tax WHERE country = 'CA'"));
        $this->assertSame('0.09975', rtrim((string) $this->db->fetchOne("SELECT rate FROM tax WHERE name = 'TVQ'"), '0'));
        $this->assertSame($email, $this->db->fetchOne('SELECT from_email FROM email_configuration ORDER BY id LIMIT 1'));
        $this->assertStringContainsString('Canada', implode(' ', $result['applied']));
        $this->assertStringContainsString('expéditeur', implode(' ', $result['applied']));

        // Second passage : rien de plus
        $result = $service->apply('test');
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tax'));
        $this->assertSame([], array_filter($result['applied'], fn ($a) => str_contains($a, 'taxes')));
        $this->assertStringContainsString('déjà saisie', implode(' ', $result['skipped']));

        // France : les lignes du modèle canadien sont retirées (aucune commande), la TVA posée avec ses catégories, prix TTC
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'France', state = ''");
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = FALSE');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $result = $service->apply('test');
        $this->assertSame(4, (int) $this->db->fetchOne("SELECT COUNT(*) FROM tax WHERE country = 'FR'"));
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM tax WHERE country = 'CA'"), 'taxes du modèle retirées');
        $this->assertSame('reduced', $this->db->fetchOne("SELECT category FROM tax WHERE name = 'TVA 5,5 %'"));
        $this->assertNull($this->db->fetchOne("SELECT category FROM tax WHERE name = 'TVA 20 %'"), 'taux standard sans catégorie');
        $this->assertTrue((bool) $this->db->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1'), 'France : prix TTC');
        $this->assertStringContainsString('France', implode(' ', $result['applied']));

        // Pays non couvert : rien, et on le dit
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'DE', state = ''");
        $this->db->executeStatement('DELETE FROM tax');
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $result = $service->apply('test');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tax'));
        $this->assertStringContainsString('DE', implode(' ', $result['skipped']));
    }
}
