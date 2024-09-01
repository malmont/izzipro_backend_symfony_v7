<?php
namespace App\Controller\Admin;

use App\Entity\InventoryMovements;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class InventoryMovementsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return InventoryMovements::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IntegerField::new('id')->hideOnForm(),
            AssociationField::new('productVariant', 'Product Variant'),
            AssociationField::new('movementType', 'Movement Type'),
            IntegerField::new('quantity', 'Quantity'),
            DateField::new('movementDate', 'Movement Date'),
        ];
    }
}
