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
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;


// 2. On étend notre contrôleur de base
class OrderCrudController extends BaseTenantCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;
    private CancelOrderUseCase $cancelOrderUseCase;

    // 3. Le constructeur appelle le parent et stocke ses propres dépendances
    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        AdminUrlGenerator $adminUrlGenerator,
        CancelOrderUseCase $cancelOrderUseCase
    ) {
        parent::__construct($emProvider); // On passe la dépendance au parent
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }
    
    // 4. On CONSERVE configureFields car il est spécifique
    public function configureFields(string $pageName): iterable
    {
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
            AssociationField::new('shippingAdress', 'Adresse de livraison')->onlyOnDetail(),
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
            MoneyField::new('totalAmount', 'Montant total')->setCurrency('CAD'),
            AssociationField::new('payments', 'Paiements')->onlyOnDetail(),
        ];
    }
    

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7) {
            $this->cancelOrderUseCase->execute($entityInstance->getId(), /* paymentMethodId? */);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
    

}