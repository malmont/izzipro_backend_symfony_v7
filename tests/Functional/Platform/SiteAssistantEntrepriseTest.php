<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assistant et fiche Entreprise (cheminement complet, 07/11/2026) : l'IA propose de remplir des champs (textes légaux rédigés, numéro de
 * taxe), un champ inconnu est refusé, rien n'est écrit avant confirmation, puis la fiche et sa traduction sont mises à jour.
 */
class SiteAssistantEntrepriseTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM ai_usage');
    }

    public function testTheAssistantFillsTheCompanySheetAfterConfirmation(): void
    {
        $before = $this->db->fetchAssociative('SELECT tel, gst_number FROM entreprise ORDER BY id LIMIT 1');
        $legalBefore = $this->db->fetchAllAssociative('SELECT id, legal_notice FROM entreprise_translation');
        try {
            FakeLandingAiClient::$queue[] = (object) ['content' => [
                (object) ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'set_entreprise_fields', 'input' => (object) ['fields' => (object) ['name' => 'X', 'isBoutiqueActive' => 'true'], 'summary' => 'interdit']],
                (object) ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'set_entreprise_fields', 'input' => (object) ['fields' => (object) ['tel' => '+1 514 555 0199', 'gstNumber' => '123456789RT0001', 'LegalNotice' => '<h2>Mentions légales</h2><p>Éditeur du site : Test.</p>'], 'summary' => 'Renseigner le téléphone, le numéro de TPS et les mentions légales']],
            ], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
            FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Voici les mentions légales rédigées ; confirmez.']], 'usage' => (object) ['input_tokens' => 1, 'output_tokens' => 1]];
            $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => 'Rédige mes mentions légales et mets mon numéro de TPS']]]);
            $this->assertSame(200, $r->getStatusCode(), $r->getContent());
            $body = json_decode((string) $r->getContent(), true);
            $this->assertCount(1, $body['actions'], 'la proposition avec un champ réservé est refusée');
            $this->assertSame(['set_entreprise_fields', 'Renseigner le téléphone, le numéro de TPS et les mentions légales'], [$body['actions'][0]['tool'], $body['actions'][0]['summary']]);
            $this->assertContains(['label' => \App\Services\SiteAssistantService\SiteAssistantTools::SCREENS['entreprise'], 'screen' => 'entreprise'], $body['links']);
            $refused = json_decode(FakeLandingAiClient::$requests[1]['messages'][2]['content'][0]['content'], true);
            $this->assertSame('isBoutiqueActive', $refused['errors'][0]['path']);
            $this->assertSame($before['tel'], $this->db->fetchOne('SELECT tel FROM entreprise ORDER BY id LIMIT 1'), 'rien avant confirmation');

            $r = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null);
            $this->assertSame(200, $r->getStatusCode(), $r->getContent());
            $this->assertSame(['tel', 'gstNumber', 'LegalNotice'], json_decode((string) $r->getContent(), true)['result']['applied']);
            $this->assertSame(['+1 514 555 0199', '123456789RT0001'], array_values($this->db->fetchAssociative('SELECT tel, gst_number FROM entreprise ORDER BY id LIMIT 1')));
            $this->assertStringContainsString('Mentions légales', (string) $this->db->fetchOne("SELECT legal_notice FROM entreprise_translation WHERE language = 'fr' ORDER BY id LIMIT 1"));
            $this->assertSame('update', $this->db->fetchOne("SELECT action FROM content_audit_log WHERE resource = 'entreprise' ORDER BY id DESC LIMIT 1"), 'écriture journalisée comme un PUT');
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET tel = ?, gst_number = ?', [$before['tel'], $before['gst_number']]);
            $ids = array_map('intval', array_column($legalBefore, 'id'));
            $this->db->executeStatement('DELETE FROM entreprise_translation WHERE id NOT IN (' . (implode(',', $ids) ?: '0') . ')');
            foreach ($legalBefore as $t) {
                $this->db->executeStatement('UPDATE entreprise_translation SET legal_notice = ? WHERE id = ?', [$t['legal_notice'], $t['id']]);
            }
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }
    }

    private function api(string $method, string $path, ?array $body): \Symfony\Component\HttpFoundation\Response
    {
        $container = static::getContainer();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test', 'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)], $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
