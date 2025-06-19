<?php
namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Repository\TypeFournisseurRepository;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class FournisseurCrudController extends BaseTenantCrudController
{


    public static function getEntityFqcn(): string
    {
        return Fournisseur::class;
    }


    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom du Fournisseur'),
            
            AssociationField::new('typeFournisseur', 'Type de fournisseur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TypeFournisseurRepository $repo) {
                        return $repo->createQueryBuilder('tf')->orderBy('tf.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            ImageField::new('typeFournisseur.photo', 'Logo type de fournisseur')
                ->setBasePath('/assets/images/')
                ->onlyOnIndex(),
            TextField::new('adresse', 'Adresse'),
            TextField::new('ville', 'Ville'),
            TextField::new('pays', 'Pays'),
            TextField::new('tel', 'Téléphone'),
        ];
    }
}