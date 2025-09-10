<?php
namespace App\Controller\Admin;

use App\Entity\Carrier;
use App\Form\CarrierTranslationType; // On importe le nouveau FormType
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField; // On importe CollectionField
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CarrierCrudController extends BaseTenantCrudController
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
            TextField::new('carrierAccountId', 'ID Compte Transporteur'),
            MoneyField::new('price', 'Prix')->setCurrency('USD'),
            ImageField::new('photo')->setBasePath('assets/uploads/Carrier/')
                ->setUploadDir('public/assets/uploads/Carrier/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(CarrierTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('name', 'Nom (Défaut)')->onlyOnIndex(),
        ];
    }
}