<?php
namespace App\Controller\Admin;

use App\Entity\PaymentMethod;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use App\Form\PaymentMethodTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField; 

class PaymentMethodCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentMethod::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de la Méthode de Paiement'),
            TextEditorField::new('description', 'Description')->hideOnIndex(),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(PaymentMethodTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }
}
