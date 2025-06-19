<?php
namespace App\Controller\Admin;

use App\Entity\Parcel;
use App\Entity\ShippingOrder;
use App\Repository\ShippingOrderRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;


class ParcelCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Parcel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            
            AssociationField::new('shippingOrder', 'Shipping Order')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ShippingOrderRepository $repo) {
                        return $repo->createQueryBuilder('so')->orderBy('so.createdAt', 'DESC');
                    },
                    'choice_label' => 'id',
                ]),

            IntegerField::new('index', 'Colis #'),
            NumberField::new('weight', 'Poids (kg)'),
            NumberField::new('length', 'Longueur (cm)'),
            NumberField::new('width', 'Largeur (cm)'),
            NumberField::new('height', 'Hauteur (cm)'),
            NumberField::new('price', 'Prix')->setStoredAsCents(false)->onlyOnIndex(),
            AssociationField::new('shippingLabel', 'Étiquette')->hideOnForm(),
        ];
    }
}