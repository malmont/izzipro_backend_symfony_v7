<?php

namespace App\Tests\Functional\Storefront;

use App\Entity\User;
use App\Security\StorefrontGateSubscriber;
use App\Services\Platform\EntitlementService;
use App\Services\Storefront\StorefrontService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot CONSTRUCTION (16/11/2026, `docs/boutique-en-construction.md`) : la construction ne vaut que si le module Boutique est actif.
 * Un site neuf né avec sa boutique ne montre au visiteur que son nom : le serveur ferme lui-même la boutique et le site vitrine
 * (étendue « site ») ; sur un site déjà en service, la boutique seule est fermée (étendue « shop »). Mémoires Vivantes, Living
 * Memory et Boussole ne sont jamais fermées. Les commandes sont fermées à tous ; un compte d'équipe ou un lien d'aperçu voit la
 * vraie boutique ; l'administrateur ouvre après une liste de contrôle. Le site de test est rendu « ouvert » à la fin, avec ses
 * modules et ses droits : les autres essais n'en voient rien.
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
        $this->saved = ['flags' => $db->fetchAssociative('SELECT ' . implode(', ', array_values(\App\Services\FeatureAccess\FeatureCatalog::GOVERNED)) . ' FROM entreprise ORDER BY id LIMIT 1') ?: [],
            'stripe' => $db->fetchAllAssociative('SELECT id, account_id, live_account_id, is_active FROM stripe_config')];
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        $db = $this->db();
        foreach ($this->saved['flags'] as $column => $value) {
            $db->executeStatement("UPDATE entreprise SET $column = ?", [in_array($value, [true, 't', 'true', 1, '1'], true) ? 'true' : 'false']);
        }
        foreach ($this->saved['stripe'] as $row) {
            $db->executeStatement('UPDATE stripe_config SET account_id = ?, live_account_id = ?, is_active = ? WHERE id = ?', [$row['account_id'], $row['live_account_id'], $row['is_active'] ? 'true' : 'false', $row['id']]);
        }
        $db->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'vitrine-%@example.invalid'");
        $db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'vitrine-%@example.invalid'");
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        parent::tearDown();
    }

    public function testANewSiteWithAShopShowsOnlyItsNameAndTheServerClosesTheShopAndTheShowcaseButNotTheOtherApplications(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $cashier = $this->user(['ROLE_USER_POS']);
        $id = (int) $this->db()->fetchOne('SELECT id FROM entreprise ORDER BY id LIMIT 1');
        $name = (string) $this->db()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1');
        $code = fn (Response $r): mixed => $this->json($r)['code'] ?? $r->getStatusCode();

        // Une boutique ouverte : tout est servi comme avant, et l'état se lit sans connexion, avec un cache court
        $this->state('open', true);
        $open = $this->api('GET', '/api/site/storefront');
        $this->assertSame(200, $open->getStatusCode(), (string) $open->getContent());
        $this->assertSame(['state' => 'open', 'scope' => null, 'name' => $name, 'preview' => ['active' => false, 'expiresAt' => null]], array_intersect_key($this->json($open), ['state' => 1, 'scope' => 1, 'name' => 1, 'preview' => 1]));
        $this->assertSame([true, (string) StorefrontService::CACHE_SECONDS, false], [$open->headers->hasCacheControlDirective('public'), (string) $open->headers->getCacheControlDirective('max-age'), $open->headers->hasCacheControlDirective('private')], (string) $open->headers->get('Cache-Control'));
        $this->assertSame([], $open->headers->getCookies(), 'une réponse publique ne pose aucun cookie');
        $this->assertSame(200, $this->api('GET', '/api/category')->getStatusCode());
        $full = $this->json($this->api('GET', "/api/entreprise/$id"));
        // un jeton expiré ou forgé ne coûte pas l'état au visiteur : la route est lue hors du pare-feu de l'API
        $this->assertSame(200, $this->api('GET', '/api/site/storefront', null, null, ['HTTP_AUTHORIZATION' => 'Bearer jeton.faux.forgé'])->getStatusCode());

        // UN SITE NEUF, né avec sa boutique : la boutique ET le site vitrine sont fermés (étendue « site »)
        $this->state('construction', true);
        $closed = $this->api('GET', '/api/site/storefront');
        $this->assertSame(['state' => 'construction', 'scope' => 'site', 'name' => $name, 'openedAt' => null, 'preview' => ['active' => false, 'expiresAt' => null]], array_intersect_key($this->json($closed), ['state' => 1, 'scope' => 1, 'name' => 1, 'openedAt' => 1, 'preview' => 1]));
        $this->assertStringContainsString('noindex', (string) $closed->headers->get('X-Robots-Tag'));
        // la boutique, par toutes ses adresses, et le site vitrine : refusés PAR LE SERVEUR au visiteur et au compte client
        foreach (['/api/category', '/api/products/by-category', '/api/products/12', '/api/productsid/12', '/api/c%61tegory', '/api/subscription-plans', '/api/boutique-settings', '/api/order-statuses', '/api/orders/12', '/api/apps/boutique/terms',
            '/api/landingpage-settings', '/api/presentations', '/api/homeslider', '/api/vehicles.json', '/api/offers', '/api/admin-settings', '/api/team'] as $path) {
            $refused = $this->api('GET', $path);
            $this->assertSame([403, 'STOREFRONT_UNDER_CONSTRUCTION', $name, 'site'], [$refused->getStatusCode(), $this->json($refused)['code'] ?? null, $this->json($refused)['name'] ?? null, $this->json($refused)['scope'] ?? null], $path);
            $this->assertSame(403, $this->api('GET', $path, null, $customer)->getStatusCode(), "$path : compte client");
        }
        $this->assertSame(403, $this->api('HEAD', '/api/category')->getStatusCode());
        foreach (['/shop', '/product/un-article', '/videos', '/shared-media', '/cart', '/cgu/conditions-generales-utilisation'] as $path) {
            $page = $this->api('GET', $path);
            $this->assertSame(403, $page->getStatusCode(), $path);
            $this->assertStringContainsString('Bientôt ouvert', (string) $page->getContent(), $path);
            $this->assertStringContainsString(htmlspecialchars($name, ENT_QUOTES), (string) $page->getContent());
        }
        $refused = $this->api('GET', '/api/category');
        $this->assertSame('private, no-store', (string) preg_replace('/^no-store, private$/', 'private, no-store', (string) $refused->headers->get('Cache-Control')), 'un refus ne se garde dans aucun cache');

        // Ce qui reste servi à tous : la connexion, le mot de passe, la découverte du site, sa fiche réduite aux mentions légales
        $this->assertSame(200, $this->api('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin'])->getStatusCode());
        $this->assertSame(200, $this->api('POST', '/api/password-reset/request', ['email' => 'inconnu@example.invalid'])->getStatusCode());
        foreach (['/api/currencies', '/api/health', '/api/tenant/hosts', '/'] as $path) {
            $this->assertSame(200, $this->api('GET', $path)->getStatusCode(), $path);
        }
        $reduced = $this->json($this->api('GET', "/api/entreprise/$id"));
        $this->assertSame(array_keys($full), array_keys($reduced), 'la forme de la réponse ne change pas');
        $this->assertSame(array_intersect_key($full, array_flip(StorefrontService::LEGAL_FIELDS)), array_intersect_key($reduced, array_flip(StorefrontService::LEGAL_FIELDS)), 'identité légale, textes légaux, logo, indicateurs des applications : inchangés');
        foreach (array_diff_key($reduced, array_flip(StorefrontService::LEGAL_FIELDS)) as $field => $value) {
            $this->assertContains($value, [null, false, []], "$field ne sert ni aux mentions légales ni à la connexion");
        }
        $this->assertSame([false, false, null, []], [$reduced['isBoutiqueActive'], $reduced['isLandingPageActive'], $reduced['apropos'], $reduced['socialNetworks']]);
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id", null, $admin)), 'un compte d\'équipe lit la fiche entière');
        $this->assertSame($reduced, $this->json($this->api('GET', "/api/entreprise/$id", null, $customer)), 'un compte client lit la fiche réduite, comme un visiteur');
        $profile = $this->json($this->api('GET', '/api/app-profile/mobile'));
        $this->assertSame([null, null, null], [$profile['apropos'] ?? null, $profile['tagline'] ?? null, $profile['website'] ?? null], 'le profil de l\'application de la boutique ne dit pas ce que la fiche réduite cache');

        // MÉMOIRES VIVANTES, LIVING MEMORY ET BOUSSOLE ne dépendent pas de la boutique : leurs routes, et les routes de compte qu'elles
        // partagent, répondent comme sur un site ouvert, pour le visiteur comme pour le compte client
        $answers = fn (?User $as): array => array_map(fn (array $c): int => $this->api($c[0], $c[1], $c[2] ?? null, $as)->getStatusCode(), [
            ['GET', '/api/memoires/books'], ['GET', '/api/memoires/mobile/pricing'], ['GET', '/api/boussole/referentials'], ['POST', '/api/boussole/auth/login', ['email' => 'x@example.invalid', 'password' => 'x']],
            ['GET', '/api/apps/livingmemory/terms'], ['GET', '/api/profile'], ['GET', '/api/account/memberships'], ['GET', '/api/validate-token'], ['GET', '/api/stripe-config'], ['GET', '/api/app-profile/livingmemory'],
        ]);
        $underConstruction = [$answers(null), $answers($customer)];
        $this->state('open', true);
        $this->assertSame([$answers(null), $answers($customer)], $underConstruction, 'mêmes réponses qu\'un site ouvert');
        $this->state('construction', true);
        $this->assertNotContains(403, $underConstruction[1], 'rien n\'est refusé au compte client sur ces routes');
        // s'inscrire ou adhérer : fermé pour la boutique, servi pour une autre application
        foreach ([[], ['app' => 'mobile'], ['app' => 'web'], ['app' => 'inconnue']] as $body) {
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/register', $body + ['email' => "vitrine-b-{$id}@example.invalid", 'password' => self::PASSWORD])), json_encode($body));
        }
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/apps/join', ['app' => 'boutique'], $customer)));
        $signup = $this->api('POST', '/api/register', ['app' => 'livingmemory', 'email' => 'vitrine-lm-' . bin2hex(random_bytes(3)) . '@example.invalid', 'password' => self::PASSWORD, 'firstname' => 'Léa', 'lastname' => 'Mémoire', 'termsVersion' => '1']);
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($signup), 'l\'inscription par Living Memory n\'est pas celle de la boutique');
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/apps/join', ['app' => 'livingmemory'], $customer)));
        // … et un compte né par ces applications N'OUVRE PAS la boutique : il n'en voit rien et n'y commande pas
        $this->assertSame(403, $this->api('GET', '/api/category', null, $customer)->getStatusCode());
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/order/create', ['x' => 1], $customer)));

        // Commandes de la boutique ET formulaires du site vitrine : fermés à TOUS, équipe comprise, chemin encodé compris
        foreach (['/api/order/create-guest', '/api/cart/quote', '/api/stripe/create-intent', '/api/order/create', '/api/payment', '/api/subscriptions', '/api/import-invitation/accept',
            '/api/contacts', '/api/contact/submit', '/api/newsletter/subscribe', '/api/reservations', '/api/form-submissions', '/api/cont%61cts'] as $path) {
            foreach ([null, $customer, $admin, $cashier] as $who) {
                $refused = $this->api('POST', $path, ['x' => 1], $who);
                // trois de ces routes exigent déjà un compte : sans compte, le pare-feu répond 401 avant la garde
                $expected = $who === null && in_array($path, ['/api/order/create', '/api/payment', '/api/subscriptions'], true) ? 401 : 'STOREFRONT_UNDER_CONSTRUCTION';
                $this->assertSame($expected, $code($refused), $path . ' / ' . ($who?->getRoles()[0] ?? 'visiteur'));
            }
        }
        // la vente en magasin a sa propre route, que la construction ne ferme pas
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/order/create-multi-payment', ['x' => 1], $cashier)));
        // les pages de l'ancienne boutique, qui écrivent aussi par une simple lecture : fermées à tous, quelle que soit la méthode
        foreach (['/register', '/contact/', '/cart/add/1'] as $path) {
            $this->assertSame(403, $this->api('POST', $path, ['x' => 1])->getStatusCode(), $path);
        }
        $this->assertSame(403, $this->api('GET', '/cart/add/1')->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/stripe-payment-succes/abc')->getStatusCode());

        // L'équipe voit la vraie boutique sans lien d'aperçu, y compris sur les lectures que le pare-feu de l'API ne couvre pas
        $team = $this->api('GET', '/api/site/storefront', null, $admin);
        $this->assertSame(['active' => true, 'expiresAt' => null], $this->json($team)['preview']);
        $this->assertStringContainsString('private', (string) $team->headers->get('Cache-Control'));
        foreach ([$admin, $cashier, $this->user(['ROLE_SUPPORT'])] as $member) {
            $this->assertSame(200, $this->api('GET', '/api/category', null, $member)->getStatusCode(), $member->getRoles()[0]);
        }
        foreach (['/api/subscription-plans', '/api/Carrier', '/api/team'] as $path) {
            $this->assertSame(403, $this->api('GET', $path)->getStatusCode(), "$path : fermé au visiteur");
            $this->assertNotSame(403, $this->api('GET', $path, null, $admin)->getStatusCode(), "$path : servi à l'équipe");
            $this->assertSame(403, $this->api('GET', $path, null, null, ['HTTP_AUTHORIZATION' => 'Bearer jeton.faux.forgé'])->getStatusCode(), "$path : un jeton forgé n'ouvre rien");
        }
        $this->assertStringContainsString('no-store', (string) $this->api('GET', '/api/category', null, $admin)->headers->get('Cache-Control'), 'une lecture d\'équipe ne se garde dans aucun cache partagé');

        // Le lien d'aperçu : 7 jours, jeton dans le fragment ; il ouvre les LECTURES, jamais une écriture
        $this->assertSame(401, $this->api('POST', '/api/site/storefront/preview-link')->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/site/storefront/preview-link', null, $customer)->getStatusCode());
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
        $seen = $this->api('GET', '/api/category', null, null, $preview);
        $this->assertSame(200, $seen->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $seen->headers->get('Cache-Control'), 'une lecture d\'aperçu ne se garde dans aucun cache partagé');
        $this->assertStringNotContainsString('public', (string) $seen->headers->get('Cache-Control'));
        $this->assertContains(StorefrontService::PREVIEW_HEADER, $seen->getVary());
        $this->assertSame(200, $this->api('GET', '/api/landingpage-settings', null, null, $preview)->getStatusCode());
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id", null, null, $preview)));
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => str_repeat('a', 64)])->getStatusCode());
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/contacts', ['x' => 1], null, $preview)));
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/order/create-guest', ['x' => 1], null, $preview)));
        $this->assertSame(403, $this->api('PUT', '/api/adresses/1', ['x' => 1], $customer, $preview)->getStatusCode(), 'l\'aperçu n\'ouvre aucune écriture, même hors des commandes');
        // la base ne garde que l'empreinte du jeton ; le journal du site dit le geste, jamais le jeton
        $this->assertSame(hash('sha256', $token), $this->db()->fetchOne('SELECT storefront_preview_hash FROM entreprise ORDER BY id LIMIT 1'));
        $journal = $this->db()->fetchAllAssociative("SELECT action, user_identifier, COALESCE(before_state, '') || COALESCE(after_state, '') || fields AS texte FROM content_audit_log WHERE resource = 'storefront' ORDER BY id");
        $this->assertSame([['preview_link', $admin->getEmail()]], array_map(static fn (array $l): array => [$l['action'], $l['user_identifier']], $journal));
        $this->assertStringNotContainsString($token, implode(' ', array_column($journal, 'texte')));
        // un nouveau lien remplace l'ancien ; un lien expiré ne vaut plus
        $second = substr($this->json($this->api('POST', '/api/site/storefront/preview-link', null, $admin))['url'], -64);
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, $preview)->getStatusCode(), 'l\'ancien lien ne vaut plus');
        $this->assertSame(200, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => $second])->getStatusCode());
        $this->db()->executeStatement("UPDATE entreprise SET storefront_preview_expires_at = NOW() - INTERVAL '1 minute'");
        $this->flush();
        $this->assertSame(403, $this->api('GET', '/api/category', null, null, ['HTTP_X_STOREFRONT_PREVIEW' => $second])->getStatusCode());

        // Ouvrir : par l'administrateur seulement ; la liste de contrôle avertit sans bloquer, sauf sans moyen de paiement ACTIF
        $this->assertSame(401, $this->api('POST', '/api/site/storefront/open')->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/site/storefront/open', null, $customer)->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/site/storefront/checklist', null, $customer)->getStatusCode());
        $this->db()->executeStatement('UPDATE stripe_config SET account_id = NULL, live_account_id = NULL');
        $checklist = $this->json($this->api('GET', '/api/site/storefront/checklist', null, $admin));
        $items = array_column($checklist['items'], null, 'key');
        $this->assertSame([['construction', 'site', true, false], ['products', 'payment', 'terms'], [false, true, true]], [[$checklist['state'], $checklist['scope'], $checklist['shop'], $checklist['canOpen']], array_keys($items), [$items['payment']['ok'], $items['payment']['blocking'], !$items['products']['blocking'] && !$items['terms']['blocking']]]);
        $notReady = $this->api('POST', '/api/site/storefront/open', null, $admin);
        $this->assertSame([409, 'STOREFRONT_NOT_READY', ['products', 'payment', 'terms']], [$notReady->getStatusCode(), $this->json($notReady)['code'], array_column($this->json($notReady)['items'], 'key')]);
        // un compte de paiement connecté mais pas encore actif (inscription abandonnée) ne suffit pas
        $this->assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM stripe_config'), 'le modèle des tests porte une ligne de configuration de paiement');
        $this->db()->executeStatement("UPDATE stripe_config SET account_id = 'acct_essai_construction', is_active = false, live_account_id = NULL");
        $this->assertSame(409, $this->api('POST', '/api/site/storefront/open', null, $admin)->getStatusCode());
        $this->assertSame('construction', $this->json($this->api('GET', '/api/site/storefront'))['state']);
        $this->db()->executeStatement('UPDATE stripe_config SET is_active = true');
        $opened = $this->json($this->api('POST', '/api/site/storefront/open', null, $admin));
        $this->assertSame(['open', null], [$opened['state'], $opened['scope']]);
        $this->assertEqualsWithDelta(time(), (new \DateTimeImmutable($opened['openedAt']))->getTimestamp(), 120, 'l\'heure d\'ouverture est écrite en UTC');
        $this->assertSame($opened, $this->json($this->api('POST', '/api/site/storefront/open', null, $admin)), 'déjà ouverte : rien ne change');
        // Ouverte : tout est servi au visiteur, aussitôt (le cache de l'état est vidé par le geste) ; le lien d'aperçu est retiré
        $after = $this->json($this->api('GET', '/api/site/storefront'));
        $this->assertSame(['open', null, $opened['openedAt'], false], [$after['state'], $after['scope'], $after['openedAt'], $after['preview']['active']]);
        $this->assertSame(200, $this->api('GET', '/api/category')->getStatusCode());
        $this->assertFalse($this->json($this->api('GET', '/api/site/storefront', null, $admin))['preview']['active'], 'sur une boutique ouverte, il n\'y a plus d\'aperçu');
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id")));
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/contacts', ['x' => 1])));
        $this->assertNull($this->db()->fetchOne('SELECT storefront_preview_hash FROM entreprise ORDER BY id LIMIT 1'));
        $this->assertSame([409, 'STOREFRONT_ALREADY_OPEN'], [$this->api('POST', '/api/site/storefront/preview-link', null, $admin)->getStatusCode(), $this->json($this->client->getResponse())['code']]);
        $this->assertSame(['preview_link', 'preview_link', 'open'], $this->db()->fetchFirstColumn("SELECT action FROM content_audit_log WHERE resource = 'storefront' ORDER BY id"));
    }

    public function testOnASiteInServiceOnlyTheShopIsUnderConstructionAndNothingIsClosedWithoutTheShopModule(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $code = fn (Response $r): mixed => $this->json($r)['code'] ?? $r->getStatusCode();
        $state = fn (): array => array_intersect_key($this->json($this->api('GET', '/api/site/storefront')), ['state' => 1, 'scope' => 1]);
        $stored = fn (): string => (string) $this->db()->fetchOne('SELECT storefront_state FROM entreprise ORDER BY id LIMIT 1');

        // SANS le module Boutique, un site n'est JAMAIS bloqué, quel que soit l'état gardé : il rend « open », il n'y a rien à ouvrir
        foreach (['construction', 'boutique', 'open'] as $kept) {
            $this->state($kept, false);
            $this->assertSame(['state' => 'open', 'scope' => null], $state(), $kept);
            $this->assertSame(200, $this->api('GET', '/api/landingpage-settings')->getStatusCode(), $kept);
            $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('GET', '/api/category')), $kept);
            $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/contacts', ['x' => 1])), $kept);
        }
        $checklist = $this->json($this->api('GET', '/api/site/storefront/checklist', null, $admin));
        $this->assertSame(['open', null, false, false], [$checklist['state'], $checklist['scope'], $checklist['shop'], $checklist['canOpen']]);
        $this->assertSame(['open', null], [$this->json($this->api('POST', '/api/site/storefront/open', null, $admin))['state'], $this->json($this->client->getResponse())['scope']]);
        $this->assertSame('open', $stored(), 'rien à ouvrir : rien n\'est écrit');
        $this->assertSame(409, $this->api('POST', '/api/site/storefront/preview-link', null, $admin)->getStatusCode());

        // Le module Boutique est activé PLUS TARD sur ce site déjà en service (un droit accordé) : la boutique naît « en construction »,
        // et elle seule (étendue « shop ») ; rien d'autre du site ne se ferme
        $this->state('open', false, false);
        static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'essai', 'boutique ajoutée', null, null, false);
        $this->flush();
        $this->assertSame(['boutique', true], [$stored(), (bool) $this->db()->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1')]);
        $this->assertSame(['state' => 'construction', 'scope' => 'shop'], $state());
        // la boutique est fermée au visiteur et au compte client…
        foreach (['/api/category', '/api/products/by-category', '/api/boutique-settings', '/api/subscription-plans', '/api/productsid/12'] as $path) {
            $refused = $this->api('GET', $path);
            $this->assertSame([403, 'STOREFRONT_UNDER_CONSTRUCTION', 'shop'], [$refused->getStatusCode(), $this->json($refused)['code'] ?? null, $this->json($refused)['scope'] ?? null], $path);
            $this->assertSame(403, $this->api('GET', $path, null, $customer)->getStatusCode(), $path);
        }
        foreach (['/api/order/create-guest', '/api/cart/quote', '/api/stripe/create-intent'] as $path) {
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', $path, ['x' => 1])), $path);
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', $path, ['x' => 1], $admin)), "$path : équipe");
        }
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', '/api/register', ['email' => 'vitrine-x@example.invalid', 'password' => self::PASSWORD])));
        $this->assertSame(403, $this->api('GET', '/shop')->getStatusCode());
        // … le site vitrine, ses formulaires et la fiche du site restent servis comme avant
        foreach (['/api/landingpage-settings', '/api/presentations', '/api/homeslider', '/api/bannieres'] as $path) {
            $served = $this->api('GET', $path);
            $this->assertSame(200, $served->getStatusCode(), $path);
            $this->assertStringNotContainsString('no-store', (string) $served->headers->get('Cache-Control'), "$path : le site vitrine garde son cache");
        }
        foreach (['/api/contacts', '/api/contact/submit', '/api/newsletter/subscribe', '/api/reservations', '/api/form-submissions'] as $path) {
            $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $code($this->api('POST', $path, ['x' => 1])), $path);
        }
        $id = (int) $this->db()->fetchOne('SELECT id FROM entreprise ORDER BY id LIMIT 1');
        $sheet = $this->json($this->api('GET', "/api/entreprise/$id"));
        $this->assertTrue($sheet['isBoutiqueActive'], 'la fiche du site n\'est pas réduite : le site est en service');
        $this->assertSame($sheet, $this->json($this->api('GET', "/api/entreprise/$id", null, $admin)));
        // l'équipe voit la boutique ; l'administrateur l'ouvre (avec un moyen de paiement), la referme : elle seule se referme
        $this->assertSame(200, $this->api('GET', '/api/category', null, $admin)->getStatusCode());
        $this->db()->executeStatement("UPDATE stripe_config SET account_id = 'acct_essai_construction', is_active = true");
        $this->assertSame('open', $this->json($this->api('POST', '/api/site/storefront/open', null, $admin))['state']);
        $this->assertSame(['state' => 'open', 'scope' => null], $state());
        $this->assertSame(['state' => 'construction', 'scope' => 'shop', 'openedAt' => null], $this->json($this->api('POST', '/api/site/storefront/close', null, $admin)));
        $this->assertSame(['boutique', null], [$stored(), $this->db()->fetchOne('SELECT storefront_preview_hash FROM entreprise ORDER BY id LIMIT 1')]);
        $this->assertSame(200, $this->api('GET', '/api/landingpage-settings')->getStatusCode(), 'refermer la boutique ne ferme pas le site vitrine');
        $this->assertSame(401, $this->api('POST', '/api/site/storefront/close')->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/site/storefront/close', null, $customer)->getStatusCode());

        // Une boutique DÉJÀ OUVERTE une fois (dont celle de tout site d'avant ce lot) : son module éteint puis rallumé ne la referme pas
        $this->api('POST', '/api/site/storefront/open', null, $admin);
        $rights = static::getContainer()->get(EntitlementService::class);
        $right = (int) $this->master()->query("SELECT id FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key = 'module.boutique' AND status = 'active'")->fetchColumn();
        $rights->cancel($right, 'essai');
        $this->flush();
        $this->assertFalse((bool) $this->db()->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'));
        $rights->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'essai', 'boutique rallumée', null, null, false);
        $this->flush();
        $this->assertSame(['open', ['state' => 'open', 'scope' => null]], [$stored(), $state()]);

        // Un site NEUF encore fermé en entier, dont la boutique est éteinte : il est servi sans boutique, donc « en service » ; si la
        // boutique lui revient, elle seule est en construction : un site vitrine déjà servi ne se referme pas
        $right = fn (): int => (int) $this->master()->query("SELECT id FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key = 'module.boutique' AND status = 'active'")->fetchColumn();
        $this->state('construction', true);
        $this->assertSame(['state' => 'construction', 'scope' => 'site'], $state());
        $rights->cancel($right(), 'essai');
        $this->flush();
        $this->assertSame(['open', ['state' => 'open', 'scope' => null]], [$stored(), $state()]);
        $this->assertSame(200, $this->api('GET', '/api/landingpage-settings')->getStatusCode());
        $rights->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'essai', 'boutique revenue', null, null, false);
        $this->flush();
        $this->assertSame(['boutique', ['state' => 'construction', 'scope' => 'shop']], [$stored(), $state()]);
        // … un droit de plus sur un site neuf fermé en entier ne change rien à son état (les modules d'un site neuf sont posés un par un)
        $this->state('construction', true);
        $rights->cancel($right(), 'essai');
        $this->state('construction', true);
        $rights->grant(MV_TEST_TENANT_CODE, 'module.boutique', 'manual', null, 'essai', 'déjà actif', null, null, false);
        $this->flush();
        $this->assertSame(['construction', ['state' => 'construction', 'scope' => 'site']], [$stored(), $state()]);

        // La fin de la fabrication d'un site neuf pose son état de naissance, quoi que les passages de modules aient écrit avant
        $storefront = static::getContainer()->get(StorefrontService::class);
        $this->db()->executeStatement("UPDATE entreprise SET storefront_state = 'boutique', is_boutique_active = true, storefront_opened_at = NOW(), storefront_preview_hash = 'x', storefront_preview_expires_at = NOW()");
        $this->assertTrue($storefront->born(MV_TEST_TENANT_CODE));
        $this->assertSame(['storefront_state' => 'construction', 'storefront_opened_at' => null, 'storefront_preview_hash' => null, 'storefront_preview_expires_at' => null],
            $this->db()->fetchAssociative('SELECT storefront_state, storefront_opened_at, storefront_preview_hash, storefront_preview_expires_at FROM entreprise ORDER BY id LIMIT 1'));
        $this->assertSame(['state' => 'construction', 'scope' => 'site'], $state(), 'le cache de l\'état est vidé par le geste');
        $this->db()->executeStatement("UPDATE entreprise SET storefront_state = 'boutique', is_boutique_active = false");
        $this->assertTrue($storefront->born(MV_TEST_TENANT_CODE));
        $this->assertSame('open', $stored(), 'né sans boutique : le site est en service');
        $this->assertFalse($storefront->born('un-site-qui-n-existe-pas'));
    }

    public function testEveryRouteOfTheApplicationIsFiledAndOnlyTheShopAndTheShowcaseCloseUnderConstruction(): void
    {
        // Fermé par défaut : tout ce qui n'est rangé nulle part est une route de la BOUTIQUE, fermée en construction. Toutes les routes
        // de l'application sont relues (leur chemin, hors de l'espace d'administration et des outils du développement).
        $classes = ['kept' => [], 'apps' => [], 'showcase' => 0, 'shop' => 0];
        $independent = 0;
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath()));
            if (str_starts_with($path, '/admin') || str_starts_with($path, '/_')) {
                continue;
            }
            $class = StorefrontGateSubscriber::classOf($path);
            if (preg_match('#^/api/(memoires|boussole)(/|$)#', $path) === 1) {
                // SENS 1 : toute route de Mémoires Vivantes, de Living Memory et de Boussole est servie comme sur un site ouvert
                $this->assertSame('apps', $class, "$name ($path)");
                $this->assertFalse(StorefrontGateSubscriber::closed($path, 'site'), $name);
                $this->assertFalse(StorefrontGateSubscriber::closed($path, 'shop'), $name);
                ++$independent;
                continue;
            }
            // SENS 2 : tout le reste est fermé pour un site neuf, sauf les routes gardées ouvertes et les routes de compte communes
            $this->assertSame(!in_array($class, ['kept', 'apps'], true), StorefrontGateSubscriber::closed($path, 'site'), "$name ($path)");
            $this->assertSame($class === 'shop', StorefrontGateSubscriber::closed($path, 'shop'), "$name ($path)");
            if (is_array($classes[$class])) {
                $classes[$class][] = $name;
            } else {
                ++$classes[$class];
            }
        }
        $this->assertGreaterThan(100, $independent, 'les routes de Mémoires Vivantes, de Living Memory et de Boussole');
        $this->assertGreaterThan(400, $classes['shop'], 'tout le reste : la boutique et ce que personne n\'a rangé');
        $this->assertGreaterThan(80, $classes['showcase']);
        sort($classes['kept']);
        sort($classes['apps']);
        // les routes servies à tous, toujours : écrites en toutes lettres
        $this->assertSame([
            'account_otp', 'api_app_activate', 'api_app_profile', 'api_currencies_get', 'api_entreprise_get', 'api_entreprise_update', 'api_landingpage_config_rollback',
            'api_landingpage_config_status', 'api_landingpage_config_sync', 'api_login', 'api_logout', 'api_mobile_app_theme', 'api_otp_verify', 'api_password_reset_confirm',
            'api_password_reset_verify', 'api_site_storefront', 'api_site_storefront_preview', 'api_stripe_webhook', 'api_tenant_check', 'api_tenant_check_mobile', 'api_tenant_hosts',
            'api_token_refresh', 'api_webhook_revenuecat', 'app_home', 'app_login', 'app_logout', 'app_memoiresvivantes_luluwebhook_handleluluwebhook', 'app_password_reset_confirm',
            'app_password_reset_confirm_form', 'app_password_reset_request', 'app_setup_status', 'app_stripe_connect', 'app_stripe_disconnect', 'app_stripe_success', 'app_tenant_setup',
            'bucket_simulator', 'health_check', 'legacy_assets_uploads', 'legacy_uploads', 'pertes_boitier_faits', 'pertes_boitier_inscription', 'pertes_boitier_jeton',
            'pertes_boitier_reglages', 'pertes_boitier_signe_de_vie', 'pos_pin_login', 'pos_users', 'secure_media_delivery', 'stripe_webhook',
        ], $classes['kept'], 'routes gardées ouvertes : à justifier dans StorefrontGateSubscriber::KEPT et dans la fiche du lot');
        // les routes de compte que les applications indépendantes partagent (et le reçu numérique de la caisse) : en toutes lettres aussi
        $this->assertSame(['api_account_delete', 'api_account_membership_leave', 'api_account_memberships', 'api_app_join', 'api_profile_get', 'api_profile_update', 'api_resend_verification',
            'api_validate_token', 'app_verify_email', 'get_stripe_config', 'notifications_appareils_delete', 'notifications_appareils_register', 'pos_public_receipt', 'register'], $classes['apps']);
        // aucune adresse voisine d'une route ouverte n'est ouverte
        foreach (['/api/products/12', '/api/productsid/12', '/api/category', '/api/vehicles.json', '/api/entreprise/12/x', '/api/entreprises', '/api/login/x', '/api/site/storefront/open', '/shop', '/api/tenant/hostsx', '/API/login', '/uploadsx',
            '/administrateur', '/api/memoiresx', '/api/boussoles', '/api/apps/boutique/terms', '/api/profilex', '/api/accounts', '/api/une-route-que-personne-n-a-rangee'] as $path) {
            $this->assertTrue(StorefrontGateSubscriber::closed($path, 'site'), $path);
            $this->assertSame(!in_array($path, ['/api/vehicles.json'], true), StorefrontGateSubscriber::closed($path, 'shop'), $path);
        }
        // les écritures de la boutique sont fermées à tous dans les deux étendues ; celles du site vitrine, pour un site neuf seulement
        foreach (['/api/order/create', '/api/order/create-guest', '/api/payment', '/api/cart/quote', '/api/stripe/create-intent', '/api/subscriptions', '/api/subscriptions/12/change-plan', '/api/subscriptions/12/resume',
            '/api/import-invitation/accept', '/api/products/12/reviews', '/register', '/checkout/confirm', '/cart/add/3', '/create-checkout-session/abc', '/stripe-payment-succes/abc'] as $path) {
            $this->assertSame([true, true], [StorefrontGateSubscriber::order($path, 'site'), StorefrontGateSubscriber::order($path, 'shop')], $path);
        }
        foreach (['/api/contacts', '/api/contacts/create', '/api/contact/submit', '/api/newsletter/subscribe', '/api/form-submissions', '/api/reservations', '/api/candidatures', '/api/financement/submit', '/api/booking/x', '/contact/'] as $path) {
            $this->assertSame([true, false], [StorefrontGateSubscriber::order($path, 'site'), StorefrontGateSubscriber::order($path, 'shop')], $path);
        }
        // … et rien de l'équipe n'y est : ni la vente en caisse, ni la gestion d'une demande ou d'un article
        foreach (['/api/order/create-multi-payment', '/api/order/cancel/3', '/api/contacts/3', '/api/reservations/3/confirm', '/api/subscriptions/3/cancel', '/api/products', '/api/pos/customers', '/admin/orders/3/capturer'] as $path) {
            $this->assertFalse(StorefrontGateSubscriber::order($path, 'site'), $path);
        }
    }

    public function testTheAdministratorOpensTheSiteFromTheAdministrationPage(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/ouverture-du-site';
        $stored = fn (): string => (string) $this->db()->fetchOne('SELECT storefront_state FROM entreprise ORDER BY id LIMIT 1');
        $this->db()->executeStatement("UPDATE stripe_config SET account_id = 'acct_essai_construction', is_active = true");
        $this->state('construction', true);
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        // sans compte : la page renvoie à la connexion
        $this->client->request('GET', $url);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($admin, 'main');
        $page = $this->client->request('GET', $url);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));
        $this->assertSame(['construction', 'site'], [$page->filter('[data-etat]')->attr('data-etat'), $page->filter('[data-etat]')->attr('data-etendue')]);
        $this->assertSame(['products', 'payment', 'terms'], $page->filter('table.ouverture-controle tr[data-point]')->each(static fn ($row) => $row->attr('data-point')));
        $this->assertCount(0, $page->filter('form.ouverture-refermer'));
        // un lien d'aperçu : rendu dans la réponse du geste, une fois ; il n'est gardé nulle part et ne se relit pas
        $shown = $this->client->submit($page->filter('form.ouverture-apercu')->form());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('#https://' . preg_quote(MV_TEST_TENANT_HOST, '#') . '/apercu\#token=[0-9a-f]{64}#', $shown->filter('.ouverture-lien code')->text());
        $again = $this->client->request('GET', $url);
        $this->assertCount(0, $again->filter('.ouverture-lien'));
        // un jeton de sécurité faux n'ouvre rien ; le bon ouvre ; puis la page propose de refermer : la boutique seule se referme
        $this->client->request('POST', $url . '/ouvrir', ['_token' => 'faux']);
        $this->assertSame('construction', $stored());
        $this->client->submit($again->filter('form.ouverture-ouvrir')->form());
        $this->assertSame('open', $stored());
        $open = $this->client->followRedirect();
        $this->assertSame(['open', ''], [$open->filter('[data-etat]')->attr('data-etat'), $open->filter('[data-etat]')->attr('data-etendue')]);
        $this->assertCount(0, $open->filter('form.ouverture-ouvrir'));
        $this->assertCount(0, $open->filter('form.ouverture-apercu'));
        $this->client->submit($open->filter('form.ouverture-refermer')->form());
        $this->assertSame('boutique', $stored());
        $closed = $this->client->followRedirect();
        $this->assertSame(['construction', 'shop'], [$closed->filter('[data-etat]')->attr('data-etat'), $closed->filter('[data-etat]')->attr('data-etendue')]);
        // sans le module Boutique : rien à ouvrir, aucun bouton
        $this->state('construction', false);
        $none = $this->client->request('GET', $url);
        $this->assertSame(['open', ''], [$none->filter('[data-etat]')->attr('data-etat'), $none->filter('[data-etat]')->attr('data-etendue')]);
        $this->assertCount(0, $none->filter('form'));
        $this->assertStringContainsString('rien à ouvrir', $none->filter('[data-ouverture-site]')->text());
        $this->client->getCookieJar()->clear();
    }

    private function reset(): void
    {
        $db = $this->db();
        // l'état de l'amorce des tests : boutique tenue pour « déjà ouverte »
        $db->executeStatement("UPDATE entreprise SET storefront_state = 'open', storefront_opened_at = NOW(), storefront_preview_hash = NULL, storefront_preview_expires_at = NULL");
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'storefront'");
        $this->master()->exec("DELETE FROM tenant_entitlement_events WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key = 'module.boutique'");
        $this->master()->exec("DELETE FROM tenant_entitlements WHERE tenant_code = '" . MV_TEST_TENANT_CODE . "' AND feature_key = 'module.boutique'");
        $this->flush();
    }

    /** Pose l'état gardé de la boutique du site de test, son module, et dit si elle a déjà été ouverte une fois */
    private function state(string $state, bool $shop, bool $openedOnce = false): void
    {
        $this->db()->executeStatement('UPDATE entreprise SET storefront_state = ?, is_boutique_active = ?, storefront_opened_at = ' . ($openedOnce ? 'NOW()' : 'NULL'), [$state, $shop ? 'true' : 'false']);
        $this->flush();
    }

    private function flush(): void
    {
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
    }

    private function master(): \PDO
    {
        return static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
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
