<?php

namespace App\Controller\Admin;

use App\Entity\CommandeLine;
use App\Entity\InventoryMovements;
use App\Repository\MovementTypeRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Mouvements de stock dans EasyAdmin (lot STOCK-VERROU, 13/11/2026) : les mouvements issus d'une vente, d'un retour ou d'une
 * réception fournisseur sont en lecture seule (modèle `CashRecordLockTrait`) ; seuls les ajustements manuels restent modifiables
 * et créables (sans effet sur `product_variant.stock_quantity`, une erreur de stock se corrige sur la fiche de la variante).
 */
class InventoryMovementsCrudController extends BaseTenantCrudController
{
    public const ADJUSTMENT_TYPE_ID = 4;

    /** Types verrouillés : 1 Entrant, 2 Sortant, 3 Return, 5 Réception fournisseur. */
    public const LOCKED_TYPE_IDS = [1, 2, 3, 5];

    private const LOCKED_MESSAGE = 'Ce mouvement vient d\'une vente, d\'un retour ou d\'une réception : il ne se modifie pas. Corrigez le stock par un ajustement.';

    public static function getEntityFqcn(): string
    {
        return InventoryMovements::class;
    }

    /** Règle unique de verrouillage : toute ligne liée à un achat ou d'un type verrouillé est en lecture seule. */
    public static function isMovementLocked(?int $movementTypeId, ?int $commandeLineId): bool
    {
        return $commandeLineId !== null || ($movementTypeId !== null && in_array($movementTypeId, self::LOCKED_TYPE_IDS, true));
    }

    public function configureActions(Actions $actions): Actions
    {
        $editable = fn ($entity): bool => !is_object($entity) || !$this->isEntityLocked($entity);
        $actions = parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::BATCH_DELETE);

        foreach ([Crud::PAGE_INDEX, Crud::PAGE_DETAIL, Crud::PAGE_EDIT] as $page) {
            foreach ([Action::EDIT, Action::DELETE] as $name) {
                try {
                    $actions->update($page, $name, fn (Action $action): Action => $action->displayIf($editable));
                } catch (\Throwable) {
                    // action absente de cette page
                }
            }
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $stockBefore = IntegerField::new('stockBeforeMovement', 'stockBeforeMovement')
            ->hideWhenCreating();
        $stockAfter = IntegerField::new('stockAfterMovement', 'stockAfterMovement')
            ->hideWhenCreating();
        if ($pageName === Crud::PAGE_EDIT) {
            $stockBefore->setFormTypeOption('disabled', true);
            $stockAfter->setFormTypeOption('disabled', true);
        }

        $fields = [
            IntegerField::new('id')->hideOnForm(),

            AssociationField::new('productVariant', 'Variante de produit')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductVariantRepository $repo) {
                        return $repo->createQueryBuilder('pv')->orderBy('pv.id', 'ASC');
                    },
                    'choice_label' => 'id',
                ]),
            AssociationField::new('movementType', 'Movement Type')
                ->setRequired($pageName === Crud::PAGE_NEW) // à la création, un oubli se signale sur le champ (retouche de la relecture)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (MovementTypeRepository $repo) {
                        return $repo->createQueryBuilder('mt')
                            ->where('mt.id = :adjustment')
                            ->setParameter('adjustment', self::ADJUSTMENT_TYPE_ID)
                            ->orderBy('mt.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            AssociationField::new('commandeLine', 'Ligne d\'achat')
                ->hideOnForm()
                ->formatValue(static function ($value): string {
                    if (!$value instanceof CommandeLine) {
                        return '—';
                    }
                    $commande = $value->getCommande();
                    $commandeLabel = $commande?->getName() ?: ($commande?->getId() ? 'Commande #' . $commande->getId() : '');
                    $productName = $value->getProduct()?->getName() ?? '';
                    $parts = array_filter([$commandeLabel, $productName], static fn (string $s): bool => $s !== '');

                    return '#' . $value->getId() . ($parts !== [] ? ' — ' . implode(' · ', $parts) : '');
                }),

            $stockBefore,
            $stockAfter,
            IntegerField::new('quantity', 'QuantityMoved'),
            DateTimeField::new('movementDate', 'Movement Date'),
        ];

        return $fields;
    }

    public function edit(AdminContext $context)
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof InventoryMovements) {
            $this->denyIfLocked($entity);
        }

        return parent::edit($context);
    }

    public function delete(AdminContext $context)
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof InventoryMovements) {
            $this->denyIfLocked($entity);
        }

        return parent::delete($context);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof InventoryMovements) {
            if ($entityInstance->getMovementType()?->getId() !== self::ADJUSTMENT_TYPE_ID) {
                throw new AccessDeniedHttpException('Seul un mouvement d\'ajustement (type 4) peut être créé ici.');
            }

            $stockBefore = null;
            $stockAfter = null;
            $variantId = $entityInstance->getProductVariant()?->getId();
            if ($variantId !== null) {
                $rawStock = $this->emProvider->getEntityManager()->getConnection()->fetchOne(
                    'SELECT stock_quantity FROM product_variant WHERE id = ?',
                    [$variantId]
                );
                if ($rawStock !== false && $rawStock !== null) {
                    $stockBefore = (int) $rawStock;
                    $stockAfter = (float) ($stockBefore + (int) ($entityInstance->getQuantity() ?? 0));
                }
            }

            $entityInstance->setStockBeforeMovement($stockBefore);
            $entityInstance->setStockAfterMovement($stockAfter);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof InventoryMovements) {
            $row = $this->denyIfLocked($entityInstance);
            if (self::isMovementLocked($entityInstance->getMovementType()?->getId(), $entityInstance->getCommandeLine()?->getId())) {
                throw new AccessDeniedHttpException(self::LOCKED_MESSAGE);
            }
            if ($row !== null) {
                $entityInstance->setStockBeforeMovement(
                    $row['stock_before_movement'] !== null ? (int) $row['stock_before_movement'] : null
                );
                $entityInstance->setStockAfterMovement(
                    $row['stock_after_movement'] !== null ? (float) $row['stock_after_movement'] : null
                );
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof InventoryMovements) {
            $this->denyIfLocked($entityInstance);
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function isEntityLocked(object $entity): bool
    {
        return $entity instanceof InventoryMovements
            && self::isMovementLocked($entity->getMovementType()?->getId(), $entity->getCommandeLine()?->getId());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function denyIfLocked(InventoryMovements $entity): ?array
    {
        $id = $entity->getId();
        if ($id === null) {
            if ($this->isEntityLocked($entity)) {
                throw new AccessDeniedHttpException(self::LOCKED_MESSAGE);
            }

            return null;
        }

        $row = $this->fetchMovementRow($id);
        $locked = $row !== null && self::isMovementLocked(
            $row['movement_type_id'] !== null ? (int) $row['movement_type_id'] : null,
            $row['commande_line_id'] !== null ? (int) $row['commande_line_id'] : null,
        );
        if ($locked) {
            throw new AccessDeniedHttpException(self::LOCKED_MESSAGE);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMovementRow(int $id): ?array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT movement_type_id, commande_line_id, stock_before_movement, stock_after_movement FROM inventory_movements WHERE id = ?',
            [$id]
        );

        return $row === false ? null : $row;
    }
}
