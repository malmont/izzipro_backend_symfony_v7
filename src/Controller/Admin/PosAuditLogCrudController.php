<?php

namespace App\Controller\Admin;

use App\Entity\PosAuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Journal d'audit de la caisse (19/10/2026) : lecture seule ; la table refuse toute modification ou suppression */
class PosAuditLogCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return PosAuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Entrée du journal de caisse')
            ->setEntityLabelInPlural('Journal de caisse (audit)')
            ->setDefaultSort(['sequence' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('type')->add('cashRegisterId')->add('occurredAt');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IntegerField::new('sequence', 'N°'),
            TextField::new('type', 'Événement'),
            DateTimeField::new('occurredAt', 'Le')->setFormat('dd/MM/yyyy HH:mm:ss'),
            TextField::new('user', 'Par')->formatValue(fn ($v, PosAuditLog $l) => $l->getData()['user']['email'] ?? '—'),
            IntegerField::new('cashRegisterId', 'Poste'),
            TextField::new('terminal', 'Terminal')->formatValue(fn ($v, PosAuditLog $l) => $l->getData()['terminal']['label'] ?? '—'),
            TextField::new('hash', 'Empreinte')->onlyOnDetail(),
            TextField::new('prevHash', 'Empreinte précédente')->onlyOnDetail(),
            TextareaField::new('payload', 'Contenu haché')->onlyOnDetail()
                ->formatValue(fn ($v) => '<pre style="white-space:pre-wrap">' . htmlspecialchars((string) json_encode(json_decode((string) $v, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'),
        ];
    }
}
