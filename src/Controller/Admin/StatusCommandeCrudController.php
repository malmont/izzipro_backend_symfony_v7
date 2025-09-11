<?php
namespace App\Controller\Admin;

use App\Entity\StatusCommande;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use App\Form\StatusCommandeTranslationType;


class StatusCommandeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StatusCommande::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Status Commande')
            ->setEntityLabelInPlural('Status Commandes')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Name'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
            CollectionField::new('orders', 'Orders')->onlyOnDetail(),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(StatusCommandeTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }
}