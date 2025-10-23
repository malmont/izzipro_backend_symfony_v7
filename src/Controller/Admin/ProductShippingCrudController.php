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


    public function configureFields(string $pageName): iterable
    {

        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),

            AssociationField::new('product', 'Produit')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductRepository $repo) {
                        return $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            NumberField::new('weight', 'Poids (kg)')->setNumDecimals(2)->setRequired(true),
            NumberField::new('length', 'Longueur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('width', 'Largeur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('height', 'Hauteur (cm)')->setNumDecimals(1)->hideOnIndex(),

            AssociationField::new('shippingClassEntity', 'Classe d’expédition')
                ->setRequired(false)
                ->setHelp('Laisse vide pour l’instant si non configuré')
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
