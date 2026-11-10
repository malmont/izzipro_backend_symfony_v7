<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\ProductVariant;
use App\Entity\MovementType;
use App\Services\OrderService\InventoryMovementService;
use App\Services\TenantEntityManagerProvider; 

class UpdateStockAndInventoryUseCase
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    private TenantEntityManagerProvider $emProvider;
    private InventoryMovementService $inventoryMovementService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        InventoryMovementService $inventoryMovementService
    ) {
        $this->emProvider = $emProvider;
        $this->inventoryMovementService = $inventoryMovementService;
    }

    public function execute(ProductVariant $productVariant, int $quantity, bool $isCancellation = false): void
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();
        $movementTypeRepository = $em->getRepository(MovementType::class);

        $stockBeforeMovement = $this->lockedStock($productVariant); // relu sous verrou : plus de mise à jour perdue (10/11/2026)
        $newQuantity = $isCancellation ? $quantity : -$quantity;
        $productVariant->setStockQuantity($stockBeforeMovement + $newQuantity);

        // On utilise l'EM du tenant pour la persistance
        $em->persist($productVariant);

        $stockAfterMovement = $productVariant->getStockQuantity();
        // On récupère le type de mouvement depuis le bon repository
        $movementType = $movementTypeRepository->find($isCancellation ? 1 : 2);

        $inventoryMovement = $this->inventoryMovementService->createInventoryMovement(
            $productVariant,
            $stockBeforeMovement,
            $stockAfterMovement,
            $quantity,
            $movementType,
            $isCancellation,
        );

        // On utilise l'EM du tenant pour la persistance
        $em->persist($inventoryMovement);
    }

    /**
     * Stock de départ d'une vente, d'une annulation ou d'un retour (10/11/2026). Avant, l'écriture partait de la valeur chargée en mémoire au
     * début de la requête, puis l'écrivait en valeur absolue : deux ventes simultanées de la même variante, ou une vente et une réception,
     * pouvaient perdre l'une des deux mises à jour. Désormais, la ligne est verrouillée (SELECT … FOR UPDATE, efficace dans la transaction de
     * la vente ou de l'annulation) et relue en base. On y ajoute ce que la requête a déjà changé en mémoire sur cette variante (deux lignes de
     * la même variante sur un ticket, ou un appelant qui a préparé l'entité). La valeur d'origine connue de Doctrine est recalée sur la base,
     * sinon un stock final égal à la valeur chargée ne serait pas écrit. Variante pas encore enregistrée : valeur en mémoire, comme avant.
     */
    private function lockedStock(ProductVariant $variant): int
    {
        $current = (int) $variant->getStockQuantity();
        if ($variant->getId() === null) {
            return $current;
        }
        $em = $this->emProvider->getEntityManager();
        $uow = $em->getUnitOfWork();
        if (!$uow->isInIdentityMap($variant)) {
            return $current;
        }
        $db = $em->getConnection()->fetchOne('SELECT stock_quantity FROM product_variant WHERE id = ? FOR UPDATE', [$variant->getId()]);
        if ($db === false || $db === null) {
            return $current;
        }
        $original = (int) ($uow->getOriginalEntityData($variant)['stockQuantity'] ?? $current);
        $uow->setOriginalEntityProperty(spl_object_id($variant), 'stockQuantity', (int) $db);
        $fresh = (int) $db + ($current - $original);
        $variant->setStockQuantity($fresh);

        return $fresh;
    }

    public function executeNewProductVariant(ProductVariant $productVariant, int $stockBeforeMovement, int $movementTypeId): void
    {
        $em = $this->emProvider->getEntityManager();
        $movementTypeRepository = $em->getRepository(MovementType::class);

        $stockAfterMovement = $productVariant->getStockQuantity();

        if ($stockBeforeMovement > $stockAfterMovement) {
            $quantity = $stockBeforeMovement - $stockAfterMovement;
            $quantity = $quantity * -1;
        } else {
            $quantity = $stockAfterMovement - $stockBeforeMovement;
        }
        
        $movementType = $movementTypeRepository->find($movementTypeId);

        $inventoryMovement = $this->inventoryMovementService->createInventoryMovement(
            $productVariant,
            $stockBeforeMovement,
            $stockAfterMovement,
            $quantity,
            $movementType,
            false,
        );
        $em->persist($inventoryMovement);
    }

    /**
     * Réception fournisseur d'une ligne d'achat (lot R1 de la gestion, 10/11/2026, relu par la session de la caisse) : la quantité (positive)
     * s'ajoute au stock de la variante, mouvement type 5 « Réception fournisseur » ; une quantité négative annule une réception, mouvement
     * type 4 « Ajustement ». Aucun flush : l'appelant tient une transaction explicite (les rappels de ProductVariant re-flushent). La vente
     * (`execute`) et la création de variante (`executeNewProductVariant`) ne sont pas touchées.
     */
    public function executeReception(ProductVariant $productVariant, int $quantity, ?\App\Entity\CommandeLine $line = null): \App\Entity\InventoryMovements
    {
        $em = $this->emProvider->getEntityManager();
        $stockBefore = $productVariant->getStockQuantity();
        $productVariant->setStockQuantity($stockBefore + $quantity);
        $em->persist($productVariant);
        $movementType = $em->getRepository(MovementType::class)->find($quantity >= 0 ? 5 : 4);
        if ($movementType === null) {
            throw new \LogicException(sprintf('Type de mouvement %d absent : migration gestion_reception non appliquée sur cette base.', $quantity >= 0 ? 5 : 4));
        }
        $movement = $this->inventoryMovementService->createInventoryMovement($productVariant, $stockBefore, $productVariant->getStockQuantity(), $quantity, $movementType, false);
        $movement->setCommandeLine($line);
        $em->persist($movement);

        return $movement;
    }
}
