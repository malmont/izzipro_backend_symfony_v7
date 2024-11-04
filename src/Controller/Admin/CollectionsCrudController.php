<?php

namespace App\Controller\Admin;

use App\Entity\Collections;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class CollectionsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Collections::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('nomCollection', 'Nom de la Collection'),
            MoneyField::new('budgetCollection', 'Budget')->setCurrency('USD'),
            DateField::new('startDateCollection', 'Date de Début'),
            DateField::new('endDateCollection', 'Date de Fin'),
            BooleanField::new('del', 'Supprimé'),
            TextField::new('photoCollection', 'URL de la Photo'),
            ImageField::new('photoCollection', 'Photo')
                ->setBasePath('')
                ->onlyOnIndex(),
            AssociationField::new('userCollections', 'Utilisateur'),
            BooleanField::new('isClosed', 'isClosed'),
        ];
    }
}
