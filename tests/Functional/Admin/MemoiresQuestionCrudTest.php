<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\Memoires\MemoireQuestionCrudController;
use App\Entity\User;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Écran EasyAdmin des questions Mémoires Vivantes (13/11/2026) : la liste s'affiche sans avertissement PHP quand une
 * question porte un rôle, et le formulaire propose « Tous / Transversal » comme vide du champ « Rôle ». Avant, ce libellé
 * était un choix de valeur null, et EasyAdmin, qui retourne la liste des choix pour afficher la liste, levait
 * « Can only flip string and integer values » dès qu'une question avec un rôle était affichée (erreur en test).
 */
class MemoiresQuestionCrudTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEveryListPageOpensWithQuestionsHavingARoleAndTheFormOffersTheTransversalPlaceholder(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $question = (new MemoireQuestion())->setBookType('famille')->setTheme('enfance')->setRole('enfant')->setDisplayOrder(9999)
            ->setQuestionText('Question du test EasyAdmin avec un rôle');
        $em->persist($question);
        $em->flush();

        // Toutes les pages de la liste (le signalement portait sur les pages 2 à 5, celles qui ont des questions à rôle), jusqu'à trouver la question
        $found = false;
        $pagesWithARole = 0;
        for ($page = 1; $page <= 10; ++$page) {
            $status = $this->screen('index', ['page' => $page]);
            if ($status === 302) {
                break; // au-delà de la dernière page, EasyAdmin renvoie vers la dernière
            }
            $this->assertSame(200, $status, 'page ' . $page . ' de la liste des questions');
            $html = html_entity_decode((string) $this->client->getResponse()->getContent());
            $pagesWithARole += (int) preg_match('/>\s*Enfant\s*</', $html);
            $found = $found || str_contains($html, 'Question du test EasyAdmin avec un rôle');
        }
        $this->assertTrue($found, 'la question créée est dans la liste');
        $this->assertGreaterThan(0, $pagesWithARole, 'au moins une page affiche le rôle « Enfant » par son libellé');

        $this->assertSame(200, $this->screen('new'), 'formulaire de création d\'une question');
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Tous / Transversal', $html, 'le vide du champ « Rôle » garde son libellé');
    }

    private function screen(string $action, array $extra = []): int
    {
        $query = http_build_query(['crudAction' => $action, 'crudControllerFqcn' => MemoireQuestionCrudController::class] + $extra);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . $query, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        return $this->client->getResponse()->getStatusCode();
    }

    private function loginAs(array $roles): User
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'questions-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Questions')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }
}
