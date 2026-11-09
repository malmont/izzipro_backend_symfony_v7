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
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('currency', 'Devise d\'achat')->setChoices(array_combine(\App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED, \App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED))->setRequired(false)
                ->setHelp('Devise des commandes passées à ce fournisseur (budget, coût d\'achat des produits rattachés, frais de port). Vide : devise du site. Une commande en devise étrangère porte un taux vers la devise du site (lot G6, 10/11/2026).'),
        ];
    }
}