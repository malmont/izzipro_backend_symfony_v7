<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\ShippingOrder;
use App\Repository\OrderRepository;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

// 2. On étend notre contrôleur de base
class ShippingOrderCrudController extends BaseTenantCrudController
{
    // 3. Le constructeur est hérité du parent, pas besoin de le réécrire ici
    //    car nous n'avons pas de dépendances supplémentaires.

    public static function getEntityFqcn(): string
    {
        return ShippingOrder::class;
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('odershipping', 'Commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),
            TextField::new('carrierAccountId', 'Account ID'),
            TextField::new('service', 'Service'),
            MoneyField::new('totalPrice', 'Prix total')->setCurrency('USD')->setStoredAsCents(false),
            DateTimeField::new('createdAt', 'Date')->onlyOnIndex(),
            AssociationField::new('parcels', 'Colis')->hideOnForm(),
        ];
    }
    
}