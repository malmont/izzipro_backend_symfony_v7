<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\AdminSettingsCrudController;
use App\Controller\Admin\AppProfileCrudController;
use App\Controller\Admin\BoutiqueSettingCrudController;
use App\Controller\Admin\LandingPageSettingCrudController;
use App\Controller\Admin\Memoires\BookPrintOrderCrudController;
use App\Controller\Admin\MovementTypeCrudController;
use App\Controller\Admin\OrderSourceCrudController;
use App\Controller\Admin\OrderTypeCrudController;
use App\Controller\Admin\PaymentTypeCrudController;
use App\Controller\Admin\SquareConfigCrudController;
use App\Controller\Admin\StatusCommandeCrudController;
use App\Controller\Admin\StatusPaymentCrudController;
use App\Controller\Admin\TransactionTypeCrudController;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Grille administrateur / super administrateur, sous-lot 2c (03/11/2026) : ce qui casse le site reste lisible par l'administrateur
 * mais ne s'écrit qu'en super administrateur. Référentiels de commande, paiement et caisse (le code dépend de leurs identifiants et
 * noms), configurations JSON brutes de la landing et de la boutique (sans suppression pour personne), AdminSettings (écran seulement,
 * les routes /api/admin-settings ne changent pas), commandes d'impression Lulu (synchronisation laissée à l'administrateur) ;
 * configuration Square en lecture seule pour tous avec jeton masqué ; profils des applications sans suppression pour personne.
 */
