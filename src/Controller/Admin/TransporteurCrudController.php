<?php

namespace App\Controller\Admin;

use App\Entity\Transporteur;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;

class TransporteurCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transporteur::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextField::new('contact', 'Contact'),
            ImageField::new('logo')->setBasePath('/bucket-simulator/assets/uploads/Carrier/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/Carrier/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
 
        ];
    }
}
