<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Action « Effacer ce compte client » du back-office (B19, 06/11/2026) : mêmes effets que DELETE /api/account (compte, adresses,
 * infolettre, client Stripe, sessions, courriel au client), livres Mémoires Vivantes effacés, mêmes refus (abonnement, réservation),
 * jamais sur un compte interne ou le sien, page de confirmation CSRF, trace sans donnée personnelle, droits.
 */
class CustomerAccountEraseAdminTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        FakeSubscriptionStripeGateway::reset();
    }

    public function testAnAdministratorErasesACustomerWithTheSameEffectsAsTheApi(): void
    {
        $admin = $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        [$email, $id] = $this->customer();
        $db = $this->db();
        $db->executeStatement('INSERT INTO newsletter_subscriber (id, email, subscribed_at) VALUES (nextval(\'newsletter_subscriber_id_seq\'), ?, NOW())', [$email]);
        $db->executeStatement("INSERT INTO reservation (id, service_name, reservation_date, client_name, client_email, client_phone, number_of_guests, status, created_at)
            VALUES (nextval('reservation_id_seq'), 'Visite', CURRENT_DATE - 30, 'Marie Tremblay', ?, '+1 514 555 0100', 1, 'confirmed', NOW())", [$email]);
        $db->executeStatement('UPDATE "user" SET stripe_customer_id = ? WHERE id = ?', ['cus_admin_' . $id, $id]);
        $db->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '7 days')", ['rt-' . bin2hex(random_bytes(8)), $email]);
        // Un livre Mémoires Vivantes sans comptabilité : effacé entièrement, comme depuis l'application
        $db->executeStatement("INSERT INTO mv_book (id, user_id, title, type, format, status, channel, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'Livre à effacer', 'famille', 'a5', 'draft', 'web', NOW(), NOW())", [$id]);

        // La fiche propose « Effacer », plus « Supprimer »
        $this->client->request('GET', $this->crudUrl(['crudAction' => 'detail', 'entityId' => $id]));
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Effacer ce compte client', $html);
        $this->assertStringContainsString('routeName=admin_user_erase', $html, 'lien habillé EasyAdmin vers la page de confirmation');
        $this->assertStringNotContainsString('action-delete', $html, 'la suppression physique n\'est plus proposée pour un compte client');

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer");
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString($email, $html);
        $this->assertStringContainsString('1 livre(s) Mémoires Vivantes', $html);
        $this->assertStringContainsString('irréversible', $html);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => 'faux', 'confirm' => '1']);
        $this->assertStringContainsString('Jeton de sécurité invalide', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => $token]);
        $this->assertStringContainsString('Cochez la case', (string) $this->client->getResponse()->getContent());
        $this->assertNull($db->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$id]), 'rien n\'est effacé sans confirmation');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => $token, 'confirm' => '1']);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertEmailCount(1, null, 'le client reçoit le courriel de confirmation, après l\'effacement');
        $this->assertSame($email, $this->getMailerMessage()->getTo()[0]->getAddress());
        $this->client->followRedirect();
        $this->assertStringContainsString("Compte client n° $id effacé", html_entity_decode((string) $this->client->getResponse()->getContent()));

        $user = $db->fetchAssociative('SELECT email, firstname, lastname, roles::text AS roles, is_verified, deleted_at, stripe_customer_id, primary_address_id FROM "user" WHERE id = ?', [$id]);
        $this->assertSame(["supprime-$id@compte-supprime.invalid", 'Compte', 'supprimé', '[]', false, null, null], [$user['email'], $user['firstname'], $user['lastname'], $user['roles'], (bool) $user['is_verified'], $user['stripe_customer_id'], $user['primary_address_id']]);
        $this->assertNotNull($user['deleted_at']);
        $this->assertContains(['deleteCustomer', 'cus_admin_' . $id], FakeSubscriptionStripeGateway::$calls);
        $address = $db->fetchAssociative('SELECT firstname, address, phone, city, codepostal, country, user_adress_id FROM adress WHERE id = (SELECT MAX(id) FROM adress)');
        $this->assertSame(['Compte', '-', '-', '-', '-', 'CA', null], array_values($address), 'adresse anonymisée sur place, pays gardé');
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM newsletter_subscriber WHERE email = ?', [$email]));
        $this->assertSame('Client anonyme', $db->fetchOne('SELECT client_name FROM reservation WHERE id = (SELECT MAX(id) FROM reservation)'));
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', [$email]), 'sessions de l\'application fermées');
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mv_book WHERE user_id = ?', [$id]), 'livre Mémoires Vivantes effacé');

        $trace = $db->fetchAssociative("SELECT user_identifier, action, after_state FROM content_audit_log WHERE resource = 'user' AND resource_id = ? ORDER BY id DESC", [(string) $id]);
        $this->assertSame([$admin->getEmail(), 'erase'], [$trace['user_identifier'], $trace['action']]);
        $this->assertStringNotContainsString($email, (string) $trace['after_state'], 'aucune donnée personnelle du client dans la trace');
        $this->assertStringContainsString('"actorUserId":' . $admin->getId(), (string) $trace['after_state']);

        // Déjà effacé : plus d'action proposée, et la page refuse
        $this->client->request('GET', $this->crudUrl(['crudAction' => 'detail', 'entityId' => $id]));
        $this->assertStringNotContainsString('admin_user_erase', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => $token, 'confirm' => '1']);
        $this->assertStringContainsString('déjà été effacé', (string) $this->client->getResponse()->getContent());
    }

    public function testASubscriptionOrAnUpcomingReservationBlocksWithALinkToSettleIt(): void
    {
        $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        [$email, $id] = $this->customer();
        $db = $this->db();
        $db->executeStatement("INSERT INTO reservation (id, service_name, reservation_date, client_name, client_email, client_phone, number_of_guests, status, created_at)
            VALUES (nextval('reservation_id_seq'), 'Visite', CURRENT_DATE + 3, 'Marie Tremblay', ?, '+1 514 555 0100', 1, 'confirmed', NOW())", [$email]);
        $reservationId = (int) $db->fetchOne('SELECT MAX(id) FROM reservation');

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer");
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Effacement impossible pour l\'instant', $html);
        $this->assertStringContainsString('annulez-la d\'abord depuis Réservations', $html);
        $this->assertStringContainsString("Réservation n° $reservationId", $html);
        $this->assertStringContainsString('ReservationCrudController', $html, 'lien vers la fiche de la réservation');
        $this->assertSame(0, $crawler->filter('form[data-erase-form]')->count(), 'aucun formulaire tant que ça bloque');

        // Formulaire forgé (jeton valable pris sur la page d'un autre compte) : refusé, rien n'est effacé
        $token = $this->tokenFromAnErasablePage();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => $token, 'confirm' => '1']);
        $this->assertStringContainsString('Réservations', (string) $this->client->getResponse()->getContent());
        $this->assertNull($db->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$id]));

        // Réservation annulée depuis le back-office : l'effacement redevient possible
        $db->executeStatement("UPDATE reservation SET status = 'cancelled' WHERE id = ?", [$reservationId]);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer");
        $this->assertStringContainsString('data-erase-form', (string) $this->client->getResponse()->getContent());

        // Abonnement en cours
        [$email2, $id2] = $this->customer();
        // La base de test n'a ni produit ni formule : un produit et une formule minimaux, le temps du test
        $productId = (int) $db->fetchOne("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Produit abonnement test', 10, false, 1, NOW(), ?, 'product') RETURNING id", ['abonnement-test-' . bin2hex(random_bytes(3))]);
        $planId = (int) $db->fetchOne("INSERT INTO subscription_plan (id, product_id, names, interval, interval_count, price, currency, trial_days, minimum_terms, active, created_at, highlighted)
            VALUES (nextval('subscription_plan_id_seq'), ?, '{\"fr\": \"Formule de test\"}', 'month', 1, 1000, 'CAD', 0, 0, true, NOW(), false) RETURNING id", [$productId]);
        $db->executeStatement("INSERT INTO subscription (id, user_id, plan_id, status, created_at, updated_at) VALUES (nextval('subscription_id_seq'), ?, ?, 'active', NOW(), NOW())", [$id2, $planId]);
        try {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id2/effacer");
            $html = html_entity_decode((string) $this->client->getResponse()->getContent());
            $this->assertStringContainsString('résiliez-le d\'abord depuis Abonnements', $html);
            $this->assertStringContainsString('SubscriptionCrudController', $html, 'lien vers la fiche de l\'abonnement');
        } finally {
            // Le produit minimal fausserait les listes publiques des autres tests (booléens null)
            $db->executeStatement('DELETE FROM subscription WHERE plan_id = ?', [$planId]);
            $db->executeStatement('DELETE FROM subscription_plan WHERE id = ?', [$planId]);
            $db->executeStatement('DELETE FROM product WHERE id = ?', [$productId]);
        }
    }

    public function testInternalAccountsSelfAndNonAdministratorsAreRefused(): void
    {
        $admin = $this->login(['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN']);
        $db = $this->db();
        $token = $this->tokenFromAnErasablePage();
        foreach ([['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_ADMIN'], ['ROLE_USER', 'ROLE_USER_INTERNET', 'ROLE_USER_POS']] as $roles) {
            [, $id] = $this->customer($roles);
            $this->client->request('GET', $this->crudUrl(['crudAction' => 'detail', 'entityId' => $id]));
            $this->assertStringNotContainsString('admin_user_erase', (string) $this->client->getResponse()->getContent(), 'pas d\'action pour un compte interne');
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer", ['_token' => $token, 'confirm' => '1']);
            $this->assertStringContainsString('Seul un compte client du site peut être effacé ici', html_entity_decode((string) $this->client->getResponse()->getContent()));
            $this->assertNull($db->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$id]));
        }
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/{$admin->getId()}/effacer", ['_token' => $token, 'confirm' => '1']);
        $this->assertStringContainsString('votre propre compte', (string) $this->client->getResponse()->getContent());

        // La suppression physique d'un compte client est refusée même par une URL forgée
        [, $customerId] = $this->customer();
        $crawler = $this->client->request('GET', $this->crudUrl(['crudAction' => 'detail', 'entityId' => $customerId]));
        $deleteToken = $crawler->filter('#delete-form input[name="token"]')->attr('value');
        $this->client->request('POST', $this->crudUrl(['crudAction' => 'delete', 'entityId' => $customerId]), ['token' => $deleteToken]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'un compte client s\'efface, il ne se supprime pas');
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$customerId]));

        // Un simple client connecté n'ouvre pas la page
        $this->login(['ROLE_USER', 'ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$customerId/effacer");
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'réservé aux administrateurs du site');
        $this->assertNull($db->fetchOne('SELECT deleted_at FROM "user" WHERE id = ?', [$customerId]));
    }

    /** Jeton CSRF du formulaire, lu sur la page d'un compte client effaçable (le jeton ne dépend pas du compte visé) */
    private function tokenFromAnErasablePage(): string
    {
        [, $id] = $this->customer();
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/admin/users/$id/effacer");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function crudUrl(array $params): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query($params + ['crudControllerFqcn' => 'App\Controller\Admin\UserCrudController']);
    }

    private function login(array $roles): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'effaceur-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Site')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }

    /** @return array{0: string, 1: int} e-mail et identifiant, avec une adresse principale */
    private function customer(array $roles = ['ROLE_USER', 'ROLE_USER_INTERNET']): array
    {
        $email = 'client-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles($roles)->setIsVerified(true)->setPasswordSet(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $address = (new Adress())->setFirstname('Marie')->setLastname('Tremblay')->setFullname('Tremblay Marie')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($user);
        $em->persist($address);
        $em->flush();
        $user->setPrimaryAddress($address);
        $em->flush();

        return [$email, (int) $user->getId()];
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
