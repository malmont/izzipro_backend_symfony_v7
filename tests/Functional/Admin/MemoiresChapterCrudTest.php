<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\Memoires\ChapterCrudController;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Écran EasyAdmin des chapitres Mémoires Vivantes (12/11/2026) : le formulaire de création s'ouvre quand un livre existe
 * et propose ce livre par son titre. Avant, l'association vers le livre n'avait pas de libellé (« Book could not be
 * converted to string ») dès qu'un livre existait.
 */
class MemoiresChapterCrudTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheNewChapterFormListsTheBooksByTitle(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $owner = $em->getRepository(User::class)->find($admin->getId()); // relu : la connexion détache l'entité
        $book = (new Book())->setTitle('Livre du test EasyAdmin')->setType('individuel')->setPerson1FirstName('Jeanne')->setUser($owner);
        $em->persist($book);
        $em->flush();

        $this->assertSame(200, $this->screen('index'), 'liste des chapitres');
        $this->assertSame(200, $this->screen('new'), 'formulaire de création d\'un chapitre');
        $this->assertStringContainsString('Livre du test EasyAdmin (Jeanne)', html_entity_decode((string) $this->client->getResponse()->getContent()));
    }

    private function screen(string $action): int
    {
        $query = http_build_query(['crudAction' => $action, 'crudControllerFqcn' => ChapterCrudController::class]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . $query, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        return $this->client->getResponse()->getStatusCode();
    }

    private function loginAs(array $roles): User
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'chapitres-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Chapitres')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }
}
