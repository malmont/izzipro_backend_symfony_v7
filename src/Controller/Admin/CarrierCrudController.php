<?php

namespace App\Controller\Admin;

use App\Entity\Carrier;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class CarrierCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Carrier::class;
    }

    
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name'),
            TextField::new('description'),
            MoneyField::new('price')->setCurrency('USD'),
            ImageField::new('photo')->setBasePath('assets/uploads/Carrier/')
                ->setUploadDir('public/assets/uploads/Carrier/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
    
}
