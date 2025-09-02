<?php

namespace App\Controller\Admin;

use App\Entity\PresentationGroup;
use App\Controller\Admin\BaseTenantCrudController;
use App\Repository\PresentationRepository; // On importe le repository
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class PresentationGroupCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return PresentationGroup::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre du groupe'),
            AssociationField::new('presentations', 'Présentations associées')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'by_reference' => false, 
                    'query_builder' => function (PresentationRepository $repo) {
                        return $repo->createQueryBuilder('p')->orderBy('p.titre', 'ASC');
                    },
                    'choice_label' => 'titre',
                ]),
        ];
    }
}
