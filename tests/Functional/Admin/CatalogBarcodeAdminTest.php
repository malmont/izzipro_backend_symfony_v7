<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\ProductVariantCrudController;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Codes-barres du catalogue dans EasyAdmin, lot CB-1 (15/11/2026, `docs/codes-barres.md`) : les fiches article et variante refusent
 * sous le champ (forme, code réservé à la caisse, code déjà porté) et gardent la forme canonique ; « Générer un code-barres » ne
 * remplace jamais un code ; « Générer les codes manquants » annonce le nombre, exige son jeton et laisse une ligne au journal du site.
 * La ressource d'API de l'article n'écrit pas de code-barres.
 */
class CatalogBarcodeAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $suffix;
    private string $s;
    private string $adminEmail = '';
    /** @var array<string, array<int, string>> codes présents avant le test, par table */
    private array $codesBefore = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->suffix = bin2hex(random_bytes(3));
        $this->s = strtoupper($this->suffix);
        // La base de test est partagée : les codes déjà là sont mis de côté le temps du test, puis remis (tearDown)
        $this->codesBefore = [
            'product' => $this->db()->fetchAllKeyValue('SELECT id, barcode FROM product WHERE barcode IS NOT NULL'),
            'product_variant' => $this->db()->fetchAllKeyValue('SELECT id, barcode FROM product_variant WHERE barcode IS NOT NULL'),
        ];
        $this->clearCodes();
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        // D'autres tests comptent les articles : ceux du test sont effacés, avec leurs variantes et ce que leurs fiches ont créé
        $ids = $db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% ' . $this->suffix . '%']);
        if ($ids !== []) {
            $db->executeStatement('DELETE FROM inventory_movements WHERE product_variant_id IN (SELECT id FROM product_variant WHERE product_id IN (?))', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM product_translation WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        }
        $this->clearCodes();
        foreach ($this->codesBefore as $table => $codes) {
            foreach ($codes as $id => $code) {
                $db->executeStatement("UPDATE $table SET barcode = ? WHERE id = ?", [$code, $id]);
            }
        }
        parent::tearDown();
    }

    public function testTheProductSheetRefusesUnderTheFieldAndKeepsTheCanonicalForm(): void
    {
        $bottle = $this->article('Gourde', 0);
        $cap = $this->article('Casquette', 1);
        $capVariant = (int) $cap->getVariants()->first()->getId();
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["PRIS-$this->s", $cap->getId()]);
        $this->db()->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["VAR-$this->s", $capVariant]);
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);
        $code = fn (): ?string => $this->codeOf('product', (int) $bottle->getId());

        // Saisi en minuscules et entouré d'espaces : enregistré en forme canonique
        $this->submitProduct((int) $bottle->getId(), ['Product[barcode]' => ' abc-' . $this->suffix . ' ']);
        $this->assertSame(302, $this->status());
        $this->assertSame("ABC-$this->s", $code());

        // Refus sous le champ, rien d'écrit : code d'un autre article, code d'une variante, forme réservée à la caisse, forme interdite
        foreach ([
            strtolower("PRIS-$this->s") => 'Ce code-barres est déjà celui de « ' . $cap->getName() . ' ».',
            " VAR-$this->s" => 'Ce code-barres est déjà celui de « ' . $cap->getName() . ', variante n° ' . $capVariant . ' ».',
            'iz-t-1-000042' => 'document de la caisse',
            '512345678901' => 'coupon de fabricant',
            'A B' => 'Code-barres refusé',
            str_repeat('a', 65) => 'Code-barres refusé',
        ] as $typed => $message) {
            $this->submitProduct((int) $bottle->getId(), ['Product[barcode]' => (string) $typed]);
            $this->assertNotSame(302, $this->status(), "$typed : la fiche n'est pas enregistrée");
            $this->assertStringContainsString($message, $this->text(), (string) $typed);
            $this->assertSame("ABC-$this->s", $code(), "$typed : rien n'est écrit");
        }

        // Un code ancien, d'une forme refusée aujourd'hui, ne bloque pas la modification du reste de la fiche tant qu'on n'y touche pas
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["IZ-ANCIEN-$this->s", $bottle->getId()]);
        $this->submitProduct((int) $bottle->getId(), ['Product[name]' => "Gourde renommée $this->suffix"]);
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertSame(["Gourde renommée $this->suffix", "IZ-ANCIEN-$this->s"], [$this->db()->fetchOne('SELECT name FROM product WHERE id = ?', [$bottle->getId()]), $code()]);

        // 13 chiffres dont la clé de contrôle est fausse : accepté, avec un avertissement
        $this->submitProduct((int) $bottle->getId(), ['Product[barcode]' => '4006381333932']);
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertSame('4006381333932', $code());
        $this->client->followRedirect();
        $this->assertStringContainsString('Code 128', $this->text());
        // … une seule fois : la fiche enregistrée de nouveau sans toucher au code n'avertit plus
        $this->submitProduct((int) $bottle->getId(), ['Product[name]' => "Gourde encore $this->suffix"]);
        $this->assertSame(302, $this->status(), $this->text());
        $this->client->followRedirect();
        $this->assertStringNotContainsString('Code 128', $this->text());

        // Code retiré : « pas de code »
        $this->submitProduct((int) $bottle->getId(), ['Product[barcode]' => '   ']);
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertNull($code());
    }

    public function testGeneratingACodeFromASheetNeverReplacesOneAndNeedsItsToken(): void
    {
        $bottle = $this->article('Gourde', 0);
        $flask = $this->article('Flasque', 0);
        $cap = $this->article('Casquette', 2);
        [$v1, $v2] = array_map(fn (ProductVariant $v) => (int) $v->getId(), $cap->getVariants()->toArray());
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);

        // Article sans variante ni code : le bouton est là, et donne un EAN-13 interne
        $this->admin(ProductCrudController::class, 'edit', (int) $bottle->getId());
        $link = $this->actionLink('generateBarcode');
        $this->assertNotNull($link, 'bouton « Générer un code-barres » sur la fiche d\'un article sans variante ni code');
        $this->open($link);
        $this->assertSame(302, $this->status());
        $generated = (string) $this->codeOf('product', (int) $bottle->getId());
        $this->assertTrue(str_starts_with($generated, '29') && CatalogBarcode::isEan13($generated), $generated);
        $this->client->followRedirect();
        $this->assertStringContainsString('Code-barres généré : ' . $generated, $this->text());
        $this->assertNull($this->actionLink('generateBarcode'), 'l\'article a un code : plus de bouton');

        // Le même lien rejoué : le code n'est pas renuméroté
        $this->open($link);
        $this->assertSame($generated, $this->codeOf('product', (int) $bottle->getId()));
        $this->client->followRedirect();
        $this->assertStringContainsString('jamais remplacé', $this->text());

        // Le lien détourné vers un article à variantes : rien sur l'article ; pas de bouton sur sa fiche
        $this->open(str_replace('entityId=' . $bottle->getId(), 'entityId=' . $cap->getId(), $link));
        $this->assertNull($this->codeOf('product', (int) $cap->getId()));
        $this->client->followRedirect();
        $this->assertStringContainsString('variantes', $this->text());
        $this->admin(ProductCrudController::class, 'edit', (int) $cap->getId());
        $this->assertNull($this->actionLink('generateBarcode'));

        // Sans jeton : rien n'est écrit
        $this->open(preg_replace('/([?&])_csrf=[^&]*/', '$1_csrf=faux', str_replace('entityId=' . $bottle->getId(), 'entityId=' . $flask->getId(), $link)));
        $this->assertNull($this->codeOf('product', (int) $flask->getId()));

        // Variante : bouton sur sa fiche de détail, code posé sur la variante, jamais remplacé
        $this->admin(ProductVariantCrudController::class, 'detail', $v1);
        $variantLink = $this->actionLink('generateBarcode');
        $this->assertNotNull($variantLink, 'bouton « Générer un code-barres » sur le détail d\'une variante sans code');
        $this->open($variantLink);
        $variantCode = (string) $this->codeOf('product_variant', $v1);
        $this->assertTrue(str_starts_with($variantCode, '29') && CatalogBarcode::isEan13($variantCode) && $variantCode !== $generated, $variantCode);
        $this->open($variantLink);
        $this->assertSame($variantCode, $this->codeOf('product_variant', $v1));
        $this->open(preg_replace('/([?&])_csrf=[^&]*/', '$1_csrf=faux', str_replace('entityId=' . $v1, 'entityId=' . $v2, $variantLink)));
        $this->assertNull($this->codeOf('product_variant', $v2));
        $this->admin(ProductVariantCrudController::class, 'detail', $v1);
        $this->assertNull($this->actionLink('generateBarcode'));
    }

    public function testTheVariantFormRefusesUnderTheFieldToo(): void
    {
        $cap = $this->article('Casquette', 2);
        [$v1, $v2] = array_map(fn (ProductVariant $v) => (int) $v->getId(), $cap->getVariants()->toArray());
        $other = $this->article('Maillot', 0);
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["ART-$this->s", $cap->getId()]);
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["AUTRE-$this->s", $other->getId()]);
        $this->db()->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["SOEUR-$this->s", $v2]);
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);
        $submit = function (string $typed) use ($v1): void {
            // comme le lien « Modifier » de la liste des variantes : la page sur mesure s'ouvre dans le cadre d'EasyAdmin
            $this->open('https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['routeName' => 'admin_product_variant_edit_custom', 'routeParams' => ['id' => $v1]]));
            $this->assertSame(200, $this->status(), $this->text());
            $this->client->submit($this->client->getCrawler()->filter('form[name="product_variant_custom"]')->form(['product_variant_custom[barcode]' => $typed]), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        };

        foreach ([
            strtolower("ART-$this->s") => 'Ce code-barres est déjà celui de « ' . $cap->getName() . ' ».', // son propre article
            "AUTRE-$this->s" => 'Ce code-barres est déjà celui de « ' . $other->getName() . ' ».',
            " soeur-$this->suffix " => 'Ce code-barres est déjà celui de « ' . $cap->getName() . ', variante n° ' . $v2 . ' ».',
            'IZ-G-1' => 'document de la caisse',
            'é1' => 'Code-barres refusé',
        ] as $typed => $message) {
            $submit((string) $typed);
            $this->assertNotSame(302, $this->status(), "$typed : la variante n'est pas enregistrée");
            $this->assertStringContainsString($message, $this->text(), (string) $typed);
            $this->assertNull($this->codeOf('product_variant', $v1), "$typed : rien n'est écrit");
        }
        $submit(' piece-' . $this->suffix . ' ');
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertSame("PIECE-$this->s", $this->codeOf('product_variant', $v1));
        // Enregistrer de nouveau la fiche sans toucher au code : pas de refus contre son propre code
        $submit("PIECE-$this->s");
        $this->assertSame(302, $this->status(), $this->text());
        // 13 chiffres dont la clé de contrôle est fausse : accepté, avec un avertissement, comme sur la fiche article ; une seule fois
        $submit('4006381333932');
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertSame('4006381333932', $this->codeOf('product_variant', $v1));
        $this->client->followRedirect();
        $this->assertStringContainsString('Code 128', $this->text());
        $submit('4006381333932');
        $this->assertSame(302, $this->status(), $this->text());
        $this->client->followRedirect();
        $this->assertStringNotContainsString('Code 128', $this->text(), 'code inchangé : pas d\'avertissement répété');
    }

    public function testGeneratingTheMissingCodesAnnouncesTheNumberNeedsItsTokenAndLeavesATrace(): void
    {
        $cap = $this->article('Casquette', 2);
        [$v1, $v2] = array_map(fn (ProductVariant $v) => (int) $v->getId(), $cap->getVariants()->toArray());
        $bottle = $this->article('Gourde', 0);
        $this->db()->executeStatement('UPDATE product_variant SET barcode = ? WHERE id = ?', ["SAISI-$this->s", $v2]);
        $this->em()->clear();
        $page = 'https://' . MV_TEST_TENANT_HOST . '/admin/barcode-management';
        $post = 'https://' . MV_TEST_TENANT_HOST . '/admin/barcode-management/generate-missing';

        // Réservé à l'administrateur : ni anonyme, ni compte client
        $this->client->request('POST', $post, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertContains($this->status(), [302, 401, 403]);
        $this->assertNull($this->codeOf('product_variant', $v1), 'anonyme : rien n\'est écrit');
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->client->request('POST', $post, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->status());

        $this->loginAs(['ROLE_ADMIN']);
        $missing = $this->barcodes()->missingCount();
        $this->assertLessThanOrEqual(CatalogBarcodeService::BULK_LIMIT, $missing['total'], 'la base de test tient sous le plafond');
        $this->open($page);
        $this->assertSame(200, $this->status());
        $this->assertStringContainsString($missing['variants'] . ' variante(s) et ' . $missing['products'] . ' article(s) sans variante', $this->text());
        $button = 'Générer les codes manquants (' . $missing['total'] . ')';
        $this->assertStringContainsString($button, $this->text(), 'le nombre est annoncé avant d\'écrire');
        $form = $this->client->getCrawler()->selectButton($button)->form();

        // Sans jeton, ou par GET : rien n'est écrit
        $this->client->request('POST', $post, ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->status());
        $this->client->request('GET', $post, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(405, $this->status());
        $this->assertSame($missing, $this->barcodes()->missingCount());
        $audit = fn (): int => (int) $this->db()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'catalog_barcode' AND action = 'generate'");
        $auditBefore = $audit();

        // La fiche publique de l'article, mise en cache AVANT la génération (elle dit « pas de code »)
        $publicCode = function () use ($bottle): ?string {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/products/' . $bottle->getId() . '?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(200, $this->status(), $this->text());

            return json_decode((string) $this->client->getResponse()->getContent(), true)['barcode'] ?? null;
        };
        $this->assertNull($publicCode());
        // … et la recherche en ligne du code que la génération va donner à cet article (le prochain numéro de la séquence, après ceux
        // des fiches qui passent avant lui : variantes, puis articles par identifiant) : réponse VIDE, mise en cache elle aussi
        $searched = function (string $code): array {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/products/by-category?locale=fr&limit=1&pageSize=1&barcode=' . rawurlencode($code), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(200, $this->status(), $this->text());

            return array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'id');
        };
        $next = (int) $this->db()->fetchOne('SELECT CASE WHEN is_called THEN last_value + 1 ELSE last_value END FROM catalog_barcode_seq');
        $before = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM product p WHERE p.barcode IS NULL AND p.id < ? AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id)', [$bottle->getId()]);
        $expected = CatalogBarcode::generated($next + $missing['variants'] + $before);
        $this->assertSame([], $searched($expected), 'personne ne porte encore ce code');

        // Avec le formulaire de la page : tout ce qui manque reçoit un code, le code saisi reste, l'article à variantes n'en reçoit pas
        $this->client->submit($form, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(302, $this->status(), $this->text());
        $this->assertSame(['variants' => 0, 'products' => 0, 'total' => 0], $this->barcodes()->missingCount());
        $this->assertSame(["SAISI-$this->s", null], [$this->codeOf('product_variant', $v2), $this->codeOf('product', (int) $cap->getId())]);
        foreach ([$this->codeOf('product_variant', $v1), $this->codeOf('product', (int) $bottle->getId())] as $code) {
            $this->assertTrue($code !== null && str_starts_with($code, '29') && CatalogBarcode::isEan13($code), var_export($code, true));
        }
        $this->assertSame($auditBefore + 1, $audit(), 'une ligne au journal du site');
        $line = $this->db()->fetchAssociative("SELECT user_identifier, after_state FROM content_audit_log WHERE resource = 'catalog_barcode' ORDER BY id DESC LIMIT 1");
        $this->assertSame([$this->adminEmail, $missing['total']], [$line['user_identifier'], json_decode((string) $line['after_state'], true)['written']], 'qui, combien');
        $this->client->followRedirect();
        $this->assertStringContainsString($missing['total'] . ' code(s)-barres généré(s)', $this->text());
        $this->assertStringContainsString('Toutes les variantes et tous les articles sans variante ont un code-barres.', $this->text());
        $this->assertStringNotContainsString('Générer les codes manquants (', $this->text());
        $this->assertSame($this->codeOf('product', (int) $bottle->getId()), $publicCode(), 'l\'écriture en SQL vide le cache du site : la fiche montre le code généré');
        $this->assertSame($expected, $this->codeOf('product', (int) $bottle->getId()), 'le code prévu est bien celui que l\'article a reçu');
        $this->assertSame([(int) $bottle->getId()], $searched($expected), 'les LISTES en cache sont vidées aussi : la recherche du code, vide il y a un instant, rend l\'article');

        // Tout ce qui manque est tenu ailleurs (ici : une variante sans code, verrouillée par une autre connexion comme par une vente en
        // cours) : le lancement ne l'attend pas, n'écrit rien, et l'écran dit qu'il en reste, jamais « aucun code à générer »
        $this->db()->executeStatement('UPDATE product_variant SET barcode = NULL WHERE id = ?', [$v1]);
        $other = DriverManager::getConnection($this->db()->getParams());
        try {
            // Garde contre un blocage : si le lancement se mettait à attendre cette connexion, la base la coupe d'elle-même et le test échoue
            $other->executeStatement("SET idle_in_transaction_session_timeout = '20s'");
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM product_variant WHERE id = ? FOR UPDATE', [$v1]);
            $this->open($page);
            $this->client->submit($this->client->getCrawler()->selectButton('Générer les codes manquants (1)')->form(), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(302, $this->status(), $this->text());
            $this->assertNull($this->codeOf('product_variant', $v1));
            $this->client->followRedirect();
            $this->assertStringContainsString('Aucun code écrit pour l\'instant : 1 fiche(s) sans code-barres sont occupées', $this->text());
            $this->assertStringNotContainsString('Aucun code à générer', $this->text());
            $this->assertSame($auditBefore + 1, $audit(), 'rien d\'écrit : pas de ligne au journal');
        } finally {
            try {
                $other->rollBack();
            } catch (\Throwable) {
                // connexion déjà coupée par la garde
            }
            $other->close();
        }
    }

    public function testTheBarcodeScreenListsVariantsPageByPageAndPrintsTheSelection(): void
    {
        // Lot CB-2 : une ligne par variante et par article sans variante ; recherche, filtre « sans code », impression de la sélection
        $cap = $this->article('Casquette', 2);
        [$v1, $v2] = array_map(fn (ProductVariant $v) => (int) $v->getId(), $cap->getVariants()->toArray());
        $bottle = $this->article('Gourde', 0);
        $solo = $this->article('Sac', 1);
        $soloVariant = (int) $solo->getVariants()->first()->getId();
        $this->db()->executeStatement('UPDATE product_variant SET barcode = ?, price = 4500 WHERE id = ?', ['2900000000018', $v1]);
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["GOURDE-$this->s", $bottle->getId()]);
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["SAC-$this->s", $solo->getId()]); // seule variante : le code de l'article sert
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);
        $page = 'https://' . MV_TEST_TENANT_HOST . '/admin/barcode-management';
        $keys = fn (): array => $this->client->getCrawler()->filter('tr[data-key]')->each(fn ($tr) => $tr->attr('data-key'));

        $this->open($page . '?search=' . rawurlencode($this->suffix));
        $this->assertSame(200, $this->status(), $this->text());
        $this->assertSame(["variant-$v1", "variant-$v2", "product-{$bottle->getId()}", "variant-$soloVariant"], $keys(), 'par nom d\'article : les deux variantes, l\'article sans variante, la variante unique');
        $this->assertStringContainsString('2900000000018 EAN-13', $this->text());
        $this->assertStringContainsString("GOURDE-$this->s Code 128", $this->text());
        $this->assertStringContainsString("SAC-$this->s Code 128, code de l'article", $this->text());
        $this->assertSame(3, $this->client->getCrawler()->filter('input[name^="copies["]')->count(), 'un nombre d\'étiquettes seulement pour les lignes qui ont un code');
        // Filtre « sans code » ; recherche par code-barres scanné (casse, espaces) ; rien de trouvé
        $this->open($page . '?missing=1&search=' . rawurlencode($this->suffix));
        $this->assertSame(["variant-$v2"], $keys());
        $this->open($page . '?search=' . rawurlencode(' gourde-' . $this->suffix . ' '));
        $this->assertSame(["product-{$bottle->getId()}"], $keys());
        $this->open($page . '?search=' . rawurlencode("INCONNU-$this->s"));
        $this->assertSame([], $keys());
        $this->assertStringContainsString('Aucune ligne trouvée.', $this->text());
        // Page par page : jamais plus de 50 lignes, quel que soit le catalogue
        $this->open($page);
        $this->assertLessThanOrEqual(50, count($keys()));
        $this->open($page . '/search?search=' . rawurlencode($this->suffix));
        $this->assertSame(302, $this->status(), 'ancienne adresse de la recherche');

        // Impression de la sélection : 2 + 3 + 1 étiquettes, prix seulement si demandé
        $print = function (array $copies, array $more = []) : void {
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/barcode-management/print', ['copies' => $copies] + $more, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        };
        $selection = ["variant-$v1" => '2', "product-{$bottle->getId()}" => '3', "variant-$soloVariant" => '1', "variant-$v2" => '0'];
        $print($selection, ['showPrice' => '1']);
        $this->assertSame(200, $this->status(), $this->text());
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame([6, 6], [substr_count($html, 'class="label"'), substr_count($html, 'class="price"')]);
        foreach (['2900000000018', "GOURDE-$this->s", "SAC-$this->s", '45,00', '19,90'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $print($selection);
        $this->assertSame([6, 0], [substr_count((string) $this->client->getResponse()->getContent(), 'class="label"'), substr_count((string) $this->client->getResponse()->getContent(), 'class="price"')]);
        $print($selection, ['output' => 'pdf']);
        $this->assertSame([200, 'application/pdf'], [$this->status(), $this->client->getResponse()->headers->get('Content-Type')]);
        $this->assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        // Refus : rien de choisi, trop d'étiquettes, une ligne sans code, une ligne inconnue ; retour à la liste avec le message
        foreach ([
            'Rien à imprimer' => ["variant-$v1" => '0'],
            'Au plus 500 étiquettes' => ["variant-$v1" => '300', "product-{$bottle->getId()}" => '201'],
            'pas de code-barres' => ["variant-$v1" => '1', "variant-$v2" => '1'],
            'n\'existe plus' => ["variant-$v1" => '1', 'variant-999999999' => '1'],
        ] as $message => $copies) {
            $print($copies, ['search' => $this->suffix, 'missing' => '1', 'page' => '1']);
            $this->assertSame(302, $this->status(), $message);
            $this->assertStringContainsString('missing=1', (string) $this->client->getResponse()->headers->get('Location'), 'retour à la même liste');
            $this->client->followRedirect();
            $this->assertStringContainsString($message, $this->text());
        }
        // Anciennes adresses d'impression d'un article : même planche
        $this->open('https://' . MV_TEST_TENANT_HOST . '/admin/print-barcode/' . $bottle->getId() . '/4');
        $this->assertSame([200, 4], [$this->status(), substr_count((string) $this->client->getResponse()->getContent(), 'class="label"')]);
        $this->open('https://' . MV_TEST_TENANT_HOST . '/admin/pdf/print-barcode/' . $bottle->getId() . '/2');
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        // Réservé à l'administrateur
        $this->loginAs(['ROLE_USER_INTERNET']);
        $print($selection);
        $this->assertSame(403, $this->status());
    }

    public function testTheProductApiResourceDoesNotWriteABarcode(): void
    {
        // La ressource d'API de l'article n'est pas un chemin d'écriture du code-barres : elle n'accepte qu'une liste de champs, dont il
        // n'est pas. Gardé par ce test : le jour où le champ y serait ouvert, il devra passer par la règle
        $bottle = $this->article('Gourde', 0);
        $this->em()->clear();
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user(['ROLE_ADMIN']));
        $this->client->getCookieJar()->clear();
        $this->client->request('PATCH', 'https://' . MV_TEST_TENANT_HOST . '/api/products/' . $bottle->getId(), [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['barcode' => "API-$this->s"]));
        $this->assertSame(422, $this->status(), $this->text());
        $this->assertStringContainsString('barcode', (string) $this->client->getResponse()->getContent());
        $this->assertNull($this->codeOf('product', (int) $bottle->getId()));
    }

    public function testTheVehicleApiResourceCannotBreakTheRule(): void
    {
        // La ressource d'API des véhicules (API Platform, écriture réservée à l'administrateur) laisse écrire le code-barres : la même
        // contrainte s'y applique (un véhicule est un article)
        static::getContainer()->get(\App\Services\BoutiqueDemoService\BoutiqueDemoSeeder::class)->seed('client-codes@example.invalid', false, false);
        $vehicle = (int) $this->db()->fetchOne("SELECT id FROM product WHERE product_type = 'vehicle' ORDER BY id LIMIT 1");
        $this->assertGreaterThan(0, $vehicle, 'le jeu de démonstration porte un véhicule');
        $cap = $this->article('Casquette', 0);
        $this->db()->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["PRIS-$this->s", $cap->getId()]);
        $this->db()->executeStatement('UPDATE product SET barcode = NULL WHERE id = ?', [$vehicle]);
        $this->em()->clear();
        // Cette adresse est hors du pare-feu des jetons de l'API : seule une session d'administrateur (celle d'EasyAdmin) peut y écrire
        $this->loginAs(['ROLE_ADMIN']);
        $patch = function (array $body) use ($vehicle): int {
            $this->client->request('PATCH', 'https://' . MV_TEST_TENANT_HOST . '/api/vehicle_products/' . $vehicle, [], [], [
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json',
            ], json_encode($body));

            return $this->status();
        };
        try {
            $this->assertSame(422, $patch(['barcode' => strtolower("PRIS-$this->s")]), $this->text());
            $this->assertStringContainsString('Ce code-barres est déjà celui de « ' . $cap->getName() . ' »', (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? ''));
            $this->assertSame(422, $patch(['barcode' => 'IZ-T-1-000001']), $this->text());
            $this->assertNull($this->codeOf('product', $vehicle), 'aucun refus n\'a rien écrit');
            $this->assertSame(200, $patch(['barcode' => ' libre-' . $this->suffix]), $this->text());
            $this->assertSame("LIBRE-$this->s", $this->codeOf('product', $vehicle), 'un code libre est écrit en forme canonique');
        } finally {
            $this->db()->executeStatement('UPDATE product SET barcode = NULL WHERE id = ?', [$vehicle]);
        }
    }

    /** Ouvre la fiche de l'article et l'envoie avec ces valeurs en plus de celles qu'elle porte */
    private function submitProduct(int $id, array $values): void
    {
        $this->admin(ProductCrudController::class, 'edit', $id);
        $this->assertSame(200, $this->status(), $this->text());
        $this->client->submit($this->client->getCrawler()->filter('form[name="Product"]')->form($values), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    /** Lien d'une action de la page ouverte, ou null si elle n'y est pas proposée */
    private function actionLink(string $action): ?string
    {
        $links = $this->client->getCrawler()->filter('a.action-' . $action);

        return $links->count() > 0 ? (string) $links->first()->attr('href') : null;
    }

    private function open(string $url): void
    {
        $this->client->request('GET', $url, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function admin(string $controller, string $action, ?int $id = null): void
    {
        $this->open('https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id])));
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function text(): string
    {
        return html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags((string) $this->client->getResponse()->getContent())), ENT_QUOTES | ENT_HTML5);
    }

    private function article(string $name, int $variants): Product
    {
        $slug = strtolower($name) . '-' . $this->suffix . '-' . bin2hex(random_bytes(2));
        $product = (new Product())->setName("$name $this->suffix")->setSlug($slug)->setPrice(1990)->setIsWeb(true)->setSaleEnabled(true);
        for ($i = 0; $i < $variants; ++$i) {
            $product->addVariant((new ProductVariant())->setStockQuantity(5));
        }
        $em = $this->em(); // une seule instance : chaque appel à em() rebranche le site et peut en rendre une autre
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function codeOf(string $table, int $id): ?string
    {
        $code = $this->db()->fetchOne("SELECT barcode FROM $table WHERE id = ?", [$id]);

        return $code === false ? null : $code;
    }

    private function user(array $roles): User
    {
        $email = 'ea-codes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Codes')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginAs(array $roles): void
    {
        $user = $this->user($roles);
        $this->adminEmail = (string) $user->getEmail();
        $this->client->loginUser($user, 'main');
    }

    private function barcodes(): CatalogBarcodeService
    {
        $this->em();

        return static::getContainer()->get(CatalogBarcodeService::class);
    }

    private function clearCodes(): void
    {
        $this->db()->executeStatement('UPDATE product SET barcode = NULL WHERE barcode IS NOT NULL');
        $this->db()->executeStatement('UPDATE product_variant SET barcode = NULL WHERE barcode IS NOT NULL');
    }

    private function em(): EntityManagerInterface
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