class SiteBreakingSettingsGuardTest extends WebTestCase
{
    private const REFERENTIALS = [
        StatusCommandeCrudController::class => 'status_commande',
        OrderSourceCrudController::class => 'order_source',
        OrderTypeCrudController::class => 'order_type',
        PaymentTypeCrudController::class => 'payment_type',
        StatusPaymentCrudController::class => 'status_payment',
        TransactionTypeCrudController::class => 'transaction_type',
        MovementTypeCrudController::class => 'movement_type',
    ];
    private const SQUARE_SECRET = 'sq0atp-secret-token-2c-ne-doit-pas-fuir';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    public function testReferentialsAreReadableBySiteAdminAndWritableBySuperAdminOnly(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        foreach (self::REFERENTIALS as $controller => $table) {
            $id = (int) $this->db()->fetchOne("SELECT MIN(id) FROM $table");
            $this->assertGreaterThan(0, $id, "$table : une ligne de référence existe");
            $row = $this->indexRow($controller, $table, $id);
            $this->assertCount(1, $row, "$table : ligne visible dans la liste");
            $this->assertCount(0, $row->filter('a.action-edit, a.action-delete, [data-action-name="edit"], [data-action-name="delete"]'), "$table : ni Modifier ni Supprimer pour l'administrateur");
            $this->client->request('GET', $this->adminUrl($controller, 'detail', $id));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$table : détail lisible");
            $this->assertSame(403, $this->status($controller, 'new'), "$table : création refusée");
            $this->assertSame(403, $this->status($controller, 'edit', $id), "$table : modification refusée");
            $this->client->request('POST', $this->adminUrl($controller, 'delete', $id), ['token' => 'x']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$table : suppression refusée");
            $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM $table WHERE id = ?", [$id]), "$table : ligne intacte");
            $this->assertStringNotContainsString('batchDelete', (string) $this->client->request('GET', $this->adminUrl($controller, 'index'))->html(), "$table : pas de suppression en lot");
        }

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        foreach (self::REFERENTIALS as $controller => $table) {
            $id = (int) $this->db()->fetchOne("SELECT MIN(id) FROM $table");
            $this->assertSame(200, $this->status($controller, 'edit', $id), "$table : formulaire ouvert au super admin");
        }
        // Enregistrement effectif par le super admin sur un référentiel (valeurs inchangées : redirection après sauvegarde)
        $id = (int) $this->db()->fetchOne('SELECT MIN(id) FROM status_commande');
        $crawler = $this->client->request('GET', $this->adminUrl(StatusCommandeCrudController::class, 'edit', $id));
        $this->client->submit($crawler->filter('form[method="post"]')->form());
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testRawJsonSettingsAndAdminSettingsAreWritableBySuperAdminOnlyAndJsonNeverDeleted(): void
    {
        $landingId = (int) $this->db()->fetchOne('SELECT MIN(id) FROM landing_page_setting');
        $adminSettingsId = (int) $this->db()->fetchOne('SELECT MIN(id) FROM admin_settings');
        $this->assertGreaterThan(0, $landingId);
        $this->assertGreaterThan(0, $adminSettingsId);

        $this->loginAs(['ROLE_ADMIN']);
        foreach ([[LandingPageSettingCrudController::class, $landingId], [AdminSettingsCrudController::class, $adminSettingsId]] as [$controller, $id]) {
            $this->assertSame(200, $this->status($controller, 'index'), "$controller : liste lisible");
            $this->assertSame(403, $this->status($controller, 'new'), "$controller : création refusée");
            $this->assertSame(403, $this->status($controller, 'edit', $id), "$controller : modification refusée");
            $this->client->request('POST', $this->adminUrl($controller, 'delete', $id), ['token' => 'x']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$controller : suppression refusée");
        }
        $this->assertSame(200, $this->status(BoutiqueSettingCrudController::class, 'index'));
        $this->assertSame(403, $this->status(BoutiqueSettingCrudController::class, 'new'), 'configuration boutique : création refusée à l\'administrateur');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->assertSame(200, $this->status(LandingPageSettingCrudController::class, 'edit', $landingId));
        $this->assertSame(200, $this->status(BoutiqueSettingCrudController::class, 'new'));
        $this->assertSame(200, $this->status(AdminSettingsCrudController::class, 'detail', $adminSettingsId), 'détail AdminSettings lisible');
        $crawler = $this->client->request('GET', $this->adminUrl(AdminSettingsCrudController::class, 'edit', $adminSettingsId));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($crawler->filter('form[method="post"]')->form());
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'AdminSettings enregistré par le super admin');
        // Suppression des JSON bruts : désactivée même pour le super admin
        $this->client->request('POST', $this->adminUrl(LandingPageSettingCrudController::class, 'delete', $landingId), ['token' => 'x']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression de la configuration landing refusée au super admin');
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM landing_page_setting WHERE id = ?', [$landingId]));
        $this->assertStringNotContainsString('action-delete', (string) $this->client->request('GET', $this->adminUrl(LandingPageSettingCrudController::class, 'index'))->html());
    }

    public function testSquareConfigIsReadOnlyForEveryoneAndNeverShowsTheToken(): void
    {
        $db = $this->db();
        $id = (int) $db->fetchOne('SELECT MIN(id) FROM square_config');
        $this->assertGreaterThan(0, $id);
        $snapshot = $db->fetchAssociative('SELECT access_token, is_active FROM square_config WHERE id = ?', [$id]);
        // Le jeton est chiffré au repos : posé par le setter de l'entité, la colonne brute est restaurée à la fin
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $config = $provider->getEntityManager()->getRepository(\App\Entity\SquareConfig::class)->find($id);
        $config->setAccessToken(self::SQUARE_SECRET);
        $provider->getEntityManager()->flush();
        $this->assertSame(self::SQUARE_SECRET, $config->getAccessToken());
        try {
            foreach ([['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']] as $roles) {
                $this->loginAs($roles);
                $index = (string) $this->indexRow(SquareConfigCrudController::class, 'square_config', $id)->closest('table')?->html();
                $this->assertStringNotContainsString(self::SQUARE_SECRET, $index, 'jeton absent de la liste (y compris des attributs title)');
                $this->assertStringContainsString('••••••••', $index);
                $this->assertStringNotContainsString('form-switch', $index, 'aucun interrupteur de modification en ligne');
                $detail = (string) $this->client->request('GET', $this->adminUrl(SquareConfigCrudController::class, 'detail', $id))->html();
                $this->assertSame(200, $this->client->getResponse()->getStatusCode());
                $this->assertStringNotContainsString(self::SQUARE_SECRET, $detail, 'jeton absent du détail');
                $this->assertSame(403, $this->status(SquareConfigCrudController::class, 'new'), 'création désactivée pour tous');
                $this->assertSame(403, $this->status(SquareConfigCrudController::class, 'edit', $id), 'modification désactivée pour tous');
                $this->client->request('POST', $this->adminUrl(SquareConfigCrudController::class, 'delete', $id), ['token' => 'x']);
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression désactivée pour tous');
            }
            $this->assertNotSame($snapshot['access_token'], $db->fetchOne('SELECT access_token FROM square_config WHERE id = ?', [$id]), 'ligne intacte (jeton posé par le test toujours en place, chiffré)');
        } finally {
            $db->update('square_config', $snapshot, ['id' => $id], self::types($snapshot));
        }
    }

    public function testLuluPrintOrdersAreWritableBySuperAdminOnlyAndAppProfilesAreNeverDeleted(): void
    {
        $db = $this->db();
        $orderId = (string) $db->fetchOne('SELECT id::text FROM mv_book_print_order ORDER BY id LIMIT 1');
        $this->assertNotSame('', $orderId, 'une commande d\'impression de test existe');
        $profileId = (int) $db->fetchOne("INSERT INTO app_profile (app) VALUES ('boussole') RETURNING id");
        try {
            $this->loginAs(['ROLE_ADMIN']);
            $this->assertSame(200, $this->status(BookPrintOrderCrudController::class, 'index'));
            $this->assertSame(200, $this->status(BookPrintOrderCrudController::class, 'detail', $orderId));
            $this->assertSame(403, $this->status(BookPrintOrderCrudController::class, 'new'), 'commande d\'impression : création refusée');
            $this->assertSame(403, $this->status(BookPrintOrderCrudController::class, 'edit', $orderId), 'commande d\'impression : modification refusée');
            $this->client->request('POST', $this->adminUrl(BookPrintOrderCrudController::class, 'delete', $orderId), ['token' => 'x']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'commande d\'impression : suppression refusée');
            $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mv_book_print_order WHERE id = ?', [$orderId]));
            $this->assertStringContainsString('syncLulu', (string) $this->client->request('GET', $this->adminUrl(BookPrintOrderCrudController::class, 'detail', $orderId))->html(), 'synchronisation Lulu toujours offerte à l\'administrateur');

            // Profils des applications : l'administrateur modifie, personne ne supprime
            $this->assertSame(200, $this->status(AppProfileCrudController::class, 'edit', $profileId), 'profil d\'application modifiable par l\'administrateur');
            $this->client->request('POST', $this->adminUrl(AppProfileCrudController::class, 'delete', $profileId), ['token' => 'x']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression d\'un profil refusée à l\'administrateur');
            $this->assertStringNotContainsString('action-delete', (string) $this->client->request('GET', $this->adminUrl(AppProfileCrudController::class, 'index'))->html());

            $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
            $this->assertSame(200, $this->status(BookPrintOrderCrudController::class, 'edit', $orderId), 'commande d\'impression modifiable par le super admin');
            $this->client->request('POST', $this->adminUrl(AppProfileCrudController::class, 'delete', $profileId), ['token' => 'x']);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'suppression d\'un profil refusée même au super admin');
            $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM app_profile WHERE id = ?', [$profileId]), 'profil toujours présent');
        } finally {
            $db->executeStatement('DELETE FROM app_profile WHERE id = ?', [$profileId]);
        }
    }

    private function status(string $controller, string $action, int|string|null $id = null): int
    {
        $this->client->request('GET', $this->adminUrl($controller, $action, $id));

        return $this->client->getResponse()->getStatusCode();
    }

    private function adminUrl(string $controller, string $action, int|string|null $id = null, array $extra = []): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id]) + $extra);
    }

    /** Ligne d'une entité dans une liste EasyAdmin paginée (20 par page, tri par identifiant croissant) : page calculée d'après la position en base */
    private function indexRow(string $controller, string $table, int $id): Crawler
    {
        $position = (int) $this->db()->fetchOne("SELECT COUNT(*) FROM $table WHERE id <= ?", [$id]);
        $crawler = $this->client->request('GET', $this->adminUrl($controller, 'index', null, ['page' => intdiv($position - 1, 20) + 1]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$table : liste lisible");

        return $crawler->filter(sprintf('tr[data-id="%d"]', $id));
    }

    private function loginAs(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'grille-2c-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Grille')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function db(): Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }

    /** Types DBAL d'une ligne lue par fetchAssociative (PDO pgsql lie false à '' sans cela) */
    private static function types(array $row): array
    {
        return array_map(fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : (is_int($v) ? \Doctrine\DBAL\ParameterType::INTEGER : \Doctrine\DBAL\ParameterType::STRING), $row);
    }
}
