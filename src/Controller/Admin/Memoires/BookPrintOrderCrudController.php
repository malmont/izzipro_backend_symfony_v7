<?php

namespace App\Controller\Admin\Memoires;

use App\Controller\Admin\BaseTenantCrudController;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Services\LuluPrintService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class BookPrintOrderCrudController extends BaseTenantCrudController
{
    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private readonly LuluPrintService $luluPrintService,
        private readonly AdminUrlGenerator $adminUrlGenerator
    ) {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return BookPrintOrder::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Commande d\'impression')
            ->setEntityLabelInPlural('Commandes d\'impression (Lulu & FedEx)')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        // Action personnalisée : Synchroniser avec Lulu
        $syncLuluAction = Action::new('syncLulu', 'Synchroniser Lulu', 'fas fa-sync')
            ->linkToCrudAction('syncWithLulu')
            ->setCssClass('btn btn-sm btn-outline-info');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $syncLuluAction)
            ->add(Crud::PAGE_DETAIL, $syncLuluAction);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')->hideOnForm()->formatValue(fn($val) => substr((string)$val, 0, 8) . '...');
        yield AssociationField::new('book', 'Livre');
        yield AssociationField::new('user', 'Demandeur');

        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'Brouillon' => 'draft',
                'Devis estimé' => 'estimated',
                'Créé chez Lulu' => 'created',
                'En impression' => 'in_production',
                'Expédié' => 'shipped',
                'Annulé' => 'canceled',
                'Erreur' => 'error',
            ])
            ->renderAsBadges([
                'draft' => 'secondary',
                'estimated' => 'info',
                'created' => 'primary',
                'in_production' => 'warning',
                'shipped' => 'success',
                'canceled' => 'dark',
                'error' => 'danger',
            ]);

        yield TextField::new('recipientName', 'Destinataire');
        yield TextField::new('city', 'Ville')->onlyOnDetail();
        yield TextField::new('postalCode', 'Code Postal')->onlyOnDetail();
        yield TextField::new('countryCode', 'Pays')->onlyOnDetail();
        yield TextField::new('phoneNumber', 'Téléphone')->onlyOnDetail();

        yield TextField::new('shippingLevel', 'Mode Livraison')
            ->formatValue(function ($val) {
                return match ($val) {
                    'EXPEDITED' => 'FedEx Express (2-3j)',
                    'GROUND' => 'FedEx Ground (3-5j)',
                    'EXPRESS' => 'FedEx Prioritaire (1-2j)',
                    'MAIL' => 'Poste Standard',
                    default => (string)$val,
                };
            });

        yield IntegerField::new('quantity', 'Qté');

        yield TextField::new('totalCost', 'Total (CAD)')
            ->formatValue(fn($val, $entity) => $val ? ($val . ' ' . $entity->getCurrency()) : '-');

        yield TextField::new('carrierName', 'Transporteur')
            ->formatValue(fn($val) => $val ?: 'FedEx Canada');

        yield TextField::new('trackingNumber', 'N° Suivi')->onlyOnDetail();

        yield UrlField::new('trackingUrl', 'Suivi Colis')
            ->hideOnForm()
            ->formatValue(function ($val, $entity) {
                if ($val) {
                    return $val;
                }
                if ($entity->getTrackingNumber()) {
                    return 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($entity->getTrackingNumber());
                }
                return null;
            });

        yield TextField::new('luluPrintJobId', 'Job ID Lulu')->hideOnIndex();

        yield UrlField::new('interiorPdfUrl', 'PDF Intérieur')
            ->hideOnForm()
            ->onlyOnDetail();

        yield UrlField::new('coverPdfUrl', 'PDF Couverture')
            ->hideOnForm()
            ->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Date Commande')->hideOnForm();
        yield DateTimeField::new('shippedAt', 'Date Expédition')->hideOnForm()->onlyOnDetail();
    }

    /**
     * Action manuelle pour synchroniser le statut Lulu et le suivi colis.
     */
    public function syncWithLulu(AdminContext $context): Response
    {
        /** @var BookPrintOrder|null $order */
        $order = $context->getEntity()->getInstance();
        if ($order && $order->getLuluPrintJobId()) {
            $this->luluPrintService->syncOrderStatus($order);
            $this->addFlash('success', sprintf('Commande #%s synchronisée avec succès auprès de Lulu (Statut: %s).', substr($order->getId()->toRfc4122(), 0, 8), $order->getStatus()));
        } else {
            $this->addFlash('warning', 'Aucun Job ID Lulu associé à cette commande pour synchronisation.');
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }
}
