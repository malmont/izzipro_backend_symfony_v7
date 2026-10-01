<?php

namespace App\Controller\Admin\Memoires;

use App\Controller\Admin\BaseTenantCrudController;
use App\MemoiresVivantes\Entity\Book;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use App\MemoiresVivantes\Services\BookFontCatalog;

class BookCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Book::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Livre Mémoires Vivantes')
            ->setEntityLabelInPlural('Livres Mémoires Vivantes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Titre');
        yield TextField::new('subtitle', 'Sous-titre');
        yield TextField::new('type', 'Type (Individuel, Couple, Famille)');
        yield TextField::new('format', 'Format');
        yield ChoiceField::new('font', 'Police du livre')->setChoices(array_column(BookFontCatalog::all(), 'code', 'label'))->setRequired(false)->hideOnIndex();
        yield TextareaField::new('clientAddress', 'Adresse du client (séances)')->hideOnIndex();
        yield AssociationField::new('user', 'Utilisateur')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}
