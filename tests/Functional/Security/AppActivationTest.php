<?php

namespace App\Tests\Functional\Security;

use App\Entity\Entreprise;
use App\Services\AppKeyService\AppActivationService;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Activation des applications mobiles depuis la fiche Entreprise (15/10/2026) : passer un interrupteur à vrai génère
 * une clé et l'envoie (clé + code QR) au contact du site ; le passer à faux révoque les clés de l'application.
 * Les quatre applications : pos, mobile (e-commerce Flutter), admin (gestion .NET MAUI), livingmemory (Mémoires Vivantes).
 */
class AppActivationTest extends WebTestCase
{
    public function testSwitchingAnAppOnSendsAKeyWithAQrCodeAndSwitchingItOffRevokesIt(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $provider->getEntityManager()->getConnection()->executeStatement("UPDATE entreprise SET email = 'contact@mvtest.test'");
        $service = static::getContainer()->get(AppActivationService::class);
        $keys = static::getContainer()->get(AppKeyService::class);
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare('DELETE FROM tenant_app_keys WHERE tenant_code = ? AND app = ?')->execute([MV_TEST_TENANT_CODE, 'livingmemory']);

        $done = $service->applyChanges(['isLivingMemoryActive' => [false, true], 'name' => ['a', 'b']], MV_TEST_TENANT_CODE, 'admin@test', 'https://' . MV_TEST_TENANT_HOST);
        $this->assertSame(['livingmemory'], array_keys($done));
        $this->assertStringContainsString('envoyée à contact@mvtest.test', $done['livingmemory']);
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertStringContainsString('LivingMemory', $email->getHtmlBody());
        $this->assertStringContainsString('cid:app_key_qr', $email->getHtmlBody(), 'code QR incorporé');
        $png = array_values(array_filter($email->getAttachments(), fn ($a) => $a->getFilename() === 'cle-activation-livingmemory.png'));
        $this->assertCount(1, $png, 'fichier PNG joint (en plus de l\'image incorporée et du logo du site)');
        $this->assertSame('image/png', $png[0]->getMediaType() . '/' . $png[0]->getMediaSubtype());
        $this->assertStringStartsWith("\x89PNG", $png[0]->getBody(), 'un vrai PNG');
        preg_match('/IZ-LMV-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $email->getHtmlBody(), $m);
        $this->assertNotEmpty($m, 'la clé est dans l\'e-mail');
        $this->assertSame(MV_TEST_TENANT_CODE, $keys->activate($m[0], 'livingmemory', 'Téléphone')['tenant']['code'], 'la clé reçue active l\'application');

        $active = array_filter($keys->list(MV_TEST_TENANT_CODE), fn ($k) => $k['app'] === 'livingmemory' && $k['revoked_at'] === null);
        $this->assertCount(1, $active);
        $this->assertStringStartsWith('Activation LivingMemory', $active[array_key_first($active)]['label']);

        $done = $service->applyChanges(['isLivingMemoryActive' => [true, false]], MV_TEST_TENANT_CODE, 'admin@test', 'https://' . MV_TEST_TENANT_HOST);
        $this->assertSame('1 clé(s) révoquée(s)', $done['livingmemory']);
        $this->assertSame([], array_filter($keys->list(MV_TEST_TENANT_CODE), fn ($k) => $k['app'] === 'livingmemory' && $k['revoked_at'] === null));

        // Sans e-mail sur la fiche : clé créée quand même, à relever dans l'écran des clés
        $provider->getEntityManager()->getConnection()->executeStatement('UPDATE entreprise SET email = NULL');
        $provider->getEntityManager()->clear();
        $done = $service->applyChanges(['isPosActive' => [false, true]], MV_TEST_TENANT_CODE, 'admin@test', 'https://' . MV_TEST_TENANT_HOST);
        $this->assertStringContainsString("pas d'e-mail", $done['pos']);
        $service->applyChanges(['isPosActive' => [true, false]], MV_TEST_TENANT_CODE, 'admin@test', 'https://' . MV_TEST_TENANT_HOST);

        // Les indicateurs sont exposés par la fiche publique du site (ajout de champs)
        $entrepriseId = (int) $provider->getEntityManager()->getConnection()->fetchOne('SELECT MIN(id) FROM entreprise');
        $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/entreprise/$entrepriseId?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $body = json_decode($client->getResponse()->getContent(), true);
        foreach (['isPosActive', 'isMobileShopActive', 'isManagementAppActive', 'isLivingMemoryActive'] as $flag) {
            $this->assertArrayHasKey($flag, $body);
        }
        $this->assertSame(4, count(AppActivationService::FLAGS));
        $this->assertSame(array_keys(AppActivationService::FLAGS), array_keys(AppKeyService::APPS), 'une application = un interrupteur');
        $entreprise = $provider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $this->assertFalse($service->isActive($entreprise, 'pos'));
    }
}
