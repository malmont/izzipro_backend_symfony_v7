<?php

namespace App\Tests\Functional\Abo;

use App\Abo\Services\AboCarrierService;
use App\Abo\Services\AboCatalogService;
use App\Dto\ProductCommerceDto;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\PosFavoriteService\PosFavoriteService;
use App\Services\Platform\EntitlementService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot ABO-CONF-b (20/11/2026, contrat validé par le chef) : les produits porteurs des formules d'abonnement.
 * - liste et geste de la gestion web (`/api/site/abonnements/produits`), mêmes règles qu'EasyAdmin et l'assistant (AboCarrierService) ;
 * - porteur créé avec sa formule (`newProduct`), dans la même transaction ; aperçu sans écriture, prestations citées dans l'aperçu ;
 * - un porteur « abonnement seulement » (prix 0 ou vente coupée) ne s'achète JAMAIS seul : panier, caisse (devis, favoris), fiche ;
 * - EasyAdmin ne décoche plus en silence un porteur à abonnés.
 */
class AboCarrierTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \Doctrine\DBAL\Connection $db;
    private bool $boutiqueBefore = true;
    private bool $categoryBefore = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $this->provider->getEntityManager()->getConnection();
        $this->categoryBefore = $this->db->fetchOne('SELECT 1 FROM categories WHERE name = ?', [AboCarrierService::CARRIER_CATEGORY]) !== false;
        $this->boutiqueBefore = in_array($this->db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 1, '1'], true);
        static::getContainer()->get(EntitlementService::class)->free(MV_TEST_TENANT_CODE, 'ai.full', 'test');
        $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = TRUE');
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement("DELETE FROM subscription WHERE plan_id IN (SELECT id FROM subscription_plan WHERE names::text LIKE '%abo-conf-b%')");
        $this->db->executeStatement("DELETE FROM plan_benefit WHERE plan_id IN (SELECT id FROM subscription_plan WHERE names::text LIKE '%abo-conf-b%')");
        $this->db->executeStatement("DELETE FROM subscription_plan_price WHERE plan_id IN (SELECT id FROM subscription_plan WHERE names::text LIKE '%abo-conf-b%')");
        $this->db->executeStatement("DELETE FROM subscription_plan WHERE names::text LIKE '%abo-conf-b%'");
        $this->db->executeStatement("DELETE FROM benefit WHERE names::text LIKE '%abo-conf-b%'");
        $this->db->executeStatement("DELETE FROM product_variant WHERE product_id IN (SELECT id FROM product WHERE name LIKE 'abo-conf-b%')");
        $this->db->executeStatement("DELETE FROM product_categories WHERE product_id IN (SELECT id FROM product WHERE name LIKE 'abo-conf-b%')");
        $this->db->executeStatement("DELETE FROM product WHERE name LIKE 'abo-conf-b%'");
        if (!$this->categoryBefore) { // la catégorie des porteurs créée par l'essai
            $this->db->executeStatement('DELETE FROM categories c WHERE c.name = ? AND NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.categories_id = c.id)', [AboCarrierService::CARRIER_CATEGORY]);
        }
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource IN ('site_assistant', 'subscription_plan', 'product_subscription')");
        $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$this->boutiqueBefore], [\PDO::PARAM_BOOL]);
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.help', 'ai.full')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'bootstrap', 'IA complète du site de test')")->execute([MV_TEST_TENANT_CODE]);
        $this->db->executeStatement('UPDATE entreprise SET is_ai_help_active = FALSE, is_ai_full_active = TRUE');
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheListAndTheToggleFollowTheRules(): void
    {
        $id = $this->product('abo-conf-b Panier', 1500.0, false);
        $list = $this->json('GET', '/api/site/abonnements/produits?porteurs=non&q=abo-conf-b', null, 'admin', 200);
        $row = $this->rowOf($list, $id);
        $this->assertSame([false, true, false, null], [$row['subscriptionEnabled'], $row['canEnable'], $row['canDisable'], $row['reason']]);

        $on = $this->json('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => true], 'admin', 200);
        $this->assertSame([true, []], [$on['product']['subscriptionEnabled'], $on['warnings']]);
        $this->json('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => true], 'admin', 200); // idempotent
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'product_subscription' AND resource_id = ?", [$id]), 'une seule écriture journalisée');
        $this->assertSame(422, $this->api('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => 'oui'], 'admin')->getStatusCode(), 'MUST_BE_BOOLEAN');
        $this->assertSame(404, $this->api('PATCH', '/api/site/abonnements/produits/999999999', ['subscriptionEnabled' => true], 'admin')->getStatusCode());
        $this->assertSame(403, $this->api('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => false], 'client')->getStatusCode(), 'un client n\'y touche pas');

        // Formule active sans abonné : désactiver est permis, ses formules sortent de la vente et SONT NOMMÉES
        $plan = static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => 'Formule abo-conf-b'], 'price' => 2000, 'interval' => 'month'], 'test');
        $off = $this->json('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => false], 'admin', 200);
        $this->assertSame([['code' => 'PLANS_HIDDEN', 'count' => 1, 'plans' => ['Formule abo-conf-b']]], $off['warnings']);
        $this->assertFalse($off['product']['subscriptionEnabled']);

        // Une formule qui a eu un abonné : le produit reste porteur
        $this->json('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => true], 'admin', 200);
        $this->db->executeStatement("INSERT INTO subscription (user_id, plan_id, quantity, status, created_at, updated_at, stripe_subscription_id) SELECT id, ?, 1, 'canceled', NOW(), NOW(), 'sub_abo_conf_b' FROM \"user\" WHERE email = 'admin@arkanoa-media.com'", [$plan->getId()]);
        $refused = $this->json('PATCH', "/api/site/abonnements/produits/$id", ['subscriptionEnabled' => false], 'admin', 409);
        $this->assertSame('PRODUCT_HAS_SUBSCRIBERS', $refused['code']);
        $row = $this->rowOf($this->json('GET', '/api/site/abonnements/produits?porteurs=oui&q=abo-conf-b', null, 'admin', 200), $id);
        $this->assertSame([true, false, 'PRODUCT_HAS_SUBSCRIBERS', ['active' => 1, 'archived' => 0, 'withSubscribers' => 1]], [$row['subscriptionEnabled'], $row['canDisable'], $row['reason'], $row['plans']]);

        // Le porteur d'une formule branchée (plateforme, agence) ne se règle pas ici
        $branched = $this->product('abo-conf-b Branché', 900.0, true);
        $em = $this->provider->getEntityManager();
        $em->persist((new \App\Entity\SubscriptionPlan())->setProduct($em->find(Product::class, $branched))->setNames(['fr' => 'Branchée abo-conf-b'])->setPrice(1000)
            ->setCurrency(static::getContainer()->get(AboCatalogService::class)->siteCurrency())->setInterval('month')->setIntervalCount(1)->setFulfillmentType('webhook')->setActive(true));
        $em->flush();
        $this->assertSame('PRODUCT_NOT_ELIGIBLE', $this->json('PATCH', "/api/site/abonnements/produits/$branched", ['subscriptionEnabled' => false], 'admin', 422)['code']);
    }

    public function testAFormulaCreatesItsCarrierProductInTheSameTransaction(): void
    {
        $base = ['names' => ['fr' => 'Club abo-conf-b'], 'price' => 3000, 'interval' => 'month'];
        $count = fn (): int => (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE name LIKE 'abo-conf-b%'");

        // Aperçu : la phrase le dit, rien n'est créé
        $preview = $this->json('POST', '/api/site/abonnements/formules/apercu', $base + ['newProduct' => ['names' => ['fr' => 'abo-conf-b Club']]], 'admin', 200);
        $this->assertStringContainsString('Un produit porteur sera créé : abo-conf-b Club.', $preview['summary']);
        $this->assertSame(['name' => 'abo-conf-b Club'], $preview['newProduct']);
        $this->assertSame(0, $count(), 'l\'aperçu ne crée rien');

        // Ni l'un ni l'autre, ou les deux : refusé
        $this->assertSame('PRODUCT_CHOICE_REQUIRED', $this->json('POST', '/api/site/abonnements/formules', $base, 'admin', 422)['code']);
        $existing = $this->product('abo-conf-b Existant', 10.0, true);
        $this->assertSame('PRODUCT_CHOICE_REQUIRED', $this->json('POST', '/api/site/abonnements/formules', $base + ['productId' => $existing, 'newProduct' => ['names' => ['fr' => 'abo-conf-b X']]], 'admin', 422)['code']);
        // Formule refusée : aucun produit créé
        $this->json('POST', '/api/site/abonnements/formules', ['price' => -5] + $base + ['newProduct' => ['names' => ['fr' => 'abo-conf-b Refusé']]], 'admin', 422);
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE name = 'abo-conf-b Refusé'"), 'formule refusée = aucun produit');

        $created = $this->json('POST', '/api/site/abonnements/formules', $base + ['newProduct' => ['names' => ['fr' => 'abo-conf-b Club']]], 'admin', 201);
        $row = $this->db->fetchAssociative("SELECT id, price, sale_enabled, subscription_enabled, is_web, is_pos FROM product WHERE name = 'abo-conf-b Club'");
        $this->assertNotFalse($row);
        $this->assertSame((int) $row['id'], (int) $created['plan']['productId']);
        $this->assertSame([0.0, false, true, AboCarrierService::CARRIER_VISIBLE, false], [(float) $row['price'], (bool) $row['sale_enabled'], (bool) $row['subscription_enabled'], (bool) $row['is_web'], (bool) $row['is_pos']]);
        $this->assertSame(AboCarrierService::CARRIER_CATEGORY, $this->db->fetchOne('SELECT c.name FROM categories c JOIN product_categories pc ON pc.categories_id = c.id WHERE pc.product_id = ?', [$row['id']]));
        // une variante (la commande née d'une période payée la demande), sans expédition
        $this->assertSame([['price' => 0, 'stock_quantity' => 0]], $this->db->fetchAllAssociative('SELECT price, stock_quantity FROM product_variant WHERE product_id = ?', [$row['id']]));
        $this->assertFalse((bool) $this->db->fetchOne('SELECT requires_shipping FROM product WHERE id = ?', [$row['id']]));
        // newProduct ne se donne qu'à la création
        $this->assertSame('FIELD_UNKNOWN', $this->json('PATCH', '/api/site/abonnements/formules/' . $created['plan']['id'], ['newProduct' => ['names' => ['fr' => 'abo-conf-b Y']]], 'admin', 422)['code']);
    }

    public function testTheCarrierAndItsCategoryAreWrittenWithTheFormulaOrNotAtAll(): void
    {
        $this->db->executeStatement('DELETE FROM categories c WHERE c.name = ? AND NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.categories_id = c.id)', [AboCarrierService::CARRIER_CATEGORY]);
        $categories = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM categories WHERE name = ?', [AboCarrierService::CARRIER_CATEGORY]);
        $before = $categories();
        // une panne à l'écriture de la formule (dans la transaction) : ni porteur, ni catégorie
        $em = $this->provider->getEntityManager();
        $failure = new class {
            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof \App\Entity\SubscriptionPlan) {
                        throw new \RuntimeException('panne simulée de l\'écriture de la formule');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::onFlush], $failure);
        try {
            static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['newProduct' => ['names' => ['fr' => 'abo-conf-b Panne']], 'names' => ['fr' => 'Panne abo-conf-b'], 'price' => 1000, 'interval' => 'month'], 'test');
            $this->fail('la panne simulée devait remonter');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('panne simulée', $e->getMessage());
        } finally {
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::onFlush], $failure);
        }
        $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM product WHERE name = 'abo-conf-b Panne'"), 'formule non écrite = aucun porteur');
        $this->assertSame($before, $categories(), 'ni catégorie');
    }

    public function testThePreviewQuotesTheBenefitsOfANewFormula(): void
    {
        $product = $this->product('abo-conf-b Yoga', 10.0, true);
        $benefit = $this->json('POST', '/api/site/abonnements/prestations', ['kind' => 'session', 'names' => ['fr' => 'Cours abo-conf-b'], 'durationMinutes' => 60], 'admin', 201);
        $benefitId = (int) ($benefit['benefit']['id'] ?? $benefit['id'] ?? $benefit['benefitId']);
        $plans = (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscription_plan');
        $preview = $this->json('POST', '/api/site/abonnements/formules/apercu', ['productId' => $product, 'names' => ['fr' => 'Yoga abo-conf-b'], 'price' => 6000, 'interval' => 'month',
            'benefits' => [['benefitId' => $benefitId, 'quantity' => 8], ['benefitId' => 999999999]]], 'admin', 200);
        $this->assertStringContainsString('Cours abo-conf-b', $preview['summary'], 'la prestation est citée avant l\'enregistrement');
        $this->assertStringContainsString('8', $preview['summary']);
        $this->assertContains('BENEFIT_NOT_FOUND', array_column($preview['warnings'], 'code'), 'prestation inconnue : un avertissement, pas une erreur');
        $this->assertSame($plans, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscription_plan'), 'aucune écriture');
    }

    public function testASubscriptionOnlyProductIsNeverSoldAlone(): void
    {
        $zero = $this->product('abo-conf-b Zéro', 0.0, true, true);
        $paid = $this->product('abo-conf-b Payant', 25.0, true, true);
        $em = $this->provider->getEntityManager();
        $em->clear();
        $zeroProduct = $em->find(Product::class, $zero);
        $paidProduct = $em->find(Product::class, $paid);
        $this->assertSame([true, false], [$zeroProduct->isSubscriptionOnly(), $zeroProduct->isSellableAlone()]);
        $this->assertSame([false, true], [$paidProduct->isSubscriptionOnly(), $paidProduct->isSellableAlone()], 'un porteur avec un prix se vend aussi seul');

        // Fiche : pas de bouton d'achat, la grille des formules (et pas de « rupture »)
        $fields = ProductCommerceDto::fields($zeroProduct);
        $this->assertSame([false, true, true], [$fields['sale']['enabled'], $fields['subscription']['enabled'], $fields['subscription']['only']]);
        $this->assertTrue(ProductCommerceDto::fields($paidProduct)['sale']['enabled']);

        // Panier (et commande, qui passe par le même devis)
        $variant = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ?', [$zero]);
        $quote = $this->api('POST', '/api/cart/quote', ['items' => [['productVariantId' => $variant, 'quantity' => 1]]], null);
        $this->assertSame(422, $quote->getStatusCode(), (string) $quote->getContent());
        $this->assertStringContainsString('SUBSCRIPTION_ONLY', (string) $quote->getContent());
        // Caisse : devis (en ligne et à la synchronisation hors ligne) et favoris
        try {
            static::getContainer()->get(\App\Services\PosDiscountService\PosDiscountService::class)->quote([['productVariantId' => $variant, 'quantity' => 1]], null);
            $this->fail('la caisse ne vend pas un porteur à 0');
        } catch (\App\Services\CaisseService\CashRegisterException $e) { // la caisse rend le refus du devis en QUOTE_INVALID, lignes jointes
            $this->assertSame('QUOTE_INVALID', $e->errorCode);
            $this->assertContains('SUBSCRIPTION_ONLY', array_column($e->errors, 'code'), (string) json_encode($e->errors));
        }
        $zeroProduct->setIsPos(true);
        $this->assertFalse(PosFavoriteService::sellable($zeroProduct));
        $paidProduct->setIsPos(true);
        $this->assertTrue(PosFavoriteService::sellable($paidProduct));
        $em->flush();

        // Prix portés par les variantes : la ligne est jugée au prix de la variante vendue
        $onVariant = $this->product('abo-conf-b Prix variante', 0.0, true, true, 25.0);
        $zeroVariant = $this->product('abo-conf-b Variante nulle', 10.0, true, true, 0.0);
        $em->clear();
        $this->assertSame([false, true], [$em->find(Product::class, $onVariant)->isSubscriptionOnly(), ProductCommerceDto::fields($em->find(Product::class, $onVariant))['sale']['enabled']],
            'produit à 0 dont la variante a un prix : il se vend seul');
        $ok = $this->api('POST', '/api/cart/quote', ['items' => [['productVariantId' => (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ?', [$onVariant]), 'quantity' => 1]]], null);
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        $zeroVariantId = (int) $this->db->fetchOne('SELECT id FROM product_variant WHERE product_id = ?', [$zeroVariant]);
        $refused = $this->api('POST', '/api/cart/quote', ['items' => [['productVariantId' => $zeroVariantId, 'quantity' => 1]]], null);
        $this->assertSame(422, $refused->getStatusCode(), 'variante à 0 d\'un porteur : jamais vendue seule');
        $this->assertStringContainsString('SUBSCRIPTION_ONLY', (string) $refused->getContent());

        // Caisse hors ligne : ni le porteur à 0, ni la variante à 0 d'un porteur ; sa variante payante reste
        $em = $this->provider->getEntityManager();
        $carrier = $em->find(Product::class, $zeroVariant)->setIsPos(true);
        $priced = (new ProductVariant())->setStockQuantity(3)->setPrice(15);
        $carrier->addVariant($priced);
        $em->persist($priced);
        $em->find(Product::class, $zero)->setIsPos(true);
        $onlyZero = $this->product('abo-conf-b Toutes nulles', 10.0, true, true, 0.0); // un prix au produit, mais sa seule variante vaut 0
        $em->find(Product::class, $onlyZero)->setIsPos(true);
        $em->flush();
        $pack = static::getContainer()->get(\App\Services\PosOfflineService\PosOfflinePackService::class)->build(null);
        $packed = array_column($pack['products'], null, 'id');
        $this->assertArrayNotHasKey($zero, $packed, 'porteur à 0 : absent du paquet');
        $this->assertArrayNotHasKey($onlyZero, $packed, 'porteur dont toutes les variantes valent 0 : rien à vendre seul, absent du paquet');
        $this->assertSame([(int) $priced->getId()], array_column($packed[$zeroVariant]['variants'], 'id'), 'la variante à 0 est écartée, la variante payante reste');
    }

    public function testEasyAdminKeepsACarrierWithSubscribers(): void
    {
        $id = $this->product('abo-conf-b EasyAdmin', 12.0, true);
        $plan = static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => 'EA abo-conf-b'], 'price' => 1000, 'interval' => 'month'], 'test');
        $this->db->executeStatement("INSERT INTO subscription (user_id, plan_id, quantity, status, created_at, updated_at, stripe_subscription_id) SELECT id, ?, 1, 'active', NOW(), NOW(), 'sub_abo_conf_b_ea' FROM \"user\" WHERE email = 'admin@arkanoa-media.com'", [$plan->getId()]);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = $this->provider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => \App\Controller\Admin\ProductCrudController::class, 'entityId' => $id]);
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $crawler = $this->client->request('GET', $url);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="Product"]')->form();
        $form['Product[subscriptionEnabled]']->untick();
        $this->client->submit($form);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$id]), 'EasyAdmin ne décoche plus en silence un porteur à abonnés');

        // Sans abonné : décoché, et le geste est au journal comme par l'API
        $free = $this->product('abo-conf-b EasyAdmin libre', 12.0, true);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => \App\Controller\Admin\ProductCrudController::class, 'entityId' => $free]));
        $form = $crawler->filter('form[name="Product"]')->form();
        $form['Product[subscriptionEnabled]']->untick();
        $this->client->submit($form);
        $this->assertFalse((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$free]));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'product_subscription' AND resource_id = ?", [(string) $free]), 'le geste d\'EasyAdmin est journalisé');

        // Un véhicule ne porte pas d'abonnement, même par sa fiche d'EasyAdmin
        $em = $this->provider->getEntityManager();
        $vehicle = (new \App\Entity\VehicleProduct())->setName('abo-conf-b Véhicule')->setSlug('abo-conf-b-vehicule-' . bin2hex(random_bytes(3)))->setPrice(5000)->setIsWeb(true)
            ->setBrand('Marque')->setModel('Modèle')->setYear(2025)->setVehicleCondition('neuf');
        $vehicle->setMode(\App\Enum\ProductMode::BOOKING);
        $em->persist($vehicle);
        $em->flush();
        try {
            static::getContainer()->get(AboCarrierService::class)->assertCanSet($vehicle, true);
            $this->fail('un véhicule ne porte pas d\'abonnement');
        } catch (\App\Abo\Services\AboException $e) {
            $this->assertSame('PRODUCT_NOT_ELIGIBLE', $e->errorCode);
        }
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => \App\Controller\Admin\VehicleProductCrudController::class, 'entityId' => $vehicle->getId()]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="VehicleProduct"]')->form();
        $form['VehicleProduct[subscriptionEnabled]']->tick();
        $this->client->submit($form);
        $this->assertFalse((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$vehicle->getId()]), 'la case reste décochée sur un véhicule');
    }

    public function testTheAssistantNamesTheFormulasThatLeaveTheSale(): void
    {
        $id = $this->product('abo-conf-b Assistant', 10.0, true);
        static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => 'Matin abo-conf-b'], 'price' => 1000, 'interval' => 'month'], 'test');
        static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => 'Soir abo-conf-b'], 'price' => 1200, 'interval' => 'month'], 'test');
        $this->answer([['list_subscription_products', ['porteurs' => 'oui', 'q' => 'abo-conf-b']], ['propose_subscription_product', ['productId' => $id, 'enabled' => false]]]);
        $body = $this->chat('Arrête les abonnements sur ce produit');
        $this->assertCount(1, $body['actions']);
        $summary = (string) json_encode($body['actions'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('« Matin abo-conf-b »', $summary, 'les formules qui sortent de la vente sont NOMMÉES');
        $this->assertStringContainsString('« Soir abo-conf-b »', $summary);
        $this->assertTrue((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$id]), 'rien sans confirmation');
        $this->assertSame(200, $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin')->getStatusCode());
        $this->assertFalse((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$id]));
    }

    public function testTheAssistantCreatesACarrierWithItsFormulaTwice(): void
    {
        // la seconde fois, la catégorie des porteurs existe déjà (avant : erreur 500 à l'aperçu)
        $benefit = $this->json('POST', '/api/site/abonnements/prestations', ['kind' => 'session', 'names' => ['fr' => 'Séance abo-conf-b'], 'durationMinutes' => 60], 'admin', 201);
        $benefitId = (int) ($benefit['benefit']['id'] ?? $benefit['id'] ?? $benefit['benefitId']);
        foreach (['Un', 'Deux'] as $n) {
            $this->answer([['propose_plan', ['newProduct' => ['names' => ['fr' => "abo-conf-b Assistant $n"]], 'names' => ['fr' => "Formule $n abo-conf-b"], 'price' => 2000, 'interval' => 'month',
                'benefits' => [['benefitId' => $benefitId, 'quantity' => 4]]]]]);
            $body = $this->chat("Crée une formule $n avec son produit");
            $this->assertCount(1, $body['actions'], substr((string) json_encode(array_map(static fn ($r) => is_array($r) ? ($r['messages'] ?? array_keys($r)) : $r, FakeLandingAiClient::$requests), JSON_UNESCAPED_UNICODE), -2500));
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product WHERE name = ?', ["abo-conf-b Assistant $n"]), 'rien sans confirmation');
            $confirm = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin');
            $this->assertSame(200, $confirm->getStatusCode(), (string) $confirm->getContent());
            $id = $this->db->fetchOne('SELECT id FROM product WHERE name = ?', ["abo-conf-b Assistant $n"]);
            $this->assertNotFalse($id);
            $this->assertSame(AboCarrierService::CARRIER_CATEGORY, $this->db->fetchOne('SELECT c.name FROM categories c JOIN product_categories pc ON pc.categories_id = c.id WHERE pc.product_id = ?', [$id]));
        }
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM categories WHERE name = ?', [AboCarrierService::CARRIER_CATEGORY]), 'une seule catégorie des porteurs');
    }

    public function testTheAssistantRefusesWhenTheFormulasChangedSinceItsProposal(): void
    {
        $id = $this->product('abo-conf-b Assistant liste', 10.0, true);
        foreach (['Un', 'Deux', 'Trois', 'Quatre'] as $n) {
            static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => "$n abo-conf-b"], 'price' => 1000, 'interval' => 'month'], 'test');
        }
        $this->answer([['propose_subscription_product', ['productId' => $id, 'enabled' => false]]]);
        $body = $this->chat('Arrête les abonnements sur ce produit');
        $summary = (string) json_encode($body['actions'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('4 formule(s)', $summary, 'le nombre d\'abord');
        $this->assertStringContainsString('et 1 autre(s)', $summary, 'résumé borné : les premiers noms seulement');
        // une formule créée entre la proposition et la confirmation : refus, rien d'écrit
        static::getContainer()->get(AboCatalogService::class)->savePlan(null, ['productId' => $id, 'names' => ['fr' => 'Cinq abo-conf-b'], 'price' => 1000, 'interval' => 'month'], 'test');
        $confirm = $this->api('POST', '/api/site/assistant/actions/' . $body['actions'][0]['id'] . '/confirm', null, 'admin');
        $this->assertNotSame(200, $confirm->getStatusCode());
        $this->assertStringContainsString('ont changé', (string) (json_decode((string) $confirm->getContent(), true)['message'] ?? ''));
        $this->assertTrue((bool) $this->db->fetchOne('SELECT subscription_enabled FROM product WHERE id = ?', [$id]), 'rien d\'écrit');
    }

    /** Un produit de test avec une variante (à son prix, ou à `$variantPrice`) ; abonnement activé ou non ; vente directe ouverte */
    private function product(string $name, float $price, bool $subscription, bool $withVariant = true, ?float $variantPrice = null): int
    {
        $em = $this->provider->getEntityManager();
        $product = (new Product())->setName($name)->setDescription(null)->setPrice($price)->setQuantity(5)->setSlug(strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3)))
            ->setCreatedAt(new \DateTimeImmutable())->setIsWeb(true)->setIsPos(false)->setIsPreOrder(false)->setSaleEnabled(true)->setSubscriptionEnabled($subscription);
        $em->persist($product);
        if ($withVariant) {
            $variant = (new ProductVariant())->setStockQuantity(5)->setPrice((int) ($variantPrice ?? $price));
            $variant->setProduct($product);
            $product->addVariant($variant);
            $em->persist($variant);
        }
        $em->flush();

        return (int) $product->getId();
    }

    /** @return array<string, mixed> */
    private function rowOf(array $list, int $id): array
    {
        foreach ($list['items'] as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("produit $id absent de la liste");
    }

    private function answer(array $uses): void
    {
        FakeLandingAiClient::reset();
        $content = [];
        foreach ($uses as $i => [$name, $input]) {
            $content[] = (object) ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => $name, 'input' => json_decode((string) json_encode((object) $input))];
        }
        FakeLandingAiClient::$queue[] = (object) ['content' => $content, 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
        FakeLandingAiClient::$queue[] = (object) ['content' => [(object) ['type' => 'text', 'text' => 'Voici.']], 'usage' => (object) ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    /** @return array<string, mixed> */
    private function chat(string $message): array
    {
        $r = $this->api('POST', '/api/site/assistant', ['messages' => [['role' => 'user', 'content' => $message]]], 'admin');
        $this->assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return json_decode((string) $r->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, string $as, int $status): array
    {
        $r = $this->api($method, $path, $body, $as);
        $this->assertSame($status, $r->getStatusCode(), "$method $path : " . substr((string) $r->getContent(), 0, 400));

        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $as): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $this->client->getResponse();
    }

    private function token(string $as): string
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $email = $as === 'admin' ? 'admin@arkanoa-media.com' : 'client-abo-conf-b@example.invalid';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('ConfB')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
