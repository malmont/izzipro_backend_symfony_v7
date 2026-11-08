<?php

namespace App\Tests\Functional\Platform;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Offres de la plateforme et branchement des abonnements (console de pilotage, lot 3 bis, 04/11/2026) : catalogue public et prix
 * modifiables par le super administrateur ; une formule branchée sur une offre accorde, met en grâce, suspend, rétablit et retire les
 * droits d'usage du site cible au fil du cycle de vie de l'abonnement (écouteur Doctrine, aucun appel dans la boutique) ; événements
 * de facturation externes ; site cible exigé à la souscription.
 */
class OfferFulfillmentTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \PDO $master;
    private \PDO $target;
    private array $targetFlagsBefore;
    private array $planBefore = [];
    private int $planId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->target = static::getContainer()->get(TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT2_DB);
        $this->targetFlagsBefore = $this->target->query('SELECT is_boussole_esg_active, is_landing_page_active FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setShopTenant(MV_TEST_TENANT_CODE, 'test'); // le site de test est la boutique de la plateforme
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-offres@example.invalid', false, false);
        $this->provider->getEntityManager()->getConnection()->executeStatement('DELETE FROM boutique_setting');
        $db = $this->provider->getEntityManager()->getConnection();
        $this->planId = (int) $db->fetchOne('SELECT sp.id FROM subscription_plan sp JOIN product p ON p.id = sp.product_id WHERE p.code = ? ORDER BY sp.id LIMIT 1', ['DEMO-PANIER']);
        $this->assertGreaterThan(0, $this->planId, 'formule du panier de démonstration');
        $this->planBefore = $db->fetchAssociative('SELECT fulfillment_type, fulfillment_ref FROM subscription_plan WHERE id = ?', [$this->planId]);
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('DELETE FROM subscription WHERE fulfillment_target IS NOT NULL');
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = ? WHERE id = ?', [$this->planBefore['fulfillment_type'], $this->planBefore['fulfillment_ref'], $this->planId]);
        $this->target->prepare('UPDATE entreprise SET is_boussole_esg_active = ?, is_landing_page_active = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)')->execute([$this->targetFlagsBefore['is_boussole_esg_active'] ? 't' : 'f', $this->targetFlagsBefore['is_landing_page_active'] ? 't' : 'f']);
        $this->cleanMaster();
        $this->master->exec("DELETE FROM platform_offers WHERE code = 'test_offer'");
        $this->master->exec("UPDATE platform_offers SET monthly_price_cents = 5900, trial_days = 30 WHERE code = 'boutique'");
        static::getContainer()->get(TenantCacheService::class)->invalidateSite(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB);
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setShopTenant(null, 'test');
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer()); // le site de test garde « IA complète » (08/11/2026)
        parent::tearDown();
    }

    public function testPublicCatalogueAndSuperAdminPricing(): void
    {
        $r = $this->api('GET', '/api/offers', null, null);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $offers = array_column(json_decode((string) $r->getContent(), true)['offers'], null, 'code');
        foreach (['landing', 'boutique', 'landing_boutique', 'pos', 'management', 'pos_management', 'mobile_shop', 'memoires', 'living_memory', 'boussole', 'all'] as $code) {
            $this->assertArrayHasKey($code, $offers, 'offre par défaut');
        }
        $this->assertSame(['Boutique en ligne', 5900, 'CAD', 30, 'module.boutique', 'Boutique'], [$offers['boutique']['label'], $offers['boutique']['monthlyPriceCents'], $offers['boutique']['currency'], $offers['boutique']['trialDays'], $offers['boutique']['features'][0]['key'], $offers['boutique']['features'][0]['label']]);
        $this->assertSame(8, count($offers['all']['features']));
        $en = array_column(json_decode((string) $this->api('GET', '/api/offers?locale=en', null, null)->getContent(), true)['offers'], null, 'code');
        $this->assertSame(['Online shop', 'Online shop'], [$en['boutique']['label'], $en['boutique']['features'][0]['label']]);

        $this->assertSame(403, $this->api('GET', '/api/platform/offers', null, 'admin')->getStatusCode(), 'administrateur de site : refusé');
        $r = $this->api('GET', '/api/platform/offers', null, 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertGreaterThanOrEqual(11, count(json_decode((string) $r->getContent(), true)['offers']));
        $r = $this->api('PUT', '/api/platform/offers/boutique', ['monthlyPriceCents' => 6900, 'trialDays' => 14], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame([6900, 14, 'Boutique en ligne'], [json_decode((string) $r->getContent(), true)['offer']['monthlyPriceCents'], json_decode((string) $r->getContent(), true)['offer']['trialDays'], json_decode((string) $r->getContent(), true)['offer']['labelFr']], 'prix modifié, le reste conservé');
        $r = $this->api('PUT', '/api/platform/offers/boutique', ['features' => ['nope'], 'monthlyPriceCents' => -1], 'super');
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(['features', 'monthlyPriceCents'], array_column(json_decode((string) $r->getContent(), true)['errors'], 'path'));
        $r = $this->api('PUT', '/api/platform/offers/test_offer', ['labelFr' => 'Essai', 'labelEn' => 'Trial', 'features' => ['module.boussole'], 'monthlyPriceCents' => 1000], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertArrayHasKey('test_offer', array_column(json_decode((string) $this->api('GET', '/api/offers', null, null)->getContent(), true)['offers'], null, 'code'), 'nouvelle offre au catalogue');
        $this->assertSame(403, $this->api('PUT', '/api/platform/offers/boutique', ['monthlyPriceCents' => 1], 'admin')->getStatusCode());
    }

    public function testASubscriptionToABoundPlanDrivesTheTargetSiteRights(): void
    {
        $em = $this->provider->getEntityManager();
        $plan = $em->getRepository(SubscriptionPlan::class)->find($this->planId);
        $plan->setFulfillmentType(SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER)->setFulfillmentRef('boussole');
        $em->flush();
        $this->setTargetFlag('is_boussole_esg_active', false);
        $plans = json_decode((string) $this->api('GET', '/api/subscription-plans?productId=' . $plan->getProduct()->getId(), null, null)->getContent(), true);
        $this->assertSame('platform_offer', $plans[0]['fulfillmentType'], 'la grille des formules dit qu\'elle est branchée');

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $subscription = (new Subscription())->setUser($user)->setPlan($plan)->setQuantity(1)->setFulfillmentTarget(MV_TEST_TENANT2_CODE);
        $em->persist($subscription);
        $em->flush();
        $s = static::getContainer()->get(EntitlementService::class);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'), 'incomplet : rien');

        // activation (première facture payée) : droit « subscription » jusqu'à la fin de période + grâce, indicateur projeté sur le site cible
        $subscription->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd((new \DateTimeImmutable())->modify('+30 days'));
        $em->flush();
        $eff = $s->effective(MV_TEST_TENANT2_CODE)['module.boussole'];
        $this->assertSame([true, 'subscription', 'active', 'boussole'], [$eff['enabled'], $eff['source'], $eff['status'], $eff['offerCode']]);
        $this->assertEqualsWithDelta(time() + 37 * 86400, strtotime($eff['endsAt']), 300, 'fin de période + 7 jours de grâce');
        $this->assertTrue($this->targetFlag('is_boussole_esg_active'), 'projeté sur la fiche du site cible');
        $reference = sprintf('subscription:%s:%d', MV_TEST_TENANT_CODE, $subscription->getId());
        $this->assertSame($reference, $s->find((int) $eff['id'])['reference']);
        $this->assertGreaterThanOrEqual(1, count($this->getMailerMessages()), 'courriel au site cible');

        // impayé : grâce (toujours actif) ; paiement régularisé avec nouvelle période : prolongé
        $subscription->setStatus(Subscription::STATUS_PAST_DUE);
        $em->flush();
        $this->assertSame('grace', $s->find((int) $eff['id'])['status']);
        $this->assertTrue($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
        $subscription->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd((new \DateTimeImmutable())->modify('+60 days'));
        $em->flush();
        $row = $s->find((int) $eff['id']);
        $this->assertSame('active', $row['status']);
        $this->assertEqualsWithDelta(time() + 67 * 86400, strtotime($row['ends_at']), 300, 'prolongé à la nouvelle fin de période');

        // renouvellement sans changement de statut : seule la période bouge
        $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->modify('+90 days'));
        $em->flush();
        $this->assertEqualsWithDelta(time() + 97 * 86400, strtotime($s->find((int) $eff['id'])['ends_at']), 300, 'renouvelé');

        // pause → suspendu ; reprise → rétabli ; résiliation → annulé
        $subscription->setStatus(Subscription::STATUS_PAUSED);
        $em->flush();
        $this->assertSame(['suspended', false], [$s->find((int) $eff['id'])['status'], $this->targetFlag('is_boussole_esg_active')]);
        $subscription->setStatus(Subscription::STATUS_ACTIVE);
        $em->flush();
        $this->assertSame(['active', true], [$s->find((int) $eff['id'])['status'], $this->targetFlag('is_boussole_esg_active')]);
        $subscription->setStatus(Subscription::STATUS_CANCELED)->setCanceledAt(new \DateTimeImmutable());
        $em->flush();
        $this->assertSame(['cancelled', false], [$s->find((int) $eff['id'])['status'], $this->targetFlag('is_boussole_esg_active')]);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
    }

    public function testOrdinaryPlansAreUntouchedAndTheTargetIsRequiredAtSubscription(): void
    {
        $em = $this->provider->getEntityManager();
        $plan = $em->getRepository(SubscriptionPlan::class)->find($this->planId);
        $this->assertSame('none', $plan->getFulfillmentType());
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $ordinary = (new Subscription())->setUser($user)->setPlan($plan)->setQuantity(1)->setFulfillmentTarget(MV_TEST_TENANT2_CODE); // cible posée mais formule ordinaire : rien
        $em->persist($ordinary);
        $em->flush();
        $ordinary->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd((new \DateTimeImmutable())->modify('+30 days'));
        $em->flush();
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn(), 'formule ordinaire : aucun droit');

        // un seul site gouverne les autres : une formule branchée sur un site qui n'est pas la boutique désignée ne produit rien
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setShopTenant(MV_TEST_TENANT2_CODE, 'test');
        $plan->setFulfillmentType(SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER)->setFulfillmentRef('boussole');
        $em->flush();
        $rogue = (new Subscription())->setUser($user)->setPlan($plan)->setQuantity(1)->setFulfillmentTarget(MV_TEST_TENANT2_CODE);
        $em->persist($rogue);
        $em->flush();
        $rogue->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd((new \DateTimeImmutable())->modify('+30 days'));
        $em->flush();
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn(), 'site non désigné : ignoré');
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setShopTenant(MV_TEST_TENANT_CODE, 'test');
        $plan->setFulfillmentType(SubscriptionPlan::FULFILLMENT_NONE)->setFulfillmentRef(null);
        $em->flush();

        $plan->setFulfillmentType(SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER)->setFulfillmentRef('boussole');
        $em->flush();
        $r = $this->api('POST', '/api/subscriptions', ['planId' => $this->planId], 'client');
        $this->assertSame(422, $r->getStatusCode(), $r->getContent());
        $this->assertSame('FULFILLMENT_TARGET_REQUIRED', json_decode((string) $r->getContent(), true)['code'] ?? json_decode((string) $r->getContent(), true)['error']);
        $r = $this->api('POST', '/api/subscriptions', ['planId' => $this->planId, 'fulfillmentTarget' => 'site-inconnu'], 'client');
        $this->assertSame(422, $r->getStatusCode(), $r->getContent());
        $this->assertStringContainsString('FULFILLMENT_TARGET_UNKNOWN', (string) $r->getContent());
    }

    public function testExternalBillingEventsAreReservedToTheSuperAdminAndValidated(): void
    {
        $this->setTargetFlag('is_landing_page_active', false);
        $s = static::getContainer()->get(EntitlementService::class);
        $this->assertSame(403, $this->api('POST', '/api/platform/billing-events', ['event' => 'activated', 'tenant' => MV_TEST_TENANT2_CODE, 'offerCode' => 'landing', 'reference' => 'ext:42'], 'admin')->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/api/platform/billing-events', ['event' => 'paid'], 'super')->getStatusCode());
        $this->assertSame(404, $this->api('POST', '/api/platform/billing-events', ['event' => 'activated', 'tenant' => 'nulle-part', 'offerCode' => 'landing', 'reference' => 'ext:42'], 'super')->getStatusCode());
        $r = $this->api('POST', '/api/platform/billing-events', ['event' => 'activated', 'tenant' => MV_TEST_TENANT2_CODE, 'offerCode' => 'inconnue', 'reference' => 'ext:42'], 'super');
        $this->assertSame(['OFFER_NOT_FOUND', 404], [json_decode((string) $r->getContent(), true)['code'], $r->getStatusCode()]);
        $r = $this->api('POST', '/api/platform/billing-events', ['event' => 'activated', 'tenant' => MV_TEST_TENANT2_CODE, 'offerCode' => 'landing', 'reference' => 'ext:42', 'periodEnd' => (new \DateTimeImmutable())->modify('+30 days')->format(\DateTimeInterface::ATOM)], 'super');
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['module.landing: granted'], json_decode((string) $r->getContent(), true)['actions']);
        $this->assertTrue($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.landing'));
        $this->assertTrue($this->targetFlag('is_landing_page_active'));
        $r = $this->api('POST', '/api/platform/billing-events', ['event' => 'cancelled', 'tenant' => MV_TEST_TENANT2_CODE, 'offerCode' => 'landing', 'reference' => 'ext:42'], 'super');
        $this->assertSame(['module.landing: cancelled'], json_decode((string) $r->getContent(), true)['actions']);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.landing'));
    }

    private function targetFlag(string $column): bool
    {
        return filter_var($this->target->query("SELECT $column FROM entreprise ORDER BY id LIMIT 1")->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
    }

    private function setTargetFlag(string $column, bool $value): void
    {
        $this->target->prepare("UPDATE entreprise SET $column = ? WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)")->execute([$value ? 't' : 'f']);
    }

    private function cleanMaster(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code IN (?, ?)")->execute([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE]);
        }
    }

    private function api(string $method, string $path, ?array $body, ?string $as): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $container = static::getContainer();
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $email = match ($as) { 'admin' => 'admin@arkanoa-media.com', 'super' => 'super-admin-offers@example.invalid', default => 'client-offers@example.invalid' };
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertNotSame('admin', $as);
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname(ucfirst($as))->setLastname('Test')
                ->setRoles($as === 'super' ? ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
