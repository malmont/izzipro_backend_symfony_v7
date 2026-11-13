<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\InventoryMovementsCrudController;
use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lot STOCK-VERROU (13/11/2026, `docs/gestion-mise-a-niveau.md` § 7) :
 * - mouvements issus d'une vente, d'un retour ou d'une réception (types 1, 2, 3, 5, ou `commande_line_id` non nul) verrouillés en
 *   lecture seule dans EasyAdmin pour administrateur et super administrateur ;
 * - création réservée au type 4 (Ajustement), avec calcul serveur de `stockBeforeMovement` et `stockAfterMovement` sans jamais
 *   modifier `product_variant.stock_quantity` ;
 * - modification des ajustements libres en gardant `stockBeforeMovement` et `stockAfterMovement` désactivés et réimposés côté serveur.
 */
class InventoryMovementsLockTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;

    /** @var list<int> */
    private array $createdMovementIds = [];

    /** @var list<int> */
    private array $createdCommandeLineIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $this->ensureMovementTypes();
    }

    protected function tearDown(): void
    {
        $this->cleanupCreatedRows();
        parent::tearDown();
    }

    public function testLockedMovementsCannotBeModifiedOrDeletedByAdminOrSuperAdmin(): void
    {
        $commande = $this->create(Commande::class, ['name' => 'Achat verrou STOCK']);
        $product = $this->create(Product::class);
        $variant = $this->create(ProductVariant::class, ['product' => $product, 'stockQuantity' => 25]);
        $line = $this->create(CommandeLine::class, [
            'commande' => $commande,
            'product' => $product,
            'variant' => $variant,
        ]);
        $this->createdCommandeLineIds[] = (int) $line->getId();

        $lockedCases = [
            'type 1 Entrant' => [$this->insertMovement($variant->getId(), 1, 4, 21, 25, null), $variant->getId(), 1, null, 4],
            'type 2 Sortant' => [$this->insertMovement($variant->getId(), 2, -2, 25, 23, null), $variant->getId(), 2, null, -2],
            'type 3 Retour' => [$this->insertMovement($variant->getId(), 3, 1, 23, 24, null), $variant->getId(), 3, null, 1],
            'type 5 Réception' => [$this->insertMovement($variant->getId(), 5, 10, 24, 34, $line->getId()), $variant->getId(), 5, (int) $line->getId(), 10],
            'type 5 dont la ligne d\'achat a disparu' => [$this->insertMovement($variant->getId(), 5, 2, 34, 36, null), $variant->getId(), 5, null, 2], // ajout de la relecture : verrouillé par son type seul
            'type 4 avec commandeLine' => [$this->insertMovement($variant->getId(), 4, 3, 34, 37, $line->getId()), $variant->getId(), 4, (int) $line->getId(), 3],
        ];
        $freeDonorId = $this->insertMovement($variant->getId(), 4, 5, 25, 30, null);

        foreach ([['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']] as $roles) {
            $this->loginAs($roles);

            // Base d'un formulaire edit valide (jeton CSRF authentique) pour forger un POST sur une fiche verrouillée
            $donorCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $freeDonorId));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $forgedValues = $donorCrawler->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
            $forgedValues['InventoryMovements']['movementType'] = '4';
            $forgedValues['InventoryMovements']['quantity'] = '999';

            foreach ($lockedCases as $label => [$id, $_v, $expectedType, $expectedLineId, $expectedQty]) {
                $row = $this->indexRow(InventoryMovementsCrudController::class, 'inventory_movements', $id);
                $this->assertCount(1, $row, "$label : ligne visible dans la liste");
                $this->assertCount(0, $row->filter('a.action-edit, [data-action-name="edit"]'), "$label : aucun bouton Modifier");
                $this->assertCount(0, $row->filter('a.action-delete, [data-action-name="delete"]'), "$label : aucun bouton Supprimer");
                $this->assertStringNotContainsString('batchDelete', (string) $row->closest('body')?->html(), "$label : pas de suppression en lot");

                $detailCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'detail', $id));
                $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$label : détail lisible");
                $this->assertCount(0, $detailCrawler->filter('a.action-edit, [data-action-name="edit"], a.action-delete, [data-action-name="delete"]'), "$label : ni Modifier ni Supprimer sur détail");
                if ($expectedLineId !== null) {
                    $detailText = $detailCrawler->filter('body')->text();
                    $this->assertStringContainsString('Ligne d\'achat', $detailText, "$label : colonne Ligne d'achat affichée");
                    $this->assertStringContainsString('#' . $expectedLineId, $detailText, "$label : identifiant de ligne d'achat affiché");
                }

                $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $id));
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$label : GET edit refusé");

                $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $id), $forgedValues);
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$label : POST edit forgé refusé");

                $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'delete', $id), ['token' => 'x']);
                $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$label : POST delete refusé");

                $dbRow = $this->db()->fetchAssociative('SELECT movement_type_id, commande_line_id, quantity FROM inventory_movements WHERE id = ?', [$id]);
                $this->assertSame([
                    'movement_type_id' => $expectedType,
                    'commande_line_id' => $expectedLineId,
                    'quantity' => $expectedQty,
                ], [
                    'movement_type_id' => (int) $dbRow['movement_type_id'],
                    'commande_line_id' => $dbRow['commande_line_id'] !== null ? (int) $dbRow['commande_line_id'] : null,
                    'quantity' => (int) $dbRow['quantity'],
                ], "$label : ligne intacte en base");
            }
        }

        // Vérification directe des gardes serveur même si l'entité en mémoire est maquillée en ajustement libre
        $controller = static::getContainer()->get(InventoryMovementsCrudController::class);
        $em = $this->em();
        $adjType = $em->find(MovementType::class, InventoryMovementsCrudController::ADJUSTMENT_TYPE_ID);
        foreach ($lockedCases as $label => [$id]) {
            $em->clear();
            $entity = $em->find(InventoryMovements::class, $id);
            $this->assertInstanceOf(InventoryMovements::class, $entity);
            $entity->setMovementType($adjType)->setCommandeLine(null)->setQuantity(999);
            foreach (['updateEntity', 'deleteEntity'] as $method) {
                try {
                    $controller->$method($em, $entity);
                    $this->fail("$label : $method aurait dû refuser");
                } catch (AccessDeniedHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $em->clear();
    }

    public function testNewAdjustmentComputesStockFromDatabaseWithoutModifyingVariantAndRejectsForgedInputs(): void
    {
        $product = $this->create(Product::class);
        $variant = $this->create(ProductVariant::class, ['product' => $product, 'stockQuantity' => 25]);
        $variantId = (int) $variant->getId();

        $this->loginAs(['ROLE_ADMIN']);

        // 1. Formulaire de création : pas de stockBefore/stockAfter, seul type 4 proposé
        $crawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'new'));
        $this->assertSame(['4'], $crawler->filter('select[name="InventoryMovements[movementType]"] option')->each(fn ($o) => (string) $o->attr('value')), 'type obligatoire à la création : seul « Ajustement », sans choix vide (retouche de la relecture)');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('input[name="InventoryMovements[stockBeforeMovement]"]'), 'stockBefore absent du formulaire de création');
        $this->assertCount(0, $crawler->filter('input[name="InventoryMovements[stockAfterMovement]"]'), 'stockAfter absent du formulaire de création');

        $typeOptions = array_values(array_filter(
            $crawler->filter('select[name="InventoryMovements[movementType]"] option')->each(fn (Crawler $o) => (string) $o->attr('value')),
            static fn (string $v): bool => $v !== ''
        ));
        $this->assertSame(['4'], $typeOptions, 'seul le type 4 Ajustement est proposé');

        // 2. Ajustement positif (+7) : stockBefore = 25, stockAfter = 32, stock variante inchangé
        $form = $crawler->filter('form[name="InventoryMovements"]')->form([
            'InventoryMovements[productVariant]' => (string) $variantId,
            'InventoryMovements[movementType]' => '4',
            'InventoryMovements[quantity]' => '7',
            'InventoryMovements[movementDate]' => '2026-11-13T10:00',
        ]);
        $this->client->submit($form);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $rowPos = $this->latestMovementForVariant($variantId);
        $this->createdMovementIds[] = (int) $rowPos['id'];
        $this->assertSame(4, (int) $rowPos['movement_type_id']);
        $this->assertSame(7, (int) $rowPos['quantity']);
        $this->assertSame(25, (int) $rowPos['stock_before_movement']);
        $this->assertSame(32.0, (float) $rowPos['stock_after_movement']);
        $this->assertSame(25, (int) $this->db()->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]), 'stock réel inchangé');

        // 3. Ajustement négatif (-3) : accepté, stockAfter = 22, stock variante toujours à 25
        $crawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'new'));
        $this->client->submit($crawler->filter('form[name="InventoryMovements"]')->form([
            'InventoryMovements[productVariant]' => (string) $variantId,
            'InventoryMovements[movementType]' => '4',
            'InventoryMovements[quantity]' => '-3',
            'InventoryMovements[movementDate]' => '2026-11-13T10:05',
        ]));
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $rowNeg = $this->latestMovementForVariant($variantId);
        $this->createdMovementIds[] = (int) $rowNeg['id'];
        $this->assertSame(-3, (int) $rowNeg['quantity']);
        $this->assertSame(25, (int) $rowNeg['stock_before_movement']);
        $this->assertSame(22.0, (float) $rowNeg['stock_after_movement']);
        $this->assertSame(25, (int) $this->db()->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]), 'stock réel inchangé après ajustement négatif');

        // 4. Variante absente : stockBefore et stockAfter restent null
        $maxIdBeforeNull = (int) $this->db()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM inventory_movements');
        $crawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'new'));
        $this->client->submit($crawler->filter('form[name="InventoryMovements"]')->form([
            'InventoryMovements[productVariant]' => '',
            'InventoryMovements[movementType]' => '4',
            'InventoryMovements[quantity]' => '-4',
            'InventoryMovements[movementDate]' => '2026-11-13T10:10',
        ]));
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $rowNullVariant = $this->db()->fetchAssociative('SELECT id, product_variant_id, stock_before_movement, stock_after_movement, quantity FROM inventory_movements WHERE id > ? ORDER BY id DESC LIMIT 1', [$maxIdBeforeNull]);
        $this->assertIsArray($rowNullVariant);
        $this->createdMovementIds[] = (int) $rowNullVariant['id'];
        $this->assertNull($rowNullVariant['product_variant_id']);
        $this->assertNull($rowNullVariant['stock_before_movement']);
        $this->assertNull($rowNullVariant['stock_after_movement']);
        $this->assertSame(-4, (int) $rowNullVariant['quantity']);

        // 5. Envoi HTTP forgé avec stockBefore/stockAfter ou type 2 : refusé par le formulaire (422), rien créé en base
        $maxIdBeforeForged = (int) $this->db()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM inventory_movements');
        $crawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'new'));
        $forgedStockValues = $crawler->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
        $forgedStockValues['InventoryMovements']['productVariant'] = (string) $variantId;
        $forgedStockValues['InventoryMovements']['movementType'] = '4';
        $forgedStockValues['InventoryMovements']['quantity'] = '6';
        $forgedStockValues['InventoryMovements']['movementDate'] = '2026-11-13T10:15';
        $forgedStockValues['InventoryMovements']['stockBeforeMovement'] = '999';
        $forgedStockValues['InventoryMovements']['stockAfterMovement'] = '888';
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'new'), $forgedStockValues);
        // Attente corrigée à la relecture : EasyAdmin ignore un champ absent du formulaire (il ne le refuse pas). L'ajustement est donc
        // créé, avec les valeurs du serveur : stock réel de la variante, puis stock + quantité ; les 999 et 888 envoyés sont perdus.
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'champs stockBefore/stockAfter forgés : ignorés, pas enregistrés');
        $forgedRow = $this->db()->fetchAssociative('SELECT id, stock_before_movement, stock_after_movement, quantity, movement_type_id FROM inventory_movements ORDER BY id DESC LIMIT 1');
        $this->assertGreaterThan($maxIdBeforeForged, (int) $forgedRow['id']);
        $realStock = (int) $this->db()->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ?', [$variantId]);
        $this->assertSame([$realStock, $realStock + 6, 6, 4], [(int) $forgedRow['stock_before_movement'], (int) $forgedRow['stock_after_movement'], (int) $forgedRow['quantity'], (int) $forgedRow['movement_type_id']], 'valeurs du serveur, pas 999 ni 888');
        $maxIdBeforeForged = (int) $forgedRow['id'];

        $forgedTypeValues = $crawler->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
        $forgedTypeValues['InventoryMovements']['productVariant'] = (string) $variantId;
        $forgedTypeValues['InventoryMovements']['movementType'] = '2';
        $forgedTypeValues['InventoryMovements']['quantity'] = '6';
        $forgedTypeValues['InventoryMovements']['movementDate'] = '2026-11-13T10:15';
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'new'), $forgedTypeValues);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'type 2 forcé refusé par le formulaire');
        $this->assertSame($maxIdBeforeForged, (int) $this->db()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM inventory_movements'));

        // 6. Vérification directe de persistEntity() : refuse un autre type (403) et écrase toute valeur avant/après posée en amont
        $controller = static::getContainer()->get(InventoryMovementsCrudController::class);
        $em = $this->em();
        $managedVariant = $em->find(ProductVariant::class, $variantId);
        $nonAdjustment = (new InventoryMovements())
            ->setProductVariant($managedVariant)
            ->setMovementType($em->find(MovementType::class, 2))
            ->setQuantity(5)
            ->setMovementDate(new \DateTime('2026-11-13 10:20:00'));
        try {
            $controller->persistEntity($em, $nonAdjustment);
            $this->fail('persistEntity aurait dû refuser le type 2');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        $directAdj = (new InventoryMovements())
            ->setProductVariant($managedVariant)
            ->setMovementType($em->find(MovementType::class, InventoryMovementsCrudController::ADJUSTMENT_TYPE_ID))
            ->setQuantity(5)
            ->setMovementDate(new \DateTime('2026-11-13 10:25:00'))
            ->setStockBeforeMovement(999)
            ->setStockAfterMovement(888.0);
        $controller->persistEntity($em, $directAdj);
        $this->createdMovementIds[] = (int) $directAdj->getId();
        $directRow = $this->db()->fetchAssociative('SELECT stock_before_movement, stock_after_movement FROM inventory_movements WHERE id = ?', [$directAdj->getId()]);
        $this->assertSame(25, (int) $directRow['stock_before_movement']);
        $this->assertSame(30.0, (float) $directRow['stock_after_movement']);
    }

    public function testFreeAndUntypedMovementsRemainEditableAndDeletableWithLockedStockBeforeAndAfter(): void
    {
        $product = $this->create(Product::class);
        $variant = $this->create(ProductVariant::class, ['product' => $product, 'stockQuantity' => 10]);
        $variantId = (int) $variant->getId();

        $freeId = $this->insertMovement($variantId, 4, 5, 10, 15, null);
        $untypedId = $this->insertMovement($variantId, null, 2, 10, 12, null);

        $this->loginAs(['ROLE_ADMIN']);

        foreach (['ajustement libre' => $freeId, 'sans type' => $untypedId] as $label => $id) {
            $row = $this->indexRow(InventoryMovementsCrudController::class, 'inventory_movements', $id);
            $this->assertCount(1, $row, "$label : ligne visible dans la liste");
            $this->assertGreaterThan(0, $row->filter('a.action-edit, [data-action-name="edit"]')->count(), "$label : bouton Modifier présent");
            $this->assertGreaterThan(0, $row->filter('a.action-delete, [data-action-name="delete"]')->count(), "$label : bouton Supprimer présent");

            $detailCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'detail', $id));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$label : détail lisible");
            $this->assertGreaterThan(0, $detailCrawler->filter('a.action-edit, [data-action-name="edit"]')->count(), "$label : Modifier présent sur détail");
            $this->assertGreaterThan(0, $detailCrawler->filter('a.action-delete, [data-action-name="delete"]')->count(), "$label : Supprimer présent sur détail");
        }

        // Formulaire edit d'un ajustement libre : stockBefore/stockAfter affichés mais désactivés
        $editCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $freeId));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $beforeInput = $editCrawler->filter('input[name="InventoryMovements[stockBeforeMovement]"]');
        $afterInput = $editCrawler->filter('input[name="InventoryMovements[stockAfterMovement]"]');
        $this->assertCount(1, $beforeInput);
        $this->assertCount(1, $afterInput);
        $this->assertNotNull($beforeInput->attr('disabled'), 'stockBeforeMovement désactivé en édition');
        $this->assertNotNull($afterInput->attr('disabled'), 'stockAfterMovement désactivé en édition');

        // Modification valide de la quantité (5 -> 12) + tentative forgée sur stockBefore/stockAfter ignorée
        $forgedStockEdit = $editCrawler->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
        $forgedStockEdit['InventoryMovements']['quantity'] = '12';
        $forgedStockEdit['InventoryMovements']['stockBeforeMovement'] = '777';
        $forgedStockEdit['InventoryMovements']['stockAfterMovement'] = '888';
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $freeId), $forgedStockEdit);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());

        $rowAfterEdit = $this->db()->fetchAssociative('SELECT movement_type_id, quantity, stock_before_movement, stock_after_movement FROM inventory_movements WHERE id = ?', [$freeId]);
        $this->assertSame(4, (int) $rowAfterEdit['movement_type_id']);
        $this->assertSame(12, (int) $rowAfterEdit['quantity']);
        $this->assertSame(10, (int) $rowAfterEdit['stock_before_movement'], 'stockBeforeMovement reste inchangé malgré POST forgé');
        $this->assertSame(15.0, (float) $rowAfterEdit['stock_after_movement'], 'stockAfterMovement reste inchangé malgré POST forgé');

        // Tentative forgée de passer l'ajustement libre vers le type 2 (HTTP 422 + direct updateEntity 403)
        $editCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $freeId));
        $forgedTypeEdit = $editCrawler->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
        $forgedTypeEdit['InventoryMovements']['movementType'] = '2';
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $freeId), $forgedTypeEdit);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'passage en type 2 refusé par le formulaire');
        $this->assertSame(4, (int) $this->db()->fetchOne('SELECT movement_type_id FROM inventory_movements WHERE id = ?', [$freeId]));

        // Garde serveur updateEntity() : réimpose stockBefore/stockAfter lus en base et refuse type 2
        $controller = static::getContainer()->get(InventoryMovementsCrudController::class);
        $em = $this->em();
        $freeEntity = $em->find(InventoryMovements::class, $freeId);
        $freeEntity->setStockBeforeMovement(777)->setStockAfterMovement(888.0)->setQuantity(14);
        $controller->updateEntity($em, $freeEntity);
        $directAfter = $this->db()->fetchAssociative('SELECT quantity, stock_before_movement, stock_after_movement FROM inventory_movements WHERE id = ?', [$freeId]);
        $this->assertSame(14, (int) $directAfter['quantity']);
        $this->assertSame(10, (int) $directAfter['stock_before_movement']);
        $this->assertSame(15.0, (float) $directAfter['stock_after_movement']);

        $freeEntity->setMovementType($em->find(MovementType::class, 2));
        try {
            $controller->updateEntity($em, $freeEntity);
            $this->fail('updateEntity aurait dû refuser le passage en type verrouillé');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $em->clear();

        // Mouvement sans type : édition valide toujours acceptée (nécessaire aussi pour EA-FUSEAU)
        $untypedEdit = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $untypedId));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $untypedValues = $untypedEdit->filter('form[name="InventoryMovements"]')->form()->getPhpValues();
        $untypedValues['InventoryMovements']['quantity'] = '6';
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'edit', $untypedId), $untypedValues);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(6, (int) $this->db()->fetchOne('SELECT quantity FROM inventory_movements WHERE id = ?', [$untypedId]));

        // Suppression d'un ajustement libre : acceptée avec le jeton CSRF d'EasyAdmin
        $detailCrawler = $this->client->request('GET', $this->adminUrl(InventoryMovementsCrudController::class, 'detail', $freeId));
        $token = (string) $detailCrawler->filter('#delete-form input[name="token"]')->attr('value');
        $this->assertNotSame('', $token);
        $this->client->request('POST', $this->adminUrl(InventoryMovementsCrudController::class, 'delete', $freeId), ['token' => $token]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE id = ?', [$freeId]));
    }

    private function ensureMovementTypes(): void
    {
        $db = $this->db();
        $types = [
            1 => 'Entrant',
            2 => 'Sortant',
            3 => 'Return',
            4 => 'Ajustement',
            5 => 'Réception fournisseur',
        ];
        foreach ($types as $id => $name) {
            $db->executeStatement(
                'INSERT INTO movement_type (id, name, description) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING',
                [$id, $name, $name]
            );
        }
        $db->executeStatement("SELECT setval(pg_get_serial_sequence('movement_type', 'id'), GREATEST((SELECT MAX(id) FROM movement_type), 1))");
    }

    private function insertMovement(?int $variantId, ?int $typeId, int $qty, ?int $before, ?float $after, ?int $commandeLineId): int
    {
        $db = $this->db();
        $id = (int) $db->fetchOne("SELECT nextval('inventory_movements_id_seq')");
        $db->insert('inventory_movements', [
            'id' => $id,
            'product_variant_id' => $variantId,
            'movement_type_id' => $typeId,
            'quantity' => $qty,
            'movement_date' => '2026-11-13 09:00:00',
            'stock_before_movement' => $before,
            'stock_after_movement' => $after,
            'commande_line_id' => $commandeLineId,
        ]);
        $this->createdMovementIds[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestMovementForVariant(int $variantId): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT id, movement_type_id, quantity, stock_before_movement, stock_after_movement FROM inventory_movements WHERE product_variant_id = ? ORDER BY id DESC LIMIT 1',
            [$variantId]
        );
        $this->assertIsArray($row);

        return $row;
    }

    private function adminUrl(string $controller, string $action, int|string|null $id = null, array $extra = []): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(
            array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id]) + $extra
        );
    }

    private function indexRow(string $controller, string $table, int $id): Crawler
    {
        $position = (int) $this->db()->fetchOne("SELECT COUNT(*) FROM $table WHERE id <= ?", [$id]);
        $crawler = $this->client->request('GET', $this->adminUrl($controller, 'index', null, ['page' => intdiv($position - 1, 20) + 1]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$table : liste lisible");

        return $crawler->filter(sprintf('tr[data-id="%d"]', $id));
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'stock-verrou-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Stock')
            ->setLastname('Verrou')
            ->setRoles($roles)
            ->setIsVerified(true)
            ->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
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

    private function cleanupCreatedRows(): void
    {
        if ($this->createdMovementIds === [] && $this->createdCommandeLineIds === []) {
            return;
        }
        $db = $this->db();
        foreach (array_reverse($this->createdMovementIds) as $id) {
            $db->delete('inventory_movements', ['id' => $id]);
        }
        foreach (array_reverse($this->createdCommandeLineIds) as $id) {
            $db->delete('commande_line', ['id' => $id]);
        }
        $this->createdMovementIds = [];
        $this->createdCommandeLineIds = [];
    }
}

