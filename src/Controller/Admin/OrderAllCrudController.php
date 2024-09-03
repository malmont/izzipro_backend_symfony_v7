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
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\HttpFoundation\Request;
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
                ->setEntryType(OrderItemsType::class)
                ->setFormTypeOptions([
                    'by_reference' => false,
                ]),
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
            // Pas besoin de persister explicitement les entités liées grâce au cascade persist
            $items = [];
            foreach ($entityInstance->getOrderItems() as $orderItem) {
                $items[] = [
                    'productVariantId' => $orderItem->getProductVariant()->getId(),
                    'quantity' => $orderItem->getQuantity(),
                ];
            }

            // Créer un tableau de données basé sur l'entité Order
            $data = [
                'orderSource' => $entityInstance->getOrderSource()->getId(),
                'paymentMethod' => $entityInstance->getPayments()->first()->getPaymentMethod()->getId(),
                'addressId' => $entityInstance->getShippingAdress()->getId(),
                'carrierId' => $entityInstance->getCarrier()->getId(),
                'items' => $items,
            ];

            // Convertir les données en JSON
            $jsonData = json_encode($data);

            // Créer un objet Request avec un contenu JSON
            $request = new Request(
                [],   // Query parameters
                [],   // Request parameters
                [],   // Attributes
                [],   // Cookies
                [],   // Files
                [],   // Server
                $jsonData // The raw body data
            );

            // Définir le type de contenu de la requête comme JSON
            $request->headers->set('Content-Type', 'application/json');

            // Appelez le UseCase de création
            $response = $this->createOrderUseCase->execute($request);

            if ($response instanceof JsonResponse && $response->getStatusCode() !== 201) {
                throw new \Exception('Failed to create order: ' . $response->getContent());
            }
        }

        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Order && $entityInstance->getStatus()->getName() === 'Annulé') {
            $response = $this->cancelOrderUseCase->execute($entityInstance->getId());

            if ($response instanceof JsonResponse && $response->getStatusCode() !== 200) {
                throw new \Exception('Failed to cancel order: ' . $response->getContent());
            }
        }

        parent::updateEntity($em, $entityInstance);
    }
}
