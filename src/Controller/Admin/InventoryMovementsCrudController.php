<?php
namespace App\Controller\Admin;

use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Entity\ProductVariant;
use App\Repository\MovementTypeRepository;
use App\Repository\ProductVariantRepository;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

// 2. On étend notre contrôleur de base
class InventoryMovementsCrudController extends BaseTenantCrudController
{
    // 3. Le constructeur est SUPPRIMÉ. Le parent s'en occupe !

    public static function getEntityFqcn(): string
    {
        return InventoryMovements::class;
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IntegerField::new('id')->hideOnForm(),
            
            AssociationField::new('productVariant', 'Product Variant')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductVariantRepository $repo) {
                        return $repo->createQueryBuilder('pv')->orderBy('pv.id', 'ASC');
                    },
                    'choice_label' => 'id',
                ]),
            AssociationField::new('movementType', 'Movement Type')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (MovementTypeRepository $repo) {
                        return $repo->createQueryBuilder('mt')->orderBy('mt.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
                
            IntegerField::new('stockBeforeMovement', 'stockBeforeMovement'),
            IntegerField::new('stockAfterMovement', 'stockAfterMovement'),
            IntegerField::new('quantity', 'QuantityMoved'),
            DateTimeField::new('movementDate', 'Movement Date'),
        ];
    }
}