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
        $this->saved = ['boutique' => $db->fetchOne('SELECT is_boutique_active FROM entreprise ORDER BY id LIMIT 1'), 'stripe' => $db->fetchAllAssociative('SELECT id, account_id, live_account_id, is_active FROM stripe_config')];
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        $db = $this->db();
        $db->executeStatement('UPDATE entreprise SET is_boutique_active = ?', [$this->saved['boutique'] ? 'true' : 'false']);
        foreach ($this->saved['stripe'] as $row) {
            $db->executeStatement('UPDATE stripe_config SET account_id = ?, live_account_id = ?, is_active = ? WHERE id = ?', [$row['account_id'], $row['live_account_id'], $row['is_active'] ? 'true' : 'false', $row['id']]);
        }
        $db->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'vitrine-%@example.invalid'");
        $db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'vitrine-%@example.invalid'");
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
        // FERMÉ PAR DÉFAUT : une autre adresse de la fiche d'un article, un suffixe de format, une lecture d'un autre module, un lien
        // court, les conditions d'une application… rien ne passe sans avoir été rangé parmi les routes gardées ouvertes
        foreach (['/api/productsid/12', '/api/vehicles.json', '/api/vehicle_products.json', '/api/memoires/books', '/api/boussole/referentials', '/api/apps/boutique/terms', '/api/offers', '/api/admin-settings', '/api/orders/12'] as $path) {
            $refused = $this->api('GET', $path);
            $this->assertSame([403, 'STOREFRONT_UNDER_CONSTRUCTION'], [$refused->getStatusCode(), $this->json($refused)['code'] ?? null], $path);
            $this->assertSame(403, $this->api('GET', $path, null, $customer)->getStatusCode(), "$path : compte client");
        }
        $this->assertSame(403, $this->api('HEAD', '/api/category')->getStatusCode());
        // les pages du serveur aussi (ancienne boutique, vidéos, médias partagés) : une page sobre, le nom du site, rien d'autre
        foreach (['/shop', '/product/un-article', '/videos', '/shared-media', '/cart', '/cgu/conditions-generales-utilisation'] as $path) {
            $page = $this->api('GET', $path);
            $this->assertSame(403, $page->getStatusCode(), $path);
            $this->assertStringContainsString('Bientôt ouvert', (string) $page->getContent(), $path);
            $this->assertStringContainsString(htmlspecialchars($name, ENT_QUOTES), (string) $page->getContent());
        }
        // un refus ne se garde dans aucun cache
        $refused = $this->api('GET', '/api/category');
        $this->assertStringContainsString('no-store', (string) $refused->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $refused->headers->get('Cache-Control'));

        // Ce qui reste servi : la connexion, le mot de passe oublié, et la fiche du site réduite aux mentions légales
        $this->assertSame(200, $this->api('POST', '/api/login', ['username' => $admin->getEmail(), 'password' => self::PASSWORD, 'platform' => 'webadmin'])->getStatusCode());
        $this->assertSame(200, $this->api('POST', '/api/password-reset/request', ['email' => 'inconnu@example.invalid'])->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/currencies')->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/health')->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/tenant/hosts')->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/')->getStatusCode(), 'le formulaire de connexion de l\'espace d\'administration');
        $this->assertNotSame(403, $this->api('GET', '/api/app-profile/pos')->getStatusCode(), 'les applications de l\'équipe lisent leur profil avant la connexion');
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
        $this->assertSame($reduced, $this->json($this->api('GET', "/api/entreprise/$id", null, $customer)), 'un compte client lit la fiche réduite, comme un visiteur');
        $this->assertSame([$full['isPosActive'], $full['isManagementAppActive'], $full['isLivingMemoryActive'], $full['isMobileShopActive']], [$reduced['isPosActive'], $reduced['isManagementAppActive'], $reduced['isLivingMemoryActive'], $reduced['isMobileShopActive']],
            'les applications de l\'équipe lisent leur indicateur avant la connexion');

        // Commandes et formulaires : fermés à TOUS, administrateur compris ; la caisse, elle, vend toujours en magasin
        foreach (['/api/order/create-guest', '/api/cart/quote', '/api/stripe/create-intent', '/api/contacts', '/api/contact/submit', '/api/newsletter/subscribe', '/api/reservations', '/api/form-submissions', '/api/register', '/api/order/create', '/api/payment', '/api/subscriptions'] as $path) {
            foreach ([null, $customer, $admin] as $who) {
                $refused = $this->api('POST', $path, ['x' => 1], $who);
                // trois de ces routes exigent déjà un compte : sans compte, le pare-feu répond 401 avant la garde du site
                $expected = $who === null && in_array($path, ['/api/order/create', '/api/payment', '/api/subscriptions'], true) ? 401 : 'STOREFRONT_UNDER_CONSTRUCTION';
                $this->assertSame($expected, $this->json($refused)['code'] ?? $refused->getStatusCode(), $path . ' / ' . ($who?->getRoles()[0] ?? 'visiteur'));
            }
        }
        foreach (['/api/order/create', '/api/payment', '/api/order/create-guest', '/api/subscriptions', '/api/cart/quote', '/api/contacts', '/api/import-invitation/accept'] as $path) {
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', $path, ['x' => 1], $cashier))['code'] ?? null, "$path : fermé à la caisse comme à tous");
        }
        // la vente en magasin a sa propre route, que la construction ne ferme pas
        $this->assertNotSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/order/create-multi-payment', ['x' => 1], $cashier))['code'] ?? null);
        // les formulaires des pages du serveur aussi, pour tous
        foreach (['/register', '/contact/', '/cart/add/1'] as $path) {
            $this->assertSame(403, $this->api('POST', $path, ['x' => 1])->getStatusCode(), $path);
            $this->assertSame(403, $this->api('POST', $path, ['x' => 1], $admin)->getStatusCode(), "$path : équipe");
        }
        // un visiteur ne s'inscrit ni n'écrit nulle part : les autres modules sont fermés avec le site
        foreach (['/api/boussole/auth/register', '/api/import-invitation/verify', '/api/apps/join'] as $path) {
            $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', $path, ['x' => 1]))['code'] ?? $this->client->getResponse()->getStatusCode(), $path);
        }
        // … et un compte client non plus (application Living Memory comprise) : le site n'est pas ouvert
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/memoires/mobile/books', ['x' => 1], $customer))['code'] ?? null);
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('GET', '/api/memoires/mobile/pricing', null, $customer))['code'] ?? null);
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email = 'nouveau-client@example.invalid'"));

        // Un compte d'équipe voit la vraie boutique sans lien d'aperçu, et travaille : il crée ses articles
        $team = $this->api('GET', '/api/site/storefront', null, $admin);
        $this->assertSame(['active' => true, 'expiresAt' => null], $this->json($team)['preview']);
        $this->assertStringContainsString('private', (string) $team->headers->get('Cache-Control'));
        $this->assertSame(200, $this->api('GET', '/api/category', null, $admin)->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/landingpage-settings', null, $admin)->getStatusCode());
        $this->assertSame(200, $this->api('GET', '/api/category', null, $this->user(['ROLE_SUPPORT']))->getStatusCode(), 'un agent d\'assistance est de l\'équipe');
        $this->assertSame(200, $this->api('GET', '/api/category', null, $cashier)->getStatusCode(), 'un caissier aussi');
        $served = $this->api('GET', '/api/category', null, $admin);
        $this->assertStringContainsString('no-store', (string) $served->headers->get('Cache-Control'), 'une lecture d\'équipe ne se garde dans aucun cache partagé');
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
        $seen = $this->api('GET', '/api/category', null, null, $preview);
        $this->assertSame(200, $seen->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $seen->headers->get('Cache-Control'), 'une lecture d\'aperçu ne se garde dans aucun cache partagé');
        $this->assertStringNotContainsString('public', (string) $seen->headers->get('Cache-Control'));
        $this->assertContains(StorefrontService::PREVIEW_HEADER, $seen->getVary());
        $this->assertSame(200, $this->api('GET', '/api/productsid/999999', null, null, $preview)->getStatusCode() === 403 ? 403 : 200, 'l\'aperçu ouvre toutes les lectures');
        $this->assertSame($full, $this->json($this->api('GET', "/api/entreprise/$id", null, null, $preview)));
        // … mais aucune écriture, même hors des commandes
        $this->assertSame('STOREFRONT_UNDER_CONSTRUCTION', $this->json($this->api('POST', '/api/boussole/auth/register', ['x' => 1], null, $preview))['code'] ?? null);
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
        $this->assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM stripe_config'), 'le modèle des tests porte une ligne de configuration de paiement');
        // un compte de paiement connecté mais pas encore actif (inscription abandonnée) ne suffit pas
        $this->db()->executeStatement("UPDATE stripe_config SET account_id = 'acct_essai_construction', is_active = false, live_account_id = NULL");
        $this->assertSame(409, $this->api('POST', '/api/site/storefront/open', null, $admin)->getStatusCode());
        $this->db()->executeStatement('UPDATE stripe_config SET is_active = true');
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
        $this->assertEqualsWithDelta(time(), (new \DateTimeImmutable($opened['openedAt']))->getTimestamp(), 120, 'l\'heure d\'ouverture est écrite en UTC');
        // refermer retire le lien d'aperçu : un ancien lien ne revit pas
        $this->api('POST', '/api/site/storefront/preview-link', null, $admin);
        $this->api('POST', '/api/site/storefront/close', null, $admin);
        $this->assertNull($this->db()->fetchOne('SELECT storefront_preview_hash FROM entreprise ORDER BY id LIMIT 1'));
    }

    public function testOnlyAShortListOfRoutesStaysOpenUnderConstructionAmongAllTheRoutesOfTheApplication(): void
    {
        // Fermé par défaut : la garde ne connaît que ce qui reste OUVERT. Toutes les routes de l'application sont relues ; celles qui
        // restent ouvertes hors de l'espace d'administration sont écrites ici en toutes lettres. Une route nouvelle est fermée sans
        // qu'on y pense ; une route qui deviendrait ouverte par accident (une expression trop large) fait échouer cet essai.
        $kept = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $route->getPath()));
            if (StorefrontGateSubscriber::kept($path) && !str_starts_with($path, '/admin') && !str_starts_with($path, '/_')) {
                $kept[] = $name;
            }
        }
        sort($kept);
        $this->assertSame([
            'account_otp', 'api_app_activate', 'api_app_profile', 'api_currencies_get', 'api_entreprise_get', 'api_entreprise_update', 'api_landingpage_config_rollback',
            'api_landingpage_config_status', 'api_landingpage_config_sync', 'api_login', 'api_logout', 'api_mobile_app_theme', 'api_otp_verify', 'api_password_reset_confirm',
            'api_password_reset_verify', 'api_site_storefront', 'api_site_storefront_preview', 'api_stripe_webhook', 'api_tenant_check', 'api_tenant_check_mobile', 'api_tenant_hosts',
            'api_token_refresh', 'api_webhook_revenuecat', 'app_home', 'app_login', 'app_logout', 'app_memoiresvivantes_luluwebhook_handleluluwebhook', 'app_password_reset_confirm',
            'app_password_reset_confirm_form', 'app_password_reset_request', 'app_setup_status', 'app_stripe_connect', 'app_stripe_disconnect', 'app_stripe_success', 'app_tenant_setup',
            'bucket_simulator', 'health_check', 'legacy_assets_uploads', 'legacy_uploads', 'pertes_boitier_faits', 'pertes_boitier_inscription', 'pertes_boitier_jeton',
            'pertes_boitier_reglages', 'pertes_boitier_signe_de_vie', 'pos_pin_login', 'pos_users', 'secure_media_delivery', 'stripe_webhook',
        ], $kept, 'routes gardées ouvertes sur un site en construction : à justifier dans StorefrontGateSubscriber::KEPT et dans la fiche du lot');
        // aucune lecture du catalogue ou du site, aucune adresse voisine, n'est ouverte
        foreach (['/api/products/12', '/api/productsid/12', '/api/category', '/api/vehicles.json', '/api/entreprise/12/x', '/api/entreprises', '/api/login/x', '/api/site/storefront/open', '/videos', '/shop', '/api/tenant/hostsx', '/API/login', '/uploadsx', '/administrateur'] as $path) {
            $this->assertFalse(StorefrontGateSubscriber::kept($path), $path);
        }
        // les écritures de la boutique publique et des formulaires sont fermées à tous…
        foreach (['/api/order/create', '/api/order/create-guest', '/api/payment', '/api/cart/quote', '/api/stripe/create-intent', '/api/contacts', '/api/contacts/create', '/api/contact/submit', '/api/newsletter/subscribe', '/api/form-submissions',
            '/api/reservations', '/api/candidatures', '/api/financement/submit', '/api/register', '/api/subscriptions', '/api/subscriptions/12/change-plan', '/api/subscriptions/12/resume', '/api/import-invitation/accept', '/api/booking/x',
            '/register', '/contact/', '/checkout/confirm', '/cart/add/3', '/create-checkout-session/abc'] as $path) {
            $this->assertTrue(StorefrontGateSubscriber::order($path), $path);
        }
        // … et rien de l'équipe n'y est : ni la vente en caisse, ni la gestion d'une demande ou d'un article
        foreach (['/api/order/create-multi-payment', '/api/order/cancel/3', '/api/contacts/3', '/api/reservations/3/confirm', '/api/subscriptions/3/cancel', '/api/products', '/api/pos/customers', '/admin/orders/3/capturer'] as $path) {
            $this->assertFalse(StorefrontGateSubscriber::order($path), $path);
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
