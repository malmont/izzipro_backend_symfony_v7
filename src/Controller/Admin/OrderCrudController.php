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
    use CashRecordLockTrait; // données de caisse non modifiables (C2)

    private AdminUrlGenerator $adminUrlGenerator;
    private CancelOrderUseCase $cancelOrderUseCase;

    // 3. Le constructeur appelle le parent et stocke ses propres dépendances
    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        AdminUrlGenerator $adminUrlGenerator,
        CancelOrderUseCase $cancelOrderUseCase,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // devise du site (lot 4, 07/11/2026)
    ) {
        parent::__construct($emProvider); // On passe la dépendance au parent
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
    }

    /** Fiche commande refondue (lot 4, 07/11/2026) : libellés en français, devise du site, plus récentes d'abord, aide */
    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Commande')->setEntityLabelInPlural('Commandes')->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['id', 'reference', 'userId.email', 'userId.lastname', 'trackingNumber'])
            ->setHelp('index', 'Ouvrez une commande (« Fiche ») pour tout voir et agir : capturer, rembourser, annuler, expédier ou acheter l\'étiquette, renvoyer un courriel. « Modifier » ne sert qu\'aux corrections avancées. Les commandes qui attendent une action sont dans « À traiter ».');
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
            DateTimeField::new('statusUpdatedAt', 'Statut mis à jour le')->setFormat('dd/MM/yyyy HH:mm')->hideOnIndex(),
            MoneyField::new('totalAmount', 'Montant total')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true),
            TextField::new('trackingNumber', 'Suivi')->hideOnForm()->hideOnDetail(),
            AssociationField::new('payments', 'Paiements')->onlyOnDetail(),
        ];
    }
    

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        if ($entityInstance instanceof Order && $entityInstance->getStatus()?->getId() === 7 && !$this->cancelFromForm($entityInstance, null)) {
            return;
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
        // « J'expédie moi-même » (livraison, lot 1, 07/11/2026) : page d'expédition, puis « Marquer livrée » ; jamais sur une vente au comptoir
        $ship = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('shipOrder', 'Marquer expédiée', 'fas fa-truck')
            ->linkToRoute('admin_order_ship', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $order instanceof Order && !self::isCounterSale($order) && in_array((int) ($order->getStatus()?->getId() ?? 0), \App\Services\OrderService\OrderShipmentService::SHIPPABLE_STATUSES, true));
        $deliver = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('deliverOrder', 'Marquer livrée', 'fas fa-box-open')
            ->linkToRoute('admin_order_ship', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $order instanceof Order && !self::isCounterSale($order) && (int) ($order->getStatus()?->getId() ?? 0) === \App\Services\OrderService\OrderShipmentService::STATUS_SHIPPED);
        // « Acheter l'étiquette » (lot 2 Livraison, 07/11/2026) : étiquette EasyPost par la plateforme (solde prépayé) ou par le compte du site
        $label = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('buyLabel', 'Acheter l\'étiquette', 'fas fa-tag')
            ->linkToRoute('admin_order_label', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $order instanceof Order && !self::isCounterSale($order) && $order->getShippingAdress() !== null && in_array((int) ($order->getStatus()?->getId() ?? 0), \App\Services\OrderService\OrderShipmentService::SHIPPABLE_STATUSES, true));
        // Opérations de support (lot 3, 07/11/2026) : rembourser, annuler, renvoyer un courriel, capturer un paiement autorisé ; jamais sur une vente au comptoir
        $web = fn ($order) => $order instanceof Order && !self::isCounterSale($order);
        $hasCard = fn (Order $order) => $order->getPayments()->exists(fn ($i, $p) => (float) $p->getAmount() > 0 && $p->getStripePaymentId() && $p->getStripeStatus() === 'succeeded');
        $refund = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('refundOrder', 'Rembourser', 'fas fa-undo')
            ->linkToRoute('admin_order_refund', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $web($order) && (int) ($order->getStatus()?->getId() ?? 0) !== 7 && $hasCard($order));
        $cancel = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('cancelOrder', 'Annuler', 'fas fa-ban')
            ->linkToRoute('admin_order_cancel', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $web($order) && in_array((int) ($order->getStatus()?->getId() ?? 0), [1, 2, 3, 4], true));
        $emails = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('orderEmails', 'Renvoyer un courriel', 'fas fa-envelope')
            ->linkToRoute('admin_order_emails', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $web($order) && $order->getUserId()?->getEmail());
        $capture = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('captureOrder', 'Capturer le paiement', 'fas fa-credit-card')
            ->linkToRoute('admin_order_refund', fn (Order $order) => ['id' => $order->getId()])
            ->displayIf(fn ($order) => $web($order) && $order->getPayments()->exists(fn ($i, $p) => (float) $p->getAmount() > 0 && $p->getStripePaymentId() && $p->getStripeStatus() === 'requires_capture'));
        // « Fiche » (lot 4) : la fiche refondue remplace le détail EasyAdmin dans la liste ; le détail EasyAdmin reste joignable depuis la fiche (Modifier)
        // retours web et adresse (lot 7, 07/11/2026)
        $return = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('returnOrder', 'Retour d\'articles', 'fas fa-exchange-alt')
            ->linkToRoute('admin_order_return', fn (Order $order) => ['id' => $order->getId()])->displayIf(fn (Order $order) => $order->getOrderSource()?->getId() !== 2 && in_array((int) ($order->getStatus()?->getId() ?? 0), [3, 4, 5, 6], true));
        $address = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('orderAddress', 'Changer l\'adresse', 'fas fa-map-marker-alt')
            ->linkToRoute('admin_order_address', fn (Order $order) => ['id' => $order->getId()])->displayIf(fn (Order $order) => $order->getOrderSource()?->getId() !== 2 && in_array((int) ($order->getStatus()?->getId() ?? 0), [1, 2, 3, 4], true));
        $sheet = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('orderSheet', 'Fiche', 'fas fa-file-alt')
            ->linkToRoute('admin_order_sheet', fn (Order $order) => ['id' => $order->getId()]);
        $actions = parent::configureActions($actions)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_INDEX, $sheet)->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $sheet)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $refund)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $return)->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $address)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $cancel)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $emails)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $capture)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $label)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $ship)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $deliver);

        return $this->lockCashRecordActions($actions);
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
