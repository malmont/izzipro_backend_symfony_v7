<?php

namespace App\Controller\Admin;

use App\Entity\CategorieMarque;
use App\Form\CategorieMarqueTranslationType; // On importe le nouveau FormType
use App\Repository\MarqueRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField; // On importe CollectionField
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
            AssociationField::new('marques', 'Marques associées')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'by_reference' => false, 
                    'query_builder' => function (MarqueRepository $repo) {
                        return $repo->createQueryBuilder('m')->orderBy('m.titre', 'ASC');
                    },
                    'choice_label' => 'titre',
                ]),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(CategorieMarqueTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('nom', 'Nom de la catégorie'),
        ];
    }
}