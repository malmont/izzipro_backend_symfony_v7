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
}
