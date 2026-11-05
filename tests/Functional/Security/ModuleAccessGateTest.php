<?php

namespace App\Tests\Functional\Security;

use App\Entity\Entreprise;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Console de pilotage, lot 1 bis (04/11/2026) : les routes d'API d'un module non activé sur le site répondent 403 MODULE_INACTIVE,
 * connecté ou non ; le module réactivé (écriture Doctrine sur la fiche, qui vide le cache du site) est servi aussitôt ; les
 * routes hors module et l'administration ne sont pas concernées.
 */
class ModuleAccessGateTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private array $before = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('live', 'test'); // les garde-fous ne s'appliquent qu'en mode live
        $e = $this->entreprise();
        $this->before = ['boussole' => (bool) $e->isBoussoleEsgActive(), 'memoires' => (bool) $e->isMemoireVivanteActive(), 'boutique' => (bool) $e->isBoutiqueActive()];
    }

    protected function tearDown(): void
    {
        $this->setFlags($this->before['boussole'], $this->before['memoires'], $this->before['boutique']);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        parent::tearDown();
    }

    public function testRoutesOfAnInactiveModuleAnswer403AndComeBackWhenItIsReactivated(): void
    {
        $this->setFlags(false, false, false);
        $r = $this->get('/api/boussole/referentials');
        $this->assertSame(403, $r->getStatusCode(), $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        $this->assertSame(['MODULE_INACTIVE', 'MODULE_INACTIVE', 'boussole'], [$body['error'], $body['code'], $body['module']]);
        $this->assertStringContainsString('Boussole ESG', $body['message']);
        $this->assertSame('memoires', json_decode((string) $this->get('/api/memoires/book-types')->getContent(), true)['module'] ?? null);
        $this->assertSame(403, $this->post('/api/cart/quote', ['items' => []])->getStatusCode(), 'devis de la boutique gardé');

        $this->assertNotSame(403, $this->get('/api/entreprise/1')->getStatusCode(), 'fiche entreprise : hors module');
        $this->assertNotSame(403, $this->get('/api/products')->getStatusCode(), 'catalogue public : servi même sans boutique (site vitrine)');
        $this->assertNotSame(403, $this->post('/api/order/create-multi-payment', [])->getStatusCode(), 'vente au comptoir : caisse, pas boutique (non gardée dans ce lot)');
        $this->assertNotSame(403, $this->post('/api/order/create', [])->getStatusCode(), 'commande : servie même module désactivé (paiement déjà autorisé finalisable)');
        $this->assertNotSame(403, $this->post('/api/order/create-guest', [])->getStatusCode());
        $this->assertSame(403, $this->post('/api/stripe/create-intent', [])->getStatusCode(), 'début du parcours : gardé');

        $this->setFlags(true, true, true);
        $r = $this->get('/api/boussole/referentials'); $this->assertSame(200, $r->getStatusCode(), 'module réactivé : servi aussitôt (cache vidé par l\'écriture) : ' . $r->getContent());
        $this->assertNotSame(403, $this->get('/api/memoires/book-types')->getStatusCode());
        $this->assertNotSame(403, $this->post('/api/cart/quote', ['items' => []])->getStatusCode());
    }

    public function testInDemoModeNothingIsGuarded(): void
    {
        $this->setFlags(false, false, false);
        $this->assertSame(403, $this->get('/api/boussole/referentials')->getStatusCode());
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('demo', 'test');
        $this->assertSame(200, $this->get('/api/boussole/referentials')->getStatusCode(), 'mode demo : module désactivé mais servi');
    }

    public function testThePathMapIsNarrowAndTheWebhookIsNeverGuarded(): void
    {
        $this->assertNull(ModuleAccessService::moduleForPath('/api/stripe/webhook'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/products'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/landingpage-settings'));
        $this->assertSame('landing', ModuleAccessService::moduleForPath('/api/landingpage-ai/instructions'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/pos/pin-login'), 'caisse : indicateur d\'application, non gardée dans ce lot');
        $this->assertNull(ModuleAccessService::moduleForPath('/api/caisse/drawer-open'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/order/create-multi-payment'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/order/create'));
        $this->assertNull(ModuleAccessService::moduleForPath('/api/payment'));
        $this->assertSame('boutique', ModuleAccessService::moduleForPath('/api/stripe/create-intent'));
        $this->assertSame('boutique', ModuleAccessService::moduleForPath('/api/subscriptions/12/cancel'));
        $this->assertSame('memoires', ModuleAccessService::moduleForPath('/api/memoires/mobile/books'));
        $this->assertSame(array_keys(ModuleAccessService::LABELS), array_keys(ModuleAccessService::COLUMNS));
    }

    private function entreprise(): Entreprise
    {
        $e = $this->provider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $this->assertInstanceOf(Entreprise::class, $e, 'la copie de base porte une fiche entreprise');

        return $e;
    }

    private function setFlags(bool $boussole, bool $memoires, bool $boutique): void
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $e = $this->entreprise();
        $e->setIsBoussoleEsgActive($boussole)->setIsMemoireVivanteActive($memoires)->setIsBoutiqueActive($boutique);
        $em->flush();
        $em->clear();
        // hors requête, la file d'invalidation n'est vidée par aucun kernel.terminate : on la vide comme le ferait la fin de requête
        static::getContainer()->get(\App\EventSubscriber\CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse();
    }

    private function post(string $path, array $body): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($body));

        return $this->client->getResponse();
    }
}
