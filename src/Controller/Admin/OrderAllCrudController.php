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
    use CashRecordLockTrait; // données de caisse non modifiables (C2)

    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;

    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // devise du site (07/11/2026)
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
            AssociationField::new('orderSource', 'Origine')
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
            AssociationField::new('orderType', 'Type de commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderTypeRepository $repo) => $repo->createQueryBuilder('ot')->orderBy('ot.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('status', 'Statut')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StatusCommandeRepository $repo) => $repo->createQueryBuilder('sc')->orderBy('sc.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            CollectionField::new('orderItems', 'Articles')
                ->allowAdd()->allowDelete()->setEntryType(OrderItemsType::class)->setFormTypeOptions(['by_reference' => false]),
            MoneyField::new('totalAmount', 'Montant total')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(false)->hideOnForm(),
            CollectionField::new('payments', 'Paiements')
                ->setEntryType(PaymentFormType::class)->allowAdd()->allowDelete(),
            TextField::new('reference', 'Référence')->hideOnForm(),
            // Vente au comptoir (source « Pos ») créée à la main : poste de caisse obligatoire, numérotée et journalisée (C2)
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posCashRegisterId', 'Poste de caisse (vente au comptoir)')
                ->setChoices(array_column(array_map(fn ($r) => [$r->getName(), $r->getId()], $this->emProvider->getEntityManager()->getRepository(\App\Entity\CashRegister::class)->findAllOrdered(true)), 1, 0))
                ->setRequired(false)->onlyWhenCreating()
                ->setHelp('Obligatoire si la source est la caisse (Pos)'),
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
                if ($productVariant === null) {
                    continue; // article libre de caisse (F7) : pas de variante
                }
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

            if ($entityInstance->getOrderSource()?->getId() === 2) {
                if (!$entityInstance->getPosCashRegisterId()) {
                    $this->addFlash('danger', 'Vente au comptoir refusée : indiquez le poste de caisse.');
                    return;
                }
                $dto->setCashRegisterId($entityInstance->getPosCashRegisterId());
            }

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
        $this->denyLockedCashRecord($entityInstance);
        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7) {
            $payment = $entityInstance->getPayments()->first(); // false sans paiement (avant : erreur)
            if (!$this->cancelFromForm($entityInstance, $payment ? $payment->getPaymentMethod()?->getId() : null)) {
                return;
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * D2 (PAYS-1b, 12/11/2026) : annulation demandée par le formulaire (statut passé à 7). Appelée seulement si la commande n'était pas déjà
     * annulée en base ; en cas d'échec (Stripe, caisse…), rien n'est enregistré, la fiche est rechargée et le message s'affiche.
     *
     * @return bool vrai si l'enregistrement peut continuer
     */
    private function cancelFromForm(Order $order, ?int $paymentMethodId): bool
    {
        $em = $this->emProvider->getEntityManager();
        if ((int) $em->getConnection()->fetchOne('SELECT status_id FROM "order" WHERE id = ?', [$order->getId()]) === 7) {
            return true; // déjà annulée : simple modification de la fiche
        }
        $response = $this->cancelOrderUseCase->execute($order->getId(), $paymentMethodId);
        if ($response->getStatusCode() === 200) {
            return true;
        }
        $body = json_decode((string) $response->getContent(), true) ?: [];
        $em->refresh($order);
        $this->addFlash('danger', 'Annulation impossible, rien n\'a été enregistré : ' . ($body['message'] ?? $body['error'] ?? 'erreur inconnue'));

        return false;
    }

    protected function isCashRecordLocked(object $entity): bool
    {
        return $entity instanceof \App\Entity\Order && self::isCounterSale($entity);
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $this->lockCashRecordActions(parent::configureActions($actions));
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
