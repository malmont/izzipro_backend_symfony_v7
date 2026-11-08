<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page d'accueil EasyAdmin (cheminement complet, 07/11/2026) : la carte « Préparer mes produits » liste les étapes par produit actif,
 * rouge pour une étape indispensable à faire, orange pour une amélioration, vert pour une étape faite, avec un lien vers l'écran ciblé.
 */
class DashboardReadinessTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    public function testTheCardShowsEachProductWithColoursAndLinks(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $card = $crawler->filter('[data-readiness-card]');
        $this->assertSame(1, $card->count(), 'carte présente dès qu\'il y a des étapes');
        $this->assertGreaterThanOrEqual(1, $crawler->filter('[data-readiness-group="site"]')->count(), 'le socle du site est un groupe');
        $steps = $crawler->filter('[data-readiness-card] [data-step]');
        $this->assertGreaterThan(5, $steps->count());
        $tones = [];
        foreach ($steps as $node) {
            $tones[] = $node->getAttribute('data-tone');
        }
        $this->assertSame([], array_diff(array_unique($tones), ['danger', 'warning', 'success', 'secondary']), 'quatre couleurs seulement');
        $this->assertSame(0, $crawler->filter('[data-readiness-card] [data-step^="obtain."]')->count(), 'les fonctionnalités à obtenir ne sont pas des étapes de produit');
        // Voir mon site en ligne (08/11/2026) : un bandeau de liens vers l'accueil et les produits accordés, un lien par groupe qui a une page
        $links = $crawler->filter('[data-readiness-card] [data-readiness-links] a[data-public-link]');
        $this->assertGreaterThanOrEqual(1, $links->count(), 'bandeau « Voir mon site en ligne »');
        $this->assertSame('site', $links->first()->attr('data-public-link'));
        $this->assertStringStartsWith('https://', (string) $links->first()->attr('href'));
        $this->assertSame('_blank', $links->first()->attr('target'));
        $this->assertSame(1, $crawler->filter('[data-readiness-group="site"] a[data-group-link="site"]')->count(), 'le socle mène à l\'accueil');
        foreach ($crawler->filter('[data-readiness-card] a[data-group-link]') as $a) {
            $this->assertStringStartsWith('https://', (string) $a->getAttribute('href'));
        }
        $todo = $crawler->filter('[data-readiness-card] [data-tone="danger"], [data-readiness-card] [data-tone="warning"]');
        if ($todo->count() > 0) {
            $this->assertGreaterThan(0, $todo->filter('a[href]')->count(), 'une étape à faire mène quelque part');
        }
        $legal = $crawler->filter('[data-readiness-card] [data-step="site.legal"]');
        $this->assertSame(1, $legal->count());
        $this->assertStringContainsString((int) $card->attr('data-blocking') > 0 ? 'indispensable' : 'amélioration', html_entity_decode((string) $this->client->getResponse()->getContent()));
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $email = 'dash-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Dash')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
