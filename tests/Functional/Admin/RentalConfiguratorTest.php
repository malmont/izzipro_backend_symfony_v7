<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Questionnaire « Configurer une location pas à pas » (08/11/2026) : choix du produit, réponses enregistrées (règles + grille tarifaire
 * créée sur la catégorie), erreurs par question sans rien écrire, « ce que verra le client » avec ses incohérences (formule journée
 * hors d'atteinte sur une grille horaire avec heures d'ouverture), encadré repris sur l'écran Règles de location ; refusé au client.
 */
class RentalConfiguratorTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $products = [];
    private int $packMax = 0;
    private ?int $categoryId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->packMax = (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM rental_pack');
    }

    protected function tearDown(): void
    {
        foreach ($this->products as $id) {
            $this->db->executeStatement('DELETE FROM booking_configuration WHERE product_id = ?', [$id]);
            foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
                $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$id]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        $this->db->executeStatement('DELETE FROM rental_pack_categories WHERE rental_pack_id > ?', [$this->packMax]);
        $this->db->executeStatement('DELETE FROM rental_pack_translation WHERE rental_pack_id > ?', [$this->packMax]);
        $this->db->executeStatement('DELETE FROM rental_pack WHERE id > ?', [$this->packMax]);
        if ($this->categoryId !== null) {
            $this->db->executeStatement('DELETE FROM categories WHERE id = ?', [$this->categoryId]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testTheQuestionnaireConfiguresAProductAndExplainsWhatTheCustomerWillSee(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/location/configurer', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'refusé au client');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $categoryEntity = (new \App\Entity\Categories())->setName('Nautique questionnaire'); // le modèle de test n'a aucune catégorie
        $em->persist($categoryEntity);
        $em->flush();
        $category = $this->categoryId = (int) $categoryEntity->getId();
        $id = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Motomarine questionnaire', 'priceCents' => 9500, 'language' => 'fr', 'modes' => ['sale' => false, 'rental' => true], 'categoryIds' => [$category]], 'test')['body']['productId'];
        $this->products[] = $id;
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();

        // Choix du produit, puis questionnaire vierge
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location_configurer', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('[data-product-list] [data-product="' . $id . '"]')->count(), 'le produit louable est proposé');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location_configurer&product=' . $id, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('[data-rental-form]')->count());
        $this->assertSame(0, $crawler->filter('[data-current-summary]')->count(), 'rien de configuré : pas de résumé');
        $this->assertSame(1, $crawler->filter('#arkanoa-assistant')->count(), 'console IA présente : corps EasyAdmin conservé');
        $token = $crawler->filter('[data-rental-form] input[name="_token"]')->attr('value');
        $answers = ['_token' => $token, 'granularity' => 'hours', 'stock' => '3', 'bufferTime' => '30', 'minDuration' => '1', 'maxDuration' => '8', 'openingStart' => '09:00', 'openingEnd' => '18:00',
            'datesMode' => 'all', 'pack' => 'new', 'packName' => 'Tarifs questionnaire', 'rateHour' => '95', 'rateHalfDay' => '295,50', 'rateDay' => '450', 'deposit' => '5000', 'arrivalLead' => '45', 'included' => "Gilets\nFormation de 15 minutes", 'excluded' => 'Essence'];

        // Réponse fausse : rien n'est écrit, l'erreur est sous la question
        $crawler = $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/location/configurer/$id", ['maxDuration' => '0'] + $answers, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filter('[data-error="maxDuration"]')->count());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM booking_configuration WHERE product_id = ?', [$id]), 'rien d\'enregistré');

        // Réponses justes : règles + grille créée sur la catégorie, puis « ce que verra le client » avec l'incohérence journée
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/location/configurer/$id", $answers, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $config = $this->db->fetchAssociative('SELECT granularity, stock_quantity, min_duration, max_duration, buffer_time, opening_start, opening_end, deposit, arrival_lead_minutes, included FROM booking_configuration WHERE product_id = ?', [$id]);
        $this->assertSame(['hours', 3, 1, 8, 30, '09:00', '18:00', 500000, 45], [$config['granularity'], (int) $config['stock_quantity'], (int) $config['min_duration'], (int) $config['max_duration'], (int) $config['buffer_time'], $config['opening_start'], $config['opening_end'], (int) $config['deposit'], (int) $config['arrival_lead_minutes']]);
        $this->assertSame(['Gilets', 'Formation de 15 minutes'], json_decode((string) $config['included'], true));
        $pack = $this->db->fetchAssociative('SELECT id, name, hour_rate, half_day_rate, day_rate, week_rate FROM rental_pack WHERE id > ? ORDER BY id DESC LIMIT 1', [$this->packMax]);
        $this->assertSame(['Tarifs questionnaire', 9500, 29550, 45000, null], [$pack['name'], (int) round((float) $pack['hour_rate']), (int) round((float) $pack['half_day_rate']), (int) round((float) $pack['day_rate']), $pack['week_rate']], 'grille créée, montants en cents');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rental_pack_categories WHERE rental_pack_id = ? AND categories_id = ?', [$pack['id'], $category]), 'grille rattachée à la catégorie du produit');

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location_configurer&product=' . $id, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $summary = $crawler->filter('[data-current-summary]')->text();
        $this->assertStringContainsString('Durée de 1 à 8 h', $summary);
        $this->assertStringContainsString('3 exemplaire(s)', $summary);
        $this->assertStringContainsString('Grille « Tarifs questionnaire »', $summary);
        $this->assertStringContainsString('95,00 $ l\'heure', $summary);
        $this->assertStringContainsString('hors d\'atteinte', $summary, 'la journée à 450 $ ne sert jamais : location le même jour');
        $this->assertStringContainsString('Formules que le client pourra choisir : l\'heure, la demi-journée', $summary);
        $this->assertStringContainsString('Caution de 5 000,00 $', $summary);
        $this->assertStringContainsString('ne servira jamais', $summary);
        $this->assertSame('8', $crawler->filter('[data-rental-form] input[name="maxDuration"]')->attr('value'), 'questionnaire prérempli');
        $this->assertSame((string) $pack['id'], $crawler->filter('[data-rental-form] [data-pack]:checked')->attr('value'), 'la grille existante est proposée à la modification');

        // L'écran Règles de location reprend l'encadré
        $configId = (int) $this->db->fetchOne('SELECT id FROM booking_configuration WHERE product_id = ?', [$id]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'edit', 'crudControllerFqcn' => 'App\Controller\Admin\BookingConfigurationCrudController', 'entityId' => $configId]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Ce que verra le client', $html);
        $this->assertStringContainsString('hors d\'atteinte', $html);
        $this->assertStringContainsString('Configurer pas à pas', $html);

        // La console Location mène au questionnaire
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_location', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(1, $crawler->filter('[data-rental-wizard]')->count());
        $this->assertStringContainsString('pas à pas', $crawler->filter('[data-rental-product="' . $id . '"]')->text());
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'locq-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Loc')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
