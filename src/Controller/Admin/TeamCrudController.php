<?php

namespace App\Controller\Admin;

use App\Entity\Team;
use App\Form\TeamTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\{
    IdField,
    TextField,
    TextareaField,
    ImageField,
    IntegerField,
    CollectionField
};

class TeamCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Team::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Membre de l\'équipe')
            ->setEntityLabelInPlural('Membres de l\'équipe')
            ->setSearchFields(['name', 'role', 'description'])
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom complet');
        yield TextField::new('role', 'Rôle (Défaut)');
        yield TextareaField::new('description', 'Description (Défaut)')->hideOnIndex();
        
        yield ImageField::new('image', 'Image de profil')
            ->setBasePath('/assets/uploads/team/')
            ->setUploadDir('public/assets/uploads/team')
            ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
            ->setRequired(false);

        yield IntegerField::new('gemsuiteTeamId', 'ID Gemsuite (Optionnel)')->hideOnIndex();

        yield CollectionField::new('translations', 'Traductions')
            ->setEntryType(TeamTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
    }
}
