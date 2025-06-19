<?php
namespace App\Controller\Admin;

use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Repository\CarrierRepository;
use App\Repository\OrderSourceRepository;
use App\Repository\OrderTypeRepository;
use App\Repository\StatusCommandeRepository;
use App\Repository\UserRepository;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class OrderCrudController extends AbstractCrudController
{
    // MODIFICATION 1 : On injecte notre provider
    private TenantEntityManagerProvider $emProvider;
    private AdminUrlGenerator $adminUrlGenerator;
    private CancelOrderUseCase $cancelOrderUseCase;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        AdminUrlGenerator $adminUrlGenerator,
        CancelOrderUseCase $cancelOrderUseCase
    ) {
        $this->emProvider = $emProvider;
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // MODIFICATION 2 : On rend les champs d'association "tenant-aware"
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('reference', 'Référence'),
            AssociationField::new('userId', 'Client')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(UserRepository $repo) => $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC'),
                    'choice_label' => 'email'
                ]),
            AssociationField::new('carrier', 'Transporteur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(CarrierRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('shippingAdress', 'Adresse de livraison')->onlyOnDetail(), // Pas de sélection
            AssociationField::new('orderSource', 'Source de la commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderSourceRepository $repo) => $repo->createQueryBuilder('os')->orderBy('os.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('orderType', 'Type de commande')
                 ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderTypeRepository $repo) => $repo->createQueryBuilder('ot')->orderBy('ot.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('status', 'Statut de la commande')
                 ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StatusCommandeRepository $repo) => $repo->createQueryBuilder('sc')->orderBy('sc.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            DateTimeField::new('orderDate', 'Date de commande')->setFormat('dd/MM/yyyy HH:mm'),
            DateTimeField::new('statusUpdatedAt', 'Date de updateStatut')->setFormat('dd/MM/yyyy HH:mm'),
            MoneyField::new('totalAmount', 'Montant total')->setCurrency('USD')->setStoredAsCents(false),
            AssociationField::new('payments', 'Paiements')->onlyOnDetail(),
            // ... autres champs
        ];
    }
    
    // ... configureActions reste inchangé ...

    // MODIFICATION 3 : On surcharge toutes les méthodes CRUD
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Order::class)->createQueryBuilder('entity');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();

        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7) {
            // Le UseCase est déjà tenant-aware
            $this->cancelOrderUseCase->execute($entityInstance->getId(), /* paymentMethod? */);
        }

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