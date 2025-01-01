<?php
namespace App\Controller\Admin;

use App\Entity\TransactionCaisse;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class TransactionCaisseCrudController extends AbstractCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(AdminUrlGenerator $adminUrlGenerator)
    {
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return TransactionCaisse::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('caisse', 'Caisse'),
            AssociationField::new('userCaisse', 'Utilisateur'),
            AssociationField::new('transactionType', 'Type de transaction'),
            DateField::new('transactionDate', 'Date de transaction'),
            MoneyField::new('amount', 'Montant')->setCurrency('USD'),
            AssociationField::new('cashdetails', 'Détails de cash')
                ->formatValue(function ($value, $entity) {
                    $cashDetailsUrl = $this->adminUrlGenerator
                        ->setController(CashDetailsListController::class)
                        ->setAction(Crud::PAGE_INDEX)
                        ->set('transactionId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les détails de cash</a>',
                        $cashDetailsUrl
                    );
                })
                ->renderAsHtml(),
        ];
    }
}
