<?php

namespace App\Tests\Functional\Storefront;

use App\Entity\User;
use App\Security\StorefrontGateSubscriber;
use App\Services\Storefront\StorefrontService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Lot CONSTRUCTION (16/11/2026, `docs/boutique-en-construction.md`) : un site « en construction » ne montre au visiteur que son nom ;
 * le serveur ferme lui-même les lectures du site et de la boutique, et, pour tous, les commandes et les formulaires ; un compte
 * d'équipe ou un lien d'aperçu voit la vraie boutique ; l'administrateur ouvre après une liste de contrôle. Le site de test est
 * refermé puis rouvert par ses propres routes, et rendu « ouvert » à la fin : les autres essais n'en voient rien.
 */
class StorefrontTest extends WebTestCase
{
    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;
    /** @var array<string, mixed> ce que l'essai change sur la fiche et le compte de paiement du site de test, rendu à la fin */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $db = $this->db();
        $this->saved = ['boutique' => $db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'), 'stripe' => $db->fetchAllAssociative('SELECT id, account_id, live_account_id FROM stripe_config')];
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        $db = $this->db();
        $db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$this->saved['boutique'] ? 'true' : 'false']);
        foreach ($this->saved['stripe'] as $row) {
            $db->executeStatement('UPDATE stripe_config SET account_id = ?, live_account_id = ? WHERE id = ?', [$row['account_id'], $row['live_account_id'], $row['id']]);
        }
        $db->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'vitrine-%@example.invalid'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        parent::tearDown();
    }

    public function testASiteUnderConstructionShowsOnlyItsNameAndTheServerClosesReadsOrdersAndForms(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $cashier = $this->user(['ROLE_USER_POS']);
        $id = (int) $this->db()->fetchOne('SELECT id FROM entreprise ORDER BY id LIMIT 1');
        $name = (string) $this->db()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1');

        // Un site ouvert : tout est servi comme avant, et l'état se lit sans connexion, avec un cache court
        $open = $this->api('GET', '/api/site/storefront');
        $this->assertSame(200, $open->getStatusCode(), (string) $open->getContent());
        $this->assertSame(['state' => 'open', 'name' => $name, 'locale' => $this->json($open)['locale'], 'openedAt' => null, 'preview' => ['active' => false, 'expiresAt' => null]], $this->json($open));
        $this->assertStringContainsString('max-age=' . StorefrontService::CACHE_SECONDS, (string) $open->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $open->headers->get('Cache-Control'));
        $this->assertSame(200, $this->api('GET', '/api/category')->getStatusCode());
        $full = $this->json($this->api('GET', "/api/entreprise/$id"));

        // Qui referme : l'administrateur du site seulement
        $this->assertSame(401, $this->api('POST', '/api/site/storefront/close')->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/site/storefront/close', null, $customer)->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/site/storefront/checklist', null, $customer)->getStatusCode());
        $this->assertSame(['state' => 'construction', 'openedAt' => null], $this->json($this->api('POST', '/api/site/storefront/close', null, $admin)));

        // Le visiteur : le nom, rien d'autre ; noindex ; les lectures du site et de la boutique sont refusées PAR LE SERVEUR
        $closed = $this->api('GET', '/api/site/storefront');
        $this->assertSame(['state' => 'construction', 'name' => $name, 'preview' => ['active' => false, 'expiresAt' => null]], array_intersect_key($this->json($closed), ['state' => 1, 'name' => 1, 'preview' => 1]));
        $this->assertStringContainsString('noindex', (string) $closed->headers->get('X-Robots-Tag'));
        foreach (['/api/products/by-category', '/api/category', '/api/products/12', '/api/homeslider', '/api/landingpage-settings', '/api/boutique-settings', '/api/presentations', '/api/subscription-plans', '/api/stripe-config', '/api/order-statuses', '/api/c%61tegory'] as $path) {
            $refused = $this->api('GET', $path);
            $this->assertSame([403, 'STOREFRONT_UNDER_CONSTRUCTION', $name], [$refused->getStatusCode(), $this->json($refused)['code'] ?? null, $this->json($refused)['name'] ?? null], $path);
        }
        $this->assertSame(403, $this->api('GET', '/api/category', null, $customer)->getStatusCode(), 'un compte client n\'en voit pas plus qu\'un visiteur');

        // Ce qui reste servi : la connexion, le mot de passe oublié, et la fiche du site réduite aux mentions légales
        $this->assertSame(200, $this->api('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin'])->getStatusCode());
        $this->assertSame(200, $this->api('POST', '/api/password-reset/request', ['email' => 'inconnu@example.invalid'])->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/currencies')->getStatusCode());
        $legal = $this->api('GET', "/api/entreprise/$id");
        $this->assertSame(200, $legal->getStatusCode());
        $reduced = $this->json($legal);
        $this->assertSame(array_keys($full), array_keys($reduced), 'la forme de la réponse ne change pas');
        $this->assertSame(array_intersect_key($full, array_flip(StorefrontService::LEGAL_FIELDS)), array_intersect_key($reduced, array_flip(StorefrontService::LEGAL_FIELDS)), 'identité légale, textes légaux et logo : inchangés');
        foreach (array_diff_key($reduced, array_flip(StorefrontService::LEGAL_FIELDS)) as $field => $value) {
            $this->assertContains($value, [null, false, []], "$field ne sert ni aux mentions légales ni à la connexion");
        }
        $this->assertSame([false, false, null, []], [$reduced['isBoutiqueActive'], $reduced['isLandingPageActive'], $reduced['apropos'], $reduced['socialNetworks']]);
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id", null, $admin)), 'un compte d\'équipe lit la fiche entière');

        // Commandes et formulaires : fermés à TOUS, administrateur compris ; la caisse, elle, vend toujours en magasin
        foreach (['/api/order/create-guest', '/api/cart/quote', '/api/stripe/create-intent', '/api/contacts', '/api/contact/submit', '/api/newsletter/subscribe', '/api/reservations', '/api/form-submissions', '/api/register', '/api/order/create', '/api/payment', '/api/subscriptions'] as $path) {
            foreach ([null, $customer, $admin] as $who) {
                $refused = $this->api('POST', $path, ['x' => 1], $who);
                // trois de ces routes exigent déjà un compte : sans compte, le pare-feu répond 401 avant la garde du site
                $expected = $who === null && in_array($path, ['/api/order/create', '/api/payment', '/api/subscriptions'], true) ? 401 : 'STOREFRONT_UNDER_CONSTRUCTION';
                $this->assertSame($expected, $this->json($refused)['code'] ?? $refused->getStatusCode(), $path . ' / ' . ($who?->getRoles()[0] ?? 'visiteur'));
            }
        }
        foreach (['/api/order/create', '/api/payment'] as $path) {
            $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', $path, ['x' => 1], $cashier))['code'] ?? null, 'la vente en magasin n\'est pas la boutique publique');
        }
        foreach (['/api/order/create-guest', '/api/subscriptions', '/api/cart/quote', '/api/contacts'] as $path) {
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', $path, ['x' => 1], $cashier))['code'] ?? null, "$path : la caisse ne passe que sur ses deux routes");
        }
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email = 'nouveau-client@example.invalid'"));

        // Un compte d'équipe voit la vraie boutique sans lien d'aperçu, et travaille : il crée ses articles
        $team = $this->api('GET', '/api/site/storefront', null, $admin);
        $this->assertSame(['active' => true, 'expiresAt' => null], $this->json($team)['preview']);
        $this->assertStringContainsString('private', (string) $team->headers->get('Cache-Control'));
        $this->assertSame(200, $this->api('GET', '/api/category', null, $admin)->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/landingpage-settings', null, $admin)->getStatusCode());
        // … y compris sur les lectures que le pare-feu de l'API ne couvre pas (formules, transporteurs, équipe) : son jeton y est lu quand même
        foreach (['/api/subscription-plans', '/api/Carrier', '/api/team'] as $path) {
            $this->assertSame(403, $this->api('GET', $path)->getStatusCode(), "$path : fermé au visiteur");
            $this->assertSame(403, $this->api('GET', $path, null, $customer)->getStatusCode(), "$path : fermé au compte client");
            $this->assertNotSame(403, $this->api('GET', $path, null, $admin)->getStatusCode(), "$path : servi à l'équipe");
            $this->assertSame(403, $this->api('GET', $path, null, null, ['HTTP_AUTHORIZATION' => 'Bearer jeton.faux.forgé'])->getStatusCode(), "$path : un jeton forgé n'ouvre rien");
        }

        // Le lien d'aperçu : 7 jours, jeton dans le fragment ; il ouvre les LECTURES, jamais une commande ni un formulaire
        $this->assertSame(401, $this->api('POST', '/api/site/storefront/preview-link')->getStatusCode());
        $link = $this->json($this->api('POST', '/api/site/storefront/preview-link', null, $admin));
        $this->assertMatchesRegularExpression('#^https://' . preg_quote(MV_TEST_TENANT_HOST, '#') . '/apercu\#token=[0-9a-f]{64}$#', $link['url']);
        $this->assertContains((new \DateTimeImmutable())->diff(new \DateTimeImmutable($link['expiresAt']))->days, [6, 7]);
        $token = substr($link['url'], -64);
        $this->assertSame(['valid' => true, 'expiresAt' => $link['expiresAt']], $this->json($this->api('POST', '/api/site/storefront/preview', ['token' => $token])));
        foreach ([str_repeat('0', 64), 'court', 42, null] as $bad) {
            $this->assertSame([400, 'PREVIEW_LINK_INVALID'], [$this->api('POST', '/api/site/storefront/preview', ['token' => $bad])->getStatusCode(), $this->json($this->client->getResponse())['code']]);
        }
        $preview = ['HTTP_X_STOREFRONT_PREVIEW' => $token];
        $this->assertSame(['active' => true, 'expiresAt' => $link['expiresAt']], $this->json($this->api('GET', '/api/site/storefront', null, null, $preview))['preview']);
        $this->assertSame(200, $this->api('GET', '/api/category', null, null, $preview)->getStatusCode());
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id", null, null, $preview)));
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => str_repeat('a', 64)])->getStatusCode());
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/contacts', ['x' => 1], null, $preview))['code']);
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/order/create-guest', ['x' => 1], null, $preview))['code']);
        // la base ne garde que l'empreinte du jeton ; le journal du site dit le geste, jamais le jeton
        $stored = $this->db()->fetchAssociative('SELECT storefront_preview_hash, storefront_state FROM entreprise ORDER BY id LIMIT 1');
        $this->assertSame([hash('sha256', $token), 'construction'], [$stored['storefront_preview_hash'], $stored['storefront_state']]);
        $journal = $this->db()->fetchAllAssociative("SELECT action, user_identifier, COALESCE(before_state, '') || COALESCE(after_state, '') || fields AS texte FROM content_audit_log WHERE resource = 'storefront' ORDER BY id");
        $this->assertSame([['close', $admin->getEmail()], ['preview_link', $admin->getEmail()]], array_map(static fn (array $l): array => [$l['action'], $l['user_identifier']], $journal));
        $this->assertStringNotContainsString($token, implode(' ', array_column($journal, 'texte')));
        // un nouveau lien remplace l'ancien
        $second = substr($this->json($this->api('POST', '/api/site/storefront/preview-link', null, $admin))['url'], -64);
        $this->assertNotSame($token, $second);
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, $preview)->getStatusCode(), 'l\'ancien lien ne vaut plus');
        $this->assertSame(200, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => $second])->getStatusCode());
        // un lien expiré ne vaut plus
        $this->db()->executeStatement("UPDATE entreprise SET storefront_preview_expires_at = NOW() - INTERVAL '1 minute'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => $second])->getStatusCode());

        // La liste de contrôle avertit sans bloquer, sauf sans aucun moyen de paiement quand la boutique en ligne fait partie du site
        $this->db()->executeStatement('UPDATE entreprise SET is_boutique_active = true');
        $this->db()->executeStatement('UPDATE stripe_config SET account_id = NULL, live_account_id = NULL');
        $checklist = $this->json($this->api('GET', '/api/site/storefront/checklist', null, $admin));
        $items = array_column($checklist['items'], null, 'key');
        $this->assertSame([['construction', false], ['products', 'payment', 'terms'], [false, true, true]], [[$checklist['state'], $checklist['canOpen']], array_keys($items), [$items['payment']['ok'], $items['payment']['blocking'], !$items['products']['blocking'] && !$items['terms']['blocking']]]);
        $notReady = $this->api('POST', '/api/site/storefront/open', null, $admin);
        $this->assertSame([409, 'STOREFRONT_NOT_READY'], [$notReady->getStatusCode(), $this->json($notReady)['code']]);
        $this->assertSame(['products', 'payment', 'terms'], array_column($this->json($notReady)['items'], 'key'));
        $this->assertSame('construction', $this->json($this->api('GET', '/api/site/storefront'))['state']);
        // un site sans boutique en ligne (site vitrine, Mémoires Vivantes) n'a pas besoin d'un moyen de paiement pour ouvrir
        $this->db()->executeStatement('UPDATE entreprise SET is_boutique_active = false');
        $this->assertTrue($this->json($this->api('GET', '/api/site/storefront/checklist', null, $admin))['canOpen']);
        $this->db()->executeStatement('UPDATE entreprise SET is_boutique_active = true');
        // avec un moyen de paiement, la boutique ouvre même si des points de la liste avertissent encore
        if ((int) $this->db()->fetchOne('SELECT COUNT(*) FROM stripe_config') === 0) {
            $this->markTestIncomplete('le modèle des tests n\'a pas de ligne stripe_config');
        }
        $this->db()->executeStatement("UPDATE stripe_config SET account_id = 'acct_essai_construction'");
        $opened = $this->json($this->api('POST', '/api/site/storefront/open', null, $admin));
        $this->assertSame('open', $opened['state']);
        $this->assertNotNull($opened['openedAt']);
        $this->assertSame($opened, $this->json($this->api('POST', '/api/site/storefront/open', null, $admin)), 'déjà ouvert : rien ne change');
        // Ouvert : tout est servi au visiteur, aussitôt (le cache de l'état est vidé par le geste), et le lien d'aperçu est retiré
        $after = $this->json($this->api('GET', '/api/site/storefront'));
        $this->assertSame(['open', $opened['openedAt'], false], [$after['state'], $after['openedAt'], $after['preview']['active']]);
        $this->assertSame(200, $this->api('GET', '/api/category')->getStatusCode());
        $this->assertFalse($this->json($this->api('GET', '/api/site/storefront', null, $admin))['preview']['active'], 'sur un site ouvert, il n\'y a plus d\'aperçu');
        $this->assertSame(array_replace($full, ['isBoutiqueActive' => true]), $this->json($this->api('GET', "/api/entreprise/$id")), 'la fiche entière (l\'essai a ouvert le module Boutique)');
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/contacts', ['x' => 1]))['code'] ?? null);
        $this->assertNull($this->db()->fetchOne('SELECT storefront_preview_hash FROM entreprise ORDER BY id LIMIT 1'));
        $this->assertSame(['close', 'preview_link', 'preview_link', 'open'], $this->db()->fetchFirstColumn("SELECT action FROM content_audit_log WHERE resource = 'storefront' ORDER BY id"));
    }

    public function testEveryPublicReadOfTheSecurityRulesIsFiledAsClosedOrKeptUnderConstruction(): void
    {
        $rules = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/security.yaml')['security']['access_control'];
        $public = null;
        foreach ($rules as $rule) {
            if (($rule['roles'] ?? null) === 'PUBLIC_ACCESS' && str_starts_with((string) $rule['path'], '^/api/(login|health|')) {
                $public = (string) $rule['path'];
            }
        }
        $this->assertNotNull($public, 'la règle des lectures publiques de security.yaml a changé de forme : relire cet essai');
        $this->assertSame(1, preg_match('#^\^/api/\((.+)\)$#', $public, $m));
        $unfiled = [];
        foreach (explode('|', $m[1]) as $route) {
            $path = '/api/' . $route;
            $closed = preg_match(StorefrontGateSubscriber::CONTENT, $path) === 1 || preg_match(StorefrontGateSubscriber::ORDERS, $path) === 1;
            $kept = in_array($route, StorefrontGateSubscriber::KEPT, true);
            if ($closed === $kept) {
                $unfiled[] = $route;
            }
        }
        $this->assertSame([], $unfiled, 'route publique à ranger : fermée en construction (CONTENT ou ORDERS de StorefrontGateSubscriber) ou gardée ouverte (KEPT), jamais les deux');
        // les routes gardées ouvertes ne sont que celles de la connexion, de la découverte du site et des mentions légales
        $this->assertSame(['login', 'health', 'currencies', 'login-web', 'token/refresh', 'password-reset/request', 'password-reset/confirm', 'password-reset/verify', 'password-reset/form', 'entreprise', 'otp-verify', 'tenant/check', 'tenant/hosts'], StorefrontGateSubscriber::KEPT);
        // les écritures de la boutique publique sont toutes dans ORDERS
        foreach (['order/create', 'order/create-guest', 'payment', 'cart/quote', 'stripe/create-intent', 'contacts', 'contacts/create', 'contact/submit', 'newsletter/subscribe', 'form-submissions', 'reservations', 'candidatures', 'financement/submit', 'register', 'subscriptions', 'subscriptions/12/change-plan', 'subscriptions/12/resume'] as $route) {
            $this->assertSame(1, preg_match(StorefrontGateSubscriber::ORDERS, '/api/' . $route), $route);
        }
        // … et rien de l'équipe n'y est : ni la vente en caisse, ni la gestion d'une demande ou d'un article
        foreach (['order/create-multi-payment', 'order/cancel/3', 'contacts/3', 'reservations/3/confirm', 'subscriptions/3/cancel', 'products', 'pos/customers'] as $route) {
            $this->assertSame(0, preg_match(StorefrontGateSubscriber::ORDERS, '/api/' . $route), $route);
        }
    }

    private function reset(): void
    {
        $db = $this->db();
        $db->executeStatement("UPDATE entreprise SET storefront_state = 'open', storefront_opened_at = NULL, storefront_preview_hash = NULL, storefront_preview_expires_at = NULL");
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'storefront'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
    }

    private function user(array $roles): User
    {
        $email = 'vitrine-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Vitrine')->setLastname('Essai')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @param array<string, string> $headers */
    private function api(string $method, string $path, ?array $body = null, ?User $as = null, array $headers = []): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.88.' . random_int(1, 254) . '.' . random_int(1, 254)] + $headers;
        if ($as !== null) {
            $this->em();
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
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
