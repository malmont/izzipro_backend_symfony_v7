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
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null) // devise du site (07/11/2026)
    {
        parent::__construct($emProvider);
    }

    use CashRecordLockTrait; // données de caisse non modifiables (C2)



    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            
            AssociationField::new('orderAssociated', 'Commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),
            AssociationField::new('productVariant', 'Variante de produit')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductVariantRepository $repo) {
                        return $repo->createQueryBuilder('pv')->orderBy('pv.id', 'ASC');
                    },
                    'choice_label' => 'id', // ou une autre propriété de ProductVariant
                ]),

            IntegerField::new('quantity', 'Quantité'),
            MoneyField::new('unitPrice', 'Prix unitaire')->setCurrency($this->currency?->code() ?? 'CAD'),
            MoneyField::new('totalPrice', 'Prix total')->setCurrency($this->currency?->code() ?? 'CAD'),

        ];
    }

    protected function isCashRecordLocked(object $entity): bool
    {
        return $entity instanceof \App\Entity\OrderItems && self::isCounterSale($entity->getOrderAssociated());
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $this->lockCashRecordActions(parent::configureActions($actions));
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
