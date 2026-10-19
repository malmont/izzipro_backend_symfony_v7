<?php

namespace App\Controller\Admin;

use App\Entity\CashDetails;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class CashDetailsCrudController extends BaseTenantCrudController
{
    use CashRecordLockTrait; // données de caisse non modifiables (C2)

    public static function getEntityFqcn(): string
    {
        return CashDetails::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('transactionCaisse', 'Transaction')
                ->setCrudController(TransactionCaisseCrudController::class),
            AssociationField::new('typeCash', 'Type de cash')
                ->setCrudController(TypeCashCrudController::class),
            IntegerField::new('nombreItems', 'Nombre d\'items'),
        ];
    }

    protected function isCashRecordLocked(object $entity): bool
    {
        return $entity instanceof \App\Entity\CashDetails;
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $this->lockCashRecordActions(parent::configureActions($actions));
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
