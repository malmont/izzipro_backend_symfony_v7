<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Codes-barres du catalogue dans l'application de gestion, lot CB-3 (15/11/2026, `docs/codes-barres.md` § 8) : routes sous
 * `/api/gestion/codes-barres`. Droits (anonyme, compte client, module de gestion éteint), recherche d'un code, codes manquants,
 * génération (jamais d'écrasement), pose et retrait d'un code saisi (chaque refus, dans les deux langues), étiquettes hors commande,
 * une ligne au journal du site par écriture (`source: gestion`), cache du site vidé.
 */
class GestionBarcodesApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const BASE = '/api/gestion/codes-barres';

    private KernelBrowser $client;
    private string $token = '';
    private string $adminEmail = '';
    private string $tag;
    /** @var array<string, array<int, string>> codes présents avant le test, par table */
    private array $codesBefore = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
        // La base de test est partagée : les codes déjà là sont mis de côté le temps du test, puis remis (tearDown)
        $db = $this->db();
        $this->codesBefore = [
            'product' => $db->fetchAllKeyValue('SELECT id, barcode FROM product WHERE barcode IS NOT NULL'),
            'product_variant' => $db->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE barcode IS NOT NULL'),
        ];
        $this->clearCodes();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $db = $this->db();
        $ids = $db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->tag]);
        if ($ids !== []) {
            $db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        $db->executeStatement('DELETE FROM size_translation WHERE size_id IN (SELECT id FROM size WHERE code = ?)', ['cb3-m-' . $this->tag]);
        $db->executeStatement('DELETE FROM size WHERE code = ?', ['cb3-m-' . $this->tag]);
        $this->clearCodes();
        foreach ($this->codesBefore as $table => $codes) {
            foreach ($codes as $id => $code) {
                $db->executeStatement("UPDATE $table SET barcode = ? WHERE id = ?", [$code, $id]);
            }
        }
        $this->endOfWrite();
        parent::tearDown();
    }

    public function testTheRoutesNeedAnAdministratorAndTheManagementModule(): void
    {
        $product = $this->product('Garde', 1000);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $product)])->getId();
        $routes = [
            ['GET', '/recherche?code=ABC', null],
            ['GET', '/manquants', null],
            ['POST', '/manquants/generer', []],
            ['POST', "/variantes/$variant/generer", []],
            ['POST', "/articles/$product/generer", []],
            ['PUT', "/variantes/$variant", ['barcode' => "GARDE-$this->tag"]],
            ['PUT', "/articles/$product", ['barcode' => "GARDE-A-$this->tag"]],
            ['POST', '/etiquettes/pdf', ['labels' => [['variantId' => $variant, 'copies' => 1]]]],
        ];
        $statuses = function () use ($routes): array {
            $seen = [];
            foreach ($routes as [$method, $path, $body]) {
                $this->request($method, self::BASE . $path, $body);
                $seen[] = $this->status();
            }

            return $seen;
        };
        $admin = $this->token;

        // Anonyme, puis compte client : rien n'est servi, rien n'est écrit
        $this->token = '';
        $this->assertSame(array_fill(0, 8, 401), $statuses(), 'anonyme');
        $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
        $this->assertSame(array_fill(0, 8, 403), $statuses(), 'compte client');

        // Module de gestion éteint sur une plateforme en mode réel : même refus que les autres routes de la gestion, même pour l'administrateur
        $this->token = $admin;
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->setManagement(false);
        $this->assertSame(array_fill(0, 8, 403), $statuses(), 'module de gestion éteint');
        $this->assertSame(['MODULE_INACTIVE', 'management'], [$this->body()['error'] ?? null, $this->body()['module'] ?? null]);
        $this->assertSame([null, null], [$this->codeOf('product_variant', $variant), $this->codeOf('product', $product)], 'aucun de ces appels n\'a écrit');
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM product_variant WHERE barcode LIKE '29%'"), 'ni généré');

        // Module allumé : servi
        $this->setManagement(true);
        $this->request('GET', self::BASE . '/manquants');
        $this->assertSame(200, $this->status(), (string) $this->client->getResponse()->getContent());
        $this->setManagement(false);
    }

    public function testSearchingAssigningGeneratingAndPrintingFromTheManagementApp(): void
    {
        $s = strtoupper($this->tag);
        $cap = $this->product('Casquette', 2900, isWeb: true);
        $size = $this->create(Size::class, ['name' => 'M', 'code' => 'cb3-m-' . $this->tag]);
        $v1 = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $cap), 'size' => $size, 'price' => 4500, 'stockQuantity' => 5])->getId();
        $v2 = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $cap), 'stockQuantity' => 3])->getId();
        $bottle = $this->product('Gourde', 1500, isPos: true);
        $solo = $this->product('Sac', 0);
        $vs = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $solo), 'stockQuantity' => 1])->getId();
        $this->endOfWrite();
        $audit = fn (): int => (int) $this->db()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'catalog_barcode'");
        $lastAudit = function (): array {
            $line = $this->db()->fetchAssociative("SELECT user_identifier, resource_id, action, before_state, after_state FROM content_audit_log WHERE resource = 'catalog_barcode' ORDER BY id DESC LIMIT 1");

            return [$line['user_identifier'], $line['resource_id'], $line['action'], json_decode((string) $line['before_state'], true), json_decode((string) $line['after_state'], true)];
        };
        $auditBefore = $audit();
        $publicSearch = function (string $code): array {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/products/by-category?locale=fr&limit=1&pageSize=1&barcode=' . rawurlencode($code), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(200, $this->status());

            return array_column($this->body()['data'], 'matchedVariantId', 'id');
        };

        // 1. Chercher : un code est attendu ; un code que rien ne porte dit s'il pourrait être posé
        foreach (['/recherche', '/recherche?code=', '/recherche?code=%20%20', '/recherche?code[]=A'] as $path) {
            $this->request('GET', self::BASE . $path);
            $this->assertSame([422, 'VALIDATION_FAILED', ['code' => 'REQUIRED']], [$this->status(), $this->body()['error'], $this->body()['fields']], $path);
        }
        $none = $this->get('/recherche?code=' . rawurlencode(" var-m-$this->tag "));
        $this->assertSame(['code' => "VAR-M-$s", 'found' => false, 'refusal' => null, 'sheet' => null], array_intersect_key($none, array_flip(['code', 'found', 'refusal', 'sheet'])));
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) $none['currency']);
        $this->assertSame([false, 'BARCODE_RESERVED'], [$this->get('/recherche?code=iz-123')['found'], $this->get('/recherche?code=iz-123')['refusal']]);
        $this->assertSame('BARCODE_INVALID', $this->get('/recherche?code=' . rawurlencode('a b'))['refusal']);
        $this->assertSame([], $publicSearch("VAR-M-$s"), 'la recherche de la caisse ne trouve rien non plus (réponse vide mise en cache)');

        // 2. Poser un code scanné sur une variante : forme canonique, fiche à jour, ligne au journal, cache vidé
        $set = $this->put("/variantes/$v1", ['barcode' => " var-m-$this->tag "], 200);
        $sheet = ['type' => 'variant', 'productId' => $cap, 'variantId' => $v1, 'name' => "Casquette $this->tag", 'variantLabel' => 'M', 'barcode' => "VAR-M-$s", 'symbology' => 'code128',
            'isPos' => false, 'isWeb' => true, 'stock' => 5, 'priceCents' => 4500, 'priceIncludesTax' => false];
        $this->assertSame(['barcode' => "VAR-M-$s", 'changed' => true, 'warning' => null, 'sheet' => $sheet], $set);
        $this->assertSame("VAR-M-$s", $this->codeOf('product_variant', $v1));
        $this->assertSame($auditBefore + 1, $audit());
        $this->assertSame([$this->adminEmail, "variant-$v1", 'set', ['barcode' => null], ['source' => 'gestion', 'barcode' => "VAR-M-$s", 'type' => 'variant', 'id' => $v1]], $lastAudit(), 'qui, quoi, depuis la gestion');
        $found = $this->get('/recherche?code=' . rawurlencode(strtolower("VAR-M-$s")));
        $this->assertSame([true, null, $sheet], [$found['found'], $found['refusal'], $found['sheet']]);
        $this->assertSame([$cap => $v1], $publicSearch("VAR-M-$s"), 'cache du site vidé : la caisse trouve aussitôt la variante, même sur un article non vendu en caisse');
        // Le même code de nouveau : rien ne change, pas de ligne au journal
        $this->assertSame(["VAR-M-$s", false, null], array_slice(array_values($this->put("/variantes/$v1", ['barcode' => "VAR-M-$s"], 200)), 0, 3));
        $this->assertSame($auditBefore + 1, $audit());

        // 3. Refus, avec le code d'erreur du lot CB-1 et ce qui porte déjà le code, dans la langue demandée
        $used = $this->put("/variantes/$v2", ['barcode' => "var-m-$s"], 409);
        $this->assertSame(['BARCODE_ALREADY_USED', 'BARCODE_ALREADY_USED', "Ce code-barres est déjà celui de « Casquette $this->tag, M »."], [$used['error'], $used['code'], $used['message']]);
        $this->assertSame(['type' => 'variant', 'id' => $v1, 'productId' => $cap, 'label' => "Casquette $this->tag, M", 'variantId' => $v1], $used['holder']);
        $this->assertSame("This barcode already belongs to \"Casquette $this->tag, M\".", $this->put("/variantes/$v2?locale=en", ['barcode' => "VAR-M-$s"], 409)['message']);
        $this->assertSame('BARCODE_ALREADY_USED', $this->put("/articles/$cap", ['barcode' => "VAR-M-$s"], 409)['error'], 'pas même l\'article de la variante');
        $this->assertSame(['BARCODE_RESERVED', 422], [$this->put("/articles/$bottle", ['barcode' => 'IZ-ABC'], 422)['error'], $this->status()]);
        $invalid = $this->put("/articles/$bottle?locale=en", ['barcode' => 'a b'], 422);
        $this->assertSame(['BARCODE_INVALID', 'Barcode refused: 1 to 64 characters, unaccented letters, digits and - . _ / only, no spaces.'], [$invalid['error'], $invalid['message']]);
        $this->assertSame(['barcode' => 'REQUIRED'], $this->put("/articles/$bottle", [], 422)['fields']);
        $this->assertSame(['barcode' => 'MUST_BE_STRING'], $this->put("/articles/$bottle", ['barcode' => 12], 422)['fields']);
        $this->client->request('PUT', self::BASE . "/articles/$bottle", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], '{');
        $this->assertSame([422, ['body' => 'INVALID_JSON']], [$this->status(), $this->body()['fields']]);
        $notFound = $this->put('/variantes/999999999', ['barcode' => 'X'], 404);
        $this->assertSame(['VARIANT_NOT_FOUND', 'Variante introuvable.'], [$notFound['error'], $notFound['message']]);
        $this->assertSame('PRODUCT_NOT_FOUND', $this->put('/articles/999999999', ['barcode' => 'X'], 404)['error']);
        $this->assertSame(['replace' => 'MUST_BE_BOOLEAN'], $this->put("/variantes/$v1", ['barcode' => "AUTRE-$s", 'replace' => 'oui'], 422)['fields']);
        $this->assertSame([null, null, $auditBefore + 1], [$this->codeOf('product_variant', $v2), $this->codeOf('product', $bottle), $audit()], 'aucun refus n\'a écrit');

        // 3 bis. Garde-fou : une fiche qui porte déjà un AUTRE code ne le perd pas sur un scan distrait ; il faut le confirmer
        foreach ([[], ['replace' => false]] as $without) {
            $kept = $this->put("/variantes/$v1", ['barcode' => "autre-$s"] + $without, 409);
            $this->assertSame(['BARCODE_ALREADY_SET', "VAR-M-$s", "Cette fiche porte déjà le code-barres « VAR-M-$s » : confirmez pour le remplacer ou le retirer."], [$kept['error'], $kept['current'], $kept['message']]);
        }
        $this->assertSame(["This item already has the barcode \"VAR-M-$s\": confirm to replace or remove it.", "VAR-M-$s"], array_values(array_intersect_key($this->put("/variantes/$v1?locale=en", ['barcode' => ''], 409), array_flip(['message', 'current']))), 'retirer se confirme aussi');
        // … le code proposé est jugé d'abord : on ne demande pas de confirmer un code refusé
        $this->assertSame('BARCODE_INVALID', $this->put("/variantes/$v1", ['barcode' => 'a b', 'replace' => true], 422)['error']);
        $this->assertSame('BARCODE_INVALID', $this->put("/variantes/$v1", ['barcode' => 'a b'], 422)['error']);
        $this->assertSame(["VAR-M-$s", $auditBefore + 1], [$this->codeOf('product_variant', $v1), $audit()], 'le code du début est toujours là, rien au journal');
        $replaced = $this->put("/variantes/$v1", ['barcode' => "autre-$s", 'replace' => true], 200);
        $this->assertSame(["AUTRE-$s", true, "AUTRE-$s"], [$replaced['barcode'], $replaced['changed'], $replaced['sheet']['barcode']]);
        $this->assertSame([$this->adminEmail, "variant-$v1", 'set', ['barcode' => "VAR-M-$s"], ['source' => 'gestion', 'barcode' => "AUTRE-$s", 'type' => 'variant', 'id' => $v1]], $lastAudit(), 'le journal garde le code d\'avant');
        $this->assertSame([], $publicSearch("VAR-M-$s"), 'l\'ancien code ne désigne plus rien');
        $this->assertSame("VAR-M-$s", $this->put("/variantes/$v1", ['barcode' => "VAR-M-$s", 'replace' => true], 200)['barcode']);
        $this->assertSame($auditBefore + 3, $audit());

        // 4. Treize chiffres à la clé fausse : accepté, avec l'avertissement ; puis retrait du code
        $warned = $this->put("/articles/$bottle?locale=en", ['barcode' => '4006381333932'], 200);
        $this->assertSame(['4006381333932', true, ['code' => 'BARCODE_CHECK_DIGIT', 'message' => 'This 13-digit code does not have a valid EAN-13 check digit: it is accepted and will be printed as Code 128.']], [$warned['barcode'], $warned['changed'], $warned['warning']]);
        $this->assertSame(['product', $bottle, null, null, 'code128', true, false, null, 1500], [$warned['sheet']['type'], $warned['sheet']['productId'], $warned['sheet']['variantId'], $warned['sheet']['variantLabel'], $warned['sheet']['symbology'],
            $warned['sheet']['isPos'], $warned['sheet']['isWeb'], $warned['sheet']['stock'], $warned['sheet']['priceCents']]);
        $unconfirmed = $this->put("/articles/$bottle", ['barcode' => ''], 409);
        $this->assertSame(['BARCODE_ALREADY_SET', '4006381333932'], [$unconfirmed['error'], $unconfirmed['current']], 'retirer sans confirmer : refusé');
        $this->assertSame('4006381333932', $this->codeOf('product', $bottle));
        foreach (['', null] as $i => $empty) {
            $removed = $this->put("/articles/$bottle", ['barcode' => $empty, 'replace' => true], 200);
            $this->assertSame([null, $i === 0, null, null], [$removed['barcode'], $removed['changed'], $removed['warning'], $removed['sheet']['barcode']]);
        }
        $this->assertSame([null, false], [$this->codeOf('product', $bottle), $this->put("/articles/$bottle", ['barcode' => null], 200)['changed']], 'rien à retirer : rien à confirmer');
        $this->assertSame([$this->adminEmail, "product-$bottle", 'remove', ['barcode' => '4006381333932'], ['source' => 'gestion', 'barcode' => null, 'type' => 'product', 'id' => $bottle]], $lastAudit());
        $this->assertSame($auditBefore + 5, $audit(), 'une ligne par écriture : poser et retirer sur l\'article');

        // 5. Générer le code d'une fiche : seulement si elle n'en a pas ; jamais sur un article à variantes
        $generated = $this->post("/variantes/$v2/generer", [], 200);
        $this->assertTrue(CatalogBarcode::isEan13($generated['barcode']) && str_starts_with($generated['barcode'], '29'), $generated['barcode']);
        $this->assertSame([$generated['barcode'], 'ean13', 3, 2900, null], [$generated['sheet']['barcode'], $generated['sheet']['symbology'], $generated['sheet']['stock'], $generated['sheet']['priceCents'], $generated['sheet']['variantLabel']]);
        $this->assertSame([$this->adminEmail, "variant-$v2", 'generate', null, ['source' => 'gestion', 'barcode' => $generated['barcode'], 'type' => 'variant', 'id' => $v2]], $lastAudit());
        foreach ([$v2, $v1] as $id) {
            $again = $this->post("/variantes/$id/generer?locale=en", [], 409);
            $this->assertSame(['BARCODE_ALREADY_SET', 'This item already has a barcode: an entered code is never replaced by a generated one.', $id === $v1 ? "VAR-M-$s" : $generated['barcode']], [$again['error'], $again['message'], $again['current']]);
        }
        $this->assertSame(["VAR-M-$s", $generated['barcode']], [$this->codeOf('product_variant', $v1), $this->codeOf('product_variant', $v2)], 'jamais d\'écrasement');
        $this->assertSame('BARCODE_HAS_VARIANTS', $this->post("/articles/$cap/generer", [], 409)['error']);
        $this->assertNull($this->codeOf('product', $cap));
        $bottleCode = $this->post("/articles/$bottle/generer", [], 200)['barcode'];
        $this->assertSame([$bottleCode, true], [$this->codeOf('product', $bottle), CatalogBarcode::isEan13($bottleCode)]);
        $this->assertSame('VARIANT_NOT_FOUND', $this->post('/variantes/999999999/generer', [], 404)['error']);
        $this->assertSame('PRODUCT_NOT_FOUND', $this->post('/articles/999999999/generer', [], 404)['error']);
        $this->assertSame($auditBefore + 7, $audit());

        // 6. Compter puis générer ce qui manque (toute la base de test, sous le plafond) : jamais un code remplacé
        $missing = $this->get('/manquants');
        $count = static::getContainer()->get(CatalogBarcodeService::class)->missingCount();
        $this->assertSame($count + ['limit' => 500], $missing);
        $this->assertGreaterThanOrEqual(1, $missing['variants'], 'au moins la variante du sac');
        $this->assertLessThanOrEqual(500, $missing['total'], 'la base de test tient sous le plafond');
        $run = $this->post('/manquants/generer', [], 200);
        $this->assertSame(['written' => $missing['total'], 'variants' => $missing['variants'], 'products' => $missing['products'], 'remaining' => 0, 'limit' => 500], $run);
        [$who, $resourceId, $action, , $after] = $lastAudit();
        $this->assertSame([$this->adminEmail, null, 'generate', 'gestion', 'catalog', $missing['total']], [$who, $resourceId, $action, $after['source'], $after['scope'], $after['written']]);
        $this->assertSame(["VAR-M-$s", $generated['barcode'], $bottleCode, null], [$this->codeOf('product_variant', $v1), $this->codeOf('product_variant', $v2), $this->codeOf('product', $bottle), $this->codeOf('product', $cap)]);
        $soloCode = (string) $this->codeOf('product_variant', $vs);
        $this->assertTrue(CatalogBarcode::isEan13($soloCode));
        $this->assertSame(['written' => 0, 'variants' => 0, 'products' => 0, 'remaining' => 0, 'limit' => 500], $this->post('/manquants/generer', [], 200));
        $this->assertSame(['variants' => 0, 'products' => 0, 'total' => 0, 'limit' => 500], $this->get('/manquants'));
        $this->assertSame($auditBefore + 8, $audit(), 'un lancement sans rien à écrire ne laisse pas de ligne');

        // 7. Étiquettes hors commande : variantes et articles sans variante, même planche que la réception
        $pdf = function (array $body, string $query = ''): void {
            $this->request('POST', self::BASE . '/etiquettes/pdf' . $query, $body);
        };
        $pdf(['labels' => [['variantId' => $v1, 'copies' => 2], ['productId' => $bottle, 'copies' => 1]], 'showPrice' => true], '?locale=en');
        $this->assertSame([200, 'application/pdf', '3'], [$this->status(), $this->client->getResponse()->headers->get('Content-Type'), $this->client->getResponse()->headers->get('X-Labels-Count')], (string) $this->client->getResponse()->getContent());
        $this->assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        $fields = function (array $body) use ($pdf): array {
            $pdf($body);
            $this->assertSame([422, 'VALIDATION_FAILED'], [$this->status(), $this->body()['error']]);

            return $this->body()['fields'];
        };
        $this->assertSame(['labels' => 'REQUIRED'], $fields([]));
        $this->assertSame(['labels' => 'REQUIRED'], $fields(['labels' => []]));
        $this->assertSame(['labels[0]' => 'ONE_TARGET_REQUIRED', 'labels[1]' => 'ONE_TARGET_REQUIRED', 'labels[2].variantId' => 'MUST_BE_POSITIVE', 'labels[3].productId' => 'MUST_BE_POSITIVE', 'labels[4].copies' => 'MUST_BE_POSITIVE', 'labels[6]' => 'DUPLICATE', 'showPrice' => 'MUST_BE_BOOLEAN'],
            $fields(['labels' => [['variantId' => $v1, 'productId' => $bottle, 'copies' => 1], ['copies' => 1], ['variantId' => '12', 'copies' => 1], ['productId' => 0, 'copies' => 1], ['productId' => $bottle, 'copies' => 0],
                ['variantId' => $v1, 'copies' => 1], ['variantId' => $v1, 'copies' => 2]], 'showPrice' => 'oui']));
        $pdf(['labels' => [['variantId' => $v1, 'copies' => 500], ['productId' => $bottle, 'copies' => 1]]]);
        $this->assertSame([422, 'LABELS_LIMIT_EXCEEDED', 500, 501], [$this->status(), $this->body()['error'], $this->body()['max'], $this->body()['requested']]);
        // Fiche inconnue, ou article à variantes (son étiquette se fait par variante)
        $pdf(['labels' => [['variantId' => 999999999, 'copies' => 1], ['productId' => $cap, 'copies' => 1], ['variantId' => $v1, 'copies' => 1]]]);
        $this->assertSame([422, 'SHEET_NOT_FOUND', [999999999], [$cap]], [$this->status(), $this->body()['error'], $this->body()['variantIds'], $this->body()['productIds']]);
        // Une fiche sans code : refus, on génère ou on la retire de la sélection. La variante unique d'un article qui porte un code s'imprime avec le code de l'article
        $this->put("/variantes/$vs", ['barcode' => '', 'replace' => true], 200);
        $pdf(['labels' => [['variantId' => $vs, 'copies' => 1], ['variantId' => $v1, 'copies' => 1]]], '?locale=en');
        $this->assertSame([409, 'BARCODE_MISSING', [$vs], [], 'Some selected items have no barcode: generate them first, or remove them.'], [$this->status(), $this->body()['error'], $this->body()['variantIds'], $this->body()['productIds'], $this->body()['message']]);
        $this->put("/articles/$solo", ['barcode' => "SAC-$s"], 200);
        $pdf(['labels' => [['variantId' => $vs, 'copies' => 4]]]);
        $this->assertSame([200, '4'], [$this->status(), $this->client->getResponse()->headers->get('X-Labels-Count')]);
        // Le sac (prix à zéro) : pas de prix dans sa fiche
        $bag = $this->get('/recherche?code=' . "SAC-$s")['sheet'];
        $this->assertSame(['product', $solo, null, null, 1, false, false], [$bag['type'], $bag['productId'], $bag['variantId'], $bag['priceCents'], $bag['stock'], $bag['isPos'], $bag['isWeb']], 'l\'article : stock de ses variantes, pas de prix à zéro');
        $this->assertSame("VAR-M-$s", $this->codeOf('product_variant', $v1), 'le code saisi au début est toujours là');
    }

    private function product(string $name, int $price, bool $isWeb = false, bool $isPos = false): int
    {
        $db = $this->db();
        $slug = 'cb3-' . bin2hex(random_bytes(4));
        $db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, :p, false, 0, NOW(), :s, 'standard', :w, :c)",
            ['n' => "$name $this->tag", 'p' => $price, 's' => $slug, 'w' => $isWeb ? 'true' : 'false', 'c' => $isPos ? 'true' : 'false']);

        return (int) $db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
    }

    private function codeOf(string $table, int $id): ?string
    {
        $code = $this->db()->fetchOne("SELECT barcode FROM $table WHERE id = ?", [$id]);

        return $code === false ? null : $code;
    }

    private function clearCodes(): void
    {
        $this->db()->executeStatement('UPDATE product SET barcode = NULL WHERE barcode IS NOT NULL');
        $this->db()->executeStatement('UPDATE product_variant SET barcode = NULL WHERE barcode IS NOT NULL');
    }

    /** Écriture Doctrine (vide le cache des indicateurs de modules) */
    private function setManagement(bool $on): void
    {
        $em = $this->em();
        $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC'])->setIsManagementAppActive($on);
        $em->flush();
        $this->endOfWrite();
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]), $body === null ? null : json_encode($body, JSON_FORCE_OBJECT * (int) ($body === [])));
    }

    private function get(string $path): array
    {
        $this->request('GET', self::BASE . $path);
        $this->assertSame(200, $this->status(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function post(string $path, array $body, int $expected): array
    {
        $this->request('POST', self::BASE . $path, $body);
        $this->assertSame($expected, $this->status(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function put(string $path, array $body, int $expected): array
    {
        $this->request('PUT', self::BASE . $path, $body);
        $this->assertSame($expected, $this->status(), $path . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function tokenFor(array $roles): string
    {
        $email = 'gestion-cb3-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('CB3')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $this->adminEmail = $email;
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
