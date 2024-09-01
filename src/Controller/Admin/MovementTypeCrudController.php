<?php
namespace App\Controller\Admin;

use App\Entity\MovementType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class MovementTypeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MovementType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
        ];
    }
}
