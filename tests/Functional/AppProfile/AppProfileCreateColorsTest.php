<?php

namespace App\Tests\Functional\AppProfile;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Création d'un profil dans EasyAdmin (31/10/2026) : une couleur laissée vide reste null, jamais #000000 */
class AppProfileCreateColorsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $db = static::getContainer()->get('doctrine')->getConnection();
        $db->executeStatement('DELETE FROM app_profile_translation');
        $db->executeStatement('DELETE FROM app_profile');
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'colors-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('C')->setLastname('T')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
    }

    public function testColoursLeftEmptyAtCreationStayNull(): void
    {
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?crudAction=new&crudControllerFqcn=App%5CController%5CAdmin%5CAppProfileCrudController');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = $this->client->getResponse()->getContent();
        foreach (['primaryColor', 'accentColor'] as $field) {
            $this->assertSame(1, preg_match('/<input type="hidden"[^>]*name="AppProfile\[' . $field . '\]"[^>]*value="([^"]*)"/', $html, $m), $field);
            $this->assertSame('', $m[1], "$field : vide à la création");
        }
        $this->assertSame(2, substr_count($html, 'optional-color-picker" value="#000000" disabled'), 'les deux sélecteurs sont hors formulaire et désactivés');
        $db = static::getContainer()->get('doctrine')->getConnection();
        // 1. Création sans toucher aux couleurs : null, jamais #000000
        $form = $crawler->selectButton('Create')->form();
        $form['AppProfile[app]'] = 'mobile';
        $form['AppProfile[appName]'] = 'Essai couleurs';
        $this->client->submit($form);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(['primary_color' => null, 'accent_color' => null], $db->fetchAssociative("SELECT primary_color, accent_color FROM app_profile WHERE app = 'mobile'"));
        // 2. Création avec le champ envoyé vide (case « Aucune » cochée dans un navigateur) : null aussi ; une couleur choisie est gardée
        $db->executeStatement('DELETE FROM app_profile');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?crudAction=new&crudControllerFqcn=App%5CController%5CAdmin%5CAppProfileCrudController');
        $form = $crawler->selectButton('Create')->form();
        $form['AppProfile[app]'] = 'mobile';
        $form['AppProfile[primaryColor]'] = '';
        $form['AppProfile[accentColor]'] = '#FF8800';
        $this->client->submit($form);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(['primary_color' => null, 'accent_color' => '#ff8800'], $db->fetchAssociative("SELECT primary_color, accent_color FROM app_profile WHERE app = 'mobile'"));
    }
}
