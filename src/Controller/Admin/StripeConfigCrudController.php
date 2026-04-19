<?php

namespace App\Controller\Admin;

use App\Entity\StripeConfig;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Controller\Admin\BaseTenantCrudController;

class StripeConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StripeConfig::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id', 'ID')->hideOnForm(),
            TextField::new('accountId', 'Stripe Account ID'),
            BooleanField::new('isActive', 'Compte Actif'),
        ];
    }
}
