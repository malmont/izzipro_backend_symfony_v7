<?php

namespace App\Tests\Functional\Group;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Import\Message\RunCatalogImportMessage;
use App\Import\MessageHandler\RunCatalogImportHandler;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeImportImages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot G-4 (19/11/2026, contrat validé par le chef, 3 ajouts) : copie du catalogue entre boutiques du groupe, par le directeur au siège.
 * - Aperçu sans écriture ; non-directeur et directeur entré refusés ; une seule tâche active par cible.
 * - Copie : nom, prix, variantes, catégorie, photo RECOPIÉE (fichier distinct), stock jamais copié ; articles MASQUÉS par défaut (ajout 2) ;
 *   coût copié depuis le siège, jamais depuis une autre boutique sans `withCost` (ajout 1) ; avis aux administrateurs de la cible (ajout 3).
 * - Correspondance article et variante dans la base maître ; rejeu sans doublon ; annulation qui retire articles et liens ; devises
 *   différentes refusées.
 *
 * Premier site de test : le siège ; second : la boutique du groupe.
 */
class GroupCatalogCopyTest extends WebTestCase
{
    private KernelBrowser $client;
    private \PDO $master;
    private string $tag;
    private int $single = 0;
    /** @var list<string> fichiers de photos posés par l'essai */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        foreach ($this->files as $file) {
            @unlink(self::photos() . '/' . $file);
        }
        parent::tearDown();
    }

    public function testTheDirectorCopiesArticlesHiddenWithPhotosRecopiedAndTheShopIsTold(): void
    {
        $groupId = $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN']);
        [$pid, $variantIds, $photo] = $this->product(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $shopProducts = fn (): int => (int) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT COUNT(*) FROM product')->fetchColumn();
        $before = $shopProducts();

        // refus : l'administrateur de la boutique chez lui, une boutique hors du groupe, une cible égale à la source
        $this->assertSame([403, 'GROUP_COPY_FORBIDDEN'], $this->call('POST', '/api/gestion/groupe/copies', $shopAdmin, MV_TEST_TENANT2_HOST, ['target' => MV_TEST_TENANT_CODE]));
        $this->assertSame([404, 'GROUP_SHOP_UNKNOWN'], $this->call('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => 'ailleurs-' . $this->tag]));
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->call('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT_CODE]));
        $this->assertSame([422, 'VALIDATION_FAILED'], $this->call('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => ['1']]));

        // aperçu : rien n'est écrit dans le catalogue de la cible
        $copy = $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid, $this->single]], 201);
        $this->assertSame(['analysed', 2, MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], [$copy['import']['status'], $copy['import']['report']['products']['importable'], $copy['source'], $copy['target']]);
        $this->assertSame($before, $shopProducts(), 'l\'aperçu n\'écrit rien');
        $this->assertSame([409, 'IMPORT_ALREADY_ACTIVE'], $this->call('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]]));
        // la boutique cible ne confirme pas elle-même une copie du groupe (seul le directeur le fait)
        $this->assertSame(404, $this->call('POST', '/api/site/imports/' . $copy['import']['id'] . '/confirm', $shopAdmin, MV_TEST_TENANT2_HOST, [])[0]);

        // confirmation : masqués par défaut, coût copié depuis le siège, avis à l'administrateur de la cible
        $confirmed = $this->json('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', $director, MV_TEST_TENANT_HOST, [], 202);
        $this->assertSame(['running', true], [$confirmed['import']['status'], $confirmed['withCost']]);
        $this->assertSame([409, 'COPY_NOT_READY'], $this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', $director, MV_TEST_TENANT_HOST, []));
        $told = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM notification_log WHERE kind = 'group_catalog_copied' AND user_id = " . $this->userId(MV_TEST_TENANT2_DB, 'boutique'))->fetchColumn();
        $this->assertSame(1, (int) $told, 'l\'administrateur de la cible est prévenu (ajout 3)');
        $this->runCopy($copy['import']['id'], MV_TEST_TENANT2_CODE);

        $copied = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT id, name, is_web, is_pos, purchase_price, image, barcode FROM product WHERE name = 'Copie g4 {$this->tag}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($copied, 'l\'article est copié');
        $this->assertSame('G4P-' . strtoupper($this->tag), $copied['barcode'], 'le code-barres de l\'article est copié');
        $alone = $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT p.id, v.barcode, v.price FROM product p JOIN product_variant v ON v.product_id = p.id WHERE p.name = 'Copie g4 seul {$this->tag}'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['G4N-' . strtoupper($this->tag), 990]], array_map(static fn (array $r): array => [$r['barcode'], (int) $r['price']], $alone), 'article sans variante : une variante unique à son prix et à son code');
        $this->assertSame([false, false], [(bool) $copied['is_web'], (bool) $copied['is_pos']], 'masqué : ni en ligne ni en caisse (ajout 2)');
        $this->assertSame(1234.0, (float) $copied['purchase_price'], 'coût copié depuis le siège (ajout 1)');
        $this->assertStringStartsWith('imp-', (string) $copied['image']);
        $this->assertNotSame($photo, $copied['image'], 'la photo est recopiée, jamais partagée');
        $this->files[] = (string) $copied['image'];
        $this->assertFileExists(self::photos() . '/' . $copied['image']);
        $this->assertFileExists(self::photos() . '/' . $photo, 'l\'original reste');
        $variants = $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT id, stock_quantity, price FROM product_variant WHERE product_id = ' . (int) $copied['id'] . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([[0, 2500], [0, 2700]], array_map(static fn (array $v): array => [(int) $v['stock_quantity'], (int) $v['price']], $variants), 'prix copiés, stock jamais');
        // correspondance dans la base maître
        $links = $this->master->query("SELECT source_product_id, source_variant_id, target_product_id, target_variant_id FROM shop_group_item_links WHERE group_id = $groupId AND source_product_id = $pid ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([[$pid, null, (int) $copied['id'], null], [$pid, $variantIds[0], (int) $copied['id'], (int) $variants[0]['id']], [$pid, $variantIds[1], (int) $copied['id'], (int) $variants[1]['id']]],
            array_map(static fn (array $l): array => [(int) $l['source_product_id'], $l['source_variant_id'] === null ? null : (int) $l['source_variant_id'], (int) $l['target_product_id'], $l['target_variant_id'] === null ? null : (int) $l['target_variant_id']], $links));

        // rejeu : déjà copié, rien de neuf ; l'aperçu s'annule
        $again = $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 201);
        $this->assertSame([0, 1], [$again['import']['report']['products']['importable'], $again['import']['report']['products']['alreadyImported']]);
        $this->json('POST', '/api/gestion/groupe/copies/' . $again['id'] . '/cancel', $director, MV_TEST_TENANT_HOST, null, 200);

        // ajout 1 : d'une autre boutique que le siège, le coût n'est pas copié sans demande explicite
        $back = $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'articles' => [(int) $copied['id']]], 201);
        $this->assertFalse($this->json('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/confirm', $director, MV_TEST_TENANT_HOST, [], 202)['withCost']);
        $this->runCopy($back['import']['id'], MV_TEST_TENANT_CODE);
        $returned = $this->pdo(MV_TEST_TENANT_DB)->query("SELECT purchase_price, image FROM product WHERE name = 'Copie g4 {$this->tag}' AND id <> $pid")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($returned['purchase_price'], 'aucun coût d\'une autre boutique que le siège sans withCost');
        $this->files[] = (string) $returned['image'];
        // avant l'annulation (qui efface le fichier) : le catalogue figé chez le siège ne porte aucun coût de la boutique associée
        $frozen = array_values(array_filter($this->groupJson(MV_TEST_TENANT_DB)));
        $this->assertNotSame([], $frozen, 'le catalogue figé de la copie est lu');
        $this->assertSame([''], array_values(array_unique(array_map(static fn (array $p): string => (string) $p['variants'][0]['costRaw'], $frozen[0]))), 'le coût d\'une autre boutique que le siège n\'est jamais figé chez une autre');
        $this->json('POST', '/api/gestion/groupe/copies/' . $back['id'] . '/cancel', $director, MV_TEST_TENANT_HOST, null, 200);
        // … sauf demande explicite : `withCost: true` relit le coût dans la source à la confirmation
        $back2 = $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['source' => MV_TEST_TENANT2_CODE, 'target' => MV_TEST_TENANT_CODE, 'articles' => [(int) $copied['id']]], 201);
        $this->assertTrue($this->json('POST', '/api/gestion/groupe/copies/' . $back2['id'] . '/confirm', $director, MV_TEST_TENANT_HOST, ['withCost' => true], 202)['withCost']);
        $this->runCopy($back2['import']['id'], MV_TEST_TENANT_CODE);
        $returned2 = $this->pdo(MV_TEST_TENANT_DB)->query("SELECT purchase_price, image FROM product WHERE name = 'Copie g4 {$this->tag}' AND id <> $pid")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1234.0, (float) $returned2['purchase_price']);
        $this->files[] = (string) $returned2['image'];
        $this->json('POST', '/api/gestion/groupe/copies/' . $back2['id'] . '/cancel', $director, MV_TEST_TENANT_HOST, null, 200);

        // annulation de la première copie : l'article et ses liens partent
        // annulée par la boutique CIBLE elle-même, dans ses imports : articles et liens partent, et la copie le dit au directeur
        $cancelled = $this->json('POST', '/api/site/imports/' . $copy['import']['id'] . '/cancel', $shopAdmin, MV_TEST_TENANT2_HOST, null, 200);
        $this->assertSame(2, $cancelled['result']['cancel']['removed']);
        $this->assertNotNull($this->json('GET', '/api/gestion/groupe/copies/' . $copy['id'], $director, MV_TEST_TENANT_HOST, null, 200)['cancelledAt']);
        $this->assertSame($before, $shopProducts());
        $this->assertSame(0, (int) $this->master->query("SELECT COUNT(*) FROM shop_group_item_links WHERE target_tenant_code = '" . MV_TEST_TENANT2_CODE . "'")->fetchColumn());
        // la plus récente d'abord : les deux retours vers le siège, le rejeu, la première copie
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_CODE], array_column($this->json('GET', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, null, 200)['copies'], 'target'));
    }

    public function testDifferentCurrenciesAreRefusedAndAnEnteredDirectorIsNotTheDirector(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        [$pid] = $this->product(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $currency = (string) $this->pdo(MV_TEST_TENANT2_DB)->query('SELECT currency FROM entreprise ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo(MV_TEST_TENANT2_DB)->exec("UPDATE entreprise SET currency = '" . ($currency === 'EUR' ? 'USD' : 'EUR') . "'");
        try {
            $this->assertSame([409, 'COPY_CURRENCY_DIFFERS'], $this->call('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]]));
            $this->assertSame(0, (int) $this->pdo(MV_TEST_TENANT2_DB)->query("SELECT COUNT(*) FROM import_job WHERE source = 'group'")->fetchColumn(), 'aucune tâche créée');
        } finally {
            $this->pdo(MV_TEST_TENANT2_DB)->exec("UPDATE entreprise SET currency = " . ($currency === '' ? 'NULL' : "'$currency'"));
        }
        // le directeur entré dans une boutique : un opérateur, jamais l'outil de copie
        $entered = $this->json('POST', '/api/me/contexts/enter', $director, MV_TEST_TENANT_HOST, ['site' => MV_TEST_TENANT2_CODE], 200);
        $refused = $this->request('POST', '/api/gestion/groupe/copies', (string) $entered['token'], MV_TEST_TENANT2_HOST, ['target' => MV_TEST_TENANT_CODE]);
        $this->assertSame([403, 'rights'], [$refused->getStatusCode(), json_decode((string) $refused->getContent(), true)['reason'] ?? null], 'fermée par la garde du mode groupe');
        // la copie d'un AUTRE groupe : introuvable pour ce directeur
        $other = (int) $this->master->query("INSERT INTO shop_groups (code, name, head_tenant_code) VALUES ('autre-g4-{$this->tag}', 'Autre', 'autre-g4-{$this->tag}') RETURNING id")->fetchColumn();
        $foreign = (int) $this->master->query("INSERT INTO shop_group_copies (group_id, source_tenant_code, target_tenant_code, target_job_id) VALUES ($other, 'autre-g4-{$this->tag}', '" . MV_TEST_TENANT2_CODE . "', 999999) RETURNING id")->fetchColumn();
        foreach ([['GET', ''], ['POST', '/confirm'], ['POST', '/cancel']] as [$method, $suffix]) {
            $this->assertSame([404, 'COPY_NOT_FOUND'], $this->call($method, "/api/gestion/groupe/copies/$foreign$suffix", $director, MV_TEST_TENANT_HOST, $method === 'POST' ? [] : null));
        }
    }

    public function testAPreviewCancelledByTheShopIsCancelledForTheDirectorToo(): void
    {
        $this->group();
        $director = $this->account(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE, 'directeur', ['ROLE_ADMIN']);
        $shopAdmin = $this->account(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE, 'boutique', ['ROLE_ADMIN']);
        [$pid] = $this->product(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $copy = $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 201);
        // la boutique libère sa place d'import en annulant l'aperçu, dans ses imports
        $this->assertSame('cancelled', $this->json('POST', '/api/site/imports/' . $copy['import']['id'] . '/cancel', $shopAdmin, MV_TEST_TENANT2_HOST, null, 200)['status']);
        $this->assertNotNull($this->json('GET', '/api/gestion/groupe/copies/' . $copy['id'], $director, MV_TEST_TENANT_HOST, null, 200)['cancelledAt'], 'le directeur la voit annulée');
        $this->assertSame([409, 'COPY_NOT_READY'], $this->call('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/confirm', $director, MV_TEST_TENANT_HOST, []));
        $this->assertSame('cancelled', $this->json('POST', '/api/gestion/groupe/copies/' . $copy['id'] . '/cancel', $director, MV_TEST_TENANT_HOST, null, 200)['import']['status'], 'son annulation réussit, sans rien refaire');
        // la place est libre : une nouvelle copie s'analyse
        $this->json('POST', '/api/gestion/groupe/copies', $director, MV_TEST_TENANT_HOST, ['target' => MV_TEST_TENANT2_CODE, 'articles' => [$pid]], 201);
    }

    /** @return list<mixed> le catalogue figé des copies du groupe dans une base (tous les fichiers JSON de ses tâches `group`) */
    private function groupJson(string $db): array
    {
        $code = $db === MV_TEST_TENANT_DB ? MV_TEST_TENANT_CODE : MV_TEST_TENANT2_CODE;
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . $code;
        $out = [];
        foreach ($this->pdo($db)->query("SELECT stored_name FROM import_job WHERE source = 'group'")->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $path = $dir . '/' . basename((string) $name);
            $out[] = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        }

        return $out;
    }

    private function runCopy(int $jobId, string $code): void
    {
        $container = static::getContainer();
        $container->get(RunCatalogImportHandler::class)(new RunCatalogImportMessage($jobId, $code, 'essai'));
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
    }

    /** @return array{0: int, 1: list<int>, 2: string} l'article, ses deux variantes, sa photo */
    private function product(string $db, string $code): array
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $photo = 'g4-' . $this->tag . '.png';
        @mkdir(self::photos(), 0775, true);
        file_put_contents(self::photos() . '/' . $photo, FakeImportImages::png());
        $this->files[] = $photo;
        $category = (new Categories())->setName('Catégorie g4 ' . $this->tag);
        $em->persist($category);
        $product = (new Product())->setName('Copie g4 ' . $this->tag)->setDescription('<p>Un article</p>')->setMoreinformations(null)->setPrice(2500.0)->setCode('G4-' . $this->tag)
            ->setSlug('copie-g4-' . $this->tag)->setTags(null)->setQuantity(5)->setIsWeb(true)->setIsPos(true)->setIsLandingPage(false)->setSaleEnabled(true)->setRentalEnabled(false)
            ->setSubscriptionEnabled(false)->setIsbestseller(false)->setIsnewarrival(false)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)
            ->setCustomizable(false)->setRequiresShipping(true)->setCreatedAt(new \DateTimeImmutable())->setPurchasePrice(1234.0)->setImage($photo)
            ->setBarcode('G4P-' . strtoupper($this->tag));
        $product->addCategory($category);
        $product->addPicture((new ProductPicture())->setImageUrl($photo));
        $em->persist($product);
        $variants = [];
        foreach ([2500, 2700] as $price) {
            $variant = (new ProductVariant())->setStockQuantity(7)->setPrice($price);
            $product->addVariant($variant);
            $em->persist($variant);
            $variants[] = $variant;
        }
        // un article SANS variante (permis par la fiche) : il est copié avec une variante unique, qui porte son code-barres
        $single = (new Product())->setName('Copie g4 seul ' . $this->tag)->setDescription('')->setMoreinformations(null)->setPrice(990.0)->setCode('G4S-' . $this->tag)
            ->setSlug('copie-g4-seul-' . $this->tag)->setTags(null)->setQuantity(0)->setIsWeb(true)->setIsPos(true)->setIsLandingPage(false)->setSaleEnabled(true)->setRentalEnabled(false)
            ->setSubscriptionEnabled(false)->setIsbestseller(false)->setIsnewarrival(false)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)
            ->setCustomizable(false)->setRequiresShipping(true)->setCreatedAt(new \DateTimeImmutable())->setBarcode('G4N-' . strtoupper($this->tag));
        $em->persist($single);
        $em->flush();
        $this->single = (int) $single->getId();
        $ids = [(int) $product->getId(), array_map(static fn (ProductVariant $v): int => (int) $v->getId(), $variants), $photo];
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $ids;
    }

    private function group(): int
    {
        $this->pdo(MV_TEST_TENANT_DB)->exec('UPDATE entreprise SET is_multi_shop_active = TRUE');
        static::getContainer()->get(TenantCacheService::class)->invalidateSite(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        $id = (int) $this->master->query("INSERT INTO shop_groups (code, name, head_tenant_code) VALUES ('groupe-g4-{$this->tag}', 'Siège', '" . MV_TEST_TENANT_CODE . "') RETURNING id")->fetchColumn();
        $this->master->exec("INSERT INTO shop_group_sites (group_id, tenant_code, origin) VALUES ($id, '" . MV_TEST_TENANT_CODE . "', 'head'), ($id, '" . MV_TEST_TENANT2_CODE . "', 'created')");

        return $id;
    }

    /** @return array{0: int, 1: ?string} */
    private function call(string $method, string $path, string $token, string $host, ?array $body): array
    {
        $response = $this->request($method, $path, $token, $host, $body);
        $data = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($data) ? ($data['code'] ?? $data['error'] ?? null) : null];
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, string $token, string $host, ?array $body, int $status): array
    {
        $response = $this->request($method, $path, $token, $host, $body);
        $this->assertSame($status, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 600));

        return json_decode((string) $response->getContent(), true);
    }

    private function request(string $method, string $path, string $token, string $host, ?array $body): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . $host . $path, [], [], ['HTTP_X_TENANT_HOST' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '10.94.' . random_int(1, 254) . '.' . random_int(1, 254)], $body !== null ? (string) json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function account(string $db, string $code, string $name, array $roles): string
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $em = $provider->getEntityManager();
        $email = "g4-$name-{$this->tag}@example.invalid";
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Prénom')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO refresh_tokens (id, refresh_token, username, valid) VALUES (nextval('refresh_tokens_id_seq'), ?, ?, NOW() + INTERVAL '1 day')", ['g4-' . bin2hex(random_bytes(30)), $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $em->clear();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $token;
    }

    private function userId(string $db, string $name): int
    {
        return (int) $this->pdo($db)->query("SELECT id FROM \"user\" WHERE email = 'g4-$name-{$this->tag}@example.invalid'")->fetchColumn();
    }

    private static function photos(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/assets/uploads/products';
    }

    private function clean(): void
    {
        foreach ([MV_TEST_TENANT_DB, MV_TEST_TENANT2_DB] as $db) {
            $pdo = $this->pdo($db);
            $ids = array_map('intval', $pdo->query("SELECT id FROM product WHERE name LIKE 'Copie g4 %'")->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $list = implode(',', $ids);
                foreach (['product_picture', 'product_translation', 'related_product'] as $table) {
                    $pdo->exec("DELETE FROM $table WHERE product_id IN ($list)");
                }
                $pdo->exec("DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN ($list))");
                $pdo->exec("DELETE FROM product_variant WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM product_categories WHERE product_id IN ($list)");
                $pdo->exec("DELETE FROM product WHERE id IN ($list)");
            }
            $pdo->exec("DELETE FROM categories WHERE name LIKE 'Catégorie g4 %'");
            $pdo->exec("DELETE FROM import_item WHERE source = 'group'");
            $pdo->exec("DELETE FROM import_job WHERE source = 'group'");
            $pdo->exec("DELETE FROM notification_log WHERE kind = 'group_catalog_copied'");
            $pdo->exec("DELETE FROM refresh_tokens WHERE username LIKE 'g4-%@example.invalid'");
            $pdo->exec("DELETE FROM \"user\" WHERE email LIKE 'g4-%@example.invalid'");
            $pdo->exec('UPDATE entreprise SET is_multi_shop_active = FALSE');
        }
        $this->master->exec("DELETE FROM site_entries WHERE mode = 'group'");
        foreach (['shop_group_item_links', 'shop_group_copies', 'shop_group_events', 'shop_group_invitations', 'shop_group_company_requests', 'shop_group_transfer_events', 'shop_group_transfer_lines', 'shop_group_transfers', 'shop_group_sites', 'shop_groups'] as $table) {
            $this->master->exec("DELETE FROM $table");
        }
        foreach ([[MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE], [MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE]] as [$db, $code]) {
            static::getContainer()->get(TenantCacheService::class)->invalidateSite($code, $db);
        }
    }

    private function pdo(string $db): \PDO
    {
        return static::getContainer()->get(TenantDatabaseConnector::class)->pdo($db);
    }
}
