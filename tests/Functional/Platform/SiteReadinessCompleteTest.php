<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cheminement complet par produit (07/11/2026) : chaque étape porte `blocking` (indispensable) ou non (amélioration), les compteurs et les
 * groupes par produit sont rendus, les textes légaux et les numéros de taxe passent à « fait » dès qu'ils sont renseignés.
 */
class SiteReadinessCompleteTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
    }

    public function testBlockingFlagsGroupsAndCompanySteps(): void
    {
        $before = $this->db->fetchAssociative('SELECT gst_number, qst_number FROM entreprise ORDER BY id LIMIT 1');
        $translations = $this->db->fetchAllAssociative('SELECT id, legal_notice, condition_of_use, privacy_policy FROM entreprise_translation');
        try {
            $this->db->executeStatement("UPDATE entreprise SET gst_number = NULL, qst_number = NULL");
            $this->db->executeStatement("UPDATE entreprise_translation SET legal_notice = NULL");
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $body = $this->readiness();
            foreach (['blockingTodo', 'improvementTodo', 'groups'] as $k) {
                $this->assertArrayHasKey($k, $body);
            }
            $byKey = array_column($body['steps'], null, 'key');
            foreach ($body['steps'] as $s) {
                $this->assertArrayHasKey('blocking', $s, $s['key']);
            }
            $selling = ($body['groups'] !== [] && in_array('module.boutique', array_column($body['groups'], 'key'), true)) || in_array('module.pos', array_column($body['groups'], 'key'), true);
            $this->assertSame(['todo', $selling], [$byKey['site.legal']['status'], $byKey['site.legal']['blocking']], 'textes légaux manquants : indispensables dès que l\'on vend');
            $this->assertStringContainsString('mentions légales', $byKey['site.legal']['description']);
            $this->assertSame(['todo', $selling, 'entreprise'], [$byKey['site.tax_numbers']['status'], $byKey['site.tax_numbers']['blocking'], $byKey['site.tax_numbers']['action']['screen']], 'adresse au Canada (QC) : TPS et TVQ');
            $this->assertFalse($byKey['site.logo']['blocking'], 'le logo est une amélioration');
            $this->assertFalse($byKey['site.email_sender']['blocking']);
            $this->assertFalse($byKey['ai.credits']['blocking']);
            $this->assertContains('site.phone', array_keys($byKey));
            $this->assertSame($body['blockingTodo'], count(array_filter($body['steps'], fn ($s) => $s['status'] === 'todo' && $s['blocking'])));
            $site = array_column($body['groups'], null, 'key')['site'];
            $this->assertSame('Site : identité et socle', $site['label']);
            $this->assertSame($site['blockingTodo'] === 0, $site['ready']);
            $this->assertGreaterThanOrEqual(1, $site['blockingTodo'] + $site['improvementTodo']);
            if (isset($byKey['boutique.product'])) {
                $this->assertTrue($byKey['boutique.product']['blocking']);
                $this->assertFalse($byKey['boutique.pictures']['blocking']);
                $this->assertSame('configuration', $byKey['boutique.reviews']['action']['screen']);
            }

            // Textes légaux et numéros de taxe renseignés : étapes faites
            $this->db->executeStatement("UPDATE entreprise_translation SET legal_notice = '<p>Mentions</p>', condition_of_use = COALESCE(NULLIF(condition_of_use, ''), '<p>CGV</p>'), privacy_policy = COALESCE(NULLIF(privacy_policy, ''), '<p>Vie privée</p>')");
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM entreprise_translation') === 0) {
                $this->db->executeStatement("INSERT INTO entreprise_translation (id, entreprise_id, language, legal_notice, condition_of_use, privacy_policy) SELECT nextval('entreprise_translation_id_seq'), id, 'fr', '<p>Mentions</p>', '<p>CGV</p>', '<p>Vie privée</p>' FROM entreprise ORDER BY id LIMIT 1");
            }
            $this->db->executeStatement("UPDATE entreprise SET gst_number = '123456789RT0001', qst_number = '1234567890TQ0001'");
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $byKey = array_column($this->readiness()['steps'], null, 'key');
            $this->assertSame('done', $byKey['site.legal']['status']);
            $this->assertSame('done', $byKey['site.tax_numbers']['status']);
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET gst_number = ?, qst_number = ?', [$before['gst_number'], $before['qst_number']]);
            $this->db->executeStatement("DELETE FROM entreprise_translation WHERE id NOT IN (" . (implode(',', array_map('intval', array_column($translations, 'id'))) ?: '0') . ')');
            foreach ($translations as $t) {
                $this->db->executeStatement('UPDATE entreprise_translation SET legal_notice = ?, condition_of_use = ?, privacy_policy = ? WHERE id = ?', [$t['legal_notice'], $t['condition_of_use'], $t['privacy_policy'], $t['id']]);
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }
    }

    private function readiness(): array
    {
        $container = static::getContainer();
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/readiness?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
