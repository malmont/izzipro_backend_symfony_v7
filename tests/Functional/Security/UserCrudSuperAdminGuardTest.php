<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Protection du compte super administrateur et interdiction de l'auto-suppression dans UserCrudController :
 * - un administrateur de site (ROLE_ADMIN sans ROLE_SUPER_ADMIN) ne peut ni ouvrir l'édition, ni modifier par POST forgé,
 *   ni supprimer, ni réinitialiser le PIN de caisse d'un compte portant ROLE_SUPER_ADMIN ;
 * - nul (pas même un super administrateur) ne peut supprimer son propre compte ;
 * - la suppression en lot (batchDelete) est désactivée pour tout le monde.
 */
class UserCrudSuperAdminGuardTest extends WebTestCase
{
    /** Préfixe des courriels créés par ce test : sert à filtrer la liste EasyAdmin (paginée dans la suite complète) */
    private const EMAIL_PREFIX = 'guard-sa-';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    public function testSiteAdminCannotSeeActionsOrOpenEditFormForSuperAdminAndBatchDeleteIsDisabled(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_POS']);
        $owner = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN', 'ROLE_USER_POS', 'ROLE_USER_INTERNET']);
        $ordinary = $this->createUser(['ROLE_USER_POS', 'ROLE_USER_INTERNET']);

        // Liste filtrée sur les comptes de ce test : dans la suite complète, la liste triée par identifiant est paginée
        $crawler = $this->admin('index', null, ['query' => self::EMAIL_PREFIX]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Suppression en lot désactivée
        $this->assertCount(0, $crawler->filter('[data-action-name="batchDelete"], .form-batch-checkbox-all'));

        // Ligne du super admin : ni Modifier, ni Supprimer, ni Réinitialiser le PIN
        $ownerRow = $crawler->filter(sprintf('tr[data-id="%d"]', $owner->getId()));
        $this->assertCount(1, $ownerRow, 'ligne du super admin présente dans la liste');
        $this->assertCount(0, $ownerRow->filter('a.action-edit, [data-action-name="edit"]'), 'pas de bouton Modifier sur le super admin');
        $this->assertCount(0, $ownerRow->filter('a.action-delete, [data-action-name="delete"]'), 'pas de bouton Supprimer sur le super admin');
        $this->assertCount(0, $ownerRow->filter('a.action-resetPosPin, [data-action-name="resetPosPin"]'), 'pas de bouton Réinitialiser le PIN sur le super admin');

        // Propre ligne de l'administrateur connecté : Modifier présent, Supprimer absent
        $selfRow = $crawler->filter(sprintf('tr[data-id="%d"]', $admin->getId()));
        $this->assertCount(1, $selfRow);
        $this->assertGreaterThan(0, $selfRow->filter('a.action-edit, [data-action-name="edit"]')->count());
        $this->assertCount(0, $selfRow->filter('a.action-delete, [data-action-name="delete"]'), 'pas d\'auto-suppression dans la liste');

        // Ligne d'un utilisateur ordinaire : Modifier, Supprimer et Réinitialiser le PIN présents
        $ordinaryRow = $crawler->filter(sprintf('tr[data-id="%d"]', $ordinary->getId()));
        $this->assertCount(1, $ordinaryRow);
        $this->assertGreaterThan(0, $ordinaryRow->filter('a.action-edit, [data-action-name="edit"]')->count());
        $this->assertGreaterThan(0, $ordinaryRow->filter('a.action-delete, [data-action-name="delete"]')->count());
        $this->assertGreaterThan(0, $ordinaryRow->filter('a.action-resetPosPin, [data-action-name="resetPosPin"]')->count());

        // GET du formulaire d'édition du super admin par un administrateur de site -> 403
        $this->admin('edit', $owner->getId());
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'GET edit du super admin refusé à l\'administrateur de site');
    }

    public function testSiteAdminForgedEditPostOnSuperAdminIsForbiddenAndLeavesDatabaseUntouched(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN']);
        $owner = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN', 'ROLE_USER_INTERNET'], otpEnabled: true, isVerified: true);
        $before = $this->db()->fetchAssociative('SELECT email, password, otp_enabled, is_verified, roles FROM "user" WHERE id = ?', [$owner->getId()]);
        $this->assertIsArray($before);

        // L'administrateur de site récupère un jeton CSRF de formulaire User valide depuis sa propre fiche
        $crawler = $this->admin('edit', $admin->getId());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['email'] = 'pirate-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $values['User']['plainPassword'] = 'MotDePassePirate123!';
        $values['User']['roles'] = ['ROLE_USER_INTERNET'];
        unset($values['User']['otpEnabled'], $values['User']['isVerified']);

        // Envoi forgé ciblant l'identifiant du super administrateur
        $this->client->request('POST', $this->adminUrl('edit', $owner->getId()), $values);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'POST forgé sur le super admin refusé en 403');

