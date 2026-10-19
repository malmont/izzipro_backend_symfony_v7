<?php

namespace App\Controller\Admin;

use App\Entity\CashRegister;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Postes de caisse du site (15/10/2026) : nom unique, actif ou non ; jamais supprimés (historique des sessions) */
class CashRegisterCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CashRegister::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Poste de caisse')
            ->setEntityLabelInPlural('Postes de caisse')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE); // un poste se désactive, il n'est pas supprimé
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom')->setMaxLength(100),
            BooleanField::new('isActive', 'Actif')->renderAsSwitch(false),
            DateTimeField::new('createdAt', 'Créé le')->hideOnForm(),
        ];
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->container->get(\App\Services\PosAuditService\PosAuditService::class)->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_CREATED,
            ['name' => $entityInstance->getName(), 'via' => 'easyadmin'], null, $entityInstance, 'cash_register', $entityInstance->getId());
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->container->get(\App\Services\PosAuditService\PosAuditService::class)->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_UPDATED,
            ['after' => ['name' => $entityInstance->getName(), 'isActive' => $entityInstance->isActive()], 'via' => 'easyadmin'], null, $entityInstance, 'cash_register', $entityInstance->getId());
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [\App\Services\PosAuditService\PosAuditService::class => \App\Services\PosAuditService\PosAuditService::class];
    }
}
