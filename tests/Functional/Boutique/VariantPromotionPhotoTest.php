<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot VAR (promotion par variante, contrat validé le 11/11/2026) : photo de non-régression des prix de vente, prise AVANT tout code du lot.
 *
 * Sur un catalogue qui couvre les cas (variante à prix propre ou non, promotion d'article en cours / à venir / passée, personnalisation, prix
 * manuel F7, remise F1, HT et TTC, catégorie fiscale), la photo réunit les réponses complètes : devis de la boutique, devis de la caisse,
 * intent Stripe, vente en caisse (create-multi-payment) et sa commande relue (détail et liste), favoris de la caisse, pack hors ligne
 * (contenu et ETag), fiches produit. Seules les valeurs qui changent d'une exécution à l'autre sont masquées (identifiants de commande,
 * horodatages, dates relatives du jeu d'exemple, secrets de paiement).
 *
 * La photo de référence (fixtures/variant-promotion-photo.json) a été enregistrée avec le code d'avant le lot :
 * VAR_PHOTO_RECORD=1 l'écrit, sinon le test la compare au tableau près.
 */
class VariantPromotionPhotoTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const ORDER_SOURCE_POS = 2;
    private const REFERENCE = __DIR__ . '/fixtures/variant-promotion-photo.json';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $token = null;
    private int $register;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeCheckoutPaymentGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-photo-variante@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD', pos_timezone = NULL, pos_discrepancy_threshold = 0, pos_cash_rounding_step = NULL, pos_approval_refund = 0, pos_approval_withdrawal = NULL,
            pos_approval_fund_withdrawal = NULL, pos_approval_drawer_open = false, pos_approval_close_without_count = false, pos_terminal_header_required = false, pos_pay_cash = true,
            pos_pay_declared_card = true, pos_pay_paypal = true, pos_pay_multi = true, pos_declared_card_blocked_with_terminal = false, prices_include_tax = false,
            pos_offline_enabled = true, pos_favorites_enabled = true, pos_favorites_mode = 'both', pos_favorites_days = 30, pos_favorites_grid_size = 500,
            pos_custom_item_enabled = false, pos_price_override_enabled = true, pos_price_override_max_amount = NULL, pos_price_override_reason_required = true, pos_price_override_reasons = NULL");
        $this->db->executeStatement('UPDATE caisse SET is_open = false, closed_at = NOW() WHERE is_open = true');
        $this->db->executeStatement('UPDATE cash_register SET is_active = (id = (SELECT MIN(id) FROM cash_register)), terminal_enabled = false, stripe_reader_id = NULL, receipt_prefix = NULL');
        $this->db->executeStatement('DELETE FROM pos_favorite');
        $this->db->executeStatement('UPDATE product SET is_pos = true, tax_category = NULL');
        // Catalogue de la photo : prix fixés ici (indépendants des autres tests)
        $this->db->executeStatement("UPDATE product SET price = 3500, special_price = NULL WHERE code = 'DEMO-TSHIRT'");
        $this->db->executeStatement("UPDATE product SET price = 6900, special_price = 4900, special_price_from = NOW() - INTERVAL '7 days', special_price_to = NOW() + INTERVAL '30 days' WHERE code = 'DEMO-SWEAT'");
        $this->db->executeStatement("UPDATE product SET price = 3800, special_price = 2900, special_price_from = NOW() - INTERVAL '60 days', special_price_to = NOW() - INTERVAL '30 days', tax_category = 'reduced' WHERE code = 'DEMO-CAFE'");
        $this->db->executeStatement("UPDATE product SET price = 2900, special_price = 1900, special_price_from = NOW() + INTERVAL '5 days', special_price_to = NOW() + INTERVAL '20 days' WHERE code = 'DEMO-CASQUETTE'");
        $this->db->executeStatement("UPDATE product SET price = 3200, special_price = NULL WHERE code = 'DEMO-GOURDE'");
        $this->db->executeStatement("UPDATE product_variant SET stock_quantity = 500 WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE', 'DEMO-GOURDE'))");
        // Prix propres des variantes remis à l'état du jeu d'exemple (d'autres tests les modifient) : seul le t-shirt noir XL a le sien (3 900)
        $this->db->executeStatement("UPDATE product_variant SET price = NULL WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE', 'DEMO-GOURDE'))");
        $this->db->executeStatement("UPDATE product_variant SET price = 3900 WHERE id = (SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id JOIN size sz ON sz.id = v.size_id JOIN color co ON co.id = v.color_id
            WHERE p.code = 'DEMO-TSHIRT' AND sz.name = 'XL' AND co.name = 'Noir' ORDER BY v.id LIMIT 1)");
        $this->register = (int) $this->db->fetchOne('SELECT MIN(id) FROM cash_register');
        $provider->getEntityManager()->clear(); // la première requête relit la base (sinon entités du seeder, d'avant les UPDATE ci-dessus)
    }

    public function testSalePricesMatchTheReferencePhoto(): void
    {
        $photo = $this->photo();
        if (getenv('VAR_PHOTO_RECORD') === '1') {
            if (!is_dir(dirname(self::REFERENCE))) {
                mkdir(dirname(self::REFERENCE), 0775, true);
            }
            file_put_contents(self::REFERENCE, json_encode($this->normalize($photo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->markTestSkipped('photo de référence enregistrée');
        }
        $this->assertFileExists(self::REFERENCE);
        $this->skipUnlessFreshCatalog();
        $this->assertSame($this->normalize(json_decode((string) file_get_contents(self::REFERENCE), true)), json_decode(json_encode($this->normalize($photo)), true), 'photo des prix de vente identique au tableau près'); // référence renormalisée : masques ajoutés depuis
    }

    /**
     * Contrat VAR, non-régression : (a) interrupteur éteint avec des promotions de variante en base, (b) allumé sans promotion, (c) allumé avec une
     * promotion sur une autre variante (hors photo) : même photo que la référence d'avant le lot (identifiants de commande masqués des deux côtés,
     * plusieurs ventes dans le même test).
     */
    public function testPhotoUnchangedWhenOffWithPromotionsOnWithoutOrElsewhere(): void
    {
        // Photo de base de cette exécution (interrupteur éteint, aucune promotion de variante) ; égale à la référence d'avant le lot si la base est fraîche
        $this->db->executeStatement('DELETE FROM variant_promotion');
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = false');
        $reference = $this->masked(json_decode(json_encode($this->normalize($this->photo())), true));
        if ($this->freshCatalog()) {
            $this->assertSame($this->masked(json_decode((string) file_get_contents(self::REFERENCE), true)), $reference, 'base de cette exécution = référence d\'avant le lot');
        }
        $variant = fn (string $code) => (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
        $promote = function (int $variantId, int $price): void {
            $this->db->executeStatement("INSERT INTO variant_promotion (product_variant_id, percent, price, base_price, start_date, end_date, starts_at, ends_at, created_at)
                VALUES (?, 20, ?, ?, CURRENT_DATE - 1, CURRENT_DATE + 10, NOW() - INTERVAL '1 day', NOW() + INTERVAL '10 days', NOW())", [$variantId, $price, $price + 500]);
        };
        // (a) éteint, promotions en base sur les variantes de la photo
        foreach ([$this->variantWithPrice('DEMO-TSHIRT', true), $this->variantWithPrice('DEMO-TSHIRT', false), $variant('DEMO-SWEAT'), $variant('DEMO-CASQUETTE'), $variant('DEMO-GOURDE')] as $id) {
            $promote($id, 1000);
        }
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = false');
        $this->assertSame($reference, $this->masked(json_decode(json_encode($this->normalize($this->photo())), true)), '(a) éteint, promotions en base');
        // (b) allumé, aucune promotion de variante
        $this->db->executeStatement('DELETE FROM variant_promotion');
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = true');
        $this->assertSame($reference, $this->masked(json_decode(json_encode($this->normalize($this->photo())), true)), '(b) allumé, sans promotion');
        // (c) allumé, promotion sur une variante hors photo
        $promote($variant('DEMO-SAC'), 1000); // article hors photo (ni vendu ni fiche) ; le pack v3 ne porte aucune promotion de variante
        $this->assertSame($reference, $this->masked(json_decode(json_encode($this->normalize($this->photo())), true)), '(c) allumé, promotion ailleurs');
        $this->db->executeStatement('DELETE FROM variant_promotion');
        $this->db->executeStatement('UPDATE entreprise SET variant_promotions_enabled = false');
    }

    /**
     * Base fraîche (jeu d'exemple tel que créé par le seeder, comme lors de l'enregistrement de la référence) : dans la suite complète, d'autres
     * tests créent des articles avant (identifiants décalés) et en renomment ; la référence d'avant le lot ne se compare alors qu'en exécution isolée
     * (`phpunit tests/Functional/Boutique/VariantPromotionPhotoTest.php`), (a), (b) et (c) restant comparés à la photo de base de l'exécution.
     */
    private function freshCatalog(): bool
    {
        $reference = json_decode((string) file_get_contents(self::REFERENCE), true);
        $line = $reference['cartQuote']['body']['lines'][0] ?? [];
        $current = $this->db->fetchAssociative("SELECT v.id AS variant, p.name FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = ? AND p.code = 'DEMO-TSHIRT'", [(int) ($line['productVariantId'] ?? 0)]);

        return $current !== false && $current['name'] === ($line['name'] ?? null)
            && (int) $this->db->fetchOne("SELECT id FROM product WHERE code = 'DEMO-CASQUETTE'") === (int) ($reference['cartQuote']['body']['lines'][4]['productId'] ?? 0)
            && $this->db->fetchOne("SELECT name FROM product WHERE code = 'DEMO-CASQUETTE'") === ($reference['cartQuote']['body']['lines'][4]['name'] ?? null);
    }

    private function skipUnlessFreshCatalog(): void
    {
        if (!$this->freshCatalog()) {
            $this->markTestSkipped('Base partagée modifiée par d\'autres tests (identifiants ou noms du jeu d\'exemple) : la photo de référence d\'avant le lot se vérifie en exécution isolée.');
        }
    }

    /** Identifiants des commandes et de leurs lignes masqués (plusieurs ventes dans un même test) */
    private function masked(array $photo): array
    {
        $mask = function (mixed $v) use (&$mask): mixed {
            if (!is_array($v)) {
                return $v;
            }
            foreach ($v as $k => $item) {
                $v[$k] = in_array($k, ['id', 'orderItemId', 'orderAssociatedId', 'barcode', 'receiptReference', 'provisionalNumber', 'receiptUrl'], true) && !is_array($item) ? '<id>' : $mask($item);
            }

            return $v;
        };
        foreach (['posSale', 'orderDetail', 'orderInPosList', 'orderInAdminList'] as $section) {
            $photo[$section] = $mask($photo[$section]);
        }

        return $photo;
    }

    // ----- photo -----

    /** @return array<string, mixed> réponses brutes (statut, corps, en-têtes utiles) */
    private function photo(): array
    {
        $v = [
            'tshirtOwn' => $this->variantWithPrice('DEMO-TSHIRT', true), 'tshirtPlain' => $this->variantWithPrice('DEMO-TSHIRT', false),
            'sweat' => $this->firstVariant('DEMO-SWEAT'), 'cafe' => $this->firstVariant('DEMO-CAFE'), 'cap' => $this->firstVariant('DEMO-CASQUETTE'), 'gourde' => $this->firstVariant('DEMO-GOURDE'),
        ];
        $combination = (int) $this->db->fetchOne('SELECT id FROM product_customization_image WHERE product_variant_id = ? ORDER BY id LIMIT 1', [$v['gourde']]);
        $address = ['country' => 'CA', 'province' => 'QC', 'city' => 'Montréal', 'postalCode' => 'H2X 1Y4'];
        $sale = [
            ['productVariantId' => $v['tshirtOwn'], 'quantity' => 2], ['productVariantId' => $v['tshirtPlain'], 'quantity' => 1], ['productVariantId' => $v['sweat'], 'quantity' => 1],
            ['productVariantId' => $v['cafe'], 'quantity' => 1], ['productVariantId' => $v['cap'], 'quantity' => 1], ['productVariantId' => $v['gourde'], 'quantity' => 1, 'customizationId' => $combination],
        ];
        $photo = [];
        $this->db->executeStatement("UPDATE product_variant SET stock_quantity = 500 WHERE product_id IN (SELECT id FROM product WHERE code IN ('DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE', 'DEMO-GOURDE'))");
        // Boutique : devis HT puis TTC, intent Stripe
        $this->token = null;
        $photo['cartQuote'] = $this->call('POST', '/api/cart/quote', ['items' => $sale, 'shippingAddress' => $address]);
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = true');
        $photo['cartQuoteTaxIncluded'] = $this->call('POST', '/api/cart/quote', ['items' => $sale, 'shippingAddress' => $address]);
        $this->db->executeStatement('UPDATE entreprise SET prices_include_tax = false');
        $photo['stripeIntent'] = $this->call('POST', '/api/stripe/create-intent', ['items' => $sale, 'shippingAddress' => $address]);
        // Caisse : devis avec prix manuel F7 et remise F1, vente, commande relue, favoris, pack hors ligne
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $this->call('POST', '/api/caisse/open', ['cashRegisterId' => $this->register, 'cashFund' => 50000]);
        $pos = $sale;
        $pos[1]['priceOverride'] = ['unitPrice' => 3000, 'reasonCode' => 'prix_concurrent'];
        $pos[2]['discounts'] = [['type' => 'percent', 'value' => 10, 'reasonCode' => 'fidelite']];
        $photo['posQuote'] = $this->call('POST', '/api/pos/quote', ['items' => $pos, 'cashRegisterId' => $this->register]);
        $this->assertSame(200, $photo['posQuote']['status'], json_encode($photo['posQuote']['body']));
        $cash = $photo['posQuote']['body']['cashTotal'] ?? $photo['posQuote']['body']['total'];
        $photo['posSale'] = $this->call('POST', '/api/order/create-multi-payment', ['items' => $pos, 'paymentMethods' => [['type' => 2, 'amount' => $cash / 100]], 'cashRegisterId' => $this->register]);
        $orderId = (int) ($photo['posSale']['body']['orderId'] ?? 0);
        $this->assertGreaterThan(0, $orderId, json_encode($photo['posSale']));
        $photo['orderItems'] = $this->db->fetchAllAssociative('SELECT unit_price, total_price, catalog_unit_price, quantity, discount_amount, tax_category FROM order_items WHERE order_associated_id = ? ORDER BY id', [$orderId]);
        $photo['orderDetail'] = $this->call('GET', "/api/orders/$orderId");
        $find = function (array $list) use ($orderId): array {
            $rows = $list['body']['data'] ?? $list['body']['items'] ?? $list['body']['orders'] ?? $list['body'];
            return ['status' => $list['status'], 'row' => array_values(array_filter(is_array($rows) ? $rows : [], fn ($r) => is_array($r) && (int) ($r['id'] ?? 0) === $orderId))];
        };
        $photo['orderInPosList'] = $find($this->call('GET', '/api/orders?orderSource=' . self::ORDER_SOURCE_POS));
        $posToken = $this->token;
        $this->token = $this->adminToken();
        $photo['orderInAdminList'] = $find($this->call('GET', '/api/orders?orderSource=' . self::ORDER_SOURCE_POS)); // l'admin lit aussi par source
        $this->token = $posToken;
        // Favoris : seulement les articles de la photo, sans le rang (les plus vendus dépendent des ventes des autres tests de la base partagée)
        $photo['favorites'] = $this->call('GET', '/api/pos/favorites');
        $codes = ['DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CAFE', 'DEMO-CASQUETTE', 'DEMO-GOURDE'];
        $mine = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM product WHERE code IN (?)', [$codes], [\Doctrine\DBAL\ArrayParameterType::STRING]));
        $items = array_values(array_filter($photo['favorites']['body']['items'] ?? [], fn ($i) => in_array($i['productId'], $mine, true)));
        usort($items, fn ($x, $y) => [$x['productId'], $x['variantId']] <=> [$y['productId'], $y['variantId']]);
        $photo['favorites']['body']['items'] = array_map(fn ($i) => array_diff_key($i, ['rank' => 1]), $items);
        $photo['offlinePack'] = $this->call('GET', "/api/pos/offline-pack?cashRegisterId=$this->register");
        // Pack : seulement les articles de la photo (les autres articles du jeu d'exemple sont modifiés par d'autres tests)
        if (isset($photo['offlinePack']['body']['products'])) {
            $photo['offlinePack']['body']['products'] = array_values(array_filter($photo['offlinePack']['body']['products'], fn ($p) => in_array($p['code'], $codes, true)));
        }
        // Ordre des variantes du pack non fixé (collection sans tri : PostgreSQL les rend dans un autre ordre après une mise à jour du stock) : trié ici
        foreach ($photo['offlinePack']['body']['products'] ?? [] as $i => $product) {
            usort($photo['offlinePack']['body']['products'][$i]['variants'], fn ($x, $y) => $x['id'] <=> $y['id']);
        }
        // Fiches produit (ProductCommerceDto) et variantes
        $this->token = null;
        foreach (['DEMO-TSHIRT', 'DEMO-SWEAT', 'DEMO-CASQUETTE', 'DEMO-GOURDE'] as $code) {
            $id = (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
            // Fiche : les parties qui portent les prix (avis, images, catégories dépendent d'autres tests)
            $sheet = $this->call('GET', "/api/products/$id");
            $sheet['body'] = array_intersect_key(is_array($sheet['body']) ? $sheet['body'] : [], array_flip(['id', 'price', 'specialPrice', 'specialPriceFrom', 'specialPriceTo', 'kind', 'sale', 'rental', 'customizable', 'pricing', 'variants']));
            $photo["product:$code"] = $sheet;
            $photo["variants:$code"] = $this->call('GET', "/api/products/$id/variants");
        }

        return $photo;
    }

    /** Masque ce qui change d'une exécution à l'autre ; tout le reste (montants, clés, ordre) est comparé tel quel */
    private function normalize(mixed $value, string $key = ''): mixed
    {
        $volatile = ['orderId', 'orderNumber', 'number', 'receiptNumber', 'reference', 'clientSecret', 'paymentIntentId', 'intentId', 'createdAt', 'updatedAt', 'soldAt',
            'generatedAt', 'etag', 'ETag', 'packVersion', 'expiresAt', 'validUntil', 'token', 'date', 'orderDate', 'caisseId', 'sessionId', 'userId', 'customerId', 'cashierId', 'transactionId',
            'receiptUrl']; // receiptUrl : lien signé dont l'expiration dépend du jour (12/11/2026)
        if (in_array($key, $volatile, true)) {
            return '<masqué>';
        }
        if (is_array($value)) {
            // PAYS-1 (12/11/2026) : clés ajoutées aux taxes des commandes et des tickets après l'enregistrement de la référence ;
            // seules ces quatre clés sont écartées, et seulement dans une entrée de taxe (libellé, taux et montants restent comparés)
            if (isset($value['rateSource'], $value['label']) && array_key_exists('ratePpm', $value)) {
                unset($value['code'], $value['ratePpm'], $value['jurisdiction'], $value['rateSource']);
            }
            $out = [];
            foreach ($value as $k => $item) {
                $out[$k] = $this->normalize($item, (string) $k);
            }

            return $out;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/', $value)) {
            return '<date>';
        }

        return $value;
    }

    // ----- helpers -----

    private function call(string $method, string $path, ?array $body = null): array
    {
        $response = $this->request($method, $path, $body);
        $decoded = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'body' => $decoded ?? (string) $response->getContent(), 'etag' => $response->headers->get('ETag')];
    }

    private function adminToken(): string
    {
        $email = 'photo-variante-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Photo')->setLastname('Admin')->setRoles(['ROLE_ADMIN'])->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function firstVariant(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? ORDER BY v.id LIMIT 1', [$code]);
    }

    private function variantWithPrice(string $code, bool $own): int
    {
        $id = (int) $this->db->fetchOne('SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = ? AND v.price IS ' . ($own ? 'NOT NULL' : 'NULL') . ' ORDER BY v.id LIMIT 1', [$code]);
        $this->assertGreaterThan(0, $id, "$code : variante " . ($own ? 'à prix propre' : 'sans prix propre'));

        return $id;
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token ? 'Bearer ' . $this->token : null,
        ]), $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function loginPos(array $roles): void
    {
        $email = 'photo-variante-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Photo')->setLastname('Variante')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->token = null;
        $login = $this->request('POST', '/api/login', ['username' => $email, 'password' => self::PASSWORD, 'platform' => 'pos']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->token = json_decode($login->getContent(), true)['token'];
    }
}
