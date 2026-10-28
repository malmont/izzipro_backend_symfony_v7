<?php

namespace App\Controller\Admin;

use App\Entity\ServiceFamily;
use App\Form\ServiceFamilyTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Familles de prestations (28/10/2026) : regroupent les offres de service dans les formulaires à étapes */
class ServiceFamilyCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceFamily::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Famille de prestations')
            ->setEntityLabelInPlural('Familles de prestations')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom (français)');
        yield IntegerField::new('position', 'Ordre')->setHelp('Croissant ; à égalité, l\'ordre de création.');
        yield CollectionField::new('translations', 'Traductions')
            ->setEntryType(ServiceFamilyTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
    }
}
