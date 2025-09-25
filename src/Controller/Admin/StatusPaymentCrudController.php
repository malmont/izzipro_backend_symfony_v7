<?php
namespace App\Controller\Admin;

use App\Entity\StatusPayment;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use App\Form\StatusPaymentTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

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
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(StatusPaymentTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }
}