<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Écran EasyAdmin « Quotas IA Mémoires Vivantes » (16/10/2026) : lecture, enregistrement, refus d'un non-admin */
class AiQuotaEasyAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnAdminReadsAndSavesTheQuotasAndANonAdminIsRefused(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->page();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), implode(' | ', array_unique(preg_match_all('#[\w@/._-]+\.twig#', (string) $this->client->getResponse()->getContent(), $m) ? $m[0] : [])));
        $this->assertStringContainsString('Quotas IA Mémoires Vivantes', $this->client->getResponse()->getContent());
        $form = $crawler->filter('form[action$="/admin/memoires/ai-quotas"]')->form();
        $this->client->submit($form, ['transcriptionsPerDay' => '12', 'improvementsPerDay' => '0', 'maxAudioSeconds' => '120', 'timezone' => 'Europe/Paris']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->page();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Quotas enregistrés', $html);
        $this->assertSame(['12', '0', '120', 'Europe/Paris'], $this->db()->fetchNumeric('SELECT transcriptions_per_day::text, improvements_per_day::text, max_audio_seconds::text, timezone FROM mv_ai_quota_setting ORDER BY id LIMIT 1'));
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM mv_ai_quota_setting'), 'une seule ligne par site');

        $this->client->submit($crawler->filter('form[action$="/admin/memoires/ai-quotas"]')->form(), ['maxAudioSeconds' => '5']);
        $this->page();
        $this->assertStringContainsString('entre 30 et 1800 secondes', $this->client->getResponse()->getContent(), 'valeur refusée, message affiché');
        $this->assertSame('120', (string) $this->db()->fetchOne('SELECT max_audio_seconds FROM mv_ai_quota_setting ORDER BY id LIMIT 1'));

        $this->page('2026-02-30');
        $this->assertStringContainsString('Date invalide', $this->client->getResponse()->getContent());

        // Depuis le menu du tableau de bord EasyAdmin (contexte EasyAdmin présent : mise en page EasyAdmin)
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_mv_ai_quotas', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), implode(' | ', array_unique(preg_match_all('#[\\w@/._-]+\\.twig#', (string) $this->client->getResponse()->getContent(), $m) ? $m[0] : [])));
        $this->assertStringContainsString('Quotas IA Mémoires Vivantes', $this->client->getResponse()->getContent());

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->page();
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'non-admin refusé');
    }

    private function page(?string $date = null): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/memoires/ai-quotas' . ($date ? '?date=' . $date : ''), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'ea-quotas-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Quotas')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'main');
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
