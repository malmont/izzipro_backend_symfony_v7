<?php

namespace App\Controller\Admin\Memoires;

use App\Controller\Admin\BaseTenantCrudController;
use App\MemoiresVivantes\Entity\Chapter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ChapterCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Chapter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Chapitre Mémoires Vivantes')
            ->setEntityLabelInPlural('Chapitres Mémoires Vivantes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('book', 'Livre');
        yield TextField::new('theme', 'Thème');
        yield TextField::new('title', 'Titre');
        yield IntegerField::new('position', 'Position');
        yield TextField::new('generationStatus', 'Statut Génération');
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}
