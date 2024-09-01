<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

class OrderAllCrudController extends AbstractCrudController
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('orderSource', 'Order Source'),
            AssociationField::new('shippingAdress', 'Shipping Address'),
            AssociationField::new('carrier', 'Carrier'),
            AssociationField::new('status', 'Order Status')
                ->formatValue(function ($value, $entity) {
                    return $entity->getStatus() ? $entity->getStatus()->getName() : '';
                }),
            CollectionField::new('orderItems', 'Items')
                ->allowAdd()
                ->allowDelete()
                ->useEntryCrudForm(OrderItemsCrudController::class),
            MoneyField::new('totalAmount', 'Total Amount')->setCurrency('USD')->hideOnForm(),
            CollectionField::new('payments', 'Payments')->onlyOnDetail(),
            TextField::new('reference', 'Reference')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Order) {
            // Logique simplifiée pour la création d'une commande de test
            $entityInstance->setReference('TEST#' . uniqid());
            $entityInstance->setOrderDate(new \DateTime());

            // Vous pouvez ajouter plus de logique ici si nécessaire
        }

        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Order) {
            // Gérer l'annulation de la commande dans EasyAdmin
            if ($entityInstance->getStatus()->getName() === 'Annulé') {
                $this->handleOrderCancellation($entityInstance);
            }
        }

        parent::updateEntity($em, $entityInstance);
    }

    private function handleOrderCancellation(Order $order): void
    {
        // Annuler la commande et rembourser
        $payments = $order->getPayments();
        $refundStatus = $this->em->getRepository(StatusPayment::class)->findOneBy(['name' => 'Remboursé']);

        foreach ($payments as $payment) {
            $refund = new Payments();
            $refund->setOrderPayment($order);
            $refund->setAmount(-$payment->getAmount());
            $refund->setPaymentMethod($payment->getPaymentMethod());
            $refund->setStatutPayment($refundStatus);
            $refund->setPaymentDate(new \DateTime());

            $this->em->persist($refund);
        }

        foreach ($order->getOrderItems() as $orderItem) {
            $productVariant = $orderItem->getProductVariant();
            $productVariant->setStockQuantity($productVariant->getStockQuantity() + $orderItem->getQuantity());

            $movementType = $this->em->getRepository(MovementType::class)->findOneBy(['name' => 'incoming']);
            $inventoryMovement = new InventoryMovements();
            $inventoryMovement->setProductVariant($productVariant);
            $inventoryMovement->setQuantity($orderItem->getQuantity());
            $inventoryMovement->setMovementType($movementType);
            $inventoryMovement->setMovementDate(new \DateTime());

            $this->em->persist($inventoryMovement);
        }

        // Si l'ordre provient de la caisse, créer une transaction de remboursement
        if ($order->getOrderSource()->getName() === 'caisse') {
            $this->createCaisseRefundTransaction($order);
        }

        $this->em->flush();
    }

    private function createCaisseRefundTransaction(Order $order): void
    {
        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->em->getRepository(TransactionType::class)->findOneBy(['name' => 'Remboursement']);
        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse.setCaisse($caisse);
        $transactionCaisse.setUser($this->getUser());
        $transactionCaisse.setOrder($order);
        $transactionCaisse.setTransactionDate(new \DateTime());
        $transactionCaisse.setTransactionType($transactionType);
        $transactionCaisse.setAmount(-$order->getTotalAmount());

        $this->em->persist($transactionCaisse);
    }

    private function getOpenCaisse(): ?Caisse
    {
        return $this->em->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
}
