<?php

namespace App\Tests\Functional\LandingPage;

use App\Controller\Admin\AiCreditSettingCrudController;
use App\Entity\AiCreditSetting;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Contrôle d'accès sur l'écran EasyAdmin « Assistant IA : crédits » :
 * - ROLE_ADMIN voit les crédits en lecture seule et modifie les consignes ;
 * - un envoi forgé de monthlyCredits par ROLE_ADMIN est neutralisé côté serveur ;
 * - ROLE_SUPER_ADMIN modifie les crédits et les consignes.
 */
class AiCreditSettingAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->db()->executeStatement('DELETE FROM ai_credit_setting');
    }

    public function testSiteAdminSeesDisabledCreditsAndCanEditInstructions(): void
    {
        $this->login(['ROLE_ADMIN']);

        $crawler = $this->admin('new');
        $creditsInput = $crawler->filter('input[name="AiCreditSetting[monthlyCredits]"]');
        $instructionsInput = $crawler->filter('textarea[name="AiCreditSetting[assistantInstructions]"]');

        $this->assertCount(1, $creditsInput);
        $this->assertSame('disabled', $creditsInput->attr('disabled'), 'champ crédits verrouillé pour l\'administrateur de site');
        $this->assertCount(1, $instructionsInput);
        $this->assertNull($instructionsInput->attr('disabled'), 'consignes modifiables par l\'administrateur de site');

        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $this->client->submit($form, [
            'AiCreditSetting[assistantInstructions]' => 'Toujours vouvoyer le lecteur.',
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT id, monthly_credits, assistant_instructions FROM ai_credit_setting LIMIT 1');
        $this->assertIsArray($row);
        $this->assertSame(AiCreditSetting::DEFAULT_MONTHLY_CREDITS, (int) $row['monthly_credits'], 'création par un administrateur : crédits par défaut (100)');
        $this->assertSame('Toujours vouvoyer le lecteur.', $row['assistant_instructions']);

        $id = (int) $row['id'];
        $this->db()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 250 WHERE id = ?', [$id]);

        $crawler = $this->admin('edit', $id);
        $creditsInput = $crawler->filter('input[name="AiCreditSetting[monthlyCredits]"]');
        $this->assertCount(1, $creditsInput);
        $this->assertSame('disabled', $creditsInput->attr('disabled'));
        $this->assertSame('250', $creditsInput->attr('value'));

        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $this->client->submit($form, [
            'AiCreditSetting[assistantInstructions]' => 'Ton chaleureux et concis.',
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT monthly_credits, assistant_instructions FROM ai_credit_setting WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(250, (int) $row['monthly_credits'], 'édition par un administrateur : crédits existants conservés');
        $this->assertSame('Ton chaleureux et concis.', $row['assistant_instructions']);
    }

    public function testForgedSubmissionBySiteAdminDoesNotChangeMonthlyCredits(): void
    {
        $this->login(['ROLE_ADMIN']);

        // 1. Envoi forgé à la création (sans ligne existante) -> doit forcer 100 tout en enregistrant les consignes
        $crawler = $this->admin('new');
        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $values = $form->getPhpValues();
        $values['AiCreditSetting']['monthlyCredits'] = '9999';
        $values['AiCreditSetting']['assistantInstructions'] = 'Consignes forgées à la création';

        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT id, monthly_credits, assistant_instructions FROM ai_credit_setting LIMIT 1');
        $this->assertIsArray($row);
        $this->assertSame(AiCreditSetting::DEFAULT_MONTHLY_CREDITS, (int) $row['monthly_credits'], 'envoi forgé à la création neutralisé');
        $this->assertSame('Consignes forgées à la création', $row['assistant_instructions']);

        // 2. Envoi forgé en édition sur une ligne à 250 crédits -> doit conserver 250 tout en enregistrant les consignes
        $id = (int) $row['id'];
        $this->db()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 250 WHERE id = ?', [$id]);

        $crawler = $this->admin('edit', $id);
        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $values = $form->getPhpValues();
        $values['AiCreditSetting']['monthlyCredits'] = '9999';
        $values['AiCreditSetting']['assistantInstructions'] = 'Consignes forgées en édition';

        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT monthly_credits, assistant_instructions FROM ai_credit_setting WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(250, (int) $row['monthly_credits'], 'envoi forgé en édition neutralisé');
        $this->assertSame('Consignes forgées en édition', $row['assistant_instructions']);

        // 3. Envoi forgé via l'action new alors qu'une ligne existe déjà -> doit conserver 250
        $crawler = $this->admin('new');
        $form = $crawler->filter('form[name="AiCreditSetting"]')->form();
        $values = $form->getPhpValues();
        $values['AiCreditSetting']['monthlyCredits'] = '9999';
        $values['AiCreditSetting']['assistantInstructions'] = 'Consignes forgées via création doublon';

        $this->client->request('POST', $form->getUri(), $values);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT monthly_credits, assistant_instructions FROM ai_credit_setting WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(250, (int) $row['monthly_credits'], 'envoi forgé via new sur ligne existante neutralisé');
        $this->assertSame('Consignes forgées via création doublon', $row['assistant_instructions']);
    }

    public function testSuperAdminCanEditBothFieldsAndNegativeCreditsAreRefused(): void
    {
        $this->login(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $crawler = $this->admin('new');
        $creditsInput = $crawler->filter('input[name="AiCreditSetting[monthlyCredits]"]');
        $instructionsInput = $crawler->filter('textarea[name="AiCreditSetting[assistantInstructions]"]');

        $this->assertCount(1, $creditsInput);
        $this->assertNull($creditsInput->attr('disabled'), 'champ crédits modifiable par le super administrateur');
        $this->assertCount(1, $instructionsInput);
        $this->assertNull($instructionsInput->attr('disabled'));

        // Valeur négative refusée (422)
        $this->client->submit($crawler->filter('form[name="AiCreditSetting"]')->form(), [
            'AiCreditSetting[monthlyCredits]' => '-5',
        ]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ai_credit_setting'));

        // Création à 250 crédits + consignes
        $crawler = $this->admin('new');
        $this->client->submit($crawler->filter('form[name="AiCreditSetting"]')->form(), [
            'AiCreditSetting[monthlyCredits]' => '250',
            'AiCreditSetting[assistantInstructions]' => 'Consignes initiales super admin',
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT id, monthly_credits, assistant_instructions FROM ai_credit_setting LIMIT 1');
        $this->assertIsArray($row);
        $this->assertSame(250, (int) $row['monthly_credits']);
        $this->assertSame('Consignes initiales super admin', $row['assistant_instructions']);

        // Édition à 300 crédits + consignes
        $id = (int) $row['id'];
        $crawler = $this->admin('edit', $id);
        $this->client->submit($crawler->filter('form[name="AiCreditSetting"]')->form(), [
            'AiCreditSetting[monthlyCredits]' => '300',
            'AiCreditSetting[assistantInstructions]' => 'Consignes modifiées super admin',
        ]);
        $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        $row = $this->db()->fetchAssociative('SELECT monthly_credits, assistant_instructions FROM ai_credit_setting WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(300, (int) $row['monthly_credits']);
        $this->assertSame('Consignes modifiées super admin', $row['assistant_instructions']);
    }

    private function admin(string $action = 'index', ?int $entityId = null): Crawler
    {
        $query = [
            'crudAction' => $action,
            'crudControllerFqcn' => AiCreditSettingCrudController::class,
        ];
        if ($entityId !== null) {
            $query['entityId'] = $entityId;
        }

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query($query));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($query));

        return $crawler;
    }

    private function login(array $roles = ['ROLE_ADMIN']): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'ai-credit-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Crédits')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function db(): Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}

