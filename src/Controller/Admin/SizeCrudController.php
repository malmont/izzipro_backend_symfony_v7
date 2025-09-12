<?php
namespace App\Controller\Admin;

use App\Entity\Size;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Form\SizeTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;


class SizeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Size::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Size Name'),
            TextField::new('code', 'Code')
                ->setHelp('Identifiant unique pour la logique du site (ex: "s", "m", "42", "us-10"). Ne pas traduire.'),
            CollectionField::new('translations', 'Traductions du nom')
                ->setEntryType(SizeTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }
}