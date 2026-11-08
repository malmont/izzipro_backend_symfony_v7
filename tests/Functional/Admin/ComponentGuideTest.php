<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\Admin\ComponentGuideCatalog;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guide des composants (08/11/2026) : chaque écran de composant du site a un nom explicite, un panneau de droite avec une illustration et
 * l'explication (liste, création, modification) ; les autres écrans n'ont pas de panneau ; chaque guide a son illustration.
 */
class ComponentGuideTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'guide-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Guide')->setLastname('Test')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }

    public function testEveryGuideHasItsIllustrationAndTheScreensShowThePanel(): void
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        foreach (ComponentGuideCatalog::GUIDES as $fqcn => $guide) {
            $this->assertTrue(class_exists($fqcn), $fqcn);
            $this->assertFileExists($projectDir . '/templates/admin/guide/svg/' . $guide['svg'] . '.html.twig', $guide['key']);
            foreach (['title', 'singular', 'purpose', 'where'] as $k) {
                $this->assertNotSame('', trim($guide[$k]), "$fqcn.$k");
            }
            $this->assertNotSame([], $guide['fields'], $fqcn);
        }

        // Liste des blocs de présentation : nom explicite, panneau, illustration, texte « Quoi remplir »
        $html = $this->admin(['crudAction' => 'index', 'crudControllerFqcn' => \App\Controller\Admin\PresentationCrudController::class]);
        $this->assertStringContainsString('Blocs de présentation (image, titre, texte, bouton)', $html, 'titre explicite de l\'écran');
        $this->assertStringContainsString('data-component-guide="presentation"', $html, 'panneau de droite');
        $this->assertStringContainsString('<svg viewBox="0 0 320 200"', $html, 'illustration');
        $this->assertStringContainsString('Quoi remplir', $html);
        $this->assertStringContainsString('Groupe de blocs', $html, 'le conseil renvoie aux groupes');

        // Création d'une marque : même panneau ; modification d'une entité quelconque sans guide : aucun panneau
        $new = $this->admin(['crudAction' => 'new', 'crudControllerFqcn' => \App\Controller\Admin\MarqueCrudController::class]);
        $this->assertStringContainsString('data-component-guide="marque"', $new);
        $this->assertStringContainsString('Logos de marques et partenaires', $new);
        $taxes = $this->admin(['crudAction' => 'index', 'crudControllerFqcn' => \App\Controller\Admin\TaxCrudController::class]);
        $this->assertStringNotContainsString('data-component-guide', $taxes, 'les écrans hors composants restent tels quels');

        // Menu : les anciens noms ont disparu
        foreach (['>Home Slider<', '>Admin Settings<', '>Explore Cards<', '>Features<', '>Multiliens<', '>Section Recherche<', '>Présentations<', '>Groupes de Présentation<'] as $old) {
            $this->assertStringNotContainsString($old, $taxes, $old);
        }
        $this->assertStringContainsString('Site vitrine (Landing Page)', $taxes);
        $this->assertStringContainsString('Boutique : accueil et pages', $taxes);
    }

    private function admin(array $query): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query($query));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($query));

        return (string) $this->client->getResponse()->getContent();
    }
}
