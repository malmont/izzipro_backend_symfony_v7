<?php

namespace App\Controller\Admin;

use App\Entity\Emploi;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class EmploiCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Emploi::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre de l\'offre'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
            AssociationField::new('candidatures', 'Candidatures')->onlyOnIndex(),
        ];
    }
}
