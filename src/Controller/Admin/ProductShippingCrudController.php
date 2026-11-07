<?php
namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ShippingClass;
use App\Repository\ProductRepository;
use App\Repository\ShippingClassRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator; 


class ProductShippingCrudController extends BaseTenantCrudController
{


    public static function getEntityFqcn(): string
    {
        return ProductShipping::class;
    }


    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Fiche poids et dimensions')->setEntityLabelInPlural('Poids et dimensions des produits')
            ->setHelp('index', 'Une fiche par produit à expédier : poids emballé et dimensions, utilisés pour les colis, les tarifs et les étiquettes. Plus rapide : la grille « Saisir les poids » de la page Livraison (saisie en masse, estimation par l\'IA). Sans fiche, un produit hérite les valeurs de sa classe d\'expédition, sinon le poids par défaut du site.');
    }

    public function configureFields(string $pageName): iterable
    {

        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),

            AssociationField::new('product', 'Produit')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function ($repo) {
                        return $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            // --- CORRECTION ICI ---
            // On pointe vers les nouvelles propriétés en KG et CM
            NumberField::new('weightKg', 'Poids emballé (kg)')->setNumDecimals(3)->setRequired(true)->setHelp('Le produit avec son emballage de vente.'),
            NumberField::new('lengthCm', 'Longueur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('widthCm', 'Largeur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('heightCm', 'Hauteur (cm)')->setNumDecimals(1)->hideOnIndex(),
            // --- FIN CORRECTION ---

            AssociationField::new('shippingClassEntity', 'Classe d’expédition')
                ->setRequired(false)
                ->setHelp('Standard, Fragile, Volumineux, Lourd… : regroupe les produits dans les mêmes colis et fournit des valeurs par défaut.')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ShippingClassRepository $repo) {
                        return $repo->createQueryBuilder('sc')->orderBy('sc.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
        ];
    }
    
}

