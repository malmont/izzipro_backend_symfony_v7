<?php

namespace App\Controller\Admin;

use App\Entity\SquareConfig;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

class SquareConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SquareConfig::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('accessToken', 'Access Token')->hideOnIndex(),
            TextField::new('applicationId', 'Application ID'),
            TextField::new('locationId', 'Location ID'), // Ajout du champ locationId
            BooleanField::new('isActive', 'Actif'),
        ];
    }
}
