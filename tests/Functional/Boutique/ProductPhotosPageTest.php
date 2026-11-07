<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page « Photos » d'un produit (07/11/2026) : dépôt de plusieurs photos (la première devient principale), changement de principale,
 * retrait, génération IA refusée sans fournisseur (aucun crédit) ; refusée au client.
 */
class ProductPhotosPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private array $created = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->db->executeStatement('DELETE FROM ai_usage');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            foreach (['product_variant', 'product_picture', 'product_translation', 'product_categories'] as $t) {
                $this->db->executeStatement("DELETE FROM $t WHERE product_id = ?", [$id]);
            }
            $this->db->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
        }
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testUploadMainRemoveAndGenerate(): void
    {
        $id = static::getContainer()->get(ProductSheetCreator::class)->create(['name' => 'Produit photos test', 'priceCents' => 1200, 'language' => 'fr'], 'test')['body']['productId'];
        $this->created[] = $id;

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/photos", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_photos&routeParams%5Bid%5D=' . $id, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $crawler->filter('[data-photo]')->count(), 'aucune photo');
        $token = $crawler->filter('form[data-photos-add] input[name="_token"]')->attr('value');

        // Deux photos : la première devient principale, la seconde entre en galerie
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/photos/ajouter", ['_token' => $token], ['photos' => [$this->photo('a.png'), $this->photo('b.png')]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_product_photos&routeParams%5Bid%5D=' . $id, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(2, $crawler->filter('[data-photo]')->count());
        $this->assertSame(1, $crawler->filter('[data-photo][data-main="1"]')->count());
        $main = (string) $this->db->fetchOne('SELECT image FROM product WHERE id = ?', [$id]);
        $this->assertNotSame('', $main);
        $this->assertFileExists(__DIR__ . '/../../../var/storage/public_bucket/assets/uploads/products/' . $main, 'fichier déposé dans le dossier des produits');
        $pictureId = (int) $this->db->fetchOne('SELECT id FROM product_picture WHERE product_id = ?', [$id]);
        $this->assertGreaterThan(0, $pictureId);

        // La photo de galerie devient principale ; l'ancienne principale passe en galerie
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/photos/principale", ['_token' => $token, 'pictureId' => $pictureId], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertNotSame($main, $this->db->fetchOne('SELECT image FROM product WHERE id = ?', [$id]));
        $this->assertSame($main, $this->db->fetchOne('SELECT image_url FROM product_picture WHERE id = ?', [$pictureId]));

        // Retrait
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/photos/supprimer", ['_token' => $token, 'pictureId' => $pictureId], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_picture WHERE product_id = ?', [$id]));

        // Génération : fournisseur absent, aucun crédit décompté
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/produits/$id/photos/generer", ['_token' => $token, 'view' => 'worn', 'environment' => 'street', 'comment' => 'test'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        $this->assertStringContainsString('fournisseur d\'images', html_entity_decode((string) $this->client->getResponse()->getContent()));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ai_usage'));
    }

    private function photo(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        copy(__DIR__ . '/../../../public/assets/arkanoa-favicon.png', $tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'photos-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('P')->setLastname('T')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
