<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\OrderSource;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
use App\Entity\Adress;
use App\Form\PaymentType as PaymentFormType;
use App\Form\OrderItemsType;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController;
use App\Repository\UserRepository;
use App\Repository\OrderSourceRepository;
use App\Repository\CarrierRepository;
use App\Repository\OrderTypeRepository;
use App\Repository\StatusCommandeRepository;
use App\Dto\CreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use App\Dto\ICreateOrderDTO;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrderAllCrudController extends BaseTenantCrudController
{
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;

    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase
    ) {
        parent::__construct($emProvider); // On passe la dépendance au parent
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
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
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('userId', 'Client')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(UserRepository $repo) => $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC'),
                    'choice_label' => 'email',
                ]),
            AssociationField::new('orderSource', 'Order Source')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderSourceRepository $repo) => $repo->createQueryBuilder('os')->orderBy('os.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('shippingAdress', 'Adresse de livraison')
                ->onlyOnDetail(),
            AssociationField::new('carrier', 'Transporteur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(CarrierRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('orderType', 'Order Type')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderTypeRepository $repo) => $repo->createQueryBuilder('ot')->orderBy('ot.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('status', 'Order Status')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StatusCommandeRepository $repo) => $repo->createQueryBuilder('sc')->orderBy('sc.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            CollectionField::new('orderItems', 'Items')
                ->allowAdd()->allowDelete()->setEntryType(OrderItemsType::class)->setFormTypeOptions(['by_reference' => false]),
            MoneyField::new('totalAmount', 'Total Amount')->setCurrency('USD')->setStoredAsCents(false)->hideOnForm(),
            CollectionField::new('payments', 'Payments')
                ->setEntryType(PaymentFormType::class)->allowAdd()->allowDelete(),
            TextField::new('reference', 'Reference')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Order) {
            if (!$entityInstance->getShippingAdress() && $entityInstance->getUserId()) {
                $user = $entityInstance->getUserId();
                $adresses = $user->getAdresses();
                if (!$adresses->isEmpty()) {
                    $entityInstance->setShippingAdress($adresses->first());
                }
            }

            $items = [];
            foreach ($entityInstance->getOrderItems() as $orderItem) {
                $productVariant = $orderItem->getProductVariant();
                $items[] = [
                    'productVariantId' => $productVariant->getId(),
                    'quantity'         => $orderItem->getQuantity(),
                    'size'             => $productVariant->getSize(),
                    'color'            => $productVariant->getColor(),
                ];
            }
            
            $paymentData = []; // A remplir si nécessaire
            $paymentMethods = [];
            if (!$entityInstance->getPayments()->isEmpty()) {
                foreach($entityInstance->getPayments() as $payment) {
                    $paymentMethods[] = new PaymentMethodDTO(
                        $payment->getPaymentMethod()->getId(), 
                        $payment->getAmount()
                    );
                }
            }

            $dto = new CreateOrderMultiPaymentDTO(
                $entityInstance->getUserId()->getId(),
                $entityInstance->getOrderSource()->getId(),
                $paymentMethods,
                $entityInstance->getShippingAdress()->getId(),
                $entityInstance->getCarrier()->getId(),
                $entityInstance->getOrderType() ? $entityInstance->getOrderType()->getId() : null,
                $items,
                $entityInstance->getCarrier()->getPrice(),
                $paymentData['squarePaymentId'] ?? null,
                $paymentData['squareOrderId'] ?? null,
                $paymentData['squareReceiptUrl'] ?? null,
                $paymentData['squareStatus'] ?? null,
                $paymentData['squareCardBrand'] ?? null,
                $paymentData['squareLast4'] ?? null,
                $paymentData['squareRiskLevel'] ?? null
            );

            $tenantEm = $this->emProvider->getEntityManager();
            $tenantEm->persist($entityInstance->getUserId());

            $response = $this->createOrderUseCase->execute($dto);

            if ($response instanceof JsonResponse && $response->getStatusCode() !== 201) {
                throw new \Exception('Failed to create order via UseCase: ' . $response->getContent());
            }
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7) {
            $this->cancelOrderUseCase->execute($entityInstance->getId(), $entityInstance->getPayments()->first()->getPaymentMethod()->getId());
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}