<?php

namespace App\Controller\Admin;

use App\Entity\CashRegisterReport;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Rapports Z (19/10/2026) : lecture seule ; aucun écran ne crée, modifie ni supprime un rapport (créé à la fermeture) */
class CashRegisterReportCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CashRegisterReport::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Rapport Z')
            ->setEntityLabelInPlural('Rapports Z (clôtures de caisse)')
            ->setDefaultSort(['closedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('cashRegister')->add('closedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        $cents = fn (?int $value) => $value === null ? '—' : number_format($value / 100, 2, ',', ' ') . ' $';

        return [
            IntegerField::new('number', 'N°'),
            AssociationField::new('cashRegister', 'Poste'),
            DateTimeField::new('closedAt', 'Fermée le')->setFormat('dd/MM/yyyy HH:mm'),
            TextField::new('closedBy', 'Par')->formatValue(fn ($value, CashRegisterReport $z) => $z->getData()['closedBy']['email'] ?? '—'),
            IntegerField::new('expectedCash', 'Espèces attendues')->formatValue(fn ($value) => $cents($value)),
            IntegerField::new('countedCash', 'Comptées')->formatValue(fn ($value) => $cents($value)),
            IntegerField::new('discrepancy', 'Écart')->formatValue(fn ($value) => $cents($value)),
            TextField::new('discrepancyReason', 'Motif')->hideOnIndex(),
            IntegerField::new('keptFund', 'Fond gardé')->formatValue(fn ($value) => $cents($value))->hideOnIndex(),
            IntegerField::new('bankDeposit', 'Dépôt en banque')->formatValue(fn ($value) => $cents($value))->hideOnIndex(),
            TextareaField::new('data', 'Instantané complet')->onlyOnDetail()
                ->formatValue(fn ($value) => '<pre style="white-space:pre-wrap">' . htmlspecialchars((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'),
        ];
    }
}
