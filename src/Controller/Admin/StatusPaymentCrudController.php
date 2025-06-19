<?php
namespace App\Controller\Admin;

use App\Entity\StatusPayment;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;

// 2. On étend notre contrôleur de base
class StatusPaymentCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StatusPayment::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom du Statut de Paiement'),
            TextEditorField::new('description', 'Description')->hideOnIndex(),
        ];
    }
}