<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PUT / GET /api/boutique-settings : validation à l'écriture (compositions, pages système, charte, commerce),
 * restitution fidèle à la lecture, journal et retour en arrière.
 */
class BoutiqueSettingsApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db()->executeStatement('DELETE FROM boutique_setting');
        $this->db()->executeStatement("DELETE FROM content_audit_log WHERE resource = 'boutique-settings'");
    }

    public function testValidConfigurationIsStoredReturnedUnchangedAndJournaled(): void
    {
        $this->loginAsAdmin();
        $this->assertSame('null', $this->request('GET')->getContent(), 'rien d\'enregistré : null');
        $landingBefore = $this->request('GET', null, '/api/landingpage-settings')->getContent();
        $body = $this->encode(['configuration' => $this->configuration()]);

        $put = $this->request('PUT', $body);
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());

        $get = $this->request('GET');
        $this->assertSame(200, $get->getStatusCode());
        // Même document JSON : ordre des clés, 1.0, pages système, charte et commerce conservés tels quels
        $this->assertSame(
            json_encode(json_decode($body, false)->configuration, JSON_PRESERVE_ZERO_FRACTION),
            json_encode(json_decode($get->getContent(), false), JSON_PRESERVE_ZERO_FRACTION)
        );
        $this->assertSame($landingBefore, $this->request('GET', null, '/api/landingpage-settings')->getContent(), 'réglages de la landing page intacts');

        $journal = json_decode($this->request('GET', null, '/api/landingpage-audit?resource=boutique-settings')->getContent(), true);
        $this->assertSame(1, $journal['total']);
        $this->assertSame(['boutique-settings', 'update'], [$journal['items'][0]['resource'], $journal['items'][0]['action']]);
        $this->assertContains('tabs', $journal['items'][0]['fields']);
        $this->assertFalse($journal['items'][0]['restorable'], 'première écriture : aucun état d\'avant à rétablir');
    }

    public function testSystemPageWithoutItsRequiredBlockIsRefusedAndNothingIsStored(): void
    {
        $this->loginAsAdmin();
        $this->assertSame(200, $this->request('PUT', $this->encode(['configuration' => $this->configuration()]))->getStatusCode());
        $before = $this->request('GET')->getContent();

        $configuration = $this->configuration();
        $blocks = &$configuration['tabs'][2]['sections'][0]['reglableConfig']['blocks'];
        $blocks = array_values(array_filter($blocks, fn ($b) => $b['type'] !== 'stripePayment'));
        unset($blocks);

        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $put->getStatusCode(), $put->getContent());
        $payload = json_decode($put->getContent(), true);
        $this->assertSame('Configuration de la boutique invalide', $payload['error']);
        $this->assertContains(['path' => 'tabs[2].sections', 'message' => 'page système « checkout » : bloc(s) obligatoire(s) manquant(s) : stripePayment'], $payload['errors']);
        $this->assertStringContainsString('stripePayment', $payload['message']);
        $this->assertSame($before, $this->request('GET')->getContent(), 'rien n\'est enregistré');
    }

    public function testProductPageRequiresAModeGroupWhichIsAContainerWithAMode(): void
    {
        $this->loginAsAdmin();
        $configuration = $this->configuration();
        $product = $this->preset('product-type-c');
        foreach ($product['blocks'] as &$block) {
            unset($block['mode']); // le modèle du catalogue a ses groupes de mode : on les retire pour provoquer le refus
        }
        unset($block);
        $configuration['tabs'][] = ['id' => 'sys-product', 'system' => 'product', 'isVisible' => false, 'title' => ['fr' => 'Fiche produit', 'en' => 'Product'],
            'sections' => [['id' => 'sp', 'componentKey' => 'ProductPage', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $product]]];

        $refused = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(422, $refused->getStatusCode(), $refused->getContent());
        $this->assertContains(['path' => 'tabs[3].sections', 'message' => 'page système « product » : bloc(s) obligatoire(s) manquant(s) : modeGroup'], json_decode($refused->getContent(), true)['errors']);

        $container = array_key_first(array_filter($product['blocks'], fn ($b) => $b['type'] === 'container'));
        $configuration['tabs'][3]['sections'][0]['reglableConfig']['blocks'][$container]['mode'] = 'sale';
        $accepted = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(200, $accepted->getStatusCode(), $accepted->getContent());

        // Une fiche alternative (variant) de la même page est acceptée ; deux fois la même variante, non
        $configuration['tabs'][] = ['id' => 'sys-product-rental', 'system' => 'product', 'variant' => 'rental', 'isVisible' => false, 'sections' => $configuration['tabs'][3]['sections']];
        $this->assertSame(200, $this->request('PUT', $this->encode(['configuration' => $configuration]))->getStatusCode());
        $configuration['tabs'][] = ['id' => 'sys-product-rental-2', 'system' => 'product', 'variant' => 'rental', 'isVisible' => false, 'sections' => $configuration['tabs'][3]['sections']];
        $duplicate = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertContains(['path' => 'tabs[5]', 'message' => 'page système « product » (variante « rental ») déjà définie par tabs[4]'], json_decode($duplicate->getContent(), true)['errors']);
    }

    public function testUnknownSystemPageCharterAndCommerceAreChecked(): void
    {
        $this->loginAsAdmin();
        $configuration = $this->configuration();
        $configuration['tabs'][1]['system'] = 'wishlist';
        $configuration['tabs'][2]['variant'] = 'avec des espaces';
        $configuration['charter'] = ['primaryColor' => 'bleu', 'accentColor' => null, 'headingFont' => '<script>', 'radius' => -1, 'buttonStyle' => 'ghost', 'inconnu' => 1];
        $configuration['commerce'] = ['guestCheckout' => 'oui', 'currency' => 'dollars', 'subscriptionsEnabled' => false];

        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));

        $this->assertSame(422, $put->getStatusCode(), $put->getContent());
        $errors = json_decode($put->getContent(), true)['errors'];
        $paths = array_column($errors, 'path');
        foreach (['tabs[1].system', 'tabs[2].variant', 'charter.primaryColor', 'charter.headingFont', 'charter.radius', 'charter.buttonStyle', 'commerce.guestCheckout', 'commerce.currency'] as $expected) {
            $this->assertContains($expected, $paths, implode(' | ', $paths));
        }
        $this->assertNotContains('charter.inconnu', $paths, 'clé inconnue conservée sans contrôle');
        $this->assertNotContains('charter.accentColor', $paths, 'null = non réglé');
        $this->assertStringContainsString('cart, checkout', $errors[array_search('tabs[1].system', $paths, true)]['message']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM boutique_setting WHERE configuration::text <> \'[]\''));
    }

    public function testInvalidCompositionIsRefusedWithPaths(): void
    {
        $this->loginAsAdmin();
        $configuration = $this->configuration();
        $configuration['tabs'][0]['sections'][0]['reglableConfig']['blocks'][0]['fontColor'] = '#ffffff';
        $configuration['tabs'][0]['sections'][0]['name'] = 'Atouts <b>gras</b>';

        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));

        $this->assertSame(422, $put->getStatusCode(), $put->getContent());
        $paths = array_column(json_decode($put->getContent(), true)['errors'], 'path');
        $this->assertContains('tabs[0].sections[0].reglableConfig.blocks[0].fontColor', $paths);
        $this->assertContains('tabs[0].sections[0].name', $paths);
    }

    public function testRemovedProductsBlockIsRefusedAndTheReglableCarouselIsAccepted(): void
    {
        $this->loginAsAdmin();
        // Contrat du 09/10/2026 : le bloc products (cartes codées en dur) est retiré au profit d'une liste répétée
        $configuration = $this->configuration();
        $configuration['tabs'][0]['sections'][0]['reglableConfig']['blocks'][] = ['id' => 'ancien-carrousel', 'type' => 'products', 'cardStyle' => 'A', 'productFetch' => 'typeBestsellers'];
        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(422, $put->getStatusCode(), $put->getContent());
        $this->assertStringContainsString('tabs[0].sections[0].reglableConfig.blocks', implode(' ', array_column(json_decode($put->getContent(), true)['errors'], 'path')));

        $configuration = $this->configuration();
        $configuration['tabs'][0]['sections'][] = ['id' => 's2', 'componentKey' => 'Carousel', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $this->preset('products-type-c')];
        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());
    }

    public function testReviewBlocksAreAcceptedOnProductAndAccountPages(): void
    {
        // Contrat a60eed8939573dcc (09/10/2026) : résumé, liste, formulaire des avis (fiche produit) et « Mes avis » (compte)
        $this->loginAsAdmin();
        $product = $this->preset('product-type-a');
        $account = $this->preset('account-type-a');
        $types = array_column(array_merge($product['blocks'], $account['blocks']), 'type');
        foreach (['reviewSummary', 'reviewList', 'reviewForm', 'myReviews'] as $type) {
            $this->assertContains($type, $types);
        }
        $configuration = $this->configuration();
        $configuration['tabs'][] = ['id' => 'sys-product', 'system' => 'product', 'isVisible' => false, 'title' => ['fr' => 'Produit', 'en' => 'Product'], 'sections' => [
            ['id' => 'sp', 'componentKey' => 'ProductPage', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $product],
        ]];
        $configuration['tabs'][] = ['id' => 'sys-account', 'system' => 'account', 'isVisible' => false, 'title' => ['fr' => 'Compte', 'en' => 'Account'], 'sections' => [
            ['id' => 'sa', 'componentKey' => 'AccountPage', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $account],
        ]];
        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());
    }

    public function testSevenSystemPagesCanBeShownInTheMenuAndStayVisible(): void
    {
        // Contrat du 09/10/2026 : catalogue, panier, paiement, compte, financement, connexion et inscription peuvent
        // figurer dans le menu (isVisible: true) ; le serveur garde la valeur telle quelle
        $this->loginAsAdmin();
        $configuration = $this->configuration();
        foreach ($configuration['tabs'] as $i => $tab) {
            if (in_array($tab['system'] ?? null, ['cart', 'checkout'], true)) {
                $configuration['tabs'][$i]['isVisible'] = true;
            }
        }
        $configuration['tabs'][] = ['id' => 'sys-catalogue', 'system' => 'catalogue', 'isVisible' => true, 'title' => ['fr' => 'Boutique', 'en' => 'Shop'], 'sections' => [
            ['id' => 'sg', 'componentKey' => 'Catalogue', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $this->preset('catalogue-type-a')],
        ]];
        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());
        $stored = json_decode($this->request('GET')->getContent(), true);
        $visible = array_column(array_filter($stored['tabs'], fn ($t) => isset($t['system'])), 'isVisible', 'system');
        $this->assertSame(['cart' => true, 'checkout' => true, 'catalogue' => true], $visible);
    }

    public function testEnrichedCartBadgeIsAccepted(): void
    {
        // Contrat f254aec13928f7c0 (09/10/2026) : nouveaux styles, icône, position du compteur, sous-total, rebond, aperçu
        $this->loginAsAdmin();
        $navbar = $this->preset('boutique-navbar-type-a');
        foreach ($navbar['blocks'] as $i => $block) {
            if ($block['type'] === 'cartBadge') {
                $navbar['blocks'][$i] = array_merge($block, ['badgeStyle' => 'stacked', 'cartIcon' => 'basket', 'counterPosition' => 'inline',
                    'badgeTotal' => true, 'badgeBump' => true, 'cartPreview' => true, 'label' => 'Mon panier', 'translations' => ['en' => ['label' => 'My cart']]]);
            }
        }
        $configuration = $this->configuration();
        $configuration['navbar']['reglableConfig'] = $navbar;
        $put = $this->request('PUT', $this->encode(['configuration' => $configuration]));
        $this->assertSame(200, $put->getStatusCode(), $put->getContent());

        $navbar['blocks'][array_search('cartBadge', array_column($navbar['blocks'], 'type'), true)]['cartIcon'] = 'sac';
        $configuration['navbar']['reglableConfig'] = $navbar;
        $this->assertSame(422, $this->request('PUT', $this->encode(['configuration' => $configuration]))->getStatusCode(), 'valeur hors du schéma');
    }

    public function testMalformedBodiesAreRefused(): void
    {
        $this->loginAsAdmin();
        $this->assertSame(400, $this->request('PUT', '{"configuration": ')->getStatusCode());
        $this->assertSame(422, $this->request('PUT', '{"configuration": []}')->getStatusCode(), 'objet attendu');
        $this->assertSame(422, $this->request('PUT', '{"autre": {}}')->getStatusCode());
        $this->assertSame(413, $this->request('PUT', '{"configuration": {"note": "' . str_repeat('x', 4194304 + 16384) . '"}}')->getStatusCode());
    }

    public function testRestoreBringsBackThePreviousConfigurationAndIsJournaled(): void
    {
        $this->loginAsAdmin();
        $first = $this->configuration();
        $first['commerce']['currency'] = 'CAD';
        $second = $this->configuration();
        $second['commerce']['currency'] = 'EUR';
        $this->assertSame(200, $this->request('PUT', $this->encode(['configuration' => $first]))->getStatusCode());
        $this->assertSame(200, $this->request('PUT', $this->encode(['configuration' => $second]))->getStatusCode());
        $this->assertSame('EUR', json_decode($this->request('GET')->getContent())->commerce->currency);

        $journal = json_decode($this->request('GET', null, '/api/landingpage-audit?resource=boutique-settings')->getContent(), true);
        $this->assertSame(2, $journal['total']);
        $this->assertSame(['commerce'], $journal['items'][0]['fields'], 'seule la clé modifiée est listée');
        $entry = $journal['items'][0]['id'];

        $restore = $this->request('POST', null, "/api/landingpage-audit/$entry/restore");
        $this->assertSame(200, $restore->getStatusCode(), $restore->getContent());
        $this->assertSame('restore', json_decode($restore->getContent())->entry->action);
        $this->assertSame('CAD', json_decode($this->request('GET')->getContent())->commerce->currency);

        $again = $this->request('POST', null, "/api/landingpage-audit/$entry/restore");
        $this->assertSame(409, $again->getStatusCode(), 'les réglages ont changé depuis cette écriture');
        $this->assertSame(200, $this->request('POST', null, "/api/landingpage-audit/$entry/restore?force=1")->getStatusCode());
    }

    public function testWritingRequiresAnAdministratorAndReadingIsPublic(): void
    {
        $response = $this->request('PUT', $this->encode(['configuration' => $this->configuration()]));
        $this->assertContains($response->getStatusCode(), [401, 403], $response->getContent());
        $this->assertSame(200, $this->request('GET')->getStatusCode());

        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(403, $this->request('PUT', $this->encode(['configuration' => $this->configuration()]))->getStatusCode());
    }

    /**
     * Configuration de la boutique : navbar et pied de page réglables, un onglet visible (id numérique), deux pages
     * système (panier, paiement ; id texte « sys-… ») prises dans les modèles du catalogue, charte et commerce.
     */
    private function configuration(): array
    {
        $features = $this->preset('features-type-a');
        $text = array_key_first(array_filter($features['blocks'], fn ($b) => in_array($b['type'], ['title', 'text'], true)));
        $features['blocks'][$text]['lineHeight'] = 1.0;

        return [
            'navbar' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $this->preset('navbar-type-c')],
            'footer' => ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $this->preset('footer-type-e')],
            'tabs' => [
                ['id' => 1, 'title' => ['fr' => 'Accueil', 'en' => 'Home'], 'isVisible' => true, 'sections' => [
                    ['id' => 's1', 'componentKey' => 'Features', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $features],
                ]],
                ['id' => 'sys-cart', 'system' => 'cart', 'isVisible' => false, 'title' => ['fr' => 'Panier', 'en' => 'Cart'], 'sections' => [
                    ['id' => 'sc', 'componentKey' => 'CartPage', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $this->preset('cart-type-b')],
                ]],
                ['id' => 'sys-checkout', 'system' => 'checkout', 'isVisible' => false, 'title' => ['fr' => 'Paiement', 'en' => 'Checkout'], 'sections' => [
                    ['id' => 'sk', 'componentKey' => 'Checkout', 'componentTypeKey' => 'typeReglable', 'dataType' => null, 'reglableConfig' => $this->preset('checkout-type-a')],
                ]],
            ],
            'reglablePresets' => [],
            'charter' => ['primaryColor' => '#1d4ed8', 'accentColor' => 'rgb(255, 200, 0)', 'textColor' => '#111', 'backgroundColor' => 'transparent',
                'headingFont' => 'Inter', 'bodyFont' => 'Inter', 'radius' => 8, 'buttonStyle' => 'pill'],
            'commerce' => ['guestCheckout' => true, 'currency' => 'CAD', 'subscriptionsEnabled' => false],
        ];
    }

    private function preset(string $id): array
    {
        [, $preset] = static::getContainer()->get(LandingAiCatalogue::class)->preset($id);

        return json_decode(json_encode($preset['composition']), true);
    }

    private function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function request(string $method, ?string $body = null, string $path = '/api/boutique-settings'): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
    }

    private function loginAs(array $roles): void
    {
        $email = 'boutique-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Boutique')->setLastname('Admin')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }
}
