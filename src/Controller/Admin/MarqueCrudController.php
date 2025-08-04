<?php

namespace App\Controller\Admin;

use App\Entity\Marque;
use App\Entity\CategorieMarque;
use App\Repository\CategorieMarqueRepository;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class MarqueCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Marque::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre de la marque'),
            ImageField::new('logosMarques', 'Logo')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            AssociationField::new('categories', 'Catégories')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'by_reference' => false, 
                    'query_builder' => function (CategorieMarqueRepository $repo) {
                        return $repo->createQueryBuilder('cm')->orderBy('cm.nom', 'ASC');
                    },
                    'choice_label' => 'nom',
                ]),
        ];
    }
}