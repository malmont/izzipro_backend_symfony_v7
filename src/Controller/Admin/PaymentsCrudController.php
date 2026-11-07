<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\PaymentMethod;
use App\Entity\Payments;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use App\Repository\OrderRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\PaymentTypeRepository;
use App\Repository\StatusPaymentRepository;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

// 2. On étend notre contrôleur de base
class PaymentsCrudController extends BaseTenantCrudController
{
    use CashRecordLockTrait; // données de caisse non modifiables (C2)

    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Payments::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Paiement')
                    ->setEntityLabelInPlural('Paiements')
                    ->setHelp('index', 'Les encaissements et remboursements de chaque commande (montants en cents dans la devise du site). Pour rembourser, annuler ou renvoyer un reçu, passez par la commande : bouton « Rembourser » ci-dessous ou fiche de la commande.')
                    ->setSearchFields(['id', 'amount', 'paymentDate', 'stripePaymentId', 'stripeStatus']);
    }

    /** Opérations de support (lot 3, 07/11/2026) : rembourser depuis la commande du paiement, ouvrir le reçu Stripe */
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        $refund = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('refundFromOrder', 'Rembourser', 'fas fa-undo')
            ->linkToRoute('admin_order_refund', fn (Payments $p) => ['id' => $p->getOrderPayment()?->getId()])
            ->displayIf(fn ($p) => $p instanceof Payments && $p->getOrderPayment() !== null && $p->getOrderPayment()->getOrderSource()?->getId() !== 2 && (float) $p->getAmount() > 0 && $p->getStripePaymentId() && $p->getStripeStatus() === 'succeeded');
        $receipt = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('stripeReceipt', 'Reçu Stripe', 'fas fa-receipt')
            ->linkToUrl(fn (Payments $p) => (string) $p->getStripeReceiptUrl())
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener'])
            ->displayIf(fn ($p) => $p instanceof Payments && (string) $p->getStripeReceiptUrl() !== '');

        return $this->lockCashRecordActions(parent::configureActions($actions))
            ->add(Crud::PAGE_INDEX, $refund)->add(Crud::PAGE_DETAIL, $refund)
            ->add(Crud::PAGE_INDEX, $receipt)->add(Crud::PAGE_DETAIL, $receipt);
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        $fields = [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('orderPayment', 'Commande')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderRepository $repo) => $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC'),
                    'choice_label' => 'reference'
                ]),
            MoneyField::new('amount', 'Montant')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true),
            DateTimeField::new('paymentDate', 'Date'),
            AssociationField::new('paymentMethod', 'Moyen de paiement')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(PaymentMethodRepository $repo) => $repo->createQueryBuilder('pm')->orderBy('pm.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('statutPayment', 'Statut')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StatusPaymentRepository $repo) => $repo->createQueryBuilder('sp')->orderBy('sp.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('paymentType', 'Type (encaissement ou remboursement)')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(PaymentTypeRepository $repo) => $repo->createQueryBuilder('pt')->orderBy('pt.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            TextField::new('stripePaymentId', 'Paiement Stripe (ou remboursement)')->hideOnForm(),
            UrlField::new('stripeReceiptUrl', 'Reçu Stripe')->hideOnForm()->hideOnIndex(),
            TextField::new('stripeStatus', 'État Stripe')->hideOnForm(),
            TextField::new('stripeCardBrand', 'Carte')->hideOnForm()->hideOnIndex(),
            TextField::new('stripeLast4', '4 derniers chiffres')->hideOnForm()->hideOnIndex(),
            TextField::new('stripeRiskLevel', 'Risque (Stripe)')->hideOnForm()->hideOnIndex(),

        ];

        // Cette logique conditionnelle doit rester ici car elle est spécifique
        if ($pageName === Crud::PAGE_DETAIL && $this->getContext()->getEntity()->getInstance()->getTransactionCaisses()->count() > 0) {
            $fields[] = CollectionField::new('transactionCaisses', 'Transaction Caisse')
                ->hideOnForm()
                ->allowAdd(false)
                ->allowDelete(false);
        }

        return $fields;
    }
    
    // 5. Les méthodes createIndexQueryBuilder, persistEntity, updateEntity, et deleteEntity
    //    ont été SUPPRIMÉES car la logique de base du parent est suffisante.

    protected function isCashRecordLocked(object $entity): bool
    {
        return $entity instanceof \App\Entity\Payments && self::isCounterSale($entity->getOrderPayment());
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
