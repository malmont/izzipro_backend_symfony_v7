<?php
namespace App\Controller\Admin;

use App\Entity\OrderSource;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;

class OrderSourceCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrderSource::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de la Source de Commande'),
            TextEditorField::new('description', 'Description')->hideOnIndex(),
        ];
    }
}
