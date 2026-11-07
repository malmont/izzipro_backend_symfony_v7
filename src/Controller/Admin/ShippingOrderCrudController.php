<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\ShippingOrder;
use App\Repository\OrderRepository;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

// 2. On étend notre contrôleur de base
class ShippingOrderCrudController extends BaseTenantCrudController
{
    // 3. Le constructeur est hérité du parent, pas besoin de le réécrire ici
    //    car nous n'avons pas de dépendances supplémentaires.

    public static function getEntityFqcn(): string
    {
        return ShippingOrder::class;
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null)
    {
        parent::__construct($emProvider);
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Expédition')->setEntityLabelInPlural('Expéditions (étiquettes achetées)')
            ->setHelp('index', 'Une ligne par achat d\'étiquettes EasyPost pour une commande : transporteur, service, prix payé, colis et étiquettes. Pour une commande expédiée par vos soins, le suivi est sur la commande elle-même (« Marquer expédiée »). Rien ne se saisit ici.');
    }

    /** Écran de consultation (lot 1 bis, 07/11/2026) : ces lignes naissent de l'achat d'une étiquette, elles ne se créent ni ne se modifient à la main */
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return parent::configureActions($actions)
            ->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::EDIT, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('odershipping', 'Commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),
            TextField::new('carrierAccountId', 'Compte transporteur EasyPost'),
            TextField::new('service', 'Service du transporteur'),
            MoneyField::new('totalPrice', 'Prix payé')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(false),
            DateTimeField::new('createdAt', 'Date')->onlyOnIndex(),
            AssociationField::new('parcels', 'Colis')->hideOnForm(),
        ];
    }
    
}