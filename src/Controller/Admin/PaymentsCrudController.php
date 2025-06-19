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
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;


class PaymentsCrudController extends AbstractCrudController
{
    /**
     * 1. On injecte notre provider
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public static function getEntityFqcn(): string
    {
        return Payments::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Payment')
                    ->setEntityLabelInPlural('Payments')
                    ->setSearchFields(['id', 'amount', 'paymentDate', 'squarePaymentId', 'squareStatus']);
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $fields = [
            IdField::new('id')->hideOnForm(),

            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
            AssociationField::new('orderPayment', 'Order')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderRepository $repo) => $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC'),
                    'choice_label' => 'reference'
                ]),

            MoneyField::new('amount', 'Amount')->setCurrency('USD')->setStoredAsCents(false),
            DateTimeField::new('paymentDate', 'Payment Date'),

            AssociationField::new('paymentMethod', 'Payment Method')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(PaymentMethodRepository $repo) => $repo->createQueryBuilder('pm')->orderBy('pm.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('statutPayment', 'Payment Status')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StatusPaymentRepository $repo) => $repo->createQueryBuilder('sp')->orderBy('sp.name', 'ASC'),
                    'choice_label' => 'name'
                ]),
            AssociationField::new('paymentType', 'Payment Type')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(PaymentTypeRepository $repo) => $repo->createQueryBuilder('pt')->orderBy('pt.name', 'ASC'),
                    'choice_label' => 'name'
                ]),

            TextField::new('squarePaymentId', 'Square Payment ID')->hideOnForm(),
            TextField::new('squareOrderId', 'Square Order ID')->hideOnForm(),
            UrlField::new('squareReceiptUrl', 'Receipt URL')->hideOnIndex(),
            TextField::new('squareStatus', 'Payment Status')->hideOnForm(),
            TextField::new('squareCardBrand', 'Card Brand')->hideOnForm(),
            TextField::new('squareLast4', 'Card Last 4')->hideOnForm(),
            TextField::new('squareRiskLevel', 'Risk Level')->hideOnForm(),
        ];

        if ($pageName === Crud::PAGE_DETAIL && $this->getContext()->getEntity()->getInstance()->getTransactionCaisses()->count() > 0) {
            $fields[] = CollectionField::new('transactionCaisses', 'Transaction Caisse')
                ->hideOnForm()
                ->allowAdd(false)
                ->allowDelete(false);
        }

        return $fields;
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Payments::class)->createQueryBuilder('entity');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
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