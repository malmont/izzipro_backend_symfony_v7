<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot FICHES-HORS-LIGNE (21/11/2026, `docs/boutique.md`) : un article HORS LIGNE (`isWeb` faux ou vide) est servi à l'équipe du site
 * (administrateur, caisse ; règle de la boutique en construction, `PublicProductVisibility`) et à personne d'autre. Le visiteur, le
 * compte client et le lien d'aperçu reçoivent EXACTEMENT la réponse d'un article absent (statut et corps, identifiant remplacé),
 * sur la fiche, ses routes filles, la personnalisation et la réservation ; le contrôle passe avant le cache partagé. Les listes
 * publiques ne le montrent pas ; le devis et l'intention de paiement du web le refusent (NOT_SELLABLE) ; la caisse ne change pas.
 */
class FichesHorsLigneTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $tag;
    /** @var array<string, int> article => identifiant (online, offline : isWeb faux, empty : isWeb vide) */
    private array $ids = [];
    /** @var array<string, int> article => identifiant de sa variante */
    private array $variants = [];
    private int $category = 0;
    private int $missing = 0;
    /** @var list<int> */
    private array $users = [];
    /** @var array<string, mixed>|null fiche du site d'origine (état de la boutique), remise à la fin */
    private ?array $sheet = null;
    private int $auditBefore = 0;
    private int $ordersBefore = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->em();
        // comme CheckoutApiTest : transporteur 6, réglages par défaut (commande d'invité permise), Stripe simulé
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-paiement@example.invalid', false, false);
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM boutique_setting');
        FakeCheckoutPaymentGateway::reset();
        $this->ordersBefore = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM "order"');
        $category = (new Categories())->setName('Hors ligne ' . $this->tag);
        $em->persist($category);
        foreach (['online' => true, 'offline' => false, 'empty' => null] as $key => $web) {
            $product = (new Product())->setName("Article $key {$this->tag}")->setSlug("hl-$key-{$this->tag}")->setPrice(1990)->setIsWeb($web)->setIsPos(true)
                ->setSaleEnabled(true)->setIsLandingPage(true);
            $variant = (new ProductVariant())->setStockQuantity(5)->setPrice(1990);
            $product->addVariant($variant);
            $product->addCategory($category);
            $em->persist($product);
            $em->flush();
            [$this->ids[$key], $this->variants[$key]] = [(int) $product->getId(), (int) $variant->getId()];
        }
        $this->category = (int) $category->getId();
        $this->missing = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 100000 FROM product');
        $this->auditBefore = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM content_audit_log');
        $this->sheet = $em->getConnection()->fetchAssociative('SELECT id, storefront_state, storefront_opened_at, storefront_preview_hash, storefront_preview_expires_at FROM entreprise ORDER BY id LIMIT 1') ?: null;
        $em->clear();
        $this->flush();
    }

    protected function tearDown(): void
    {
        $db = $this->em()->getConnection();
        // les commandes de l'essai (et tout ce qui les cite), puis ses articles (et tout ce qui les cite : variantes, mouvements de stock…)
        $this->purge($db, 'order', array_map('intval', $db->fetchFirstColumn('SELECT id FROM "order" WHERE id > ?', [$this->ordersBefore])));
        $this->purge($db, 'product', array_values($this->ids));
        $this->purge($db, 'user', array_map('intval', $db->fetchFirstColumn("SELECT id FROM \"user\" WHERE email LIKE 'hl-invite%'"))); // invités des commandes
        if ($this->sheet !== null) {
            $db->executeStatement('UPDATE entreprise SET storefront_state = ?, storefront_opened_at = ?, storefront_preview_hash = ?, storefront_preview_expires_at = ? WHERE id = ?',
                [$this->sheet['storefront_state'], $this->sheet['storefront_opened_at'], $this->sheet['storefront_preview_hash'], $this->sheet['storefront_preview_expires_at'], $this->sheet['id']]);
        }
        $db->executeStatement('DELETE FROM categories WHERE id = ?', [$this->category]);
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'storefront' AND id > ?", [$this->auditBefore]); // le lien d'aperçu de l'essai
        foreach ($this->users as $id) {
            $db->executeStatement('DELETE FROM "user" WHERE id = ?', [$id]);
        }
        $this->flush();
        parent::tearDown();
    }

    public function testAnOfflineArticleAnswersThePublicExactlyLikeAMissingOneAndTheTeamStillReadsIt(): void
    {
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $admin = $this->user(['ROLE_ADMIN']);
        $cashier = $this->user(['ROLE_USER_POS']);
        foreach ($this->reads() as $label => $path) {
            $online = $this->get($path('online'), null);
            $this->assertNotSame(404, $online->getStatusCode(), "$label : l'article en ligne est servi ({$online->getContent()})");
            $absent = $this->get($path('missing'), null);
            foreach (['offline', 'empty'] as $hidden) {
                foreach (['visiteur' => null, 'client' => $customer] as $who => $as) {
                    $this->assertSame($this->shape($absent, 'missing'), $this->shape($this->get($path($hidden), $as), $hidden), "$label ($hidden, $who) : la réponse d'un article absent");
                }
                foreach (['administrateur' => $admin, 'caissier' => $cashier] as $who => $as) {
                    $this->assertSame($online->getStatusCode(), $this->get($path($hidden), $as)->getStatusCode(), "$label ($hidden, $who) : l'équipe le lit comme un article en ligne");
                }
            }
        }
        // par son slug : la réponse d'un slug inconnu
        $unknown = $this->get('/api/products/by-slug/hl-inconnu-' . $this->tag . '?locale=fr', null);
        $this->assertSame(404, $unknown->getStatusCode());
        foreach (['offline', 'empty'] as $hidden) {
            $this->assertSame([404, $unknown->getContent()], $this->status($this->get("/api/products/by-slug/hl-$hidden-{$this->tag}?locale=fr", $customer)), "by-slug ($hidden)");
            $this->assertSame(200, $this->get("/api/products/by-slug/hl-$hidden-{$this->tag}?locale=fr", $admin)->getStatusCode(), "by-slug ($hidden) : l'administrateur");
        }
        $this->assertSame(200, $this->get("/api/products/by-slug/hl-online-{$this->tag}?locale=fr", null)->getStatusCode());
        // le client ne note pas un article retiré de la vente en ligne : la réponse d'un article absent
        foreach (['GET' => '/reviews/eligibility', 'POST' => '/reviews'] as $method => $suffix) {
            $absent = $this->send($method, "/api/products/{$this->missing}$suffix", $customer, ['rating' => 5, 'comment' => 'Très bien, essai hors ligne.']);
            $hidden = $this->send($method, "/api/products/{$this->ids['offline']}$suffix", $customer, ['rating' => 5, 'comment' => 'Très bien, essai hors ligne.']);
            $this->assertSame($this->shape($absent, 'missing'), $this->shape($hidden, 'offline'), "$method $suffix (client)");
        }
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews_product WHERE product_reviews_id = ?', [$this->ids['offline']]), 'aucun avis écrit');
    }

    public function testAFicheReadByTheTeamIsNeverServedFromTheSharedCacheToThePublic(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $id = $this->ids['offline'];
        // l'administrateur lit d'abord (la fiche entre dans le cache partagé product_detail_<id>_<langue>), puis le visiteur
        $this->assertSame(200, $this->get("/api/products/$id?locale=fr", $admin)->getStatusCode());
        $this->assertSame(200, $this->get("/api/products/$id/variants", $admin)->getStatusCode());
        $this->assertSame(404, $this->get("/api/products/$id?locale=fr", null)->getStatusCode(), 'fiche : pas resservie depuis le cache');
        $this->assertSame(404, $this->get("/api/productsid/$id?locale=fr", null)->getStatusCode(), 'productsid : pas resservie depuis le cache');
        $this->assertSame(404, $this->get("/api/products/$id/variants", null)->getStatusCode(), 'variantes : pas resservies depuis le cache');
        // et l'inverse : le refus du visiteur ne ferme pas la fiche à l'équipe
        $this->assertSame(200, $this->get("/api/products/$id?locale=fr", $admin)->getStatusCode());
    }

    public function testTheLinkOfThePreviewSeesWhatThePublicWillSee(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        // site neuf en construction : l'aperçu par lien est servi, comme le public le sera à l'ouverture
        $this->em()->getConnection()->executeStatement("UPDATE entreprise SET storefront_state = 'construction', storefront_opened_at = NULL WHERE id = ?", [$this->sheet['id']]);
        $this->flush();
        $link = json_decode((string) $this->send('POST', '/api/site/storefront/preview-link', $admin)->getContent(), true);
        $this->assertArrayHasKey('url', $link, json_encode($link));
        $preview = ['HTTP_X_STOREFRONT_PREVIEW' => substr((string) $link['url'], -64)];
        $this->assertSame(200, $this->get("/api/productsid/{$this->ids['online']}?locale=fr", null, $preview)->getStatusCode(), 'l\'aperçu lit un article en ligne');
        $absent = $this->get("/api/productsid/{$this->missing}?locale=fr", null, $preview);
        $this->assertSame($this->shape($absent, 'missing'), $this->shape($this->get("/api/productsid/{$this->ids['offline']}?locale=fr", null, $preview), 'offline'), 'l\'aperçu ne voit pas un article hors ligne');
        $this->assertSame(200, $this->get("/api/productsid/{$this->ids['offline']}?locale=fr", $admin)->getStatusCode(), 'l\'administrateur, lui, le voit');
    }

    public function testThePublicListsShowOnlineArticlesAndTheCashierStillGetsTheWholeCatalogue(): void
    {
        $cashier = $this->user(['ROLE_USER_POS']);
        $categories = '/api/products/by-category?pageSize=50&categories=' . urlencode(json_encode([$this->category]));
        foreach (['sans paramètre' => '', 'isWeb=false demandé' => '&isWeb=false', 'isWeb=true' => '&isWeb=true'] as $label => $param) {
            $this->assertSame([$this->ids['online']], $this->listed($categories . $param, null), "visiteur, $label : les seuls articles en ligne");
        }
        // relecture : le corps dépend de qui lit, un cache partagé doit le savoir
        $vary = implode(', ', $this->get($categories, null)->getVary()); // toutes les valeurs (headers->get ne rend que la première)
        foreach (['Authorization', 'Cookie'] as $header) {
            $this->assertStringContainsString($header, $vary, "by-category : Vary nomme $header");
        }
        // la caisse (posflutter) appelle by-category sans le paramètre, avec son jeton : les articles de caisse hors web reviennent
        $all = [$this->ids['online'], $this->ids['offline'], $this->ids['empty']];
        sort($all);
        $this->assertSame($all, $this->listed($categories, $cashier), 'caissier sans paramètre : tout le catalogue');
        $this->assertSame([$this->ids['online']], $this->listed($categories . '&isWeb=true', $cashier), 'caissier, isWeb=true : le filtre demandé');
        // recherche par mot-clé : même règle
        $this->assertSame([$this->ids['online']], $this->listed('/api/products/by-category?pageSize=50&keyword=' . urlencode($this->tag), null), 'recherche du visiteur');
        // articles de la page d'accueil : seulement ceux en ligne (aucun article concerné sur les sites, mesuré le 21/11/2026)
        $landing = json_decode((string) $this->get('/api/landingpage?locale=fr', null)->getContent(), true);
        $ids = array_column(is_array($landing) && array_is_list($landing) ? $landing : ($landing['data'] ?? $landing['products'] ?? []), 'id');
        $this->assertContains($this->ids['online'], $ids, 'page d\'accueil : l\'article en ligne');
        $this->assertNotContains($this->ids['offline'], $ids, 'page d\'accueil : pas l\'article hors ligne');
        $this->assertNotContains($this->ids['empty'], $ids, 'page d\'accueil : pas l\'article sans canal');
    }

    public function testTheWebQuoteAndThePaymentIntentRefuseAnOfflineArticleAndTheCashRegisterDoesNot(): void
    {
        $line = fn (string $key): array => ['items' => [['productVariantId' => $this->variants[$key], 'quantity' => 1]]];
        $this->assertSame(200, $this->send('POST', '/api/cart/quote', null, $line('online'))->getStatusCode(), 'devis web d\'un article en ligne');
        foreach (['offline', 'empty'] as $hidden) {
            $refused = $this->send('POST', '/api/cart/quote', null, $line($hidden));
            $this->assertSame(422, $refused->getStatusCode(), (string) $refused->getContent());
            $this->assertContains(['items[0]', 'NOT_SELLABLE'], $this->errors($refused), "devis web ($hidden)");
            // l'intention de paiement : refusée au devis, rien n'est ouvert
            $before = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM checkout_session');
            $intent = $this->send('POST', '/api/stripe/create-intent', null, $line($hidden));
            $this->assertSame(422, $intent->getStatusCode(), (string) $intent->getContent());
            $this->assertContains(['items[0]', 'NOT_SELLABLE'], $this->errors($intent), "intention de paiement ($hidden)");
            $this->assertSame($before, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM checkout_session'), 'aucune session de paiement ouverte');
        }
        // relecture : un poste de caisse posé par un VISITEUR ne lève pas le refus ; le caissier (compte d'équipe) vend les articles
        // de caisse seule, comme avant
        $forged = $this->send('POST', '/api/cart/quote', null, $line('offline') + ['cashRegisterId' => 0]);
        $this->assertSame(422, $forged->getStatusCode(), 'visiteur avec un cashRegisterId : ' . $forged->getContent());
        $this->assertContains(['items[0]', 'NOT_SELLABLE'], $this->errors($forged));
        $counter = $this->send('POST', '/api/cart/quote', $this->user(['ROLE_USER_POS']), $line('offline') + ['cashRegisterId' => 1]);
        $this->assertSame(200, $counter->getStatusCode(), 'devis de caisse : ' . $counter->getContent());
    }

    /**
     * Demande du chef (21/11/2026) : un article qui passe hors ligne APRÈS l'intention de paiement n'empêche pas la commande. Le
     * client a payé (intention vérifiée) : la commande se crée, et l'administrateur la lit. Jamais de paiement sans commande.
     */
    public function testAnArticleTakenOfflineAfterThePaymentIntentStillGivesTheOrderOfThePaidCustomer(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $body = $this->guestBody($this->variants['online'], 'hl-invite-' . $this->tag . '@example.invalid');
        $intent = json_decode((string) $this->send('POST', '/api/stripe/create-intent', null, ['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']])->getContent(), true);
        $this->assertArrayHasKey('paymentIntentId', $intent, json_encode($intent));
        FakeCheckoutPaymentGateway::confirm($intent['paymentIntentId']);
        // l'article passe hors ligne entre l'intention et la commande
        $this->em()->getConnection()->executeStatement('UPDATE product SET is_web = false WHERE id = ?', [$this->ids['online']]);
        $this->flush();
        $created = $this->send('POST', '/api/order/create-guest', null, $body + ['paymentIntentId' => $intent['paymentIntentId'], 'clientSecret' => $intent['paymentIntentId'] . '_secret_test']);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $order = (int) json_decode((string) $created->getContent(), true)['orderId'];
        $this->assertSame(200, $this->get("/api/site/commandes/$order", $admin)->getStatusCode(), 'l\'administrateur voit la commande payée');
        // et l'article, désormais hors ligne, ne se vend plus au web
        $this->assertSame(422, $this->send('POST', '/api/cart/quote', null, ['items' => $body['items']])->getStatusCode());
    }

    /** Demande du chef : aucun chemin web ne crée de commande sans intention de paiement vérifiée (invité comme client connecté) */
    public function testNoWebPathCreatesAnOrderWithoutAVerifiedPaymentIntent(): void
    {
        $customer = $this->user(['ROLE_USER_INTERNET']);
        $body = $this->guestBody($this->variants['online'], 'hl-invite2-' . $this->tag . '@example.invalid');
        $open = json_decode((string) $this->send('POST', '/api/stripe/create-intent', null, ['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']])->getContent(), true)['paymentIntentId'];
        $count = fn (): int => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM "order" WHERE id > ?', [$this->ordersBefore]);
        foreach (['sans intention' => [], 'intention inconnue' => ['paymentIntentId' => 'pi_inconnu_' . $this->tag], 'intention non confirmée' => ['paymentIntentId' => $open, 'clientSecret' => $open . '_secret_test']] as $label => $payment) {
            foreach (['invité' => ['/api/order/create-guest', null], 'client' => ['/api/order/create', $customer]] as $who => [$path, $as]) {
                $refused = $this->send('POST', $path, $as, $body + $payment);
                $this->assertGreaterThanOrEqual(400, $refused->getStatusCode(), "$who, $label : " . $refused->getContent());
                $this->assertSame(0, $count(), "$who, $label : aucune commande créée");
            }
        }
    }

    /** @return array<string, mixed> le corps d'une commande d'invité (comme CheckoutApiTest) */
    private function guestBody(int $variant, string $email): array
    {
        return [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Hors ligne', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => 6,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Hors ligne', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
    }

    /**
     * Efface des lignes et, d'abord, tout ce qui les cite par une clé étrangère (lu dans le schéma de la base d'essai), de proche
     * en proche : l'essai ne laisse rien derrière lui dans la base partagée.
     *
     * @param list<int> $ids
     */
    private function purge(\Doctrine\DBAL\Connection $db, string $table, array $ids, int $depth = 0): void
    {
        if ($ids === [] || $depth > 6) {
            return;
        }
        $refs = $db->fetchAllAssociative("SELECT kcu.table_name AS t, kcu.column_name AS c FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
            WHERE ccu.table_name = ? AND ccu.column_name = 'id' AND kcu.table_schema = current_schema()", [$table]);
        $list = implode(',', array_map('intval', $ids));
        foreach ($refs as $ref) {
            if ($ref['t'] === $table) {
                continue;
            }
            $hasId = (bool) $db->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'id'", [$ref['t']]);
            if ($hasId) {
                $this->purge($db, $ref['t'], array_map('intval', $db->fetchFirstColumn(sprintf('SELECT id FROM "%s" WHERE "%s" IN (%s)', $ref['t'], $ref['c'], $list))), $depth + 1);
            } else {
                $db->executeStatement(sprintf('DELETE FROM "%s" WHERE "%s" IN (%s)', $ref['t'], $ref['c'], $list));
            }
        }
        $db->executeStatement(sprintf('DELETE FROM "%s" WHERE id IN (%s)', $table, $list));
    }

    /** @return array<string, \Closure(string): string> les lectures publiques d'un article, l'article choisi par sa clé */
    private function reads(): array
    {
        $id = fn (string $key): int => $key === 'missing' ? $this->missing : $this->ids[$key];
        $variant = fn (string $key): int => $key === 'missing' ? $this->missing : $this->variants[$key];
        $range = 'start=' . date('Y-m-d', strtotime('+3 days')) . '&end=' . date('Y-m-d', strtotime('+5 days'));

        return [
            'fiche' => fn (string $k): string => "/api/products/{$id($k)}?locale=fr",
            'productsid' => fn (string $k): string => "/api/productsid/{$id($k)}?locale=fr",
            'variantes' => fn (string $k): string => "/api/products/{$id($k)}/variants",
            'avis' => fn (string $k): string => "/api/products/{$id($k)}/reviews?locale=fr",
            'personnalisation' => fn (string $k): string => "/api/customization/config/{$variant($k)}",
            'calendrier' => fn (string $k): string => "/api/booking/calendar/{$id($k)}?$range",
            'disponibilité' => fn (string $k): string => "/api/booking/check/{$id($k)}?start=" . date('Y-m-d', strtotime('+3 days')) . '%2010:00&end=' . date('Y-m-d', strtotime('+3 days')) . '%2012:00',
        ];
    }

    /** @return array{0: int, 1: string} statut et corps, l'identifiant de l'article (ou de sa variante) remplacé */
    private function shape(Response $response, string $key): array
    {
        $ids = $key === 'missing' ? [$this->missing] : [$this->ids[$key], $this->variants[$key]];

        return [$response->getStatusCode(), str_replace(array_map('strval', $ids), '<id>', (string) $response->getContent())];
    }

    /** @return array{0: int, 1: string} */
    private function status(Response $response): array
    {
        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    /** @return list<int> identifiants des articles de l'essai dans la liste, triés */
    private function listed(string $path, ?User $as): array
    {
        $response = $this->get($path, $as);
        $this->assertSame(200, $response->getStatusCode(), $path . ' : ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $ids = array_values(array_intersect(array_column($body['data'] ?? [], 'id'), $this->ids));
        sort($ids);

        return $ids;
    }

    /** @return list<array{0: string, 1: string}> chemin et code de chaque erreur rendue */
    private function errors(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true) ?? [];

        return array_map(static fn (array $e): array => [(string) ($e['path'] ?? $e['field'] ?? ''), (string) ($e['code'] ?? '')], array_filter($body['errors'] ?? [], 'is_array'));
    }

    private function user(array $roles): User
    {
        $email = 'hl-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Hors')->setLastname('Ligne')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    /** @param array<string, string> $headers */
    private function get(string $path, ?User $as, array $headers = []): Response
    {
        return $this->send('GET', $path, $as, null, $headers);
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $path, ?User $as, ?array $body = null, array $headers = []): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.87.' . random_int(1, 254) . '.' . random_int(1, 254)] + $headers;
        if ($as !== null) {
            $this->em();
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function flush(): void
    {
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
