<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;


class OrderItemsCrudController extends BaseTenantCrudController
{


    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            
            AssociationField::new('orderAssociated', 'Order')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),
            AssociationField::new('productVariant', 'Product Variant')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductVariantRepository $repo) {
                        return $repo->createQueryBuilder('pv')->orderBy('pv.id', 'ASC');
                    },
                    'choice_label' => 'id', // ou une autre propriété de ProductVariant
                ]),

            IntegerField::new('quantity', 'Quantity'),
            MoneyField::new('unitPrice', 'Unit Price')->setCurrency('USD')->setStoredAsCents(false),
            MoneyField::new('totalPrice', 'Total Price')->setCurrency('USD')->setStoredAsCents(false),
        ];
    }

}