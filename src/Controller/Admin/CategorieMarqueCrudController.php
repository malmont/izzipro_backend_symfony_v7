<?php

namespace App\Controller\Admin;

use App\Entity\CategorieMarque;
use App\Entity\Marque;
use App\Repository\MarqueRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class CategorieMarqueCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return CategorieMarque::class;
    }
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('nom', 'Nom de la catégorie'),
            
            AssociationField::new('marques', 'Marques associées')
            ->setFormTypeOptions([
                'em' => $tenantEm,
                'query_builder' => function (MarqueRepository $repo) {
                    return $repo->createQueryBuilder('m')->orderBy('m.titre', 'ASC');
                },
                'choice_label' => 'titre',
            ]),
        ];
    }
}
    

