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
    /** plus grand id de app_profile avant le test : les profils créés par le service sont retirés (sinon doublon « boussole » dans un autre test) */
    private int $profileMax = 0;
    private \PDO $master;
    private array $aiFlags = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->taxes = $this->db->fetchAllAssociative('SELECT * FROM tax ORDER BY id');
        $this->orderTaxes = $this->db->fetchAllAssociative('SELECT * FROM order_tax ORDER BY id');
        $this->db->executeStatement('DELETE FROM order_tax');
        $this->profileMax = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM app_profile');
        $this->sender = $this->db->fetchAllAssociative('SELECT id, from_email, from_name FROM email_configuration ORDER BY id');
        $this->address = $this->db->fetchAssociative('SELECT id, country, state FROM address_entreprise ORDER BY id LIMIT 1') ?: null;
        $this->ttc = (bool) $this->db->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1');
        $this->emailBefore = $this->db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1');
        // Essai « Aide IA » à la création (08/11/2026) : on part d'un site sans aucun droit IA, puis on remet « IA complète » de l'amorçage
        $this->master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]);
        $this->aiFlags = $this->db->fetchAssociative('SELECT is_ai_help_active, is_ai_full_active FROM entreprise ORDER BY id LIMIT 1') ?: [];
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM tax');
        $sql = fn (array $row) => array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, $row); // booléens (order_tax.prices_include_tax, PAYS-1) : texte PostgreSQL
        foreach ($this->taxes as $t) {
            $this->db->insert('tax', $sql($t));
        }
        foreach ($this->orderTaxes as $row) {
            $this->db->insert('order_tax', $sql($row));
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
        $this->db->executeStatement('DELETE FROM app_profile WHERE id > ?', [$this->profileMax]);
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]);
        $this->master->exec("DELETE FROM tenant_entitlement_events WHERE feature_key IN ('ai.help', 'ai.full')");
        $this->master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'bootstrap', 'IA complète du site de test')")->execute([MV_TEST_TENANT_CODE]);
        if ($this->aiFlags !== []) {
            $this->db->executeStatement('UPDATE entreprise SET is_ai_help_active = ?, is_ai_full_active = ?', [$this->aiFlags['is_ai_help_active'], $this->aiFlags['is_ai_full_active']], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
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
        $this->assertStringContainsString('essai « Aide IA » de 30 jours', implode(' ', $result['applied']), 'essai automatique à la création (08/11/2026)');
        $this->assertSame(1, (int) $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key = 'ai.help' AND source = 'trial' AND status = 'active'")->fetchColumn());

        // Second passage : rien de plus
        $result = $service->apply('test');
        $this->assertSame(7, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tax'));
        $this->assertSame([], array_filter($result['applied'], fn ($a) => str_contains($a, 'taxes')));
        $this->assertStringContainsString('déjà saisie', implode(' ', $result['skipped']));
        $this->assertStringContainsString('déjà eu un droit', implode(' ', $result['skipped']), 'pas de second essai');

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
        $hasOrders = (int) $this->db->fetchOne('SELECT COUNT(*) FROM "order"') > 0; // d'autres tests de la suite laissent des commandes : le réglage est alors figé
        $this->assertSame(!$hasOrders, (bool) $this->db->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1'), 'France : prix TTC tant qu\'aucune vente');
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
