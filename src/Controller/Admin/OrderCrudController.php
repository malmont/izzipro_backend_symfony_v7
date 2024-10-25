<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderItems;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use App\UseCase\OrderUseCase\CancelOrderUseCase;

class OrderCrudController extends AbstractCrudController
{
    private $em;
    private $adminUrlGenerator;
    private $cancelOrderUseCase;

    public function __construct(EntityManagerInterface $em, AdminUrlGenerator $adminUrlGenerator, CancelOrderUseCase $cancelOrderUseCase)
    {
        $this->em = $em;
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('reference', 'Référence'),
            AssociationField::new('userId', 'Client'),
            AssociationField::new('carrier', 'Transporteur')
                ->formatValue(function ($value, $entity) {
                    return $entity->getCarrier() ? $entity->getCarrier()->getName() : 'Transporteur non disponible';
                }),
            AssociationField::new('shippingAdress', 'Adresse de livraison')
                ->formatValue(function ($value, $entity) {
                    if ($entity->getShippingAdress()) {
                        $address = $entity->getShippingAdress()->getAddress();
                        $city = $entity->getShippingAdress()->getCity();
                        return $address . ', ' . $city;
                    } else {
                        return 'Adresse non disponible';
                    }
                }),
            AssociationField::new('orderSource', 'Source de la commande'),
            AssociationField::new('orderType', 'Type de commande'),
            AssociationField::new('status', 'Statut de la commande')
                ->formatValue(function ($value, $entity) {
                    return $entity->getStatus() ? $entity->getStatus()->getName() : '';
                }),
            DateTimeField::new('orderDate', 'Date de commande')
                ->setFormat('dd/MM/yyyy HH:mm'),
            MoneyField::new('totalAmount', 'Montant total')->setCurrency('USD'),
            
            AssociationField::new('orderItems', 'Articles de la commande')
                ->formatValue(function ($value, $entity) {
                    $orderItemsUrl = $this->adminUrlGenerator
                        ->setController(OrderItemsListController::class)
                        ->setAction('index')
                        ->set('orderId', $entity->getId())
                        ->generateUrl();
    
                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les articles de la commande</a>',
                        $orderItemsUrl
                    );
                })
                ->renderAsHtml(),
            
            AssociationField::new('payments', 'Paiements')->onlyOnDetail(),
        ];
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Order && $entityInstance->getStatus()->getId() === 7) {
            // Appel du UseCase pour annuler la commande
            $this->cancelOrderUseCase->execute($entityInstance->getId());
        }

        // Continuer la mise à jour de l'entité
        parent::updateEntity($entityManager, $entityInstance);
    }
}
