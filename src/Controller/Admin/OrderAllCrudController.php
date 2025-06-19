<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\OrderSource;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
use App\Entity\Adress;
use App\Form\PaymentType as PaymentFormType; // Renommé pour éviter conflit de nom
use App\Form\OrderItemsType;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Repository\UserRepository;
use App\Repository\OrderSourceRepository;
use App\Repository\AdressRepository;
use App\Repository\CarrierRepository;
use App\Repository\OrderTypeRepository;
use App\Repository\StatusCommandeRepository;
use App\Dto\CreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use Symfony\Component\HttpFoundation\JsonResponse;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class OrderAllCrudController extends AbstractCrudController
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;
    private TenantEntityManagerProvider $emProvider;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        TenantEntityManagerProvider $emProvider
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->emProvider = $emProvider;
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
        // MODIFICATION 2 : On rend les champs d'association "tenant-aware"
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
                ->onlyOnDetail(), // Gardé en lecture seule pour la simplicité
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

    // MODIFICATION 3 : On surcharge toutes les méthodes CRUD
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Order::class)->createQueryBuilder('entity');
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        // Votre logique personnalisée est conservée, mais elle appelle maintenant
        // des UseCases qui sont eux-mêmes "tenant-aware".
        // Le `parent::persistEntity` est remplacé par l'appel à votre UseCase.
        if ($entityInstance instanceof Order) {
            if (!$entityInstance->getShippingAdress() && $entityInstance->getUserId()) {
                $user = $entityInstance->getUserId();
                $adresses = $user->getAdresses();
                if (!$adresses->isEmpty()) {
                    $entityInstance->setShippingAdress($adresses->first());
                }
            }

            // ... (logique de construction du DTO, inchangée)
            
            // On s'assure que le User est bien managé par notre EM
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
        // Votre logique personnalisée est conservée
        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7) {
            $this->cancelOrderUseCase->execute($entityInstance->getId(), /* paymentMethodId ? */);
        }

        // On utilise l'EM du tenant pour sauvegarder les autres changements
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}