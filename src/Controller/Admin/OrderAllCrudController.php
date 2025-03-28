<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Form\PaymentType;
use App\Form\OrderItemsType;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Component\HttpFoundation\JsonResponse;

class OrderAllCrudController extends AbstractCrudController
{
    private $createOrderUseCase;
    private $cancelOrderUseCase;
    private $em;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        EntityManagerInterface $em
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
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
        // On utilise le champ "userId" pour sélectionner le client.
        // Le champ "shippingAdress" sera automatiquement rempli dans persistEntity.
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('userId', 'Client'),
            AssociationField::new('orderSource', 'Order Source'),
            // On affiche l'adresse en lecture seule dans le détail, mais dans le formulaire elle sera vide.
            AssociationField::new('shippingAdress', 'Adresse de livraison')
                ->onlyOnDetail(),
            AssociationField::new('carrier', 'Transporteur')
                ->formatValue(function ($value, $entity) {
                    return $entity->getCarrier() ? $entity->getCarrier()->getName() : 'Transporteur non disponible';
                }),
            AssociationField::new('orderType', 'Order Type'),
            AssociationField::new('status', 'Order Status')
                ->formatValue(function ($value, $entity) {
                    return $entity->getStatus() ? $entity->getStatus()->getName() : '';
                }),
            CollectionField::new('orderItems', 'Items')
                ->allowAdd()
                ->allowDelete()
                ->setEntryType(OrderItemsType::class)
                ->setFormTypeOptions(['by_reference' => false]),
            MoneyField::new('totalAmount', 'Total Amount')->setCurrency('USD')->hideOnForm(),
            CollectionField::new('payments', 'Payments')
                ->setEntryType(PaymentType::class)
                ->allowAdd()
                ->allowDelete(),
            TextField::new('reference', 'Reference')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Order) {
            // Si aucune adresse n'est renseignée, on récupère la première adresse du client
            if (!$entityInstance->getShippingAdress() && $entityInstance->getUserId()) {
                $user = $entityInstance->getUserId();
                $adresses = $user->getAdresses(); // Assurez-vous que cette méthode renvoie une Collection
                if (!$adresses->isEmpty()) {
                    $firstAdress = $adresses->first();
                    $entityInstance->setShippingAdress($firstAdress);
                }
            }

            // Construction du tableau des items de commande
            $items = [];
            foreach ($entityInstance->getOrderItems() as $orderItem) {
                $productVariant = $orderItem->getProductVariant();
                $items[] = [
                    'productVariantId' => $productVariant->getId(),
                    'quantity'         => $orderItem->getQuantity(),
                    'size'             => $productVariant->getSize(),   // Vérifiez que la méthode existe
                    'color'            => $productVariant->getColor(),  // Vérifiez que la méthode existe
                ];
            }

            // Création du tableau de données pour le DTO
            $data = [
                'userId'        => $entityInstance->getUserId()->getId(),
                'orderSource'   => $entityInstance->getOrderSource()->getId(),
                // Pour une création manuelle, on peut passer null pour paymentMethod si aucun paiement n'est défini
                'paymentMethod' => $entityInstance->getPayments()->isEmpty()
                                    ? null
                                    : $entityInstance->getPayments()->first()->getPaymentMethod()->getId(),
                'addressId'     => $entityInstance->getShippingAdress()->getId(),
                'carrierId'     => $entityInstance->getCarrier()->getId(),
                'typeOrder'     => $entityInstance->getOrderType() ? $entityInstance->getOrderType()->getId() : null,
                'items'         => $items,
            ];

            $dto = new \App\Dto\CreateOrderDTO(
                $data['userId'],
                $data['orderSource'],
                $data['paymentMethod'],
                $data['addressId'],
                $data['carrierId'],
                $data['typeOrder'],
                $data['items'],
                null, // squarePaymentId
                null, // squareOrderId
                null, // squareReceiptUrl
                null, // squareStatus
                null, // squareCardBrand
                null, // squareLast4
                null  // squareRiskLevel
            );
            $response = $this->createOrderUseCase->execute($dto);

            if ($response instanceof JsonResponse && $response->getStatusCode() !== 201) {
                throw new \Exception('Failed to create order: ' . $response->getContent());
            }
            return;
        }

        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Order && $entityInstance->getStatus()->getId() === 7) {
            $this->cancelOrderUseCase->execute($entityInstance->getId());
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
