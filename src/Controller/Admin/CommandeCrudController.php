<?php
namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\Collections;
use App\Entity\Fournisseur;
use App\Entity\CollectionPicture;
use App\Repository\CollectionsRepository;
use App\Repository\FournisseurRepository;
use App\Repository\CollectionPictureRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class CommandeCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Commande::class;
    }


    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            MoneyField::new('budget', 'Budget')->setCurrency('USD')->setStoredAsCents(false),
            DateField::new('date', 'Date'),
            TextField::new('name', 'Nom de la Commande'),
            BooleanField::new('isClosed', 'isClosed'),

            AssociationField::new('collections', 'Collections')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionsRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.nomCollection', 'ASC');
                    },
                    'choice_label' => 'nomCollection',
                ]),
            AssociationField::new('fournisseur', 'Fournisseur Associé')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (FournisseurRepository $repo) {
                        return $repo->createQueryBuilder('f')->orderBy('f.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            AssociationField::new('commandepictures', 'Image de la collection')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionPictureRepository $repo) {
                        return $repo->createQueryBuilder('cp')->orderBy('cp.id', 'ASC');
                    },
                    'choice_label' => 'imageUrl',
                ]),
            
            ImageField::new('commandepictures.imageUrl', 'Aperçu de l\'image')
                ->setBasePath('assets/images/')
                ->onlyOnIndex(),
        ];
    }
    
}