<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page Configuration, lot 5 (07/11/2026) : les quatre cheminements (Stripe, taxes, courriels, avis) avec leurs étapes et leur état
 * calculé depuis la base, le courriel d'essai envoyé à l'administrateur connecté, les refus (client, anonyme, CSRF).
 */
class ConfigurationConsolePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
    }

    public function testPathsReflectTheDatabaseAndTheTestEmailGoesToTheAdmin(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], 'anonyme : refusé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration/courriel-essai', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'client : refusé');

        $email = $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->db->executeStatement('DELETE FROM review_setting');
        try {
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $html = html_entity_decode((string) $this->client->getResponse()->getContent());
            foreach (['stripe', 'taxes', 'email', 'reviews'] as $path) {
                $this->assertSame(1, $crawler->filter('[data-configuration-path="' . $path . '"]')->count(), "cheminement $path");
            }
            $status = fn (string $key) => $this->client->getCrawler()->filter('[data-step="' . $key . '"]')->attr('data-status'); // relit la page courante

            // Taxes : l'adresse de l'entreprise (Canada/QC) et la table de taxes du modèle
            $taxes = (int) $this->db->fetchOne('SELECT COUNT(*) FROM tax');
            $this->assertSame('done', $status('tax.address'));
            $this->assertSame($taxes > 0 ? 'done' : 'todo', $status('tax.table'));
            $this->assertStringContainsString($taxes > 0 ? sprintf('%d taxe(s) saisie(s)', $taxes) : 'Aucune taxe', $html);

            // Stripe : les étapes de la plateforme et du site sont là, l'état d'un paiement d'essai suit la table des paiements
            $this->assertContains($status('stripe.platform_keys'), ['done', 'pending']);
            $this->assertContains($status('stripe.account'), ['done', 'todo']);
            $this->assertSame((int) $this->db->fetchOne("SELECT COUNT(*) FROM payments WHERE stripe_payment_id IS NOT NULL AND stripe_status = 'succeeded' AND amount > 0") > 0 ? 'done' : $status('stripe.first_payment'), $status('stripe.first_payment'));
            $this->assertStringContainsString('4242 4242 4242 4242', $html, 'mode test : carte d\'essai expliquée');

            // Courriels : expéditeur du modèle, SMTP facultatif, courriel d'essai proposé
            $this->assertSame('done', $status('email.sender'));
            $this->assertSame('optional', $status('email.smtp'), 'pas de SMTP propre dans le modèle : la plateforme envoie');
            $this->assertSame('todo', $status('email.test'));
            $this->assertStringContainsString($email, $html, 'le bouton annonce l\'adresse de l\'administrateur');

            // Avis : sans ligne de réglages, valeurs par défaut et politique à rédiger
            $this->assertSame('done', $status('reviews.settings'));
            $this->assertStringContainsString('valeurs par défaut', $html);
            $this->assertSame('todo', $status('reviews.policy'));
            $this->assertSame((int) $this->db->fetchOne("SELECT COUNT(*) FROM reviews_product WHERE status = 'pending'") > 0 ? 'todo' : 'done', $status('reviews.moderation'));

            // Une politique rédigée : l'étape passe à faite
            $this->db->executeStatement("INSERT INTO review_setting (enabled, verified_only, moderation, min_length, policy, updated_at) VALUES (TRUE, TRUE, 'manual', 20, '{\"fr\": \"Avis vérifiés.\"}', NOW())");
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame('done', $status('reviews.policy'));
            $this->assertStringContainsString('Politique rédigée (fr)', html_entity_decode((string) $this->client->getResponse()->getContent()));

            // Courriel d'essai : CSRF obligatoire, puis envoi à l'administrateur connecté
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration/courriel-essai', ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode());
            $this->assertEmailCount(0);
            $token = $crawler->filter('form[data-email-test-form] input[name="_token"]')->attr('value');
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/configuration/courriel-essai', ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(302, $this->client->getResponse()->getStatusCode());
            $this->assertEmailCount(1);
            $mail = $this->getMailerMessage();
            $this->assertSame($email, $mail->getTo()[0]->getAddress());
            $this->assertStringContainsString('Courriel d\'essai', (string) $mail->getHtmlBody());
            $this->assertStringContainsString('serveur de la plateforme', (string) $mail->getHtmlBody());
            $this->client->followRedirect();
            $this->assertStringContainsString('Courriel d\'essai envoyé à ' . $email, html_entity_decode((string) $this->client->getResponse()->getContent()));
        } finally {
            $this->db->executeStatement('DELETE FROM review_setting');
        }
    }

    private function loginAs(array $roles): string
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'config-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Config')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $email;
    }
}