        $after = $this->db()->fetchAssociative('SELECT email, password, otp_enabled, is_verified, roles FROM "user" WHERE id = ?', [$owner->getId()]);
        $this->assertSame($before['email'], $after['email'], 'courriel du super admin inchangé');
        $this->assertSame($before['password'], $after['password'], 'mot de passe du super admin inchangé');
        $this->assertTrue((bool) $after['otp_enabled'], 'otp_enabled du super admin inchangé');
        $this->assertTrue((bool) $after['is_verified'], 'is_verified du super admin inchangé');
        $this->assertSame(json_decode((string) $before['roles'], true), json_decode((string) $after['roles'], true), 'rôles du super admin inchangés');
    }

    public function testSiteAdminCannotDeleteSuperAdminOrThemselvesNorResetSuperAdminPin(): void
    {
        $admin = $this->loginAs(['ROLE_ADMIN']);
        $owner = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN', 'ROLE_USER_POS']);
        $ordinary = $this->createUser(['ROLE_USER_INTERNET']);

        $crawler = $this->admin('index');
        $deleteToken = (string) $crawler->filter('#delete-form input[name="token"]')->attr('value');
        $this->assertNotEmpty($deleteToken, 'jeton ea-delete présent sur la page');

        // Suppression forgée du super admin -> 403, compte toujours présent
        $this->client->request('POST', $this->adminUrl('delete', $owner->getId()), ['token' => $deleteToken]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression forgée du super admin refusée');
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$owner->getId()]));

        // Suppression de son propre compte -> 403, compte toujours présent
        $this->client->request('POST', $this->adminUrl('delete', $admin->getId()), ['token' => $deleteToken]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'auto-suppression refusée');
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$admin->getId()]));

        // Réinitialisation forgée du PIN de caisse du super admin -> 403
        $this->admin('resetPosPin', $owner->getId());
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'resetPosPin sur le super admin refusé');

        // Non-régression : modification d'un utilisateur ordinaire toujours possible
        $crawler = $this->admin('edit', $ordinary->getId());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['firstname'] = 'PrénomModifié';
        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame('PrénomModifié', $this->db()->fetchOne('SELECT firstname FROM "user" WHERE id = ?', [$ordinary->getId()]));
    }

    public function testSuperAdminCanEditSuperAdminAndDeleteOtherUserButCannotDeleteThemselves(): void
    {
        $superAdmin = $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
        $otherSuperAdmin = $this->createUser([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN']);
        $deletable = $this->createUser(['ROLE_USER_INTERNET']);

        // Action en lot absente même pour le super administrateur
        $crawler = $this->admin('index');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('[data-action-name="batchDelete"], .form-batch-checkbox-all'));
        $deleteToken = (string) $crawler->filter('#delete-form input[name="token"]')->attr('value');
        $this->assertNotEmpty($deleteToken);

        // GET et POST d'édition d'un compte super admin -> 200 puis 302
        $crawler = $this->admin('edit', $otherSuperAdmin->getId());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET edit du super admin autorisé au super admin');
        $form = $crawler->filter('form[name="edit-User-form"], form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['firstname'] = 'PropriétaireMaj';
        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame('PropriétaireMaj', $this->db()->fetchOne('SELECT firstname FROM "user" WHERE id = ?', [$otherSuperAdmin->getId()]));

        // Suppression d'un autre compte -> 302 et ligne supprimée
        $this->client->request('POST', $this->adminUrl('delete', $deletable->getId()), ['token' => $deleteToken]);
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$deletable->getId()]));

        // Suppression de son propre compte -> 403 et compte conservé
        $this->client->request('POST', $this->adminUrl('delete', $superAdmin->getId()), ['token' => $deleteToken]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'auto-suppression refusée même au super admin');
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$superAdmin->getId()]));
    }

    private function admin(string $action, ?int $entityId = null, array $extra = []): Crawler
    {
        return $this->client->request('GET', $this->adminUrl($action, $entityId, $extra));
    }

    private function adminUrl(string $action, ?int $entityId = null, array $extra = []): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter([
            'crudAction' => $action,
            'crudControllerFqcn' => UserCrudController::class,
            'entityId' => $entityId,
        ]) + $extra);
    }

    private function loginAs(array $roles): User
    {
        $user = $this->createUser($roles);
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }

    private function createUser(array $roles, bool $otpEnabled = false, bool $isVerified = true): User
    {
        $email = 'guard-sa-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Test')
            ->setLastname('Guard')
            ->setRoles($roles)
            ->setOtpEnabled($otpEnabled)
            ->setIsVerified($isVerified)
            ->setPassword('$2y$13$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}

