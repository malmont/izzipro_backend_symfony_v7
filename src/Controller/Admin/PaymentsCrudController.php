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
    // 3. Le constructeur est SUPPRIMÉ. Le parent s'en occupe !

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

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        $fields = [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('orderPayment', 'Order')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(OrderRepository $repo) => $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC'),
                    'choice_label' => 'reference'
                ]),
            MoneyField::new('amount', 'Amount')->setCurrency('USD'),
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
            TextField::new('stripePaymentId', 'stripe Payment ID')->hideOnForm(),
            TextField::new('stripeReceiptUrl', 'stripe Receipt URL')->hideOnForm(),
            UrlField::new('stripeStatus', 'stripe Status')->hideOnIndex(),
            TextField::new('stripeCardBrand', 'stripe Card Brand')->hideOnForm(),
            TextField::new('stripeLast4', 'stripe Last 4')->hideOnForm(),
            TextField::new('stripeRiskLevel', 'stripe Risk Level')->hideOnForm(),

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
}